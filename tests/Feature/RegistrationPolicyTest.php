<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\EnrollableCategory;
use Tests\TestCase;

// Accès aux inscriptions (PRD §4.9 + garde catégorielle §4.5 + invariant parent → Registration
// enfant, ROADMAP_DEV §26).
class RegistrationPolicyTest extends TestCase
{
    use EnrollableCategory;
    use RefreshDatabase;

    /** Séance ciblant la catégorie ouverte : inscriptible par un athlete() du trait. */
    private function makeSession(): Session
    {
        return $this->targetCategory(Session::create([
            'kind' => 'training', 'title' => 'Natation',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0),
            'duration_min' => 60,
            'created_by' => User::factory()->coach()->create()->id,
        ]));
    }

    public function test_member_can_enroll_self(): void
    {
        $u = $this->athlete();

        $this->assertTrue($u->can('enroll', $this->makeSession()));
        $this->assertTrue($u->can('unenroll', $this->makeSession()));
    }

    public function test_suspended_cannot_self_enroll(): void
    {
        $u = $this->athlete(['athlete_access_suspended' => true]);

        $this->assertFalse($u->can('enroll', $this->makeSession()));
        // La désinscription reste possible (sortir d'une séance).
        $this->assertTrue($u->can('unenroll', $this->makeSession()));
    }

    public function test_guardian_can_enroll_ward(): void
    {
        $parent = User::factory()->create();
        $child = $this->categorize(User::factory()->create(['guardian_id' => $parent->id]));
        $s = $this->makeSession();

        $this->assertTrue($parent->can('enroll', [$s, $child]));
        $this->assertTrue($parent->can('unenroll', [$s, $child]));
    }

    public function test_non_guardian_cannot_enroll_other(): void
    {
        $stranger = User::factory()->create();
        $other = $this->athlete();

        $this->assertFalse($stranger->can('enroll', [$this->makeSession(), $other]));
        $this->assertFalse($stranger->can('unenroll', [$this->makeSession(), $other]));
    }

    public function test_guardian_cannot_enroll_suspended_ward(): void
    {
        $parent = User::factory()->create();
        $child = $this->categorize(User::factory()->create([
            'guardian_id' => $parent->id, 'athlete_access_suspended' => true,
        ]));

        $this->assertFalse($parent->can('enroll', [$this->makeSession(), $child]));
    }

    // ── Garde catégorielle (§4.5, défense en profondeur) ──

    public function test_athlete_without_active_category_cannot_enroll(): void
    {
        // §4.5 l.271 : « athlète sans catégorie active … ne peut s'inscrire à aucune ».
        $u = User::factory()->create(); // aucune catégorie rattachée

        $this->assertFalse($u->can('enroll', $this->makeSession()));
    }

    public function test_athlete_with_only_archived_category_cannot_enroll(): void
    {
        // Une catégorie archivée ne compte pas comme « active ».
        $archived = Category::create([
            'label' => 'Poussins', 'age_min' => 8, 'age_max' => 9, 'sort_order' => 1,
            'archived_at' => Carbon::now(),
        ]);
        $u = User::factory()->create();
        $u->categories()->attach($archived->id, ['is_primary' => true]);
        // La séance cible cette catégorie archivée, mais l'athlète n'a aucune catégorie active.
        $s = Session::create([
            'kind' => 'training', 'title' => 'Natation',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0), 'duration_min' => 60,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
        $s->categories()->attach($archived->id);

        $this->assertFalse($u->can('enroll', $s));
    }

    public function test_athlete_cannot_enroll_session_outside_his_categories(): void
    {
        // L'athlète a une catégorie active, mais la séance en cible une autre.
        $other = Category::create(['label' => 'Minimes', 'age_min' => 13, 'age_max' => 14, 'sort_order' => 2]);
        $u = $this->athlete(); // catégorie « ouverte »
        $s = Session::create([
            'kind' => 'training', 'title' => 'Natation',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0), 'duration_min' => 60,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
        $s->categories()->attach($other->id);

        $this->assertFalse($u->can('enroll', $s));
    }

    public function test_untargeted_session_is_open_to_any_categorized_athlete(): void
    {
        // Séance sans aucune catégorie ciblée = ouverte à toutes les catégories (§4.5 défaut).
        $u = $this->athlete();
        $s = Session::create([
            'kind' => 'training', 'title' => 'Séance ouverte',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0), 'duration_min' => 60,
            'created_by' => User::factory()->coach()->create()->id,
        ]);

        $this->assertTrue($u->can('enroll', $s));
    }

    public function test_untargeted_session_still_blocked_without_active_category(): void
    {
        // Même ouverte à toutes les catégories, une séance reste interdite à l'athlète sans
        // catégorie active (§4.5 l.271 : « ne peut s'inscrire à aucune »).
        $u = User::factory()->create();
        $s = Session::create([
            'kind' => 'training', 'title' => 'Séance ouverte',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0), 'duration_min' => 60,
            'created_by' => User::factory()->coach()->create()->id,
        ]);

        $this->assertFalse($u->can('enroll', $s));
    }

    public function test_already_enrolled_athlete_is_grandfathered(): void
    {
        // §4.5 l.262 : une inscription active existante échappe à la garde (séance dé-ciblée depuis,
        // désinscription/re-inscription). Ici l'athlète est inscrit sur une séance hors catégorie.
        $other = Category::create(['label' => 'Minimes', 'age_min' => 13, 'age_max' => 14, 'sort_order' => 2]);
        $u = $this->athlete();
        $s = Session::create([
            'kind' => 'training', 'title' => 'Natation',
            'start_at' => Carbon::now()->addDays(2)->setTime(19, 0), 'duration_min' => 60,
            'created_by' => User::factory()->coach()->create()->id,
        ]);
        $s->categories()->attach($other->id);
        Registration::create([
            'session_id' => $s->id, 'user_id' => $u->id,
            'status' => 'participating', 'registered_at' => Carbon::now(),
        ]);

        $this->assertTrue($u->can('enroll', $s->fresh()->load('registrations')));
    }
}
