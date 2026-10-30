{{-- Onglet Trombinoscope de la fiche adhérent (#72, PRD §4.23.1, §4.17.1). Ni la photo masquée ni
     le numéro n'apparaissent ici : l'admin voit seulement qu'ils existent. --}}
@php
    $who = $member->first_name;
    $recipientName = $recipient?->first_name;
@endphp
<div class="mc-show-grid">
    <x-flash-float />

    <div style="display:flex;flex-direction:column;gap:18px">
        {{-- Fonction au bureau --}}
        <div class="card card-pad">
            <div class="flex ac jb"><span class="sect-title">Fonction au bureau</span><span class="meta">affichée sans accord</span></div>
            <div class="flex g8 wrap" style="margin-top:12px">
                <select class="input f1" style="min-width:200px" wire:model="boardRole" aria-label="Fonction au bureau">
                    <option value="">Aucune</option>
                    @foreach (\App\Support\Trombi\BoardRole::cases() as $role)
                        <option value="{{ $role->value }}">{{ $role->label() }}</option>
                    @endforeach
                </select>
                <button type="button" class="btn btn-primary btn-sm" wire:click="saveBoardRole" wire:loading.attr="disabled" wire:target="saveBoardRole">Enregistrer</button>
            </div>
        </div>

        {{-- Accords en cours --}}
        <div class="card card-pad">
            <div class="flex ac jb"><span class="sect-title">Accords de diffusion</span><span class="meta">registre horodaté</span></div>
            <div style="margin-top:6px">
                @foreach ($types as $type)
                    @php $c = $active->get($type->value); @endphp
                    <div class="row" wire:key="adm-consent-{{ $type->value }}">
                        <div class="f1">
                            <div style="font-weight:700;font-size:14px">{{ $type->label() }}</div>
                            <div class="meta" style="font-size:12px">
                                @if ($c)
                                    Depuis le {{ $c->granted_at->locale('fr')->isoFormat('D MMMM YYYY') }}
                                    @if ($c->grantor) · par {{ $c->grantor->id === $member->id ? 'l\'adhérent' : $c->grantor->fullName() }} @endif
                                    @if ($c->source) · {{ $c->source }} @endif
                                @else
                                    Non partagé
                                @endif
                            </div>
                        </div>
                        @if ($c)
                            <span class="chip chip-sm chip-green">actif</span>
                            <button type="button" class="btn btn-ghost btn-sm" wire:click="revokeConsent('{{ $type->value }}')"
                                    wire:confirm="Retirer l'accord « {{ $type->label() }} » de {{ $who }} ? La donnée sera masquée aux adhérents."
                                    wire:loading.attr="disabled" wire:target="revokeConsent">Retirer</button>
                        @else
                            <span class="chip chip-sm chip-line">éteint</span>
                        @endif
                    </div>
                @endforeach
            </div>
            <div class="meta" style="font-size:12px;margin-top:10px">
                Photo : {{ $member->photo_path ? 'déposée' : 'aucune' }}
                @unless ($member->isLegallyMinor()) · Téléphone : {{ $member->phone ? 'saisi' : 'aucun' }} @endunless
                — seuls {{ $member->isLegallyMinor() ? 'l\'adhérent et son parent garant' : 'l\'adhérent' }} les déposent.
            </div>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:18px">
        {{-- Enregistrer un accord recueilli hors de l'app --}}
        <div class="card card-pad">
            <div class="sect-title">Enregistrer un accord recueilli hors de l'app</div>
            <div class="meta" style="font-size:12px;margin-top:4px">
                Case cochée sur le bulletin d'adhésion, par exemple.
                @if ($member->isLegallyMinor()) Pour un mineur : l'accord signé par un parent. @endif
                {{ $recipientName ? $recipientName.' recevra un message disant ce qui a été enregistré et comment le retirer.' : 'Personne ne peut être prévenu : ce compte n\'a pas d\'adresse.' }}
            </div>
            @if ($recordable === [])
                <div class="meta" style="margin-top:12px">Rien à enregistrer : tout ce qui peut l'être est déjà actif.</div>
            @else
                <div style="display:flex;flex-direction:column;gap:6px;margin-top:12px">
                    @foreach ($recordable as $type)
                        <label class="flex ac g8" style="cursor:pointer;font-size:14px" wire:key="adm-rec-{{ $type->value }}">
                            <input type="checkbox" wire:model="recordTypes" value="{{ $type->value }}"> {{ $type->label() }}
                        </label>
                    @endforeach
                    @error('recordTypes') <div class="meta" style="color:var(--danger);font-size:12px">{{ $message }}</div> @enderror
                </div>
                <div style="margin-top:12px">
                    <label class="field-label" for="adm-rec-source">Source (obligatoire)</label>
                    <input id="adm-rec-source" class="input" style="margin-top:6px" wire:model="recordSource" maxlength="120"
                           placeholder="Bulletin d'adhésion 2027" autocomplete="off">
                    @error('recordSource') <div class="meta" style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
                </div>
                <button type="button" class="btn btn-primary btn-sm" style="margin-top:12px" wire:click="openRecord" wire:loading.attr="disabled" wire:target="openRecord">Enregistrer…</button>
            @endif
        </div>

        {{-- Modération de la photo --}}
        @if ($member->photo_path)
            <div class="card card-pad">
                <div class="sect-title">Modération</div>
                <div class="meta" style="font-size:12px;margin-top:4px">Supprime une photo inappropriée et retire l'accord photo.</div>
                <button type="button" class="btn btn-ghost btn-sm" style="margin-top:10px" wire:click="$set('confirmingPhotoRemoval', true)">
                    <x-icon name="trash" :size="14" /> Supprimer la photo
                </button>
            </div>
        @endif
    </div>

    @if ($confirmingRecord)
        <x-dialog title="Enregistrer des accords" sub="Pour {{ $member->fullName() }}" :width="460" close="cancelRecord">
            <div style="display:flex;flex-direction:column;gap:12px">
                <x-conseq-row icon="check" label="{{ count($recordTypes) }} accord(s)">
                    {{ collect($recordTypes)->map(fn ($v) => \App\Support\Trombi\ConsentType::tryFrom($v)?->label())->filter()->implode(', ') }}
                    — d'après « {{ $recordSource }} ».
                </x-conseq-row>
                <x-conseq-row icon="eye" label="Visible des adhérents" tone="warn">Dès l'enregistrement, au trombinoscope.</x-conseq-row>
                @if ($recipientName)
                    <x-conseq-row icon="mail" label="{{ $recipientName }} est prévenu·e">Par email et notification, avec le moyen de retirer l'accord.</x-conseq-row>
                @endif
            </div>
            <div class="flex ac g10" style="margin-top:14px;font-size:14px;cursor:pointer" wire:click="$toggle('recordCheck')">
                <x-check :on="$recordCheck" wire:click.stop="$toggle('recordCheck')" aria-labelledby="txt-record-check" />
                <span id="txt-record-check">Je confirme que {{ count($recordTypes) }} accord(s) figurent sur « {{ $recordSource }} »{{ $recipientName ? ', et que '.$recipientName.' en sera prévenu·e' : '' }}.</span>
            </div>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="cancelRecord">Annuler</button>
                <button type="button" class="btn btn-primary{{ $recordCheck ? '' : ' is-disabled' }}"
                        @if ($recordCheck) wire:click="recordConsents" @endif
                        wire:loading.attr="disabled" wire:target="recordConsents">Enregistrer</button>
            </x-slot:footer>
        </x-dialog>
    @endif

    @if ($confirmingPhotoRemoval)
        <x-dialog title="Supprimer la photo" :danger="true" :width="460" close="$set('confirmingPhotoRemoval', false)">
            <div style="display:flex;flex-direction:column;gap:12px">
                <x-conseq-row icon="trash" label="Effacée du stockage" tone="danger">La photo de {{ $who }} est supprimée, sans copie.</x-conseq-row>
                <x-conseq-row icon="eye-off" label="Accord photo retiré">{{ $who }} pourra en déposer une autre et la partager à nouveau.</x-conseq-row>
                <x-conseq-row icon="file-text" label="Tracé au journal">Geste de modération consigné.</x-conseq-row>
            </div>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="$set('confirmingPhotoRemoval', false)">Annuler</button>
                <button type="button" class="btn btn-danger-solid" wire:click="removePhoto" wire:loading.attr="disabled" wire:target="removePhoto">Supprimer la photo</button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
