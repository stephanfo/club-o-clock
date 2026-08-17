{{-- Bloc « Mes quotas · cette semaine » — porté de screen-home.jsx (lignes 84-96 mobile, 191-202 desktop).
     Reçoit $weekQuotas (array [{tag, used, max}]) depuis Home::render(). --}}
@if (count($weekQuotas))
    <div>
        <div class="sect-head"><span class="sect-title">Mes quotas · cette semaine</span></div>
        <div class="card card-pad" style="display:flex;flex-direction:column;gap:10px">
            @foreach ($weekQuotas as $q)
                @php $full = $q['used'] >= $q['max']; @endphp
                <div class="flex ac g10">
                    <span class="chip chip-sm chip-tag chip-line">{{ $q['tag'] }}</span>
                    <x-quota-bar :used="$q['used']" :max="$q['max']" />
                    <span class="num" style="font-size:16px">{{ $q['used'] }}<span style="font-size:12px;color:var(--fg-muted)">/{{ $q['max'] }}</span></span>
                </div>
            @endforeach
        </div>
    </div>
@endif
