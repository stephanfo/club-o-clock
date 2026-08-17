{{-- Fiche d'un parcours de la bibliothèque (PRD §4.20, J10.C).
     Référence design : screen-parcours.jsx <ParcoursBlock> branche GPX — profil altimétrique,
     bloc métriques, bouton de téléchargement. Les classes viennent toutes du design system
     (.alt-profile, .route-metrics/.rm, .gpx-map/.gpx-fswrap/.gpx-fsbtn, .or-fallback, .scard).
     Ce qui n'existe pas dans le proto — séances liées, actions d'archivage — reprend les patrons
     déjà validés ailleurs (<x-session-card>, <x-dialog danger> + <x-conseq-row>). --}}
@php
    $r = $gpxRoute;
    $archived = $r->isArchived();
@endphp
<div class="form-screen">
    <x-flash-float />

    <x-topbar :title="$r->name" :sub="$r->discipline?->label ?? 'Parcours'"
              :back="route('gpx-routes.index')" back-label="Retour aux parcours">
        <x-slot:trailing>
            @if ($canManage)
                <a href="{{ route('gpx-routes.edit', $r) }}" wire:navigate class="iconbtn" aria-label="Modifier le parcours">
                    <x-icon name="pencil" />
                </a>
            @endif
            <x-alert-bell dark />
        </x-slot:trailing>
    </x-topbar>

    {{-- ─── Topbar desktop ─── --}}
    <div class="dk-topbar">
        {{-- Retour historique d'abord (conserve les filtres de la bibliothèque, ou ramène à la
             séance d'où l'on vient), URL en repli. Pas de wire:navigate : cf. x-topbar. --}}
        <a href="{{ route('gpx-routes.index') }}" class="btn btn-ghost btn-sm"
           onclick="return !window.clubBack?.()">
            <x-icon name="chevron-left" :size="15" /> Parcours
        </a>
        <div class="f1" style="min-width:0">
            <div class="dsp" style="font-size:24px">{{ $r->name }}</div>
            <div class="meta">
                {{ $r->discipline?->label ?? 'Parcours' }}@if ($r->sector) · secteur {{ $r->sector }}@endif
                @if ($r->startLocation) · départ {{ $r->startLocation->name }}@endif
            </div>
        </div>
        @if ($archived)
            <span class="chip chip-warn">Archivé</span>
        @endif
        @if ($canManage)
            <a href="{{ route('gpx-routes.edit', $r) }}" class="btn btn-ghost btn-sm" wire:navigate>
                <x-icon name="pencil" :size="15" /> Modifier
            </a>
        @endif
    </div>

    <div class="dk-body">
        @if ($archived)
            <div style="margin-bottom:16px">
                <x-banner kind="warn">
                    <div>
                        Ce parcours est <b>archivé</b> : il n'apparaît plus dans la bibliothèque des adhérents.
                        Sa trace et son fichier sont conservés — le restaurer le rend à nouveau visible.
                    </div>
                </x-banner>
            </div>
        @endif

        <div style="display:flex;flex-direction:column;gap:14px">
            {{-- Géo absente = bloc rejeté en bloc par GpxStats::sanitizeGeo, OU trace déposée avant
                 que l'extraction géo n'existe (cas des 2 parcours du 2026-08-02). Sans ce liseré, un
                 parcours sans direction ni forme paraît normal en bibliothèque : il est juste
                 silencieusement absent des filtres. Ton neutre : c'est une donnée manquante, pas une
                 erreur — le parcours reste consultable et téléchargeable. --}}
            @if ($missingGeo)
                <div class="or-fallback">
                    <span class="of-ic"><x-icon name="navigation" :size="20" /></span>
                    <div class="f1">
                        <div style="font-weight:700;font-size:14px">Données géographiques absentes</div>
                        <div class="meta" style="font-size:12.5px;margin-top:2px">
                            Ce parcours n'a ni direction, ni forme, ni profil altimétrique : il n'apparaît pas dans
                            les filtres correspondants.
                            @if ($canManage)
                                Redépose le fichier depuis <a class="underline-link" href="{{ route('gpx-routes.edit', $r) }}" wire:navigate>Modifier le parcours</a> pour les extraire.
                            @endif
                        </div>
                    </div>
                </div>
            @endif

            {{-- ═══ Tracé (Leaflet, même composant que la fiche séance) ═══ --}}
            {{-- lockable : la carte fait 400 px de haut et capturerait le scroll de la page.
                 Verrouillée = simple aperçu, on la libère d'un tap (cf. carte du lieu, §4.13.4). --}}
            <div wire:ignore x-data="gpxMap({ url: '{{ route('gpx-routes.gpx', $r) }}', lockable: true })">
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
                    <div class="f1">
                        <div style="font-weight:700;font-size:14px">Tracé indisponible</div>
                        <div class="meta" style="font-size:12.5px;margin-top:2px">Le fichier GPX reste téléchargeable ci-dessous.</div>
                    </div>
                </div>
            </div>

            {{-- ═══ Profil altimétrique ═══ --}}
            {{-- Rendu serveur depuis elevation_profile ; le composant ne rend rien sans altimétrie. --}}
            {{-- distance-km : longueur RÉELLE, pour que le pas des graduations soit celui des bornes
                 kilométriques de la carte. Le dernier point du profil tombe un pas avant la fin et
                 ferait basculer le pas juste au-dessus d'un seuil. --}}
            <x-alt-profile :profile="$r->elevation_profile" :distance-km="$r->distance_km"
                           :label="'Profil altimétrique de ' . $r->name" />

            {{-- ═══ Métriques ═══ --}}
            <div class="card card-pad">
                <div class="route-metrics">
                    <div class="rm"><div class="eyebrow"><x-icon name="route" :size="12" style="color:var(--fg-muted)" /> Distance</div><div class="num" style="font-size:22px;margin-top:3px">{{ $r->distance_km !== null ? $r->distance_km.' km' : '—' }}</div></div>
                    <div class="rm"><div class="eyebrow"><x-icon name="mountain" :size="12" style="color:var(--fg-muted)" /> Dénivelé +</div><div class="num" style="font-size:22px;margin-top:3px">{{ $r->dplus_m !== null ? $r->dplus_m.' m' : '—' }}</div></div>
                    <div class="rm"><div class="eyebrow"><x-icon name="mountain" :size="12" style="color:var(--fg-muted)" /> Dénivelé −</div><div class="num" style="font-size:22px;margin-top:3px">{{ $r->dmoins_m !== null ? $r->dmoins_m.' m' : '—' }}</div></div>
                </div>

                <div class="route-metrics" style="margin-top:12px">
                    <div class="rm"><div class="eyebrow"><x-icon name="gauge" :size="12" style="color:var(--fg-muted)" /> Alt. min</div><div class="num" style="font-size:19px;margin-top:3px">{{ $r->alt_min_m !== null ? $r->alt_min_m.' m' : '—' }}</div></div>
                    <div class="rm"><div class="eyebrow"><x-icon name="mountain" :size="12" style="color:var(--fg-muted)" /> Alt. max</div><div class="num" style="font-size:19px;margin-top:3px">{{ $r->alt_max_m !== null ? $r->alt_max_m.' m' : '—' }}</div></div>
                    {{-- Relief : le libellé oriente, la valeur brute reste affichée — les seuils sont
                         relatifs au terrain du club (cf. GpxRoute::GRADE_*). --}}
                    <div class="rm">
                        <div class="eyebrow"><x-icon name="bar-chart" :size="12" style="color:var(--fg-muted)" /> Relief</div>
                        <div class="num" style="font-size:19px;margin-top:3px">{{ $r->gradeLabel() ?? '—' }}</div>
                        @if ($r->gradeIndex() !== null)
                            <div class="meta" style="font-size:12px">{{ str_replace('.', ',', (string) $r->gradeIndex()) }} m/km</div>
                        @endif
                    </div>
                </div>

                <div class="flex g6 wrap" style="margin-top:12px">
                    @if ($r->sector)<span class="chip chip-sm chip-line"><x-icon name="navigation" :size="11" /> Secteur {{ $r->sector }}</span>@endif
                    @if ($r->shapeLabel())<span class="chip chip-sm">{{ ucfirst($r->shapeLabel()) }}</span>@endif
                    @if ($r->is_loop)<span class="chip chip-sm chip-line">Boucle</span>@endif
                    @if ($r->point_count)<span class="chip chip-sm chip-line">{{ number_format($r->point_count, 0, ',', ' ') }} points</span>@endif
                </div>

                <div class="meta" style="font-size:12px;margin-top:10px">
                    Distance, dénivelés et altitudes extraits du fichier GPX (parsing côté client).
                    @if ($r->duration_min)
                        {{-- Propriété de l'ENREGISTREMENT source, pas du parcours (doc J10 §1) :
                             deux adhérents sur le même circuit n'ont pas le même temps. --}}
                        Temps de l'enregistrement d'origine : {{ intdiv($r->duration_min, 60) }} h {{ str_pad((string) ($r->duration_min % 60), 2, '0', STR_PAD_LEFT) }}.
                    @endif
                </div>
            </div>

            {{-- ═══ Description ═══ --}}
            @if (filled($r->description))
                <div class="card card-pad">
                    <div class="eyebrow" style="margin-bottom:6px">Description</div>
                    <div style="font-size:13.5px;line-height:1.6;white-space:pre-line">{{ $r->description }}</div>
                </div>
            @endif

            {{-- ═══ Téléchargement + OpenRunner ═══ --}}
            <a class="btn btn-ghost btn-block" href="{{ route('gpx-routes.gpx', $r) }}">
                <x-icon name="download" :size="15" /> Télécharger le GPX
                @if ($r->gpx_size_ko)<span class="meta" style="font-size:12px;margin-left:4px">· {{ $r->gpx_size_ko }} Ko</span>@endif
            </a>

            @if ($r->openrunner_public_url)
                <a class="btn btn-ghost btn-block" href="{{ $r->openrunner_public_url }}" target="_blank" rel="noopener noreferrer">
                    <x-icon name="arrow-up-right" :size="15" /> Ouvrir sur OpenRunner
                </a>
            @endif

            {{-- ═══ Séances liées ═══ --}}
            <div class="card" style="overflow:hidden">
                <div class="card-pad" style="padding-bottom:0">
                    <div class="eyebrow">
                        Séances utilisant ce parcours
                        @if ($sessionCount)<span class="meta" style="text-transform:none;letter-spacing:0">· {{ $sessionCount }}</span>@endif
                    </div>
                </div>
                @if ($sessions->isEmpty())
                    <div class="card-pad meta">Aucune séance n'utilise ce parcours pour l'instant.</div>
                @else
                    <div style="display:flex;flex-direction:column;gap:8px;padding:12px">
                        @foreach ($sessions as $s)
                            <x-session-card :session="$s" :tz="$tz" variant="row" />
                        @endforeach
                    </div>
                    @if ($sessionCount > $sessions->count())
                        {{-- Liste bornée à 20 : la fiche parcours n'est pas un agenda. --}}
                        <div class="card-pad meta tc" style="padding-top:0">
                            {{ $sessions->count() }} séances les plus récentes sur {{ $sessionCount }}.
                        </div>
                    @endif
                @endif
            </div>

            {{-- ═══ Zone de gestion (coach/admin) ═══ --}}
            @if ($canArchive)
                <div class="card card-pad">
                    <div class="eyebrow" style="margin-bottom:8px">Gestion</div>
                    <div class="flex g8 wrap">
                        @if ($archived)
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="restore"
                                    wire:loading.attr="disabled" wire:target="restore">
                                <x-icon name="rotate-ccw" :size="15" /> Restaurer le parcours
                            </button>
                        @else
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('confirmingArchive', true)">
                                <x-icon name="archive" :size="15" /> Archiver
                            </button>
                        @endif

                        @if ($canDelete)
                            <button type="button" class="btn btn-ghost btn-sm" style="color:var(--danger)"
                                    wire:click="$set('confirmingDelete', true)">
                                <x-icon name="trash" :size="15" /> Supprimer définitivement
                            </button>
                        @endif
                    </div>

                    @if ($sessionCountBlocksDelete)
                        {{-- Suppression impossible tant qu'une séance référence le parcours
                             (GpxRouteService::delete) : on l'explique plutôt que d'afficher un
                             bouton qui échouerait. --}}
                        <div class="meta" style="font-size:12px;margin-top:8px">
                            Suppression définitive impossible : {{ $sessionCount }} séance{{ $sessionCount > 1 ? 's' : '' }}
                            référence{{ $sessionCount > 1 ? 'nt' : '' }} ce parcours. L'archivage le retire de la bibliothèque
                            sans toucher à ces séances.
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- ═══ Confirmations ═══ --}}
    @if ($confirmingArchive)
        <x-dialog title="Archiver ce parcours ?" :sub="$r->name" close="$set('confirmingArchive', false)">
            <div style="display:flex;flex-direction:column;gap:14px">
                <x-conseq-row icon="eye-off" label="Bibliothèque" tone="warn">
                    Le parcours disparaît de la liste des adhérents. L'encadrement continue de le voir
                    en cochant « inclure les parcours archivés ».
                </x-conseq-row>
                <x-conseq-row icon="calendar" label="Séances">
                    @if ($sessionCount)
                        Les {{ $sessionCount }} séance{{ $sessionCount > 1 ? 's' : '' }} qui l'utilisent gardent leur parcours :
                        tracé, métriques et téléchargement restent accessibles depuis leur fiche.
                    @else
                        Aucune séance n'utilise ce parcours.
                    @endif
                </x-conseq-row>
                <x-conseq-row icon="rotate-ccw" label="Réversible">
                    Le fichier GPX est conservé. Restaurer le parcours le rend intégralement, carte comprise.
                </x-conseq-row>
            </div>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="$set('confirmingArchive', false)">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="archive"
                        wire:loading.attr="disabled" wire:target="archive">Archiver</button>
            </x-slot:footer>
        </x-dialog>
    @endif

    @if ($confirmingDelete)
        <x-dialog danger title="Supprimer définitivement ?" :sub="$r->name" close="$set('confirmingDelete', false)">
            <div style="display:flex;flex-direction:column;gap:14px">
                <x-conseq-row icon="trash" label="Irréversible" tone="danger">
                    Le parcours et son fichier GPX sont effacés du serveur. Aucune restauration possible.
                </x-conseq-row>
                <x-conseq-row icon="archive" label="Alternative">
                    L'archivage retire le parcours de la bibliothèque tout en conservant sa trace.
                </x-conseq-row>
            </div>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="$set('confirmingDelete', false)">Annuler</button>
                <button type="button" class="btn btn-danger" wire:click="delete"
                        wire:loading.attr="disabled" wire:target="delete">Supprimer</button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
