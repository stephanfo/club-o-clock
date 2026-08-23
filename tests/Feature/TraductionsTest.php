<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Traductions FR des messages du framework (§ « Langue du projet : français »).
//
// Le trou que ces tests ferment : `lang/fr/` ne contenait que `validation.php`. L'application tourne
// en locale `fr` AVEC `fr` en repli — donc personne ne traduisait `auth.*`, `passwords.*` ni
// `pagination.*`, et Laravel rend alors la CLÉ. Se tromper de mot de passe affichait littéralement
// « auth.failed » à l'écran de connexion.
class TraductionsTest extends TestCase
{
    use RefreshDatabase;

    /** Fichiers de langue du framework dont l'application doit fournir la contrepartie FR. */
    private const FICHIERS = ['auth', 'passwords', 'pagination', 'validation'];

    private function aplatir(array $lignes, string $prefixe = ''): array
    {
        $plat = [];
        foreach ($lignes as $cle => $valeur) {
            $chemin = $prefixe === '' ? (string) $cle : $prefixe.'.'.$cle;
            $plat += is_array($valeur) ? $this->aplatir($valeur, $chemin) : [$chemin => $valeur];
        }

        return $plat;
    }

    public function test_no_framework_key_is_left_untranslated(): void
    {
        // Garde de non-régression contre la classe entière de bug : une montée de version de Laravel
        // qui ajoute une clé (c'est ainsi que `array_keys` et `base64` manquaient) la ferait
        // apparaître en anglais — ou en clé brute — sans que rien ne le signale.
        $base = base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en');

        foreach (self::FICHIERS as $fichier) {
            $en = $this->aplatir(require "{$base}/{$fichier}.php");
            $fr = $this->aplatir(require lang_path("fr/{$fichier}.php"));

            $manquantes = array_diff_key($en, $fr);
            $this->assertSame([], array_keys($manquantes),
                "Clés absentes de lang/fr/{$fichier}.php : ".implode(', ', array_keys($manquantes)));
        }
    }

    public function test_a_wrong_password_answers_in_french(): void
    {
        // Le symptôme signalé : « auth.failed » affiché tel quel.
        User::factory()->create(['email' => 'membre@club.test', 'password' => 'un-mot-de-passe-long']);

        $this->post('/login', ['email' => 'membre@club.test', 'password' => 'faux'])
            ->assertSessionHasErrors(['email' => 'Ces identifiants ne correspondent à aucun compte.']);
    }

    public function test_no_visible_message_still_looks_like_a_translation_key(): void
    {
        // Contrôle générique : aucune valeur rendue ne doit ressembler à `fichier.cle`. Vaut pour
        // les messages qu'on n'a pas énumérés un par un.
        foreach (self::FICHIERS as $fichier) {
            foreach ($this->aplatir(require lang_path("fr/{$fichier}.php")) as $cle => $valeur) {
                $this->assertDoesNotMatchRegularExpression('/^[a-z_]+\.[a-z_]+$/', $valeur,
                    "lang/fr/{$fichier}.php : « {$cle} » ressemble encore à une clé.");
            }
        }
    }

    public function test_the_reset_link_answer_does_not_reveal_whether_the_account_exists(): void
    {
        // Le lien magique se donne du mal à taire l'existence d'un compte (§4.1.1) : le message du
        // password broker ne doit pas le dire à sa place. « We can't find a user with that email
        // address » traduit littéralement aurait fait de cet écran un oracle d'énumération lisible.
        $this->assertStringNotContainsString('aucun compte', mb_strtolower(__('passwords.user')));
        $this->assertStringNotContainsString('introuvable', mb_strtolower(__('passwords.user')));
    }
}
