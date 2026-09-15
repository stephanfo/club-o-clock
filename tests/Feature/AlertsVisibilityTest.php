<?php

namespace Tests\Feature;

use App\Livewire\Alerts;
use App\Models\NotificationOutbox;
use App\Models\Session;
use App\Models\User;
use App\Services\OutboxAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Page Alertes (#79) : visibilité liée à la séance, retrait manuel, regroupement parent/enfants.
 *
 * Retour d'une adhérente parent et athlète : la liste s'allongeait d'alertes périmées, triplait
 * chaque annonce (elle + deux enfants) et perdait une compétition annoncée trois mois plus tôt
 * avant qu'elle ait eu lieu, faute de fenêtre calée sur la séance.
 */
class AlertsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function seance(array $attrs = []): Session
    {
        return Session::create(array_merge([
            'kind' => 'training',
            'title' => 'SwimRun',
            'start_at' => Carbon::now()->addDays(10)->setTime(9, 0),
            'duration_min' => 120,
            'created_by' => User::factory()->coach()->create()->id,
        ], $attrs));
    }

    /** created_at n'est pas fillable : forceFill, pour vieillir une ligne. */
    private function alerte(User $u, array $payload = [], array $attrs = []): NotificationOutbox
    {
        $ligne = new NotificationOutbox;
        $ligne->forceFill(array_merge([
            'type' => 'event_created', 'channel' => 'push', 'payload' => $payload,
            'user_id' => $u->id, 'status' => 'sent', 'sent_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ], $attrs))->save();

        return $ligne;
    }

    private function visibles(User $u): array
    {
        return NotificationOutbox::alertsFor($u->id)->pluck('id')->all();
    }

    // ── Disparition automatique ─────────────────────────────────────────────────────────────────

    public function test_une_alerte_de_seance_vit_jusqua_j7_apres_la_fin(): void
    {
        $u = User::factory()->create();
        // Fin il y a 6 jours 23 h → visible ; fin il y a 7 jours 1 h → expirée.
        $recente = $this->seance(['start_at' => Carbon::now()->subDays(7)->addHour()->subMinutes(120)]);
        $vieille = $this->seance(['start_at' => Carbon::now()->subDays(7)->subHour()->subMinutes(120)]);
        $garde = $this->alerte($u, ['session_id' => $recente->id]);
        $perdue = $this->alerte($u, ['session_id' => $vieille->id]);

        $this->assertContains($garde->id, $this->visibles($u));
        $this->assertNotContains($perdue->id, $this->visibles($u));
    }

    public function test_une_annonce_tres_ancienne_dune_seance_a_venir_reste_visible(): void
    {
        $u = User::factory()->create();
        $alerte = $this->alerte($u, ['session_id' => $this->seance()->id], [
            'created_at' => Carbon::now()->subDays(90), 'sent_at' => Carbon::now()->subDays(90),
        ]);

        $this->assertSame([$alerte->id], $this->visibles($u));
    }

    public function test_une_seance_annulee_suit_sa_fin_prevue(): void
    {
        $u = User::factory()->create();
        $avenir = $this->seance();
        $passee = $this->seance(['start_at' => Carbon::now()->subDays(10)]);
        foreach ([$avenir, $passee] as $s) {
            $s->forceFill(['cancelled_at' => Carbon::now()])->save();
        }
        $garde = $this->alerte($u, ['session_id' => $avenir->id], ['type' => 'session_cancelled']);
        $this->alerte($u, ['session_id' => $passee->id], ['type' => 'session_cancelled']);

        $this->assertSame([$garde->id], $this->visibles($u));
    }

    public function test_une_seance_supprimee_suit_le_creneau_fige_au_payload(): void
    {
        $u = User::factory()->create();
        $garde = $this->alerte($u, ['session_title' => 'X', 'session_start_at' => Carbon::now()->subDays(6)->toIso8601String()]);
        $this->alerte($u, ['session_title' => 'Y', 'session_start_at' => Carbon::now()->subDays(8)->toIso8601String()]);

        $this->assertSame([$garde->id], $this->visibles($u));
    }

    public function test_une_alerte_sans_seance_disparait_60_jours_apres_lenvoi(): void
    {
        $u = User::factory()->create();
        $garde = $this->alerte($u, [], ['type' => 'guardianship_invitation', 'created_at' => Carbon::now()->subDays(59)]);
        $this->alerte($u, [], ['type' => 'guardianship_invitation', 'created_at' => Carbon::now()->subDays(61)]);

        $this->assertSame([$garde->id], $this->visibles($u));
    }

    // ── Retrait manuel ──────────────────────────────────────────────────────────────────────────

    public function test_la_croix_masque_lalerte_et_fait_baisser_le_badge(): void
    {
        $u = User::factory()->create();
        $masquee = $this->alerte($u, ['session_id' => $this->seance()->id]);
        $gardee = $this->alerte($u, ['session_id' => $this->seance(['title' => 'Trail'])->id]);

        Livewire::actingAs($u)->test(Alerts::class)->call('dismiss', [$masquee->id]);

        $this->assertSame([$gardee->id], $this->visibles($u));
        $this->assertNotNull($masquee->fresh()->dismissed_at);

        // Badge : une ligne non lue masquée sort du compte (mount a marqué les autres lues).
        $nonLue = $this->alerte($u, ['session_id' => $this->seance()->id]);
        NotificationOutbox::forgetUnreadCount();
        $this->assertSame(1, NotificationOutbox::unreadCountFor($u->id));
        $nonLue->forceFill(['dismissed_at' => Carbon::now()])->save();
        NotificationOutbox::forgetUnreadCount();
        $this->assertSame(0, NotificationOutbox::unreadCountFor($u->id));
    }

    public function test_tout_effacer_masque_toutes_les_alertes_visibles(): void
    {
        $u = User::factory()->create();
        $this->alerte($u, ['session_id' => $this->seance()->id]);
        $this->alerte($u, [], ['type' => 'guardianship_invitation']);
        $autre = User::factory()->create();
        $etrangere = $this->alerte($autre, ['session_id' => $this->seance()->id]);

        Livewire::actingAs($u)->test(Alerts::class)
            ->assertSee('Tout effacer')
            ->call('dismissAll')
            ->assertSee('Aucune notification reçue.');

        $this->assertSame([], $this->visibles($u));
        $this->assertSame([$etrangere->id], $this->visibles($autre));
    }

    public function test_refus_masquer_lalerte_dun_autre_compte(): void
    {
        $u = User::factory()->create();
        $autre = User::factory()->create();
        $etrangere = $this->alerte($autre, ['session_id' => $this->seance()->id]);

        Livewire::actingAs($u)->test(Alerts::class)->call('dismiss', [$etrangere->id]);

        $this->assertNull($etrangere->fresh()->dismissed_at);
        $this->assertSame([$etrangere->id], $this->visibles($autre));
    }

    public function test_lecran_des_envois_montre_toujours_les_lignes_masquees(): void
    {
        $u = User::factory()->create();
        $ligne = $this->alerte($u, ['session_id' => $this->seance()->id], ['dismissed_at' => Carbon::now()]);

        $page = app(OutboxAdminService::class)->page([]);

        $this->assertSame([], $this->visibles($u));
        $this->assertTrue($page['rows']->contains('id', $ligne->id));
    }

    // ── Regroupement ────────────────────────────────────────────────────────────────────────────

    public function test_parent_et_deux_enfants_forment_une_carte_que_la_croix_masque_entiere(): void
    {
        $parent = User::factory()->create(['first_name' => 'Julie']);
        $lea = User::factory()->create(['first_name' => 'Léa']);
        $tom = User::factory()->create(['first_name' => 'Tom']);
        $seance = $this->seance();
        $lignes = [
            $this->alerte($parent, ['session_id' => $seance->id]),
            $this->alerte($parent, ['session_id' => $seance->id, 'subject_id' => $tom->id], ['created_at' => Carbon::now()->addSecond()]),
            $this->alerte($parent, ['session_id' => $seance->id, 'subject_id' => $lea->id], ['created_at' => Carbon::now()->addSeconds(2)]),
        ];

        $page = Livewire::actingAs($parent)->test(Alerts::class)
            ->assertSee('Toi, Léa et Tom · Nouvelle compétition ou événement club');
        $alerts = $page->viewData('alerts');
        $this->assertCount(1, $alerts);

        $page->call('dismiss', $alerts[0]['ids']);

        foreach ($lignes as $ligne) {
            $this->assertNotNull($ligne->fresh()->dismissed_at);
        }
    }

    public function test_deux_modifications_successives_restent_deux_cartes(): void
    {
        $u = User::factory()->create();
        $seance = $this->seance();
        $this->alerte($u, ['session_id' => $seance->id], ['type' => 'session_modified']);
        $this->alerte($u, ['session_id' => $seance->id], ['type' => 'session_modified', 'created_at' => Carbon::now()->addSeconds(30)]);
        // Même séance, types différents : jamais regroupées non plus.
        $this->alerte($u, ['session_id' => $seance->id], ['type' => 'session_content']);

        $alerts = Livewire::actingAs($u)->test(Alerts::class)->viewData('alerts');

        $this->assertCount(3, $alerts);
    }
}
