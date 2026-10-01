<?php

namespace App\Services;

use App\Models\Debrief;
use App\Models\DebriefReaction;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Réactions « j'aime » sur les débriefs (#101, PRD §4.12.5). Tout lecteur du débrief peut réagir
// (membre connecté, mineur compris), sauf son auteur. Réagir n'abonne à rien.
//
// Notification à l'auteur REGROUPÉE : le premier « j'aime » crée une ligne d'outbox différée de
// GROUPING_MINUTES ; ceux qui suivent tant qu'elle n'est pas partie la mettent à jour (« Léa, Tom
// et 3 autres ») au lieu d'en créer une nouvelle. Un retrait la recalcule, et l'annule s'il ne reste
// personne : l'auteur n'est pas prévenu d'un « j'aime » qui n'existe plus.
class DebriefReactionService
{
    /** Fenêtre de regroupement : délai entre le premier « j'aime » et l'envoi à l'auteur. */
    public const GROUPING_MINUTES = 30;

    /** Prénoms portés par la notification ; au-delà, « et N autres ». */
    private const NAMES_IN_NOTICE = 2;

    public function __construct(private NotificationDispatcher $dispatcher) {}

    /**
     * Ajoute ou retire le « j'aime » de $user. Renvoie l'état final (true = aimé).
     *
     * @throws RuntimeException débrief archivé, ou réaction à son propre débrief
     */
    public function toggle(Debrief $debrief, User $user): bool
    {
        if ($debrief->isArchived()) {
            throw new RuntimeException('Ce débrief n\'est plus visible.');
        }
        if ($debrief->author_id === $user->id) {
            throw new RuntimeException('On ne réagit pas à son propre débrief.');
        }

        $liked = DB::transaction(function () use ($debrief, $user) {
            $deleted = DebriefReaction::where('debrief_id', $debrief->id)->where('user_id', $user->id)->delete();
            if ($deleted > 0) {
                return false;
            }
            // insertOrIgnore : un double-tap concurrent ne lève pas sur l'unicité.
            DebriefReaction::insertOrIgnore([
                'debrief_id' => $debrief->id,
                'user_id' => $user->id,
                'created_at' => Carbon::now(),
            ]);

            return true;
        });

        $this->syncNotice($debrief, created: $liked);

        return $liked;
    }

    /**
     * Efface les réactions d'un compte supprimé (§4.3) et retire son prénom des notifications qui
     * ne sont pas encore parties.
     */
    public function forgetUser(User $user): void
    {
        $debriefIds = DebriefReaction::where('user_id', $user->id)->pluck('debrief_id');
        DebriefReaction::where('user_id', $user->id)->delete();

        foreach (Debrief::whereIn('id', $debriefIds)->get() as $debrief) {
            $this->syncNotice($debrief);
        }
    }

    /**
     * Aligne la notification en attente sur les réactions réelles. $created : un « j'aime » vient
     * d'être ajouté, ce qui ouvre une nouvelle fenêtre si aucune n'est en cours.
     */
    private function syncNotice(Debrief $debrief, bool $created = false): void
    {
        $pending = NotificationOutbox::query()
            ->where('type', NotificationType::DebriefReaction->value)
            ->where('status', 'pending')
            ->where('payload->debrief_id', $debrief->id)
            ->get();

        if ($pending->isEmpty()) {
            if ($created) {
                $this->openNotice($debrief);
            }

            return;
        }

        $since = Carbon::parse($pending->first()->payload['reactions_since']);
        $reactors = $this->reactorsSince($debrief, $since);

        if ($reactors->isEmpty()) {
            NotificationOutbox::whereIn('id', $pending->pluck('id'))->delete();

            return;
        }

        foreach ($pending as $line) {
            $line->update(['payload' => [...$line->payload, ...$this->reactionPayload($reactors)]]);
        }
    }

    /** Première réaction d'une fenêtre : une ligne par destinataire et canal, différée. */
    private function openNotice(Debrief $debrief): void
    {
        $author = $debrief->author;
        $session = $debrief->session;
        if ($author === null || $session === null) {
            return;
        }

        // La fenêtre démarre à la réaction la plus récente (celle qui vient d'être posée), pas
        // maintenant : la seconde d'écart ne doit pas l'exclure du décompte.
        $since = Carbon::parse($debrief->reactions()->max('created_at'));
        $reactors = $this->reactorsSince($debrief, $since);
        if ($reactors->isEmpty()) {
            return;
        }

        $payload = [
            ...$session->payloadNotification(),
            'debrief_id' => $debrief->id,
            'reactions_since' => $since->toIso8601String(),
            ...$this->reactionPayload($reactors),
        ];

        $lines = $this->dispatcher->dispatch(NotificationType::DebriefReaction, $author, $payload);

        NotificationOutbox::whereIn('id', $lines->pluck('id'))
            ->update(['available_at' => Carbon::now()->addMinutes(self::GROUPING_MINUTES)]);
    }

    /**
     * Réacteurs de la fenêtre, du plus récent au plus ancien.
     *
     * @return Collection<int,User>
     */
    private function reactorsSince(Debrief $debrief, Carbon $since): Collection
    {
        return $debrief->reactions()
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->with('user:id,first_name')
            ->get()
            ->pluck('user')
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int,User>  $reactors
     * @return array{reactor_names:list<string>,reaction_count:int}
     */
    private function reactionPayload(Collection $reactors): array
    {
        return [
            'reactor_names' => $reactors->take(self::NAMES_IN_NOTICE)->pluck('first_name')->values()->all(),
            'reaction_count' => $reactors->count(),
        ];
    }
}
