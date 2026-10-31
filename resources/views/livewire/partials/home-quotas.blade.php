{{-- Bloc « Mes quotas · cette semaine » — porté de screen-home.jsx (lignes 84-96 mobile, 191-202 desktop).
     Reçoit $weekQuotas (array [{tag, used, max, sessions}]) depuis Home::render(). Seul affichage des
     compteurs (§4.10) depuis le retrait de l'onglet Quotas du profil : séances comptées + alerte
     quota atteint y ont été reprises. --}}
@if (count($weekQuotas))
    <div>
        <div class="sect-head"><span class="sect-title">Mes quotas · cette semaine</span></div>
        <div class="card card-pad" style="display:flex;flex-direction:column;gap:10px">
            @foreach ($weekQuotas as $q)
                @php $full = $q['used'] >= $q['max']; @endphp
                <div>
                    <div class="flex ac g10">
                        <span class="chip chip-sm chip-tag chip-line">{{ $q['tag'] }}</span>
                        <x-quota-bar :used="$q['used']" :max="$q['max']" />
                        <span class="num" style="font-size:16px">{{ $q['used'] }}<span style="font-size:12px;color:var(--fg-muted)">/{{ $q['max'] }}</span></span>
                    </div>
                    @if (count($q['sessions']))
                        <div class="meta" style="font-size:12px;margin-top:4px">
                            @foreach ($q['sessions'] as $s)
                                <div>· {{ $s }}</div>
                            @endforeach
                        </div>
                    @endif
                    @if ($full)
                        <div class="flex ac g6" style="margin-top:4px;color:var(--warning-text);font-size:12px;font-weight:600">
                            <x-icon name="alert-triangle" :size="14" /> Quota atteint — prochaine inscription en file quota
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
