<?php

namespace Tests\Feature;

use App\Livewire\SessionShow;
use App\Models\NotificationOutbox;
use App\Models\QuotaTag;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\CoachRegistrationService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// Gestion des participants par le bureau sur la fiche séance (PRD §4.9.7, §4.11.5) :
// inscription/retrait d'un athlète par un coach + garde d'exclusivité athlète/coach.
class CoachManageParticipantsTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function makeSession(?int $capacity = 10, ?QuotaTag $tag = null): Session
    {
        return $this->targetCategory(Session::create([
            'kind' => 'training', 'title' => 'Natation',
            'start_at' => Carbon::now()->startOfWeek(Carbon::MONDAY)->addWeek()->setTime(19, 0),
            'duration_min' => 60, 'capacity' => $capacity, 'quota_tag_id' => $tag?->id,
            'created_by' => User::factory()->coach()->create()->id,
        ]));
    }

    // ── Inscription d'un athlète par le bureau (§4.9.7) ──

    public function test_coach_enrolls_athlete_under_quota_participating(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();
        $athlete = $this->athlete(); // rôle athlète par défaut

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->call('openAthletePicker')
            ->assertSet('pickingAthlete', true)
            ->assertSee($athlete->fullName())
            ->call('enrollAthlete', $athlete->id)
            ->assertSet('pickingAthlete', false);

        $reg = Registration::where('session_id', $s->id)->where('user_id', $athlete->id)->first();
        $this->assertSame('participating', $reg->status);
        // §4.9.7 « Notif à l'athlète » : EnrolledByCoach (push + email = 2 lignes outbox).
        $this->assertGreaterThan(0, NotificationOutbox::where('type', 'enrolled_by_coach')
            ->where('user_id', $athlete->id)->count());
    }

    public function test_over_quota_opens_dialog_without_enrolling(): void
    {
        $tag = QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 1]);
        $coach = User::factory()->coach()->create();
        $athlete = $this->athlete();

        // Une 1re séance taguée déjà participating → quota 1/1 atteint cette semaine.
        $s1 = $this->makeSession(tag: $tag);
        app(RegistrationService::class)->register($s1, $athlete, $athlete);

        $s2 = $this->makeSession(tag: $tag);
        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s2])
            ->call('enrollAthlete', $athlete->id)
            ->assertSet('athleteQuotaConfirm.user_id', $athlete->id)
            ->assertSet('athleteQuotaConfirm.count', 1)
            ->assertSet('athleteQuotaConfirm.max', 1);

        // Pas d'inscription tant que le coach n'a pas tranché a/b.
        $this->assertNull(Registration::where('session_id', $s2->id)->where('user_id', $athlete->id)->first());
    }

    public function test_quota_dialog_place_in_queue(): void
    {
        $tag = QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 1]);
        $coach = User::factory()->coach()->create();
        $athlete = $this->athlete();
        $s1 = $this->makeSession(tag: $tag);
        app(RegistrationService::class)->register($s1, $athlete, $athlete);
        $s2 = $this->makeSession(tag: $tag);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s2])
            ->call('enrollAthlete', $athlete->id)
            ->call('confirmAthleteQuota', false)
            ->assertSet('athleteQuotaConfirm', null);

        $reg = Registration::where('session_id', $s2->id)->where('user_id', $athlete->id)->first();
        $this->assertSame('waitlist', $reg->status);
        $this->assertSame('quota_exceeded', $reg->waitlist_reason);
    }

    public function test_quota_dialog_force_override(): void
    {
        $tag = QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 1]);
        $coach = User::factory()->coach()->create();
        $athlete = $this->athlete();
        $s1 = $this->makeSession(tag: $tag);
        app(RegistrationService::class)->register($s1, $athlete, $athlete);
        $s2 = $this->makeSession(tag: $tag);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s2])
            ->call('enrollAthlete', $athlete->id)
            ->set('athleteQuotaConfirm.motif', 'demande coach')
            ->call('confirmAthleteQuota', true)
            ->assertSet('athleteQuotaConfirm', null);

        $reg = Registration::where('session_id', $s2->id)->where('user_id', $athlete->id)->first();
        $this->assertSame('participating', $reg->status);
        $this->assertSame($coach->id, $reg->override_by);
    }

    // ── Retrait d'un athlète par le bureau ──

    public function test_coach_removes_athlete_promotes_capacity_fifo(): void
    {
        $s = $this->makeSession(capacity: 1);
        $coach = User::factory()->coach()->create();
        $a = $this->athlete();
        $b = $this->athlete();
        $svc = app(RegistrationService::class);
        $svc->register($s, $a, $a); // participating (capacité 1)
        $svc->register($s, $b, $b); // waitlist capacity

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->call('removeAthlete', $a->id);

        $this->assertSame('cancelled', Registration::where('session_id', $s->id)->where('user_id', $a->id)->first()->status);
        // Mécanisme A : b promu sur la place libérée.
        $this->assertSame('participating', Registration::where('session_id', $s->id)->where('user_id', $b->id)->first()->status);
    }

    // ── Exclusivité athlète/coach (§2, §4.11.5) ──

    public function test_register_coach_on_enrolled_athlete_throws_already_athlete(): void
    {
        $s = $this->makeSession();
        $user = User::factory()->coach()->create(); // a aussi le rôle athlète ? non — coach pur
        // L'utilisateur est inscrit athlète sur la séance.
        Registration::create([
            'session_id' => $s->id, 'user_id' => $user->id,
            'status' => 'participating', 'registered_at' => Carbon::now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CoachRegistrationService::ALREADY_ATHLETE);
        app(CoachRegistrationService::class)->register($s, $user, $user);
    }

    public function test_picker_register_coach_on_athlete_opens_flip(): void
    {
        $s = $this->makeSession();
        $admin = User::factory()->admin()->create();
        $coach = User::factory()->coach()->create();
        Registration::create([
            'session_id' => $s->id, 'user_id' => $coach->id,
            'status' => 'participating', 'registered_at' => Carbon::now(),
        ]);

        Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $s])
            ->call('registerCoach', $coach->id)
            ->assertSet('flipConfirm.dir', 'to_coach')
            ->assertSet('flipConfirm.user_id', $coach->id);

        // Pas de double-statut : toujours pas encadrant.
        $this->assertFalse($s->coaches()->whereKey($coach->id)->exists());
    }

    public function test_athlete_picker_excludes_already_registered(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();
        $in = $this->athlete();
        $free = $this->athlete();
        app(RegistrationService::class)->register($s, $in, $in);

        // L'inscrit reste sélectionnable pour personne : le picker propose enrollAthlete(free) mais
        // pas enrollAthlete(in). assertDontSee sur le nom serait faux (l'inscrit figure dans la liste
        // des inscrits du rendu) → on cible l'action du picker.
        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->call('openAthletePicker')
            ->assertSeeHtml('wire:click="enrollAthlete('.$free->id.')"')
            ->assertDontSeeHtml('wire:click="enrollAthlete('.$in->id.')"');
    }

    // ── Coach-pur ne peut pas devenir athlète (§2) ──

    public function test_pure_coach_cannot_be_registered_as_athlete(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create(); // rôle coach seul

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(RegistrationService::NOT_AN_ATHLETE);
        app(RegistrationService::class)->register($s, $coach, $coach);
    }

    public function test_override_cannot_force_pure_coach_as_athlete(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();
        $admin = User::factory()->admin()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(RegistrationService::NOT_AN_ATHLETE);
        app(RegistrationService::class)->overrideRegister($s, $coach, $admin, 'forçage');
    }

    public function test_flip_to_athlete_blocked_for_pure_coach_without_detaching(): void
    {
        $s = $this->makeSession();
        $c1 = User::factory()->coach()->create();
        $c2 = User::factory()->coach()->create();
        $s->coaches()->attach([$c1->id, $c2->id]);

        try {
            app(CoachRegistrationService::class)->flipToAthlete($s, $c1, $c1, confirmLastCoach: true);
            $this->fail('Attendu : NOT_AN_ATHLETE');
        } catch (RuntimeException $e) {
            $this->assertSame(RegistrationService::NOT_AN_ATHLETE, $e->getMessage());
        }

        // Garde AVANT le détach : le coach-pur reste encadrant, aucune inscription créée.
        $this->assertTrue($s->coaches()->whereKey($c1->id)->exists());
        $this->assertNull(Registration::where('session_id', $s->id)->where('user_id', $c1->id)->first());
    }

    // Voie SELF (bloc « Mon inscription » de enroll-actions) : distincte de la voie tiers
    // testée ci-dessous, qui porte sur l'onglet Encadrement. Un coach-pur encadrant ne doit pas
    // se voir proposer « Je participe » — la bascule échouerait en NOT_AN_ATHLETE (§2).
    public function test_self_participate_button_hidden_for_pure_coach(): void
    {
        $s = $this->makeSession();
        $pureCoach = User::factory()->coach()->create();
        $s->coaches()->attach($pureCoach->id);

        Livewire::actingAs($pureCoach)->test(SessionShow::class, ['session' => $s])
            ->assertDontSeeHtml('flipToAthlete('.$pureCoach->id.')');
    }

    // Contrôle positif appairé du test précédent : le cas nominal (rôles cumulés) doit, lui,
    // continuer d'offrir la bascule.
    public function test_self_participate_button_visible_for_dual_coach(): void
    {
        $s = $this->makeSession();
        $dualCoach = User::factory()->athleteCoach()->create();
        $s->coaches()->attach($dualCoach->id);

        Livewire::actingAs($dualCoach)->test(SessionShow::class, ['session' => $s])
            ->assertSeeHtml('flipToAthlete('.$dualCoach->id.')');
    }

    // Défense en profondeur : même appelée directement, la bascule ne doit pas ouvrir un dialog
    // que la validation refuserait de toute façon.
    public function test_flip_to_athlete_does_not_open_dialog_for_pure_coach(): void
    {
        $s = $this->makeSession();
        $pureCoach = User::factory()->coach()->create();
        $other = User::factory()->coach()->create();
        $s->coaches()->attach([$pureCoach->id, $other->id]);

        Livewire::actingAs($pureCoach)->test(SessionShow::class, ['session' => $s])
            ->call('flipToAthlete', $pureCoach->id)
            ->assertSet('flipConfirm', null);
    }

    // ── Coach-athlète SANS catégorie active (§4.5) : la bascule aboutit à un register(), elle
    // hérite donc des mêmes gardes que l'inscription directe. Cas réel : catégorie archivée, ou
    // dob hors barème — le PRD §4.5 l.281 impose un message explicite, pas un bouton mort.

    // Reproduction du bug : le bloc « Je participe » ignorait $canEnroll.
    // On cible le bouton SELF par son libellé : l'icône de bascule tierce (onglet Encadrement)
    // rend le même wire:click et doit, elle, rester offerte au staff (cf. test $byStaff plus bas).
    public function test_self_participate_button_hidden_without_active_category(): void
    {
        $s = $this->makeSession();
        $dual = User::factory()->athleteCoach()->create(); // volontairement PAS categorize()
        $s->coaches()->attach($dual->id);

        $this->assertFalse($dual->hasActiveCategory()); // pré-requis du scénario

        Livewire::actingAs($dual)->test(SessionShow::class, ['session' => $s])
            ->assertDontSee('Je participe')
            ->assertDontSee('Mon inscription');
    }

    // Masquer le bouton ne suffit pas : sans message on remplace un bouton trompeur par un
    // silence. Le motif §4.5 doit s'afficher (même libellé que la voie athlète normale).
    public function test_self_participate_message_shown_without_active_category(): void
    {
        $s = $this->makeSession();
        $dual = User::factory()->athleteCoach()->create();
        $s->coaches()->attach($dual->id);

        Livewire::actingAs($dual)->test(SessionShow::class, ['session' => $s])
            ->assertSee('Aucune catégorie attribuée à ton compte', escape: false);
    }

    // Contrôle positif appairé : avec une catégorie couvrante, la bascule reste offerte.
    public function test_self_participate_button_visible_with_active_category(): void
    {
        $s = $this->makeSession();
        $dual = $this->categorize(User::factory()->athleteCoach()->create());
        $s->coaches()->attach($dual->id);

        Livewire::actingAs($dual)->test(SessionShow::class, ['session' => $s])
            ->assertSee('Je participe')
            ->assertSeeHtml('flipToAthlete('.$dual->id.')');
    }

    // Défense en profondeur : la modale ne s'ouvre pas pour une auto-bascule qui échouera.
    public function test_flip_to_athlete_does_not_open_dialog_without_category(): void
    {
        $s = $this->makeSession();
        $dual = User::factory()->athleteCoach()->create();
        $other = User::factory()->coach()->create();
        $s->coaches()->attach([$dual->id, $other->id]);

        Livewire::actingAs($dual)->test(SessionShow::class, ['session' => $s])
            ->call('flipToAthlete', $dual->id)
            ->assertSet('flipConfirm', null)
            ->assertSee('Inscription impossible', escape: false);
    }

    // Garde-fou de non-régression : RegistrationService épargne le staff de la garde catégorielle
    // ($byStaff, §4.9.7). La garde UI ne doit donc PAS bloquer la bascule d'un tiers par un admin.
    public function test_staff_can_still_flip_a_third_party_without_category(): void
    {
        $s = $this->makeSession();
        $admin = User::factory()->admin()->create();
        $dual = User::factory()->athleteCoach()->create(); // sans catégorie
        $other = User::factory()->coach()->create();
        $s->coaches()->attach([$dual->id, $other->id]);

        Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $s])
            ->call('flipToAthlete', $dual->id)
            ->assertSet('flipConfirm.dir', 'to_athlete')   // le dialog s'ouvre bien
            ->call('flipToAthlete', $dual->id, true)
            ->assertSet('flipConfirm', null);

        // La bascule staff a abouti malgré l'absence de catégorie.
        $this->assertFalse($s->coaches()->whereKey($dual->id)->exists());
        $this->assertSame('participating', $dual->registrations()
            ->where('session_id', $s->id)->first()->status);
    }

    public function test_flip_button_hidden_for_pure_coach(): void
    {
        $s = $this->makeSession();
        $admin = User::factory()->admin()->create();
        $pureCoach = User::factory()->coach()->create();
        $dualCoach = User::factory()->athleteCoach()->create();
        $s->coaches()->attach([$pureCoach->id, $dualCoach->id]);

        Livewire::actingAs($admin)->test(SessionShow::class, ['session' => $s])
            ->assertDontSeeHtml('flipToAthlete('.$pureCoach->id.')')
            ->assertSeeHtml('flipToAthlete('.$dualCoach->id.')');
    }

    // ── Retrait d'un inscrit : fermé une fois la séance commencée (§4.9.7) ──

    // La désinscription est refusée par RegistrationService une fois la séance commencée : la
    // corbeille ne doit donc plus être offerte au staff, sinon elle est morte sur toute séance passée.
    public function test_remove_button_hidden_once_session_started(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();
        $athlete = $this->athlete();
        app(RegistrationService::class)->register($s, $athlete, $athlete);
        $s->forceFill(['start_at' => Carbon::now()->subHour()])->save();

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s->fresh()])
            ->assertDontSeeHtml('wire:click="removeAthlete('.$athlete->id.')"');
    }

    // Contrôle positif appairé : sur une séance à venir, la corbeille reste offerte.
    public function test_remove_button_visible_before_session_starts(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();
        $athlete = $this->athlete();
        app(RegistrationService::class)->register($s, $athlete, $athlete);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->assertSeeHtml('wire:click="removeAthlete('.$athlete->id.')"');
    }

    // ── Policy ──

    public function test_athlete_cannot_enroll_or_remove_others(): void
    {
        $s = $this->makeSession();
        $athlete = $this->athlete();

        $this->assertFalse($athlete->can('enrollOther', $s));
        $this->assertFalse($athlete->can('unenrollOther', $s));
    }

    // ── Accès athlète suspendu (§4.4) : le bureau ne contourne pas sans override ──

    public function test_coach_cannot_enroll_suspended_athlete(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();
        $suspended = User::factory()->create(['athlete_access_suspended' => true]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(RegistrationService::SUSPENDED);
        app(RegistrationService::class)->register($s, $suspended, $coach);
    }

    public function test_athlete_picker_excludes_suspended(): void
    {
        $s = $this->makeSession();
        $coach = User::factory()->coach()->create();
        $free = $this->athlete();
        $suspended = User::factory()->create(['athlete_access_suspended' => true]);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->call('openAthletePicker')
            ->assertSeeHtml('wire:click="enrollAthlete('.$free->id.')"')
            ->assertDontSeeHtml('wire:click="enrollAthlete('.$suspended->id.')"');
    }

    public function test_override_still_bypasses_suspension(): void
    {
        // §4.10.5 : l'override outrepasse quota/capacité/suspension — seul chemin restant.
        $s = $this->makeSession();
        $admin = User::factory()->admin()->create();
        $suspended = User::factory()->create(['athlete_access_suspended' => true]);

        $reg = app(RegistrationService::class)->overrideRegister($s, $suspended, $admin, 'réintégration');

        $this->assertSame('participating', $reg->status);
        $this->assertSame($admin->id, $reg->override_by);
    }

    // ── Non-régression : inscription self / parent ne notifie pas EnrolledByCoach ──

    public function test_self_enroll_does_not_notify_enrolled_by_coach(): void
    {
        $s = $this->makeSession();
        $athlete = $this->athlete();

        app(RegistrationService::class)->register($s, $athlete, $athlete);

        $this->assertSame(0, NotificationOutbox::where('type', 'enrolled_by_coach')->count());
    }
}
