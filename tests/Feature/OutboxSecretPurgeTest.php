<?php

namespace Tests\Feature;

use App\Livewire\Admin\Outbox;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Notifications\OutboxDrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Les jetons d'invitation voyagent en clair dans le payload d'outbox jusqu'à l'envoi. Ils n'ont
// aucune raison d'y survivre : un jeton d'activation vaut la prise du compte visé, et le tiroir
// admin affichait le payload brut — donc, pour tout admin et pour toujours, de quoi entrer dans
// n'importe quel compte invité.
class OutboxSecretPurgeTest extends TestCase
{
    use RefreshDatabase;

    private function ligne(string $status = 'pending'): NotificationOutbox
    {
        return NotificationOutbox::create([
            'type' => 'member_invitation',
            'channel' => 'email',
            'user_id' => User::factory()->create()->id,
            'payload' => ['token' => 'jeton-tres-secret'],
            'status' => $status,
            'available_at' => Carbon::now(),
        ]);
    }

    public function test_token_is_stripped_once_the_line_is_sent(): void
    {
        $ligne = $this->ligne();

        app(OutboxDrainer::class)->drainNow(collect([$ligne]));

        $ligne->refresh();
        $this->assertSame('sent', $ligne->status);
        $this->assertArrayNotHasKey('token', $ligne->payload ?? []);
    }

    public function test_failed_lines_keep_their_token_to_stay_replayable(): void
    {
        // Vider une ligne en échec produirait un lien mort au rejeu depuis l'écran d'envois.
        $ligne = $this->ligne('failed');

        $this->artisan('club:prune-tokens');

        $this->assertSame('jeton-tres-secret', $ligne->fresh()->payload['token'] ?? null);
    }

    public function test_prune_purges_secrets_left_by_previously_sent_lines(): void
    {
        // Rattrapage des lignes écrites avant que le drain ne purge lui-même.
        $ligne = $this->ligne('sent');

        $this->artisan('club:prune-tokens');

        $this->assertArrayNotHasKey('token', $ligne->fresh()->payload ?? []);
    }

    public function test_admin_drawer_never_renders_a_live_token(): void
    {
        // Contrôle positif apparié : la ligne EST bien affichée, c'est le seul jeton qui manque.
        $ligne = $this->ligne();

        Livewire::actingAs(User::factory()->create(['roles' => ['admin']]))
            ->test(Outbox::class)
            ->call('showDetail', $ligne->id)
            ->assertSee('member_invitation')
            ->assertDontSee('jeton-tres-secret');
    }
}
