<?php

namespace App\Livewire;

use App\Models\User;
use App\Policies\TrombiPolicy;
use App\Support\Trombi\BoardRole;
use App\Support\Trombi\TrombiCard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

// Trombinoscope (#72, PRD §4.23.6) : grille de cartes des adhérents de la saison, recherche,
// filtres Tous / Bureau / Coachs et par catégorie, vue Anniversaires par mois. Tout ce que voit le
// lecteur sort de TrombiCard — y compris ce sur quoi il peut chercher et filtrer : chercher un nom
// de famille non partagé, ou filtrer sur une catégorie masquée, révélerait ce que la carte cache.
#[Layout('layouts.app')]
#[Title('Trombinoscope')]
class Trombinoscope extends Component
{
    public const FILTERS = ['tous' => 'Tous', 'bureau' => 'Bureau', 'coachs' => 'Coachs'];

    #[Url]
    public string $q = '';

    #[Url]
    public string $filtre = 'tous';

    #[Url]
    public string $categorie = '';

    /** cartes | anniversaires */
    #[Url]
    public string $vue = 'cartes';

    /** Mois de la vue Anniversaires, 1-12 ; 0 = mois courant. */
    #[Url]
    public int $mois = 0;

    public function mount(TrombiPolicy $policy): void
    {
        abort_unless($policy->consult(auth()->user()), 403);
    }

    public function setFilter(string $filtre): void
    {
        $this->filtre = array_key_exists($filtre, self::FILTERS) ? $filtre : 'tous';
    }

    public function setCategory(string $label): void
    {
        $this->categorie = $this->categorie === $label ? '' : $label;
    }

    public function setView(string $vue): void
    {
        $this->vue = $vue === 'anniversaires' ? 'anniversaires' : 'cartes';
    }

    public function shiftMonth(int $delta): void
    {
        $this->mois = (($this->month() - 1 + $delta) % 12 + 12) % 12 + 1;
    }

    public function month(): int
    {
        return $this->mois >= 1 && $this->mois <= 12 ? $this->mois : Carbon::now()->month;
    }

    /** @return Collection<int, TrombiCard> */
    private function cards(TrombiPolicy $policy): Collection
    {
        $viewer = auth()->user();

        return User::query()->auTrombinoscope()
            ->with(TrombiCard::EAGER)
            ->orderBy('first_name')->orderBy('last_name')
            ->get()
            ->map(fn (User $u) => TrombiCard::make($viewer, $u, $policy))
            ->sortBy(fn (TrombiCard $c) => Str::ascii(mb_strtolower($c->name)))
            ->values();
    }

    public function render(TrombiPolicy $policy)
    {
        $all = $this->cards($policy);
        $categories = $all->pluck('category')->filter()->unique()->sort()->values();

        $boardOrder = array_map(fn (BoardRole $r) => $r->label(), BoardRole::cases());
        $needle = Str::ascii(mb_strtolower(trim($this->q)));
        $shown = $all
            ->when($needle !== '', fn ($c) => $c->filter(fn (TrombiCard $card) => str_contains(Str::ascii(mb_strtolower($card->name)), $needle)))
            ->when($this->filtre === 'bureau', fn ($c) => $c->filter(fn (TrombiCard $card) => $card->board !== null))
            ->when($this->filtre === 'coachs', fn ($c) => $c->filter(fn (TrombiCard $card) => in_array('Coach', $card->roles, true)))
            ->when($this->categorie !== '', fn ($c) => $c->filter(fn (TrombiCard $card) => $card->category === $this->categorie))
            // Bureau : dans l'ordre des fonctions (présidence d'abord), pas des prénoms.
            ->when($this->filtre === 'bureau', fn ($c) => $c->sortBy(fn (TrombiCard $card) => array_search($card->board, $boardOrder, true)))
            ->values();

        $month = $this->month();
        $birthdays = $shown
            ->filter(fn (TrombiCard $card) => $card->birthday?->month === $month)
            ->sortBy(fn (TrombiCard $card) => $card->birthday->day)
            ->values();

        return view('livewire.trombinoscope', [
            'cards' => $shown,
            'total' => $all->count(),
            'categories' => $categories,
            'birthdays' => $birthdays,
            'monthLabel' => ucfirst(Carbon::create(2000, $month, 1)->locale('fr')->isoFormat('MMMM')),
        ]);
    }
}
