{{-- Onglet Notifs — porté de screen-profil.jsx PrNotifs. Pause globale (§4.15.4) + matrice
     type×canal (§4.15.3, défaut tout activé, opt-out cellule par cellule). Lignes issues du registre
     §4.15.2 (groupes ; le groupe Encadrement n'apparaît qu'aux coachs/admins). --}}
<div style="display:flex;flex-direction:column;gap:16px">

    {{-- Push sur l'appareil (J8.6) — abonnement PushManager, état purement client (window.clubPush).
         Distinct des préférences ci-dessous : ici on autorise/coupe le canal push DE CET APPAREIL ;
         la matrice règle quels types passent par push/email. Non couvert par le proto → markup maison
         sur les classes design (card/toggle/meta).

         Masqué si le club a coupé le push (§4.17) : s'abonner n'aurait aucun effet, et proposer le
         geste laisserait croire que l'appareil recevra des alertes. --}}
    @if ($clubChannels['push'] ?? true)
    <div class="card card-pad card-soft flex ac g10"
        x-data="{
            state: 'loading',
            busy: false,
            async init() { this.state = await window.clubPush.getState(); },
            async toggle() {
                if (this.busy || this.state === 'denied' || this.state === 'unsupported') return;
                this.busy = true;
                try {
                    this.state = this.state === 'on' ? await window.clubPush.disable() : await window.clubPush.enable();
                } catch (e) { this.state = await window.clubPush.getState(); }
                this.busy = false;
            }
        }">
        <button type="button" class="toggle" :class="{ 'on': state === 'on' }"
            :aria-pressed="state === 'on' ? 'true' : 'false'"
            :disabled="busy || state === 'denied' || state === 'unsupported'"
            x-on:click="toggle()"></button>
        <div class="f1">
            <div style="font-weight:700;font-size:14px">Notifications sur cet appareil</div>
            <div class="meta" style="font-size:12px">
                <span x-show="state === 'on'">Activées ici — appuie pour les couper sur cet appareil.</span>
                <span x-show="state === 'off'" x-cloak>Reçois les alertes push sur cet appareil, même l'application fermée.</span>
                <span x-show="state === 'denied'" x-cloak>Bloquées par le navigateur. Autorise les notifications dans ses réglages.</span>
                <span x-show="state === 'unsupported'" x-cloak>Cet appareil ou navigateur ne gère pas les notifications push.</span>
                <span x-show="state === 'loading'">Vérification…</span>
            </div>
        </div>
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
</div>
