<?php

namespace Tests\Feature;

use App\Livewire\Home;
use App\Livewire\Planning;
use App\Livewire\SessionForm;
use App\Livewire\SessionShow;
use App\Models\Category;
use App\Models\EventType;
use App\Models\NotificationOutbox;
use App\Models\NotificationPreferences;
use App\Models\Session;
use App\Models\User;
use App\Notifications\NotificationRenderer;
use App\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ouverture des inscriptions aux compétitions (#105, PRD §4.7 et §4.15.2) : date et heure
 * facultative sur la compétition, état affiché (fiche, vue Courses — pas l'accueil),
 * notification à la catégorie ciblée 15 min avant l'heure — 9 h heure club sans heure —, jamais
 * en double, jamais sur une course annulée ni en retard, replanifiée quand la date change.
 */
class RegistrationOpeningTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Paris';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 12, 8, 0, 0, self::TZ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $local): Carbon
    {
        return Carbon::parse($local, self::TZ);
    }

    private function course(?string $opens, bool $hasTime = true, array $categoryIds = [], string $title = 'Tri de Saint-Nazaire'): Session
    {
        $coach = User::factory()->coach()->create();
        $s = Session::create([
            'kind' => 'competition', 'title' => $title, 'start_at' => $this->at('2027-06-20 09:00'),
            'duration_min' => 240, 'created_by' => $coach->id,
            'registration_opens_at' => $opens ? $this->at($opens) : null,
            'registration_opens_has_time' => $hasTime,
        ]);
        $s->categories()->sync($categoryIds);

        return $s;
    }

    private function category(string $label, int $sort): Category
    {
        return Category::create(['label' => $label, 'age_min' => 10 * $sort, 'age_max' => 10 * $sort + 9, 'sort_order' => $sort]);
    }

    private function lignes(?User $u = null): int
    {
        return NotificationOutbox::where('type', 'registration_opening')
            ->when($u, fn ($q) => $q->where('user_id', $u->id))->count();
    }

    // ── Modèle ──

    public function test_notify_moment_is_fifteen_minutes_before_or_nine_am_without_time(): void
    {
        $this->assertTrue($this->course('2026-10-12 10:00')->registrationOpeningNotifyAt()->equalTo($this->at('2026-10-12 09:45')));
        $this->assertTrue($this->course('2026-10-12 00:00', hasTime: false)->registrationOpeningNotifyAt()->equalTo($this->at('2026-10-12 09:00')));
    }

    public function test_state_follows_the_opening(): void
    {
        $s = $this->course('2026-10-15 10:00');
        $this->assertSame('upcoming', $s->registrationState($this->at('2026-10-12 08:00')));
        $this->assertSame('today', $s->registrationState($this->at('2026-10-15 09:59')));
        $this->assertSame('open', $s->registrationState($this->at('2026-10-15 10:00')));
        $this->assertSame('open', $s->registrationState($this->at('2026-10-18 23:00')));
        $this->assertSame('maybe_full', $s->registrationState($this->at('2026-10-19 00:01')));

        // Sans heure : « aujourd'hui » toute la journée.
        $sansHeure = $this->course('2026-10-15 00:00', hasTime: false);
        $this->assertSame('today', $sansHeure->registrationState($this->at('2026-10-15 18:00')));
        $this->assertSame('open', $sansHeure->registrationState($this->at('2026-10-16 08:00')));

        $this->assertNull($this->course(null)->registrationState());
        $annulee = $this->course('2026-10-15 10:00');
        $annulee->forceFill(['cancelled_at' => Carbon::now()])->save();
        $this->assertNull($annulee->registrationState());
    }

    // ── Notification ──

    public function test_notifies_target_category_fifteen_minutes_before_once(): void
    {
        $seniors = $this->category('Sénior', 2);
        $masters = $this->category('Master', 4);
        $cible = User::factory()->create();
        $cible->categories()->attach($seniors->id);
        $horsCible = User::factory()->create();
        $horsCible->categories()->attach($masters->id);
        $this->course('2026-10-12 10:00', categoryIds: [$seniors->id]);

        Carbon::setTestNow($this->at('2026-10-12 09:40'));
        $this->artisan('notifications:ouvertures')->assertSuccessful();
        $this->assertSame(0, $this->lignes(), 'trop tôt : rien avant H-15');

        Carbon::setTestNow($this->at('2026-10-12 09:45'));
        $this->artisan('notifications:ouvertures');
        $this->assertSame(2, $this->lignes($cible), 'push + email');
        $this->assertSame(0, $this->lignes($horsCible));

        $rendu = app(NotificationRenderer::class)->render(NotificationOutbox::where('user_id', $cible->id)->first());
        $this->assertSame('Ouverture des inscriptions', $rendu['title']);
        $this->assertStringContainsString('Tri de Saint-Nazaire : les inscriptions ouvrent aujourd\'hui à 10:00', $rendu['body']);

        Carbon::setTestNow($this->at('2026-10-12 09:50'));
        $this->artisan('notifications:ouvertures');
        $this->assertSame(2, $this->lignes($cible), 'jamais en double');
    }

    public function test_without_time_notifies_at_nine_club_time(): void
    {
        $u = User::factory()->create();
        $this->course('2026-10-12 00:00', hasTime: false);

        Carbon::setTestNow($this->at('2026-10-12 08:55'));
        $this->artisan('notifications:ouvertures');
        $this->assertSame(0, $this->lignes($u));

        Carbon::setTestNow($this->at('2026-10-12 09:00'));
        $this->artisan('notifications:ouvertures');
        $this->assertSame(2, $this->lignes($u));
        $rendu = app(NotificationRenderer::class)->render(NotificationOutbox::where('user_id', $u->id)->first());
        $this->assertStringContainsString('ouvrent aujourd\'hui, sur le site', $rendu['body']);
    }

    public function test_cancelled_or_late_competition_is_not_notified(): void
    {
        User::factory()->create();
        $annulee = $this->course('2026-10-12 10:00', title: 'Course annulée');
        $annulee->forceFill(['cancelled_at' => Carbon::now()])->save();
        // Échéance manquée (cron arrêté) : l'ouverture est passée, « ouvrent aujourd'hui » serait faux.
        $ratee = $this->course('2026-10-12 07:00', title: 'Course ratée');

        Carbon::setTestNow($this->at('2026-10-12 09:45'));
        $this->artisan('notifications:ouvertures');

        $this->assertSame(0, $this->lignes());
        $this->assertNotNull($ratee->fresh()->registration_opening_notified_at, 'marquée : plus réexaminée');
        $this->assertNull($annulee->fresh()->registration_opening_notified_at);
    }

    public function test_opt_out_is_respected(): void
    {
        $u = User::factory()->create();
        NotificationPreferences::create(['user_id' => $u->id, 'paused' => false, 'matrix' => [
            NotificationType::RegistrationOpening->value => ['push' => false, 'email' => false],
        ]]);
        $autre = User::factory()->create();
        $this->course('2026-10-12 10:00');

        Carbon::setTestNow($this->at('2026-10-12 09:45'));
        $this->artisan('notifications:ouvertures');

        $this->assertSame(0, $this->lignes($u));
        $this->assertSame(2, $this->lignes($autre));
    }

    public function test_type_is_in_the_club_group_of_the_preference_matrix(): void
    {
        $club = collect(NotificationType::matrixGroups())->firstWhere('label', 'Le club');
        $this->assertContains(NotificationType::RegistrationOpening, $club['types']);
    }

    // ── Formulaire ──

    private function form(User $coach, ?Session $s = null)
    {
        return Livewire::actingAs($coach)->test(SessionForm::class, $s ? ['session' => $s] : []);
    }

    public function test_form_saves_opening_and_reschedules_when_date_changes(): void
    {
        $coach = User::factory()->coach()->create();
        $type = EventType::create(['label' => 'Triathlon', 'sort_order' => 0]);

        $this->form($coach)
            ->set('kind', 'competition')->set('title', 'Tri de Saint-Nazaire')->set('event_type_id', $type->id)
            ->set('start_at', '2027-06-20T09:00')->set('duration_min', 240)
            ->set('registration_opens_date', '2026-11-03')->set('registration_opens_time', '10:00')
            ->call('save')->assertHasNoErrors();

        $s = Session::where('title', 'Tri de Saint-Nazaire')->firstOrFail();
        $this->assertTrue($s->registration_opens_at->equalTo($this->at('2026-11-03 10:00')));
        $this->assertTrue($s->registration_opens_has_time);
        $this->assertNull($s->registration_opening_notified_at);

        // Envoi déjà parti, puis l'organisateur décale : la notification est replanifiée.
        $s->forceFill(['registration_opening_notified_at' => Carbon::now()])->save();
        $this->form($coach, $s->fresh())->set('registration_opens_date', '2026-11-10')->set('registration_opens_time', '')
            ->call('save')->assertHasNoErrors();
        $s->refresh();
        $this->assertFalse($s->registration_opens_has_time);
        $this->assertTrue($s->registration_opens_at->equalTo($this->at('2026-11-10 00:00')));
        $this->assertNull($s->registration_opening_notified_at);

        // Autre champ modifié, ouverture inchangée : l'état d'envoi ne bouge pas.
        $s->forceFill(['registration_opening_notified_at' => Carbon::now()])->save();
        $this->form($coach, $s->fresh())->set('distance', 'M')->call('save');
        $this->assertNotNull($s->fresh()->registration_opening_notified_at);
    }

    public function test_past_opening_entered_later_sends_nothing(): void
    {
        $coach = User::factory()->coach()->create();
        $s = $this->course(null);
        User::factory()->create();

        $this->form($coach, $s)->set('event_type_id', EventType::create(['label' => 'Trail', 'sort_order' => 0])->id)
            ->set('registration_opens_date', '2026-10-01')->call('save')->assertHasNoErrors();
        $this->assertNotNull($s->fresh()->registration_opening_notified_at);

        $this->artisan('notifications:ouvertures');
        $this->assertSame(0, $this->lignes());
    }

    public function test_form_refuses_time_without_date_and_opening_after_the_race(): void
    {
        $coach = User::factory()->coach()->create();
        $s = $this->course(null);
        $type = EventType::create(['label' => 'Trail', 'sort_order' => 0]);

        $this->form($coach, $s)->set('event_type_id', $type->id)->set('registration_opens_time', '10:00')
            ->call('save')->assertHasErrors(['registration_opens_date' => 'required_with']);
        $this->form($coach, $s)->set('event_type_id', $type->id)->set('registration_opens_date', '2027-07-01')
            ->call('save')->assertHasErrors('registration_opens_date');
    }

    // ── Affichage ──

    public function test_fiche_and_courses_view_show_the_opening(): void
    {
        $s = $this->course('2026-10-15 10:00');
        $u = User::factory()->create();

        Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s])
            ->assertSee('Inscriptions')
            ->assertSee('Jeudi 15 octobre à 10:00');

        Livewire::withQueryParams(['view' => 'courses'])->actingAs($u)->test(Planning::class)
            ->assertSee('Inscriptions le jeu. 15 oct. à 10:00');
    }

    public function test_home_does_not_show_openings(): void
    {
        // Choix de Stéphane (01/10) : l'accueil reste aux débriefs ; les courses à venir ont la vue Courses.
        $this->course('2026-10-13 10:00', title: 'Ouvre demain');

        $u = User::factory()->create();

        // Contrôle positif : la course et son état existent bien, dans la vue Courses.
        Livewire::withQueryParams(['view' => 'courses'])->actingAs($u)->test(Planning::class)
            ->assertSee('Ouvre demain')
            ->assertSee('Inscriptions le');

        // Sur l'accueil, la course peut figurer comme « Prochaine » séance (héros) : seul son état
        // d'inscriptions, et le bloc des ouvertures, en sont absents.
        Livewire::actingAs($u)->test(Home::class)
            ->assertDontSee('Inscriptions le')
            ->assertDontSee('data-inscriptions', false);
    }
}
