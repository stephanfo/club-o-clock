<?php

namespace Tests\Feature;

use App\Livewire\SessionShow;
use App\Models\AperoFlag;
use App\Models\Session;
use App\Models\User;
use App\Services\AperoService;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// UI flag apéro sur la fiche séance (PRD §4.14.5) : offrir / ne plus offrir / modération coach.
class AperoUiTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function makeSession(): Session
    {
        return $this->targetCategory(Session::create([
            'kind' => 'training', 'title' => 'Natation seuil',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'duration_min' => 90, 'capacity' => 10,
            'created_by' => User::factory()->coach()->create()->id,
        ]));
    }

    private function participant(Session $s): User
    {
        $u = $this->athlete();
        app(RegistrationService::class)->register($s, $u, $u);

        return $u;
    }

    // ── Fenêtre de (dé)flag : figée au début de la séance (§4.14.3) ──

    // AperoService::guardWindow refuse le retrait après start_at : les deux boutons de retrait
    // (le sien, et celui de modération coach) ne doivent plus être offerts sur une séance commencée.
    public function test_unflag_buttons_hidden_once_session_started(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);
        app(AperoService::class)->flag($s, $u);
        $s->forceFill(['start_at' => Carbon::now()->subHour()])->save();

        // Le payeur lui-même : plus de « Je ne l'offre plus ».
        Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s->fresh()])
            ->assertDontSeeHtml('wire:click="unflagApero('.$u->id.')"');

        // Un coach modérateur : plus de « Retirer ce flag ».
        $coach = User::factory()->coach()->create();
        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s->fresh()])
            ->assertDontSeeHtml('wire:click="unflagApero('.$u->id.')"')
            // Contrôle positif : le flag est toujours AFFICHÉ (c'est le bouton de retrait qui
            // disparaît, pas l'information). Sans ça, un rendu vide ferait passer le test.
            ->assertSee($u->first_name, escape: false);
    }

    // Contrôle positif appairé : avant le début, les deux voies de retrait restent offertes.
    public function test_unflag_buttons_visible_before_session_starts(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);
        app(AperoService::class)->flag($s, $u);

        Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s])
            ->assertSeeHtml('wire:click="unflagApero('.$u->id.')"');

        $coach = User::factory()->coach()->create();
        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->assertSeeHtml('wire:click="unflagApero('.$u->id.')"');
    }

    public function test_participant_offers_apero_with_motif(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);

        Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s])
            ->set('aperoMotif', 'mon anniversaire')
            ->call('flagApero')
            ->assertSet('aperoMotif', ''); // réinitialisé après pose

        $this->assertDatabaseHas('apero_flags', [
            'session_id' => $s->id, 'user_id' => $u->id, 'motif' => 'mon anniversaire', 'parked_at' => null,
        ]);
    }

    public function test_payer_retracts_own_flag(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);
        app(AperoService::class)->flag($s, $u);

        Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s])
            ->call('unflagApero', $u->id);

        $this->assertDatabaseMissing('apero_flags', ['session_id' => $s->id, 'user_id' => $u->id]);
    }

    public function test_coach_moderates_a_flag(): void
    {
        $s = $this->makeSession();
        $payer = $this->participant($s);
        $coach = User::factory()->coach()->create();
        app(AperoService::class)->flag($s, $payer);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->call('unflagApero', $payer->id);

        $this->assertDatabaseMissing('apero_flags', ['session_id' => $s->id, 'user_id' => $payer->id]);
    }

    public function test_athlete_cannot_moderate_another_flag(): void
    {
        $s = $this->makeSession();
        $payer = $this->participant($s);
        $other = $this->participant($s);
        app(AperoService::class)->flag($s, $payer);

        Livewire::actingAs($other)->test(SessionShow::class, ['session' => $s])
            ->call('unflagApero', $payer->id)
            ->assertForbidden();

        $this->assertDatabaseHas('apero_flags', ['session_id' => $s->id, 'user_id' => $payer->id]);
    }

    public function test_session_cancel_parks_and_restore_reactivates_via_fiche(): void
    {
        $s = $this->makeSession();
        $u = $this->participant($s);
        $coach = User::factory()->coach()->create();
        app(AperoService::class)->flag($s, $u);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s])
            ->set('cancelCheck', true)
            ->call('cancel');
        $this->assertNotNull(AperoFlag::where('session_id', $s->id)->first()->parked_at);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $s->fresh()])
            ->call('restore');
        $this->assertNull(AperoFlag::where('session_id', $s->id)->first()->parked_at);
    }
}
