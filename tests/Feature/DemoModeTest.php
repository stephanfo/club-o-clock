<?php

namespace Tests\Feature;

use App\Models\GpxRoute;
use App\Models\User;
use App\Notifications\Channels\LogChannel;
use App\Providers\AppServiceProvider;
use App\Services\AuthMethodService;
use App\Support\DemoMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Instance de démonstration publique (plan open source OS7).
//
// L'enjeu de ces tests n'est pas l'affichage : c'est qu'une démo dont les identifiants admin
// sont écrits sur l'écran de connexion ne puisse RIEN envoyer à personne, et que la commande
// qui détruit la base refuse de s'exécuter ailleurs que sur une démo.
class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    private function enableDemo(): void
    {
        config(['club.demo.enabled' => true]);
    }

    // --- Garde-fous d'envoi -------------------------------------------------

    public function test_demo_mode_forces_mail_transport_to_log(): void
    {
        // Le .env de la démo prétend envoyer pour de vrai : le mode démo doit l'écraser.
        config(['club.demo.enabled' => true, 'mail.default' => 'smtp']);

        (new AppServiceProvider($this->app))->register();

        $this->assertSame('log', config('mail.default'));
    }

    public function test_demo_mode_forces_both_notification_channels_to_log(): void
    {
        config([
            'club.demo.enabled' => true,
            'club.notifications.channels.push' => 'App\\Notifications\\Push\\RealSender',
            'club.notifications.channels.email' => 'App\\Mail\\RealMailer',
        ]);

        (new AppServiceProvider($this->app))->register();

        $this->assertSame(LogChannel::class, config('club.notifications.channels.push'));
        $this->assertSame(LogChannel::class, config('club.notifications.channels.email'));
    }

    public function test_normal_instance_keeps_its_mail_configuration(): void
    {
        // Le pendant indispensable : sans DEMO_MODE, l'écrasement ne doit JAMAIS avoir lieu,
        // sinon une instance de club cesserait silencieusement d'envoyer ses emails.
        config(['club.demo.enabled' => false, 'mail.default' => 'smtp']);

        (new AppServiceProvider($this->app))->register();

        $this->assertSame('smtp', config('mail.default'));
    }

    // --- demo:reset ---------------------------------------------------------

    public function test_reset_is_refused_outside_demo_mode(): void
    {
        config(['club.demo.enabled' => false]);
        $user = User::factory()->create();

        $this->artisan('demo:reset')->assertFailed();

        // La base doit être intacte : c'est tout l'enjeu du refus.
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_reset_refusal_explains_why(): void
    {
        config(['club.demo.enabled' => false]);

        $this->artisan('demo:reset')
            ->expectsOutputToContain('n\'est pas une démo')
            ->assertFailed();
    }

    // --- Lien magique : coupé par le mode, pas par le club ------------------

    /**
     * Le mailer étant forcé sur `log`, un lien magique demandé depuis la démo n'arrive jamais :
     * l'écran promettait « un lien t'attend dans ta boîte mail » sur l'onglet coché par défaut,
     * donc l'impasse était le PREMIER geste du visiteur. Le mode le coupe d'office.
     */
    public function test_demo_mode_closes_the_magic_link(): void
    {
        $this->enableDemo();

        $this->assertFalse(app(AuthMethodService::class)->magicLinkEnabled());
    }

    /** Le club décide toujours chez lui : hors démo, l'interrupteur §4.17 reprend la main. */
    public function test_a_real_club_keeps_its_magic_link(): void
    {
        config(['club.demo.enabled' => false]);

        $this->assertTrue(app(AuthMethodService::class)->magicLinkEnabled());
    }

    /**
     * La coupure n'est pas qu'un masquage de formulaire : les 3 routes consomment la même source,
     * donc un visiteur qui connaît les URL ne peut pas contourner l'écran. Sans quoi il obtiendrait
     * un jeton qu'aucun email ne lui livrera — et l'envoi partirait dans les journaux.
     */
    public function test_demo_mode_closes_the_magic_link_routes(): void
    {
        $this->enableDemo();

        // Le contrôleur renvoie vers l'écran de connexion plutôt qu'un 404 : le moyen est
        // publiquement coupé, il n'y a rien à dissimuler (cf. MagicLinkController::send).
        $this->get(route('magic-link.request'))->assertRedirect(route('login'));
        $this->post(route('magic-link.send'), ['email' => 'admin@demo.club'])
            ->assertRedirect(route('login'));
    }

    /**
     * L'écran doit dire la VRAIE raison : « désactivée par le bureau » ferait porter au club de
     * démonstration un choix qu'il n'a pas fait, et laisserait croire que le produit n'a pas la
     * fonctionnalité. Elle existe — c'est cette instance qui ne peut pas l'exercer.
     */
    public function test_the_demo_explains_why_the_magic_link_is_absent(): void
    {
        $this->enableDemo();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('cette démo n\'envoie aucun email', false)
            ->assertDontSee('désactivée par le bureau');
    }

    // --- Écran de connexion -------------------------------------------------

    public function test_login_screen_shows_demo_warning_and_accounts(): void
    {
        $this->enableDemo();

        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertSee('Instance de démonstration')
            ->assertSee('réinitialisées chaque')
            ->assertSee('admin@demo.club')
            // Les DEUX profils de tutelle : le garant « pur » (un pupille) et le garant de deux
            // pupilles à niveaux d'autonomie différents, athlète par ailleurs. Cf. la partial.
            ->assertSee('olivier@demo.club')
            ->assertSee('sandrine@demo.club');
    }

    public function test_login_screen_of_a_real_club_shows_nothing_of_the_demo(): void
    {
        config(['club.demo.enabled' => false]);

        $response = $this->get(route('login'));

        $response->assertOk()
            ->assertDontSee('Instance de démonstration')
            ->assertDontSee('admin@demo.club');
    }

    /**
     * Le chemin nominal, et l'invariant qu'il porte : après un reset, les parcours en base
     * ont TOUS leur fichier sur disque.
     *
     * Ce test naît d'un bug réel : la purge des uploads tournait APRÈS les seeders et
     * effaçait `local:gpx` que GpxRouteSeeder venait de remplir. Les 16 parcours restaient
     * en base avec un gpx_path orphelin → « Tracé indisponible » sur toutes les fiches.
     *
     * Il joue la commande pour de vrai : `migrate:fresh` et suppression des journaux locaux
     * compris. C'est assumé — les journaux sont éphémères et gitignorés, et le seul moyen de
     * verrouiller l'ORDRE des étapes est de les exécuter.
     */
    public function test_reset_leaves_every_seeded_route_with_its_file_on_disk(): void
    {
        $this->enableDemo();

        $this->artisan('demo:reset')->assertSuccessful();

        $routes = GpxRoute::query()->get(['id', 'gpx_path']);

        $this->assertNotEmpty($routes, 'Le seed doit produire des parcours.');

        foreach ($routes as $route) {
            $this->assertTrue(
                Storage::disk('local')->exists($route->gpx_path),
                "Parcours {$route->id} : fichier absent ({$route->gpx_path}). La purge a-t-elle tourné après le seed ?"
            );
        }
    }

    // --- Rappel permanent dans l'app ----------------------------------------

    public function test_connected_screens_carry_the_permanent_demo_reminder(): void
    {
        $this->enableDemo();

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('demo-badge', false)
            ->assertSee('remise à zéro chaque nuit');
    }

    public function test_a_real_club_never_shows_the_reminder(): void
    {
        config(['club.demo.enabled' => false]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('demo-badge', false);
    }

    /**
     * Sur mobile, la pastille vit DANS la topbar verte et non au-dessus du contenu : posée en
     * position:fixed sous la barre, elle recouvrait la première ligne de l'écran (nav semaine du
     * planning, carte d'identité du profil). L'emplacement se vérifie par l'imbrication dans le
     * bloc .topbar — la seule chose qui garantit qu'elle ne peut rien masquer.
     */
    #[DataProvider('screensWithATopbar')]
    public function test_the_reminder_sits_inside_the_topbar_on_every_mobile_screen(string $route): void
    {
        $this->enableDemo();

        $html = $this->actingAs(User::factory()->create())
            ->get($route)
            ->assertOk()
            ->assertSee('demo-badge-bar', false)
            ->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString(
            'demo-badge-bar',
            $this->topbarBlockOf($html, $route),
            "La pastille de démo de {$route} n'est pas dans la topbar : elle recouvrirait l'en-tête."
        );
    }

    /**
     * Extrait le contenu du <div class="topbar…"> en suivant l'imbrication des <div> : le titre
     * en ouvre un, on ne peut donc pas s'arrêter au premier </div> rencontré.
     */
    private function topbarBlockOf(string $html, string $route): string
    {
        $start = strpos($html, '<div class="topbar');
        $this->assertNotFalse($start, "L'écran {$route} n'a pas de topbar mobile.");

        $depth = 0;
        $offset = $start;
        while (preg_match('/<div\b|<\/div>/', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $at = $m[0][1];
            $depth += $m[0][0] === '</div>' ? -1 : 1;
            $offset = $at + strlen($m[0][0]);

            if ($depth === 0) {
                return substr($html, $start, $offset - $start);
            }
        }

        $this->fail("La topbar de {$route} n'est jamais refermée.");
    }

    /** @return array<string, array{string}> */
    public static function screensWithATopbar(): array
    {
        return [
            // Accueil et fiche séance composent leur barre à la main, les autres passent par
            // <x-topbar> : les deux voies doivent porter la pastille.
            'accueil' => ['/dashboard'],
            'planning' => ['/planning'],
            'profil' => ['/profil'],
        ];
    }

    // --- Indexation ---------------------------------------------------------

    public function test_demo_pages_are_marked_noindex(): void
    {
        $this->enableDemo();

        $this->get(route('login'))->assertSee('name="robots"', false);
    }

    public function test_club_pages_are_not_marked_noindex(): void
    {
        config(['club.demo.enabled' => false]);

        $this->get(route('login'))->assertDontSee('name="robots"', false);
    }

    // --- Helper -------------------------------------------------------------

    public function test_enabled_reads_the_configuration(): void
    {
        config(['club.demo.enabled' => false]);
        $this->assertFalse(DemoMode::enabled());

        config(['club.demo.enabled' => true]);
        $this->assertTrue(DemoMode::enabled());
    }
}
