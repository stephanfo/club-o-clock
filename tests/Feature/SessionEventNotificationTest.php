<?php

namespace Tests\Feature;

use App\Livewire\SessionForm;
use App\Livewire\SessionShow;
use App\Models\Category;
use App\Models\ClubSettings;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\Location;
use App\Models\NotificationOutbox;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Notifications\Channels\FakeChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

// J8.2 — Événements de séance (§4.7) : modification (dialog 3 choix + envoi prioritaire),
// annulation (notif toujours) et restauration. Fan-out aux `participating` via l'outbox (J8.1).
class SessionEventNotificationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Séance d'entraînement future avec $n inscrits actifs.
     *
     * @return array{0:User,1:Session,2:Collection<int,User>}
     */
    private function trainingWithParticipants(int $n): array
    {
        $coach = User::factory()->coach()->create();
        $disc = Discipline::create(['label' => 'Natation', 'sort_order' => 0]);
        $session = Session::create([
            'kind' => 'training', 'title' => 'Natation seuil', 'discipline_id' => $disc->id,
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);

        $athletes = User::factory()->count($n)->create();
        foreach ($athletes as $a) {
            Registration::create([
                'session_id' => $session->id, 'user_id' => $a->id,
                'status' => 'participating', 'registered_at' => Carbon::now(),
            ]);
        }

        return [$coach, $session, $athletes];
    }

    private function useFakeChannels(): FakeChannel
    {
        $fake = new FakeChannel;
        $this->app->instance(FakeChannel::class, $fake);
        config([
            'club.notifications.channels.push' => FakeChannel::class,
            'club.notifications.channels.email' => FakeChannel::class,
        ]);

        return $fake;
    }

    // ── Annulation / restauration ──

    public function test_cancel_always_notifies_all_participants(): void
    {
        [$coach, $session] = $this->trainingWithParticipants(3);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session])->set('cancelCheck', true)->call('cancel');

        // 3 inscrits × (push + email) = 6 lignes session_cancelled.
        $this->assertSame(6, NotificationOutbox::where('type', 'session_cancelled')->count());
    }

    public function test_restore_notifies_all_participants(): void
    {
        [$coach, $session] = $this->trainingWithParticipants(2);
        $session->forceFill(['cancelled_at' => Carbon::now(), 'cancelled_by' => $coach->id])->save();

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session->fresh()])->call('restore');

        $this->assertSame(4, NotificationOutbox::where('type', 'session_restored')->count());
    }

    public function test_cancel_without_participants_emits_nothing(): void
    {
        $coach = User::factory()->coach()->create();
        $session = Session::create([
            'kind' => 'training', 'title' => 'Vide',
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);

        Livewire::actingAs($coach)->test(SessionShow::class, ['session' => $session])->set('cancelCheck', true)->call('cancel');

        $this->assertSame(0, NotificationOutbox::count());
    }

    // ── Modification : dialog 3 choix ──

    public function test_structural_change_with_participants_opens_dialog_without_saving(): void
    {
        [$coach, $session] = $this->trainingWithParticipants(1);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('duration_min', 90)
            ->call('save')
            ->assertSet('showSaveDialog', true);

        $this->assertSame(60, $session->fresh()->duration_min); // rien persisté
        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_location_change_is_detected_as_structural(): void
    {
        [$coach, $session] = $this->trainingWithParticipants(1);
        $loc = Location::create(['name' => 'Piscine', 'latitude' => 47.37, 'longitude' => -1.17, 'created_by' => $coach->id]);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('location_id', $loc->id)
            ->call('save')
            ->assertSet('showSaveDialog', true);

        $this->assertNull($session->fresh()->location_id); // rien persisté
    }

    public function test_category_change_is_detected_as_structural(): void
    {
        [$coach, $session] = $this->trainingWithParticipants(1);
        $cat = Category::create(['label' => 'Sénior', 'age_min' => 20, 'age_max' => 39, 'sort_order' => 1]);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->call('toggleCategory', $cat->id)
            ->call('save')
            ->assertSet('showSaveDialog', true);

        $this->assertSame(0, $session->fresh()->categories()->count()); // rien persisté
    }

    public function test_save_and_notify_is_idempotent_without_any_change(): void
    {
        // Garde de durcissement : invoquée hors dialog, sans aucun changement → aucune notif.
        [$coach, $session] = $this->trainingWithParticipants(2);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->call('saveAndNotify');

        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_save_silently_persists_without_notifying(): void
    {
        [$coach, $session] = $this->trainingWithParticipants(2);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('capacity', 12)
            ->call('save')
            ->call('saveSilently');

        $this->assertSame(12, $session->fresh()->capacity);
        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_save_and_notify_emits_session_modified(): void
    {
        [$coach, $session] = $this->trainingWithParticipants(2);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('duration_min', 75)
            ->call('save')
            ->call('saveAndNotify');

        $this->assertSame(75, $session->fresh()->duration_min);
        // Différé par défaut : lignes en attente, pas encore envoyées.
        $this->assertSame(4, NotificationOutbox::where('type', 'session_modified')->where('status', 'pending')->count());
    }

    public function test_priority_send_drains_immediately(): void
    {
        $fake = $this->useFakeChannels();
        [$coach, $session] = $this->trainingWithParticipants(1);

        // Nouvelle date en HEURE LOCALE club (comme la saisie utilisateur), décalée de +3 h par
        // rapport à l'existant pour garantir un changement détecté à la minute.
        $tz = ClubSettings::current()->timezone;
        $newStart = $session->start_at->copy()->setTimezone($tz)->addHours(3)->format('Y-m-d\TH:i');

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('start_at', $newStart)
            ->call('save')
            ->set('notify_priority', true)
            ->call('saveAndNotify');

        // Envoi prioritaire → drainé tout de suite (push + email).
        $this->assertSame(2, NotificationOutbox::where('status', 'sent')->count());
        $this->assertCount(2, $fake->sent);
    }

    public function test_content_change_with_participants_opens_dialog(): void
    {
        // J8.5 : le contenu (texte/parcours) rejoint le dialog 3 choix (type session_content).
        [$coach, $session] = $this->trainingWithParticipants(2);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('content_markdown', '# Plan de séance')
            ->call('save')
            ->assertSet('showSaveDialog', true);

        $this->assertNull($session->fresh()->content_markdown); // rien persisté
        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_content_change_emits_session_content_via_dialog(): void
    {
        [$coach, $session] = $this->trainingWithParticipants(2);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('content_markdown', '# Plan de séance')
            ->call('save')
            ->call('saveAndNotify');

        $this->assertStringContainsString('Plan de séance', $session->fresh()->content_markdown);
        // 2 inscrits × (push + email) = 4 lignes session_content (différé, pas session_modified).
        $this->assertSame(4, NotificationOutbox::where('type', 'session_content')->count());
        $this->assertSame(0, NotificationOutbox::where('type', 'session_modified')->count());
    }

    public function test_content_change_without_participants_saves_directly(): void
    {
        $coach = User::factory()->coach()->create();
        $disc = Discipline::create(['label' => 'Natation', 'sort_order' => 0]);
        $session = Session::create([
            'kind' => 'training', 'title' => 'Sans inscrit', 'discipline_id' => $disc->id,
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('content_markdown', '# Notes')
            ->call('save')
            ->assertSet('showSaveDialog', false);

        $this->assertStringContainsString('Notes', $session->fresh()->content_markdown);
        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_structural_change_without_participants_saves_directly(): void
    {
        $coach = User::factory()->coach()->create();
        $disc = Discipline::create(['label' => 'Natation', 'sort_order' => 0]);
        $session = Session::create([
            'kind' => 'training', 'title' => 'Sans inscrit', 'discipline_id' => $disc->id,
            'start_at' => Carbon::now()->addWeek(), 'duration_min' => 60, 'created_by' => $coach->id,
        ]);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('duration_min', 90)
            ->call('save')
            ->assertSet('showSaveDialog', false);

        $this->assertSame(90, $session->fresh()->duration_min);
        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_dismiss_dialog_keeps_form_without_saving(): void
    {
        [$coach, $session] = $this->trainingWithParticipants(1);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('duration_min', 90)
            ->call('save')
            ->assertSet('showSaveDialog', true)
            ->call('dismissSaveDialog')
            ->assertSet('showSaveDialog', false);

        $this->assertSame(60, $session->fresh()->duration_min);
    }

    // ── Ce que le dialog ANNONCE (§4.17) : canaux réellement ouverts, nature du changement ──

    /** Ouvre le dialog sur un changement structurant et renvoie le HTML rendu. */
    private function dialogHtmlForStructuralChange(): string
    {
        [$coach, $session] = $this->trainingWithParticipants(1);

        return Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('duration_min', 90)
            ->call('save')
            ->assertSet('showSaveDialog', true)
            ->html();
    }

    public function test_dialog_ne_promet_que_les_canaux_ouverts_par_le_club(): void
    {
        ClubSettings::current()->update(['notif_email_enabled' => false]);

        $html = $this->dialogHtmlForStructuralChange();

        $this->assertStringContainsString('par push', $html);
        $this->assertStringNotContainsString('par email', $html);
    }

    public function test_dialog_avertit_et_desactive_le_bouton_quand_aucun_canal_nest_ouvert(): void
    {
        ClubSettings::current()->update(['notif_push_enabled' => false, 'notif_email_enabled' => false]);

        $html = $this->dialogHtmlForStructuralChange();

        $this->assertStringContainsString('Aucun canal de notification', $html);
        // Le bouton « prévenir » reste visible (l'admin doit comprendre pourquoi), mais inactivable.
        // `disabled` nu (attribut booléen rendu par @disabled), pas `="disabled"` — que porterait
        // aussi le wire:loading.attr voisin : on exige l'attribut, précédé d'un blanc et fermant la balise.
        $this->assertMatchesRegularExpression('/wire:click="saveAndNotify"[^>]*\sdisabled\s*>/s', $html);
    }

    public function test_dialog_distingue_changement_de_contenu_et_champ_structurant(): void
    {
        $this->assertStringContainsString('Un champ structurant a changé', $this->dialogHtmlForStructuralChange());

        [$coach, $session] = $this->trainingWithParticipants(1);
        $html = Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('content_markdown', '# Plan de séance')
            ->call('save')
            ->assertSet('pendingStructural', false)
            ->html();

        $this->assertStringContainsString('Le contenu de la séance a changé', $html);
        $this->assertStringNotContainsString('Un champ structurant a changé', $html);
    }

    // ── Le dialog ne survit pas au départ vers la fiche ──
    //
    // `wire:navigate` mémorise la page quittée AVEC le dernier snapshot du composant : un
    // showSaveDialog resté à true rouvrait la modale, déjà validée, à chaque retour arrière.

    public function test_save_and_notify_referme_le_dialog_avant_de_rediriger(): void
    {
        [$coach, $session] = $this->trainingWithParticipants(1);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('duration_min', 90)
            ->call('save')
            ->assertSet('showSaveDialog', true)
            ->call('saveAndNotify')
            ->assertSet('showSaveDialog', false)
            ->assertSet('pendingChanges', [])
            ->assertSet('pendingStructural', false)
            ->assertSet('notify_priority', false)
            ->assertRedirect(route('sessions.show', $session));
    }

    public function test_save_silently_referme_le_dialog_avant_de_rediriger(): void
    {
        [$coach, $session] = $this->trainingWithParticipants(1);

        Livewire::actingAs($coach)->test(SessionForm::class, ['session' => $session])
            ->set('duration_min', 90)
            ->call('save')
            ->call('saveSilently')
            ->assertSet('showSaveDialog', false)
            ->assertSet('pendingChanges', []);
    }

    // ── Création compétition / événement club : annonce event_created (§4.7) ──

    /** @return array{0:Discipline,1:EventType} */
    private function competitionCatalogues(): array
    {
        return [
            Discipline::create(['label' => 'Course', 'sort_order' => 0]),
            EventType::create(['label' => 'Triathlon', 'sort_order' => 0]),
        ];
    }

    public function test_event_created_notifies_target_category_only(): void
    {
        $coach = User::factory()->coach()->create();
        [$disc, $type] = $this->competitionCatalogues();
        $catX = Category::create(['label' => 'Sénior', 'age_min' => 20, 'age_max' => 39, 'sort_order' => 1]);
        $catY = Category::create(['label' => 'Master', 'age_min' => 40, 'age_max' => 59, 'sort_order' => 2]);

        $inX = User::factory()->count(2)->create();
        $inX->each(fn (User $u) => $u->categories()->attach($catX->id));
        $outY = User::factory()->create();
        $outY->categories()->attach($catY->id);

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'competition')
            ->set('title', 'Triathlon de Nantes')
            ->set('discipline_id', $disc->id)
            ->set('event_type_id', $type->id)
            ->set('start_at', Carbon::now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('duration_min', 120)
            ->set('category_ids', [$catX->id])
            ->call('save');

        // 2 athlètes ciblés × (push + email) = 4 ; hors catégorie et créateur = 0.
        $this->assertSame(4, NotificationOutbox::where('type', 'event_created')->count());
        $inX->each(fn (User $u) => $this->assertSame(
            2, NotificationOutbox::where('type', 'event_created')->where('user_id', $u->id)->count()
        ));
        $this->assertSame(0, NotificationOutbox::where('user_id', $outY->id)->count());
        $this->assertSame(0, NotificationOutbox::where('user_id', $coach->id)->count());
    }

    public function test_training_creation_emits_no_event_created(): void
    {
        $coach = User::factory()->coach()->create();
        $disc = Discipline::create(['label' => 'Natation', 'sort_order' => 0]);
        User::factory()->count(3)->create(); // membres existants

        Livewire::actingAs($coach)->test(SessionForm::class)
            ->set('kind', 'training')
            ->set('title', 'Natation seuil')
            ->set('discipline_id', $disc->id)
            ->set('start_at', Carbon::now()->addWeek()->format('Y-m-d\TH:i'))
            ->set('duration_min', 60)
            ->call('save');

        $this->assertSame(0, NotificationOutbox::count());
    }
}
