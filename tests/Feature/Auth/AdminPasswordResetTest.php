<?php

namespace Tests\Feature\Auth;

use App\Livewire\Admin\MemberShow;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

// Dépannage d'accès par le bureau (PRD §4.1.5). Propriété centrale à protéger : l'admin DÉCLENCHE
// l'envoi, il ne connaît ni ne choisit jamais le mot de passe d'un tiers.
class AdminPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['roles' => ['admin']]);
    }

    public function test_admin_sends_reset_link_to_member(): void
    {
        Notification::fake();
        $membre = User::factory()->create(['email' => 'membre@club.test']);

        Livewire::actingAs($this->admin())->test(MemberShow::class, ['user' => $membre])
            ->call('sendPasswordReset');

        Notification::assertSentTo($membre, ResetPassword::class);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'password_reset_sent',
            'target_id' => $membre->id,
        ]);
    }

    public function test_non_admin_cannot_send_reset_link(): void
    {
        Notification::fake();
        $membre = User::factory()->create(['email' => 'membre@club.test']);

        foreach ([['athlete'], ['coach']] as $roles) {
            Livewire::actingAs(User::factory()->create(['roles' => $roles]))
                ->test(MemberShow::class, ['user' => $membre])
                ->assertForbidden();
        }

        Notification::assertNothingSent();
    }

    public function test_reset_link_refused_for_account_without_email(): void
    {
        Notification::fake();
        $membre = User::factory()->minorP1()->create();

        Livewire::actingAs($this->admin())->test(MemberShow::class, ['user' => $membre])
            ->call('sendPasswordReset');

        Notification::assertNothingSent();
    }

    public function test_reset_link_refused_for_inactive_account(): void
    {
        // is_active=false couvre aussi la demande de suppression en cours (§4.3) : on n'aide pas un
        // compte à revenir pendant que le tampon de 7 jours court.
        Notification::fake();
        $membre = User::factory()->create(['email' => 'membre@club.test', 'is_active' => false]);

        Livewire::actingAs($this->admin())->test(MemberShow::class, ['user' => $membre])
            ->call('sendPasswordReset');

        Notification::assertNothingSent();
    }

    public function test_reset_link_refused_in_demo_mode(): void
    {
        // Le mailer est forcé sur `log` en démo : le lien promis n'arriverait jamais.
        Notification::fake();
        config(['club.demo.enabled' => true]);
        $membre = User::factory()->create(['email' => 'membre@club.test']);

        Livewire::actingAs($this->admin())->test(MemberShow::class, ['user' => $membre])
            ->call('sendPasswordReset');

        Notification::assertNothingSent();
    }

    public function test_password_reset_wipes_every_session_of_the_user(): void
    {
        // CompletePasswordReset régénère remember_token mais laisse vivre les lignes de session :
        // sans le listener, un attaquant encore connecté le resterait après le reset.
        $membre = User::factory()->create(['email' => 'membre@club.test', 'password' => 'password']);
        DB::table(config('session.table'))->insert([
            'id' => 'session-attaquant',
            'user_id' => $membre->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'Firefox',
            'payload' => base64_encode('x'),
            'last_activity' => now()->getTimestamp(),
        ]);

        $token = app('auth.password.broker')->createToken($membre);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'membre@club.test',
            'password' => 'motdepassesolide',
            'password_confirmation' => 'motdepassesolide',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing(config('session.table'), ['id' => 'session-attaquant']);
    }

    public function test_reset_token_older_than_fifteen_minutes_is_refused(): void
    {
        // PRD §4.1.1 : TTL 15 min, aligné sur le lien magique.
        $membre = User::factory()->create(['email' => 'membre@club.test', 'password' => 'password']);
        $token = app('auth.password.broker')->createToken($membre);

        $this->travel(16)->minutes();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'membre@club.test',
            'password' => 'motdepassesolide',
            'password_confirmation' => 'motdepassesolide',
        ])->assertSessionHasErrors();

        $this->travelBack();
    }
}
