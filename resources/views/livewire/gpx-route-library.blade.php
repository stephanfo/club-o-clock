{{-- Bibliothèque de parcours (PRD §4.20, J10.B).
     Pas d'écran de référence dans design/ (screen-parcours.jsx = bloc de fiche séance uniquement) :
     structure dérivée de member-list.blade.php, classes du design réutilisées telles quelles
     (.dk-topbar, .input, .chip/.is-active, .scard, .stat). Seule .route-grid est nouvelle. --}}
<div class="form-screen">
    <x-flash-float />

    {{-- Topbar verte fixe mobile (couvre la safe-area iOS). Écran de premier niveau accessible
         par la bottom-nav : pas de bouton retour, comme le planning. --}}
    <x-topbar title="Parcours">
        <x-slot:trailing>
            {{-- .dk-topbar est masquée sous 768px : le « Ajouter » desktop disparaîtrait sans ce
                 relais. Un <a> et non un wire:click — jamais d'action Livewire dans la barre fixe. --}}
            @if ($canManage)
                <a href="{{ route('gpx-routes.create') }}" wire:navigate class="iconbtn" aria-label="Ajouter un parcours">
                    <x-icon name="plus" />
                </a>
            @endif
            <x-alert-bell dark />
        </x-slot:trailing>
    </x-topbar>

    {{-- ─── Topbar desktop ─── --}}
    <div class="dk-topbar">
        <div class="f1">
            <div class="dsp" style="font-size:24px">Parcours</div>
            <div class="meta">{{ $total }} parcours{{ $this->hasFilters() ? ' correspondant' . ($total > 1 ? 's' : '') . ' aux filtres' : ' en bibliothèque' }}</div>
        </div>
        @if ($canManage)
            <a href="{{ route('gpx-routes.create') }}" class="btn btn-primary btn-sm" wire:navigate>
                <x-icon name="plus" :size="15" /> Ajouter
            </a>
        @endif
    </div>

    <div class="dk-body">
        {{-- ═══ Recherche + filtres ═══ --}}
        <div class="card" style="overflow:hidden;margin-bottom:16px">
            <div class="flex g8 ac wrap" style="padding:12px;border-bottom:1px solid var(--divider)">
                <div class="input flex ac g8 f1" style="min-width:220px">
                    <x-icon name="search" :size="16" style="color:var(--fg-muted)" />
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Rechercher un parcours…"
                           style="border:none;background:none;outline:none;font:inherit;color:inherit;width:100%" />
                </div>
                @if ($this->hasFilters())
                    <button type="button" wire:click="resetFilters" class="btn btn-ghost btn-sm">
                        <x-icon name="x" :size="14" /> Réinitialiser
                    </button>
                @endif
            </div>

            <div style="padding:12px;display:flex;flex-direction:column;gap:10px">
                {{-- Chips à sélection MULTIPLE (2026-08-02) : plusieurs valeurs d'un même filtre
                     s'unissent (OU), les filtres se croisent entre eux (ET). aria-pressed reste le
                     bon rôle — ce sont des bascules, pas des cases d'un groupe exclusif. --}}

                {{-- Secteur cardinal : rose des vents, ordre géographique et non alphabétique. --}}
                <div>
                    <div class="eyebrow" style="margin-bottom:6px">Direction</div>
                    <div class="sector-chips">
                        @foreach (\App\Models\GpxRoute::SECTORS as $s)
                            <button type="button" wire:click="toggle('sector', '{{ $s }}')"
                                    class="chip{{ $this->isOn('sector', $s) ? ' is-active' : '' }}"
                                    aria-pressed="{{ $this->isOn('sector', $s) ? 'true' : 'false' }}">{{ $s }}</button>
                        @endforeach
                    </div>
                </div>

                @if ($disciplines->isNotEmpty())
                    <div>
                        <div class="eyebrow" style="margin-bottom:6px">Discipline</div>
                        <div class="flex g6 wrap">
                            @foreach ($disciplines as $d)
                                <button type="button" wire:click="toggle('discipline', '{{ $d->id }}')"
                                        class="chip{{ $this->isOn('discipline', (string) $d->id) ? ' is-active' : '' }}"
                                        aria-pressed="{{ $this->isOn('discipline', (string) $d->id) ? 'true' : 'false' }}">{{ $d->label }}</button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div>
                    <div class="eyebrow" style="margin-bottom:6px">Distance</div>
                    {{-- 8 tranches, même grille que la rose des vents (4 colonnes sous 560px, 8 au-dessus)
                         plutôt qu'un flex wrap qui laisserait une dernière ligne bancale. --}}
                    <div class="sector-chips">
                        @foreach (\App\Livewire\GpxRouteLibrary::DISTANCE_BANDS as $key => [$label, $min, $max])
                            <button type="button" wire:click="toggle('distance', '{{ $key }}')"
                                    class="chip{{ $this->isOn('distance', $key) ? ' is-active' : '' }}"
                                    aria-pressed="{{ $this->isOn('distance', $key) ? 'true' : 'false' }}">{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Forme : is_loop ne discrimine rien sur un club qui roule en boucles (doc J10 §2),
                     d'où le tri arrondi / étiré sur l'allongement de l'emprise. --}}
                <div>
                    <div class="eyebrow" style="margin-bottom:6px">Forme</div>
                    <div class="flex g6 wrap">
                        <button type="button" wire:click="toggle('shape', 'round')" class="chip{{ $this->isOn('shape', 'round') ? ' is-active' : '' }}"
                                aria-pressed="{{ $this->isOn('shape', 'round') ? 'true' : 'false' }}">Arrondi</button>
                        <button type="button" wire:click="toggle('shape', 'long')" class="chip{{ $this->isOn('shape', 'long') ? ' is-active' : '' }}"
                                aria-pressed="{{ $this->isOn('shape', 'long') ? 'true' : 'false' }}">Étiré</button>
                    </div>
                </div>

                {{-- Relief : seuils RELATIFS au terrain du club (cf. GpxRoute::GRADE_*). --}}
                <div>
                    <div class="eyebrow" style="margin-bottom:6px">Relief</div>
                    <div class="flex g6 wrap">
                        <button type="button" wire:click="toggle('grade', 'rolling')" class="chip{{ $this->isOn('grade', 'rolling') ? ' is-active' : '' }}"
                                aria-pressed="{{ $this->isOn('grade', 'rolling') ? 'true' : 'false' }}">Roulant</button>
                        <button type="button" wire:click="toggle('grade', 'hilly')" class="chip{{ $this->isOn('grade', 'hilly') ? ' is-active' : '' }}"
                                aria-pressed="{{ $this->isOn('grade', 'hilly') ? 'true' : 'false' }}">Vallonné</button>
                        <button type="button" wire:click="toggle('grade', 'tough')" class="chip{{ $this->isOn('grade', 'tough') ? ' is-active' : '' }}"
                                aria-pressed="{{ $this->isOn('grade', 'tough') ? 'true' : 'false' }}">Exigeant</button>
                    </div>
                </div>

                @if ($canManage)
                    <label class="flex ac g8 meta" style="cursor:pointer;font-size:12.5px">
                        <input type="checkbox" wire:model.live="archived" />
                        Inclure les parcours archivés
                    </label>
                @endif
            </div>
        </div>

        {{-- ═══ Bascule liste / carte (J10.C bis) ═══
             .seg/.seg-item du design, comme le sélecteur de période du planning. Les filtres
             ci-dessus s'appliquent aux DEUX modes : la carte n'est qu'une autre lecture du même
             jeu filtré, pas un écran séparé. --}}
        <div class="seg" style="margin-bottom:16px" role="tablist" aria-label="Mode d'affichage">
            <button type="button" role="tab" wire:click="setMode('list')"
                    class="seg-item{{ $this->isMap() ? '' : ' on' }}"
                    aria-selected="{{ $this->isMap() ? 'false' : 'true' }}">
                <x-icon name="list" :size="14" /> Liste
            </button>
            <button type="button" role="tab" wire:click="setMode('map')"
                    class="seg-item{{ $this->isMap() ? ' on' : '' }}"
                    aria-selected="{{ $this->isMap() ? 'true' : 'false' }}">
                <x-icon name="map" :size="14" /> Carte
            </button>
        </div>

        @if ($this->isMap())
            {{-- Îlot Alpine en wire:ignore : Livewire ne doit jamais re-render le conteneur Leaflet
                 (il détruirait les tuiles).

                 Conséquence directe, et c'est le piège : puisque le sous-arbre n'est pas re-rendu,
                 ses ATTRIBUTS ne le sont pas non plus. Un `x-effect="load('…')"` garderait à jamais
                 l'URL du montage — Alpine ne réévalue une expression que si une dépendance RÉACTIVE
                 change, or une URL interpolée par Blade est une constante littérale.

                 Le canal doit donc contourner le DOM : un événement Livewire, qui traverse
                 wire:ignore par construction. Même mécanique que `location-located` → locationMap. --}}
            <div wire:ignore x-data="gpxRoutesMap({ url: @js($tracesUrl), lockable: true })"
                 x-on:gpx-routes-filtered.window="load($event.detail.url)">
                <div class="routes-map-wrap">
                    <div x-ref="map" class="routes-map"></div>
                    <div class="routes-map-veil" x-show="loading" x-transition.opacity>
                        <span class="meta">Chargement des tracés…</span>
                    </div>
                    <div class="routes-map-veil" x-show="!loading && (failed || empty)" x-cloak>
                        <span class="meta" x-text="failed ? 'Tracés indisponibles.' : 'Aucun parcours à afficher sur la carte.'"></span>
                    </div>
                    {{-- Verrou d'interaction (même dispositif que la fiche parcours) : 62vh de carte
                         captureraient tout le scroll de la page. Le voile n'apparaît qu'une fois les
                         tracés dessinés, sinon il se superposerait à celui du chargement. --}}
                    <button type="button" class="loc-map-veil" x-show="locked && !loading && !failed && !empty"
                            x-cloak x-on:click="toggleLock()" aria-label="Déverrouiller la carte">
                        <span class="loc-map-veil-pill"><x-icon name="maximize" :size="15" /> Toucher pour interagir</span>
                    </button>
                    {{-- Pas de bouton plein écran ici (contrairement à gpxMap) : le lockbtn occupe
                         seul le coin, sans décalage. --}}
                    <button type="button" class="loc-map-lockbtn" x-show="!locked" x-cloak
                            x-on:click="toggleLock()" aria-label="Verrouiller la carte"
                            title="Verrouiller la carte"><x-icon name="lock" :size="15" /></button>
                </div>
                <div class="meta tc" style="padding:10px 12px">
                    <span x-show="!loading && !failed && !empty" x-cloak>
                        <span x-text="count"></span> tracé<span x-show="count > 1">s</span> ·
                        <span x-text="locked ? 'touchez la carte pour l\'explorer' : 'touchez un tracé pour l\'identifier'"></span>
                    </span>
                    <span x-show="truncated" x-cloak style="color:var(--danger)">
                        · affichage limité aux 120 premiers
                    </span>
                </div>
                @if ($notMappable > 0)
                    {{-- Le compte de la carte ne peut pas correspondre à celui de la liste : on le dit,
                         plutôt que de laisser croire à un affichage exhaustif (cf. incident géo J10). --}}
                    <div class="meta tc" style="padding:0 12px 12px;font-size:12px">
                        {{ $notMappable }} parcours sans données de tracé {{ $notMappable > 1 ? 'ne sont' : "n'est" }} pas affichable{{ $notMappable > 1 ? 's' : '' }} sur la carte.
                    </div>
                @endif
            </div>
        @else

        {{-- ═══ Résultats ═══ --}}
        @if ($routes->isEmpty())
            <div class="card card-pad meta tc" style="padding:32px">
                @if ($this->hasFilters())
                    Aucun parcours ne correspond à ces critères.
                @else
                    Aucun parcours en bibliothèque pour l'instant.
                @endif
            </div>
        @else
            <div class="route-grid">
                @foreach ($routes as $r)
                    {{-- Carte entièrement cliquable → fiche parcours, comme <x-session-card>.
                         <a wire:navigate> SANS wire:click (piège documenté : ne jamais empiler les deux). --}}
                    <a class="scard {{ $r->discipline?->colorClass() }}" href="{{ route('gpx-routes.show', $r) }}" wire:navigate
                       style="padding:13px;display:flex;flex-direction:column;gap:8px;text-align:left">
                        <div class="flex ac g8">
                            <x-disc-badge :discipline="$r->discipline" :size="30" />
                            <div class="f1" style="min-width:0">
                                <div style="font-weight:700;font-size:14.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $r->name }}</div>
                                <div class="meta" style="font-size:12px">
                                    {{ $r->discipline?->label ?? 'Parcours' }}@if ($r->sector) · secteur {{ $r->sector }}@endif
                                </div>
                            </div>
                            @if ($r->isArchived())
                                <span class="chip chip-sm chip-warn" style="flex:0 0 auto">archivé</span>
                            @endif
                        </div>

                        <div class="flex g6 wrap">
                            @if ($r->distance_km !== null)
                                <span class="chip chip-sm chip-line">{{ $r->distance_km }} km</span>
                            @endif
                            @if ($r->dplus_m !== null)
                                <span class="chip chip-sm chip-line">{{ $r->dplus_m }} m D+</span>
                            @endif
                            @if ($r->gradeLabel())
                                <span class="chip chip-sm chip-blue">{{ $r->gradeLabel() }}</span>
                            @endif
                            @if ($r->shapeLabel())
                                <span class="chip chip-sm">{{ ucfirst($r->shapeLabel()) }}</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="meta tc" style="padding:12px">
                {{ $routes->count() }} / {{ $total }}
                @if ($routes->count() < $total)
                    · <button type="button" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore"
                              class="underline-link" style="border:none;background:none;cursor:pointer;font:inherit;color:inherit">charger plus</button>
                @endif
            </div>
        @endif
        @endif {{-- fin de la bascule liste / carte --}}
    </div>
</div>
