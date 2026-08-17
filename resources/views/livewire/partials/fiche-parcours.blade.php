{{-- Section « Parcours » — porté de screen-parcours.jsx <ParcoursBlock>.
     Coexistence OpenRunner (carte iframe) + GPX (tracé OSM Leaflet), §4.13.1/.2/.3.
     Reçoit : $session. --}}
@php
    $embed = $session->route_openrunner_embed_url;
    $publicLink = $session->route_openrunner_public_url;
    $hasEmbed = (bool) $embed;
    $hasPublic = (bool) $publicLink;
    $hasOR = $hasEmbed || $hasPublic;
    $gpxRoute = $session->gpxRoute;
    $hasGPX = (bool) $gpxRoute;
    $both = $hasOR && $hasGPX;
@endphp
@if (! $hasOR && ! $hasGPX)
    <div class="card card-pad meta tc" style="padding:20px 16px;color:var(--fg-muted)">Aucun parcours associé à cette séance.</div>
@else
    {{-- Onglet par défaut : le TRACÉ GPX quand il existe (arbitrage 2026-08-15, §4.13.3 laissait
         le placement « à arbitrer aux maquettes »). C'est la source que le club maîtrise —
         hébergée chez lui, sans tiers, avec ses métriques et son profil altimétrique. OpenRunner
         passe en second : il dépend d'un compte externe et d'un jeton d'embed qui peut expirer. --}}
    <div x-data="{ tab: '{{ $hasGPX ? 'gpx' : 'carte' }}' }" style="display:flex;flex-direction:column;gap:14px">
        @if ($both)
            <div class="route-seg" role="tablist" aria-label="Source du parcours">
                <button type="button" role="tab" :aria-selected="tab === 'gpx'" :class="tab === 'gpx' ? 'is-on' : ''" x-on:click="tab = 'gpx'"><x-icon name="route" :size="14" /> Tracé GPX</button>
                <button type="button" role="tab" :aria-selected="tab === 'carte'" :class="tab === 'carte' ? 'is-on' : ''" x-on:click="tab = 'carte'"><x-icon name="map" :size="14" /> Carte OpenRunner</button>
            </div>
        @endif

        {{-- ── Tracé GPX (OpenStreetMap, Leaflet) ── --}}
        @if ($hasGPX)
            <div class="route-stack" @if ($both) x-show="tab === 'gpx'" @endif>
                {{-- lockable : même verrou que la carte du lieu (§4.13.4). Sans lui, une carte de
                     400 px capture le scroll de la page sur mobile. --}}
                <div wire:ignore x-data="gpxMap({ url: '{{ route('gpx-routes.gpx', $gpxRoute) }}', lockable: true })">
                    <div x-ref="fsWrap" class="gpx-fswrap" x-show="!failed" style="position:relative">
                        <div x-ref="map" class="gpx-map"></div>
                        <button type="button" class="gpx-fsbtn" x-show="fsSupported" x-on:click="toggleFullscreen()"
                                :aria-label="isFs ? 'Quitter le plein écran' : 'Afficher en plein écran'"
                                :title="isFs ? 'Quitter le plein écran' : 'Plein écran'">
                            <x-icon name="maximize" :size="16" x-show="!isFs" />
                            <x-icon name="minimize" :size="16" x-show="isFs" x-cloak />
                        </button>
                        <button type="button" class="loc-map-veil" x-show="locked" x-on:click="toggleLock()"
                                aria-label="Déverrouiller la carte">
                            <span class="loc-map-veil-pill"><x-icon name="maximize" :size="15" /> Toucher pour interagir</span>
                        </button>
                        {{-- Décalé à gauche SEULEMENT si le bouton plein écran est là : sur iOS
                             (fullscreen indisponible) il occupe seul le coin, sans trou à droite. --}}
                        <button type="button" class="loc-map-lockbtn" :class="fsSupported && 'gpx-lockbtn'"
                                x-show="!locked" x-cloak x-on:click="toggleLock()"
                                aria-label="Verrouiller la carte"
                                title="Verrouiller la carte"><x-icon name="lock" :size="15" /></button>
                    </div>
                    <div class="or-fallback" x-show="failed" x-cloak>
                        <span class="of-ic"><x-icon name="route" :size="20" /></span>
                        <div class="f1"><div style="font-weight:700;font-size:14px">Tracé indisponible</div><div class="meta" style="font-size:12.5px;margin-top:2px">Le fichier GPX reste téléchargeable ci-dessous.</div></div>
                    </div>
                </div>

                <div class="card card-pad">
                    <div class="route-metrics">
                        <div class="rm"><div class="eyebrow">Distance</div><div class="num" style="font-size:19px;margin-top:3px">{{ $gpxRoute->distance_km !== null ? $gpxRoute->distance_km.' km' : '—' }}</div></div>
                        <div class="rm"><div class="eyebrow"><x-icon name="mountain" :size="12" style="color:var(--fg-muted)" /> D+</div><div class="num" style="font-size:19px;margin-top:3px">{{ $gpxRoute->dplus_m !== null ? $gpxRoute->dplus_m.' m' : '—' }}</div></div>
                        <div class="rm"><div class="eyebrow">D−</div><div class="num" style="font-size:19px;margin-top:3px">{{ $gpxRoute->dmoins_m !== null ? $gpxRoute->dmoins_m.' m' : '—' }}</div></div>
                    </div>
                    <div class="route-metrics" style="margin-top:12px">
                        <div class="rm"><div class="eyebrow"><x-icon name="gauge" :size="12" style="color:var(--fg-muted)" /> Alt. min</div><div class="num" style="font-size:19px;margin-top:3px">{{ $gpxRoute->alt_min_m !== null ? $gpxRoute->alt_min_m.' m' : '—' }}</div></div>
                        <div class="rm"><div class="eyebrow"><x-icon name="mountain" :size="12" style="color:var(--fg-muted)" /> Alt. max</div><div class="num" style="font-size:19px;margin-top:3px">{{ $gpxRoute->alt_max_m !== null ? $gpxRoute->alt_max_m.' m' : '—' }}</div></div>
                        {{-- Relief : le libellé oriente, la valeur brute reste affichée dessous — les
                             seuils sont relatifs au terrain du club (cf. GpxRoute::GRADE_*). --}}
                        <div class="rm">
                            <div class="eyebrow"><x-icon name="bar-chart" :size="12" style="color:var(--fg-muted)" /> Relief</div>
                            <div class="num" style="font-size:19px;margin-top:3px">{{ $gpxRoute->gradeLabel() ?? '—' }}</div>
                            @if ($gpxRoute->gradeIndex() !== null)
                                <div class="meta" style="font-size:12px">{{ str_replace('.', ',', (string) $gpxRoute->gradeIndex()) }} m/km</div>
                            @endif
                        </div>
                    </div>
                    <div class="meta" style="font-size:12px;margin-top:10px">Distance, dénivelés et altitudes extraits du fichier GPX (parsing côté client).</div>
                </div>

                {{-- Le parcours est une entité partagée (§4.20) : on le nomme ici, et on renvoie vers
                     sa fiche (profil altimétrique + autres séances qui l'utilisent). Même patron que
                     la carte pièce jointe de screen-fiche.jsx, chevron compris. --}}
                <a class="card card-pad flex ac g10" href="{{ route('gpx-routes.show', $gpxRoute) }}" wire:navigate
                   style="text-decoration:none;color:inherit">
                    <span class="disc-badge" style="background:var(--slate-100);color:var(--fg-soft)"><x-icon name="route" :size="16" /></span>
                    <div class="f1" style="min-width:0">
                        <div style="font-weight:700;font-size:14px">{{ $gpxRoute->name }}</div>
                        <div class="meta">{{ $gpxRoute->discipline?->label ?? 'Parcours' }}@if ($gpxRoute->sector) · secteur {{ $gpxRoute->sector }}@endif</div>
                    </div>
                    <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
                </a>

                <a class="btn btn-ghost btn-block" href="{{ route('gpx-routes.gpx', $gpxRoute) }}">
                    <x-icon name="download" :size="15" /> Télécharger le GPX
                    @if ($gpxRoute->gpx_size_ko)<span class="meta" style="font-size:12px;margin-left:4px">· {{ $gpxRoute->gpx_size_ko }} Ko</span>@endif
                </a>
            </div>
        @endif

        {{-- ── Carte OpenRunner ── --}}
        @if ($hasOR)
            {{-- `armed` diffère le rendu de l'iframe au premier affichage de l'onglet. Deux raisons.
                 D'abord la correction d'un piège : l'iframe porte loading="lazy", or un élément en
                 display:none n'entre jamais dans le viewport — masquée, elle ne se serait jamais
                 chargée, et le minuteur de 6 s l'aurait déclarée « indisponible » à tort.
                 Ensuite la vie privée : l'embed expose l'IP et l'user-agent du visiteur à
                 OpenRunner (§4.13, conséquence RGPD). Ne rien charger tant que personne n'a
                 demandé la carte, c'est un appel tiers en moins sur chaque fiche de séance. --}}
            <div class="route-stack" @if ($both) x-show="tab === 'carte'" x-cloak @endif
                 x-data="{ loaded: false, failed: false, armed: {{ $both ? 'false' : 'true' }} }"
                 @if ($both)
                     x-effect="if (tab === 'carte' && ! armed) { armed = true; setTimeout(() => { if (! loaded) failed = true }, 6000) }"
                 @else
                     x-init="setTimeout(() => { if (! loaded) failed = true }, 6000)"
                 @endif>
                @if ($hasEmbed)
                    <template x-if="armed && !failed">
                        <iframe src="{{ $embed }}" width="100%" height="650" loading="lazy" referrerpolicy="no-referrer"
                                title="Carte du parcours OpenRunner" x-on:load="loaded = true"
                                style="border:none;display:block;border-radius:var(--radius-md)"></iframe>
                    </template>
                    <template x-if="failed">
                        <div class="or-fallback">
                            <span class="of-ic"><x-icon name="map-pin" :size="20" /></span>
                            <div class="f1">
                                <div style="font-weight:700;font-size:14px">Carte indisponible</div>
                                <div class="meta" style="font-size:12.5px;margin-top:2px">L'aperçu OpenRunner n'a pas pu se charger.@if ($hasPublic) Le parcours reste consultable sur le site.@endif</div>
                            </div>
                        </div>
                    </template>
                @endif
                @if ($hasPublic)
                    <a class="btn btn-ghost btn-block" href="{{ $publicLink }}" target="_blank" rel="noopener noreferrer">
                        <x-icon name="arrow-up-right" :size="15" /> Ouvrir sur OpenRunner
                    </a>
                @endif
            </div>
        @endif
    </div>
@endif
