<?php

namespace App\Models;

use App\Support\Allures\Referentiel;
use Illuminate\Database\Eloquent\Model;

// Catalogue Discipline (PRD §5.1, §4.6.1).
class Discipline extends Model
{
    protected $fillable = ['label', 'referentiel', 'sort_order', 'archived_at'];

    /** @var array<string, string> */
    protected $casts = ['archived_at' => 'datetime'];

    /**
     * Référentiel d'allures (#114) : quelle grille s'applique aux séances de cette discipline.
     * Colonne laissée en chaîne (et non castée) pour que le formulaire de catalogue la lie telle quelle.
     */
    public function referentielEnum(): ?Referentiel
    {
        return Referentiel::tryFrom((string) $this->referentiel);
    }

    /**
     * Classe couleur du design (liseré scard, dot) dérivée du label.
     * Natation=swim (bleu Loire), Vélo=bike (vert), Course=run (hibiscus), autres=prep.
     */
    public function colorClass(): string
    {
        $l = mb_strtolower($this->label);

        return match (true) {
            str_contains($l, 'natation') => 'swim',
            str_contains($l, 'vélo'), str_contains($l, 'velo'), str_contains($l, 'cyclisme') => 'bike',
            str_contains($l, 'course'), str_contains($l, 'cap'), str_contains($l, 'trail') => 'run',
            default => 'prep',
        };
    }

    /** Icône Lucide (composant x-icon) dérivée du label. */
    public function icon(): string
    {
        return match ($this->colorClass()) {
            'swim' => 'waves',
            'bike' => 'bike',
            'run' => 'footprints',
            default => 'calendar',
        };
    }
}
