{{-- Onglet « Infos » — porté de screen-fiche.jsx FInfos (programme + lieu).
     Météo / parcours OpenRunner / ciblage détaillé = features futures, non rendues ici. --}}

{{-- Bloc spécifique kind --}}
@if ($session->kind === 'training' && $session->content_markdown)
    <div>
        <div class="sect-head"><span class="sect-title">Programme</span></div>
        <div class="card card-pad"><div class="db-prose">{!! \App\Support\Markup::render($session->content_markdown) !!}</div></div>
    </div>
@elseif ($session->kind === 'competition')
    <div>
        <div class="sect-head"><span class="sect-title">Épreuve</span></div>
        <div class="card card-pad">
            <dl class="fiche-infos">
                @if ($session->eventType)<dt>Type</dt><dd>{{ $session->eventType->label }}</dd>@endif
                @if ($session->distance)<dt>Distance</dt><dd>{{ $session->distance }}</dd>@endif
                @if ($session->external_url)<dt>Lien</dt><dd><a href="{{ $session->external_url }}" target="_blank" rel="noopener noreferrer" class="auth-link">Infos officielles</a></dd>@endif
            </dl>
        </div>
    </div>
@elseif ($session->kind === 'club_event' && $session->agenda)
    <div>
        <div class="sect-head"><span class="sect-title">Programme</span></div>
        <div class="card card-pad"><div class="db-prose">{!! \App\Support\Markup::render($session->agenda) !!}</div></div>
    </div>
@endif

{{-- Album photos externe (§4.12.6) — simple lien, aucune intégration --}}
@if ($session->photos_album_url)
    <div>
        <div class="sect-head"><span class="sect-title">Photos</span></div>
        <div class="card card-pad">
            <a class="btn btn-ghost btn-block album-link" href="{{ $session->photos_album_url }}" target="_blank" rel="noopener noreferrer">
                <x-icon name="image" :size="16" class="ic-img" style="flex:0 0 auto" />
                <span class="f1" style="text-align:left">Album photos du club</span>
                <x-icon name="arrow-up-right" :size="15" style="color:var(--fg-muted);flex:0 0 auto" />
            </a>
            <div class="meta" style="font-size:12px;margin-top:8px">Album externe partagé, ouvert dans un nouvel onglet. Les photos sont déposées hors de l'app par les participants.</div>
        </div>
    </div>
@endif

{{-- Lieu --}}
@if ($session->location || $session->location_text)
    <div>
        <div class="sect-head"><span class="sect-title">Lieu</span></div>
        <div class="card card-pad">
            <div style="font-weight:700;font-size:15px">{{ $session->location_text ?: $session->location?->name }}</div>
            @if ($session->location?->address)
                <div class="meta">{{ $session->location->address }}</div>
            @endif
            {{-- Aperçu cartographique du lieu (§4.13.4) — uniquement si géocodé (cohérent avec la météo). --}}
            @if ($session->location?->latitude && $session->location?->longitude)
                <div wire:ignore style="margin-top:12px">
                    <div x-data="locationMap({ lat: {{ (float) $session->location->latitude }}, lng: {{ (float) $session->location->longitude }}, lockable: true })"
                         class="loc-map-wrap">
                        <div x-ref="map" class="loc-map fiche-loc-map"></div>
                        {{-- Verrou d'interaction : verrouillée, la carte n'est qu'un aperçu (le scroll de
                             la page passe au travers). Un tap sur le voile la déverrouille ; un petit
                             bouton la re-verrouille. Handlers Leaflet natifs (cf. applyLock dans gpx.js). --}}
                        <button type="button" class="loc-map-veil" x-show="locked" x-on:click="toggleLock()"
                                aria-label="Déverrouiller la carte">
                            <span class="loc-map-veil-pill"><x-icon name="maximize" :size="15" /> Toucher pour interagir</span>
                        </button>
                        <button type="button" class="loc-map-lockbtn" x-show="!locked" x-cloak x-on:click="toggleLock()"
                                aria-label="Verrouiller la carte" title="Verrouiller la carte"><x-icon name="lock" :size="15" /></button>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endif

{{-- Météo prévisionnelle (§4.13.5) — masquée pour les séances passées/annulées, et sur desktop
     (colonne droite) quand $noWeather=true (météo déjà rendue dans fiche-side). --}}
@if (($weatherState ?? 'none') !== 'none' && !($noWeather ?? false))
    <div>
        <div class="sect-head"><span class="sect-title">Météo</span><span class="meta mlauto" style="font-size:11px">J-16</span></div>
        @include('livewire.partials.weather-cartouche')
    </div>
@endif

{{-- Ciblage (§4.5) — catégories d'âge acceptées pour la séance. Porté de screen-fiche.jsx FInfos.
     Chips triées par sort_order (Benjamins → Master), cohérent avec le référentiel FFTri. --}}
@php $targetCats = $session->categories->sortBy('sort_order'); @endphp
@if ($targetCats->isNotEmpty())
    <div>
        <div class="sect-head"><span class="sect-title">Ciblage</span></div>
        <div class="flex g6 wrap">
            @foreach ($targetCats as $cat)
                <span class="chip chip-line">{{ $cat->label }}</span>
            @endforeach
        </div>
    </div>
@endif

@php
    $hasContent = ($session->kind === 'training' && $session->content_markdown)
        || $session->kind === 'competition'
        || ($session->kind === 'club_event' && $session->agenda)
        || $session->location || $session->location_text
        || $targetCats->isNotEmpty();
@endphp
@unless ($hasContent)
    <div class="card card-pad meta" style="text-align:center">Pas encore de détails pour cette séance.</div>
@endunless
