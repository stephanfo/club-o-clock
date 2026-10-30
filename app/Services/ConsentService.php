<?php

namespace App\Services;

use App\Models\Consent;
use App\Models\User;
use App\Support\Logging\AuditLogger;
use App\Support\Trombi\ConsentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Accords de diffusion au trombinoscope (PRD §4.23.1, cadrage §7.18). Seul point d'écriture du
// registre `consents`, en AJOUT SEUL : accorder crée une ligne, retirer pose `revoked_at` sur la
// ligne active. Le geste de l'admin passe par les mêmes écritures que celui de l'adhérent, plus
// l'AuditLog. Un retrait ne touche jamais au stockage (photo, téléphone) : il masque.
class ConsentService
{
    /**
     * $actor peut-il ACCORDER pour $subject dans l'app ? L'intéressé majeur pour lui-même ; pour un
     * mineur, son parent garant seul (§4.23.4) — un mineur sans garant ne s'accorde rien lui-même,
     * seul l'admin enregistre l'accord signé sur le bulletin (recordByAdmin).
     */
    public function canGrant(User $actor, User $subject): bool
    {
        if ($subject->anonymized_at !== null) {
            return false;
        }

        return $subject->isLegallyMinor()
            ? $subject->guardian_id !== null && $actor->id === $subject->guardian_id
            : $actor->id === $subject->id;
    }

    /** $actor peut-il RETIRER ? Qui peut accorder, plus le mineur lui-même : un retrait l'emporte. */
    public function canRevoke(User $actor, User $subject): bool
    {
        return $this->canGrant($actor, $subject)
            || ($subject->anonymized_at === null && $actor->id === $subject->id);
    }

    /**
     * Types proposés à $subject : jamais de coordonnées pour un mineur (§4.23.4).
     *
     * @return list<ConsentType>
     */
    public function applicableTypes(User $subject): array
    {
        return $subject->isLegallyMinor() ? ConsentType::forMinor() : ConsentType::cases();
    }

    public function grant(User $subject, ConsentType $type, User $actor): void
    {
        if (! $this->canGrant($actor, $subject)) {
            throw new RuntimeException('Tu ne peux pas donner cet accord.');
        }

        DB::transaction(fn () => $this->write($this->lock($subject), $type, $actor, null, Carbon::now()));
        $subject->unsetRelation('unrevokedConsents');
    }

    public function revoke(User $subject, ConsentType $type, User $actor): void
    {
        if (! $this->canRevoke($actor, $subject)) {
            throw new RuntimeException('Tu ne peux pas retirer cet accord.');
        }

        DB::transaction(fn () => $this->close($this->lock($subject), [$type], $actor, Carbon::now()));
        $subject->unsetRelation('unrevokedConsents');
    }

    /**
     * Interrupteur « Tout » (§4.23.1). Pas de type propre : une ligne par accord, au même instant,
     * pour que la preuve reste par donnée. Allumer ignore ce qui ne s'applique pas — le téléphone
     * sans numéro saisi reste éteint (l'écran invite à le saisir).
     *
     * @return list<ConsentType> les types effectivement changés
     */
    public function setAll(User $subject, bool $on, User $actor): array
    {
        if (! ($on ? $this->canGrant($actor, $subject) : $this->canRevoke($actor, $subject))) {
            throw new RuntimeException($on ? 'Tu ne peux pas donner ces accords.' : 'Tu ne peux pas retirer ces accords.');
        }

        $changed = DB::transaction(function () use ($subject, $on, $actor) {
            $locked = $this->lock($subject);
            $at = Carbon::now();

            if (! $on) {
                return $this->close($locked, ConsentType::cases(), $actor, $at);
            }

            $changed = [];
            foreach ($this->grantableTypes($locked) as $type) {
                if ($this->write($locked, $type, $actor, null, $at)) {
                    $changed[] = $type;
                }
            }

            return $changed;
        });
        $subject->unsetRelation('unrevokedConsents');

        return $changed;
    }

    /**
     * L'admin enregistre un accord recueilli hors de l'app (§4.23.1), source obligatoire. Pour un
     * mineur, c'est l'accord d'un parent : les coordonnées restent refusées, comme dans l'app.
     *
     * @param  list<ConsentType>  $types
     * @return list<ConsentType> les types effectivement enregistrés (déjà actifs exclus)
     */
    public function recordByAdmin(User $subject, array $types, User $admin, string $source): array
    {
        $source = trim($source);
        if (! $admin->isAdmin()) {
            throw new RuntimeException('Réservé à l\'admin.');
        }
        if ($source === '' || mb_strlen($source) > 120) {
            throw new RuntimeException('Indique d\'où vient l\'accord (120 caractères au plus).');
        }
        if ($subject->anonymized_at !== null) {
            throw new RuntimeException('Ce compte a été anonymisé.');
        }
        if ($types === []) {
            throw new RuntimeException('Choisis au moins un accord.');
        }

        $recorded = DB::transaction(function () use ($subject, $types, $admin, $source) {
            $locked = $this->lock($subject);
            $at = Carbon::now();

            $recorded = [];
            foreach ($types as $type) {
                if ($this->write($locked, $type, $admin, $source, $at)) {
                    $recorded[] = $type;
                }
            }

            if ($recorded !== []) {
                AuditLogger::record('consent_recorded_by_admin', $admin, [
                    'target_type' => User::class,
                    'target_id' => $subject->id,
                    'motif' => mb_substr(self::typeList($recorded).' — '.$source, 0, 255),
                ]);
            }

            return $recorded;
        });
        $subject->unsetRelation('unrevokedConsents');

        return $recorded;
    }

    /** L'admin retire un accord à la demande de l'adhérent, quel que soit le canal (§4.23.1). */
    public function revokeByAdmin(User $subject, ConsentType $type, User $admin): void
    {
        if (! $admin->isAdmin()) {
            throw new RuntimeException('Réservé à l\'admin.');
        }

        DB::transaction(function () use ($subject, $type, $admin) {
            if ($this->close($this->lock($subject), [$type], $admin, Carbon::now()) !== []) {
                AuditLogger::record('consent_revoked_by_admin', $admin, [
                    'target_type' => User::class,
                    'target_id' => $subject->id,
                    'motif' => $type->value,
                ]);
            }
        });
        $subject->unsetRelation('unrevokedConsents');
    }

    /**
     * Rupture ou changement de garant (§4.23.4) : tombent les accords qui ne viennent pas de
     * l'intéressé lui-même — donnés par le parent, ou enregistrés par l'admin sur bulletin. À
     * appeler DANS la transaction du geste de tutelle.
     */
    public function revokeGrantedByOthers(User $subject, ?User $actor): int
    {
        return $this->closeWhere($subject, $actor, fn ($q) => $q->where(
            fn ($w) => $w->whereNull('granted_by')->orWhere('granted_by', '!=', $subject->id)
        ));
    }

    /** Retrait d'un type sans trace propre : le geste appelant (modération d'une photo) a la sienne. */
    public function revokeType(User $subject, ConsentType $type, ?User $actor): int
    {
        return $this->closeWhere($subject, $actor, fn ($q) => $q->where('type', $type->value));
    }

    /** Tous les accords de $subject — purge de bascule de saison (§4.4). */
    public function revokeAll(User $subject, ?User $actor): int
    {
        return $this->closeWhere($subject, $actor, fn ($q) => $q);
    }

    /**
     * `club:trombi-revoquer {type}` : révoque les accords d'une version antérieure du texte. Les
     * données restent, masquées, au profil de l'intéressé.
     */
    public function revokeOutdated(ConsentType $type): int
    {
        return Consent::query()
            ->whereNull('revoked_at')
            ->where('type', $type->value)
            ->where('version', '!=', $type->version())
            ->update(['revoked_at' => Carbon::now(), 'revoked_by' => null]);
    }

    /**
     * Types que « Tout » allume pour $subject.
     *
     * @return list<ConsentType>
     */
    public function grantableTypes(User $subject): array
    {
        return array_values(array_filter(
            $this->applicableTypes($subject),
            fn (ConsentType $t) => $t !== ConsentType::Phone || $subject->phone !== null,
        ));
    }

    /** @param  list<ConsentType>  $types */
    public static function typeList(array $types): string
    {
        return implode(', ', array_map(fn (ConsentType $t) => $t->value, $types));
    }

    /**
     * Relit l'adhérent sous verrou : deux onglets qui accordent en même temps ne doivent pas
     * laisser deux lignes actives, et les gardes jugent l'état réel (âge, téléphone).
     */
    private function lock(User $subject): User
    {
        return User::query()->lockForUpdate()->findOrFail($subject->getKey());
    }

    /** Crée la ligne active si besoin. @return bool true si une ligne a été créée */
    private function write(User $subject, ConsentType $type, User $actor, ?string $source, Carbon $at): bool
    {
        if ($subject->anonymized_at !== null) {
            throw new RuntimeException('Ce compte a été anonymisé.');
        }
        if ($type->isContact() && $subject->isLegallyMinor()) {
            throw new RuntimeException('Un mineur ne partage ni email ni téléphone.');
        }
        if ($type === ConsentType::Phone && $subject->phone === null) {
            throw new RuntimeException('Saisis d\'abord le numéro à partager.');
        }

        $current = Consent::query()->unrevoked()->where('user_id', $subject->id)->where('type', $type->value)->get();
        if ($current->contains(fn (Consent $c) => $c->isActive())) {
            return false;
        }

        // Une ligne d'une version périmée encore ouverte se ferme : une seule ligne ouverte par type.
        if ($current->isNotEmpty()) {
            Consent::query()->whereKey($current->modelKeys())->update(['revoked_at' => $at, 'revoked_by' => $actor->id]);
        }

        Consent::create([
            'user_id' => $subject->id,
            'type' => $type,
            'version' => $type->version(),
            'granted_at' => $at,
            'granted_by' => $actor->id,
            'source' => $source,
        ]);

        return true;
    }

    /**
     * Ferme les lignes ouvertes des types donnés.
     *
     * @param  list<ConsentType>  $types
     * @return list<ConsentType> les types qui étaient actifs
     */
    private function close(User $subject, array $types, User $actor, Carbon $at): array
    {
        $open = Consent::query()->unrevoked()->where('user_id', $subject->id)
            ->whereIn('type', array_map(fn (ConsentType $t) => $t->value, $types))
            ->get();

        if ($open->isEmpty()) {
            return [];
        }

        Consent::query()->whereKey($open->modelKeys())->update(['revoked_at' => $at, 'revoked_by' => $actor->id]);

        return array_values($open->filter(fn (Consent $c) => $c->isActive())->map(fn (Consent $c) => $c->type)->unique()->all());
    }

    /** @param  callable(Builder<Consent>): mixed  $scope */
    private function closeWhere(User $subject, ?User $actor, callable $scope): int
    {
        $query = Consent::query()->unrevoked()->where('user_id', $subject->id);
        $scope($query);
        $count = $query->update(['revoked_at' => Carbon::now(), 'revoked_by' => $actor?->id]);
        $subject->unsetRelation('unrevokedConsents');

        return $count;
    }
}
