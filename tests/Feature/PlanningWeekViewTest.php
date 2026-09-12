<?php

namespace Tests\Feature;

use App\Livewire\Planning;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Vue Semaine du planning (§4.2). Le format mobile empile des groupes de jour dont l'en-tête est
 * collant : ces groupes doivent être identifiés, sinon le morphing Livewire réécrit leurs nœuds
 * texte en place d'une semaine à l'autre et WebKit ne recompose pas l'en-tête sticky (issue #32).
 */
class PlanningWeekViewTest extends TestCase
{
    use RefreshDatabase;

    private function sessionOn(Carbon $at, string $title): Session
    {
        $coach = User::factory()->coach()->create();

        return Session::create([
            'kind' => 'training', 'title' => $title,
            'start_at' => $at, 'duration_min' => 60, 'created_by' => $coach->id,
        ]);
    }

    public function test_each_mobile_day_group_is_keyed_on_its_date(): void
    {
        $monday = Carbon::now()->startOfWeek();
        $tuesday = $monday->copy()->addDay();

        $this->sessionOn($monday->copy()->setTime(18, 0), 'Fractionné');
        $this->sessionOn($tuesday->copy()->setTime(18, 0), 'Sortie longue');

        $athlete = User::factory()->create();
        $html = Livewire::actingAs($athlete)->test(Planning::class)->set('view', 'week')->html();

        // Contrôle positif : les deux jours sont bien rendus (sans quoi l'assertion suivante
        // serait vraie sur une liste vide).
        $this->assertStringContainsString('Fractionné', $html);
        $this->assertStringContainsString('Sortie longue', $html);

        // Chaque groupe porte une clé propre à sa date : le morphing détruit et recrée le bloc
        // au changement de semaine au lieu d'y substituer du texte.
        $this->assertStringContainsString('wire:key="daygroup-'.$monday->toDateString().'"', $html);
        $this->assertStringContainsString('wire:key="daygroup-'.$tuesday->toDateString().'"', $html);
    }
}
