<?php

namespace Tests\Feature;

use App\Livewire\SessionShow;
use App\Models\Debrief;
use App\Models\DebriefReaction;
use App\Models\NotificationOutbox;
use App\Models\NotificationPreferences;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Notifications\NotificationRenderer;
use App\Notifications\NotificationType;
use App\Services\DebriefReactionService;
use App\Services\DebriefService;
use App\Services\MemberService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Réactions « j'aime » sur les débriefs (#101, PRD §4.12.5).
class DebriefReactionTest extends TestCase
{
    use RefreshDatabase;

    private Session $session;

    private User $author;

    private Debrief $debrief;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = Session::create([
            'kind' => 'competition', 'title' => 'Tri de Vertou',
            'start_at' => Carbon::now()->subDay(), 'duration_min' => 120, 'capacity' => null,
            'created_by' => User::factory()->admin()->create()->id,
        ]);
        $this->author = User::factory()->create(['first_name' => 'Inès']);
        Registration::create([
            'session_id' => $this->session->id, 'user_id' => $this->author->id,
            'status' => 'participating', 'registered_at' => Carbon::now()->subWeek(),
        ]);
        $this->debrief = app(DebriefService::class)->publish($this->session, $this->author, 'Belle course');
        // Les annonces de publication ne nous intéressent pas ici.
        NotificationOutbox::query()->delete();
    }

    private function service(): DebriefReactionService
    {
        return app(DebriefReactionService::class);
    }

    /** @return Collection<int,NotificationOutbox> */
    private function notices()
    {
        return NotificationOutbox::where('type', NotificationType::DebriefReaction->value)->where('status', 'pending')->get();
    }

    public function test_any_member_can_like_and_the_author_gets_one_deferred_notice_per_channel(): void
    {
        // Ni participant ni coach : un simple lecteur du débrief.
        $lea = User::factory()->create(['first_name' => 'Léa']);

        $this->assertTrue($this->service()->toggle($this->debrief, $lea));

        $this->assertDatabaseHas('debrief_reactions', ['debrief_id' => $this->debrief->id, 'user_id' => $lea->id]);
        $notices = $this->notices();
        $this->assertEqualsCanonicalizing(['push', 'email'], $notices->pluck('channel')->all());
        $this->assertTrue($notices->every(fn ($n) => $n->user_id === $this->author->id));
        $this->assertSame(['Léa'], $notices->first()->payload['reactor_names']);
        // Différée : laisse le temps aux autres « j'aime » de s'y regrouper.
        $this->assertTrue($notices->first()->available_at->gte(Carbon::now()->addMinutes(DebriefReactionService::GROUPING_MINUTES - 1)));
    }

    public function test_following_likes_are_grouped_into_the_pending_notice(): void
    {
        foreach (['Léa', 'Tom', 'Zoé'] as $prenom) {
            $this->travel(1)->minutes();
            $this->service()->toggle($this->debrief, User::factory()->create(['first_name' => $prenom]));
        }

        $notices = $this->notices();
        $this->assertCount(2, $notices, 'Toujours une ligne par canal, pas une par « j\'aime ».');
        $this->assertSame(3, $notices->first()->payload['reaction_count']);
        $this->assertSame(['Zoé', 'Tom'], $notices->first()->payload['reactor_names']);
    }

    public function test_unlike_withdraws_the_reaction_and_cancels_an_empty_notice(): void
    {
        $lea = User::factory()->create();
        $this->service()->toggle($this->debrief, $lea);

        $this->assertFalse($this->service()->toggle($this->debrief, $lea));

        $this->assertSame(0, DebriefReaction::count());
        $this->assertCount(0, $this->notices(), 'L\'auteur n\'est pas prévenu d\'un « j\'aime » retiré.');
    }

    public function test_a_like_after_the_notice_left_opens_a_new_window(): void
    {
        $this->service()->toggle($this->debrief, User::factory()->create());
        NotificationOutbox::query()->update(['status' => 'sent', 'sent_at' => Carbon::now()]);

        $this->travel(2)->hours();
        $this->service()->toggle($this->debrief, User::factory()->create(['first_name' => 'Tom']));

        $notices = $this->notices();
        $this->assertCount(2, $notices);
        $this->assertSame(1, $notices->first()->payload['reaction_count']);
        $this->assertSame(['Tom'], $notices->first()->payload['reactor_names']);
    }

    public function test_author_cannot_like_own_debrief(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service()->toggle($this->debrief, $this->author);
    }

    public function test_archived_debrief_refuses_likes_but_keeps_existing_ones(): void
    {
        $lea = User::factory()->create();
        $this->service()->toggle($this->debrief, $lea);
        app(DebriefService::class)->archive($this->debrief, User::factory()->admin()->create());

        $this->assertSame(1, DebriefReaction::count(), 'Conservées pour une éventuelle réactivation.');
        $this->expectException(RuntimeException::class);
        $this->service()->toggle($this->debrief->refresh(), User::factory()->create());
    }

    public function test_minor_with_own_account_can_like(): void
    {
        $parent = User::factory()->create();
        $mineur = User::factory()->create([
            'guardian_id' => $parent->id,
            'dob' => Carbon::now()->subYears(14)->format('Y-m-d'),
        ]);

        $this->assertTrue($this->service()->toggle($this->debrief, $mineur));
    }

    public function test_preference_off_means_no_notice_but_the_like_counts(): void
    {
        NotificationPreferences::create([
            'user_id' => $this->author->id,
            'matrix' => [NotificationType::DebriefReaction->value => ['push' => false, 'email' => false]],
        ]);

        $this->service()->toggle($this->debrief, User::factory()->create());

        $this->assertSame(1, DebriefReaction::count());
        $this->assertCount(0, $this->notices());
    }

    public function test_reaction_type_is_in_the_club_group_of_the_matrix(): void
    {
        $club = collect(NotificationType::matrixGroups())->firstWhere('label', 'Le club');
        $this->assertContains(NotificationType::DebriefReaction, $club['types']);
        $this->assertSame('debriefs', NotificationType::DebriefReaction->sessionTab());
    }

    public function test_rendered_body_groups_names_and_falls_back_to_the_count(): void
    {
        $render = fn (array $payload, ?array $extra = []) => app(NotificationRenderer::class)->render(new NotificationOutbox([
            'type' => NotificationType::DebriefReaction->value, 'channel' => 'push', 'user_id' => $this->author->id,
            'payload' => ['session_id' => $this->session->id, 'session_title' => 'Tri de Vertou', ...$payload, ...$extra],
        ]))['body'];

        $this->assertSame('Léa a aimé ton débrief · Tri de Vertou', $render(['reactor_names' => ['Léa'], 'reaction_count' => 1]));
        $this->assertSame('Léa et Tom ont aimé ton débrief · Tri de Vertou', $render(['reactor_names' => ['Léa', 'Tom'], 'reaction_count' => 2]));
        $this->assertSame('Léa, Tom et 3 autres ont aimé ton débrief · Tri de Vertou', $render(['reactor_names' => ['Léa', 'Tom'], 'reaction_count' => 5]));
        // Prénoms purgés à l'envoi (volatils) : la page Alertes garde le compte.
        $this->assertSame('5 personnes ont aimé ton débrief · Tri de Vertou', $render(['reaction_count' => 5]));
        // Lue par le garant : on nomme l'enfant.
        $this->assertSame('Léa a aimé le débrief de Inès · Tri de Vertou', $render(
            ['reactor_names' => ['Léa'], 'reaction_count' => 1],
            ['subject_id' => $this->author->id + 1000, 'subject_first_name' => 'Inès'],
        ));
        $this->assertContains('reactor_names', NotificationOutbox::VOLATILE_PAYLOAD_KEYS);
    }

    public function test_account_deletion_erases_reactions_and_names_in_pending_notices(): void
    {
        $lea = User::factory()->create(['first_name' => 'Léa']);
        $tom = User::factory()->create(['first_name' => 'Tom']);
        $this->service()->toggle($this->debrief, $lea);
        $this->travel(1)->minutes();
        $this->service()->toggle($this->debrief, $tom);

        $tom->forceFill(['deletion_requested_at' => Carbon::now()->subDays(8), 'is_active' => false])->save();
        app(MemberService::class)->confirmDeletion($tom->refresh(), User::factory()->admin()->create());

        $this->assertSame([$lea->id], DebriefReaction::pluck('user_id')->all());
        $this->assertSame(['Léa'], $this->notices()->first()->payload['reactor_names']);
        $this->assertSame(1, $this->notices()->first()->payload['reaction_count']);
    }

    public function test_reader_toggles_from_the_debrief_tab_and_sees_count_and_names(): void
    {
        $lea = User::factory()->create(['first_name' => 'Léa', 'last_name' => 'Martin']);
        $this->service()->toggle($this->debrief, $lea);
        $tom = User::factory()->create();

        Livewire::actingAs($tom)->test(SessionShow::class, ['session' => $this->session])
            ->assertSee('J\'aime · 1', false)
            ->assertSee('Léa M.')
            ->call('toggleReaction', $this->debrief->id)
            ->assertSee('J\'aime · 2', false)
            ->assertSee('Toi et Léa M.');

        $this->assertSame(2, DebriefReaction::count());
    }

    public function test_author_sees_the_count_without_a_like_button(): void
    {
        $this->service()->toggle($this->debrief, User::factory()->create(['first_name' => 'Léa', 'last_name' => 'Martin']));

        Livewire::actingAs($this->author)->test(SessionShow::class, ['session' => $this->session])
            ->assertSee('Léa M.')
            ->assertDontSee('J\'aime', false)
            ->call('toggleReaction', $this->debrief->id)
            ->assertSee('propre débrief');

        $this->assertSame(1, DebriefReaction::count());
    }

    public function test_reaction_on_a_debrief_of_another_session_is_refused(): void
    {
        $autre = Session::create([
            'kind' => 'competition', 'title' => 'Autre', 'start_at' => Carbon::now()->subDay(),
            'duration_min' => 60, 'capacity' => null, 'created_by' => $this->session->created_by,
        ]);

        try {
            Livewire::actingAs(User::factory()->create())->test(SessionShow::class, ['session' => $autre])
                ->call('toggleReaction', $this->debrief->id);
            $this->fail('Un débrief d\'une autre séance ne doit pas être atteignable.');
        } catch (ModelNotFoundException) {
        }

        $this->assertSame(0, DebriefReaction::count());
    }
}
