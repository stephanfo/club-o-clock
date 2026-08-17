@extends('layouts.guest')

{{-- Demande de lien magique — split héros + carte. --}}
@section('content')
<div class="auth-dk">
    @include('auth.partials.brand-panel')

    <div class="auth-dk-right">
        <div class="auth-card">
            <div class="eyebrow eyebrow-pink">{{ \App\Models\ClubSettings::current()->name }}</div>
            <div class="dsp" style="font-size:40px;color:var(--ink);margin-top:6px">Connexion</div>
            <div class="meta" style="font-size:var(--text-sm);margin-top:6px;margin-bottom:22px">Reçois un lien de connexion, sans mot de passe.</div>

            <div class="auth-body">
                @if (session('status'))
                    <div class="banner banner-info">{{ session('status') }}</div>
                @endif
                @error('email')<div class="banner banner-danger">{{ $message }}</div>@enderror

                <form method="POST" action="{{ route('magic-link.send') }}" class="auth-stack">
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
                    <p class="auth-fine">Un lien de connexion sans mot de passe, valable <b>15&nbsp;min</b>, t'attend dans ta boîte mail.</p>
                </form>

                <button type="button" class="auth-link auth-link-center" onclick="location.href='{{ route('login') }}'">
                    <x-icon name="arrow-left" :size="15" /> Retour à la connexion
                </button>
            </div>
        </div>
    </div>
</div>
@endsection
