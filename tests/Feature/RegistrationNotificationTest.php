<?php

namespace Tests\Feature;

use App\Models\NotificationOutbox;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// J8.5 — Producteurs d'inscription (§4.10) : promotion depuis la liste d'attente (mécanismes A/B/C
// → waitlist_promoted) et inscription forcée par un coach (override → coach_override). Fan-out via
// l'outbox (J8.1) : routage parent/enfant + matrice + pause appliqués par le dispatcher.
class RegistrationNotificationTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function service(): RegistrationService
    {
        return app(RegistrationService::class);
    }

    private function makeSession(?int $capacity = null): Session
    {
        return $this->targetCategory(Session::create([
            'kind' => 'training',
            'title' => 'Natation seuil',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'duration_min' => 90,
            'capacity' => $capacity,
            'created_by' => User::factory()->coach()->create()->id,
        ])); // séance ciblant la catégorie ouverte (§4.5).
    }

    public function test_capacity_promotion_notifies_promoted_athlete(): void
    {
        // Mécanisme A (§4.9.3) : une place se libère, le 1er de la file capacity passe participating.
        $s = $this->makeSession(capacity: 1);
        $a = $this->athlete();
        $b = $this->athlete();

        $this->service()->register($s, $a, $a);          // participating
        $regB = $this->service()->register($s, $b, $b);   // waitlist capacity
        $this->assertSame('waitlist', $regB->fresh()->status);

        $this->service()->cancel($s, $a, $a);

        // Seul b (promu) est notifié : push + email = 2 lignes waitlist_promoted.
        $this->assertSame(2, NotificationOutbox::where('type', 'waitlist_promoted')->count());
        $this->assertSame(2, NotificationOutbox::where('type', 'waitlist_promoted')
            ->where('user_id', $b->id)->count());
    }

    public function test_coach_quota_unblock_notifies_each_promoted(): void
    {
        // Mécanisme C (§4.10.4) : déblocage manuel coach de la file quota_exceeded.
        $s = $this->makeSession(capacity: null);
        $coach = User::factory()->coach()->create();
        $a = $this->athlete();
        $b = $this->athlete();

        foreach ([$a, $b] as $u) {
            Registration::create([
                'session_id' => $s->id, 'user_id' => $u->id,
                'status' => 'waitlist', 'waitlist_reason' => 'quota_exceeded',
                'registered_at' => Carbon::now(),
            ]);
        }

        $promoted = $this->service()->fillFromQuotaExceeded($s, $coach);

        $this->assertSame(2, $promoted);
        // 2 promus × (push + email) = 4 lignes.
        $this->assertSame(4, NotificationOutbox::where('type', 'waitlist_promoted')->count());
    }

    public function test_le_deblocage_d_une_file_ne_multiplie_pas_les_lectures_de_seance(): void
    {
        // Les promus sont notifiés en lot, et le payload de chacun porte la séance : le nombre de
        // lectures de `sessions` ne doit donc PAS suivre le nombre de promus. Invisible à deux,
        // sensible sur un déblocage de file entière — et le mutualisé n'a pas de marge.
        $this->assertSame($this->lecturesDeSeance(2), $this->lecturesDeSeance(6));
    }

    /** Nombre de SELECT sur `sessions` pendant le déblocage d'une file de $promus athlètes. */
    private function lecturesDeSeance(int $promus): int
    {
        $s = $this->makeSession(capacity: null);
        $coach = User::factory()->coach()->create();

        for ($i = 0; $i < $promus; $i++) {
            Registration::create([
                'session_id' => $s->id, 'user_id' => $this->athlete()->id,
                'status' => 'waitlist', 'waitlist_reason' => 'quota_exceeded',
                'registered_at' => Carbon::now(),
            ]);
        }

        // Écoute posée APRÈS la mise en place : seul le déblocage lui-même est compté.
        $lectures = 0;
        DB::listen(function ($requete) use (&$lectures) {
            if (str_contains($requete->sql, 'from `sessions`')) {
                $lectures++;
            }
        });

        $this->service()->fillFromQuotaExceeded($s, $coach);

        return $lectures;
    }

    public function test_override_notifies_target(): void
    {
        $s = $this->makeSession(capacity: 1);
        $coach = User::factory()->coach()->create();
        $target = $this->athlete();

        $this->service()->overrideRegister($s, $target, $coach, 'remplaçant');

        $this->assertSame(2, NotificationOutbox::where('type', 'coach_override')
            ->where('user_id', $target->id)->count());
    }

    public function test_plain_registration_emits_nothing(): void
    {
        // Une inscription qui ne promeut personne ne notifie pas (pas de coach_override, pas de promotion).
        $s = $this->makeSession(capacity: 2);
        $u = $this->athlete();

        $this->service()->register($s, $u, $u);

        $this->assertSame(0, NotificationOutbox::count());
    }
}
