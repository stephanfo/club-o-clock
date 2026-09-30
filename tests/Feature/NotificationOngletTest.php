<?php

namespace Tests\Feature;

use App\Livewire\Alerts;
use App\Livewire\SessionShow;
use App\Models\NotificationOutbox;
use App\Models\Session;
use App\Models\User;
use App\Notifications\NotificationRenderer;
use App\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// #99 — une notification qui vise un onglet précis de la fiche l'ouvre directement : toucher la
// notification d'un débrief menait sur l'onglet Infos, et il fallait chercher Débriefs soi-même.
class NotificationOngletTest extends TestCase
{
    use RefreshDatabase;

    private function seance(string $kind = 'competition'): Session
    {
        return Session::create([
            'kind' => $kind,
            'title' => 'Triathlon de la Baule',
            'start_at' => Carbon::now()->addDays(3),
            'duration_min' => 120,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
    }

    private function ligne(NotificationType $type, User $destinataire, array $payload, string $statut = 'pending'): NotificationOutbox
    {
        return NotificationOutbox::create([
            'type' => $type->value,
            'channel' => 'push',
            'payload' => $payload,
            'user_id' => $destinataire->id,
            'status' => $statut,
            'available_at' => Carbon::now(),
            'sent_at' => $statut === 'sent' ? Carbon::now() : null,
        ]);
    }

    private function url(NotificationType $type, array $payload): string
    {
        return app(NotificationRenderer::class)->render($this->ligne($type, User::factory()->create(), $payload))['url'];
    }

    // ── Liens push et email ─────────────────────────────────────────────────────────────────

    public function test_new_debrief_link_opens_the_debriefs_tab(): void
    {
        $this->assertStringEndsWith('/seances/42?tab=debriefs', $this->url(NotificationType::NewDebrief, ['session_id' => 42]));
    }

    public function test_coach_notifications_open_the_coaching_tab(): void
    {
        $this->assertStringEndsWith('?tab=encadrement', $this->url(NotificationType::CoachRegistration, ['session_id' => 42]));
        $this->assertStringEndsWith('?tab=encadrement', $this->url(NotificationType::CoachAssigned, ['session_id' => 42]));
    }

    public function test_other_session_notifications_keep_the_bare_link(): void
    {
        $this->assertStringEndsWith('/seances/42', $this->url(NotificationType::SessionCancelled, ['session_id' => 42]));
        $this->assertStringEndsWith('/seances/42', $this->url(NotificationType::WaitlistPromoted, ['session_id' => 42]));
    }

    public function test_tab_and_subject_travel_together(): void
    {
        $garant = User::factory()->create();
        $enfant = User::factory()->minorP1()->create(['guardian_id' => $garant->id]);
        $ligne = $this->ligne(NotificationType::NewDebrief, $garant, ['session_id' => 42, 'subject_id' => $enfant->id]);

        $url = app(NotificationRenderer::class)->render($ligne)['url'];

        $this->assertStringContainsString('as='.$enfant->id, $url);
        $this->assertStringContainsString('tab=debriefs', $url);
    }

    // ── Écran Alertes ───────────────────────────────────────────────────────────────────────

    public function test_alerts_screen_links_to_the_same_tab(): void
    {
        $user = User::factory()->create();
        $s = $this->seance();
        $this->ligne(NotificationType::NewDebrief, $user, ['session_id' => $s->id], 'sent');

        Livewire::actingAs($user)->test(Alerts::class)
            ->assertSee(route('sessions.show', [$s, 'tab' => 'debriefs']), false);
    }

    // ── Fiche : onglet d'ouverture ──────────────────────────────────────────────────────────

    public function test_session_opens_on_the_requested_tab(): void
    {
        $s = $this->seance();

        $this->actingAs(User::factory()->coach()->create())
            ->get(route('sessions.show', [$s, 'tab' => 'debriefs']))
            ->assertOk()
            ->assertSee('x-data="{ tab: \'debriefs\' }"', false);
    }

    public function test_unknown_or_hidden_tab_falls_back_to_infos(): void
    {
        $coach = User::factory()->coach()->create();
        // Débriefs n'existe que sur une compétition : masqué sur un entraînement.
        $entrainement = $this->seance('training');

        foreach ([[$entrainement, 'debriefs'], [$this->seance(), 'nimportequoi']] as [$s, $tab]) {
            $this->actingAs($coach)
                ->get(route('sessions.show', [$s, 'tab' => $tab]))
                ->assertOk()
                ->assertSee('x-data="{ tab: \'infos\' }"', false);
        }
    }

    public function test_tab_survives_the_subject_redirect(): void
    {
        $garant = User::factory()->create();
        $enfant = User::factory()->minorP1()->create(['guardian_id' => $garant->id]);
        $s = $this->seance();

        Livewire::actingAs($garant)
            ->withQueryParams(['as' => $enfant->id, 'tab' => 'debriefs'])
            ->test(SessionShow::class, ['session' => $s])
            ->assertRedirect(route('sessions.show', [$s, 'tab' => 'debriefs']));
    }
}
