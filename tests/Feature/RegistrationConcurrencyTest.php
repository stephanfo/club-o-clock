<?php

namespace Tests\Feature;

use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// Sérialisation atomique des inscriptions (PRD §4.9.5, NF §6) : deux inscriptions quasi
// simultanées sur la DERNIÈRE place ne produisent JAMAIS deux `participating`.
//
// Vraie concurrence : le test fork un second processus PHP ; chaque processus rouvre sa propre
// connexion MariaDB et les deux register() partent ensemble (barrière temporelle). Le verrou
// pessimiste (Session lockForUpdate) doit sérialiser : 1 participating + 1 waitlist `capacity`.
// DatabaseTruncation (et non RefreshDatabase) : les données doivent être réellement commitées
// pour être visibles du processus enfant — pas de transaction d'enrobage.
class RegistrationConcurrencyTest extends TestCase
{
    use DatabaseTruncation;
    use EnrollableCategory;

    protected function tearDown(): void
    {
        // Les données de ce test sont réellement commitées (pas de transaction d'enrobage) : on
        // re-truncate en sortie pour ne pas polluer les tests suivants (RefreshDatabase
        // transactionnel ne les nettoierait pas).
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_two_simultaneous_registrations_never_double_accept_last_seat(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('extension pcntl requise pour le test de concurrence');
        }

        // 3 tours pour maximiser la probabilité d'entrelacement réel des transactions.
        foreach (range(1, 3) as $round) {
            $session = Session::create([
                'kind' => 'training',
                'title' => "Natation dernière place {$round}",
                'start_at' => Carbon::now()->addDays(2)->setTime(19, 0),
                'duration_min' => 90,
                'capacity' => 1,
                'created_by' => User::factory()->coach()->create()->id,
            ]);
            $this->targetCategory($session); // séance ciblant la catégorie ouverte (§4.5).
            $a = $this->athlete();
            $b = $this->athlete();

            $startAt = microtime(true) + 0.25;
            $register = function (User $u) use ($session, $startAt): void {
                // Connexion propre au processus courant (le socket hérité du fork est partagé).
                DB::purge();
                DB::reconnect();
                while (microtime(true) < $startAt) {
                    usleep(500);
                }
                try {
                    app(RegistrationService::class)->register(Session::findOrFail($session->id), $u, $u);
                } catch (\Throwable) {
                    // L'invariant se vérifie sur l'état final, pas sur l'issue individuelle.
                }
            };

            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('fork impossible');
            }
            if ($pid === 0) {
                // Enfant : inscrit b puis meurt SANS exécuter le shutdown PHPUnit hérité.
                $register($b);
                posix_kill(posix_getpid(), SIGKILL);
            }

            $register($a);
            pcntl_waitpid($pid, $status);
            DB::purge();
            DB::reconnect();

            $statuses = Registration::query()
                ->where('session_id', $session->id)
                ->pluck('waitlist_reason', 'status');

            $this->assertSame(1, Registration::where('session_id', $session->id)
                ->where('status', 'participating')->count(),
                "tour {$round} : double-acceptation sur la dernière place ({$statuses->toJson()})");
            $this->assertSame(1, Registration::where('session_id', $session->id)
                ->where('status', 'waitlist')->where('waitlist_reason', 'capacity')->count(),
                "tour {$round} : le perdant doit être en waitlist capacity");
        }
    }
}
