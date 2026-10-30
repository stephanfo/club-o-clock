<?php

namespace App\Support\Trombi;

/**
 * Accords de diffusion au trombinoscope (PRD §4.23.1). Un accord n'est ACTIF que s'il porte la
 * version courante de son type : changer le texte présenté de façon substantielle, c'est
 * incrémenter la constante ET lancer `club:trombi-revoquer {type}` (cadrage §7.18).
 */
enum ConsentType: string
{
    case FullName = 'trombi_full_name';
    case Photo = 'trombi_photo';
    case Category = 'trombi_category';
    case Birthday = 'trombi_birthday';
    case Email = 'trombi_email';
    case Phone = 'trombi_phone';

    /** Version courante du texte présenté, par type. */
    public function version(): int
    {
        return match ($this) {
            self::FullName, self::Photo, self::Category, self::Birthday, self::Email, self::Phone => 1,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::FullName => 'Nom complet',
            self::Photo => 'Photo',
            self::Category => 'Catégorie',
            self::Birthday => 'Anniversaire',
            self::Email => 'Email',
            self::Phone => 'Téléphone',
        };
    }

    /** Le texte de l'accord, tel que présenté (sa version est celle de version()). */
    public function description(): string
    {
        return match ($this) {
            self::FullName => 'Mon prénom et mon nom en entier, au lieu de l\'initiale du nom.',
            self::Photo => 'Ma photo sur ma carte et dans les listes d\'inscrits des séances.',
            self::Category => 'Ma catégorie d\'âge (elle situe l\'âge à deux ans près).',
            self::Birthday => 'Le jour et le mois de mon anniversaire, jamais l\'année ni l\'âge.',
            self::Email => 'L\'email de mon compte, pour que les adhérents puissent m\'écrire.',
            self::Phone => 'Mon numéro, pour que les adhérents puissent m\'appeler (covoiturage, logistique).',
        };
    }

    /** Coordonnées : jamais pour un mineur, jamais montrées à un lecteur mineur (§4.23.4, §4.23.5). */
    public function isContact(): bool
    {
        return $this === self::Email || $this === self::Phone;
    }

    /**
     * Types qu'un mineur peut recevoir (par son parent garant).
     *
     * @return list<self>
     */
    public static function forMinor(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => ! $t->isContact()));
    }
}
