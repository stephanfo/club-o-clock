{{-- Onglet « Encadrement » — porté de screen-fiche.jsx FEncadrement (§4.11.4, données réelles).
     Liste nominative (noms complets, visibilité publique §4.11.4), qualifs par coach + agrégées,
     actions de gestion coach (inscrire/retirer/flip) pour coach/admin, bandeau « pas de coach ».
     Reçoit : $session, $aggregatedQualifs, $canManageCoaches, $iAmCoachHere. --}}
@php($me = auth()->user())
@php($isTraining = $session->kind === 'training')
@php($manage = ($canManageCoaches ?? false) && ! $session->isCancelled() && ! $session->hasStarted())
<div style="display:flex;flex-direction:column;gap:14px">

    {{-- Bandeau d'alerte douce : aucun encadrant (training uniquement, §4.11.4). --}}
    @if ($isTraining && $session->coaches->isEmpty())
        <x-banner kind="warn">Pas de coach inscrit pour le moment.</x-banner>
    @endif

    @if ($session->coaches->isNotEmpty())
    <div>
        <div class="sect-head">
            <span class="sect-title">{{ $isTraining ? 'Encadrants' : 'Accompagnement' }}</span>
            <span class="meta mlauto">{{ $session->coaches->count() }} {{ $isTraining ? 'coach·s' : 'accompagnateur·s' }}</span>
        </div>
        <div style="display:flex;flex-direction:column;gap:14px">
        {{-- Carte par encadrant : nom complet + ses qualifs (badges expiration). --}}
        @foreach ($session->coaches as $coach)
            <div class="card card-pad flex ac g12">
            <x-avatar :name="$coach->fullName()" size="lg" tint="tint-swim" />
            <div class="f1" style="min-width:0">
                <div style="font-weight:700;font-size:15px">{{ $coach->fullName() }}</div>
                <div class="meta" style="margin-bottom:6px">{{ $isTraining ? 'Coach' : ($session->kind === 'competition' ? 'Accompagnateur' : 'Organisateur') }}</div>
                @if ($isTraining && $coach->qualifications->isNotEmpty())
                    <div class="flex g4 wrap">
                        @foreach ($coach->qualifications as $q)
                            @php($st = \App\Support\QualificationDisplay::status($q->pivot->expires_at ? \Illuminate\Support\Carbon::parse($q->pivot->expires_at) : null))
                            <span class="chip chip-sm {{ $st['status'] === 'none' ? 'chip-line' : $st['cls'] }}"
                                  @if ($st['expires_at']) title="{{ $st['status'] === 'expired' ? 'Expirée le' : 'Valide jusqu’au' }} {{ $st['expires_at']->locale('fr')->isoFormat('D MMM YYYY') }}" @endif>
                                {{ $q->code ?: $q->label }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Actions de gestion par coach (voie 3 retrait + bascule cas 3/4 — §4.11.2/.5). --}}
            @if ($manage && $isTraining)
                <div class="flex g4" style="flex:0 0 auto">
                    {{-- Bascule coach → athlète : seulement si la personne a le rôle athlète (§2) ;
                         un coach-pur n'a pas d'existence athlète à activer. --}}
                    @if ($coach->hasRole('athlete'))
                        <button wire:click="flipToAthlete({{ $coach->id }})" class="iconbtn" title="Passer {{ $coach->first_name }} en athlète" aria-label="Passer {{ $coach->first_name }} en athlète">
                            <x-icon name="user-check" :size="16" />
                        </button>
                    @endif
                    <button wire:click="unregisterCoach({{ $coach->id }})" class="iconbtn" title="Retirer {{ $coach->first_name }} de l’encadrement" aria-label="Retirer {{ $coach->first_name }} de l’encadrement">
                        <x-icon name="user-minus" :size="16" />
                    </button>
                </div>
            @endif
            </div>
        @endforeach
        </div>
    </div>
    @endif

    {{-- Actions d'inscription coach (voie 2 self + voie 3 tiers — §4.11.2). Training uniquement. --}}
    @if ($manage && $isTraining)
        <div class="flex g6 wrap">
            @if ($me->hasRole('coach') && ! ($iAmCoachHere ?? false) && ! $session->registrations->where('user_id', $me->id)->whereIn('status', ['participating', 'waitlist'])->count())
                <button wire:click="registerCoachSelf" class="btn btn-dark btn-sm">
                    <x-icon name="whistle" :size="14" /> M’inscrire comme coach
                </button>
            @endif
            <button wire:click="openCoachPicker" class="btn btn-ghost btn-sm">
                <x-icon name="user-plus" :size="14" /> Inscrire un coach
            </button>
        </div>
    @endif

    {{-- Qualifications agrégées (déduplication par qualificationId — §4.11.4). --}}
    @if ($isTraining && $session->coaches->isNotEmpty())
        <div x-data="{ open: null }">
            <div class="sect-head">
                <span class="sect-title">Qualifications disponibles</span>
                @if ($aggregatedQualifs->isNotEmpty())<span class="meta mlauto">Touche une qualif pour le détail</span>@endif
            </div>
            <div class="card card-pad card-soft">
                @if ($aggregatedQualifs->isEmpty())
                    <div class="meta">Aucune qualification renseignée par les encadrants.</div>
                @else
                    <div class="flex g6 wrap">
                        @foreach ($aggregatedQualifs as $agg)
                            <button type="button" class="chip {{ \App\Support\QualificationDisplay::clsFor($agg['worst']) }}"
                                    x-on:click="open = (open === {{ $agg['id'] }} ? null : {{ $agg['id'] }})">
                                {{ $agg['code'] ?? $agg['label'] }}
                            </button>
                        @endforeach
                    </div>
                    {{-- Détail au tap : coachs porteurs + badge d'expiration éventuel. --}}
                    @foreach ($aggregatedQualifs as $agg)
                        <div x-show="open === {{ $agg['id'] }}" x-cloak style="margin-top:10px;display:flex;flex-direction:column;gap:6px">
                            <div class="eyebrow">{{ $agg['label'] }}</div>
                            @foreach ($agg['holders'] as $h)
                                <div class="flex ac g6">
                                    <span style="font-size:var(--text-sm)">{{ $h['name'] }}</span>
                                    @if ($h['status']['status'] === 'expired')
                                        <span class="chip chip-sm chip-danger">expirée</span>
                                    @elseif ($h['status']['status'] === 'soon')
                                        <span class="chip chip-sm chip-warn">expire bientôt</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                    <div class="meta" style="font-size:var(--text-xs);margin-top:8px">Déduplication automatique sur l’ensemble des coachs encadrants.</div>
                @endif
            </div>
        </div>
    @endif
</div>
