{{-- Formulaire mot de passe — extrait de login-body pour être rendu dans les deux dispositions :
     • $pane = true  → dans le toggle CSS (.pane-pwd), révélé par le radio « Mot de passe » ;
     • $pane = false → seul, quand le club a coupé le lien magique (§4.17). La classe .pane-pwd
       est alors OMISE : le CSS la masque par défaut et il n'y a plus de radio pour la révéler. --}}
@php($pane = $pane ?? true)
<form method="POST" action="{{ route('login') }}" class="auth-stack{{ $pane ? ' pane-pwd' : '' }}">
    @csrf
    <div>
        <label class="field-label">E-mail</label>
        <div class="ifield">
            <x-icon name="mail" :size="18" />
            <input class="ifield-input" type="email" name="email" placeholder="ton@email.fr" value="{{ old('email') }}">
        </div>
    </div>
    <div>
        <div class="flex ac jb" style="margin-bottom:6px">
            <label class="field-label" style="margin-bottom:0">Mot de passe</label>
            <a href="{{ route('password.request') }}" class="auth-link">Mot de passe oublié ?</a>
        </div>
        <div class="ifield">
            <x-icon name="lock" :size="18" />
            <input class="ifield-input" type="password" name="password" placeholder="Mot de passe">
        </div>
    </div>
    <label class="flex ac g8" style="font-size:13px">
        <input type="checkbox" name="remember"> Se souvenir de moi
    </label>
    <button type="submit" class="btn btn-primary btn-block btn-lg">Se connecter</button>
</form>
