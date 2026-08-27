{{-- Corps de l'écran d'activation, partagé par les deux coquilles. --}}
<div class="card card-pad">
    <div class="eyebrow">Ton compte est activé</div>
    <div class="dsp-7" style="font-size:20px;margin-top:4px">Comment veux-tu te connecter ?</div>
    <div class="meta" style="margin-top:8px;line-height:1.5">
        Définir un mot de passe est <b>facultatif</b> — c'est utile pour entrer sans attendre d'email,
        notamment depuis l'application installée sur ton téléphone. Tu pourras le faire plus tard
        depuis ton profil.
    </div>
</div>

{{-- Poser un mot de passe (optionnel, §4.1.3). Le compte est passwordless : aucun ancien à prouver. --}}
<div class="card card-pad">
    <div class="sect-title" style="margin-bottom:10px">Définir un mot de passe</div>
    <div style="display:flex;flex-direction:column;gap:10px">
        <div>
            <label class="field-label" for="ac-pwd">Mot de passe</label>
            <input id="ac-pwd" type="password" class="input" autocomplete="new-password" wire:model.blur="password">
            @error('password') <div class="meta" style="color:var(--danger);font-size:12px;margin-top:4px">{{ $message }}</div> @enderror
            {{-- La règle est annoncée AVANT la saisie, et sort de PasswordPolicy : c'est la même
                 source que la validation, donc les deux ne peuvent plus diverger. --}}
            <div class="meta" style="font-size:12px;margin-top:4px">{{ \App\Support\PasswordPolicy::hint() }}</div>
        </div>
        <div>
            <label class="field-label" for="ac-pwd2">Confirmer</label>
            <input id="ac-pwd2" type="password" class="input" autocomplete="new-password" wire:model.blur="password_confirmation">
        </div>
        <button type="button" wire:click="definePassword" class="btn btn-primary btn-block"
            wire:loading.attr="disabled" wire:target="definePassword">
            Définir et entrer
        </button>
    </div>
</div>

{{-- Google (§4.17). Carte à part entière, au même niveau que le mot de passe : noyée dans le
     paragraphe « continuer sans », l'option passait inaperçue et les invités croyaient Google absent.
     PAS de bouton `oauth.redirect` ici — le compte est DÉJÀ connecté à ce stade, l'aller-retour
     OAuth le ramènerait où il est, et son `intended(dashboard)` court-circuiterait cet écran (donc
     l'occasion de poser un mot de passe). Le rattachement se fait tout seul au prochain login par
     l'email vérifié (OAuthController::callback), qu'on se contente d'annoncer.
     La condition sur l'adresse reste VISIBLE : un Gmail personnel ≠ email club échoue au login, et
     l'échec silencieux serait plus frustrant que la mise en garde. --}}
@if ($googleOn)
    <div class="card card-pad">
        <div class="flex ac g10" style="margin-bottom:10px">
            <x-google-g :size="20" />
            <div class="sect-title">Se connecter avec Google</div>
        </div>
        <div class="meta" style="line-height:1.5">
            Rien à faire maintenant : au prochain passage, choisis <b>Continuer avec Google</b> sur
            l'écran de connexion. Ton compte Google sera rattaché automatiquement, <b>à condition
            que son adresse soit {{ $user->email }}</b>.
        </div>
    </div>
@endif

{{-- Continuer sans. On n'annonce que les moyens réellement ouverts par le club (§4.17). --}}
<div class="card card-pad">
    <div class="sect-title" style="margin-bottom:10px">Continuer sans mot de passe</div>
    <div class="meta" style="line-height:1.5">
        @if ($magicOn)
            À chaque connexion, tu demanderas un <b>lien</b> envoyé à {{ $user->email }}.
        @elseif (! $googleOn)
            Tu devras définir un mot de passe pour te reconnecter — le club n'a pas ouvert la
            connexion par lien.
        @else
            Le club n'a pas ouvert la connexion par lien : tu entreras par Google ou par mot de passe.
        @endif
    </div>
    <button type="button" wire:click="skip" class="btn btn-ghost btn-block" style="margin-top:10px"
        wire:loading.attr="disabled" wire:target="skip">
        Plus tard, entrer maintenant
    </button>
</div>
