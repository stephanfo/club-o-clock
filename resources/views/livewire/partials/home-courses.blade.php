{{-- « Côté courses » (§4.12.5, #104) — débriefs publiés depuis moins de 15 jours, une ligne par
     compétition, la plus récemment débriefée en tête. Même structure que home-apero. Reçoit $recentDebriefs, $tz. --}}
@if ($recentDebriefs->isNotEmpty())
    <div>
        <div class="sect-head"><span class="sect-title">Côté courses</span><x-icon name="trophy" :size="15" style="color:var(--fg-muted)" /></div>
        <div class="card" style="overflow:hidden">
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
