{{-- Onglet Quotas — porté de screen-profil.jsx PrQuotas. Usage hebdo par tag (§4.10) de la
     semaine courante : barre used/max + séances comptées + alerte quota atteint. Lecture seule. --}}
<div style="display:flex;flex-direction:column;gap:12px">
    <div class="meta">{{ $weekLabel }}</div>

    @forelse ($quotas as $q)
        @php $full = $q['used'] >= $q['max']; @endphp
        <div class="card card-pad">
            <div class="flex ac g10">
                <span class="chip chip-sm chip-tag chip-line">{{ $q['tag'] }}</span>
                <x-quota-bar :used="$q['used']" :max="$q['max']" />
                <span class="num" style="font-size:18px">{{ $q['used'] }}<span class="muted" style="font-size:13px">/{{ $q['max'] }}</span></span>
            </div>
            @if (count($q['sessions']))
                <div class="meta" style="font-size:13px;margin-top:8px">
                    @foreach ($q['sessions'] as $s)
                        <div>· {{ $s }}</div>
                    @endforeach
                </div>
            @endif
            @if ($full)
                <div class="flex ac g6" style="margin-top:8px;color:var(--warning-text);font-size:12px;font-weight:600">
                    <x-icon name="alert-triangle" :size="14" /> Quota atteint — prochaine inscription en file quota
                </div>
            @endif
        </div>
    @empty
        <div class="meta tc" style="padding:24px">Aucun tag de quota défini.</div>
    @endforelse
</div>
