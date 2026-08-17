{{-- Création / édition d'un parcours de la bibliothèque (PRD §4.20).
     Aucun screen-*.jsx de référence pour cet écran (écart assumé, doc J10 §6) : composé par
     analogie avec session-form.blade.php, dont il reprend les classes telles quelles
     (.form-screen, .dk-topbar, .form-card, .ifield, .gpx-drop/.gpx-loaded/.gpx-meta-grid). --}}
@php
    $edit = $gpxRoute?->exists;
@endphp
<div class="form-screen">
    {{-- flash('status') = succès (vert) · flash('warn') = refus (orange) — à la racine de l'écran. --}}
    <x-flash-float />

    <x-topbar :title="$edit ? 'Modifier le parcours' : 'Nouveau parcours'"
              :sub="$edit ? $gpxRoute->name : 'Bibliothèque de parcours'"
              :back="route('gpx-routes.index')"
              back-label="Retour parcours" />

    <div class="dk-topbar">
        <a href="{{ route('gpx-routes.index') }}" class="btn btn-ghost btn-sm"
           onclick="return !window.clubBack?.()">
            <x-icon name="chevron-left" :size="15" /> Parcours
        </a>
        <div class="f1">
            <div class="dsp" style="font-size:24px">{{ $edit ? 'Modifier le parcours' : 'Nouveau parcours' }}</div>
            <div class="meta">{{ $edit ? $gpxRoute->name : 'Trace réutilisable par plusieurs séances' }}</div>
        </div>
        <button type="submit" form="gpx-route-form" class="btn btn-primary btn-sm"
                wire:loading.attr="disabled" wire:target="save">{{ $edit ? 'Enregistrer' : 'Créer' }}</button>
    </div>

    <div class="dk-body">
        <form id="gpx-route-form" wire:submit="save" class="form-grid">
            <div class="form-main">
                <div class="form-card">
                    <div class="sect-head"><span class="sect-title">Le parcours</span></div>

                    <div class="fgrid">
                        <div>
                            <label class="field-label">Nom<x-req-mark /></label>
                            <div class="ifield @error('name') is-error @enderror">
                                <input class="ifield-input" type="text" wire:model="name" placeholder="Ex. Boucle Loire 42 km">
                            </div>
                            @error('name')<div class="field-error">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="field-label">Discipline</label>
                            <div class="ifield @error('discipline_id') is-error @enderror">
                                <select class="ifield-input" wire:model="discipline_id">
                                    <option value="">— Aucune —</option>
                                    @foreach ($disciplines as $d)
                                        <option value="{{ $d->id }}">{{ $d->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @error('discipline_id')<div class="field-error">{{ $message }}</div>@enderror
                        </div>

                        <div>
                            <label class="field-label">Point de départ</label>
                            <div class="ifield @error('start_location_id') is-error @enderror">
                                <select class="ifield-input" wire:model="start_location_id">
                                    <option value="">— Non précisé —</option>
                                    @foreach ($locations as $l)
                                        <option value="{{ $l->id }}">{{ $l->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @error('start_location_id')<div class="field-error">{{ $message }}</div>@enderror
                        </div>

                        <div style="grid-column:1/-1">
                            <label class="field-label">Description <span class="meta" style="text-transform:none;letter-spacing:0;font-weight:400">· optionnel</span></label>
                            <div class="ifield @error('description') is-error @enderror">
                                <textarea class="ifield-input" rows="3" wire:model="description" placeholder="Revêtement, points d'eau, passages délicats…"></textarea>
                            </div>
                            @error('description')<div class="field-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="form-card">
                    <div class="sect-head"><span class="sect-title">Trace GPX</span></div>

                    {{-- Doublon détecté au dépôt (§1) : on SIGNALE, on ne bloque pas définitivement.
                         La détection ne couvre que les fichiers binairement identiques. --}}
                    @if ($duplicateId && ! $duplicateAcknowledged)
                        <x-banner kind="info">
                            <div>
                                Ce GPX existe déjà dans la bibliothèque : <b>{{ $duplicateName }}</b>.
                                <div class="flex ac g8" style="margin-top:8px">
                                    <a class="btn btn-ghost btn-sm" href="{{ route('gpx-routes.edit', $duplicateId) }}" wire:navigate>Utiliser ce parcours</a>
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="acknowledgeDuplicate">Créer quand même</button>
                                </div>
                            </div>
                        </x-banner>
                    @endif

                    <div>
                        <label class="field-label">
                            Fichier GPX
                            @if (! $edit)<x-req-mark />@endif
                            <span class="meta" style="text-transform:none;letter-spacing:0;font-weight:400">
                                · ≤ 5 Mo{{ $edit ? ' · déposer un fichier remplace la trace actuelle' : '' }}
                            </span>
                        </label>
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
                                    <div class="meta" style="font-size:12px">ou clique pour parcourir · max 5 Mo · distance, dénivelé et direction extraits automatiquement</div>
                                </button>
                            </template>
                            <div class="meta" style="font-size:12px;margin-top:6px;color:var(--danger)" x-show="error" x-text="error" x-cloak></div>
                        </div>
                        <div wire:loading wire:target="gpxFile" class="meta" style="font-size:12px;margin-top:6px">Envoi du fichier…</div>
                        @error('gpxFile')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div class="form-side">
                <div class="form-card">
                    <div class="sect-head"><span class="sect-title">OpenRunner</span></div>

                    <div>
                        <label class="field-label">Lien d'embed <span class="meta" style="text-transform:none;letter-spacing:0;font-weight:400">· optionnel</span></label>
                        <div class="ifield @error('openrunner_embed_url') is-error @enderror">
                            <input class="ifield-input" type="text" wire:model="openrunner_embed_url" placeholder="https://www.openrunner.com/embed.html?code=…">
                        </div>
                        @error('openrunner_embed_url')<div class="field-error">{{ $message }}</div>@enderror
                    </div>

                    <div style="margin-top:12px">
                        <label class="field-label">Lien public <span class="meta" style="text-transform:none;letter-spacing:0;font-weight:400">· optionnel</span></label>
                        <div class="ifield @error('openrunner_public_url') is-error @enderror">
                            <input class="ifield-input" type="text" wire:model="openrunner_public_url" placeholder="https://www.openrunner.com/route-details/…">
                        </div>
                        @error('openrunner_public_url')<div class="field-error">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            {{-- Barre collante mobile : le CTA desktop vit dans .dk-topbar. --}}
            <div class="form-actions">
                <a class="btn btn-ghost" href="{{ route('gpx-routes.index') }}" wire:navigate>Annuler</a>
                <button type="submit" class="btn btn-primary f1"
                        wire:loading.attr="disabled" wire:target="save">{{ $edit ? 'Enregistrer' : 'Créer le parcours' }}</button>
            </div>
        </form>
    </div>
</div>
