<?php

namespace Tests\Feature;

use App\Livewire\Admin\Outbox;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Vérifie que la garde du trait AuthorizesAdminGate (hook boot) protège les ACTIONS, pas que le mount. */
class AdminGateBootTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_admin_action_call_is_forbidden_via_boot_hook(): void
    {
        $coach = User::factory()->create(['roles' => ['coach']]);

        // boot() re-vérifie la gate à CHAQUE requête (mount ET actions) : le non-admin est
        // refusé dès le mount et n'obtient jamais de composant vivant sur lequel appeler une
        // action — c'est l'enjeu du refactor (garde structurelle, pas un authorize par action).
        Livewire::actingAs($coach)
            ->test(Outbox::class)
            ->assertForbidden();
    }

    public function test_admin_can_mount(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);

        Livewire::actingAs($admin)
            ->test(Outbox::class)
            ->assertOk();
    }
}
