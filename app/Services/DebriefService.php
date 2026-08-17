<?php

namespace App\Services;

use App\Models\Debrief;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Support\Logging\ActivityLogger;
use App\Support\Markup;
use Illuminate\Support\Carbon;
use RuntimeException;

// Débriefs de compétition (PRD §4.12.5) : retour personnel d'un participant, texte enrichi WYSIWYG.
// 0..N par compétition, ≤1 par (compétition, auteur). Rédaction après le départ. Édition par l'auteur
// et l'admin ; archivage/réactivation admin (soft-delete). Sanitisation serveur faisant foi (§4.12.1).
class DebriefService
{
    public function __construct(private SessionNotificationService $notifier) {}

    /**
     * Publie le débrief de $author sur $session. Gardes (§4.12.5) : compétition commencée, auteur
     * `participating`, pas de débrief existant. ActivityLog debrief_published + notif new_debrief
     * aux co-participants (l'auteur exclu).
     */
    public function publish(Session $session, User $author, string $markdown): Debrief
    {
        if ($session->kind !== 'competition') {
            throw new RuntimeException('Les débriefs ne concernent que les compétitions.');
        }
        if (! $session->hasStarted()) {
            throw new RuntimeException('Le débrief s\'écrit après le départ de la compétition.');
        }
        if (! $this->participates($session, $author)) {
            throw new RuntimeException('Seuls les participants peuvent rédiger un débrief.');
        }
        if (Debrief::where('session_id', $session->id)->where('author_id', $author->id)->exists()) {
            throw new RuntimeException('Tu as déjà publié un débrief pour cette compétition.');
        }

        $content = Markup::clean($markdown);
        if ($content === null) {
            throw new RuntimeException('Le débrief est vide.');
        }

        $debrief = Debrief::create([
            'session_id' => $session->id,
            'author_id' => $author->id,
            'content_markdown' => $content,
        ]);

        ActivityLogger::record('debrief_published', $author, [
            'user_id' => $author->id,
            'session_id' => $session->id,
        ]);

        // Les autres participants de la compétition sont prévenus (l'auteur ne se notifie pas).
        $this->notifier->notifyParticipants($session, NotificationType::NewDebrief, excludeUserId: $author->id);

        return $debrief;
    }

    /** Édition (auteur ou admin §4.12.5). Pas de renotification (compléments silencieux). */
    public function update(Debrief $debrief, User $actor, string $markdown): Debrief
    {
        $content = Markup::clean($markdown);
        if ($content === null) {
            throw new RuntimeException('Le débrief est vide.');
        }

        $debrief->update(['content_markdown' => $content]);

        ActivityLogger::record('debrief_updated', $actor, [
            'user_id' => $debrief->author_id,
            'session_id' => $debrief->session_id,
        ]);

        return $debrief;
    }

    /** Archivage admin (soft-delete §4.12.5) : disparaît de la liste publique, reste restaurable. */
    public function archive(Debrief $debrief, User $admin): void
    {
        if ($debrief->isArchived()) {
            return;
        }

        $debrief->update(['archived_at' => Carbon::now(), 'archived_by' => $admin->id]);

        ActivityLogger::record('debrief_archived', $admin, [
            'user_id' => $debrief->author_id,
            'session_id' => $debrief->session_id,
        ]);
    }

    /** Réactivation admin : le débrief redevient visible. */
    public function restore(Debrief $debrief, User $admin): void
    {
        if (! $debrief->isArchived()) {
            return;
        }

        $debrief->update(['archived_at' => null, 'archived_by' => null]);

        ActivityLogger::record('debrief_restored', $admin, [
            'user_id' => $debrief->author_id,
            'session_id' => $debrief->session_id,
        ]);
    }

    /** L'utilisateur a-t-il une inscription `participating` sur la compétition ? */
    private function participates(Session $session, User $user): bool
    {
        return Registration::query()
            ->where('session_id', $session->id)
            ->where('user_id', $user->id)
            ->where('status', 'participating')
            ->exists();
    }
}
