<?php

namespace Tests\Feature;

use App\Livewire\SessionShow;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// Inscription/désinscription via la fiche séance (PRD §4.9), bout-en-bout Livewire.
class SessionEnrollmentTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function makeSession(?int $capacity = 2): Session
    {
        $s = Session::create([
            'kind' => 'training', 'title' => 'Natation seuil',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'duration_min' => 90, 'capacity' => $capacity,
            'created_by' => User::factory()->coach()->create()->id,
        ]);

        return $this->targetCategory($s); // séance ciblant la catégorie ouverte (§4.5).
    }

    public function test_athlete_enrolls_then_unenrolls(): void
    {
        $s = $this->makeSession();
        $u = $this->athlete();

        Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s])
            ->call('enroll')
            ->assertSee('Tu participes');

        $this->assertDatabaseHas('registrations', [
            'session_id' => $s->id, 'user_id' => $u->id, 'status' => 'participating',
        ]);

        Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s->fresh()])
            ->call('unenroll');

        $this->assertDatabaseHas('registrations', [
            'session_id' => $s->id, 'user_id' => $u->id, 'status' => 'cancelled',
        ]);
    }

    public function test_full_session_sends_athlete_to_waitlist(): void
    {
        $s = $this->makeSession(capacity: 1);
        $taken = $this->athlete();
        app(RegistrationService::class)->register($s, $taken, $taken);

        $u = $this->athlete();
        // Avant inscription : la séance est pleine → bouton « Rejoindre la liste d'attente ».
        $component = Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s->fresh()])
            ->assertSee('Rejoindre la liste', false);

        $component->call('enroll');

        $this->assertDatabaseHas('registrations', [
            'session_id' => $s->id, 'user_id' => $u->id,
            'status' => 'waitlist', 'waitlist_reason' => 'capacity',
        ]);
    }

    public function test_suspended_athlete_cannot_enroll(): void
    {
        $s = $this->makeSession();
        $u = User::factory()->create(['athlete_access_suspended' => true]);

        Livewire::actingAs($u)->test(SessionShow::class, ['session' => $s])
            ->call('enroll')
            ->assertForbidden();

        $this->assertDatabaseMissing('registrations', ['session_id' => $s->id, 'user_id' => $u->id]);
    }

    public function test_waitlist_rank_is_sequential_fifo(): void
    {
        $s = $this->makeSession(capacity: 1);
        $svc = app(RegistrationService::class);
        $taken = $this->athlete(['first_name' => 'Taken']);
        $svc->register($s, $taken, $taken);

        // Deux athlètes en waitlist, registered_at distincts → rangs 1 puis 2.
        $w1 = $this->athlete(['first_name' => 'Premier']);
        $w2 = $this->athlete(['first_name' => 'Second']);
        $r1 = $svc->register($s, $w1, $w1);
        Registration::where('id', $r1->id)->update(['registered_at' => Carbon::now()->subMinutes(5)]);
        $r2 = $svc->register($s, $w2, $w2);
        Registration::where('id', $r2->id)->update(['registered_at' => Carbon::now()->subMinute()]);

        // Le second (entré après) se voit « 2ᵉ », pas « 3ᵉ » (rang réindexé, pas la clé brute).
        Livewire::actingAs($w2)->test(SessionShow::class, ['session' => $s->fresh()])
            ->assertSee('2ᵉ', false)
            ->assertDontSee('3ᵉ', false);
    }
}
