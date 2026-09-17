<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// notification_outbox — file d'envois persistée (cadrage §7.14). Drain branché J8.
class NotificationOutbox extends Model
{
    protected $table = 'notification_outbox';

    public const STATUSES = ['pending', 'sent', 'failed', 'cancelled'];

    /** Libellés FR des statuts pour l'écran de gestion (§4.15.6). */
    public const STATUS_LABELS = [
        'pending' => 'En attente',
        'sent' => 'Envoyée',
        'failed' => 'En échec',
        'cancelled' => 'Annulée',
    ];

    /**
     * Clés de payload qui portent un SECRET D'ACCÈS et ne doivent survivre ni à l'envoi ni à
     * l'affichage. Un jeton d'invitation en clair vaut la prise du compte de l'adhérent : le laisser
     * dormir dans la table et le rendre lisible dans le tiroir admin donnait à tout admin, pour
     * toujours, de quoi entrer dans n'importe quel compte invité.
     *
     * Purgées à l'envoi comme VOLATILE_PAYLOAD_KEYS, mais en plus MASQUÉES à l'affichage.
     */
    public const SENSITIVE_PAYLOAD_KEYS = ['token'];

    /**
     * Clés de payload qui ont fini leur office une fois la ligne envoyée. Distinctes des secrets :
     * elles ne sont PAS masquées dans le tiroir admin — un prénom n'est pas un jeton, le masquer
     * rendrait l'écran des envois illisible pour la seule ligne où il aide (celle qui n'est pas
     * partie). Elles sont simplement retirées au passage à `sent` (minimisation RGPD §4.19) : le
     * prénom du sujet n'a servi qu'à composer le titre, et la page Alertes le re-résout depuis
     * `subject_id`, qui reste. Un nom d'enfant ne dort donc pas indéfiniment dans la file.
     *
     * Même raison pour `guardian_name` (nom complet du garant, §4.2.1) : il ne sert qu'au corps de
     * l'alerte de rattachement, dont le rendu retombe sur la description du type sans lui.
     */
    public const VOLATILE_PAYLOAD_KEYS = ['subject_first_name', 'guardian_name'];

    /** Visibilité d'une alerte sur la page Alertes (#79) : après la fin de sa séance, ou après l'envoi. */
    public const DAYS_AFTER_SESSION = 7;

    public const DAYS_WITHOUT_SESSION = 60;

    protected $fillable = [
        'type', 'channel', 'payload', 'user_id', 'status', 'attempts', 'available_at', 'sent_at', 'read_at', 'dismissed_at',
    ];

    /**
     * Payload rendu affichable : les secrets sont masqués, jamais montrés — y compris sur une ligne
     * `pending` ou `failed`, dont le jeton est encore vivant.
     *
     * @return array<string,mixed>
     */
    public function redactedPayload(): array
    {
        $payload = $this->payload ?? [];

        foreach (self::SENSITIVE_PAYLOAD_KEYS as $cle) {
            if (array_key_exists($cle, $payload)) {
                $payload[$cle] = '••••••';
            }
        }

        return $payload;
    }

    /** @var array<string, string> */
    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'sent_at' => 'datetime',
        'read_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alertes visibles par l'utilisateur sur la page Alertes (push envoyés, ni masqués ni expirés) —
     * base commune du listing, du badge de non-lus et du marquage lu (revue UX 2026-07-11).
     *
     * @return Builder<self>
     */
    public static function alertsFor(int $userId): Builder
    {
        // Rattachement à la séance par le payload JSON (aucune clé étrangère, cf. SessionDeletionService).
        $seance = fn ($q) => $q->selectRaw('1')->from('sessions')
            ->whereRaw("sessions.id = JSON_EXTRACT(notification_outbox.payload, '$.session_id')");
        $creneauFige = "JSON_UNQUOTE(JSON_EXTRACT(notification_outbox.payload, '$.session_start_at'))";

        return self::query()
            ->where('user_id', $userId)
            ->where('status', 'sent')
            ->where('channel', 'push')
            ->whereNull('dismissed_at')
            // Visibilité (#79) : une alerte de séance vit jusqu'à 7 jours après la FIN de la séance,
            // quelle que soit sa date d'envoi — une compétition annoncée trois mois plus tôt reste là.
            // Annulée : même règle sur la fin prévue. Supprimée : le créneau figé au payload
            // (toIso8601String en UTC, d'où les 19 premiers caractères). Sans séance : 60 jours d'envoi.
            ->where(fn ($q) => $q
                ->whereExists(fn ($s) => $seance($s)->whereRaw(
                    'DATE_ADD(sessions.start_at, INTERVAL sessions.duration_min + ? MINUTE) >= ?',
                    [self::DAYS_AFTER_SESSION * 1440, now()]
                ))
                ->orWhere(fn ($q) => $q->whereNotExists($seance)->whereRaw("$creneauFige IS NOT NULL")
                    ->whereRaw("STR_TO_DATE(LEFT($creneauFige, 19), '%Y-%m-%dT%H:%i:%s') >= ?", [now()->subDays(self::DAYS_AFTER_SESSION)]))
                ->orWhere(fn ($q) => $q->whereNotExists($seance)->whereRaw("$creneauFige IS NULL")
                    ->where('created_at', '>=', now()->subDays(self::DAYS_WITHOUT_SESSION)))
            );
    }

    /** @var array<int,int> cache par requête du compteur de non-lus (badge rendu 2×/page : sidebar + cloche mobile). */
    private static array $unreadCache = [];

    /** Nombre d'alertes non lues (badge cloche + nav). Mémoïsé par requête (cf. ClubSettings::current). */
    public static function unreadCountFor(int $userId): int
    {
        return self::$unreadCache[$userId] ??= self::alertsFor($userId)->whereNull('read_at')->count();
    }

    /** Invalide le cache après un marquage lu en masse (page Alertes) pour éviter un badge périmé. */
    public static function forgetUnreadCount(?int $userId = null): void
    {
        if ($userId === null) {
            self::$unreadCache = [];
        } else {
            unset(self::$unreadCache[$userId]);
        }
    }
}
