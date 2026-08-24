<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
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

    // ── Chaînes du framework à clé JSON (gabarit d'email) ──

    /**
     * Le gabarit de notification par email n'utilise PAS de clés `fichier.cle` : il appelle
     * `@lang('Regards,')`, `@lang('Hello!')` et la ligne de repli du bouton avec la PHRASE ANGLAISE
     * pour clé. Ces chaînes-là ne vivent pas dans `lang/fr/*.php` mais dans `lang/fr.json` — les
     * ignorer laissait le pied de TOUS les emails du club en anglais, sans que rien ne le voie.
     *
     * @return array<int, array<int, string>>
     */
    public static function chainesDuGabaritMail(): array
    {
        return [
            ['Hello!'],
            ['Whoops!'],
            ['Regards,'],
            ['All rights reserved.'],
            ["If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:"],
        ];
    }

    #[DataProvider('chainesDuGabaritMail')]
    public function test_the_mail_template_strings_are_translated(string $chaine): void
    {
        $this->assertNotSame($chaine, __($chaine),
            "lang/fr.json ne traduit pas « {$chaine} » — le gabarit d'email la rendrait en anglais.");
    }

    public function test_the_password_reset_email_is_written_in_french(): void
    {
        // Le seul email que Laravel compose lui-même : ses lignes sont à clé JSON, donc rien dans
        // lang/fr/*.php ne pouvait les traduire. Le bouton admin « envoyer un lien » expédiait un
        // mail intégralement anglais (« Hello! », « Reset Password », « This password reset link
        // will expire in :count minutes. ») à un adhérent francophone. App\Notifications\
        // ResetPasswordNotification le remplace, branchée par User::sendPasswordResetNotification().
        $membre = User::factory()->create(['email' => 'membre@club.test']);

        $rendu = (new ResetPasswordNotification('un-jeton'))->toMail($membre)->render()->toHtml();

        $this->assertStringContainsString('Bonjour,', $rendu);
        $this->assertStringContainsString('Choisir un nouveau mot de passe', $rendu);
        $this->assertStringContainsString('À bientôt,', $rendu);

        foreach (['Hello!', 'Reset Password', 'Regards,', 'no further action is required', 'having trouble clicking'] as $anglais) {
            $this->assertStringNotContainsString($anglais, $rendu,
                "L'email de réinitialisation contient encore « {$anglais} ».");
        }
    }

    public function test_the_password_reset_flow_actually_sends_the_french_notification(): void
    {
        // Le test ci-dessus rend la classe à la main : il resterait vert si plus personne ne
        // l'envoyait. Celui-ci vérifie le BRANCHEMENT — User::sendPasswordResetNotification() —
        // sans lequel le broker repartirait sur la notification anglaise de Laravel.
        Notification::fake();
        $membre = User::factory()->create(['email' => 'membre@club.test']);

        Password::broker()->sendResetLink(['email' => 'membre@club.test']);

        Notification::assertSentTo($membre, ResetPasswordNotification::class);
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
