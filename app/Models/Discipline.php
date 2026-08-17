<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Catalogue Discipline (PRD §5.1, §4.6.1).
class Discipline extends Model
{
    protected $fillable = ['label', 'sort_order', 'archived_at'];

    /** @var array<string, string> */
    protected $casts = ['archived_at' => 'datetime'];

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
