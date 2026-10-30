{{-- Trombinoscope à l'accueil (§4.23.6) — « Anniversaires de la semaine » (jour et prénom), masqué
     s'il est vide ; sur mobile, l'entrée vers le trombinoscope (la barre du bas n'en a pas).
     Reçoit $weekBirthdays, $canTrombi, $tz et $withLink. --}}
@if ($weekBirthdays !== [])
    <div>
        <div class="sect-head"><span class="sect-title">Anniversaires de la semaine</span></div>
        <div class="card" style="overflow:hidden">
            @foreach ($weekBirthdays as $b)
                @php $today = $b['date']->isSameDay(now($tz)); @endphp
                <a href="{{ route('trombi.show', $b['id']) }}" wire:navigate class="row row-press"
                   wire:key="bday-home-{{ $withLink ? 'm' : 'dk' }}-{{ $b['id'] }}"
                   style="padding:10px 14px;{{ ! $loop->last ? 'border-bottom:1px solid var(--divider)' : '' }}">
                    <x-avatar :name="$b['first_name']" size="sm" tint="tint-run" :photo="$b['photo']" />
                    <div class="f1" style="min-width:0;font-weight:700;font-size:14px">{{ $b['first_name'] }}</div>
                    @if ($today)
                        <span class="chip chip-sm chip-green">aujourd'hui</span>
                    @else
                        <span class="meta">{{ $b['date']->locale('fr')->isoFormat('ddd D MMM') }}</span>
                    @endif
                </a>
            @endforeach
        </div>
    </div>
@endif
@if ($withLink && $canTrombi)
    <a href="{{ route('trombi') }}" wire:navigate class="card row row-press" style="padding:12px 14px">
        <x-icon name="users" :size="18" style="color:var(--fg-muted);flex:0 0 auto" />
        <span class="f1" style="font-weight:700;font-size:14px">Trombinoscope du club</span>
        <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
    </a>
@endif
