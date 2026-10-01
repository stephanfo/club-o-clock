{{-- Onglet Notifs — porté de screen-profil.jsx PrNotifs. Pause globale (§4.15.4) + matrice
     type×canal (§4.15.3, défaut tout activé, opt-out cellule par cellule). Lignes issues du registre
     §4.15.2 (groupes ; le groupe Encadrement n'apparaît qu'aux coachs/admins). --}}
<div style="display:flex;flex-direction:column;gap:16px">

    {{-- Push sur l'appareil (J8.6) — abonnement PushManager, piloté par window.clubPush. « Activées »
         n'est affiché que si le serveur a confirmé l'abonnement ; sinon « à réparer » (#96). Un échec
         d'activation ou de coupure s'affiche en clair sous le libellé, et l'état précédent reste.
         Distinct des préférences ci-dessous : ici on autorise/coupe le canal push DE CET APPAREIL ;
         la matrice règle quels types passent par push/email. Non couvert par le proto → markup maison
         sur les classes design (card/toggle/meta).

         Masqué si le club a coupé le push (§4.17) : s'abonner n'aurait aucun effet, et proposer le
         geste laisserait croire que l'appareil recevra des alertes. --}}
    @if ($clubChannels['push'] ?? true)
    @php
        // Avertissement (#97) : l'adhérent veut des push (pas en pause, au moins un type coché en
        // push) mais aucun appareil n'est abonné — ils finissent « sans destinataire », lisibles sur
        // la seule page Alertes. On ne prévient que parce que la correction passe par lui.
        $wantsPush = ! $paused && collect($matrix)->contains(fn ($c) => (bool) ($c['push'] ?? false));
    @endphp
    <div style="display:flex;flex-direction:column;gap:16px"
        x-data="{
            state: 'loading',
            busy: false,
            testing: false,
            error: null,
            current: null,
            async init() {
                this.state = await window.clubPush.getState();
                this.current = await window.clubPush.currentEndpointHash();
            },
            async toggle() {
                if (this.busy || this.state === 'denied' || this.state === 'unsupported') return;
                this.busy = true;
                this.error = null;
                try {
                    this.state = this.state === 'on' ? await window.clubPush.disable() : await window.clubPush.enable();
                    this.current = await window.clubPush.currentEndpointHash();
                    $wire.$refresh(); // liste des appareils et avertissement suivent
                } catch (e) {
                    this.error = window.clubPush.messageFor(e);
                }
                this.busy = false;
            },
            async test() {
                if (this.testing) return;
                this.testing = true;
                const endpoint = await window.clubPush.currentEndpoint();
                if (endpoint) {
                    await $wire.sendTestPush(endpoint);
                } else {
                    this.state = 'repair';
                }
                this.testing = false;
            }
        }">
    <div class="card card-pad card-soft flex ac g10">
        <button type="button" class="toggle" :class="{ 'on': state === 'on' }"
            :aria-pressed="state === 'on' ? 'true' : 'false'"
            :disabled="busy || state === 'denied' || state === 'unsupported'"
            x-on:click="toggle()"></button>
        <div class="f1">
            <div style="font-weight:700;font-size:14px">Notifications sur cet appareil</div>
            <div class="meta" style="font-size:12px">
                <span x-show="state === 'on'">Activées ici — appuie pour les couper sur cet appareil.</span>
                <span x-show="state === 'off'" x-cloak>Reçois les alertes push sur cet appareil, même l'application fermée.</span>
                <span x-show="state === 'repair'" x-cloak>Le club ne peut plus joindre cet appareil. Appuie pour réactiver les notifications.</span>
                <span x-show="state === 'denied'" x-cloak>Bloquées par le navigateur. Autorise les notifications dans ses réglages.</span>
                <span x-show="state === 'unsupported'" x-cloak>Cet appareil ou navigateur ne gère pas les notifications push.</span>
                <span x-show="state === 'loading'">Vérification…</span>
            </div>
            <div class="field-error" role="alert" x-show="error" x-text="error" x-cloak></div>
            {{-- Preuve de bout en bout (#97) : un push à cet appareil seul, fréquence bornée serveur. --}}
            <button type="button" class="btn btn-ghost btn-sm" style="margin-top:10px" data-push-test
                x-show="state === 'on'" x-cloak :disabled="testing" x-on:click="test()">
                <x-icon name="send" :size="14" /> M'envoyer une notification de test
            </button>
        </div>
    </div>

    @if ($pushDevices === [] && $wantsPush)
        <x-banner kind="warn" data-push-aucun>
            <div><b>Aucun appareil ne reçoit tes notifications push.</b> Tu ne les vois que sur la page Alertes.</div>
            <div style="margin-top:4px" x-show="state === 'off' || state === 'repair'">Active « Notifications sur cet appareil » ci-dessus.</div>
            <div style="margin-top:4px" x-show="state === 'denied'" x-cloak>Autorise d'abord les notifications dans les réglages du navigateur.</div>
            <div style="margin-top:4px" x-show="state === 'unsupported'" x-cloak>Cet appareil ne les gère pas : active-les depuis un autre. Sur iPhone, ajoute d'abord l'application à l'écran d'accueil.</div>
        </x-banner>
    @endif

    {{-- Mes appareils (#97) : chaque abonnement, son dernier envoi réussi, et le retrait des
         autres appareils (anodin et réversible : wire:confirm). Le courant se coupe par
         l'interrupteur ci-dessus, qui désabonne aussi le navigateur. --}}
    @if ($pushDevices !== [])
        <div>
            <div class="sect-head"><span class="sect-title">Mes appareils</span><span class="meta mlauto">{{ count($pushDevices) }}</span></div>
            <div style="display:flex;flex-direction:column;gap:8px">
                @foreach ($pushDevices as $d)
                    <div class="card card-pad flex ac jb g10" wire:key="push-device-{{ $d['id'] }}" data-push-appareil>
                        <div style="min-width:0">
                            <div class="flex ac g6 wrap" style="font-weight:700;font-size:14px">
                                {{ $d['device'] }}
                                <span class="chip chip-sm chip-green" x-show="current === '{{ $d['hash'] }}'" x-cloak>cet appareil</span>
                            </div>
                            <div class="meta" style="font-size:12px">
                                {{ $d['lastSuccess'] ? 'Dernier envoi réussi '.$d['lastSuccess'] : 'Aucun envoi réussi pour l\'instant' }}
                                · abonné le {{ $d['since'] }}
                            </div>
                            @if ($d['failing'])
                                <div class="meta" style="font-size:12px;color:var(--accent-700)">Les derniers envois vers cet appareil ont échoué.</div>
                            @endif
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm" x-show="current !== '{{ $d['hash'] }}'"
                            wire:click="removePushDevice({{ $d['id'] }})" wire:loading.attr="disabled" wire:target="removePushDevice({{ $d['id'] }})"
                            wire:confirm="Retirer cet appareil ? Il ne recevra plus de notifications push.">Retirer</button>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
    </div>
    @endif

    {{-- Pause globale (§4.15.4) --}}
    <div class="card card-pad card-soft flex ac g10">
        <x-toggle :on="$paused" wire:click="togglePause" />
        <div class="f1">
            <div style="font-weight:700;font-size:14px">Pause globale</div>
            <div class="meta" style="font-size:12px">Coupe toutes les notifications, tous canaux confondus, tant que tu ne la lèves pas</div>
        </div>
    </div>

    {{-- Canal coupé par le club (§4.17) : le réglage personnel reste stocké mais n'a plus d'effet,
         autant le dire plutôt que de laisser croire à un bug. --}}
    @php($closed = collect($clubChannels)->reject(fn ($on) => $on)->keys()
        ->map(fn ($c) => $c === 'push' ? 'push' : 'par email')->all())
    @if ($closed !== [])
        <x-banner kind="warn">
            Les notifications {{ implode(' et ', $closed) }} sont désactivées par le club — les réglages
            ci-dessous reprendront effet si le bureau les réactive.
        </x-banner>
    @endif

    {{-- Matrice — soumise à la pause globale --}}
    <div>
        <div class="sect-head"><span class="sect-title">Mes préférences</span></div>
        <div class="card" style="overflow:hidden;opacity:{{ $paused ? '.45' : '1' }};pointer-events:{{ $paused ? 'none' : 'auto' }}">
            {{-- En-tête de colonnes --}}
            <div class="flex ac" style="padding:8px 14px;gap:10px;background:var(--slate-50);border-bottom:1px solid var(--divider)">
                <div class="f1"></div>
                @foreach ([['push', 'Push'], ['email', 'Email']] as [$col, $colLabel])
                    <div style="width:48px;text-align:center">
                        <span class="eyebrow" style="font-size:10px">{{ $colLabel }}</span>
                        @unless ($clubChannels[$col] ?? true)
                            <div class="meta" style="font-size:9px">coupé</div>
                        @endunless
                    </div>
                @endforeach
            </div>

            @foreach ($groups as $group)
                <div style="padding:11px 14px 5px;font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:var(--fg-soft);border-top:1px solid var(--divider)">{{ $group['label'] }}</div>

                @foreach ($group['types'] as $type)
                    <div class="flex ac" style="padding:11px 14px;border-bottom:1px solid var(--divider);gap:10px">
                        <div class="f1">
                            <div class="flex ac g6" style="flex-wrap:wrap;row-gap:4px">
                                <span style="font-weight:700;font-size:14px">{{ $type->label() }}</span>
                                @if ($group['coachOnly'])
                                    <span class="chip chip-sm chip-blue">Coachs</span>
                                @endif
                            </div>
                            <div class="meta" style="font-size:12px;margin-top:2px;line-height:1.4">{{ $type->description() }}</div>
                        </div>
                        @foreach (['push', 'email'] as $channel)
                            {{-- Canal coupé par le club : cellule inerte, même traitement visuel que
                                 la pause globale ci-dessus (opacité + pointer-events). --}}
                            @php($open = $clubChannels[$channel] ?? true)
                            <div class="flex ac jc" style="width:48px;opacity:{{ $open ? '1' : '.45' }};pointer-events:{{ $open ? 'auto' : 'none' }}">
                                @if (in_array($channel, $type->channels(), true))
                                    <x-toggle :on="$matrix[$type->value][$channel] ?? true"
                                        wire:click="togglePref('{{ $type->value }}', '{{ $channel }}')" />
                                @else
                                    <span class="meta" style="font-size:11px">—</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            @endforeach
        </div>
    </div>

    @include('livewire.profil._agenda')
</div>
