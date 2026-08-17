<?php

namespace Tests\Feature;

use App\Livewire\ParentChildren;
use App\Livewire\Planning;
use App\Livewire\SessionShow;
use App\Models\ActivityLog;
use App\Models\InvitationToken;
use App\Models\NotificationOutbox;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\RegistrationService;
use App\Support\SubjectContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// UI parent garant (PRD §4.2) : écran « Mes enfants », sélecteur de sujet, inscription d'un
// enfant depuis planning/fiche — le parent agit POUR l'enfant, jamais EN TANT QUE lui.
class ParentSubjectTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function makeSession(?int $capacity = 10): Session
    {
        return $this->targetCategory(Session::create([
            'kind' => 'training', 'title' => 'Natation jeunes',
            'start_at' => Carbon::now()->addDays(2)->setTime(18, 0),
            'duration_min' => 60, 'capacity' => $capacity,
            'created_by' => User::factory()->coach()->create()->id,
        ])); // séance ciblant la catégorie ouverte (§4.5).
    }

    private function makeFamily(): array
    {
        $parent = User::factory()->create();
        // P1 : pas d'email/credential — le parent agit en son nom (§4.2). Catégorisé pour être
        // inscriptible (§4.5).
        $p1 = $this->categorize(User::factory()->create([
            'email' => null, 'password' => null, 'is_minor' => true, 'guardian_id' => $parent->id,
        ]));
        // P2 : compte propre, tutelle active.
        $p2 = $this->categorize(User::factory()->create(['is_minor' => true, 'guardian_id' => $parent->id]));

        return [$parent, $p1, $p2];
    }

    // ── Écran « Mes enfants » ──

    public function test_children_page_requires_guardianship(): void
    {
        $this->actingAs(User::factory()->create())->get('/enfants')->assertForbidden();
    }

    public function test_guardian_sees_children_with_phases(): void
    {
        [$parent, $p1, $p2] = $this->makeFamily();

        $this->actingAs($parent)->get('/enfants')
            ->assertOk()
            ->assertSee($p1->fullName())
            ->assertSee($p2->fullName())
            ->assertSee('Accès autonome')     // action P1
            ->assertSee('Rompre la tutelle'); // action P2
    }

    public function test_children_page_lists_up_to_three_upcoming_registered_sessions(): void
    {
        [$parent, $p1] = $this->makeFamily();

        // 4 séances à venir (semaines suivantes, hors semaine courante pour ne pas polluer la
        // section « Cette semaine »), l'enfant inscrit à chacune — la carte n'en montre que 3.
        $coach = User::factory()->coach()->create();
        $titles = [];
        foreach (range(1, 4) as $i) {
            $s = Session::create([
                'kind' => 'training', 'title' => "Séance numéro $i",
                'start_at' => Carbon::now()->addWeeks($i)->setTime(18, 0),
                'duration_min' => 60, 'capacity' => 10,
                'created_by' => $coach->id,
            ]);
            Registration::create(['session_id' => $s->id, 'user_id' => $p1->id, 'status' => 'participating', 'registered_at' => Carbon::now()]);
            $titles[$i] = $s->title;
        }

        $this->actingAs($parent)->get('/enfants')
            ->assertSee('Prochaines séances')
            ->assertSee($titles[1])
            ->assertSee($titles[2])
            ->assertSee($titles[3])
            ->assertDontSee($titles[4]); // 4e séance tronquée (limite à 3)
    }

    public function test_invite_sets_email_and_creates_token(): void
    {
        [$parent, $p1] = $this->makeFamily();

        Livewire::actingAs($parent)->test(ParentChildren::class)
            ->call('openInvite', $p1->id)
            ->set('inviteDialog.email', 'enfant@demo.club')
            ->call('sendInvite')
            ->assertSet('inviteDialog', null);

        $this->assertSame('enfant@demo.club', $p1->fresh()->email);
        $this->assertSame(1, InvitationToken::where('user_id', $p1->id)->whereNull('consumed_at')->count());
    }

    public function test_invite_rejects_invalid_email_with_error(): void
    {
        [$parent, $p1] = $this->makeFamily();

        Livewire::actingAs($parent)->test(ParentChildren::class)
            ->call('openInvite', $p1->id)
            ->set('inviteDialog.email', 'pas-un-email')
            ->call('sendInvite')
            ->assertHasErrors('inviteDialog.email');

        $this->assertNull($p1->fresh()->email);
    }

    public function test_sever_breaks_guardianship(): void
    {
        [$parent, , $p2] = $this->makeFamily();

        Livewire::actingAs($parent)->test(ParentChildren::class)
            ->call('openSever', $p2->id)
            ->call('confirmSever')
            ->assertSet('severDialog', null);

        $this->assertNull($p2->fresh()->guardian_id);
    }

    public function test_actions_rejected_on_non_ward(): void
    {
        [$parent] = $this->makeFamily();
        $stranger = User::factory()->create(['is_minor' => true, 'guardian_id' => User::factory()->create()->id]);

        Livewire::actingAs($parent)->test(ParentChildren::class)
            ->call('openSever', $stranger->id)
            ->assertStatus(404);
    }

    public function test_children_page_links_sessions_with_as_param(): void
    {
        [$parent, $p1] = $this->makeFamily();
        $s = $this->makeSession();
        Registration::create([
            'session_id' => $s->id, 'user_id' => $p1->id,
            'status' => 'participating', 'registered_at' => Carbon::now(),
        ]);

        // La carte enfant pointe vers la fiche AVEC le sujet en query param (?as=), pas de wire:click.
        $this->actingAs($parent)->get('/enfants')
            ->assertOk()
            ->assertSee(route('sessions.show', ['session' => $s, 'as' => $p1->id]), false);
    }

    public function test_opening_ward_session_via_query_param_sets_subject_in_single_request(): void
    {
        [$parent, $p1] = $this->makeFamily();
        $s = $this->makeSession();

        // Ouvrir la fiche AU NOM de l'enfant = un GET porteur de ?as= : le sujet est posé côté
        // serveur (pas de course avec wire:navigate), puis redirection vers l'URL canonique sans
        // ?as= — un reload/back-forward ne doit pas re-basculer le sujet sans geste délibéré.
        $this->actingAs($parent)
            ->get(route('sessions.show', ['session' => $s, 'as' => $p1->id]))
            ->assertRedirect(route('sessions.show', $s));

        $this->assertSame($p1->id, SubjectContext::current($parent->fresh())->id);

        // L'URL canonique rend la fiche, toujours au nom de l'enfant.
        $this->actingAs($parent)->get(route('sessions.show', $s))->assertOk();
        $this->assertSame($p1->id, SubjectContext::current($parent->fresh())->id);
    }

    public function test_opening_session_with_forged_as_param_is_ignored(): void
    {
        [$parent] = $this->makeFamily();
        $stranger = User::factory()->create(['is_minor' => true, 'guardian_id' => User::factory()->create()->id]);
        $s = $this->makeSession();

        // ?as= forgé sur un non-ward → SubjectContext::set() l'ignore, on reste soi
        // (la redirection canonique a lieu dans tous les cas : le param est purgé de l'URL).
        $this->actingAs($parent)
            ->get(route('sessions.show', ['session' => $s, 'as' => $stranger->id]))
            ->assertRedirect(route('sessions.show', $s));

        $this->assertSame($parent->id, SubjectContext::current($parent->fresh())->id);
    }

    // ── Sélecteur de sujet ──

    public function test_set_subject_ignores_non_ward(): void
    {
        [$parent] = $this->makeFamily();
        $stranger = User::factory()->create();

        SubjectContext::set($parent, $stranger->id);

        $this->assertSame($parent->id, SubjectContext::current($parent)->id);
    }

    public function test_guardian_nav_shows_children_entry(): void
    {
        [$parent] = $this->makeFamily();

        $this->actingAs($parent)->get('/dashboard')->assertSee('Mes enfants');
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertDontSee('Mes enfants');
    }

    // ── Inscription de l'enfant via le sujet (depuis la fiche) ──

    public function test_parent_enrolls_and_unenrolls_child_from_fiche(): void
    {
        [$parent, , $p2] = $this->makeFamily();
        $s = $this->makeSession();

        Livewire::actingAs($parent)->test(SessionShow::class, ['session' => $s])
            ->call('setSubject', $p2->id)
            ->call('enroll');
        $this->assertSame('participating', Registration::where('session_id', $s->id)->where('user_id', $p2->id)->first()->status);

        Livewire::actingAs($parent)->test(SessionShow::class, ['session' => $s])
            ->call('unenroll');
        $this->assertSame('cancelled', Registration::where('session_id', $s->id)->where('user_id', $p2->id)->first()->status);
    }

    public function test_coach_parent_enrolling_own_child_is_not_by_staff(): void
    {
        // Un coach-parent inscrivant SON enfant reste un parent (§4.9.7) : pas de notif
        // enrolled_by_coach, ActivityLog inscription_for_other.
        $parent = User::factory()->athleteCoach()->create();
        $child = $this->categorize(User::factory()->create(['is_minor' => true, 'guardian_id' => $parent->id]));
        $s = $this->makeSession();

        app(RegistrationService::class)->register($s, $child, $parent);

        $this->assertSame(0, NotificationOutbox::where('type', 'enrolled_by_coach')->count());
        $this->assertSame(1, ActivityLog::where('action', 'inscription_for_other')->count());
    }
}
