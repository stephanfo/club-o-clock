{{-- Onglet Connexion — porté de screen-profil.jsx PrConnexion. Méthodes de login liées (§4.1.1),
     sessions actives (driver base), déconnexion. La section « suppression de compte » (§4.3) est
     ajoutée à part. --}}
<div style="display:flex;flex-direction:column;gap:14px">

    {{-- Méthodes liées (§4.1.1) --}}
    <div class="sect-head"><span class="sect-title">Méthodes liées</span></div>
    @foreach ($methods as $m)
        <div class="card card-pad">
            <div class="flex ac g10">
                {{-- Méthode fermée par le club (§4.17) : liée au compte mais inopérante tant que le
                     bureau ne la réactive pas. La coche décrocherait un accès promis à tort. --}}
                <x-check :on="! $m['off']" />
                <div class="f1">
                    <div class="flex ac g6" style="flex-wrap:wrap;row-gap:4px">
                        <span style="font-weight:700;font-size:14px">{{ $m['label'] }}</span>
                        @if ($m['off'])
                            <span class="chip chip-sm">Désactivé par le club</span>
                        @endif
                    </div>
                    <div class="meta" style="font-size:12px">{{ $m['sub'] }}</div>
                </div>
                @if ($m['revocable'])
                    <button type="button" wire:click="revokeMethod({{ $m['id'] }})"
                        wire:confirm="Délier {{ $m['label'] }} de ton compte ?" class="btn btn-ghost btn-sm">Révoquer</button>
                @endif
            </div>
        </div>
    @endforeach

    {{-- Mot de passe (§4.1.1, §4.1.5). Un compte créé par invitation n'en a pas : le bloc POSE alors
         un premier mot de passe, sans réclamer d'ancien. Le mot de passe reste facultatif — le lien
         magique est une méthode complète à part entière. --}}
    @php($hasPassword = $user->password !== null)
    <div class="sect-head" style="margin-top:4px">
        <span class="sect-title">{{ $hasPassword ? 'Changer mon mot de passe' : 'Définir un mot de passe' }}</span>
    </div>
    <div class="card card-pad">
        @if ($demo)
            <div class="meta">Indisponible en mode démo : les comptes sont partagés entre visiteurs.</div>
        @else
            @unless ($hasPassword)
                <div class="meta" style="margin-bottom:10px">
                    Ton compte n'a pas de mot de passe — tu te connectes par lien ou par Google.
                    En définir un est facultatif, mais te permet d'entrer sans attendre d'email.
                </div>
            @endunless
            <div style="display:flex;flex-direction:column;gap:10px">
                @if ($hasPassword)
                    <div>
                        <label class="field-label" for="pf-cur-pwd">Mot de passe actuel</label>
                        <input id="pf-cur-pwd" type="password" class="input" autocomplete="current-password"
                            wire:model="current_password">
                        @error('current_password') <div class="meta" style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
                    </div>
                @endif
                <div>
                    <label class="field-label" for="pf-new-pwd">Nouveau mot de passe</label>
                    <input id="pf-new-pwd" type="password" class="input" autocomplete="new-password"
                        wire:model="password">
                    @error('password') <div class="meta" style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
                    {{-- La règle est annoncée AVANT la saisie, et sort de PasswordPolicy : c'est la
                         même source que la validation, donc les deux ne peuvent plus diverger. --}}
                    <div class="meta" style="font-size:12px;margin-top:4px">{{ \App\Support\PasswordPolicy::hint() }}</div>
                </div>
                <div>
                    <label class="field-label" for="pf-new-pwd2">Confirmer</label>
                    <input id="pf-new-pwd2" type="password" class="input" autocomplete="new-password"
                        wire:model="password_confirmation">
                </div>
                @if ($hasPassword)
                    <label class="flex ac g8" style="font-size:13px">
                        <input type="checkbox" wire:model="revokeOthers"> Déconnecter mes autres appareils
                    </label>
                @endif
                <div class="flex ac g8" style="flex-wrap:wrap;row-gap:8px">
                    <button type="button" wire:click="savePassword" class="btn btn-primary btn-sm"
                        wire:loading.attr="disabled" wire:target="savePassword">
                        {{ $hasPassword ? 'Modifier' : 'Définir' }}
                    </button>
                    @if ($hasPassword && $canRemovePassword)
                        <button type="button" wire:click="removePassword" class="btn btn-ghost btn-sm"
                            wire:confirm="Retirer ton mot de passe ? Tu te connecteras par lien ou par Google."
                            wire:loading.attr="disabled" wire:target="removePassword">Retirer</button>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- Sessions actives --}}
    <div class="sect-head" style="margin-top:4px"><span class="sect-title">Sessions actives</span></div>
    @forelse ($sessions as $s)
        <div class="card card-pad flex ac jb">
            <div>
                <div style="font-weight:700;font-size:14px">
                    {{ $s['device'] }}
                    @if ($s['current'])<span class="chip chip-sm chip-green">cette session</span>@endif
                </div>
                <div class="meta" style="font-size:12px">Dernière activité {{ $s['last'] }}</div>
            </div>
            @unless ($s['current'])
                <button type="button" wire:click="revokeSession('{{ $s['id'] }}')"
                    wire:confirm="Déconnecter cet appareil ?" class="btn btn-ghost btn-sm">Déconnecter</button>
            @endunless
        </div>
    @empty
        <div class="meta">Session courante uniquement.</div>
    @endforelse

    @if (collect($sessions)->where('current', false)->isNotEmpty())
        <button type="button" wire:click="revokeOtherSessions"
            wire:confirm="Déconnecter tous les autres appareils ?" class="btn btn-ghost btn-block" style="margin-top:4px">
            <x-icon name="log-out" :size="16" /> Déconnecter tous les autres appareils
        </button>
    @endif

    {{-- Déconnexion (appareil courant) — déplacée ici depuis la nav (§4.1). --}}
    <form method="POST" action="{{ route('logout') }}" style="margin-top:4px">
        @csrf
        <button type="submit" class="btn btn-danger btn-block"><x-icon name="log-out" :size="16" /> Se déconnecter</button>
    </form>

    {{-- Suppression de compte (§4.3 voie 1) — porté de DeleteAccountSection. La demande ouvre le
         tampon 7j ; l'admin confirme la suppression définitive à J+7. Rétractable in-app pendant le tampon. --}}
    @if ($user->isDeletionPending())
        <div class="sect-head" style="margin-top:4px"><span class="sect-title">Suppression du compte</span></div>
        <div class="card card-pad" style="border-color:var(--warning-border);background:var(--warning-bg)">
            <div class="flex ac g10" style="color:var(--warning-text)">
                <x-icon name="clock" :size="22" />
                <div class="f1">
                    <div class="dsp-7" style="font-size:18px;color:var(--warning-text)">Demande envoyée</div>
                    <div class="meta" style="color:var(--warning-text);font-size:12px">En attente de validation du bureau</div>
                </div>
                <span class="chip chip-sm chip-warn">en attente</span>
            </div>
            <div style="font-size:13.5px;color:var(--warning-text);margin-top:10px;line-height:1.5">
                Le bureau dispose d'un <b>délai minimum de 7 jours</b> avant la suppression définitive. Tes données seront ensuite anonymisées.
            </div>
            <div class="flex ac jb g8" style="margin-top:14px">
                <span class="meta" style="font-size:12px;color:var(--warning-text)">Envoyée le {{ $user->deletion_requested_at->locale('fr')->isoFormat('D MMM YYYY') }}</span>
                <button type="button" wire:click="cancelDeletion" class="btn btn-ghost btn-sm">Me rétracter</button>
            </div>
        </div>
    @else
        <div class="sect-head" style="margin-top:4px"><span class="sect-title">Zone danger</span></div>
        <div class="card card-pad" style="border-color:var(--danger)">
            <div style="font-weight:700;font-size:14px">Supprimer mon compte</div>
            <div class="meta" style="font-size:13px;margin-top:4px;line-height:1.5">Une demande est envoyée à l'admin. La suppression intervient après un délai minimum de 7 jours et la validation d'un admin.</div>
            @if ($lastAdmin)
                <x-banner kind="warn" style="margin-top:12px">Tu es le dernier administrateur actif du club. Transfère le rôle admin à un autre membre avant de pouvoir demander la suppression.</x-banner>
            @elseif (($p1Wards ?? 0) > 0)
                {{-- Seconde garde de MemberService::requestDeletion (§4.2) : sans ça, le bouton
                     ouvrait toute la modale de conséquences pour finir en refus. --}}
                <x-banner kind="warn" style="margin-top:12px">Tu es garant·e d'un mineur sans compte propre. Autonomise l'enfant ou rattache-le à un autre garant avant de pouvoir demander la suppression.</x-banner>
            @else
                <button type="button" wire:click="confirmDeleteAccount" class="btn btn-danger btn-sm" style="margin-top:12px">
                    <x-icon name="trash" :size="15" /> Supprimer mon compte
                </button>
            @endif
        </div>
    @endif
</div>

{{-- Modale de confirmation (§4.3) --}}
@if ($showDeleteDialog)
    <x-dialog title="Supprimer mon compte ?" sub="Demande soumise au bureau du club" :width="440" danger close="dismissDeleteDialog">
        <x-banner kind="warn">Ta demande n'est <b>pas immédiate</b> : elle doit être validée par un admin, après un <b>délai minimum de 7 jours</b>.</x-banner>
        <div class="eyebrow" style="margin-top:16px;margin-bottom:10px">Ce qui se passe ensuite</div>
        <div style="display:flex;flex-direction:column;gap:12px">
            <x-conseq-row icon="clock" label="Délai de 7 jours">Le bureau peut traiter ou refuser ta demande pendant cette période.</x-conseq-row>
            <x-conseq-row icon="shield" label="Validation par l'admin">Un administrateur du club valide la suppression définitive.</x-conseq-row>
            <x-conseq-row icon="user" label="Données anonymisées">Tes infos personnelles sont effacées ; ton historique de séances est anonymisé (les stats du club restent justes).</x-conseq-row>
        </div>
        <div style="margin-top:16px"><x-banner kind="info">Tu pourras te <b>rétracter</b> tant que le bureau n'a pas validé ta demande.</x-banner></div>
        <x-slot:footer>
            <button type="button" wire:click="dismissDeleteDialog" class="btn btn-ghost">Annuler</button>
            <button type="button" wire:click="requestDeletion" class="btn btn-danger">Envoyer la demande</button>
        </x-slot:footer>
    </x-dialog>
@endif
