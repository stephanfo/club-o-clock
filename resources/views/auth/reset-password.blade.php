@extends('layouts.guest')

{{-- Réinitialisation mot de passe — porté de screen-auth.jsx ResetBody. --}}
@section('content')
<div class="auth-dk">
    @include('auth.partials.brand-panel')

    <div class="auth-dk-right">
        <div class="auth-card">
            <div class="eyebrow eyebrow-pink">Sécurité</div>
            <div class="dsp" style="font-size:40px;color:var(--ink);margin-top:6px">Mot de passe</div>
            <div class="meta" style="font-size:var(--text-sm);margin-top:6px;margin-bottom:22px">Choisis un nouveau mot de passe.</div>

            <div class="auth-body">
                @if ($errors->any())
                    <div class="banner banner-danger">
                        @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('password.update') }}" class="auth-stack">
                    @csrf
                    <input type="hidden" name="token" value="{{ $request->route('token') }}">

                    <div>
                        <label class="field-label">E-mail</label>
                        <div class="ifield">
                            <x-icon name="mail" :size="18" />
                            <input class="ifield-input" type="email" name="email" value="{{ old('email', $request->email) }}" autofocus>
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Nouveau mot de passe</label>
                        <div class="ifield">
                            <x-icon name="lock" :size="18" />
                            <input class="ifield-input" type="password" name="password" placeholder="{{ \App\Support\PasswordPolicy::placeholder() }}">
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Confirme le nouveau</label>
                        <div class="ifield">
                            <x-icon name="lock" :size="18" />
                            <input class="ifield-input" type="password" name="password_confirmation" placeholder="Répète le mot de passe">
                        </div>
                    </div>

                    <div class="banner banner-info">{{ \App\Support\PasswordPolicy::hint() }}</div>

                    <button type="submit" class="btn btn-primary btn-block btn-lg">Mettre à jour</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
