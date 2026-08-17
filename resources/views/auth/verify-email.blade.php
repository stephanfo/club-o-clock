@extends('layouts.guest')

{{-- Vérification email — porté du style screen-auth.jsx SentBody (centré, cercle icône). --}}
@section('content')
<div class="auth-dk">
    @include('auth.partials.brand-panel')

    <div class="auth-dk-right">
        <div class="auth-card">
            <div class="auth-body auth-center">
                <div class="auth-icon-circle"><x-icon name="mail" :size="30" /></div>
                <div class="dsp" style="font-size:24px;text-align:center;color:var(--ink)">Vérifie ta boîte mail</div>
                <p class="auth-sub-c">
                    On t'a envoyé un lien de vérification.<br>Clique dessus pour activer ton accès.
                </p>

                @if (session('status') === 'verification-link-sent')
                    <div class="banner banner-info">Un nouveau lien de vérification a été envoyé.</div>
                @endif

                <div class="auth-stack" style="width:100%">
                    <form method="POST" action="{{ route('verification.send') }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-block">
                            <x-icon name="refresh-cw" :size="16" /> Renvoyer le lien
                        </button>
                    </form>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="auth-link auth-link-center">
                            <x-icon name="arrow-left" :size="15" /> Se déconnecter
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
