{{-- Trombinoscope (#72, PRD §4.23.6). Pas d'écran de référence dans design/ : structure reprise de
     la bibliothèque de parcours (topbar, carte de filtres, .seg, grille de .scard). --}}
<div class="form-screen">
    <x-flash-float />

    {{-- Mobile : atteint depuis l'accueil ou le profil (pas d'entrée dans la barre du bas). --}}
    <x-topbar title="Trombinoscope" :sub="$total.' adhérent'.($total > 1 ? 's' : '')"
              :back="route('dashboard')" back-label="Retour">
        <x-slot:trailing><x-alert-bell dark /></x-slot:trailing>
    </x-topbar>

    <div class="dk-topbar">
        <div class="f1">
            <div class="dsp" style="font-size:24px">Trombinoscope</div>
            <div class="meta">{{ $total }} adhérent{{ $total > 1 ? 's' : '' }} de la saison · chacun choisit ce qu'il partage</div>
        </div>
        <a href="{{ route('profil', ['tab' => 'trombi']) }}" wire:navigate class="btn btn-ghost btn-sm">
            <x-icon name="user" :size="15" /> Ma carte
        </a>
    </div>

    <div class="dk-body">
        <div class="card" style="padding:12px;margin-bottom:16px;display:flex;flex-direction:column;gap:10px">
            <div class="input flex ac g8">
                <x-icon name="search" :size="16" style="color:var(--fg-muted)" />
                <input type="text" wire:model.live.debounce.300ms="q" placeholder="Rechercher un nom…" aria-label="Rechercher un adhérent"
                       style="border:none;background:none;outline:none;font:inherit;color:inherit;width:100%" />
            </div>
            <div class="flex g6 wrap">
                @foreach (\App\Livewire\Trombinoscope::FILTERS as $key => $label)
                    <button type="button" wire:click="setFilter('{{ $key }}')" wire:loading.attr="disabled" wire:target="setFilter, setCategory"
                            class="chip{{ $filtre === $key ? ' is-active' : '' }}" aria-pressed="{{ $filtre === $key ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            @if ($categories->isNotEmpty())
                <div class="flex g6 wrap" aria-label="Catégories">
                    @foreach ($categories as $cat)
                        <button type="button" wire:click="setCategory(@js($cat))" wire:loading.attr="disabled" wire:target="setFilter, setCategory"
                                class="chip chip-sm{{ $categorie === $cat ? ' is-active' : '' }}" aria-pressed="{{ $categorie === $cat ? 'true' : 'false' }}">{{ $cat }}</button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="seg" style="margin-bottom:16px" role="tablist" aria-label="Affichage">
            <button type="button" role="tab" wire:click="setView('cartes')" class="seg-item{{ $vue === 'anniversaires' ? '' : ' on' }}"
                    aria-selected="{{ $vue === 'anniversaires' ? 'false' : 'true' }}">
                <x-icon name="users" :size="14" /> Cartes
            </button>
            <button type="button" role="tab" wire:click="setView('anniversaires')" class="seg-item{{ $vue === 'anniversaires' ? ' on' : '' }}"
                    aria-selected="{{ $vue === 'anniversaires' ? 'true' : 'false' }}">
                <x-icon name="calendar-days" :size="14" /> Anniversaires
            </button>
        </div>

        @if ($vue === 'anniversaires')
            <div class="card card-pad">
                <div class="flex ac jb" style="margin-bottom:12px">
                    <button type="button" class="iconbtn" wire:click="shiftMonth(-1)" wire:loading.attr="disabled" wire:target="shiftMonth" aria-label="Mois précédent"><x-icon name="chevron-left" /></button>
                    <div class="dsp" style="font-size:var(--text-lg)">{{ $monthLabel }}</div>
                    <button type="button" class="iconbtn" wire:click="shiftMonth(1)" wire:loading.attr="disabled" wire:target="shiftMonth" aria-label="Mois suivant"><x-icon name="chevron-right" /></button>
                </div>
                @forelse ($birthdays as $card)
                    <a href="{{ route('trombi.show', $card->id) }}" wire:navigate class="row row-press trombi-bday" wire:key="bday-{{ $card->id }}">
                        <span class="trombi-bday-day">{{ $card->birthday->day }}</span>
                        <x-avatar :name="$card->name" tint="tint-run" :photo="$card->photo192" />
                        <span class="f1" style="font-weight:600">{{ $card->name }}</span>
                    </a>
                @empty
                    <div class="meta tc" style="padding:16px">Aucun anniversaire partagé en {{ mb_strtolower($monthLabel) }}.</div>
                @endforelse
            </div>
        @elseif ($cards->isEmpty())
            <div class="card card-pad meta tc" style="padding:32px">Personne ne correspond à ces critères.</div>
        @else
            <div class="trombi-grid">
                @foreach ($cards as $card)
                    <div wire:key="card-{{ $card->id }}" style="display:contents">
                        @include('livewire.partials.trombi-card', ['card' => $card, 'href' => route('trombi.show', $card->id)])
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
