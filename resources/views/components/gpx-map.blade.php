{{-- Carte du tracé GPX (Leaflet, composant Alpine gpxMap) — fiche séance ET fiche parcours.
     Un seul balisage pour les deux : il portait déjà le plein écran et le verrou, le survol animé
     (#67) l'aurait dupliqué une fois de plus.

     lockable : la carte fait 400 px de haut et capturerait le scroll de la page. Verrouillée = simple
     aperçu, on la libère d'un tap (cf. carte du lieu, §4.13.4). Le survol fonctionne verrouillé.

     Le panneau de survol vit SOUS la carte et non en surimpression : il masquerait le tracé et
     l'attribution OSM, et le voile de verrouillage (inset:0) le rendrait inatteignable. --}}
@props(['url'])
<div wire:ignore x-data="gpxMap({ url: @js($url), lockable: true })">
    <div x-ref="fsWrap" class="gpx-fswrap" x-show="!failed">
        <div class="gpx-mapbox">
            <div x-ref="map" class="gpx-map"></div>
            <button type="button" class="gpx-fsbtn" x-show="fsSupported" x-on:click="toggleFullscreen()"
                    :aria-label="isFs ? 'Quitter le plein écran' : 'Afficher en plein écran'"
                    :title="isFs ? 'Quitter le plein écran' : 'Plein écran'">
                <x-icon name="maximize" :size="16" x-show="!isFs" />
                <x-icon name="minimize" :size="16" x-show="isFs" x-cloak />
            </button>
            <button type="button" class="loc-map-veil" x-show="locked" x-on:click="toggleLock()"
                    aria-label="Déverrouiller la carte">
                {{-- Masquée pendant le survol : centrée sur la carte, elle cacherait le point suivi. --}}
                <span class="loc-map-veil-pill" x-show="!fly.on"><x-icon name="maximize" :size="15" /> Toucher pour interagir</span>
            </button>
            {{-- Décalé à gauche SEULEMENT si le bouton plein écran est là : sur iOS
                 (fullscreen indisponible) il occupe seul le coin, sans trou à droite. --}}
            <button type="button" class="loc-map-lockbtn" :class="fsSupported && 'gpx-lockbtn'"
                    x-show="!locked" x-cloak x-on:click="toggleLock()"
                    aria-label="Verrouiller la carte"
                    title="Verrouiller la carte"><x-icon name="lock" :size="15" /></button>
            {{-- N'apparaît qu'une fois le tracé lu : pas de bouton sur une carte en échec. --}}
            <button type="button" class="gpx-flybtn" x-show="fly.ready && !fly.on" x-cloak x-on:click="flyOpen()">
                <x-icon name="play" :size="13" /> Survoler
            </button>
        </div>

        <div class="gpx-fly" x-show="fly.on" x-cloak>
            <div class="gpx-fly-row">
                <button type="button" class="gpx-fly-btn" x-on:click="flyToggle()"
                        :aria-label="fly.playing ? 'Mettre en pause' : 'Lancer le survol'">
                    <x-icon name="pause" :size="15" x-show="fly.playing" />
                    <x-icon name="play" :size="15" x-show="!fly.playing" />
                </button>
                {{-- :value et non x-model : la lecture réécrit la position 60 fois par seconde. --}}
                <input type="range" class="gpx-fly-range" min="0" :max="fly.total" step="1"
                       :value="fly.d" x-on:input="flySeek($event.target.value)"
                       aria-label="Position sur le tracé">
                <button type="button" class="gpx-fly-btn" x-on:click="flyClose()" aria-label="Arrêter le survol">
                    <x-icon name="x" :size="15" />
                </button>
            </div>
            <div class="gpx-fly-row">
                <div class="gpx-fly-info" aria-live="off">
                    <span class="gpx-fly-dist" x-text="flyDistance"></span>
                    <span x-show="flyAltitude !== null"><x-icon name="mountain" :size="12" /> <span x-text="flyAltitude"></span></span>
                    <span x-show="flySlope !== null"><x-icon name="bar-chart" :size="12" /> <span x-text="flySlope"></span></span>
                </div>
                <div class="seg" role="group" aria-label="Vitesse du survol">
                    @foreach ([1, 2, 4] as $speed)
                        <button type="button" class="seg-item gpx-fly-speed" :class="fly.speed === {{ $speed }} && 'on'"
                                :aria-pressed="fly.speed === {{ $speed }}"
                                x-on:click="flySpeed({{ $speed }})">×{{ $speed }}</button>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
    <div class="or-fallback" x-show="failed" x-cloak>
        <span class="of-ic"><x-icon name="route" :size="20" /></span>
        <div class="f1">
            <div style="font-weight:700;font-size:14px">Tracé indisponible</div>
            <div class="meta" style="font-size:12.5px;margin-top:2px">Le fichier GPX reste téléchargeable ci-dessous.</div>
        </div>
    </div>
</div>
