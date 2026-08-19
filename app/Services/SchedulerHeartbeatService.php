<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Battement de cœur du cron (INSTALL §5.4).
 *
 * Toute la vie différée de l'app dépend d'un cron unique appelant `schedule:run` : drain de l'outbox
 * (§7.13/§7.14), météo, élagage des jetons. Si ce cron meurt — quota mutualisé, chemin PHP changé
 * après une mise à jour de l'hébergeur, ligne crontab perdue à un transfert — **rien ne le signale** :
 * les notifications s'accumulent en `pending` et le premier symptôme est un adhérent qui n'a pas été
 * prévenu d'une annulation. Panne silencieuse, découverte au pire moment.
 *
 * Ce service enregistre un horodatage à chaque passe du cron, et permet à l'écran des envois d'en
 * déduire un état lisible. Trois raisons de ne PAS déduire l'état des seules lignes de l'outbox :
 *
 *  1. `sent_at` ne prouve que les passes ayant eu quelque chose à envoyer — une outbox vide trois
 *     jours est indiscernable d'un cron mort trois jours ;
 *  2. le drain à la demande (bouton admin « envoyer maintenant ») écrit lui aussi `sent_at`, et
 *     ferait croire que le cron vit alors qu'un humain a cliqué ;
 *  3. l'absence de trace doit rester ambiguë (« jamais observé ») et non se lire comme une panne,
 *     sinon toute installation neuve s'ouvre sur une alerte rouge.
 *
 * Stockage en cache (driver `database` par défaut, cf. config/cache.php) plutôt qu'en table dédiée :
 * la donnée est purement opérationnelle, sans valeur historique, et n'a pas à peser une migration.
 * Un `cache:clear` remet le voyant à « inconnu » — état neutre, jamais une fausse alerte.
 */
class SchedulerHeartbeatService
{
    private const KEY = 'scheduler.last_run_at';

    /** Conservé bien au-delà du seuil d'alerte : au-delà, l'absence de clé vaut « inconnu ». */
    private const TTL_DAYS = 30;

    /**
     * Seuil au-delà duquel le cron est considéré interrompu.
     *
     * Le drain est planifié toutes les 5 min (routes/console.php). 15 min = trois passes manquées :
     * assez large pour absorber un mutualisé chargé ou un cron décalé, assez court pour repérer la
     * panne avant la vague de rappels du lendemain.
     */
    public const STALE_AFTER_MINUTES = 15;

    /** Appelé à chaque passe du cron, qu'il y ait eu ou non quelque chose à envoyer. */
    public function beat(?Carbon $at = null): void
    {
        Cache::put(self::KEY, ($at ?? Carbon::now())->toIso8601String(), Carbon::now()->addDays(self::TTL_DAYS));
    }

    /** Dernier passage observé, ou null si aucun n'a jamais été enregistré. */
    public function lastRunAt(): ?Carbon
    {
        $raw = Cache::get(self::KEY);

        return is_string($raw) ? Carbon::parse($raw) : null;
    }

    /**
     * Âge lisible d'un passage, en français. Au-delà de deux heures, « 180 min » demande un calcul
     * mental pour rien : on bascule en heures, puis en jours pour une panne installée.
     */
    public function humanAge(int $minutes): string
    {
        if ($minutes < 1) {
            return 'à l\'instant';
        }
        if ($minutes < 120) {
            return "il y a {$minutes} min";
        }
        if ($minutes < 2880) {
            return 'il y a '.intdiv($minutes, 60).' h';
        }

        return 'il y a '.intdiv($minutes, 1440).' jours';
    }

    /**
     * État de supervision destiné à l'affichage.
     *
     * @return array{state:'ok'|'stale'|'unknown', last:?Carbon, minutes:?int, age:?string}
     *                                                                                      - ok      : passage récent, tout va bien ;
     *                                                                                      - stale   : dernier passage trop ancien → le cron ne tourne plus ;
     *                                                                                      - unknown : jamais observé (installation neuve, ou cache vidé) — surtout pas une alerte.
     */
    public function status(): array
    {
        $last = $this->lastRunAt();

        if ($last === null) {
            return ['state' => 'unknown', 'last' => null, 'minutes' => null, 'age' => null];
        }

        // Un horodatage futur (horloge serveur reculée) ne doit pas produire un age negatif
        // qui passerait pour « frais » indefiniment : on le borne a 0.
        $minutes = max(0, (int) $last->diffInMinutes(Carbon::now(), absolute: false));

        return [
            'state' => $minutes >= self::STALE_AFTER_MINUTES ? 'stale' : 'ok',
            'last' => $last,
            'minutes' => $minutes,
            'age' => $this->humanAge($minutes),
        ];
    }
}
