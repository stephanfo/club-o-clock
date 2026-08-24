{{-- Demande d'un lien de connexion. --}}
@include('auth.partials.auth-feedback')

<form method="POST" action="{{ route('magic-link.send') }}" class="auth-stack">
    @csrf
    <div>
        <label class="field-label" for="{{ $scope }}-ml-email">Ton adresse e-mail</label>
        <div class="ifield">
            <x-icon name="mail" :size="18" />
            <input id="{{ $scope }}-ml-email" class="ifield-input" type="email" name="email"
                placeholder="ton@email.fr" value="{{ old('email') }}" autocomplete="email">
        </div>
    </div>
    <button type="submit" class="btn btn-primary btn-block btn-lg">
        <x-icon name="send" :size="17" /> Envoyer le lien
    </button>
    <p class="auth-fine">Un lien de connexion sans mot de passe, valable <b>15&nbsp;min</b>, t'attend dans ta boîte mail.</p>
</form>

<button type="button" class="auth-link auth-link-center" onclick="location.href='{{ route('login') }}'">
    <x-icon name="arrow-left" :size="15" /> Retour à la connexion
</button>
