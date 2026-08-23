{{-- Saisie du code à usage unique reçu avec le lien (§4.1.1).

     Pourquoi un écran dédié plutôt qu'un 3e onglet sur l'écran de connexion : le code n'est pas une
     troisième méthode, c'est la seconde moitié du lien magique — il ne veut rien dire tant qu'aucun
     code n'a été demandé. Et le segment de l'écran de connexion est un toggle CSS à deux radios,
     dupliqué dans les deux coquilles : un troisième libellé en capitales y déborderait à 360 px.

     $envoye = true juste après la demande (« un email t'attend »), false quand on arrive avec un
     code déjà reçu depuis un autre contexte de navigation. --}}
@php($envoye = $envoye ?? false)
@include('auth.partials.auth-feedback')

@if ($envoye && $email !== '')
    <x-banner kind="info">
        <div>Un email vient de partir vers <b>{{ $email }}</b>.</div>
    </x-banner>
@endif

<form method="POST" action="{{ route('magic-link.code.verify') }}" class="auth-stack">
    @csrf
    <div>
        <label class="field-label" for="{{ $scope }}-code-email">Ton adresse e-mail</label>
        <div class="ifield">
            <x-icon name="mail" :size="18" />
            <input id="{{ $scope }}-code-email" class="ifield-input" type="email" name="email"
                placeholder="ton@email.fr" value="{{ old('email', $email) }}" autocomplete="email">
        </div>
    </div>
    <div>
        <label class="field-label" for="{{ $scope }}-code">Code reçu par email</label>
        <div class="ifield">
            <x-icon name="lock" :size="18" />
            {{-- type="text" et non "number" : auth/partials/demo-accounts remplit tous les
                 input[type=email] de la page, et un champ numérique perd les zéros de tête.
                 inputmode=numeric fait apparaître le pavé chiffré au téléphone. --}}
            <input id="{{ $scope }}-code" class="ifield-input" type="text" name="code"
                inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code"
                placeholder="123456" style="letter-spacing:0.3em;font-variant-numeric:tabular-nums">
        </div>
    </div>
    <button type="submit" class="btn btn-primary btn-block btn-lg">
        <x-icon name="log-in" :size="17" /> Me connecter
    </button>
    <p class="auth-fine">
        Le code est valable <b>15&nbsp;min</b> et ne sert qu'une fois.
        @if ($envoye)
            <br>Si tu as ouvert l'application depuis ton écran d'accueil, le lien de l'email s'ouvrira
            dans ton navigateur et ne te connectera pas ici : saisis plutôt le code.
        @endif
    </p>
</form>

<div class="flex ac jb g8" style="flex-wrap:wrap;row-gap:8px">
    <a href="{{ route('magic-link.request') }}" class="auth-link">Recevoir un nouveau code</a>
    <a href="{{ route('login') }}" class="auth-link">Retour à la connexion</a>
</div>
