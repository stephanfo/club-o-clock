<?php

namespace App\Support\Trombi;

use App\Models\User;
use App\Policies\TrombiPolicy;
use App\Support\QualificationDisplay;
use Illuminate\Support\Carbon;

/**
 * Ce qu'un lecteur voit de la carte d'un adhérent (PRD §4.23). Construite côté serveur depuis
 * TrombiPolicy : une donnée qui ne doit pas sortir n'entre pas dans la carte, la vue n'a rien à
 * masquer. Relations à charger d'avance : self::EAGER.
 */
final class TrombiCard
{
    /** @var list<string> */
    public const EAGER = ['unrevokedConsents', 'categories', 'qualifications', 'guardian.unrevokedConsents'];

    /**
     * @param  list<string>  $roles
     * @param  list<array{label:string,cls:string,title:?string}>  $qualifications
     * @param  array{name:string,email:?string,phone:?string}|null  $parent
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $self,
        public readonly ?string $photo192,
        public readonly ?string $photo512,
        public readonly array $roles,
        public readonly ?string $board,
        public readonly ?string $category,
        public readonly array $qualifications,
        public readonly ?Carbon $birthday,
        public readonly ?string $email,
        public readonly ?string $phone,
        public readonly ?array $parent,
    ) {}

    /** Prénom et nom, ou prénom et initiale du nom (§4.23.1). */
    public static function name(User $subject, TrombiPolicy $policy): string
    {
        if ($policy->fullName($subject)) {
            return $subject->fullName();
        }
        $last = (string) $subject->last_name;

        return trim($subject->first_name.' '.($last === '' ? '' : mb_substr($last, 0, 1).'.'));
    }

    /** URL d'une taille de la photo, si le lecteur peut la voir. Le nom du fichier change à chaque dépôt. */
    public static function photoUrl(User $viewer, User $subject, int $size, TrombiPolicy $policy): ?string
    {
        if (! $policy->photo($viewer, $subject)) {
            return null;
        }

        return route('trombi.photo', ['user' => $subject->id, 'size' => $size, 'v' => substr(md5((string) $subject->photo_path), 0, 10)]);
    }

    /**
     * La carte telle qu'un adhérent majeur ordinaire la voit (aperçu du profil) : ni coach ni
     * admin, donc sans la catégorie non consentie ; ni l'intéressé, donc sans la photo masquée.
     */
    public static function asSeenByMembers(User $subject): self
    {
        $member = new User(['roles' => ['athlete'], 'is_active' => true, 'athlete_access_suspended' => false]);
        $member->id = 0;

        return self::make($member, $subject, new TrombiPolicy);
    }

    public static function make(User $viewer, User $subject, TrombiPolicy $policy): self
    {
        $roles = array_values(array_filter([
            $subject->hasRole('coach') ? 'Coach' : null,
            $subject->hasRole('admin') ? 'Admin' : null,
        ]));

        return new self(
            id: $subject->id,
            name: self::name($subject, $policy),
            self: $viewer->id === $subject->id,
            photo192: self::photoUrl($viewer, $subject, 192, $policy),
            photo512: self::photoUrl($viewer, $subject, 512, $policy),
            roles: $roles,
            board: $subject->board_role?->label(),
            category: $policy->category($viewer, $subject) ? $subject->primaryCategory()?->label : null,
            qualifications: $subject->hasRole('coach') ? self::qualifications($subject) : [],
            birthday: $policy->birthday($subject) ? Carbon::instance($subject->dob) : null,
            email: $policy->email($viewer, $subject) ? $subject->email : null,
            phone: $policy->phone($viewer, $subject) ? $subject->phone : null,
            parent: self::parent($viewer, $subject, $policy),
        );
    }

    /** Qualifications en cours de validité, avec le badge d'expiration de la fiche séance (§4.11.4). */
    private static function qualifications(User $subject): array
    {
        $out = [];
        foreach ($subject->qualifications->sortBy('label') as $q) {
            // Le pivot est une relation posée par BelongsToMany, pas une propriété déclarée du modèle.
            $pivot = $q->getRelation('pivot');
            $expires = $pivot->expires_at ? Carbon::parse($pivot->expires_at) : null;
            $status = QualificationDisplay::status($expires);
            if ($status['status'] === 'expired') {
                continue;
            }
            $out[] = [
                'label' => (string) $q->label,
                'cls' => $status['cls'],
                'title' => $expires ? 'Valide jusqu\'au '.$expires->locale('fr')->isoFormat('D MMMM YYYY') : null,
            ];
        }

        return $out;
    }

    /**
     * « Parent : X » sur la fiche d'un mineur, avec les seules coordonnées que le parent a lui-même
     * partagées (§4.23.4). Rien partagé : le lien de tutelle n'apparaît pas.
     */
    private static function parent(User $viewer, User $subject, TrombiPolicy $policy): ?array
    {
        $guardian = $subject->isLegallyMinor() ? $subject->guardian : null;
        if ($guardian === null || $guardian->anonymized_at !== null) {
            return null;
        }
        $email = $policy->email($viewer, $guardian) ? $guardian->email : null;
        $phone = $policy->phone($viewer, $guardian) ? $guardian->phone : null;

        return $email === null && $phone === null
            ? null
            : ['name' => self::name($guardian, $policy), 'email' => $email, 'phone' => $phone];
    }
}
