<?php

namespace Tests\Feature;

use App\Livewire\SessionForm;
use App\Livewire\SessionShow;
use App\Models\Discipline;
use App\Models\NotificationOutbox;
use App\Models\QuotaTag;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

/**
 * #66 — le déblocage du quota devient un ÉTAT de la séance, actif jusqu'à ce que le coach le
 * referme ou que la séance commence (PRD §4.10.4). Avant, le geste ne promouvait la file
 * `quota_exceeded` qu'à l'instant du clic : une inscription hors quota arrivée après, un
 * désistement ou une hausse de capacité retombaient dans la règle normale.
 */
class QuotaReleaseTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    private QuotaTag $tag;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 1]);
        $this->coach = User::factory()->coach()->create();
    }

    private function svc(): RegistrationService
    {
        return app(RegistrationService::class);
    }

    /** Séance piscine de la semaine prochaine ; $day décale dans la même semaine. */
    private function piscine(?int $capacity = 2, int $day = 3): Session
    {
        $base = Carbon::now()->startOfWeek(Carbon::MONDAY)->addWeek()->setTime(19, 0);

        return $this->targetCategory(Session::create([
            'kind' => 'training', 'title' => 'Natation '.$day,
            'discipline_id' => Discipline::firstOrCreate(['label' => 'Natation'], ['sort_order' => 0])->id,
            'start_at' => $base->copy()->addDays($day),
            'duration_min' => 60, 'capacity' => $capacity, 'quota_tag_id' => $this->tag->id,
            'created_by' => $this->coach->id,
        ]));
    }

    /** Athlète ayant déjà consommé son quota piscine de la semaine (séance du lundi). */
    private function horsQuota(string $prenom = 'Xavier'): User
    {
        $u = $this->athlete(['first_name' => $prenom]);
        $this->svc()->register($this->piscine(capacity: null, day: 0), $u, $u);

        return $u;
    }

    private function statut(Session $s, User $u): string
    {
        $r = Registration::where('session_id', $s->id)->where('user_id', $u->id)->firstOrFail();

        return $r->status.($r->waitlist_reason ? ' '.$r->waitlist_reason : '');
    }

    // ── Inscription sur séance débloquée ────────────────────────────────────────────────────

    public function test_over_quota_athlete_registers_directly_when_released(): void
    {
        $s = $this->piscine(capacity: 2);
        $x = $this->horsQuota();

        $this->svc()->releaseQuota($s, $this->coach);

        // Sans confirmation : plus de QUOTA_NEEDS_CONFIRM, et une place libre est prise.
        $this->svc()->register($s, $x, $x);
        $this->assertSame('participating', $this->statut($s, $x));
    }

    public function test_over_quota_athlete_joins_quota_queue_when_released_session_is_full(): void
    {
        $s = $this->piscine(capacity: 1);
        $plein = $this->athlete();
        $this->svc()->register($s, $plein, $plein);

        // Xavier attendait avant le déblocage, Yann arrive après : Xavier ne doit pas être doublé.
        $x = $this->horsQuota('Xavier');
        $this->svc()->register($s, $x, $x, confirmQuota: true);
        $this->travel(1)->minutes();
        $this->svc()->releaseQuota($s, $this->coach);
        $this->travel(1)->minutes();
        $y = $this->horsQuota('Yann');
        $this->svc()->register($s, $y, $y);

        $this->assertSame('waitlist quota_exceeded', $this->statut($s, $y));

        $this->svc()->cancel($s, $plein, $plein);

        $this->assertSame('participating', $this->statut($s, $x));
        $this->assertSame('waitlist quota_exceeded', $this->statut($s, $y));
    }

    public function test_under_quota_waiter_still_passes_before_quota_queue(): void
    {
        $s = $this->piscine(capacity: 1);
        $plein = $this->athlete();
        $this->svc()->register($s, $plein, $plein);

        $x = $this->horsQuota();
        $this->svc()->register($s, $x, $x, confirmQuota: true);   // arrivé en premier, hors quota
        $this->travel(1)->minutes();
        $z = $this->athlete();
        $this->svc()->register($s, $z, $z);                        // sous quota → file capacity
        $this->svc()->releaseQuota($s, $this->coach);

        $this->svc()->cancel($s, $plein, $plein);

        $this->assertSame('participating', $this->statut($s, $z));
        $this->assertSame('waitlist quota_exceeded', $this->statut($s, $x));
    }

    // ── Mécanisme A étendu ──────────────────────────────────────────────────────────────────

    public function test_withdrawal_promotes_quota_queue_when_released_and_notifies(): void
    {
        $s = $this->piscine(capacity: 1);
        $plein = $this->athlete();
        $this->svc()->register($s, $plein, $plein);
        $x = $this->horsQuota();
        $this->svc()->register($s, $x, $x, confirmQuota: true);
        $this->svc()->releaseQuota($s, $this->coach);   // séance pleine : personne à promouvoir

        $this->svc()->cancel($s, $plein, $plein);

        $this->assertSame('participating', $this->statut($s, $x));
        $this->assertSame(2, NotificationOutbox::where('type', 'waitlist_promoted')->where('user_id', $x->id)->count());
    }

    public function test_withdrawal_leaves_quota_queue_alone_when_not_released(): void
    {
        // Contrôle : sans déblocage, la règle d'avant tient — la place reste libre.
        $s = $this->piscine(capacity: 1);
        $plein = $this->athlete();
        $this->svc()->register($s, $plein, $plein);
        $x = $this->horsQuota();
        $this->svc()->register($s, $x, $x, confirmQuota: true);

        $this->svc()->cancel($s, $plein, $plein);

        $this->assertSame('waitlist quota_exceeded', $this->statut($s, $x));
    }

    public function test_capacity_increase_promotes_quota_queue_when_released(): void
    {
        $s = $this->piscine(capacity: 1);
        $plein = $this->athlete();
        $this->svc()->register($s, $plein, $plein);
        $x = $this->horsQuota();
        $this->svc()->register($s, $x, $x, confirmQuota: true);
        $this->svc()->releaseQuota($s, $this->coach);

        $s->update(['capacity' => 2]);
        $this->svc()->onCapacityIncreased($s);

        $this->assertSame('participating', $this->statut($s, $x));
    }

    // ── Le geste : débloquer, refermer ──────────────────────────────────────────────────────

    public function test_release_is_allowed_with_an_empty_queue_and_is_audited(): void
    {
        $s = $this->piscine();

        $this->assertSame(0, $this->svc()->releaseQuota($s, $this->coach, motif: 'places la veille'));

        $s->refresh();
        $this->assertNotNull($s->quota_released_at);
        $this->assertSame($this->coach->id, $s->quota_released_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'quota_release', 'session_id' => $s->id, 'actor_id' => $this->coach->id, 'motif' => 'places la veille',
        ]);
    }

    public function test_release_promoting_someone_requires_acknowledgement(): void
    {
        $s = $this->piscine(capacity: 2);
        $x = $this->horsQuota();
        $this->svc()->register($s, $x, $x, confirmQuota: true);

        try {
            $this->svc()->releaseQuota($s, $this->coach);
            $this->fail('Attendu RELEASE_NEEDS_ACK.');
        } catch (RuntimeException $e) {
            $this->assertSame(RegistrationService::RELEASE_NEEDS_ACK, $e->getMessage());
        }

        // Rien n'a bougé : ni état, ni promotion.
        $this->assertNull($s->fresh()->quota_released_at);
        $this->assertSame('waitlist quota_exceeded', $this->statut($s, $x));

        $this->assertSame(1, $this->svc()->releaseQuota($s, $this->coach, acknowledged: true));
        $this->assertSame('participating', $this->statut($s, $x));
    }

    public function test_close_keeps_promoted_and_restores_the_rule_for_newcomers(): void
    {
        $s = $this->piscine(capacity: 5);
        $x = $this->horsQuota('Xavier');
        $this->svc()->register($s, $x, $x, confirmQuota: true);
        $this->svc()->releaseQuota($s, $this->coach, acknowledged: true);

        $this->svc()->closeQuota($s, $this->coach);

        $this->assertNull($s->fresh()->quota_released_at);
        $this->assertSame('participating', $this->statut($s, $x));
        $this->assertDatabaseHas('audit_logs', ['action' => 'quota_close', 'session_id' => $s->id]);

        $y = $this->horsQuota('Yann');
        $this->expectExceptionMessage(RegistrationService::QUOTA_NEEDS_CONFIRM);
        $this->svc()->register($s, $y, $y);
    }

    public function test_release_is_refused_on_started_cancelled_or_untagged_session(): void
    {
        $passee = $this->piscine();
        $passee->forceFill(['start_at' => Carbon::now()->subHour()])->save();
        $annulee = $this->piscine();
        $annulee->forceFill(['cancelled_at' => Carbon::now()])->save();
        $sansTag = $this->piscine();
        $sansTag->update(['quota_tag_id' => null]);

        foreach ([$passee, $annulee, $sansTag] as $s) {
            try {
                $this->svc()->releaseQuota($s, $this->coach);
                $this->fail('Déblocage accepté à tort sur « '.$s->title.' ».');
            } catch (RuntimeException) {
                $this->assertNull($s->fresh()->quota_released_at);
            }
        }

        // Contrôle positif : la même séance, future et taguée, se débloque.
        $this->svc()->releaseQuota($ok = $this->piscine(), $this->coach);
        $this->assertNotNull($ok->fresh()->quota_released_at);
    }

    // ── Remises à zéro ──────────────────────────────────────────────────────────────────────

    public function test_changing_the_quota_tag_resets_the_release(): void
    {
        $s = $this->piscine();
        $this->svc()->releaseQuota($s, $this->coach);
        $autre = QuotaTag::create(['code' => 'ht', 'label' => 'Home-trainer', 'max_per_week' => 1]);

        // Contrôle : une édition qui ne touche pas au tag conserve l'état.
        Livewire::actingAs($this->coach)->test(SessionForm::class, ['session' => $s])
            ->set('capacity', 3)->call('saveSilently');
        $this->assertNotNull($s->fresh()->quota_released_at);

        Livewire::actingAs($this->coach)->test(SessionForm::class, ['session' => $s->fresh()])
            ->set('quota_tag_id', $autre->id)->call('saveSilently');

        $this->assertSame($autre->id, $s->fresh()->quota_tag_id);
        $this->assertNull($s->fresh()->quota_released_at);
    }

    public function test_restoring_a_cancelled_session_resets_the_release(): void
    {
        $s = $this->piscine();
        $this->svc()->releaseQuota($s, $this->coach);

        Livewire::actingAs($this->coach)->test(SessionShow::class, ['session' => $s])
            ->set('cancelCheck', true)->call('cancel');
        Livewire::actingAs($this->coach)->test(SessionShow::class, ['session' => $s->fresh()])
            ->call('restore');

        $this->assertNull($s->fresh()->cancelled_at);
        $this->assertNull($s->fresh()->quota_released_at);
    }

    // ── Fiche séance ────────────────────────────────────────────────────────────────────────

    public function test_coach_release_dialog_lists_promoted_and_is_guarded_server_side(): void
    {
        $s = $this->piscine(capacity: 2);
        $x = $this->athlete(['first_name' => 'Xavier', 'last_name' => 'Nageur']);
        $this->svc()->register($this->piscine(capacity: null, day: 0), $x, $x);
        $this->svc()->register($s, $x, $x, confirmQuota: true);

        $c = Livewire::actingAs($this->coach)->test(SessionShow::class, ['session' => $s])
            ->assertSee('Débloquer le quota')
            ->call('openReleaseConfirm')
            ->assertSet('releaseCheck', false)
            ->assertSee('Xavier Nageur')
            ->assertSee('Je comprends que 1 athlète sera prévenu·e');

        // Case non cochée : le serveur refuse, même si le client envoie l'appel.
        $c->call('releaseQuota')->assertSet('confirmingRelease', true);
        $this->assertNull($s->fresh()->quota_released_at);

        $c->set('releaseCheck', true)->set('releaseMotif', 'veille')->call('releaseQuota')
            ->assertSet('confirmingRelease', false)
            ->assertSee('Refermer le quota');
        $this->assertSame('participating', $this->statut($s, $x));
        $this->assertDatabaseHas('audit_logs', ['action' => 'quota_release', 'session_id' => $s->id, 'motif' => 'veille']);

        $c->call('closeQuota')->assertSee('Débloquer le quota');
        $this->assertNull($s->fresh()->quota_released_at);
    }

    public function test_athlete_sees_the_chip_but_cannot_release_or_close(): void
    {
        $s = $this->piscine();
        $athlete = $this->athlete();

        Livewire::actingAs($athlete)->test(SessionShow::class, ['session' => $s])
            ->assertDontSee('Quota débloqué')
            ->assertDontSee('Débloquer le quota')
            ->call('openReleaseConfirm')->assertForbidden();

        Livewire::actingAs($athlete)->test(SessionShow::class, ['session' => $s])
            ->call('releaseQuota')->assertForbidden();
        $this->assertNull($s->fresh()->quota_released_at);

        $this->svc()->releaseQuota($s, $this->coach);

        Livewire::actingAs($athlete)->test(SessionShow::class, ['session' => $s->fresh()])
            ->assertSee('Quota débloqué')
            ->assertDontSee('Refermer le quota')
            ->call('closeQuota')->assertForbidden();
        $this->assertNotNull($s->fresh()->quota_released_at);
    }
}
