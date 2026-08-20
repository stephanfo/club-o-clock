{{-- Carte enfant (screen-parent.jsx) — header, prochaine séance + Inscrire, semaine, tutelle.
     Reçoit : $c (ward/phase/age/cat/nextRegistered/nextOpen/quotaFull), $tz, $pad. --}}
@php
    $w = $c['ward'];
    $registered = $c['nextRegistered'];
    $open = $c['nextOpen'];
    // Teinte de l'avatar : discipline de la 1re séance à venir (inscrite ou ouverte).
    $tintSession = $registered->first() ?? $open;
    $tintCls = $tintSession?->discipline?->colorClass() ?? 'prep';
    $tint = in_array($tintCls, ['swim', 'bike', 'run'], true) ? 'tint-'.$tintCls : null;
@endphp
<div class="card" style="overflow:hidden" wire:key="child-{{ $w->id }}">
    <div class="flex ac g10" style="padding:{{ $pad }}px;border-bottom:1px solid var(--divider);background:var(--bg-alt)">
        <x-avatar :name="$w->fullName()" :tint="$tint" />
        <div class="f1">
            <div class="dsp-7" style="font-size:18px">{{ $w->fullName() }}</div>
            <div class="meta" style="font-size:12px">{{ $c['age'] !== null ? $c['age'].' ans · ' : '' }}{{ $c['cat'] ? $c['cat'].' · ' : '' }}phase {{ $c['phase'] }}</div>
        </div>
    </div>
    <div style="padding:{{ $pad }}px">
        <div class="eyebrow" style="margin-bottom:8px">Prochaines séances</div>
        {{-- Le lien de la carte porte le sujet enfant (?as=, via :linkAs) : la fiche pose le sujet
             côté serveur au GET (pas de wire:click concurrent → pas de course avec wire:navigate)
             puis redirige vers l'URL canonique sans ?as= (reload/back ne rebasculent pas le sujet).
             Le parent consulte/gère la séance AU NOM de l'enfant. --}}
        @if ($registered->isNotEmpty())
            <div class="home-cards">
                @foreach ($registered as $ns)
                    <x-session-card :session="$ns" :tz="$tz" variant="row"
                        :viewAs="$w->id" :linkAs="$w->id" :subjectName="$w->first_name"
                        wire:key="reg-{{ $w->id }}-{{ $ns->id }}" />
                @endforeach
            </div>
        @elseif ($open)
            {{-- Enfant non inscrit : même bascule via l'URL, la fiche permet ensuite l'inscription. --}}
            <div class="home-cards">
                <x-session-card :session="$open" :tz="$tz" variant="row"
                    :viewAs="$w->id" :linkAs="$w->id" :subjectName="$w->first_name" />
            </div>
        @else
            <div class="meta">Aucune séance à venir.</div>
        @endif
        @if ($c['quotaFull'])
            <x-banner kind="warn"><span><b>{{ $w->first_name }}</b> a déjà {{ $c['quotaFull']['used'] }}/{{ $c['quotaFull']['max'] }} sur #{{ $c['quotaFull']['tag'] }} cette semaine — une inscription de plus passera en waitlist quota.</span></x-banner>
        @endif

        <div class="sect-head" style="margin-top:16px"><span class="sect-title">Lien de tutelle</span></div>
        <div class="flex ac jb g8">
            <span class="meta" style="font-size:12.5px;line-height:1.4">Phase {{ $c['phase'] }} · {{ $c['phase'] === 'P1' ? 'tu agis en son nom' : 'enfant + parent destinataires' }}</span>
            {{-- L'autonomisation exige un mineur (GuardianshipService::invite) : un pupille devenu
                 majeur garde son garant (MemberService::updateDob), le bouton mènerait au refus.
                 Le geste pertinent devient « Rompre la tutelle ». --}}
            @if ($c['phase'] === 'P1' && $w->is_minor)
                <button class="btn btn-ghost btn-sm" style="flex:0 0 auto" wire:click="openInvite({{ $w->id }})">
                    <x-icon name="user-plus" :size="14" /> Accès autonome
                </button>
            @elseif ($c['phase'] === 'P1')
                <button class="btn btn-ghost btn-sm" style="flex:0 0 auto" wire:click="openSever({{ $w->id }})">
                    <x-icon name="log-out" :size="14" /> Rompre la tutelle
                </button>
            @else
                <button class="btn btn-ghost btn-sm" style="flex:0 0 auto" wire:click="openSever({{ $w->id }})">
                    <x-icon name="log-out" :size="14" /> Rompre la tutelle
                </button>
            @endif
        </div>
    </div>
</div>
