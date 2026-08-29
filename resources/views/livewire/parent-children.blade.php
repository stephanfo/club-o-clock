{{-- « Mes enfants » — porté de screen-parent.jsx (ParentChildren mobile + ParentChildrenDesktop).
     Cartes par enfant : prochaine séance + Inscrire, semaine courante, lien de tutelle (P1/P2).
     Divergences PRD assumées : pas de « Ajouter un enfant » (création admin-only §4.1.3),
     pas d'onglets décoratifs du proto (non fonctionnels). --}}
@php
    $p2Names = $cards->filter(fn ($c) => $c['phase'] === 'P2')->map(fn ($c) => $c['ward']->first_name);
@endphp

<div class="children-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ═══════════════ MOBILE ═══════════════ --}}
    <div class="children-mobile">
        <x-topbar title="Mes enfants" :sub="$cards->count().' compte'.($cards->count() > 1 ? 's' : '').' mineur'.($cards->count() > 1 ? 's' : '')">
            <x-slot:trailing><x-alert-bell dark /></x-slot:trailing>
        </x-topbar>
        <div style="background:var(--app-bg);padding:14px;display:flex;flex-direction:column;gap:14px">
            <x-banner kind="info">
                <div><b>Tu agis au nom de tes enfants.</b>
                    @if ($p2Names->isNotEmpty())
                        Pour <b>{{ $p2Names->join(', ') }}</b> (P2), les notifications arrivent aussi sur son propre compte.
                    @endif
                </div>
            </x-banner>
            @foreach ($cards as $c)
                @include('livewire.partials.child-card', ['c' => $c, 'tz' => $tz, 'pad' => 14])
            @endforeach
        </div>
    </div>

    {{-- ═══════════════ DESKTOP ═══════════════ --}}
    <div class="children-desktop">
        <div class="dk-topbar">
            <div class="f1">
                <div class="dsp" style="font-size:26px">Mes enfants</div>
                <div class="meta">{{ $cards->count() }} compte{{ $cards->count() > 1 ? 's' : '' }} mineur{{ $cards->count() > 1 ? 's' : '' }} · tu agis en leur nom</div>
            </div>
        </div>
        <div class="dk-body">
            <div style="max-width:1000px;margin:0 auto">
                <x-banner kind="info">
                    <div><b>Tu agis au nom de tes enfants.</b>
                        @if ($p2Names->isNotEmpty())
                            Pour <b>{{ $p2Names->join(', ') }}</b> (phase P2), les notifications arrivent aussi sur son propre compte.
                        @endif
                    </div>
                </x-banner>
                <div class="children-grid">
                    @foreach ($cards as $c)
                        @include('livewire.partials.child-card', ['c' => $c, 'tz' => $tz, 'pad' => 16])
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- ── Dialog « Accès autonome » (P1→P2, §4.2.1) ── --}}
    @if ($inviteDialog)
        @php
            $invCard = $cards->first(fn ($c) => $c['ward']->id === $inviteDialog['ward_id']);
            $invWard = $invCard['ward'] ?? null;
        @endphp
        <x-dialog title="Accès autonome" sub="Ouvre un compte à {{ $invWard?->first_name }} — le lien de tutelle est conservé." :width="460" close="cancelInvite">
            <x-banner kind="info">L'email doit appartenir à <b>{{ $invWard?->first_name }}</b> : c'est lui/elle qui activera le compte et choisira sa méthode de connexion.</x-banner>
            <div style="margin-top:14px">
                <label class="field-label">Email de l'enfant</label>
                <div class="ifield"><input class="ifield-input" type="email" wire:model.blur="inviteDialog.email" placeholder="prenom@exemple.fr"></div>
                @error('inviteDialog.email')<div class="meta" style="color:var(--accent);margin-top:6px">{{ $message }}</div>@enderror
            </div>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="cancelInvite">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="sendInvite" wire:loading.attr="disabled" wire:target="sendInvite"><x-icon name="send" :size="14" /> Envoyer l'invitation</button>
            </x-slot:footer>
        </x-dialog>
    @endif

    {{-- ── Dialog « Rompre la tutelle » (P2→P3, §4.2.2) ── --}}
    @if ($severDialog)
        @php
            $sevCard = $cards->first(fn ($c) => $c['ward']->id === $severDialog);
            $sevWard = $sevCard['ward'] ?? null;
        @endphp
        <x-dialog title="Rompre la tutelle" sub="{{ $sevWard?->first_name }} devient autonome (P3)." danger :width="460" close="cancelSever">
            <x-banner kind="danger">
                Effet immédiat : tu ne recevras plus ses notifications, tu ne verras plus son historique et tu ne pourras plus l'inscrire. Cette action est tracée et vous serez notifiés tous les deux.
            </x-banner>
            {{-- Accusé de réception avant d'armer le bouton (motif « bascule de saison » §4.17) :
                 la rupture notifie les DEUX comptes, et l'envoi ne se dédit pas. --}}
            {{-- Le toggle est porté par la RANGÉE (pour la souris, toute la ligne est cliquable) et
                 aussi par le x-check, qui est un vrai <button> : sans quoi rien dans le dialog n'est
                 focusable au clavier et la case — donc le bouton qu'elle arme — devient inatteignable.
                 `.stop` sur le check empêche le clic de remonter à la rangée et de re-basculer. --}}
            <div class="flex ac g10" style="margin-top:14px;font-size:14px;cursor:pointer" wire:click="$toggle('severCheck')">
                <x-check :on="$severCheck" wire:click.stop="$toggle('severCheck')" aria-labelledby="txt-rompre-tutelle" />
                <span id="txt-rompre-tutelle">Je comprends que {{ $sevWard?->first_name }} et moi serons prévenu·e·s de la rupture.</span>
            </div>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="cancelSever">Annuler</button>
                <button type="button" class="btn btn-danger{{ $severCheck ? '' : ' is-disabled' }}"
                        @if ($severCheck) wire:click="confirmSever" @endif
                        wire:loading.attr="disabled" wire:target="confirmSever"><x-icon name="log-out" :size="14" /> Rompre la tutelle</button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
