<?php

namespace App\Support\Trombi;

/**
 * Fonction au bureau (PRD §4.23.3) : liste fixe, une au plus par personne, plusieurs titulaires
 * possibles. Affichée sans accord — elle relève du fonctionnement de l'association.
 */
enum BoardRole: string
{
    case President = 'president';
    case VicePresident = 'vice_president';
    case Secretary = 'secretary';
    case DeputySecretary = 'deputy_secretary';
    case Treasurer = 'treasurer';
    case DeputyTreasurer = 'deputy_treasurer';
    // Élu·e sans fonction nommée. En dernier : l'ordre des cas est celui du filtre « Bureau ».
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::President => 'Président·e',
            self::VicePresident => 'Vice-président·e',
            self::Secretary => 'Secrétaire',
            self::DeputySecretary => 'Secrétaire adjoint·e',
            self::Treasurer => 'Trésorier·e',
            self::DeputyTreasurer => 'Trésorier·e adjoint·e',
            self::Member => 'Membre du bureau',
        };
    }
}
