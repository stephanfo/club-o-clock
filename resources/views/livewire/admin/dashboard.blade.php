{{-- Dashboard statistiques bureau (PRD §4.16 — J6.6) — porté de screen-admin.jsx AdminDashboard.
     Filtres globaux (période/discipline/catégorie) + indicateurs + export XLSX. Admin uniquement. --}}
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar ─── --}}
    <div class="dk-topbar">
        <div class="f1">
            <div class="dsp" style="font-size:26px">Dashboard admin</div>
            <div class="meta">{{ $periodLabel }}</div>
        </div>
        <button type="button" wire:click="export" class="btn btn-ghost btn-sm" wire:loading.attr="disabled" wire:target="export">
            <x-icon name="download" :size="15" /> XLSX
        </button>
    </div>

    <div class="dk-body">
        <div style="max-width:1000px;margin:0 auto;display:flex;flex-direction:column;gap:18px">

            {{-- ═══ Filtres globaux (§4.16.1) ═══ --}}
            <div class="flex ac g12 wrap">
                <x-segmented :items="[['v'=>'season','l'=>'Saison'],['v'=>'30d','l'=>'30 j'],['v'=>'90d','l'=>'90 j'],['v'=>'12m','l'=>'12 mois']]"
                             :value="$period" wire-method="setPeriod" />
                <select wire:model.live="discipline" class="input" style="width:auto;min-width:150px">
                    <option value="">Toutes disciplines</option>
                    @foreach ($disciplines as $d)
                        <option value="{{ $d->id }}">{{ $d->label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="category" class="input" style="width:auto;min-width:150px">
                    <option value="">Toutes catégories</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}">{{ $c->label }}</option>
                    @endforeach
                </select>
            </div>

            {{-- Bandeau d'alerte douce — comptes éligibles à suppression définitive (§4.16) --}}
            @if ($eligibleCount > 0)
                <x-banner kind="warn">
                    <div class="flex ac jb">
                        <span><b>{{ $eligibleCount }} compte{{ $eligibleCount > 1 ? 's' : '' }} éligible{{ $eligibleCount > 1 ? 's' : '' }} à suppression définitive</b> (suspendu depuis plus de 7 jours)</span>
                        <a href="{{ route('admin.members', ['access' => 'eligible']) }}" class="underline-link" wire:navigate>Voir</a>
                    </div>
                </x-banner>
            @endif

            {{-- ═══ KPI ═══ --}}
            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px">
                <div class="stat">
                    <div class="n">{{ $headline['active'] }}</div><div class="l">adhérents actifs</div>
                    @if ($headline['new_since_season'] > 0)
                        <div class="meta" style="font-size:11px;margin-top:4px">+{{ $headline['new_since_season'] }} depuis sept.</div>
                    @endif
                </div>
                <div class="stat">
                    <div class="n">{{ $headline['fill_rate'] !== null ? $headline['fill_rate'].'%' : '—' }}</div><div class="l">taux de remplissage</div>
                    <div class="meta" style="font-size:11px;margin-top:4px">séances training</div>
                </div>
                <div class="stat">
                    <div class="n">{{ $headline['competitions'] }}</div><div class="l">compétitions</div>
                    <div class="meta" style="font-size:11px;margin-top:4px">{{ $periodLabel }}</div>
                </div>
                <div class="stat">
                    <div class="n">{{ $headline['overrides'] }}</div><div class="l">overrides coach</div>
                </div>
            </div>

            {{-- ═══ Évolution mensuelle + Top séances ═══ --}}
            <div style="display:grid;grid-template-columns:1.4fr 1fr;gap:16px">
                <div class="card card-pad">
                    <div class="flex ac jb"><div class="eyebrow">Évolution des inscriptions · mensuel</div><span class="meta" style="font-size:12px">{{ $periodLabel }}</span></div>
                    @php $maxBar = max(1, collect($monthly)->max('count')); @endphp
                    <div style="display:flex;align-items:flex-end;gap:14px;height:130px;margin-top:14px;padding:0 4px">
                        @foreach ($monthly as $m)
                            <div class="f1 flex col ac" style="justify-content:flex-end;height:100%">
                                <div title="{{ $m['count'] }} inscription{{ $m['count'] > 1 ? 's' : '' }}"
                                     style="width:100%;height:{{ round($m['count'] / $maxBar * 100) }}%;{{ $m['count'] > 0 ? 'min-height:3px;' : '' }}background:var(--brand);border-radius:4px 4px 0 0"></div>
                                <div class="meta" style="font-size:11px;margin-top:6px">{{ $m['label'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="card card-pad">
                    <div class="eyebrow" style="margin-bottom:12px">Top séances · remplissage</div>
                    <div style="display:flex;flex-direction:column;gap:10px">
                        @forelse ($topSessions as $i => $s)
                            <div class="flex ac g8" style="font-size:13px">
                                <span class="muted" style="width:14px">{{ $i + 1 }}</span>
                                <span class="f1" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $s['title'] }}</span>
                                <div class="qbar{{ $s['fill'] >= 100 ? ' full' : '' }}" style="max-width:80px"><i style="width:{{ min(100, $s['fill']) }}%"></i></div>
                                <span class="num" style="font-size:13px;width:38px;text-align:right">{{ $s['fill'] }}%</span>
                            </div>
                        @empty
                            <div class="meta" style="font-size:13px">Aucune séance à capacité sur la période.</div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- ═══ Liste d'attente ═══ --}}
            <div class="card card-pad">
                <div class="flex ac jb" style="margin-bottom:12px"><div class="eyebrow">Liste d'attente</div><span class="meta" style="font-size:12px">demande non satisfaite · {{ $periodLabel }}</span></div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
                    <div><div class="num" style="font-size:28px">{{ $waitlist['total'] }}</div><div class="meta" style="font-size:12px;margin-top:2px">inscriptions en file d'attente</div></div>
                    <div><div class="num" style="font-size:28px">{{ $waitlist['capacity'] }} <span class="meta" style="font-size:13px">+ {{ $waitlist['quota'] }}</span></div><div class="meta" style="font-size:12px;margin-top:2px">file capacité · file quota</div></div>
                    <div><div class="num" style="font-size:28px;color:var(--brand-700)">{{ $waitlist['promotion_rate'] !== null ? $waitlist['promotion_rate'].'%' : '—' }}</div><div class="meta" style="font-size:12px;margin-top:2px">taux de promotion vers inscrit·e</div></div>
                </div>
            </div>

            {{-- ═══ Activité coachs ═══ --}}
            <div class="card card-pad">
                <div class="flex ac jb" style="margin-bottom:10px"><div class="eyebrow">Activité coachs</div><span class="meta" style="font-size:12px">séances training · {{ $periodLabel }}</span></div>
                <table class="tbl">
                    <thead>
                        <tr>
                            <th>Coach</th>
                            @foreach ($coachActivity['disciplines'] as $d)<th>{{ $d->label }}</th>@endforeach
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($coachActivity['rows'] as $row)
                            <tr>
                                <td style="font-weight:700">{{ $row['coach'] }}</td>
                                @foreach ($coachActivity['disciplines'] as $d)<td>{{ $row['by_discipline'][$d->id] ?? 0 }}</td>@endforeach
                                <td style="font-weight:700">{{ $row['total'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ $coachActivity['disciplines']->count() + 2 }}" class="meta" style="text-align:center">Aucune séance encadrée sur la période.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                @if ($coachActivity['future_without_coach'] > 0)
                    <x-banner kind="warn" style="margin-top:12px">
                        <span><b>{{ $coachActivity['future_without_coach'] }} séance{{ $coachActivity['future_without_coach'] > 1 ? 's' : '' }} future{{ $coachActivity['future_without_coach'] > 1 ? 's' : '' }}</b> sans coach inscrit · <a href="{{ route('planning') }}" class="underline-link" wire:navigate>voir le planning</a></span>
                    </x-banner>
                @endif
            </div>

        </div>
    </div>
</div>
