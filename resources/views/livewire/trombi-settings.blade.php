{{-- Réglages du trombinoscope (#72, PRD §4.23.6). Profil (sujet = soi) ou page d'un enfant (sujet =
     l'enfant, réglé par son parent). Classes du design : sect-head, card, toggle, input, btn, meta. --}}
@php
    $who = $self ? 'toi' : $subject->first_name;
    $masked = $subject->photo_path !== null && ! $subject->hasConsent(\App\Support\Trombi\ConsentType::Photo);
@endphp
<div style="display:flex;flex-direction:column;gap:16px">
    <x-flash-float />

    <div class="meta" style="font-size:13px;line-height:1.45">
        Le trombinoscope montre les adhérents de la saison aux autres adhérents (et aux parents d'enfants
        adhérents). Rien n'est partagé sans accord, chaque accord se retire à tout moment, sans conséquence.
    </div>

    {{-- Aperçu --}}
    <div>
        <div class="sect-head"><span class="sect-title">Ce que voient les adhérents</span></div>
        <div style="max-width:220px">
            @include('livewire.partials.trombi-card', ['card' => $preview, 'href' => null])
        </div>
    </div>

    {{-- Photo --}}
    <div>
        <div class="sect-head"><span class="sect-title">Photo</span></div>
        <div class="card card-pad flex ac g10" style="gap:14px" x-data="photoCrop()">
            <x-avatar :name="$subject->fullName()" size="lg" tint="tint-run" :photo="$ownPhoto" />
            <div class="f1" style="min-width:0">
                @if ($subject->photo_path === null)
                    <div class="meta" style="font-size:12.5px">Aucune photo. JPEG, PNG ou WebP, directement depuis ta pellicule : tu la cadres avant l'envoi, et elle part sans ses métadonnées (lieu de prise de vue compris).</div>
                @elseif ($masked)
                    <div class="meta" style="font-size:12.5px">Masquée : visible de {{ $self ? 'toi' : 'toi et '.$subject->first_name }} seulement. Active l'accord « Photo » pour la montrer.</div>
                @else
                    <div class="meta" style="font-size:12.5px">Visible des adhérents.</div>
                @endif
                <div class="flex g6 wrap" style="margin-top:8px">
                    @if ($canEdit)
                        {{-- Pas de wire:model : ce qui part, après « Valider » dans le dialog de recadrage
                             (resources/js/photo-crop.js), est le carré cadré, réduit par le navigateur. --}}
                        <label class="btn btn-ghost btn-sm" style="cursor:pointer">
                            <input type="file" accept="image/jpeg,image/png,image/webp" x-on:change="choose($event)" hidden>
                            <x-icon name="upload" :size="14" /> {{ $subject->photo_path ? 'Changer' : 'Choisir une photo' }}
                        </label>
                    @endif
                    @if ($subject->photo_path)
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="deletePhoto"
                                wire:confirm="Supprimer définitivement la photo ?" wire:loading.attr="disabled" wire:target="deletePhoto">
                            <x-icon name="trash" :size="14" /> Supprimer
                        </button>
                    @endif
                </div>
                <div class="meta" style="color:var(--danger);font-size:12px;margin-top:6px" x-show="error && !open" x-text="error" x-cloak></div>
                @error('photo') <div class="meta" style="color:var(--danger);font-size:12px;margin-top:6px">{{ $message }}</div> @enderror
            </div>

            {{-- Dialog de recadrage : mêmes classes que <x-dialog>, piloté par Alpine (rien ne part
                 au serveur avant « Valider »). Glisser pour déplacer, curseur / molette / pincement
                 pour zoomer, quart de tour horaire. --}}
            <template x-if="open">
                <div class="scrim" x-on:click="close()">
                    <div class="dialog" style="max-width:380px" x-on:click.stop role="dialog" aria-modal="true" aria-labelledby="crop-title-{{ $this->getId() }}">
                        <div class="dialog-head">
                            <div class="flex ac jb g8">
                                <div class="dialog-title" id="crop-title-{{ $this->getId() }}">Cadrer la photo</div>
                                <button type="button" class="iconbtn" aria-label="Fermer" x-on:click="close()" :disabled="busy"><x-icon name="x" /></button>
                            </div>
                            <div class="dialog-sub">Ce que montre le cercle est ce que verront les adhérents.</div>
                        </div>
                        <div class="dialog-body" style="display:flex;flex-direction:column;gap:12px">
                            <div class="crop-stage" x-ref="stage"
                                 x-on:pointerdown.prevent="down($event)" x-on:pointermove="move($event)"
                                 x-on:pointerup="up($event)" x-on:pointercancel="up($event)"
                                 x-on:wheel.prevent="wheel($event)">
                                <img class="crop-img" x-ref="img" :src="src" alt="" draggable="false" :style="imgStyle"
                                     x-on:load="loaded($el)" x-on:error="unreadable()">
                                <div class="crop-mask" aria-hidden="true"></div>
                            </div>
                            <div class="flex ac g10">
                                <x-icon name="minus" :size="16" style="color:var(--fg-muted);flex:0 0 auto" />
                                <input type="range" class="zone-range f1" min="1" max="4" step="0.01" aria-label="Zoom"
                                       :value="zoom" x-on:input="setZoom($event.target.value)" :disabled="busy">
                                <x-icon name="plus" :size="16" style="color:var(--fg-muted);flex:0 0 auto" />
                                <button type="button" class="iconbtn" aria-label="Pivoter d'un quart de tour" title="Pivoter" x-on:click="rotate()" :disabled="busy">
                                    <x-icon name="rotate-ccw" :size="18" style="transform:scaleX(-1)" />
                                </button>
                            </div>
                            <div class="meta" style="font-size:12px" x-show="busy" x-text="'Envoi en cours… ' + progress + ' %'"></div>
                            <div class="meta" style="color:var(--danger);font-size:12px" x-show="error" x-text="error"></div>
                        </div>
                        <div class="dialog-foot">
                            <button type="button" class="btn btn-ghost" x-on:click="close()" :disabled="busy">Annuler</button>
                            <button type="button" class="btn btn-primary" x-on:click="confirm()" :disabled="busy || !nw">Valider</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- Téléphone : majeurs seulement (§4.23.4) --}}
    @unless ($minor)
        <div>
            <div class="sect-head"><span class="sect-title">Téléphone</span></div>
            <div class="card card-pad" style="display:flex;flex-direction:column;gap:8px">
                <label class="field-label" for="trombi-phone-{{ $subject->id }}">Numéro à partager</label>
                <div class="flex g6 wrap">
                    <input id="trombi-phone-{{ $subject->id }}" type="tel" inputmode="tel" autocomplete="tel" class="input f1"
                           style="min-width:180px" wire:model="phone" placeholder="Ex. 06 12 34 56 78" @disabled(! $canEdit)>
                    @if ($canEdit)
                        <button type="button" class="btn btn-primary btn-sm" wire:click="savePhone" wire:loading.attr="disabled" wire:target="savePhone">Enregistrer</button>
                    @endif
                    @if ($subject->phone)
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="deletePhone" wire:confirm="Supprimer le numéro ?"
                                wire:loading.attr="disabled" wire:target="deletePhone" aria-label="Supprimer le numéro"><x-icon name="trash" :size="14" /></button>
                    @endif
                </div>
                <div class="meta" style="font-size:12px">Pour le trombinoscope seulement : l'application n'y envoie jamais rien.</div>
            </div>
        </div>
    @endunless

    {{-- Accords --}}
    <div>
        <div class="sect-head"><span class="sect-title">Ce que {{ $self ? 'je partage' : $subject->first_name.' partage' }}</span></div>
        @if ($minor && ! $canGrant)
            <x-banner kind="info">
                <span>
                    @if ($subject->guardian_id)
                        Ton parent garant décide de ce qui est partagé. Tu peux retirer un accord toi-même.
                    @else
                        Un accord se donne par un parent, sur le bulletin d'adhésion. Tu peux retirer un accord toi-même.
                    @endif
                </span>
            </x-banner>
        @endif
        <div class="card card-pad" style="display:flex;flex-direction:column">
            @if ($canGrant || $allState !== 'off')
                <div class="row">
                    <x-toggle :on="$allState === 'on'" :mixed="$allState === 'mixed'" wire:click="toggleAll" wire:loading.attr="disabled" wire:target="toggleAll, toggleConsent"
                              aria-label="Tout partager" />
                    <div class="f1">
                        <div style="font-weight:700;font-size:14px">Tout</div>
                        <div class="meta" style="font-size:12px">{{ $canGrant ? 'Active ou retire tous les accords d\'un geste.' : 'Retire tous les accords d\'un geste.' }}</div>
                    </div>
                </div>
            @endif
            @foreach ($types as $type)
                @php
                    $on = $subject->hasConsent($type);
                    $noNumber = $type === \App\Support\Trombi\ConsentType::Phone && $subject->phone === null;
                @endphp
                <div class="row" wire:key="consent-{{ $type->value }}">
                    <x-toggle :on="$on" wire:click="toggleConsent('{{ $type->value }}')" wire:loading.attr="disabled" wire:target="toggleAll, toggleConsent"
                              :disabled="! $on && (! $canGrant || $noNumber)" aria-label="{{ $type->label() }}" />
                    <div class="f1">
                        <div style="font-weight:700;font-size:14px">{{ $type->label() }}</div>
                        <div class="meta" style="font-size:12px">{{ $type->description() }}@if ($noNumber && ! $on) Saisis d'abord ton numéro ci-dessus.@endif</div>
                    </div>
                </div>
            @endforeach
        </div>
        @if ($minor)
            <div class="meta" style="font-size:12px;margin-top:8px">Pas d'email ni de téléphone pour un mineur. Sur la fiche, les adhérents voient les coordonnées que le parent a lui-même partagées.</div>
        @endif
    </div>
</div>
