<?php

namespace Tests\Feature;

use App\Livewire\Home;
use App\Livewire\Profil;
use App\Models\ClubSettings;
use App\Models\QuotaTag;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Compteurs de quota (§4.10) : affichés sur l'accueil seulement — l'onglet Quotas du profil a été
 * retiré pour faire tenir les onglets sur iPhone. Le bloc reprend ce que l'onglet portait en plus :
 * séances comptées et alerte « quota atteint ».
 */
class HomeQuotasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Milieu de semaine club gelé : lancé un dimanche soir, « now + 2h » basculerait la séance
        // sur la semaine locale suivante — hors de l'usage hebdo affiché (test date-dépendant).
        $this->travelTo(Carbon::now(ClubSettings::current()->timezone)
            ->startOfWeek(Carbon::MONDAY)->addDays(2)->setTime(12, 0));
    }

    private function inscrit(User $u, QuotaTag $tag, string $title): void
    {
        $session = Session::create([
            'kind' => 'training', 'title' => $title,
            'start_at' => Carbon::now()->addHours(2), 'duration_min' => 60,
            'quota_tag_id' => $tag->id, 'created_by' => User::factory()->coach()->create()->id,
        ]);
        Registration::create([
            'session_id' => $session->id, 'user_id' => $u->id,
            'status' => 'participating', 'registered_at' => Carbon::now(),
        ]);
    }

    public function test_home_lists_counted_sessions_and_warns_when_quota_is_reached(): void
    {
        $u = User::factory()->create();
        $tag = QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 1]);
        $this->inscrit($u, $tag, 'Natation seuil');

        Livewire::actingAs($u)->test(Home::class)
            ->assertSee('Mes quotas · cette semaine')
            ->assertSee('Natation seuil')
            ->assertSee('Quota atteint');
    }

    public function test_no_warning_while_under_quota(): void
    {
        $u = User::factory()->create();
        $tag = QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 2]);
        $this->inscrit($u, $tag, 'Natation seuil');

        Livewire::actingAs($u)->test(Home::class)
            ->assertSee('Natation seuil') // contrôle positif : le bloc est bien rendu
            ->assertDontSee('Quota atteint');
    }

    public function test_profil_no_longer_has_a_quotas_tab(): void
    {
        $u = User::factory()->create();
        $tag = QuotaTag::create(['code' => 'piscine', 'label' => 'Piscine', 'max_per_week' => 2]);
        $this->inscrit($u, $tag, 'Natation seuil');

        // Un ancien lien ?tab=quotas retombe sur l'onglet Identité, sans compteur.
        Livewire::actingAs($u)->test(Profil::class)
            ->set('tab', 'quotas')
            ->assertSee('Identité')
            ->assertDontSee('Natation seuil');
    }
}
