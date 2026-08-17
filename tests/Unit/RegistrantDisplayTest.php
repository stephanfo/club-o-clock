<?php

namespace Tests\Unit;

use App\Models\User;
use App\Support\RegistrantDisplay;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

// Affichage RGPD des noms d'inscrits (PRD §4.9.4).
class RegistrantDisplayTest extends TestCase
{
    private function user(int $id, string $first, string $last): User
    {
        $u = new User(['first_name' => $first, 'last_name' => $last]);
        $u->id = $id;

        return $u;
    }

    public function test_full_names_for_coach_view(): void
    {
        $users = new Collection([$this->user(1, 'Marc', 'Sapin')]);

        $labels = RegistrantDisplay::labels($users, fullNames: true);

        $this->assertSame('Marc Sapin', $labels[1]);
    }

    public function test_first_name_plus_initial_between_athletes(): void
    {
        $users = new Collection([$this->user(1, 'Léa', 'Petit')]);

        $labels = RegistrantDisplay::labels($users, fullNames: false);

        $this->assertSame('Léa P.', $labels[1]);
    }

    public function test_extends_initial_on_homonym(): void
    {
        $users = new Collection([
            $this->user(1, 'Marc', 'Sapin'),
            $this->user(2, 'Marc', 'Simon'),
        ]);

        $labels = RegistrantDisplay::labels($users, fullNames: false);

        // « Sa. » vs « Si. » : extension minimale pour différencier.
        $this->assertSame('Marc Sa.', $labels[1]);
        $this->assertSame('Marc Si.', $labels[2]);
    }

    public function test_distinct_first_names_keep_single_initial(): void
    {
        $users = new Collection([
            $this->user(1, 'Marc', 'Sapin'),
            $this->user(2, 'Léa', 'Sirot'),
        ]);

        $labels = RegistrantDisplay::labels($users, fullNames: false);

        $this->assertSame('Marc S.', $labels[1]);
        $this->assertSame('Léa S.', $labels[2]);
    }
}
