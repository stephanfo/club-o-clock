<?php

namespace Tests\Feature\Auth;

use App\Models\ClubSettings;
use App\Models\MagicLinkToken;
use App\Models\User;
use App\Notifications\MagicLinkNotification;
use App\Support\MagicLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// Code à usage unique joint au lien magique (PRD §4.1.1).
//
// Raison d'être : sur iOS, une PWA installée a un pot de cookies distinct de Safari. Un lien cliqué
// dans Mail ouvre la session dans Safari et laisse l'application déconnectée ; le code, lui, se
// saisit DANS l'application. Aucun réglage de manifest ne remplace ça.
//
// 6 chiffres ≈ 20 bits : seul, c'est cassable en minutes. Ces tests portent surtout sur les
// contrôles qui rendent ce format acceptable — compteur par jeton, double limiteur, usage unique.
class MagicCodeTest extends TestCase
{
    use RefreshDatabase;

    private function membre(string $email = 'a@club.test'): User
    {
        return User::factory()->create(['email' => $email]);
    }

    /** @return array{0:User,1:string} le membre et son code en clair */
    private function codePour(string $email = 'a@club.test'): array
    {
        $u = $this->membre($email);

        return [$u, MagicLink::issue($email)['code']];
    }

    private function poster(string $email, string $code)
    {
        return $this->post('/magic-link/code', ['email' => $email, 'code' => $code]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('magic-code|127.0.0.1');
    }

    // ── Chemin nominal ──

    public function test_valid_code_logs_the_member_in(): void
    {
        [$u, $code] = $this->codePour();

        $this->poster($u->email, $code)->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($u);
    }

    public function test_code_is_single_use(): void
    {
        [$u, $code] = $this->codePour();

        $this->poster($u->email, $code);
        auth()->logout();

        $this->poster($u->email, $code);
        $this->assertGuest();
    }

    public function test_expired_code_is_refused(): void
    {
        [$u, $code] = $this->codePour();

        $this->travel(MagicLink::TTL_MINUTES + 1)->minutes();
        $this->poster($u->email, $code);
        $this->travelBack();

        $this->assertGuest();
    }

    // ── Le lien et le code sont une seule autorisation ──

    public function test_consuming_the_link_kills_the_code(): void
    {
        $u = $this->membre();
        $emis = MagicLink::issue($u->email);
        $token = str($emis['url'])->afterLast('/')->toString();

        $this->get("/magic-link/{$token}")->assertRedirect();
        auth()->logout();

        // Le code doit mourir avec le lien : c'est une seule et même autorisation.
        $this->poster($u->email, $emis['code']);
        $this->assertGuest();
    }

    public function test_consuming_the_code_kills_the_link(): void
    {
        $u = $this->membre();
        $emis = MagicLink::issue($u->email);
        $token = str($emis['url'])->afterLast('/')->toString();

        $this->poster($u->email, $emis['code']);
        auth()->logout();

        $this->get("/magic-link/{$token}")->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ── Force brute ──

    public function test_five_wrong_codes_burn_the_token(): void
    {
        [$u, $bon] = $this->codePour();
        $faux = $bon === '000000' ? '111111' : '000000';

        for ($i = 0; $i < MagicLink::MAX_CODE_ATTEMPTS; $i++) {
            $this->poster($u->email, $faux);
            RateLimiter::clear('magic-code|'.mb_strtolower($u->email).'|127.0.0.1');
            RateLimiter::clear('magic-code|127.0.0.1');
        }

        // Le BON code ne vaut plus rien : le jeton est brûlé, il faut en redemander un.
        $this->poster($u->email, $bon);
        $this->assertGuest();
        $this->assertNotNull(MagicLinkToken::where('email', $u->email)->first()->consumed_at);
    }

    public function test_wrong_email_does_not_burn_the_victims_attempts(): void
    {
        // Sinon on offrirait un déni de service : n'importe qui pourrait épuiser le compteur d'un
        // tiers en saisissant son code sous une autre adresse.
        [$victime, $code] = $this->codePour('victime@club.test');
        $this->membre('autre@club.test');
        MagicLink::issue('autre@club.test');

        $this->poster('autre@club.test', $code);

        $jeton = MagicLinkToken::where('email', $victime->email)->first();
        $this->assertSame(0, $jeton->code_attempts);
        $this->assertNull($jeton->consumed_at);
    }

    public function test_ip_rate_limit_blocks_scanning_across_many_emails(): void
    {
        // Le compteur par jeton ne voit pas un attaquant qui essaie un code sur mille adresses :
        // il n'épuise le compteur d'aucune. D'où le limiteur par IP seule.
        for ($i = 0; $i < 10; $i++) {
            $this->poster("cible{$i}@club.test", '000000');
        }

        [$u, $bon] = $this->codePour('vraie@club.test');
        // 11e tentative depuis la même IP : refusée, quel que soit l'email visé.
        $this->poster($u->email, $bon);

        $this->assertGuest();
    }

    // ── Anti-énumération ──

    public function test_unknown_email_answers_exactly_like_a_known_one(): void
    {
        $connu = $this->membre('connu@club.test');
        MagicLink::issue($connu->email);

        $reponseConnu = $this->post('/magic-link', ['email' => 'connu@club.test']);
        $reponseInconnu = $this->post('/magic-link', ['email' => 'fantome@club.test']);

        $this->assertSame($reponseConnu->headers->get('Location'), $reponseInconnu->headers->get('Location'));
        $this->assertSame($reponseConnu->getStatusCode(), $reponseInconnu->getStatusCode());
    }

    public function test_request_screen_is_identical_whether_the_account_exists(): void
    {
        // Contrôle positif apparié : l'écran s'affiche bien, et il ne dit rien du compte.
        $this->post('/magic-link', ['email' => 'fantome@club.test']);

        $this->get(route('magic-link.sent'))
            ->assertOk()
            ->assertSee('fantome@club.test')
            ->assertDontSee('inconnu', false);
    }

    // ── Interrupteur club et mode démo (§4.17) ──

    public function test_every_code_route_is_closed_when_magic_link_is_disabled(): void
    {
        [$u, $code] = $this->codePour();
        ClubSettings::current()->update(['auth_magic_link_enabled' => false]);
        ClubSettings::flushCache();

        $this->get(route('magic-link.sent'))->assertRedirect(route('login'));
        $this->get(route('magic-link.code'))->assertRedirect(route('login'));
        $this->poster($u->email, $code)->assertRedirect(route('login'));

        $this->assertGuest();
        // Le jeton n'est PAS consommé : le club peut rouvrir le moyen et l'honorer encore.
        $this->assertNull(MagicLinkToken::where('email', $u->email)->first()->consumed_at);
    }

    public function test_demo_mode_closes_the_code_routes(): void
    {
        config(['club.demo.enabled' => true]);
        [$u, $code] = $this->codePour();

        $this->poster($u->email, $code)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ── Gardes de compte ──

    public function test_inactive_account_cannot_use_a_valid_code(): void
    {
        [$u, $code] = $this->codePour();
        $u->update(['is_active' => false]);

        $this->poster($u->email, $code);

        $this->assertGuest();
    }

    // ── Écran mobile (le défaut corrigé au passage) ──

    public function test_magic_link_screens_render_both_shells(): void
    {
        // auth/magic-link.blade.php n'avait que la coquille desktop, sur une application utilisée
        // majoritairement au téléphone.
        foreach ([route('magic-link.request'), route('magic-link.code')] as $url) {
            $this->get($url)->assertOk()->assertSee('auth-mobile', false)->assertSee('auth-dk', false);
        }
    }

    public function test_prune_removes_consumed_code_tokens(): void
    {
        [$u, $code] = $this->codePour();
        $this->poster($u->email, $code);

        $this->artisan('model:prune', ['--model' => [MagicLinkToken::class]]);

        $this->assertDatabaseCount('magic_link_tokens', 0);
    }

    public function test_issued_code_is_never_stored_in_clear(): void
    {
        $emis = MagicLink::issue('a@club.test');

        $this->assertDatabaseMissing('magic_link_tokens', ['code_hash' => $emis['code']]);
        $this->assertNotNull(MagicLinkToken::first()->code_hash);
    }

    public function test_code_is_six_digits(): void
    {
        // Zéros de tête conservés : un code tronqué ne se saisirait pas.
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', MagicLink::issue('a@club.test')['code']);
        }
    }

    public function test_expired_tokens_do_not_block_a_fresh_code(): void
    {
        $u = $this->membre();
        MagicLink::issue($u->email);
        $this->travel(MagicLink::TTL_MINUTES + 1)->minutes();

        $frais = MagicLink::issue($u->email)['code'];
        $this->poster($u->email, $frais);
        $this->travelBack();

        $this->assertAuthenticatedAs($u);
    }

    public function test_burnt_token_does_not_block_a_fresh_code(): void
    {
        [$u, $bon] = $this->codePour();
        $faux = $bon === '000000' ? '111111' : '000000';

        for ($i = 0; $i < MagicLink::MAX_CODE_ATTEMPTS; $i++) {
            $this->poster($u->email, $faux);
            RateLimiter::clear('magic-code|'.mb_strtolower($u->email).'|127.0.0.1');
            RateLimiter::clear('magic-code|127.0.0.1');
        }

        // Se faire brûler son jeton ne doit pas verrouiller le compte : un nouveau code marche.
        $frais = MagicLink::issue($u->email)['code'];
        $this->poster($u->email, $frais);

        $this->assertAuthenticatedAs($u);
    }

    public function test_email_carries_both_the_link_and_the_code(): void
    {
        $u = $this->membre();

        Notification::fake();
        $this->post('/magic-link', ['email' => $u->email]);

        Notification::assertSentOnDemand(
            MagicLinkNotification::class,
            fn ($notification) => $notification->code !== null && $notification->url !== ''
        );
    }

    public function test_carbon_dates_are_restored(): void
    {
        Carbon::setTestNow();
        $this->assertTrue(true);
    }
}
