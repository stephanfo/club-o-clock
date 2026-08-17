@extends('layouts.guest')

{{-- Mot de passe oublié — split héros + carte (cohérent screen-auth.jsx). --}}
@section('content')
<div class="auth-dk">
    @include('auth.partials.brand-panel')

    <div class="auth-dk-right">
        <div class="auth-card">
            <div class="eyebrow eyebrow-pink">Sécurité</div>
            <div class="dsp" style="font-size:40px;color:var(--ink);margin-top:6px">Mot de passe</div>
            <div class="meta" style="font-size:var(--text-sm);margin-top:6px;margin-bottom:22px">On t'envoie un lien pour en choisir un nouveau.</div>

            <div class="auth-body">
                @if (session('status'))
                    <div class="banner banner-info">{{ session('status') }}</div>
                @endif
                @error('email')<div class="banner banner-danger">{{ $message }}</div>@enderror

                <form method="POST" action="{{ route('password.email') }}" class="auth-stack">
                    @csrf
                    <div>
                        <label class="field-label">Ton adresse e-mail</label>
                        <div class="ifield">
                            <x-icon name="mail" :size="18" />
                            <input class="ifield-input" type="email" name="email" placeholder="ton@email.fr" value="{{ old('email') }}" autofocus>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block btn-lg">
                        <x-icon name="send" :size="17" /> Envoyer le lien
                    </button>
                </form>

                <button type="button" class="auth-link auth-link-center" onclick="location.href='{{ route('login') }}'">
                    <x-icon name="arrow-left" :size="15" /> Retour à la connexion
                </button>
            </div>
        </div>
    </div>
</div>
@endsection
