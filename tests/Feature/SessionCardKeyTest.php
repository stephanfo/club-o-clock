<?php

namespace Tests\Feature;

use App\Livewire\GpxRouteShow;
use App\Livewire\Home;
use App\Livewire\ParentChildren;
use App\Livewire\Planning;
use App\Models\GpxRoute;
use App\Models\Registration;
use App\Models\Session;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Identité des cartes de séance pour le morphing Livewire (issue #50).
 *
 * Même famille de défaut que #32 (en-têtes de jour collants) : sans `wire:key`, le morphing
 * apparie les nœuds PAR POSITION. Au changement de semaine ou de filtre, il réécrit le contenu
 * d'une carte en place au lieu de détruire puis recréer le bloc — d'où des contenus croisés et,
 * sur WebKit, des éléments qui ne se recomposent pas.
 *
 * Deux pièges, tous deux vérifiés ici :
 *  - `:key` n'est PAS interprété par Livewire sur un composant Blade : il ressort en attribut
 *    `key="…"` inerte dans le HTML. Seul `wire:key` compte.
 *  - Les écrans rendent DEUX coquilles (mobile et desktop) dans le même arbre DOM : une clé non
 *    préfixée par sa coquille apparaît deux fois, ce qui est pire qu'absente.
 */
class SessionCardKeyTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> le HTML de chaque ancre de carte de séance. */
    private function cardTags(string $html): array
    {
        preg_match_all('/<a\b[^>]*\bclass="[^"]*\bscard\b[^"]*"[^>]*>/', $html, $m);

        return $m[0];
    }

    /** Toute carte rendue porte une clé, et aucune clé n'est portée deux fois dans l'arbre. */
    private function assertCardsUniquelyKeyed(string $html, int $atLeast, string $ecran): void
    {
        $cards = $this->cardTags($html);
        // Contrôle positif : sans cartes rendues, les assertions suivantes seraient vraies à vide.
        $this->assertGreaterThanOrEqual($atLeast, count($cards), "$ecran : trop peu de cartes rendues");

        foreach ($cards as $tag) {
            $this->assertStringContainsString('wire:key=', $tag, "$ecran : carte sans wire:key → ".$tag);
        }

        // `:key` inerte : Blade le recrache tel quel, Livewire ne le voit pas.
        $this->assertStringNotContainsString(' key="', $html, "$ecran : attribut key= inerte");

        preg_match_all('/wire:key="([^"]+)"/', $html, $k);
        $doublons = array_keys(array_filter(array_count_values($k[1]), fn ($n) => $n > 1));
        $this->assertSame([], $doublons, "$ecran : clés dupliquées → ".implode(', ', $doublons));
    }

    private function sessionOn(Carbon $at, string $title, array $extra = []): Session
    {
        return Session::create([
            'kind' => 'training', 'title' => $title,
            'start_at' => $at, 'duration_min' => 60,
            'created_by' => User::factory()->coach()->create()->id,
            ...$extra,
        ]);
    }

    /** Planning · Semaine : la grille desktop ET la liste mobile rendent les MÊMES séances. */
    public function test_week_view_keys_every_card_in_both_shells(): void
    {
        $monday = Carbon::now()->startOfWeek();
        $this->sessionOn($monday->copy()->setTime(18, 0), 'Fractionné');
        $this->sessionOn($monday->copy()->setTime(20, 0), 'Natation');

        $html = Livewire::actingAs(User::factory()->create())
            ->test(Planning::class)->set('view', 'week')->html();

        // 2 séances × 2 coquilles.
        $this->assertCardsUniquelyKeyed($html, 4, 'Planning semaine');
    }

    /** Planning · Mois : plan-body est inclus deux fois (scope dk et mo) → mini-cartes en double. */
    public function test_month_view_keys_every_pill_card(): void
    {
        $this->sessionOn(Carbon::now()->startOfMonth()->addDays(9)->setTime(18, 0), 'Fractionné');
        $this->sessionOn(Carbon::now()->startOfMonth()->addDays(9)->setTime(20, 0), 'Natation');

        $html = Livewire::actingAs(User::factory()->create())
            ->test(Planning::class)->set('view', 'month')->html();

        $this->assertCardsUniquelyKeyed($html, 2, 'Planning mois');
    }

    /** Accueil : « Mes prochaines séances » est rendu dans les deux coquilles. */
    public function test_home_keys_every_upcoming_card(): void
    {
        $athlete = User::factory()->create();
        foreach ([1, 2] as $j) {
            $s = $this->sessionOn(Carbon::now()->addDays($j)->setTime(18, 30), 'Séance J+'.$j);
            Registration::create([
                'session_id' => $s->id, 'user_id' => $athlete->id,
                'status' => 'participating', 'registered_at' => Carbon::now(),
            ]);
        }

        $html = Livewire::actingAs($athlete)->test(Home::class)->html();

        $this->assertCardsUniquelyKeyed($html, 2, 'Accueil');
    }

    /** Mes enfants : child-card est inclus deux fois — ses clés existantes collisionnaient. */
    public function test_children_screen_keys_every_card(): void
    {
        $parent = User::factory()->create();
        $ward = User::factory()->create([
            'dob' => now()->subYears(12)->toDateString(), 'guardian_id' => $parent->id,
        ]);
        $s = $this->sessionOn(Carbon::now()->addDays(2)->setTime(18, 0), 'Natation jeunes');
        Registration::create([
            'session_id' => $s->id, 'user_id' => $ward->id,
            'status' => 'participating', 'registered_at' => Carbon::now(),
        ]);

        $html = Livewire::actingAs($parent)->test(ParentChildren::class)->html();

        $this->assertCardsUniquelyKeyed($html, 2, 'Mes enfants');
    }

    /** Fiche parcours : la liste des séances qui l'utilisent. */
    public function test_route_screen_keys_every_session_card(): void
    {
        $route = GpxRoute::factory()->create();
        foreach ([1, 2] as $j) {
            $this->sessionOn(Carbon::now()->addDays($j)->setTime(18, 0), 'Sortie J+'.$j, ['route_id' => $route->id]);
        }

        $html = Livewire::actingAs(User::factory()->create())
            ->test(GpxRouteShow::class, ['gpxRoute' => $route])->html();

        $this->assertCardsUniquelyKeyed($html, 2, 'Fiche parcours');
    }
}
