<?php

namespace Tests\Feature;

use App\Support\FrontBuild;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

// Garde-fou contre le déploiement d'un bundle front périmé (cf. App\Support\FrontBuild).
//
// Tous les tests visent une arborescence JETABLE, jamais le vrai public/build/ : le contrôle écrit
// une empreinte et modifie des sources, il n'a rien à faire dans l'arbre du projet. Le conteneur
// est rebindé pour ça — c'est la raison d'être du binding posé dans AppServiceProvider.
class FrontBuildDriftTest extends TestCase
{
    private string $racine;

    private string $bundle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->racine = sys_get_temp_dir().'/coc-front-'.bin2hex(random_bytes(6));
        $this->bundle = $this->racine.'/public/build';

        // Arborescence source minimale, mais fidèle : les mêmes entrées que FrontBuild::SOURCES,
        // un dossier et des fichiers isolés, pour que le parcours récursif soit réellement exercé.
        File::ensureDirectoryExists($this->racine.'/resources/css');
        File::ensureDirectoryExists($this->racine.'/resources/js');
        File::put($this->racine.'/resources/css/app.css', '.a{color:red}');
        File::put($this->racine.'/resources/js/app.js', 'console.log(1)');
        File::put($this->racine.'/vite.config.js', 'export default {}');
        File::put($this->racine.'/package.json', '{"name":"x"}');
        File::put($this->racine.'/package-lock.json', '{"lockfileVersion":3}');

        $this->app->bind(FrontBuild::class, fn () => new FrontBuild($this->racine, $this->bundle));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->racine);

        parent::tearDown();
    }

    /** Simule un `vite build` : le dossier de sortie et son manifeste, sans l'empreinte. */
    private function builder(): void
    {
        File::ensureDirectoryExists($this->bundle);
        File::put($this->bundle.'/manifest.json', '{}');
    }

    public function test_sans_bundle_le_controle_passe(): void
    {
        // Clone frais et CI : rien n'a été buildé, il n'y a rien à mettre en doute.
        $this->artisan('front:check-drift')
            ->expectsOutputToContain('rien à comparer')
            ->assertExitCode(0);
    }

    public function test_un_bundle_sans_empreinte_est_refuse(): void
    {
        // Le cœur du garde-fou : un bundle dont on ignore l'origine ne doit PAS se lire comme un
        // bundle à jour, sinon le silence rétablit exactement la panne qu'on cherche à empêcher.
        $this->builder();

        $this->artisan('front:check-drift')
            ->expectsOutputToContain('sans empreinte')
            ->assertExitCode(1);
    }

    public function test_un_bundle_a_jour_passe(): void
    {
        $this->builder();
        $this->artisan('front:stamp')->assertExitCode(0);

        $this->artisan('front:check-drift')
            ->expectsOutputToContain('Front cohérent')
            ->assertExitCode(0);
    }

    public function test_une_source_modifiee_apres_le_build_est_detectee(): void
    {
        $this->builder();
        $this->artisan('front:stamp')->assertExitCode(0);

        File::put($this->racine.'/resources/css/app.css', '.a{color:blue}');

        $this->artisan('front:check-drift')
            ->expectsOutputToContain('ne correspond plus')
            ->expectsOutputToContain('resources/css/app.css')
            ->assertExitCode(1);
    }

    public function test_une_source_ajoutee_ou_supprimee_apres_le_build_est_detectee(): void
    {
        $this->builder();
        $this->artisan('front:stamp')->assertExitCode(0);

        File::put($this->racine.'/resources/js/nouveau.js', 'export const x = 1');
        File::delete($this->racine.'/resources/js/app.js');

        $this->artisan('front:check-drift')
            ->expectsOutputToContain('resources/js/nouveau.js')
            ->expectsOutputToContain('resources/js/app.js')
            ->assertExitCode(1);
    }

    public function test_une_montee_de_dependance_seule_est_detectee(): void
    {
        // Aucune source du projet ne bouge, mais les bundles produits changent : le lockfile fait
        // partie des entrées du build, au même titre que le CSS.
        $this->builder();
        $this->artisan('front:stamp')->assertExitCode(0);

        File::put($this->racine.'/package-lock.json', '{"lockfileVersion":3,"bump":true}');

        $this->artisan('front:check-drift')
            ->expectsOutputToContain('package-lock.json')
            ->assertExitCode(1);
    }

    public function test_lempreinte_ignore_les_dates_de_modification(): void
    {
        // Contrôle POSITIF apparié au précédent : `git checkout` et `git rebase` réécrivent les
        // mtimes de tous les fichiers touchés. Une empreinte fondée sur les dates virerait au rouge
        // à chaque bascule de branche — et au vert à tort dans l'autre sens.
        $this->builder();
        $this->artisan('front:stamp')->assertExitCode(0);

        touch($this->racine.'/resources/css/app.css', time() + 3600);
        clearstatcache();

        $this->artisan('front:check-drift')
            ->expectsOutputToContain('Front cohérent')
            ->assertExitCode(0);
    }

    public function test_stamp_refuse_de_tamponner_sans_bundle(): void
    {
        // `php artisan front:stamp` lancé seul, hors `npm run build` : il n'y a rien à tamponner,
        // et inscrire une empreinte sans bundle fabriquerait une attestation mensongère.
        $this->artisan('front:stamp')
            ->expectsOutputToContain('Aucun bundle')
            ->assertExitCode(1);
    }
}
