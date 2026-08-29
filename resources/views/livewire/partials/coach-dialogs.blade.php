{{-- Dialogs d'encadrement (§4.11.2 / §4.11.5), portés de modals.jsx (BasculeModal + inscrireCoach).
     Pilotés par les propriétés Livewire : $pickingCoach, $lastCoachConfirm, $flipConfirm.
     Reçoit : $session, $selectableCoaches. --}}

{{-- ── Sélecteur « Inscrire un coach » (voie 3, §4.11.2) ── --}}
@if ($pickingCoach)
    <x-dialog title="Inscrire un coach" sub="Encadrants éligibles · rôle coach actif" :width="440" close="closeCoachPicker">
        @if ($selectableCoaches->isEmpty())
            <div class="meta tc" style="padding:var(--space-3) 0">Aucun coach disponible à inscrire.</div>
        @else
            <div style="display:flex;flex-direction:column;gap:8px">
                @foreach ($selectableCoaches as $c)
                    <button type="button" wire:click="registerCoach({{ $c->id }})"
                            class="card card-pad flex ac g10" style="text-align:left;cursor:pointer;width:100%">
                        <x-avatar :name="$c->fullName()" tint="tint-swim" />
                        <div class="f1" style="min-width:0">
                            <div style="font-weight:700;font-size:14px">{{ $c->fullName() }}</div>
                            <div class="meta" style="font-size:12px">{{ $c->qualifications->isNotEmpty() ? $c->qualifications->pluck('code')->filter()->join(' · ') : 'Sans qualification renseignée' }}</div>
                        </div>
                        <x-icon name="user-plus" :size="16" />
                    </button>
                @endforeach
            </div>
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeCoachPicker">Fermer</button>
        </x-slot:footer>
    </x-dialog>
@endif

{{-- ── Confirmation « dernier coach » au retrait (§4.11.2) ── --}}
@if ($lastCoachConfirm)
    @php($lcCoach = $session->coaches->firstWhere('id', $lastCoachConfirm['coach_id']))
    <x-dialog title="Dernier coach inscrit" :danger="true" :width="440" close="cancelLastCoachConfirm">
        <x-banner kind="danger">
            <div><b>{{ $lcCoach?->fullName() }}</b> est le seul coach inscrit. La séance se retrouvera
            sans encadrement après son retrait.</div>
        </x-banner>
        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="cancelLastCoachConfirm">Annuler</button>
            <button type="button" class="btn btn-danger" wire:click="unregisterCoach({{ $lastCoachConfirm['coach_id'] }}, true)">
                Retirer quand même
            </button>
        </x-slot:footer>
    </x-dialog>
@endif

{{-- ── Bascule athlète ↔ coach (4 cas §4.11.5) ── --}}
@if ($flipConfirm)
    @php($self = (int) $flipConfirm['user_id'] === (int) auth()->id())
    @php($fcUser = $self ? auth()->user() : ($session->coaches->firstWhere('id', $flipConfirm['user_id']) ?? \App\Models\User::find($flipConfirm['user_id'])))

    @if ($flipConfirm['dir'] === 'to_coach')
        {{-- Cas 1 (self) / 4 (tiers) : athlète → coach. --}}
        <x-dialog :title="$self ? 'Je passe coach' : 'Passer un inscrit en coach'" :width="476" close="cancelFlip">
            <div class="card card-pad card-soft flex ac g10" style="margin-bottom:14px">
                <x-avatar :name="$fcUser->fullName()" tint="tint-bike" />
                <div class="f1" style="min-width:0">
                    <div class="flex ac g6"><b style="font-size:14px">{{ $fcUser->fullName() }}</b>
                        @if ($self)<span class="chip chip-sm chip-line">moi</span>@else<span class="chip chip-sm chip-pink">autre membre</span>@endif
                    </div>
                    <div class="meta" style="font-size:12px">Athlète inscrit · qualifié coach</div>
                </div>
            </div>
            <x-banner kind="info">
                <div>{{ $self ? 'Ton' : 'Son' }} inscription athlète sera <b>annulée</b> — {{ $self ? 'ta' : 'sa' }} place et {{ $self ? 'ton' : 'son' }} quota seront libérés.
                Conséquences possibles : promotion automatique d’un·e athlète en file « séance pleine » (mécanisme A),
                et déblocage d’une inscription « quota » {{ $self ? 'à toi' : 'à lui/elle' }} sur une autre séance du même tag cette semaine (mécanisme B).</div>
            </x-banner>
            @unless ($self)
                <div style="margin-top:14px"><x-banner kind="info"><div>{{ $fcUser->first_name }} sera <b>notifié·e</b> de son passage en encadrement. Il/elle pourra redevenir athlète à tout moment.</div></x-banner></div>
            @endunless
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="cancelFlip">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="flipToCoach({{ $flipConfirm['user_id'] }}, true)">
                    {{ $self ? 'Passer coach' : 'Basculer en coach' }}
                </button>
            </x-slot:footer>
        </x-dialog>

    @else
        {{-- Cas 2 (self) / 3 (tiers) : coach → athlète. --}}
        <x-dialog :title="$self ? 'Je repasse athlète' : 'Repasser un coach en athlète'" :danger="!empty($flipConfirm['last_coach'])" :width="476" close="cancelFlip">
            <div class="card card-pad card-soft flex ac g10" style="margin-bottom:14px">
                <x-avatar :name="$fcUser->fullName()" tint="tint-swim" />
                <div class="f1" style="min-width:0">
                    <div class="flex ac g6"><b style="font-size:14px">{{ $fcUser->fullName() }}</b>
                        @if ($self)<span class="chip chip-sm chip-line">moi</span>@else<span class="chip chip-sm chip-pink">autre membre</span>@endif
                    </div>
                    <div class="meta" style="font-size:12px">Coach encadrant{{ empty($flipConfirm['last_coach']) ? '' : ' · dernier coach inscrit' }}</div>
                </div>
            </div>

            @if (! empty($flipConfirm['last_coach']))
                <x-banner kind="danger"><div><b>Dernier coach.</b> En basculant, la séance se retrouvera sans encadrement (0 coach).</div></x-banner>
            @endif

            <div style="margin-top:{{ empty($flipConfirm['last_coach']) ? 0 : 14 }}px">
                <x-banner kind="info">
                    <div>{{ $self ? 'Ton' : 'Son' }} inscription comme coach sera <b>retirée</b>, puis {{ $self ? 'tu seras' : 'il/elle sera' }} inscrit·e
                    comme athlète selon la capacité et le quota (place ou liste d’attente).</div>
                </x-banner>
            </div>

            @if (! empty($flipConfirm['need_quota']))
                <div style="margin-top:14px"><x-banner kind="warn"><div>L’inscription athlète dépasse le quota du créneau cette semaine : {{ $self ? 'tu seras placé·e' : 'la personne sera placée' }} en file « quota ». Confirmer ?</div></x-banner></div>
            @endif

            @unless ($self)
                <div style="margin-top:14px"><x-banner kind="green"><div>{{ $fcUser->first_name }} sera <b>notifié·e</b> du changement.</div></x-banner></div>
            @endunless

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="cancelFlip">Annuler</button>
                <button type="button" class="btn {{ empty($flipConfirm['last_coach']) ? 'btn-primary' : 'btn-danger' }}"
                        wire:click="flipToAthlete({{ $flipConfirm['user_id'] }}, true, {{ !empty($flipConfirm['last_coach']) ? 'true' : 'false' }}, {{ !empty($flipConfirm['need_quota']) ? 'true' : 'false' }})">
                    {{ !empty($flipConfirm['need_quota']) ? 'Continuer quand même' : ($self ? 'Repasser athlète' : 'Basculer en athlète') }}
                </button>
            </x-slot:footer>
        </x-dialog>
    @endif
@endif

{{-- ── Sélecteur « Inscrire un athlète » (§4.9.7) ── --}}
@if ($pickingAthlete)
    <x-dialog title="Inscrire un athlète" sub="Athlètes actifs · non inscrits sur cette séance" :width="440" close="closeAthletePicker">
        <div class="ifield" style="margin-bottom:10px">
            <x-icon name="search" :size="15" style="color:var(--fg-muted);flex:0 0 auto" />
            <input class="ifield-input" type="text" wire:model.live.debounce.250ms="athleteSearch" placeholder="Chercher un athlète…" autocomplete="off">
        </div>
        @if ($selectableAthletes->isEmpty())
            <div class="meta tc" style="padding:var(--space-3) 0">Aucun athlète à inscrire.</div>
        @else
            <div style="display:flex;flex-direction:column;gap:8px;max-height:340px;overflow:auto">
                @foreach ($selectableAthletes as $a)
                    <button type="button" wire:click="enrollAthlete({{ $a->id }})" wire:key="ath-{{ $a->id }}"
                            class="card card-pad flex ac g10" style="text-align:left;cursor:pointer;width:100%">
                        <x-avatar :name="$a->fullName()" tint="tint-run" />
                        <div class="f1" style="min-width:0">
                            <div style="font-weight:700;font-size:14px">{{ $a->fullName() }}</div>
                        </div>
                        <x-icon name="user-plus" :size="16" />
                    </button>
                @endforeach
            </div>
        @endif
        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="closeAthletePicker">Fermer</button>
        </x-slot:footer>
    </x-dialog>
@endif

{{-- ── Dialog quota dépassé SELF (§4.10.3) : remplacement du bandeau inline par une modale ── --}}
@if ($confirmingQuota)
    {{-- Forme php inline uniquement dans ce fichier : mélanger inline et blocs raw (ou même
         citer le mot-clé de fermeture de bloc dans un commentaire) casse l'extraction Blade. --}}
    @php($qSubj = $subjectFirstName ?? null)
    @php($qSub = $qSubj ? $qSubj.' a atteint son quota pour ce créneau cette semaine.' : 'Tu as atteint ton quota pour ce créneau cette semaine.')
    <x-dialog title="Quota atteint" :sub="$qSub" :width="440" close="cancelQuotaConfirm">
        <x-banner kind="warn">
            @if ($qSubj)
                En continuant, {{ $qSubj }} sera placé·e en liste d'attente « quota ». Un coach pourra ensuite débloquer manuellement son inscription.
            @else
                En continuant, tu seras placé·e en liste d'attente « quota ». Un coach pourra ensuite te débloquer manuellement s'il décide de t'inscrire.
            @endif
        </x-banner>
        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="cancelQuotaConfirm">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="enroll(true)">Continuer quand même</button>
        </x-slot:footer>
    </x-dialog>
@endif

{{-- ── Dialog quota dépassé (§4.9.7 cas b) : (a) file quota / (b) override ── --}}
@if ($athleteQuotaConfirm)
    @php($aqUser = \App\Models\User::find($athleteQuotaConfirm['user_id']))
    {{-- footStack : trois issues (file quota / override / abandon) totalisant 552px de boutons — la
         rangée sortait du cadre et se faisait couper. Empilées, elles se lisent dans l'ordre voulu. --}}
    <x-dialog title="Quota dépassé" sub="Choisis la suite de l'inscription" :width="476" foot-stack close="cancelAthleteQuota">
        <x-banner kind="warn">
            <div>
                @if (! empty($athleteQuotaConfirm['count']) || $athleteQuotaConfirm['count'] === 0)
                    <b>{{ $aqUser?->fullName() }}</b> a déjà {{ $athleteQuotaConfirm['count'] }}/{{ $athleteQuotaConfirm['max'] }} séance·s
                    @if (! empty($athleteQuotaConfirm['tag']))« {{ $athleteQuotaConfirm['tag'] }} » @endif cette semaine.
                @else
                    <b>{{ $aqUser?->fullName() }}</b> dépasse le quota de ce créneau cette semaine.
                @endif
            </div>
        </x-banner>
        <div style="margin-top:14px">
            <label class="field-label">Motif (optionnel, pour l'override)</label>
            <div class="ifield"><input class="ifield-input" type="text" wire:model.blur="athleteQuotaConfirm.motif" placeholder="Ex. créneau libéré, demande coach…"></div>
        </div>
        <x-slot:footer>
            <button type="button" class="btn btn-dark" wire:click="confirmAthleteQuota(false)">Placer en file quota</button>
            <button type="button" class="btn btn-danger" wire:click="confirmAthleteQuota(true)">Forcer l'inscription</button>
            <button type="button" class="btn btn-ghost" wire:click="cancelAthleteQuota">Annuler</button>
        </x-slot:footer>
    </x-dialog>
@endif
