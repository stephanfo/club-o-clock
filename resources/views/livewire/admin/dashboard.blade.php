{{-- Dashboard statistiques bureau (PRD §4.16 — J6.6) — porté de screen-admin.jsx AdminDashboard.
     Filtres globaux (période/discipline/catégorie) + indicateurs + export XLSX. Admin uniquement. --}}
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar ─── --}}
    {{-- Topbar mobile (#151) : le dk-topbar est masqué sous 768px. L'export XLSX reste au bureau. --}}
    <x-topbar title="Dashboard admin" :sub="$periodLabel" :back="route('club')" back-label="Retour au club" />
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

            {{-- ═══ Chiffres clés (§4.16.2, bloc 3) ═══ --}}
            <div class="dash-kpi">
                <div class="stat">
                    <div class="n">{{ $headline['practitioners'] }}</div><div class="l">pratiquants</div>
                    <div class="meta" style="font-size:11px;margin-top:4px">≥ 1 séance · sur {{ $headline['active'] }} comptes athlètes</div>
                </div>
                <div class="stat">
                    <div class="n">{{ $headline['weekly_avg'] ?? '—' }}</div><div class="l">participations / semaine</div>
                    <div class="meta" style="font-size:11px;margin-top:4px">moyenne, semaines avec séances</div>
                </div>
                <div class="stat">
                    <div class="n">{{ $headline['fill_rate'] !== null ? $headline['fill_rate'].'%' : '—' }}</div><div class="l">taux de remplissage</div>
                    <div class="meta" style="font-size:11px;margin-top:4px">{{ $headline['fill_sessions'] }} séance{{ $headline['fill_sessions'] > 1 ? 's' : '' }} à capacité</div>
                </div>
                <div class="stat">
                    <div class="n">{{ $headline['competitors'] }}</div><div class="l">compétiteurs</div>
                    <div class="meta" style="font-size:11px;margin-top:4px">sur {{ $headline['competitions'] }} compétition{{ $headline['competitions'] > 1 ? 's' : '' }}</div>
                </div>
            </div>

            {{-- ═══ Fréquentation hebdomadaire (§4.16.2, bloc 4) ═══ --}}
            @php
                // Abscisses : chaque semaine si elles sont peu nombreuses, sinon le mois à chaque
                // changement de mois — sept libellés « 14 sept. » ne tiennent pas sur 300 px. Une semaine
                // appartient au mois de son JEUDI (convention ISO) : la semaine du 31 août est celle de
                // septembre, et deux libellés de mois ne tombent jamais sur des barres voisines.
                $dense = count($weekly) > 6;
                $prevMonth = null;
                $weekPoints = [];
                foreach ($weekly as $w) {
                    $thursday = $w['start']->copy()->addDays(3);
                    $month = $thursday->format('Y-m');
                    $weekPoints[] = [
                        'label' => $dense ? ($month !== $prevMonth ? $thursday->locale('fr')->isoFormat('MMM') : null) : $w['start']->locale('fr')->isoFormat('D MMM'),
                        'value' => $w['participations'],
                        'line' => $w['athletes'],
                        'muted' => $w['partial'],
                    ];
                    $prevMonth = $month;
                }
            @endphp
            <div class="card card-pad">
                <div class="flex ac jb"><div class="eyebrow">Fréquentation · par semaine</div><span class="meta" style="font-size:12px">séances training tenues · {{ $periodLabel }}</span></div>
                @if (collect($weekly)->sum('sessions') > 0)
                    <x-bar-chart :points="$weekPoints" legend="Participations" line-legend="Athlètes distincts"
                                 label="Participations et athlètes distincts par semaine" style="margin-top:12px" />
                    <details class="chart-detail">
                        <summary>Détail par semaine</summary>
                        <div style="overflow-x:auto">
                            <table class="tbl">
                                <thead><tr><th>Semaine du</th><th>Séances</th><th>Participations</th><th>Athlètes</th></tr></thead>
                                <tbody>
                                    @foreach (array_reverse($weekly) as $w)
                                        <tr>
                                            <td>{{ $w['start']->locale('fr')->isoFormat('ddd D MMM') }}@if ($w['partial']) <span class="muted">· en cours</span>@endif</td>
                                            <td>{{ $w['sessions'] }}</td>
                                            <td>{{ $w['participations'] }}</td>
                                            <td>{{ $w['athletes'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @else
                    <div class="meta" style="font-size:13px;margin-top:12px">Aucune séance tenue sur la période.</div>
                @endif
            </div>

            {{-- ═══ Créneaux (§4.16.2, bloc 6) — une ligne par modèle, ponctuelles par discipline ═══ --}}
            <div class="card card-pad">
                <div class="flex ac jb wrap g8"><div class="eyebrow">Créneaux · fréquentation</div><span class="meta" style="font-size:12px">séances tenues · {{ $periodLabel }}</span></div>
                @if (count($slots))
                    <div style="overflow-x:auto;margin-top:6px">
                        <table class="tbl tbl-cartes">
                            <thead><tr><th>Créneau</th><th class="r">Séances</th><th class="r">Inscrits moy.</th><th class="r">Remplissage</th><th class="r">Sans encadrement</th></tr></thead>
                            <tbody>
                                @foreach ($slots as $c)
                                    <tr>
                                        <td class="tbl-m-titre tbl-m-ligne">
                                            <div style="font-weight:700">{{ $c['label'] }}</div>
                                            <div class="meta flex ac wrap" style="font-size:11px;gap:2px 6px">
                                                @if ($c['day'])<span>{{ ucfirst($c['day']) }} · {{ $c['time'] }}</span>@endif
                                                @if ($c['discipline'])<span style="white-space:nowrap"><span class="dot dot-{{ $c['discipline']->colorClass() }}"></span> {{ $c['discipline']->label }}</span>@endif
                                            </div>
                                        </td>
                                        <td class="r num" data-label="Séances">{{ $c['sessions'] }}</td>
                                        <td class="r num" data-label="Moyenne"><b>{{ number_format($c['avg'], 1, ',', '') }}</b> <span class="meta" style="font-size:11px">({{ $c['min'] }}–{{ $c['max'] }})</span></td>
                                        <td class="r num" data-label="Remplissage">{{ $c['fill'] !== null ? $c['fill'].' %' : '—' }}</td>
                                        <td class="r num" data-label="Sans encadrement" @if ($c['unstaffed'] > 0) style="color:var(--warning-text)" @endif>{{ $c['unstaffed'] }} %</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="meta" style="font-size:13px;margin-top:12px">Aucune séance tenue sur la période.</div>
                @endif
            </div>

            {{-- ═══ Désinscriptions (§4.16.2, bloc 7) — horodatées par le journal d'activité ═══ --}}
            <div class="card card-pad">
                <div class="flex ac jb wrap g8"><div class="eyebrow">Désinscriptions</div><span class="meta" style="font-size:12px">séances non annulées · {{ $periodLabel }}</span></div>
                @if ($unregistrations['total'] > 0)
                    <div style="font-size:14px;margin-top:10px">
                        <b class="num">{{ $unregistrations['total'] }}</b> désinscription{{ $unregistrations['total'] > 1 ? 's' : '' }}
                        · <b class="num">{{ $unregistrations['late_pct'] }} %</b> moins de 24 h avant le début
                    </div>
                    <table class="tbl" style="margin-top:6px">
                        <thead><tr><th>Discipline</th><th class="r">Total</th><th class="r">&lt; 24 h</th></tr></thead>
                        <tbody>
                            @foreach ($unregistrations['rows'] as $r)
                                <tr>
                                    <td>@if ($r['discipline'])<span class="dot dot-{{ $r['discipline']->colorClass() }}"></span> {{ $r['discipline']->label }}@else<span class="meta">—</span>@endif</td>
                                    <td class="r num">{{ $r['total'] }}</td>
                                    <td class="r num">{{ $r['late'] }} <span class="meta">({{ $r['late_pct'] }} %)</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div class="meta" style="font-size:13px;margin-top:12px">Aucune désinscription sur la période.</div>
                @endif
            </div>

            {{-- ═══ Liste d'attente (§4.16.2, bloc 10 — ligne compacte) ═══ --}}
            <div class="card card-pad flex ac jb wrap g8">
                <div class="eyebrow">Liste d'attente</div>
                <div style="font-size:14px">
                    <b class="num">{{ $waitlist['total'] }}</b> en attente
                    <span class="meta">({{ $waitlist['capacity'] }} capacité · {{ $waitlist['quota'] }} quota)</span>
                    · <b class="num">{{ $waitlist['promotion_rate'] !== null ? $waitlist['promotion_rate'].'%' : '—' }}</b> promus
                </div>
            </div>

            {{-- ═══ Activité coachs ═══ --}}
            <div class="card card-pad">
                <div class="flex ac jb" style="margin-bottom:10px"><div class="eyebrow">Activité coachs</div><span class="meta" style="font-size:12px">séances training · {{ $periodLabel }}</span></div>
                {{-- Tableau croisé (une colonne par discipline) : défilement horizontal, coach figé (#151). --}}
                <div style="overflow-x:auto">
                <table class="tbl tbl-figee">
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
                </div>
                @if ($coachActivity['future_without_coach'] > 0)
                    <x-banner kind="warn" style="margin-top:12px">
                        <span><b>{{ $coachActivity['future_without_coach'] }} séance{{ $coachActivity['future_without_coach'] > 1 ? 's' : '' }} future{{ $coachActivity['future_without_coach'] > 1 ? 's' : '' }}</b> sans coach inscrit · <a href="{{ route('planning') }}" class="underline-link" wire:navigate>voir le planning</a></span>
                    </x-banner>
                @endif
            </div>

        </div>
    </div>
</div>
