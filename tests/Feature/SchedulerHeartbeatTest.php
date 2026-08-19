<?php

namespace Tests\Feature;

use App\Livewire\Admin\Outbox;
use App\Models\User;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

// Supervision du cron (INSTALL §5.4). Tout l'envoi différé dépend d'un cron unique appelant
// `schedule:run` ; s'il meurt, les notifications s'accumulent sans que rien ne le signale.
// Ces tests couvrent le battement de cœur, les trois états de supervision, et leur restitution
// sur l'écran des envois — y compris le refus d'alerter sur une installation neuve.
class SchedulerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function heartbeat(): SchedulerHeartbeatService
    {
        return app(SchedulerHeartbeatService::class);
    }

    // ─────────── Le service ───────────

    public function test_status_is_unknown_when_never_observed(): void
    {
        $this->assertSame('unknown', $this->heartbeat()->status()['state']);
        $this->assertNull($this->heartbeat()->lastRunAt());
    }

    public function test_beat_records_a_recent_run(): void
    {
        $this->heartbeat()->beat();

        $status = $this->heartbeat()->status();

        $this->assertSame('ok', $status['state']);
        $this->assertSame(0, $status['minutes']);
        $this->assertNotNull($status['last']);
    }

    public function test_status_turns_stale_past_the_threshold(): void
    {
        // Un battement juste sous le seuil reste sain…
        $this->heartbeat()->beat(Carbon::now()->subMinutes(SchedulerHeartbeatService::STALE_AFTER_MINUTES - 1));
        $this->assertSame('ok', $this->heartbeat()->status()['state']);

        // …et bascule une fois le seuil atteint (contrôle positif apparié à l'assertion négative).
        $this->heartbeat()->beat(Carbon::now()->subMinutes(SchedulerHeartbeatService::STALE_AFTER_MINUTES));
        $this->assertSame('stale', $this->heartbeat()->status()['state']);
    }

    public function test_stale_status_reports_the_age(): void
    {
        $this->heartbeat()->beat(Carbon::now()->subHours(3));

        $status = $this->heartbeat()->status();

        $this->assertSame('stale', $status['state']);
        $this->assertSame(180, $status['minutes']);
    }

    /** Horloge serveur reculée : un horodatage futur ne doit pas donner un âge négatif « frais » à vie. */
    public function test_future_timestamp_does_not_produce_a_negative_age(): void
    {
        $this->heartbeat()->beat(Carbon::now()->addHours(2));

        $status = $this->heartbeat()->status();

        $this->assertSame(0, $status['minutes']);
        $this->assertSame('ok', $status['state']);
    }

    /** Le cache vidé remet le voyant à « inconnu » — état neutre, jamais une fausse panne. */
    public function test_cleared_cache_falls_back_to_unknown(): void
    {
        $this->heartbeat()->beat();
        $this->assertSame('ok', $this->heartbeat()->status()['state']);

        Cache::clear();

        $this->assertSame('unknown', $this->heartbeat()->status()['state']);
    }

    /**
     * Âge lisible : les bascules d'unité. « il y a 180 min » est exact mais demande un calcul
     * mental — c'est le défaut qu'un sabotage du seuil avait rendu visible à l'écran.
     */
    public function test_human_age_switches_units(): void
    {
        $h = $this->heartbeat();

        $this->assertSame("à l'instant", $h->humanAge(0));
        $this->assertSame('il y a 7 min', $h->humanAge(7));
        $this->assertSame('il y a 119 min', $h->humanAge(119));   // dernière minute en minutes
        $this->assertSame('il y a 2 h', $h->humanAge(120));       // bascule en heures
        $this->assertSame('il y a 3 h', $h->humanAge(180));
        $this->assertSame('il y a 47 h', $h->humanAge(2879));     // dernière heure en heures
        $this->assertSame('il y a 2 jours', $h->humanAge(2880));  // bascule en jours
    }

    /** L'écran ne doit jamais afficher une durée en minutes au-delà de deux heures. */
    public function test_outbox_screen_renders_a_readable_age(): void
    {
        $this->heartbeat()->beat(Carbon::now()->subHours(3));

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Outbox::class)
            ->assertSee('il y a 3 h')
            ->assertDontSee('il y a 180 min');
    }

    // ─────────── La commande planifiée ───────────

    /** Le battement est posé même quand la file est VIDE : c'est le cas qui rend la panne invisible. */
    public function test_drain_command_beats_even_with_an_empty_outbox(): void
    {
        $this->assertSame('unknown', $this->heartbeat()->status()['state']);

        $this->artisan('notifications:drain')->assertSuccessful();

        $this->assertSame('ok', $this->heartbeat()->status()['state']);
    }

    // ─────────── L'écran des envois ───────────

    public function test_outbox_screen_warns_when_scheduler_is_stale(): void
    {
        $this->heartbeat()->beat(Carbon::now()->subHours(3));

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Outbox::class)
            ->assertSee('Traitement automatique interrompu')
            ->assertSee('Les notifications ne partent plus')
            // Contrôle positif apparié : l'état sain n'est pas annoncé en même temps.
            ->assertDontSee('traitement automatique actif');
    }

    public function test_outbox_screen_shows_healthy_state_discreetly(): void
    {
        $this->heartbeat()->beat();

        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Outbox::class)
            ->assertSee('traitement automatique actif')
            ->assertDontSee('Traitement automatique interrompu');
    }

    /** Installation neuve : on informe, on n'alarme pas. */
    public function test_outbox_screen_does_not_alarm_on_a_fresh_install(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Outbox::class)
            ->assertSee('Traitement automatique jamais observé')
            ->assertDontSee('Traitement automatique interrompu')
            ->assertDontSee('Les notifications ne partent plus');
    }
}
