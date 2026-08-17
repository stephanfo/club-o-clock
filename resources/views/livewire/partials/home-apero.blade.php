{{-- « Apéro à venir » (§4.14.5 affordance 3) — porté de screen-home.jsx. Reçoit $aperoUpcoming, $tz. --}}
@if ($aperoUpcoming->isNotEmpty())
    <div>
        <div class="sect-head"><span class="sect-title">Apéro à venir</span><x-chope :size="15" style="color:var(--apero)" /></div>
        <div class="card" style="overflow:hidden">
            @foreach ($aperoUpcoming as $s)
                @php $loc = $s->location_text ?: $s->location?->name; @endphp
                <a href="{{ route('sessions.show', $s) }}" wire:navigate class="row row-press"
                   style="padding:12px 14px;{{ ! $loop->last ? 'border-bottom:1px solid var(--divider)' : '' }}">
                    <span class="apero-dot"><x-chope :size="14" /></span>
                    <div class="f1" style="min-width:0">
                        <div style="font-weight:700;font-size:14px">{{ $s->title }}</div>
                        <div class="meta">{{ $s->start_at->copy()->setTimezone($tz)->locale('fr')->isoFormat('ddd HH:mm') }}{{ $loc ? ' · '.$loc : '' }}</div>
                    </div>
                    <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
                </a>
            @endforeach
        </div>
    </div>
@endif
