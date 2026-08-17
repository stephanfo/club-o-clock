<?php

namespace App\Notifications;

use App\Models\ClubSettings;
use App\Models\NotificationOutbox;
use App\Notifications\Channels\ChannelManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

// Drain de l'outbox (cadrage §7.14) — chemin d'envoi unique partagé par les trois modes :
//   • cron (drainDue) : par lots, lignes échues — tâche planifiée §7.13 ;
//   • à la demande (drainNow) : envoi prioritaire synchrone après commit, ignore l'échéance.
// Mono-cron, volume faible → pas de SKIP LOCKED ni de concurrence de drain (cadrage §6, §7.14).
class OutboxDrainer
{
    /** Backoff en minutes indexé par n° de tentative ; au-delà de MAX_ATTEMPTS → failed. */
    public const BACKOFF_MINUTES = [1 => 1, 2 => 5, 3 => 15, 4 => 60, 5 => 240];

    public const MAX_ATTEMPTS = 5;

    public function __construct(private ChannelManager $channels) {}

    /**
     * Drain par lots : lignes pending dont available_at est échu (ou nul). Utilisé par le cron.
     *
     * @return array{sent:int,retried:int,failed:int,cancelled:int}
     */
    public function drainDue(int $limit = 100): array
    {
        $lines = NotificationOutbox::query()
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', Carbon::now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return $this->process($lines);
    }

    /**
     * Drain à la demande (envoi prioritaire §7.14) : pousse immédiatement les lignes fournies,
     * sans attendre l'échéance. Ne traite que les lignes encore pending.
     *
     * @param  iterable<NotificationOutbox>  $lines
     * @return array{sent:int,retried:int,failed:int,cancelled:int}
     */
    public function drainNow(iterable $lines): array
    {
        $lines = collect($lines)->filter(fn (NotificationOutbox $line) => $line->status === 'pending');

        return $this->process($lines->values());
    }

    /**
     * @param  Collection<int,NotificationOutbox>  $lines
     * @return array{sent:int,retried:int,failed:int,cancelled:int}
     */
    private function process(Collection $lines): array
    {
        $stats = ['sent' => 0, 'retried' => 0, 'failed' => 0, 'cancelled' => 0];
        // Singleton mémoïsé : une lecture pour toute la passe.
        $settings = ClubSettings::current();

        foreach ($lines as $line) {
            // Garde de rattrapage de l'interrupteur club (§4.17) : lignes émises avant la coupure
            // du canal, ou pendant la fenêtre de bascule. Annulées plutôt qu'envoyées — `cancelled`
            // est déjà un statut de l'outbox, donc la ligne reste consultable dans l'écran admin
            // des envois. Pas de retry : la cause n'est pas transitoire.
            if (! $settings->channelEnabled($line->channel)) {
                $line->update(['status' => 'cancelled']);
                $stats['cancelled']++;

                continue;
            }

            try {
                $delivered = $this->channels->driver($line->channel)->send($line);
            } catch (Throwable $e) {
                // Échec de transport : on programme un retry/backoff plus bas, mais on trace l'erreur
                // (sinon les échecs de livraison sont silencieux, seul `attempts` bouge).
                Log::warning('Outbox delivery failed', [
                    'outbox_id' => $line->id,
                    'channel' => $line->channel,
                    'type' => $line->type,
                    'attempts' => $line->attempts,
                    'exception' => $e->getMessage(),
                ]);
                $delivered = false;
            }

            if ($delivered) {
                $line->update(['status' => 'sent', 'sent_at' => Carbon::now()]);
                $stats['sent']++;

                continue;
            }

            $attempts = $line->attempts + 1;

            if ($attempts > self::MAX_ATTEMPTS) {
                // Échec définitif : reste consultable et rejouable depuis l'écran outbox (J8.3).
                $line->update(['attempts' => $attempts, 'status' => 'failed']);
                $stats['failed']++;
            } else {
                $line->update([
                    'attempts' => $attempts,
                    'available_at' => Carbon::now()->addMinutes(self::BACKOFF_MINUTES[$attempts]),
                ]);
                $stats['retried']++;
            }
        }

        return $stats;
    }
}
