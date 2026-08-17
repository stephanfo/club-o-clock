<?php

namespace Tests\Feature;

use App\Models\Discipline;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Helpers d'affichage de Session factorisés depuis les vues : colorClass() (couleur du liseré /
 * de la pastille) et statusFor() (statut d'inscription du sujet consulté, §4.2).
 */
class SessionDisplayHelpersTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(string $kind = 'training', ?int $disciplineId = null): Session
    {
        $coach = User::factory()->coach()->create();

        return Session::create([
            'kind' => $kind, 'title' => 'Séance', 'discipline_id' => $disciplineId,
            'start_at' => Carbon::now()->addDay(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);
    }

    public function test_color_class_comes_from_the_discipline_when_set(): void
    {
        $swim = Discipline::create(['label' => 'Natation', 'sort_order' => 1]);

        // La discipline prime, y compris sur un kind qui aurait sa propre couleur.
        $this->assertSame('swim', $this->makeSession('training', $swim->id)->colorClass());
        $this->assertSame('swim', $this->makeSession('competition', $swim->id)->colorClass());
    }

    public function test_color_class_falls_back_on_kind_without_discipline(): void
    {
        $this->assertSame('competition', $this->makeSession('competition')->colorClass());
        $this->assertSame('event', $this->makeSession('club_event')->colorClass());
        $this->assertSame('prep', $this->makeSession('training')->colorClass());
    }

    public function test_status_for_returns_the_active_registration_status(): void
    {
        $session = $this->makeSession();
        $athlete = User::factory()->create();
        Registration::create([
            'session_id' => $session->id, 'user_id' => $athlete->id,
            'status' => 'waitlist', 'registered_at' => Carbon::now(),
        ]);

        $this->assertSame('waitlist', $session->fresh()->statusFor($athlete->id));
    }

    public function test_status_for_ignores_cancelled_registrations_and_unknown_users(): void
    {
        // `cancelled` = le sujet n'est plus inscrit → même rendu qu'une absence d'inscription.
        $session = $this->makeSession();
        $athlete = User::factory()->create();
        Registration::create([
            'session_id' => $session->id, 'user_id' => $athlete->id,
            'status' => 'cancelled', 'registered_at' => Carbon::now(),
        ]);

        $session = $session->fresh();

        $this->assertNull($session->statusFor($athlete->id));
        $this->assertNull($session->statusFor(User::factory()->create()->id));
        $this->assertNull($session->statusFor(null));
    }
}
