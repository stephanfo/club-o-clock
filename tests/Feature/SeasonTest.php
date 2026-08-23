<?php

namespace Tests\Feature;

use App\Livewire\Admin\ClubSettingsForm;
use App\Livewire\Admin\MemberShow;
use App\Livewire\Home;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ClubSettings;
use App\Models\Session;
use App\Models\User;
use App\Services\RegistrationService;
use App\Services\SeasonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// Bascule de saison J6.4 (PRD §4.4, §4.5) : suspension de masse + annulation futures,
// démarrage nouvelle année (recalc catégories + purge surclassements), réactivation individuelle.
class SeasonTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function seedCategories(): array
    {
        return [
            'u17' => Category::create(['label' => 'U17', 'age_min' => 15, 'age_max' => 16, 'sort_order' => 1]),
            'senior' => Category::create(['label' => 'Sénior', 'age_min' => 17, 'age_max' => 39, 'sort_order' => 2]),
        ];
    }

    private function makeSession(?int $capacity = null): Session
    {
        return $this->targetCategory(Session::create([
            'kind' => 'training',
            'title' => 'Natation seuil',
            'start_at' => Carbon::now()->addDays(3)->setTime(19, 0),
            'duration_min' => 90,
            'capacity' => $capacity,
            'created_by' => User::factory()->coach()->create()->id,
        ])); // séance ciblant la catégorie ouverte (§4.5)
    }

    // ── §4.4 — suspension de masse ──

    public function test_deactivate_suspends_athletes_cancels_future_regs_keeps_other_roles(): void
    {
        $admin = User::factory()->admin()->create(); // admin pur (pas athlete)
        $athlete = $this->categorize(User::factory()->create(['roles' => ['athlete'], 'is_active' => true]));
        $coachAthlete = User::factory()->create(['roles' => ['coach', 'athlete']]);

        $session = $this->makeSession(capacity: 5);
        app(RegistrationService::class)->register($session, $athlete, $athlete);

        $result = app(SeasonService::class)->deactivateAllAthletes($admin, 'Saison 2026-2027');

        // Athlètes suspendus (incl. coach-athlète), admin pur épargné.
        $this->assertTrue($athlete->fresh()->athlete_access_suspended);
        $this->assertTrue($coachAthlete->fresh()->athlete_access_suspended);
        $this->assertFalse($admin->fresh()->athlete_access_suspended);

        // Flag séparé de is_active : le compte n'est pas désactivé.
        $this->assertTrue($athlete->fresh()->is_active);

        // Inscription future annulée par le système.
        $this->assertDatabaseHas('registrations', ['user_id' => $athlete->id, 'session_id' => $session->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'registration_cancelled', 'actor_is_system' => 1, 'user_id' => $athlete->id]);

        // 1 entrée AuditLog globale, actor = admin, compteurs dans le motif.
        $this->assertSame(1, AuditLog::where('action', 'bulk_athlete_deactivation')->count());
        $log = AuditLog::where('action', 'bulk_athlete_deactivation')->first();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertStringContainsString('Saison 2026-2027', $log->motif);

        $this->assertSame(2, $result['accounts']);
        $this->assertSame(1, $result['registrations']);
    }

    // ── Câblage UI (page Paramètres du club, PRD §4.17) : les actions de saison y sont centralisées ──

    public function test_settings_deactivate_requires_both_checks_then_suspends_with_motif(): void
    {
        $admin = User::factory()->admin()->create();
        $athlete = $this->categorize(User::factory()->create(['roles' => ['athlete']]));

        // Sans les deux cases : garde serveur, aucun effet.
        Livewire::actingAs($admin)->test(ClubSettingsForm::class)
            ->call('openBascule')
            ->assertSet('showBascule', true)
            ->call('deactivateAllAthletes')
            ->assertHasErrors('bascule');
        $this->assertFalse($athlete->fresh()->athlete_access_suspended);

        // Les deux cases cochées + motif : suspension effective, motif tracé.
        Livewire::actingAs($admin)->test(ClubSettingsForm::class)
            ->call('openBascule')
            ->set('basculeCheck1', true)
            ->set('basculeCheck2', true)
            ->set('basculeMotif', 'Saison 2026-2027')
            ->call('deactivateAllAthletes')
            ->assertHasNoErrors()
            ->assertSet('showBascule', false);

        $this->assertTrue($athlete->fresh()->athlete_access_suspended);
        $log = AuditLog::where('action', 'bulk_athlete_deactivation')->first();
        $this->assertStringContainsString('Saison 2026-2027', $log->motif);
    }

    public function test_settings_start_new_season_recalcs_categories(): void
    {
        $cats = $this->seedCategories();
        $admin = User::factory()->admin()->create();
        $athlete = User::factory()->create(['roles' => ['athlete'], 'dob' => '2001-01-01']);
        $athlete->categories()->attach($cats['senior']->id, ['is_primary' => true]);
        $athlete->categories()->attach($cats['u17']->id, ['is_primary' => false]); // surclassement à purger

        Livewire::actingAs($admin)->test(ClubSettingsForm::class)
            ->set('showNouvelleAnnee', true)
            ->call('startNewSeason')
            ->assertHasNoErrors()
            ->assertSet('showNouvelleAnnee', false);

        $this->assertFalse($athlete->categories()->wherePivot('is_primary', false)->exists());
        $this->assertSame(1, AuditLog::where('action', 'season_rollover')->count());
    }

    public function test_settings_page_forbidden_to_non_admin(): void
    {
        $coach = User::factory()->coach()->create(); // coach non-admin

        // La Gate manage-club (admin-only) est vérifiée dès mount() : la page entière — donc les
        // actions de saison qu'elle porte — est inaccessible à un non-admin.
        Livewire::actingAs($coach)->test(ClubSettingsForm::class)->assertForbidden();
    }

    public function test_deactivate_triggers_capacity_promotion(): void
    {
        $admin = User::factory()->admin()->create();
        $reg = app(RegistrationService::class);
        $session = $this->makeSession(capacity: 1);

        $first = $this->categorize(User::factory()->create(['roles' => ['athlete']]));
        $second = $this->categorize(User::factory()->create(['roles' => ['athlete']]));
        $reg->register($session, $first, $first);   // participating
        $promoted = $reg->register($session, $second, $second); // waitlist capacity
        $this->assertSame('waitlist', $promoted->status);

        // Annuler le 1er (participating) via la bascule → mécanisme A promeut le 2e... mais le 2e
        // est aussi athlète suspendu, donc sera lui-même annulé. Net : les deux finissent cancelled.
        app(SeasonService::class)->deactivateAllAthletes($admin);

        $this->assertSame('cancelled', $promoted->fresh()->status);
        $this->assertSame('cancelled', $session->registrations()->where('user_id', $first->id)->first()->status);
    }

    // ── §4.5 — nouvelle année sportive ──

    public function test_start_new_season_recalcs_primary_purges_surclassements_grandfathers(): void
    {
        $cats = $this->seedCategories();
        $admin = User::factory()->admin()->create();
        $athlete = User::factory()->create(['roles' => ['athlete'], 'dob' => '2001-01-01']); // ~25 ans → Sénior

        // Principale Sénior + un surclassement manuel U17.
        $athlete->categories()->attach($cats['senior']->id, ['is_primary' => true]);
        $athlete->categories()->attach($cats['u17']->id, ['is_primary' => false]);

        // Inscription future à grandfather. Séance ciblant la catégorie de l'athlète (§4.5).
        $session = $this->makeSession(capacity: 5);
        $session->categories()->sync([$cats['senior']->id]);
        app(RegistrationService::class)->register($session, $athlete, $athlete);

        $result = app(SeasonService::class)->startNewSeason($admin);

        $athlete->refresh()->load('categories');
        // Surclassement purgé, principale recalculée conservée.
        $this->assertFalse($athlete->categories()->wherePivot('is_primary', false)->exists());
        $this->assertSame($cats['senior']->id, $athlete->primaryCategory()->id);

        // Inscription future intacte (grandfathered).
        $this->assertDatabaseHas('registrations', ['user_id' => $athlete->id, 'session_id' => $session->id, 'status' => 'participating']);

        $this->assertSame(1, AuditLog::where('action', 'season_rollover')->count());
        $this->assertNotNull(ClubSettings::current()->fresh()->season_rollover_at);
        $this->assertSame(1, $result['surclassements_removed']);

        // Comptes non désactivés.
        $this->assertFalse($athlete->fresh()->athlete_access_suspended);
    }

    // ── §4.4 — suspension individuelle (pendant du geste de masse) ──

    public function test_suspend_one_athlete_without_touching_the_rest_of_the_club(): void
    {
        // Le trou comblé : pour écarter une seule personne — licence non renouvelée, départ en
        // cours de saison — il fallait suspendre TOUT le club par la bascule de saison.
        $admin = User::factory()->admin()->create();
        $vise = $this->categorize(User::factory()->create(['roles' => ['athlete']]));
        $epargne = $this->categorize(User::factory()->create(['roles' => ['athlete']]));

        $session = $this->makeSession(capacity: 5);
        app(RegistrationService::class)->register($session, $vise, $vise);
        app(RegistrationService::class)->register($session, $epargne, $epargne);

        $annulees = app(SeasonService::class)->suspendAthlete($vise, $admin, 'Licence non renouvelée');

        $this->assertSame(1, $annulees);
        $this->assertTrue($vise->fresh()->athlete_access_suspended);
        // Contrôle positif apparié : le voisin n'est pas touché, ni son inscription.
        $this->assertFalse($epargne->fresh()->athlete_access_suspended);
        $this->assertDatabaseHas('registrations', [
            'session_id' => $session->id, 'user_id' => $epargne->id, 'status' => 'participating',
        ]);
    }

    public function test_suspending_cancels_only_future_registrations(): void
    {
        $admin = User::factory()->admin()->create();
        $membre = $this->categorize(User::factory()->create(['roles' => ['athlete']]));

        $future = $this->makeSession();
        app(RegistrationService::class)->register($future, $membre, $membre);

        app(SeasonService::class)->suspendAthlete($membre, $admin);

        $this->assertDatabaseHas('registrations', [
            'session_id' => $future->id, 'user_id' => $membre->id, 'status' => 'cancelled',
        ]);
    }

    public function test_suspending_keeps_the_account_open(): void
    {
        // La suspension n'est PAS une fermeture de compte : l'adhérent se connecte toujours et voit
        // le planning. Fermer le compte, c'est la suppression (§4.3), un autre geste.
        $admin = User::factory()->admin()->create();
        $membre = User::factory()->create(['roles' => ['athlete'], 'email' => 'a@b.test']);

        app(SeasonService::class)->suspendAthlete($membre, $admin);

        $this->assertTrue($membre->fresh()->is_active);
        $this->assertNull($membre->fresh()->deletion_requested_at);
    }

    public function test_suspending_is_audited_with_a_target_and_the_motif(): void
    {
        // Trace CIBLÉE, contrairement au geste de masse qui n'écrit qu'une entrée globale : ici
        // l'acte vise une personne, le journal doit pouvoir répondre « qui, quand ».
        $admin = User::factory()->admin()->create();
        $membre = User::factory()->create(['roles' => ['athlete']]);

        app(SeasonService::class)->suspendAthlete($membre, $admin, 'Licence non renouvelée');

        $entree = AuditLog::where('action', 'account_deactivated')->firstOrFail();
        $this->assertSame($admin->id, $entree->actor_id);
        $this->assertSame($membre->id, $entree->target_id);
        $this->assertStringContainsString('Licence non renouvelée', (string) $entree->motif);
    }

    public function test_suspending_sends_no_notification(): void
    {
        // §4.15 : pas d'email ni de push à la suspension — c'est la bannière in-app persistante qui
        // informe. Même règle que le geste de masse, sinon une suspension de club inonderait les
        // boîtes.
        $admin = User::factory()->admin()->create();
        $membre = User::factory()->create(['roles' => ['athlete'], 'email' => 'a@b.test']);

        app(SeasonService::class)->suspendAthlete($membre, $admin);

        $this->assertDatabaseMissing('notification_outbox', ['user_id' => $membre->id]);
    }

    public function test_suspending_is_noop_when_already_suspended(): void
    {
        $admin = User::factory()->admin()->create();
        $membre = User::factory()->create(['roles' => ['athlete'], 'athlete_access_suspended' => true]);

        $this->assertSame(0, app(SeasonService::class)->suspendAthlete($membre, $admin));
        $this->assertSame(0, AuditLog::where('action', 'account_deactivated')->count());
    }

    public function test_member_show_suspends_then_reactivates(): void
    {
        $admin = User::factory()->admin()->create();
        $membre = User::factory()->create(['roles' => ['athlete'], 'email' => 'a@b.test']);

        $composant = Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $membre])
            ->set('suspendMotif', 'Licence non renouvelée')
            ->call('suspendAccess')
            ->assertHasNoErrors();

        $this->assertTrue($membre->fresh()->athlete_access_suspended);
        // La modale se referme et le bouton bascule vers la réactivation.
        // escape:false — l'apostrophe typographique du gabarit est rendue telle quelle.
        $composant->assertSet('confirmingSuspend', false)->assertSee("Réactiver l'accès athlète", false);

        $composant->call('reactivateAccess');
        $this->assertFalse($membre->fresh()->athlete_access_suspended);
    }

    public function test_non_admin_cannot_suspend_from_the_member_sheet(): void
    {
        $membre = User::factory()->create(['roles' => ['athlete']]);

        foreach ([['athlete'], ['coach']] as $roles) {
            Livewire::actingAs(User::factory()->create(['roles' => $roles]))
                ->test(MemberShow::class, ['user' => $membre])
                ->assertForbidden();
        }

        $this->assertFalse($membre->fresh()->athlete_access_suspended);
    }

    // ── §4.4 — réactivation individuelle ──

    public function test_reactivate_clears_flag_audits_and_queues_email(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['roles' => ['athlete'], 'athlete_access_suspended' => true, 'email' => 'a@b.test']);

        app(SeasonService::class)->reactivateAthlete($member, $admin);

        $this->assertFalse($member->fresh()->athlete_access_suspended);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account_activated', 'actor_id' => $admin->id, 'target_id' => $member->id]);
        $this->assertDatabaseHas('notification_outbox', ['type' => 'athlete_reactivated', 'channel' => 'email', 'user_id' => $member->id, 'status' => 'pending']);
    }

    public function test_reactivate_without_email_queues_nothing(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['roles' => ['athlete'], 'athlete_access_suspended' => true, 'email' => null]);

        app(SeasonService::class)->reactivateAthlete($member, $admin);

        $this->assertFalse($member->fresh()->athlete_access_suspended);
        $this->assertDatabaseMissing('notification_outbox', ['user_id' => $member->id]);
    }

    public function test_reactivate_is_noop_when_not_suspended(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['athlete_access_suspended' => false]);

        app(SeasonService::class)->reactivateAthlete($member, $admin);

        $this->assertSame(0, AuditLog::where('action', 'account_activated')->count());
    }

    // ── Rappel passif (§4.5) ──

    public function test_rollover_reminder_until_triggered(): void
    {
        $settings = ClubSettings::current();
        $this->assertTrue($settings->needsRolloverReminder()); // jamais déclenchée

        $settings->update(['season_rollover_at' => Carbon::now()]);
        $this->assertFalse($settings->fresh()->needsRolloverReminder());
    }

    // ── UI ──

    public function test_member_show_reactivate_button(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create(['roles' => ['athlete'], 'athlete_access_suspended' => true, 'email' => 'a@b.test']);

        Livewire::actingAs($admin)->test(MemberShow::class, ['user' => $member])
            ->call('reactivateAccess')
            ->assertHasNoErrors();

        $this->assertFalse($member->fresh()->athlete_access_suspended);
    }

    public function test_home_rollover_banner_admin_only(): void
    {
        ClubSettings::current(); // season_rollover_at null → rappel actif
        $admin = User::factory()->admin()->create();
        $athlete = User::factory()->create(['roles' => ['athlete']]);

        Livewire::actingAs($admin)->test(Home::class)->assertSee('Rentrée sportive');
        Livewire::actingAs($athlete)->test(Home::class)->assertDontSee('Rentrée sportive');
    }
}
