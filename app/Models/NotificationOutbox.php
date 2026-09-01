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
     */
    public const VOLATILE_PAYLOAD_KEYS = ['subject_first_name'];

    protected $fillable = [
        'type', 'channel', 'payload', 'user_id', 'status', 'attempts', 'available_at', 'sent_at', 'read_at',
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
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alertes visibles par l'utilisateur sur la page Alertes (push envoyés, 60 j) —
     * base commune du listing, du badge de non-lus et du marquage lu (revue UX 2026-07-11).
     *
     * @return Builder<self>
     */
    public static function alertsFor(int $userId): Builder
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('status', 'sent')
            ->where('channel', 'push')
            ->where('created_at', '>=', now()->subDays(60));
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
