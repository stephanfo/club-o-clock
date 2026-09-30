<?php

namespace Tests\Feature;

use App\Livewire\SessionShow;
use App\Models\ActivityLog;
use App\Models\Discipline;
use App\Models\NotificationOutbox;
use App\Models\QuotaTag;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Notifications\NotificationRenderer;
use App\Services\RegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

/**
 * #103 — obtenir une place HORS QUOTA réévalue l'autre place de la semaine (PRD §4.10.4 C). Avant,
 * l'athlète promu mardi gardait aussi vendredi : deux places pour un quota d'une, et quelqu'un
 * privé de séance vendredi. L'ordre des jours n'importe plus : c'est la dernière place obtenue
 * qui est rendue, sauf si le quota y est débloqué.
 */
class QuotaRequeueTest extends TestCase
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

    /** Séance piscine de la semaine prochaine, $day jours après le lundi. */
    private function piscine(?int $capacity, int $day): Session
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

    private function reg(Session $s, User $u): Registration
    {
        return Registration::where('session_id', $s->id)->where('user_id', $u->id)->firstOrFail();
    }

    private function statut(Session $s, User $u): string
    {
        $r = $this->reg($s, $u);

        return $r->status.($r->waitlist_reason ? ' '.$r->waitlist_reason : '');
    }

    /**
     * Le cas du coach : inscrit d'abord vendredi (dans son quota), puis mardi hors quota. Renvoie
     * [athlète, mardi, vendredi] ; mardi garde une place libre pour que le déblocage le promeuve.
     *
     * @return array{User, Session, Session}
     */
    private function vendrediPuisMardi(int $capaciteVendredi = 1): array
    {
        $mardi = $this->piscine(capacity: 2, day: 1);
        $vendredi = $this->piscine(capacity: $capaciteVendredi, day: 4);
        $x = $this->athlete(['first_name' => 'Xavier']);

        $this->svc()->register($vendredi, $x, $x);
        $this->travel(1)->minutes();
        $this->svc()->register($mardi, $x, $x, confirmQuota: true);
        $this->assertSame('waitlist quota_exceeded', $this->statut($mardi, $x));

        return [$x, $mardi, $vendredi];
    }

    // ── Rétrogradation ─────────────────────────────────────────────────────────────────────

    public function test_quota_release_requeues_the_other_place_of_the_week(): void
    {
        [$x, $mardi, $vendredi] = $this->vendrediPuisMardi();
        $inscritLe = $this->reg($vendredi, $x)->registered_at;

        $this->travel(1)->hours();
        $this->svc()->releaseQuota($mardi, $this->coach, acknowledged: true);

        $this->assertSame('participating', $this->statut($mardi, $x));
        $this->assertSame('waitlist quota_exceeded', $this->statut($vendredi, $x));
        // Rang d'origine : la date d'inscription n'est pas réécrite.
        $this->assertTrue($this->reg($vendredi, $x)->registered_at->equalTo($inscritLe));
        $this->assertNull($this->reg($vendredi, $x)->promoted_at);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'quota_requeued', 'actor_is_system' => true,
            'user_id' => $x->id, 'session_id' => $vendredi->id, 'resulting_status' => 'waitlist_quota_exceeded',
        ]);
    }

    public function test_mechanism_a_on_released_session_requeues_the_other_place(): void
    {
        [$x, $mardi, $vendredi] = $this->vendrediPuisMardi();
        // Mardi se remplit, puis est débloqué : Xavier reste en file quota faute de place.
        $a = $this->athlete();
        $b = $this->athlete();
        $this->svc()->register($mardi, $a, $a);
        $this->svc()->register($mardi, $b, $b);
        $this->reg($mardi, $x)->update(['status' => 'waitlist', 'waitlist_reason' => 'quota_exceeded']);
        $this->svc()->releaseQuota($mardi, $this->coach, acknowledged: true);
        $this->assertSame('participating', $this->statut($vendredi, $x), 'contrôle : rien ne bouge sans place');

        // Une place se libère mardi : A pioche dans la file quota → Xavier, qui rend vendredi.
        $this->svc()->cancel($mardi, $a, $a);

        $this->assertSame('participating', $this->statut($mardi, $x));
        $this->assertSame('waitlist quota_exceeded', $this->statut($vendredi, $x));
    }

    public function test_direct_registration_on_released_session_requeues_the_other_place(): void
    {
        $mardi = $this->piscine(capacity: 2, day: 1);
        $vendredi = $this->piscine(capacity: 1, day: 4);
        $x = $this->athlete();
        $this->svc()->register($vendredi, $x, $x);
        $this->svc()->releaseQuota($mardi, $this->coach);

        $reg = $this->svc()->register($mardi, $x, $x);

        $this->assertSame('participating', $reg->status);
        $this->assertSame('waitlist quota_exceeded', $this->statut($vendredi, $x));
        $this->assertTrue($reg->relationLoaded('requeued'));
    }

    public function test_freed_seat_goes_to_the_capacity_queue_and_is_notified(): void
    {
        [$x, $mardi, $vendredi] = $this->vendrediPuisMardi(capaciteVendredi: 1);
        $w = $this->athlete(['first_name' => 'Wanda']);
        $this->svc()->register($vendredi, $w, $w);
        $this->assertSame('waitlist capacity', $this->statut($vendredi, $w));

        $this->svc()->releaseQuota($mardi, $this->coach, acknowledged: true);

        $this->assertSame('participating', $this->statut($vendredi, $w));
        $this->assertSame(2, NotificationOutbox::where('type', 'waitlist_promoted')->where('user_id', $w->id)->count());
    }

    public function test_promotion_notification_names_the_requeued_session(): void
    {
        [$x, $mardi] = $this->vendrediPuisMardi();

        $this->svc()->releaseQuota($mardi, $this->coach, acknowledged: true);

        $line = NotificationOutbox::where('type', 'waitlist_promoted')->where('user_id', $x->id)->firstOrFail();
        $this->assertSame('Natation 4', $line->payload['requeued_session_title']);
        $body = app(NotificationRenderer::class)->render($line)['body'];
        $this->assertMatchesRegularExpression(
            "/^Tu es inscrit·e sur Natation 1 \\(.+\\), mais tu repasses en liste d'attente sur Natation 4 \\(.+\\) pour laisser la place à quelqu'un qui attendait\\.$/u",
            $body,
        );
    }

    /**
     * Corps d'une promotion avec place rendue : la bascule vient en tête (« inscrit·e… mais
     * repasse… »), sans quoi l'écran de notifications de l'OS la tronquait derrière le nom de séance.
     */
    private function corpsPromotion(array $payload, ?int $destinataire = null): string
    {
        $line = new NotificationOutbox([
            'type' => 'waitlist_promoted',
            'channel' => 'push',
            'payload' => ['session_id' => 1, 'session_title' => 'Natation 1', ...$payload],
            'user_id' => $destinataire ?? 999,
            'status' => 'pending',
        ]);

        return app(NotificationRenderer::class)->render($line)['body'];
    }

    public function test_guardian_reads_the_child_first_name_in_the_requeue_body(): void
    {
        $this->assertSame(
            "Léa est inscrit·e sur Natation 1, mais repasse en liste d'attente sur Natation 4 pour laisser la place à quelqu'un qui attendait.",
            $this->corpsPromotion(['requeued_session_title' => 'Natation 4', 'subject_id' => 7, 'subject_first_name' => 'Léa']),
        );
        $this->assertSame(
            "Ton enfant est inscrit·e sur Natation 1, mais repasse en liste d'attente sur Natation 4 pour laisser la place à quelqu'un qui attendait.",
            $this->corpsPromotion(['requeued_session_title' => 'Natation 4', 'subject_id' => 7]),
        );
    }

    /** Contrôle apparié : sans place rendue, le corps reste le format commun « séance · date ». */
    public function test_promotion_without_requeue_keeps_the_plain_session_body(): void
    {
        $this->assertSame('Natation 1', $this->corpsPromotion([]));
    }

    public function test_requeued_athlete_keeps_his_rank_ahead_of_later_over_quota_registrants(): void
    {
        // Vendredi plein : Xavier (inscrit en premier) le tient ; Yann arrive ensuite hors quota.
        [$x, $mardi, $vendredi] = $this->vendrediPuisMardi(capaciteVendredi: 1);
        $this->travel(1)->minutes();
        $y = $this->athlete(['first_name' => 'Yann']);
        $this->svc()->register($this->piscine(capacity: null, day: 0), $y, $y);
        $this->svc()->register($vendredi, $y, $y, confirmQuota: true);

        $this->svc()->releaseQuota($mardi, $this->coach, acknowledged: true);

        $file = Registration::where('session_id', $vendredi->id)
            ->where('waitlist_reason', 'quota_exceeded')->orderBy('registered_at')->pluck('user_id')->all();
        $this->assertSame([$x->id, $y->id], $file);
    }

    public function test_with_two_per_week_the_last_obtained_place_is_requeued(): void
    {
        $this->tag->update(['max_per_week' => 2]);
        $lundi = $this->piscine(capacity: null, day: 0);
        $mercredi = $this->piscine(capacity: null, day: 2);
        $vendredi = $this->piscine(capacity: 2, day: 4);
        $x = $this->athlete();

        // Obtenu mercredi PUIS lundi : c'est lundi, la dernière place obtenue, qui est rendue.
        $this->svc()->register($mercredi, $x, $x);
        $this->travel(1)->minutes();
        $this->svc()->register($lundi, $x, $x);
        $this->travel(1)->minutes();
        $this->svc()->register($vendredi, $x, $x, confirmQuota: true);

        $this->svc()->releaseQuota($vendredi, $this->coach, acknowledged: true);

        $this->assertSame('participating', $this->statut($vendredi, $x));
        $this->assertSame('waitlist quota_exceeded', $this->statut($lundi, $x));
        $this->assertSame('participating', $this->statut($mercredi, $x));
    }

    // ── Maintien ───────────────────────────────────────────────────────────────────────────

    public function test_other_place_is_kept_when_its_quota_is_released_too(): void
    {
        [$x, $mardi, $vendredi] = $this->vendrediPuisMardi();
        $this->svc()->releaseQuota($vendredi, $this->coach);

        $this->svc()->releaseQuota($mardi, $this->coach, acknowledged: true);

        // Pas de boucle ni de perte : deux séances débloquées, deux places.
        $this->assertSame('participating', $this->statut($mardi, $x));
        $this->assertSame('participating', $this->statut($vendredi, $x));
        $this->assertSame(0, ActivityLog::where('action', 'quota_requeued')->count());
    }

    public function test_override_never_triggers_nor_suffers_a_requeue(): void
    {
        // Override sur mardi : geste délibéré du coach, vendredi n'est pas réévalué.
        [$x, $mardi, $vendredi] = $this->vendrediPuisMardi();
        $this->svc()->overrideRegister($mardi, $x, $this->coach);
        $this->assertSame('participating', $this->statut($vendredi, $x));

        // Place obtenue par override : jamais rendue par le système.
        $y = $this->athlete();
        $jeudi = $this->piscine(capacity: 2, day: 3);
        $this->svc()->overrideRegister($vendredi, $y, $this->coach);
        $this->svc()->register($jeudi, $y, $y, confirmQuota: true);
        $this->svc()->releaseQuota($jeudi, $this->coach, acknowledged: true);

        $this->assertSame('participating', $this->statut($jeudi, $y));
        $this->assertSame('participating', $this->statut($vendredi, $y));
    }

    public function test_a_started_session_is_never_requeued(): void
    {
        $lundi = $this->piscine(capacity: null, day: 0);
        $vendredi = $this->piscine(capacity: 2, day: 4);
        $x = $this->athlete();
        $this->svc()->register($lundi, $x, $x);
        $this->svc()->register($vendredi, $x, $x, confirmQuota: true);

        $this->travelTo($lundi->start_at->copy()->addMinutes(10));
        $this->svc()->releaseQuota($vendredi, $this->coach, acknowledged: true);

        $this->assertSame('participating', $this->statut($vendredi, $x));
        $this->assertSame('participating', $this->statut($lundi, $x));
    }

    // ── Écran ──────────────────────────────────────────────────────────────────────────────

    public function test_quota_dialog_names_the_place_that_would_be_given_back(): void
    {
        $mardi = $this->piscine(capacity: 2, day: 1);
        $vendredi = $this->piscine(capacity: 1, day: 4);
        $x = $this->athlete();
        $this->svc()->register($vendredi, $x, $x);

        Livewire::actingAs($x)->test(SessionShow::class, ['session' => $mardi->fresh()])
            ->call('enroll')
            ->assertSet('confirmingQuota', true)
            ->assertSee('Si une place t&#039;est attribuée ici', false)
            ->assertSee('« Natation 4 » du', false);
    }

    public function test_released_session_asks_before_giving_back_the_other_place(): void
    {
        $mardi = $this->piscine(capacity: 2, day: 1);
        $vendredi = $this->piscine(capacity: 1, day: 4);
        $x = $this->athlete();
        $this->svc()->register($vendredi, $x, $x);
        $this->svc()->releaseQuota($mardi, $this->coach);

        Livewire::actingAs($x)->test(SessionShow::class, ['session' => $mardi->fresh()])
            ->assertSee('Ta place à « Natation 4 » du')
            ->call('enroll')
            ->assertSee('repasse en liste d&#039;attente', false);

        $this->assertSame('waitlist quota_exceeded', $this->statut($vendredi, $x));
    }

    public function test_no_warning_without_another_place(): void
    {
        $mardi = $this->piscine(capacity: 2, day: 1);
        $x = $this->athlete();
        $this->svc()->releaseQuota($mardi, $this->coach);

        Livewire::actingAs($x)->test(SessionShow::class, ['session' => $mardi->fresh()])
            ->assertSee("S'inscrire")
            ->assertDontSee('repassera en liste', false);
    }
}
