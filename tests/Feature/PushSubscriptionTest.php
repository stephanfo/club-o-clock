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
}
