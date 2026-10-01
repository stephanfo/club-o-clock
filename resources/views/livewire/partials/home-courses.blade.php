{{-- « Côté courses » (§4.12.5, #104 et #105) — un seul bloc pour ce qui se passe côté compétitions :
     d'abord les inscriptions qui ouvrent (J-14 → J+3, catégories du sujet), puis les débriefs publiés
     depuis moins de 15 jours, la compétition la plus récemment débriefée en tête. Même structure
     que home-apero. Reçoit $openings, $recentDebriefs, $tz. --}}
@if ($openings->isNotEmpty() || $recentDebriefs->isNotEmpty())
    <div>
        <div class="sect-head"><span class="sect-title">Côté courses</span><x-icon name="trophy" :size="15" style="color:var(--fg-muted)" /></div>
        <div class="card" style="overflow:hidden">
            @foreach ($openings as $s)
                <a href="{{ route('sessions.show', $s) }}" wire:navigate class="row row-press" data-ouverture="{{ $s->id }}"
                   style="padding:12px 14px;{{ ! $loop->last || $recentDebriefs->isNotEmpty() ? 'border-bottom:1px solid var(--divider)' : '' }}">
                    <div class="f1" style="min-width:0">
                        <div style="font-weight:700;font-size:14px">{{ $s->title }}</div>
                        <div class="meta">Course le {{ $s->start_at->copy()->setTimezone($tz)->locale('fr')->isoFormat('ddd D MMM') }}</div>
                        <div style="margin-top:6px"><x-registration-opening :session="$s" /></div>
                    </div>
                    <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
                </a>
            @endforeach
            @foreach ($recentDebriefs as $s)
                @php
                    $n = $s->debriefs->count();
                    $auteurs = $s->debriefs->pluck('author.first_name')->filter()->unique()->implode(', ');
                @endphp
                <a href="{{ route('sessions.show', ['session' => $s, 'tab' => 'debriefs']) }}" wire:navigate class="row row-press"
                   style="padding:12px 14px;{{ ! $loop->last ? 'border-bottom:1px solid var(--divider)' : '' }}">
                    <div class="f1" style="min-width:0">
                        <div style="font-weight:700;font-size:14px">{{ $s->title }}</div>
                        <div class="meta">{{ $s->start_at->copy()->setTimezone($tz)->locale('fr')->isoFormat('ddd D MMM') }} · {{ $n }} débrief{{ $n > 1 ? 's' : '' }}{{ $auteurs !== '' ? ' · '.$auteurs : '' }}</div>
                    </div>
                    <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
                </a>
            @endforeach
        </div>
    </div>
@endif
