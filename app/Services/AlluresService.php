<?php

namespace App\Services;

use App\Models\AllureLevel;
use App\Models\ClubSettings;
use App\Models\ReferenceValue;
use App\Models\User;
use App\Support\Allures\Calculateur;
use App\Support\Allures\Referentiel;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

// Allures course (#114) : valeur de référence du membre et calculs qui en dépendent.
// La valeur ne s'écrit que sur le compte de la personne connectée (appelant = auth()->user()) :
// ni coach ni admin n'y ont accès. Aucun chrono n'est jamais persisté (§3.2).
class AlluresService
{
    public const OUT_OF_BOUNDS = 'out_of_bounds';

    /** Pose (ou écrase) la valeur courante. Hors bornes de vraisemblance : refus. */
    public function setReference(User $user, Referentiel $referentiel, float $value, string $source, ?string $distance = null): ReferenceValue
    {
        [$min, $max] = $referentiel->bounds();
        $value = round($value, 1);
        if ($value < $min || $value > $max) {
            throw new InvalidArgumentException(self::OUT_OF_BOUNDS);
        }
        if (! in_array($source, [ReferenceValue::SOURCE_SAISIE, ReferenceValue::SOURCE_ESTIMATION], true)) {
            throw new InvalidArgumentException('source');
        }
        if ($distance !== null && ! array_key_exists($distance, $referentiel->distances())) {
            $distance = null;
        }

        $row = ReferenceValue::updateOrCreate(
            ['user_id' => $user->id, 'referentiel' => $referentiel->value],
            [
                'value' => $value,
                'source' => $source,
                'source_distance' => $source === ReferenceValue::SOURCE_ESTIMATION ? $distance : null,
                'measured_on' => Carbon::now(ClubSettings::current()->timezone)->toDateString(),
            ],
        );
        $user->unsetRelation('referenceValues');

        return $row;
    }

    public function clearReference(User $user, Referentiel $referentiel): void
    {
        $user->referenceValues()->where('referentiel', $referentiel->value)->delete();
        $user->unsetRelation('referenceValues');
    }

    /**
     * Estimation de la valeur de référence depuis une course : Riegel, ou la table club si elle
     * existe (avec le niveau choisi). Résultat en fourchette [basse, haute] (égales sous Riegel),
     * plus le drapeau `improbable` quand la fourchette sort des bornes de vraisemblance.
     *
     * @return array{low: float, high: float, mid: float, improbable: bool, speed: float}|null
     */
    public function estimate(Referentiel $referentiel, string $distance, float $seconds, ?AllureLevel $level): ?array
    {
        $meters = $referentiel->distances()[$distance][1] ?? null;
        if ($meters === null || $seconds <= 0) {
            return null;
        }

        if ($level !== null && ($target = $level->target($distance)) !== null) {
            [$low, $high] = Calculateur::vmaTable($meters, $seconds, $target[0], $target[1]);
        } else {
            $low = $high = Calculateur::vmaRiegel($meters, $seconds);
        }
        [$min, $max] = $referentiel->bounds();

        return [
            'low' => $low,
            'high' => $high,
            'mid' => ($low + $high) / 2,
            'improbable' => $low > $max || $high < $min,
            'speed' => $meters / $seconds * 3.6,
        ];
    }

    /**
     * Projection des temps de course pour une valeur de référence : [clé distance => [rapide, lent]]
     * (égaux sous Riegel). Avec une table club, une distance sans bornes pour ce niveau est omise.
     *
     * @return array<string, array{float, float}>
     */
    public function project(Referentiel $referentiel, float $value, ?AllureLevel $level): array
    {
        $out = [];
        foreach ($referentiel->distances() as $key => [, $meters]) {
            if ($level !== null) {
                $target = $level->target($key);
                if ($target !== null) {
                    $out[$key] = Calculateur::tempsTable($meters, $value, $target[0], $target[1]);
                }
            } else {
                $t = Calculateur::tempsRiegel($meters, $value);
                $out[$key] = [$t, $t];
            }
        }

        return $out;
    }
}
