<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// Cœur des inscriptions J2 (PRD §4.9). Capacité + waitlist FIFO `capacity` + promotion synchrone.
class RegistrationServiceTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private function service(): RegistrationService
    {
        return app(RegistrationService::class);
    }

    private function makeSession(?int $capacity = null, ?Carbon $startAt = null): Session
    {
        $s = Session::create([
            'kind' => 'training',
            'title' => 'Natation seuil',
            'start_at' => $startAt ?? Carbon::now()->addDays(2)->setTime(19, 0),
            'duration_min' => 90,
            'capacity' => $capacity,
            'created_by' => User::factory()->coach()->create()->id,
        ]);

        return $this->targetCategory($s); // séance ciblant la catégorie ouverte (§4.5).
    }

    public function test_registers_as_participating_when_room(): void
    {
        $s = $this->makeSession(capacity: 2);
        $u = $this->athlete();

        $reg = $this->service()->register($s, $u, $u);

        $this->assertSame('participating', $reg->status);
        $this->assertNull($reg->waitlist_reason);
    }

    public function test_overflow_goes_to_capacity_waitlist(): void
    {
        $s = $this->makeSession(capacity: 1);
        $first = $this->athlete();
        $second = $this->athlete();

        $this->service()->register($s, $first, $first);
        $reg = $this->service()->register($s, $second, $second);

        $this->assertSame('waitlist', $reg->status);
        $this->assertSame('capacity', $reg->waitlist_reason);
    }

    public function test_null_capacity_never_waitlists(): void
    {
        $s = $this->makeSession(capacity: null);

        foreach (range(1, 5) as $_) {
            $u = $this->athlete();
            $this->assertSame('participating', $this->service()->register($s, $u, $u)->status);
        }
    }

    public function test_cancel_promotes_first_fifo(): void
    {
        $s = $this->makeSession(capacity: 1);
        $a = $this->athlete();
        $b = $this->athlete();
        $c = $this->athlete();

        $this->service()->register($s, $a, $a); // participating
        // b puis c en waitlist capacity, registered_at ASC distincts.
        $regB = $this->service()->register($s, $b, $b);
        Registration::where('id', $regB->id)->update(['registered_at' => Carbon::now()->subMinutes(2)]);
        $regC = $this->service()->register($s, $c, $c);
        Registration::where('id', $regC->id)->update(['registered_at' => Carbon::now()->subMinute()]);

        $this->service()->cancel($s, $a, $a);

        // b est entré le premier (registered_at antérieur) → promu.
        $this->assertSame('participating', $regB->fresh()->status);
        $this->assertNotNull($regB->fresh()->promoted_at);
        $this->assertSame('waitlist', $regC->fresh()->status);
    }

    public function test_capacity_increase_promotes_capacity_waitlist_fifo(): void
    {
        // Mécanisme A étendu (E2) : séance pleine (cap 1) + 2 en file `capacity`.
        // Hausse à 3 → les 2 sont promus dans l'ordre FIFO (registered_at ASC).
        $s = $this->makeSession(capacity: 1);
        $a = $this->athlete();
        $b = $this->athlete();
        $c = $this->athlete();

        $this->service()->register($s, $a, $a); // participating
        $regB = $this->service()->register($s, $b, $b);
        Registration::where('id', $regB->id)->update(['registered_at' => Carbon::now()->subMinutes(2)]);
        $regC = $this->service()->register($s, $c, $c);
        Registration::where('id', $regC->id)->update(['registered_at' => Carbon::now()->subMinute()]);

        $s->update(['capacity' => 3]);
        $this->service()->onCapacityIncreased($s->fresh());

        $this->assertSame('participating', $regB->fresh()->status);
        $this->assertSame('participating', $regC->fresh()->status);
        $this->assertNotNull($regB->fresh()->promoted_at);
        $this->assertNull($regB->fresh()->waitlist_reason);
        // FIFO : b (registered_at antérieur) promu avant c — vérifié par promoted_at croissant.
        $this->assertTrue($regB->fresh()->promoted_at <= $regC->fresh()->promoted_at);
    }

    public function test_capacity_increase_stops_when_no_room_left(): void
    {
        // Cap 1 → 2 : une seule place libérée, un seul promu, le second reste en file.
        $s = $this->makeSession(capacity: 1);
        $a = $this->athlete();
        $b = $this->athlete();
        $c = $this->athlete();

        $this->service()->register($s, $a, $a);
        $regB = $this->service()->register($s, $b, $b);
        Registration::where('id', $regB->id)->update(['registered_at' => Carbon::now()->subMinutes(2)]);
        $regC = $this->service()->register($s, $c, $c);
        Registration::where('id', $regC->id)->update(['registered_at' => Carbon::now()->subMinute()]);

        $s->update(['capacity' => 2]);
        $this->service()->onCapacityIncreased($s->fresh());

        $this->assertSame('participating', $regB->fresh()->status);
        $this->assertSame('waitlist', $regC->fresh()->status);
    }

    public function test_capacity_increase_ignores_quota_waitlist(): void
    {
        // La file `quota_exceeded` (mécanisme C, déblocage manuel) ne doit JAMAIS être promue
        // par une hausse de capacité, même s'il reste des places.
        $s = $this->makeSession(capacity: 1);
        $a = $this->athlete();
        $b = $this->athlete();

        $this->service()->register($s, $a, $a); // participating
        // b en waitlist mais motif quota_exceeded (simulé directement).
        $regB = $this->service()->register($s, $b, $b);
        Registration::where('id', $regB->id)->update(['waitlist_reason' => 'quota_exceeded']);

        $s->update(['capacity' => 5]);
        $this->service()->onCapacityIncreased($s->fresh());

        $this->assertSame('waitlist', $regB->fresh()->status);
        $this->assertSame('quota_exceeded', $regB->fresh()->waitlist_reason);
    }

    public function test_capacity_increase_noop_when_unlimited(): void
    {
        // Capacité null = illimitée → personne n'est jamais en file `capacity`, rien à promouvoir.
        $s = $this->makeSession(capacity: null);
        $a = $this->athlete();
        $this->service()->register($s, $a, $a);

        $this->service()->onCapacityIncreased($s->fresh()); // ne doit pas lever.
        $this->assertSame('participating', $s->registrations()->where('user_id', $a->id)->first()->status);
    }

    public function test_capacity_change_from_unlimited_to_finite_is_noop(): void
    {
        // null → finie : SessionForm déclenche onCapacityIncreased ($oldCapacity === null), mais
        // comme la capacité était illimitée, personne n'est en file `capacity` → aucune promotion,
        // aucune rétrogradation même si la nouvelle capacité est inférieure au nb de participants.
        $s = $this->makeSession(capacity: null);
        $a = $this->athlete();
        $b = $this->athlete();
        $this->service()->register($s, $a, $a);
        $this->service()->register($s, $b, $b);

        $s->update(['capacity' => 1]); // sous-capacitaire : 2 participants pour 1 place.
        $this->service()->onCapacityIncreased($s->fresh());

        $this->assertSame('participating', $s->registrations()->where('user_id', $a->id)->first()->status);
        $this->assertSame('participating', $s->registrations()->where('user_id', $b->id)->first()->status);
    }

    public function test_cancel_of_waitlisted_does_not_promote(): void
    {
        $s = $this->makeSession(capacity: 1);
        $a = $this->athlete();
        $b = $this->athlete();

        $this->service()->register($s, $a, $a);  // participating
        $this->service()->register($s, $b, $b);  // waitlist
        $this->service()->cancel($s, $b, $b);    // libère une place waitlist, pas participating

        $this->assertSame('participating', $s->registrations()->where('user_id', $a->id)->first()->status);
        $this->assertSame('cancelled', $s->registrations()->where('user_id', $b->id)->first()->status);
    }

    public function test_reregister_after_cancel_reuses_row(): void
    {
        $s = $this->makeSession(capacity: 2);
        $u = $this->athlete();

        $this->service()->register($s, $u, $u);
        $this->service()->cancel($s, $u, $u);
        $reg = $this->service()->register($s, $u, $u);

        $this->assertSame('participating', $reg->status);
        $this->assertSame(1, Registration::where('session_id', $s->id)->where('user_id', $u->id)->count());
    }

    public function test_register_is_idempotent_when_active(): void
    {
        $s = $this->makeSession(capacity: 2);
        $u = $this->athlete();

        $first = $this->service()->register($s, $u, $u);
        $second = $this->service()->register($s, $u, $u);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Registration::where('session_id', $s->id)->count());
    }

    public function test_register_blocked_after_start(): void
    {
        $s = $this->makeSession(capacity: 2, startAt: Carbon::now()->subHour());
        $u = $this->athlete();

        $this->expectException(RuntimeException::class);
        $this->service()->register($s, $u, $u);
    }

    public function test_cancel_blocked_after_start(): void
    {
        $s = $this->makeSession(capacity: 2);
        $u = $this->athlete();
        $this->service()->register($s, $u, $u);

        // Décale le début dans le passé après inscription.
        $s->update(['start_at' => Carbon::now()->subHour()]);

        $this->expectException(RuntimeException::class);
        $this->service()->cancel($s, $u, $u);
    }

    // ── Garde catégorielle serveur (§4.5, défense en profondeur) ──

    /** Séance ciblant UNE AUTRE catégorie que la catégorie ouverte du trait. */
    private function offCategorySession(): Session
    {
        $other = Category::create([
            'label' => 'Minimes', 'age_min' => 13, 'age_max' => 14, 'sort_order' => 9,
        ]);
        $s = Session::create([
            'kind' => 'training', 'title' => 'Hors catégorie',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0), 'duration_min' => 90,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
        $s->categories()->attach($other->id);

        return $s;
    }

    public function test_self_enroll_blocked_outside_category(): void
    {
        $s = $this->offCategorySession();
        $u = $this->athlete(); // catégorie ouverte, pas Minimes

        try {
            $this->service()->register($s, $u, $u);
            $this->fail('Attendu : CATEGORY_MISMATCH');
        } catch (RuntimeException $e) {
            $this->assertSame(RegistrationService::CATEGORY_MISMATCH, $e->getMessage());
        }
        $this->assertDatabaseMissing('registrations', ['session_id' => $s->id, 'user_id' => $u->id]);
    }

    public function test_self_enroll_blocked_without_active_category(): void
    {
        $s = $this->makeSession(capacity: 2);
        $u = User::factory()->create(); // aucune catégorie active

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(RegistrationService::CATEGORY_MISMATCH);
        $this->service()->register($s, $u, $u);
    }

    public function test_self_enroll_allowed_on_untargeted_open_session(): void
    {
        // Séance sans aucune catégorie ciblée = ouverte à toutes (§4.5 défaut) : la garde
        // serveur laisse passer tout athlète ayant une catégorie active.
        $s = Session::create([
            'kind' => 'training', 'title' => 'Séance ouverte',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0), 'duration_min' => 60,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
        $u = $this->athlete();

        $this->assertSame('participating', $this->service()->register($s, $u, $u)->status);
    }

    public function test_staff_can_enroll_athlete_outside_category(): void
    {
        // §4.9.7 : le bureau inscrit qui il veut — la garde catégorielle épargne le staff.
        $s = $this->offCategorySession();
        $coach = User::factory()->coach()->create();
        $u = $this->athlete(); // hors de la catégorie de la séance

        $reg = $this->service()->register($s, $u, $coach);

        $this->assertSame('participating', $reg->status);
    }

    public function test_override_forces_registration_outside_category(): void
    {
        // L'override §4.10.5 passe par overrideRegister() : aucune garde catégorielle.
        $s = $this->offCategorySession();
        $coach = User::factory()->coach()->create();
        $u = $this->athlete();

        $reg = $this->service()->overrideRegister($s, $u, $coach, 'surclassement ponctuel');

        $this->assertSame('participating', $reg->status);
        $this->assertSame($coach->id, $reg->override_by);
    }

    public function test_logs_activity_on_register_and_promote(): void
    {
        $s = $this->makeSession(capacity: 1);
        $a = $this->athlete();
        $b = $this->athlete();

        $this->service()->register($s, $a, $a);
        $this->service()->register($s, $b, $b);
        $this->service()->cancel($s, $a, $a); // promeut b

        $this->assertDatabaseHas('activity_logs', ['action' => 'inscription', 'user_id' => $a->id]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'waitlist_promoted', 'user_id' => $b->id, 'actor_is_system' => true,
        ]);
    }
}
