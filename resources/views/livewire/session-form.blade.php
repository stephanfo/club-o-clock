@php
    $edit = $session?->exists;
    $titleWord = ['training' => 'la séance', 'competition' => 'la compétition', 'club_event' => "l'événement"][$kind];
    $kindOpts = [
        ['k' => 'training', 'l' => 'Entraînement', 'icon' => 'zap', 'sub' => 'tag · waitlist · quota'],
        ['k' => 'competition', 'l' => 'Compétition', 'icon' => 'trophy', 'sub' => 'type · distance · lien'],
        ['k' => 'club_event', 'l' => 'Événement', 'icon' => 'flag', 'sub' => 'agenda · lien'],
    ];
    $enc = [
        'training' => ['sect' => 'Encadrement', 'sel' => 'Coachs sélectionnés'],
        'competition' => ['sect' => 'Accompagnement', 'sel' => 'Accompagnateurs sélectionnés'],
        'club_event' => ['sect' => 'Organisation', 'sel' => 'Organisateurs sélectionnés'],
    ][$kind];
    $isTraining = $kind === 'training';
    $selectedCoaches = $coaches->whereIn('id', $coach_ids);
    // Qualifs agrégées des encadrants sélectionnés (déduplication par qualif — §4.11.4), recalculées
    // à chaque re-rendu Livewire → mise à jour temps réel au fil des toggleCoach.
    $selectedQualifs = \App\Support\QualificationDisplay::aggregate($selectedCoaches->values());
@endphp
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar verte fixe (mobile) — couvre la safe-area iOS ; le dk-topbar était pensé
         desktop et remontait sous la barre de statut. Le CTA Publier/Enregistrer vit dans la
         barre collante du bas (.form-actions) sur mobile, il n'est donc pas repris ici. --}}
    {{-- Chevron en ÉDITION seulement : on y vient toujours d'une fiche précise, le retour a donc un
         sens. La création est une entrée de navigation permanente (sidebar + bottom-nav) qu'on
         atteint depuis n'importe où — aucune destination de retour n'est « la bonne », et les autres
         écrans de nav (Planning, Parcours, Infos) n'en ont pas non plus. La sortie explicite est le
         bouton Annuler du formulaire. --}}
    <x-topbar :title="$edit ? 'Modifier '.$titleWord : 'Créer '.$titleWord"
              :sub="$edit ? $session->title.' · édition isolée' : 'Création autonome · pas un modèle'"
              :back="$edit ? route('sessions.show', $session) : null"
              back-label="Retour fiche" />
    {{-- ─── Topbar desktop (porté de screen-creation.jsx CreationDesktop) — masquée sur mobile. --}}
    <div class="dk-topbar">
        {{-- Cf. topbar mobile : pas de retour en création, c'est un écran de navigation. --}}
        @if ($edit)
            <a href="{{ route('sessions.show', $session) }}" class="btn btn-ghost btn-sm"
               onclick="return !window.clubBack?.()">
                <x-icon name="chevron-left" :size="15" /> Fiche
            </a>
        @endif
        <div class="f1">
            <div class="dsp" style="font-size:24px">{{ $edit ? 'Modifier '.$titleWord : 'Créer '.$titleWord }}</div>
            <div class="meta">{{ $edit ? $session->title.' · édition isolée' : 'Création autonome · pas un modèle' }}</div>
        </div>
        <button type="submit" form="session-form" class="btn btn-primary btn-sm">{{ $edit ? 'Enregistrer' : 'Publier' }}</button>
    </div>

    <div class="dk-body">
        {{-- Scroll auto vers le 1er champ fautif après un échec de validation serveur : Livewire
             re-rend le composant (event `livewire:updated` bulle jusqu'au form), on cible alors le
             premier .is-error / .field-error. Le flag évite de re-scroller à chaque re-render. --}}
        <form id="session-form" wire:submit="save" class="form-grid"
              x-data="{ scrolled: false }"
              x-on:livewire:updated="$nextTick(() => {
                  const el = $el.querySelector('.is-error, .field-error');
                  if (el && !scrolled) { el.scrollIntoView({ behavior: 'smooth', block: 'center' }); scrolled = true; }
                  else if (!el) { scrolled = false; }
              })">
            @if ($errors->any())
                <x-banner kind="danger" style="grid-column:1/-1;margin-bottom:var(--space-2)">
                    <div style="font-weight:700;margin-bottom:4px">Formulaire incomplet — corrige les champs signalés :</div>
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </x-banner>
            @endif

            {{-- Édition isolée + rappel des champs structurants (§4.7). --}}
            @if ($edit)
                <x-banner kind="info" style="grid-column:1/-1">
                    <div><b>Édition isolée</b> — les modifications ne touchent que cette séance. Les champs marqués <x-icon name="bell" :size="11" style="color:var(--accent);vertical-align:middle" /> sont <b>structurants</b> : les modifier proposera de notifier les inscrits.</div>
                </x-banner>
            @endif

            {{-- ═══ Colonne gauche ═══ --}}
            <div class="form-main">
                <div class="meta" style="text-transform:none;letter-spacing:0"><span class="req-mark">*</span> champ obligatoire</div>
                {{-- Type — 3 cartes --}}
                <div>
                    <label class="field-label">Type</label>
                    <div class="kind-cards">
                        @foreach ($kindOpts as $o)
                            <button type="button" wire:click="setKind('{{ $o['k'] }}')"
                                class="kind-card{{ $kind === $o['k'] ? ' is-active' : '' }}">
                                <x-icon name="{{ $o['icon'] }}" :size="20" />
                                <div class="kind-card-l">{{ $o['l'] }}</div>
                                @if ($kind === $o['k'])<div class="meta kind-card-sub">+ {{ $o['sub'] }}</div>@endif
                            </button>
                        @endforeach
                    </div>
                </div>

                {{-- Discipline — chips à puces (training only) --}}
                @if ($isTraining)
                    <div>
                        <label class="field-label">Discipline<x-req-mark /></label>
                        <div class="flex g6 wrap">
                            @foreach ($disciplines as $d)
                                <button type="button" wire:click="$set('discipline_id', {{ $d->id }})"
                                    class="chip{{ $discipline_id === $d->id ? ' is-active' : '' }}">
                                    <span class="dot dot-{{ $d->colorClass() }}"></span> {{ $d->label }}
                                </button>
                            @endforeach
                        </div>
                        @error('discipline_id')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                @endif

                {{-- Type d'épreuve + distance (competition only) --}}
                @if ($kind === 'competition')
                    <div class="form-row2">
                        <div>
                            <label class="field-label">Type d'épreuve<x-req-mark /></label>
                            <div class="flex g6 wrap">
                                @foreach ($eventTypes as $et)
                                    <button type="button" wire:click="$set('event_type_id', {{ $et->id }})"
                                        class="chip{{ $event_type_id === $et->id ? ' is-active' : '' }}">{{ $et->label }}</button>
                                @endforeach
                            </div>
                            @error('event_type_id')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label class="field-label">Distance</label>
                            <div class="ifield @error('distance') is-error @enderror"><input class="ifield-input" type="text" wire:model="distance" placeholder="ex. 750 m / 20 km / 5 km"></div>
                            @error('distance')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                @endif

                {{-- Titre + capacité --}}
                <div class="form-row-title">
                    <div>
                        <label class="field-label">Titre<x-req-mark /></label>
                        <div class="ifield @error('title') is-error @enderror"><input class="ifield-input" type="text" wire:model="title" placeholder="Ex. Natation seuil"></div>
                        @error('title')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label class="field-label">Capacité{{ $isTraining ? '' : ' (optionnelle)' }}<x-struct-tag :show="$edit" /></label>
                        <div class="ifield @error('capacity') is-error @enderror"><input class="ifield-input" type="number" min="1" wire:model="capacity" placeholder="16"></div>
                        @error('capacity')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>

                {{-- Date / début / durée --}}
                <div class="form-row2">
                    <div>
                        <label class="field-label">Date et heure<x-req-mark /><x-struct-tag :show="$edit" /></label>
                        <div class="ifield @error('start_at') is-error @enderror"><input class="ifield-input" type="datetime-local" wire:model="start_at"></div>
                        @error('start_at')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    <div>
                        <label class="field-label">Durée (min)<x-req-mark /><x-struct-tag :show="$edit" /></label>
                        <div class="ifield @error('duration_min') is-error @enderror"><input class="ifield-input" type="number" min="1" wire:model="duration_min"></div>
                        @error('duration_min')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>

                {{-- Lieu + tag quota --}}
                <div class="{{ $isTraining ? 'form-row2' : '' }}">
                    <div>
                        <label class="field-label">Lieu<x-struct-tag :show="$edit" /></label>
                        <select class="input" wire:model="location_id">
                            <option value="">—</option>
                            @foreach ($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach
                        </select>
                    </div>
                    @if ($isTraining)
                        <div>
                            <label class="field-label">Tag quota<x-struct-tag :show="$edit" /></label>
                            <select class="input" wire:model="quota_tag_id">
                                <option value="">Aucun</option>
                                @foreach ($quotaTags as $tag)<option value="{{ $tag->id }}">{{ $tag->label }}</option>@endforeach
                            </select>
                        </div>
                    @endif
                </div>

                {{-- Lieu libre (optionnel) --}}
                <div>
                    <label class="field-label">Lieu libre (optionnel)</label>
                    <div class="ifield"><input class="ifield-input" type="text" wire:model="location_text" placeholder="…ou précise une adresse"></div>
                </div>

                {{-- Lien externe (competition & club_event) --}}
                @if (!$isTraining)
                    <div>
                        <label class="field-label">{{ $kind === 'competition' ? 'Lien organisateur' : 'Lien (optionnel)' }}</label>
                        <div class="ifield @error('external_url') is-error @enderror"><x-icon name="link" :size="15" style="color:var(--info)" /><input class="ifield-input" type="url" wire:model="external_url" placeholder="https://…"></div>
                        @error('external_url')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                    {{-- Album photos externe (§4.12.6) — simple lien, aucune intégration --}}
                    <div>
                        <label class="field-label">Album photos <span class="meta" style="text-transform:none;letter-spacing:0;font-weight:400">· optionnel</span></label>
                        <div class="ifield @error('photos_album_url') is-error @enderror"><x-icon name="image" :size="15" style="color:var(--info)" /><input class="ifield-input" type="url" wire:model="photos_album_url" placeholder="https://photos.google.com/…"></div>
                        @error('photos_album_url')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                @endif

                {{-- Catégories ciblées — chips toggle --}}
                <div>
                    <label class="field-label">Catégories ciblées<x-struct-tag :show="$edit" /></label>
                    <div class="flex g6 wrap">
                        @foreach ($categories as $cat)
                            <button type="button" wire:click="toggleCategory({{ $cat->id }})"
                                class="chip{{ in_array($cat->id, $category_ids) ? ' is-active' : '' }}">{{ $cat->label }}</button>
                        @endforeach
                    </div>
                </div>

                {{-- Contenu (training) / Agenda (club_event) --}}
                @if ($isTraining)
                    <div>
                        <div class="sect-head"><span class="sect-title">Contenu</span></div>
                        <x-wysiwyg model="content_markdown" :markdown="$content_markdown"
                                   placeholder="Échauffement, séries, récup… (mise en forme avec la barre d'outils)" />
                    </div>
                @elseif ($kind === 'club_event')
                    <div>
                        <div class="sect-head"><span class="sect-title">Agenda</span></div>
                        <x-wysiwyg model="agenda" :markdown="$agenda"
                                   placeholder="10:00 · Accueil — 10:30 · …" />
                    </div>
                @endif

                {{-- Parcours OpenRunner (toutes séances, optionnel — §4.13.1). Validation symétrique
                     client (feedback) ; serveur fait foi. Porté de screen-creation.jsx ParcoursFields. --}}
                <div x-data="{
                        embed: @js($route_openrunner_embed_url),
                        valid: true,
                        check() {
                            const v = (this.embed || '').trim();
                            this.valid = v === '' || (/^https:\/\/www\.openrunner\.com\/embed\.html\?/i.test(v) && /[?&]code=[^&]+/.test(v));
                        }
                     }" x-init="check()">
                    <div class="sect-head"><span class="sect-title">Parcours</span><span class="meta mlauto" style="font-size:11px">optionnel</span></div>
                    <div style="display:flex;flex-direction:column;gap:14px">
                        <div>
                            <label class="field-label">Lien d'embed OpenRunner <span class="meta" style="text-transform:none;letter-spacing:0;font-weight:400">· URL <code style="font-family:monospace;font-size:12px">src</code></span></label>
                            <div class="ifield" :style="valid ? '' : 'border-color:var(--danger)'">
                                <x-icon name="map" :size="15" ::style="valid ? 'color:var(--brand-700)' : 'color:var(--danger)'" style="flex:0 0 auto" />
                                <input class="ifield-input" type="text" wire:model="route_openrunner_embed_url"
                                       x-on:input="embed = $event.target.value; check()"
                                       placeholder="https://www.openrunner.com/embed.html?code=…">
                            </div>
                            <div class="meta" style="font-size:12px;margin-top:6px" x-show="valid">
                                Sur OpenRunner Pro : <b>Partager &gt; Embed</b>, copie l'URL <code style="font-family:monospace;font-size:11.5px">src</code> de l'iframe (elle contient <code style="font-family:monospace;font-size:11.5px">/embed.html?code=…</code>).
                            </div>
                            <div class="meta" style="font-size:12px;margin-top:6px;color:var(--danger)" x-show="!valid" x-cloak>
                                Lien d'embed invalide — colle l'URL <code style="font-family:monospace;font-size:11.5px">src</code> issue de la fonctionnalité <b>Embed</b> d'OR Pro.
                            </div>
                            @error('route_openrunner_embed_url')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                        <div>
                            <label class="field-label">Lien public OpenRunner <span class="meta" style="text-transform:none;letter-spacing:0;font-weight:400">· optionnel</span></label>
                            <div class="ifield"><x-icon name="link" :size="15" style="color:var(--info);flex:0 0 auto" /><input class="ifield-input" type="url" wire:model="route_openrunner_public_url" placeholder="https://www.openrunner.com/r/…"></div>
                            @error('route_openrunner_public_url')<div class="field-error">{{ $message }}</div>@enderror
                        </div>

                        {{-- Sélecteur de parcours existant (§4.20, J10.B). Placé AVANT la dropzone :
                             réutiliser une trace de la bibliothèque est le geste à privilégier,
                             l'upload direct reste possible en dessous. --}}
                        <div>
                            <label class="field-label">Parcours de la bibliothèque <span class="meta" style="text-transform:none;letter-spacing:0;font-weight:400">· optionnel</span></label>
                            @if ($route_id && ! $gpxFile)
                                @php($picked = \App\Models\GpxRoute::with('discipline')->find($route_id))
                                @if ($picked)
                                    <div class="card card-pad flex ac g10">
                                        <x-disc-badge :discipline="$picked->discipline" :size="30" />
                                        <div class="f1" style="min-width:0">
                                            <div style="font-weight:700;font-size:14px">{{ $picked->name }}</div>
                                            <div class="meta" style="font-size:12.5px">
                                                {{ $picked->discipline?->label ?? 'Parcours' }}@if ($picked->distance_km) · {{ $picked->distance_km }} km @endif @if ($picked->gradeLabel()) · {{ $picked->gradeLabel() }} @endif
                                            </div>
                                        </div>
                                        <button type="button" class="iconbtn" wire:click="removeGpx" wire:loading.attr="disabled" wire:target="removeGpx"
                                                aria-label="Détacher ce parcours"><x-icon name="x" /></button>
                                    </div>
                                @endif
                            @else
                                <button type="button" class="btn btn-ghost btn-block" wire:click="toggleRoutePicker"
                                        wire:loading.attr="disabled" wire:target="toggleRoutePicker">
                                    <x-icon name="search" :size="15" /> Choisir un parcours existant
                                </button>
                            @endif

                            @if ($showRoutePicker && ! $route_id)
                                <div class="card" style="margin-top:8px;overflow:hidden">
                                    <div class="input flex ac g8" style="margin:10px">
                                        <x-icon name="search" :size="15" style="color:var(--fg-muted)" />
                                        <input type="text" wire:model.live.debounce.300ms="routeSearch" placeholder="Rechercher un parcours…"
                                               style="border:none;background:none;outline:none;font:inherit;color:inherit;width:100%" />
                                    </div>
                                    @php($candidates = $this->routeCandidates())
                                    @if ($candidates->isEmpty())
                                        <div class="meta tc" style="padding:16px">Aucun parcours ne correspond.</div>
                                    @else
                                        <div style="max-height:280px;overflow-y:auto">
                                            @foreach ($candidates as $cand)
                                                <button type="button" wire:click="pickRoute({{ $cand->id }})" wire:key="cand-{{ $cand->id }}"
                                                        wire:loading.attr="disabled" wire:target="pickRoute({{ $cand->id }})"
                                                        class="flex ac g10" style="width:100%;text-align:left;border:none;background:none;cursor:pointer;padding:10px 12px;border-top:1px solid var(--divider);font:inherit;color:inherit">
                                                    <x-disc-badge :discipline="$cand->discipline" :size="26" />
                                                    <div class="f1" style="min-width:0">
                                                        <div style="font-weight:600;font-size:13.5px">{{ $cand->name }}</div>
                                                        <div class="meta" style="font-size:11.5px">
                                                            {{ $cand->discipline?->label ?? 'Parcours' }}@if ($cand->distance_km) · {{ $cand->distance_km }} km @endif @if ($cand->sector) · {{ $cand->sector }} @endif
                                                        </div>
                                                    </div>
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- Fichier GPX (§4.13.2) — parsing CLIENT, fichier brut uploadé. --}}
                        <div>
                            <label class="field-label">Fichier GPX <span class="meta" style="text-transform:none;letter-spacing:0;font-weight:400">· optionnel · ≤ 5 Mo</span></label>
                            <div wire:ignore x-data="gpxField({ stats: @js($gpxStats) })">
                                <input type="file" accept=".gpx" x-ref="file" wire:model="gpxFile" x-on:change="onPick($event)" hidden>
                                <template x-if="meta">
                                    <div class="gpx-loaded">
                                        <div class="flex ac g10">
                                            <x-icon name="route" :size="18" style="color:var(--brand-700);flex:0 0 auto" />
                                            <div class="f1" style="min-width:0">
                                                <div style="font-weight:700;font-size:13.5px" x-text="meta.name"></div>
                                                <div class="meta" style="font-size:11.5px"><span x-text="meta.sizeKo"></span> Ko · analysé côté client</div>
                                            </div>
                                            <button type="button" class="iconbtn" x-on:click="remove()" aria-label="Retirer le GPX"><x-icon name="x" /></button>
                                        </div>
                                        <div class="gpx-meta-grid">
                                            <div class="gm"><div class="l">Distance</div><div class="v"><span x-text="meta.distanceKm ?? '—'"></span> km</div></div>
                                            <div class="gm"><div class="l">D+</div><div class="v"><span x-text="meta.dplus ?? '—'"></span> m</div></div>
                                            <div class="gm"><div class="l">D−</div><div class="v"><span x-text="meta.dmoins ?? '—'"></span> m</div></div>
                                            <div class="gm"><div class="l">Alt min</div><div class="v"><span x-text="meta.altMin ?? '—'"></span> m</div></div>
                                            <div class="gm"><div class="l">Alt max</div><div class="v"><span x-text="meta.altMax ?? '—'"></span> m</div></div>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="!meta">
                                    <button type="button" class="gpx-drop" :class="drag ? 'is-drag' : ''" x-on:click="$refs.file.click()"
                                            x-on:dragover.prevent="drag = true" x-on:dragleave="drag = false" x-on:drop.prevent="onDrop($event)">
                                        <x-icon name="file-up" :size="26" class="gpx-ic" />
                                        <div style="font-weight:700;font-size:14px">Glisse un fichier GPX ici</div>
                                        <div class="meta" style="font-size:12px">ou clique pour parcourir · max 5 Mo · distance &amp; dénivelé extraits automatiquement</div>
                                    </button>
                                </template>
                                <div class="meta" style="font-size:12px;margin-top:6px;color:var(--danger)" x-show="error" x-text="error" x-cloak></div>
                            </div>
                            <div wire:loading wire:target="gpxFile" class="meta" style="font-size:12px;margin-top:6px">Envoi du fichier…</div>
                            @error('gpxFile')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══ Colonne droite ═══ --}}
            <div class="form-side">
                {{-- Encadrement --}}
                <div>
                <div class="sect-head"><span class="sect-title">{{ $enc['sect'] }}</span></div>
                <div class="card card-pad">
                    <label class="field-label">{{ $enc['sel'] }}</label>
                    <div class="flex g6 wrap">
                        @forelse ($selectedCoaches as $coach)
                            <button type="button" wire:click="toggleCoach({{ $coach->id }})" class="chip chip-ink">
                                {{ $coach->first_name }} {{ $coach->last_name }} <x-icon name="x" :size="12" style="display:inline;vertical-align:-1px" />
                            </button>
                        @empty
                            <span class="meta" style="font-size:var(--text-sm)">Aucun encadrant sélectionné.</span>
                        @endforelse
                    </div>
                    @if ($coaches->whereNotIn('id', $coach_ids)->isNotEmpty())
                        <label class="field-label" style="margin-top:var(--space-3)">Ajouter</label>
                        <div class="flex g6 wrap">
                            @foreach ($coaches->whereNotIn('id', $coach_ids) as $coach)
                                <button type="button" wire:click="toggleCoach({{ $coach->id }})" class="chip chip-line" style="border-style:dashed">
                                    + {{ $coach->first_name }} {{ $coach->last_name }}
                                </button>
                            @endforeach
                        </div>
                    @endif

                    {{-- Qualifications combinées des encadrants sélectionnés (§4.11.4). Temps réel :
                         recalculé à chaque toggleCoach. Training uniquement (comme la fiche). --}}
                    @if ($isTraining && $selectedCoaches->isNotEmpty())
                        <div x-data="{ open: null }" style="margin-top:var(--space-3)">
                            <label class="field-label flex ac">
                                Qualifications combinées
                                @if ($selectedQualifs->isNotEmpty())<span class="meta mlauto" style="text-transform:none;letter-spacing:0;font-weight:400">Touche une qualif pour le détail</span>@endif
                            </label>
                            @if ($selectedQualifs->isEmpty())
                                <div class="meta" style="font-size:var(--text-sm)">Aucune qualification renseignée par les encadrants sélectionnés.</div>
                            @else
                                <div class="flex g6 wrap">
                                    @foreach ($selectedQualifs as $agg)
                                        <button type="button" class="chip {{ \App\Support\QualificationDisplay::clsFor($agg['worst']) }}"
                                                x-on:click="open = (open === {{ $agg['id'] }} ? null : {{ $agg['id'] }})">
                                            {{ $agg['code'] ?? $agg['label'] }}
                                        </button>
                                    @endforeach
                                </div>
                                {{-- Détail au tap : coachs porteurs + badge d'expiration éventuel. --}}
                                @foreach ($selectedQualifs as $agg)
                                    <div x-show="open === {{ $agg['id'] }}" x-cloak style="margin-top:10px;display:flex;flex-direction:column;gap:6px">
                                        <div class="eyebrow">{{ $agg['label'] }}</div>
                                        @foreach ($agg['holders'] as $h)
                                            <div class="flex ac g6">
                                                <span style="font-size:var(--text-sm)">{{ $h['name'] }}</span>
                                                @if ($h['status']['status'] === 'expired')
                                                    <span class="chip chip-sm chip-danger">expirée</span>
                                                @elseif ($h['status']['status'] === 'soon')
                                                    <span class="chip chip-sm chip-warn">expire bientôt</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endif
                </div>
                </div>
            </div>

            {{-- Barre d'action mobile (collante) --}}
            <div class="form-actions">
                <a href="{{ $edit ? route('sessions.show', $session) : route('planning') }}" class="btn btn-ghost f1">Annuler</a>
                <button type="submit" class="btn btn-primary" style="flex:2">{{ $edit ? 'Enregistrer' : 'Publier' }}</button>
            </div>
        </form>
    </div>

    {{-- Dialog de confirmation à la sauvegarde (§4.7) : 3 issues + envoi prioritaire. --}}
    @if ($showSaveDialog)
        <x-dialog title="Notifier les inscrits ?" sub="Un champ structurant a changé" :width="460" close="dismissSaveDialog">
            <x-banner kind="info">
                <div>Tu as modifié un <b>champ structurant</b> (date, horaire, lieu, capacité, tag quota ou catégories). Les <b>inscrits</b> de cette séance peuvent être prévenus (push + email).</div>
            </x-banner>

            <div class="card card-pad card-soft" style="margin:12px 0;display:flex;flex-direction:column;gap:8px">
                <div class="eyebrow">Changements détectés</div>
                @foreach ($pendingChanges as $c)
                    <div class="flex ac g8" style="font-size:13px">
                        <span class="meta" style="width:96px;flex:0 0 auto">{{ $c['label'] }}</span>
                        <span class="strike">{{ $c['before'] }}</span>
                        <x-icon name="arrow-right" :size="14" class="muted" style="flex:0 0 auto" />
                        <b>{{ $c['after'] }}</b>
                    </div>
                @endforeach
            </div>

            <label class="flex ac g8" style="cursor:pointer">
                <x-check :on="$notify_priority" wire:click="$toggle('notify_priority')" />
                <span style="font-size:13px"><b>Envoi prioritaire</b> — pousser tout de suite, sans attendre l'envoi groupé.</span>
            </label>

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="saveSilently">Sauvegarder en silence</button>
                <button type="button" class="btn btn-primary" wire:click="saveAndNotify">
                    <x-icon name="bell" :size="15" /> Oui — push + email
                </button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
