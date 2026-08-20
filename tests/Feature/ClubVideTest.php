<?php

namespace Tests\Feature;

use App\Livewire\Admin\CatalogueManager;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\Journal;
use App\Livewire\Admin\MemberList;
use App\Livewire\Admin\Outbox;
use App\Livewire\Admin\TemplateList;
use App\Livewire\Alerts;
use App\Livewire\GpxRouteLibrary;
use App\Livewire\Home;
use App\Livewire\InformationPages;
use App\Livewire\Planning;
use App\Livewire\Profil;
use App\Models\Category;
use App\Models\ClubSettings;
use App\Models\Discipline;
use App\Models\Registration;
use App\Models\Session;
use App\Models\SessionTemplate;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jour 1 d'un club fraîchement installé (INSTALL §4).
 *
 * État reproduit : migrations + CatalogSeeder + un unique admin créé par `club:create-admin`.
 * Aucune séance, aucun adhérent, aucun modèle, aucune inscription, aucune notification.
 *
 * C'est l'état que personne ne voit jamais en développement — la base de travail est toujours
 * seedée — et pourtant le tout premier qu'un club rencontre. Un écran qui plante ou qui affiche
 * une zone muette ce jour-là donne la pire première impression possible.
 *
 * Ces tests ne jugent pas l'esthétique : ils vérifient que chaque écran **rend sans erreur** et
 * qu'il **dit quelque chose** plutôt que de laisser un vide. L'appréciation visuelle reste manuelle.
 */
class ClubVideTest extends TestCase
{
    use RefreshDatabase;

    /** Le premier admin, tel que `club:create-admin` le crée : sans catégorie, sans rien autour. */
    private function premierAdmin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Exactement ce que fait une installation neuve (INSTALL §2) : les catalogues, rien d'autre.
        $this->seed(CatalogSeeder::class);
    }

    /** Garde-fou du décor : si un seeder venait à peupler la base, ces tests ne prouveraient plus rien. */
    public function test_the_fixture_really_is_an_empty_club(): void
    {
        $this->assertSame(0, Session::count(), 'aucune séance attendue');
        $this->assertSame(0, Registration::count(), 'aucune inscription attendue');
        $this->assertSame(0, SessionTemplate::count(), 'aucun modèle attendu');
        $this->assertSame(0, User::count(), 'aucun compte avant création de l\'admin');

        // Contrôle positif apparié : les catalogues, eux, sont bien là.
        $this->assertGreaterThan(0, Discipline::count(), 'les disciplines doivent être seedées');
        $this->assertGreaterThan(0, Category::count(), 'les catégories doivent être seedées');
    }

    /** Les paramètres du club s'auto-créent : aucun écran ne doit tomber sur un null. */
    public function test_club_settings_are_created_on_the_fly(): void
    {
        $settings = ClubSettings::current();

        $this->assertNotNull($settings);
        $this->assertNotEmpty($settings->timezone);
    }

    // ─────────── Écrans athlète / membre ───────────

    /**
     * @return array<string, array{class-string}>
     */
    public static function ecransMembre(): array
    {
        return [
            'accueil' => [Home::class],
            'planning' => [Planning::class],
            'profil' => [Profil::class],
            'alertes' => [Alerts::class],
            'pages d\'information' => [InformationPages::class],
            'bibliothèque de parcours' => [GpxRouteLibrary::class],
            // « Mes enfants » (ParentChildren) est volontairement absent : son mount() exige d'être
            // garant d'au moins un pupille (403 sinon). Sur un club vide personne n'a d'enfant
            // rattaché, donc ce 403 est le comportement CORRECT, pas une régression à couvrir ici.
        ];
    }

    /**
     * @param  class-string  $composant
     */
    #[DataProvider('ecransMembre')]
    public function test_member_screens_render_on_an_empty_club(string $composant): void
    {
        Livewire::actingAs($this->premierAdmin())
            ->test($composant)
            ->assertOk();
    }

    // ─────────── Écrans d'administration ───────────

    /**
     * @return array<string, array{class-string}>
     */
    public static function ecransAdmin(): array
    {
        return [
            'adhérents' => [MemberList::class],
            'statistiques' => [Dashboard::class],
            'envois' => [Outbox::class],
            'journaux' => [Journal::class],
            'modèles de séances' => [TemplateList::class],
            // CatalogueManager prend son type en paramètre de route : traité à part ci-dessous.
        ];
    }

    /**
     * @param  class-string  $composant
     */
    #[DataProvider('ecransAdmin')]
    public function test_admin_screens_render_on_an_empty_club(string $composant): void
    {
        Livewire::actingAs($this->premierAdmin())
            ->test($composant)
            ->assertOk();
    }

    /**
     * Les six écrans de catalogue, chacun avec son type. Ils sont seedés par CatalogSeeder pour
     * quatre d'entre eux — mais `quota_tag` et `location` sont VIDES au jour 1, ce qui en fait le
     * vrai test de cette classe : un catalogue sans aucune ligne doit rendre proprement.
     *
     * @return array<string, array{string}>
     */
    public static function typesDeCatalogue(): array
    {
        return [
            'disciplines' => ['discipline'],
            'catégories d\'âge' => ['category'],
            'types d\'épreuve' => ['event_type'],
            'qualifications' => ['qualification'],
            'tags de quota (vide au jour 1)' => ['quota_tag'],
            'lieux (vide au jour 1)' => ['location'],
        ];
    }

    #[DataProvider('typesDeCatalogue')]
    public function test_catalogue_screens_render_on_an_empty_club(string $type): void
    {
        Livewire::actingAs($this->premierAdmin())
            ->test(CatalogueManager::class, ['type' => $type])
            ->assertOk();
    }

    // ─────────── Pas de zone muette ───────────

    /**
     * Un écran vide doit le DIRE. Rendre « sans erreur » ne suffit pas : une zone blanche sans un
     * mot laisse croire à un écran cassé.
     *
     * On assère le libellé EXACT et non une regex floue (`/aucun|vide/i`) : une telle regex matche
     * n'importe où dans la page — y compris dans un filtre ou une infobulle sans rapport — et reste
     * verte même si la vue entière est remplacée par un div vide. Mesuré : c'était le cas de la
     * première version de ces tests.
     *
     * @return array<string, array{class-string, string}>
     */
    public static function ecransAvecMessageDeVide(): array
    {
        return [
            'planning' => [Planning::class, 'Aucune séance cette semaine.'],
            'envois' => [Outbox::class, 'Aucun envoi ne correspond à ces filtres.'],
            // La liste d'adhérents n'est PAS vide au jour 1 : l'admin créé par `club:create-admin`
            // s'y trouve. C'est le comportement correct — un club sans aucun compte n'existe pas.
        ];
    }

    /**
     * @param  class-string  $composant
     */
    #[DataProvider('ecransAvecMessageDeVide')]
    public function test_empty_screens_explain_themselves(string $composant, string $message): void
    {
        Livewire::actingAs($this->premierAdmin())
            ->test($composant)
            ->assertSee($message);
    }

    /**
     * Jour 1 vu de la liste d'adhérents : elle contient exactement le compte d'amorçage, et
     * l'annonce clairement. Le contrôle porte sur le décompte affiché, pas sur un message de vide
     * qui n'a pas lieu d'être ici.
     */
    public function test_member_list_shows_only_the_bootstrap_admin(): void
    {
        $admin = $this->premierAdmin();

        Livewire::actingAs($admin)
            ->test(MemberList::class)
            ->assertSee($admin->fullName())
            ->assertSee('1 actifs');
    }

    // ─────────── Routes publiques ───────────

    public function test_public_routes_respond_on_an_empty_club(): void
    {
        $this->get('/login')->assertOk();
        $this->get('/mentions-legales')->assertOk();
        $this->get('/up')->assertOk();
    }

    /** Le manifest PWA est servi dynamiquement d'après les paramètres du club (§9). */
    public function test_pwa_manifest_is_served_on_an_empty_club(): void
    {
        $this->get('/manifest.webmanifest')->assertOk();
    }
}
