<?php

namespace Tests\Feature;

use App\Livewire\Club;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// #150 — Hub « Club » : dernier onglet de la barre du bas mobile, index des écrans qui n'y ont pas
// d'entrée. Chaque entrée suit les droits de l'écran qu'elle ouvre.
class ClubHubTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_redirige(): void
    {
        $this->get('/club')->assertRedirect();
    }

    public function test_athlete_voit_profil_trombinoscope_infos_alertes_et_mentions(): void
    {
        $u = User::factory()->create(['first_name' => 'Léa', 'last_name' => 'Pereira']);

        Livewire::actingAs($u)->test(Club::class)
            ->assertOk()
            ->assertSee('Léa Pereira')
            ->assertSee('href="'.route('profil').'"', false)
            ->assertSee('href="'.route('trombi').'"', false)
            ->assertSee('href="'.route('infos').'"', false)
            ->assertSee('href="'.route('alerts').'"', false)
            ->assertSee('href="'.route('legal').'"', false);
    }

    public function test_sans_acces_au_trombinoscope_pas_d_entree(): void
    {
        Livewire::actingAs(User::factory()->create(['roles' => []]))->test(Club::class)
            ->assertSee('href="'.route('infos').'"', false)
            ->assertDontSee('href="'.route('trombi').'"', false);
    }

    public function test_la_barre_du_bas_mene_au_hub_et_non_plus_au_profil(): void
    {
        $u = User::factory()->create();

        $this->actingAs($u)->get(route('dashboard'))
            ->assertSee('href="'.route('club').'" wire:navigate class="botnav-item "', false)
            ->assertDontSee('<span>Profil</span>', false);
    }

    public function test_l_onglet_club_reste_allume_sur_les_ecrans_qu_il_indexe(): void
    {
        $u = User::factory()->create();
        $onglet = 'href="'.route('club').'" wire:navigate class="botnav-item on"';

        foreach (['club', 'profil', 'trombi', 'infos', 'alerts'] as $ecran) {
            $this->actingAs($u)->get(route($ecran))->assertOk()->assertSee($onglet, false);
        }
        $this->actingAs($u)->get(route('planning'))->assertDontSee($onglet, false);
    }

    public function test_le_profil_mobile_ramene_au_hub(): void
    {
        $this->actingAs(User::factory()->create())->get(route('profil'))
            ->assertSee('aria-label="Retour au club"', false);
    }
}
