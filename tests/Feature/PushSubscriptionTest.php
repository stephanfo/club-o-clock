<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// J8.6 — Capture/retrait d'un abonnement Web Push pour l'appareil courant (PRD §4.15).
class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'endpoint' => 'https://push.example/abc',
        'keys' => ['p256dh' => 'PUB', 'auth' => 'AUTH'],
        'contentEncoding' => 'aes128gcm',
    ];

    public function test_guest_cannot_subscribe(): void
    {
        // Routes web (le rendu JSON est réservé à api/* — bootstrap/app.php) → garde = redirect login.
        $this->post('/push/subscriptions', $this->payload)->assertRedirect(route('login'));
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_authenticated_user_subscribes_device(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/push/subscriptions', $this->payload)->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $user->id,
            'endpoint_hash' => PushSubscription::hashFor($this->payload['endpoint']),
            'p256dh' => 'PUB',
            'content_encoding' => 'aes128gcm',
        ]);
    }

    public function test_subscribing_twice_with_same_endpoint_is_deduplicated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/push/subscriptions', $this->payload)->assertCreated();
        $this->actingAs($user)->postJson('/push/subscriptions', [
            ...$this->payload,
            'keys' => ['p256dh' => 'PUB2', 'auth' => 'AUTH2'],
        ])->assertCreated();

        $this->assertSame(1, PushSubscription::where('user_id', $user->id)->count());
        $this->assertDatabaseHas('push_subscriptions', ['p256dh' => 'PUB2']); // mis à jour, pas dupliqué
    }

    public function test_subscribe_requires_endpoint_and_keys(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/profil')->post('/push/subscriptions', ['endpoint' => 'https://x'])
            ->assertSessionHasErrors(['keys.p256dh', 'keys.auth']);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_user_unsubscribes_only_own_device(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        PushSubscription::create([
            'user_id' => $other->id,
            'endpoint' => $this->payload['endpoint'],
            'endpoint_hash' => PushSubscription::hashFor($this->payload['endpoint']),
            'p256dh' => 'PUB', 'auth' => 'AUTH', 'content_encoding' => 'aes128gcm',
        ]);

        // L'utilisateur courant n'a pas cet abonnement : la suppression ne touche pas celui d'autrui.
        $this->actingAs($user)->deleteJson('/push/subscriptions', ['endpoint' => $this->payload['endpoint']])
            ->assertNoContent();

        $this->assertDatabaseHas('push_subscriptions', ['user_id' => $other->id]);
    }

    public function test_resync_of_a_known_endpoint_updates_it_without_duplicate(): void
    {
        // #96 : la page renvoie l'abonnement à chaque ouverture (au plus une fois par jour) — le même
        // POST doit rester sans effet de bord hormis la mise à jour de la ligne.
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/push/subscriptions', $this->payload)->assertCreated();
        $this->travel(2)->days();

        $this->actingAs($user)->postJson('/push/subscriptions', $this->payload)->assertCreated();

        $this->assertSame(1, PushSubscription::count());
        $this->assertTrue(PushSubscription::first()->updated_at->isToday());
    }

    public function test_endpoint_of_another_account_follows_the_logged_in_account_and_nothing_else(): void
    {
        // Appareil partagé : l'endpoint est celui du navigateur. Il passe au compte connecté, mais les
        // AUTRES appareils de l'ancien compte ne sont pas touchés.
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$this->payload['endpoint'], 'https://push.example/autre-appareil'] as $endpoint) {
            PushSubscription::create([
                'user_id' => $other->id, 'endpoint' => $endpoint,
                'endpoint_hash' => PushSubscription::hashFor($endpoint),
                'p256dh' => 'PUB', 'auth' => 'AUTH', 'content_encoding' => 'aes128gcm',
            ]);
        }

        $this->actingAs($user)->postJson('/push/subscriptions', $this->payload)->assertCreated();

        $this->assertSame(2, PushSubscription::count());
        $this->assertDatabaseHas('push_subscriptions', ['endpoint_hash' => PushSubscription::hashFor($this->payload['endpoint']), 'user_id' => $user->id]);
        $this->assertDatabaseHas('push_subscriptions', ['endpoint' => 'https://push.example/autre-appareil', 'user_id' => $other->id, 'p256dh' => 'PUB']);
    }

    public function test_fresh_csrf_token_is_served_to_the_logged_in_user_only(): void
    {
        $this->get('/push/jeton')->assertRedirect(route('login'));

        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/push/jeton')->assertOk();

        $this->assertSame(session()->token(), $response->json('token'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
