{{-- Corps du formulaire de connexion — device-agnostic, porté de screen-auth.jsx LoginBody.
     Réutilisé par la coquille mobile (auth-sheet) ET desktop (auth-card).
     Toggle magic/pwd en CSS pur (radios :checked) → zéro JS.
     $scope : préfixe unique des id/name de radios (les 2 coquilles coexistent dans le DOM).

     Les moyens coupés par le club (§4.17) disparaissent d'ici. Le mot de passe n'est jamais coupé,
     donc l'écran garde toujours au moins un formulaire. Attention au CSS du toggle : les panes sont
     masqués par défaut et révélés par le radio `:checked` — quand le lien magique est absent, il
     faut sortir le formulaire mot de passe du wrapper `.auth-method`, sinon rien ne s'affiche. --}}
@php($scope = $scope ?? 'dk')
@php($authMethods = app(App\Services\AuthMethodService::class))
@php($magicOn = $authMethods->magicLinkEnabled())
@php($googleOn = $authMethods->googleEnabled())
<div class="auth-body">
    @if ($errors->any())
        <x-banner kind="danger">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </x-banner>
    @endif
    @if (session('status'))
        <x-banner kind="info">{{ session('status') }}</x-banner>
    @endif

    {{-- Démo publique (OS7) : l'avertissement et les identifiants passent AVANT les formulaires —
         un visiteur doit savoir où il est avant de chercher comment entrer. --}}
    @if (App\Support\DemoMode::enabled())
        @include('auth.partials.demo-accounts')
    @endif

    {{-- Google — le séparateur « ou » n'a plus rien à séparer si le bouton disparaît. --}}
    @if ($googleOn)
        <a href="{{ route('oauth.redirect', 'google') }}" class="btn btn-block auth-google">
            <x-google-g /> Continuer avec Google
        </a>

        <div class="auth-or"><span>ou</span></div>
    @endif

    @if ($magicOn)
        {{-- Toggle méthode en CSS pur : 2 radios pilotent l'affichage des 2 formulaires. --}}
        <div class="auth-method">
            <input type="radio" name="auth-method-{{ $scope }}" id="{{ $scope }}-magic" class="auth-method-radio" checked>
            <input type="radio" name="auth-method-{{ $scope }}" id="{{ $scope }}-pwd" class="auth-method-radio">

            <div class="auth-seg">
                <div class="seg">
                    <label for="{{ $scope }}-magic" class="seg-item seg-magic">Lien magique</label>
                    <label for="{{ $scope }}-pwd" class="seg-item seg-pwd">Mot de passe</label>
                </div>
            </div>

            {{-- Lien magique --}}
            <form method="POST" action="{{ route('magic-link.send') }}" class="auth-stack pane-magic">
                @csrf
                <div>
                    <label class="field-label">Ton adresse e-mail</label>
                    <div class="ifield">
                        <x-icon name="mail" :size="18" />
                        <input class="ifield-input" type="email" name="email" placeholder="ton@email.fr" value="{{ old('email') }}">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                    <x-icon name="send" :size="17" /> Envoyer le lien
                </button>
                <p class="auth-fine">Un lien de connexion sans mot de passe, valable <b>15&nbsp;min</b>, t'attend dans ta boîte mail.</p>
                {{-- Chemin de l'application installée : le lien a pu être demandé depuis le
                     navigateur, où la session ne vaut pas ici (§4.1.1). --}}
                <a href="{{ route('magic-link.code') }}" class="auth-link auth-link-center">J'ai déjà reçu un code</a>
            </form>

            @include('auth.partials.login-password', ['pane' => true])
        </div>
    @else
        {{-- Lien magique coupé : le formulaire mot de passe est rendu SEUL, hors de `.auth-method`
             (sinon le CSS des panes le masquerait) et sans le segment de bascule, qui n'a plus
             qu'une seule option. --}}
        @include('auth.partials.login-password', ['pane' => false])
    @endif

    {{-- Repère pour l'adhérent qui cherche un bouton devenu absent (§4.17).
         En démo, le lien magique est coupé par le MODE, pas par le bureau : dire « désactivée par
         le bureau » sur une instance vitrine ferait croire à un choix du club, alors que c'est une
         conséquence du mailer neutralisé. On explique donc la vraie raison — et on en profite pour
         signaler que la fonctionnalité existe bel et bien, pour ne pas la faire passer pour absente
         du produit. --}}
    @unless ($magicOn && $googleOn)
        <p class="auth-fine auth-fine-foot">
            @unless ($magicOn)
                @if (App\Support\DemoMode::enabled())
                    La connexion par <b>lien magique</b> existe dans l'application, mais elle est
                    désactivée ici : cette démo n'envoie aucun email.
                @else
                    La connexion par lien a été désactivée par le bureau.
                @endif
            @endunless
            @unless ($googleOn) La connexion Google n'est pas proposée sur ce club. @endunless
        </p>
    @endunless

    <p class="auth-fine auth-fine-foot">
        Pas encore de compte ? L'inscription se fait par <b>invitation du bureau</b>.
    </p>
    <p class="auth-fine">
        <a href="{{ route('legal') }}">Mentions légales &amp; confidentialité</a>
    </p>
</div>
