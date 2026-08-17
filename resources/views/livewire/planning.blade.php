{{-- Planning — porté de screen-planning.jsx (Desktop : grille 7 col ; Mobile : filtres + weeknav + liste/jour).
     3 vues : semaine / jour / mois. Mois = calendrier à dots, Jour = timeline horaire. --}}
@php
    // Granularité croissante (Jour → Mois). La vue par défaut reste Semaine (Planning::$view).
    $viewItems = [
        ['v' => 'day', 'l' => 'Jour'],
        ['v' => 'week', 'l' => 'Semaine'],
        ['v' => 'month', 'l' => 'Mois'],
    ];
    $rangeLabel = $from->locale('fr')->isoFormat('D MMM') . ' — ' . $to->locale('fr')->isoFormat('D MMM');
    $weekIsCurrent = ($view === 'week') && ($from->isoWeek === now($tz)->isoWeek && $from->year === now($tz)->year);
@endphp
<div class="planning-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />

    {{-- ═══════════════════════ DESKTOP : topbar + grille ═══════════════════════ --}}
    <div class="planning-desktop">
        <div class="dk-topbar dk-topbar-plan">
            {{-- Ligne 1 : date/semaine + nav à gauche · sélecteur de vue à droite. --}}
            <div class="dk-plan-row1">
                <div class="dk-plan-when">
                    <div class="dk-plan-date">
                        @if ($view === 'week')
                            <div class="dsp" style="font-size:26px">Semaine {{ $from->isoWeek }}</div>
                            <div class="meta">
                                {{ $rangeLabel }}
                                @if ($weekIsCurrent) · <span style="color:var(--accent)">cette semaine</span>@endif
                            </div>
                        @elseif ($view === 'month')
                            <div class="dsp" style="font-size:26px;text-transform:capitalize">{{ $from->locale('fr')->isoFormat('MMMM YYYY') }}</div>
                        @else
                            <div class="dsp" style="font-size:26px;text-transform:capitalize">{{ $from->locale('fr')->isoFormat('dddd D MMMM') }}</div>
                        @endif
                    </div>
                    <div class="weeknav">
                        <button wire:click="previous" class="iconbtn" aria-label="Précédent"><x-icon name="chevron-left" :size="18" /></button>
                        <button wire:click="today" class="weeknav-today">Aujourd'hui</button>
                        <button wire:click="next" class="iconbtn" aria-label="Suivant"><x-icon name="chevron-right" :size="18" /></button>
                    </div>
                </div>
                <x-segmented wire-method="setView" :value="$view" :items="$viewItems" />
            </div>
            {{-- Ligne 2 : filtres par activité + « Mes inscriptions ». --}}
            <div class="dk-plan-row2 flex ac g12">
                <div class="dk-plan-filters flex g6">@include('livewire.partials.plan-filters')</div>
                <label class="planning-mine"><input type="checkbox" wire:model.live="mine"> Mes inscriptions</label>
            </div>
            {{-- Sélecteur de sujet parent (§4.2) — desktop : inline sous les filtres. --}}
            @include('livewire.partials.subject-switcher', ['inline' => true])
            @include('livewire.partials.subject-banner', ['inline' => true])
        </div>

        <div class="dk-body" style="padding:0">
            @if ($view === 'week')
                <div class="wk-grid-dk">
                    @foreach ($weekDays as $day)
                        @php
                            $dayStr = $day->toDateString();
                            $isToday = $dayStr === $todayStr;
                            $daySessions = $grouped[$dayStr] ?? collect();
                        @endphp
                        <div class="wk-col-dk">
                            <div class="wk-colhead-dk {{ $isToday ? 'is-today' : '' }}">
                                <div class="eyebrow" style="font-size:10px">{{ $day->locale('fr')->isoFormat('ddd') }}</div>
                                <div class="num" style="font-size:24px;{{ $isToday ? 'color:var(--accent-700)' : '' }}">{{ $day->format('j') }}</div>
                            </div>
                            <div class="wk-colbody-dk">
                                @foreach ($daySessions as $s)
                                    <x-session-card :session="$s" :tz="$tz" variant="week"
                                        :viewAs="$subjectUser->id" :subjectName="$subjectFirstName" />
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="plan-pad">@include('livewire.partials.plan-body', ['scope' => 'dk'])</div>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════ MOBILE : filtres + weeknav + liste ═══════════════════════ --}}
    <div class="planning-mobile {{ $subjectUser->id !== auth()->id() ? 'has-child-banner' : '' }}">
        {{-- Topbar verte fixe : titre + accès alertes, couvre la safe-area iOS. Les éléments
             interactifs (filtres, nav semaine) restent dans le flux juste dessous — on ne met
             pas de wire:click dans la barre fixe (cf. pièges wire:navigate/wire:click). --}}
        <x-topbar title="Planning">
            <x-slot:trailing><x-alert-bell dark /></x-slot:trailing>
        </x-topbar>
        {{-- Contexte enfant (§4.2) : tout le contexte enfant (sélecteur + bandeau « Tu agis pour X »)
             vit désormais dans la barre de vue en bas → le haut est entièrement dégagé. --}}

        {{-- Filtres — TEMPORAIREMENT masqués sur mobile (classe is-hidden-temp, cf. app.css).
             Pour réafficher : retirer is-hidden-temp ici + la règle CSS associée. --}}
        <div class="plan-filterrow flex g6 wrap is-hidden-temp">@include('livewire.partials.plan-filters')</div>

        {{-- WeekNav / MonthNav (semaine ou mois) --}}
        @if ($view === 'week')
            <div class="plan-weeknav">
                <button wire:click="previous" class="iconbtn" aria-label="Précédent"><x-icon name="chevron-left" /></button>
                <div class="tc f1" style="min-width:0">
                    <div class="dsp-7" style="font-size:15px;line-height:1.1">Semaine {{ $from->isoWeek }}</div>
                    <div class="meta" style="font-size:11px">
                        {{ $rangeLabel }}
                        @if ($weekIsCurrent) · <span style="color:var(--accent)">cette semaine</span>@endif
                    </div>
                </div>
                <button wire:click="next" class="iconbtn" aria-label="Suivant"><x-icon name="chevron-right" /></button>
            </div>
        @elseif ($view === 'month')
            <div class="plan-weeknav">
                <button wire:click="previous" class="iconbtn" aria-label="Précédent"><x-icon name="chevron-left" /></button>
                <div class="tc f1" style="min-width:0">
                    <div class="dsp-7" style="font-size:15px;line-height:1.1;text-transform:capitalize">{{ $from->locale('fr')->isoFormat('MMMM YYYY') }}</div>
                </div>
                <button wire:click="next" class="iconbtn" aria-label="Suivant"><x-icon name="chevron-right" /></button>
            </div>
        @endif

        <div class="plan-scroll-m">
            @if ($view === 'week')
                {{-- Liste par jour (header sticky) --}}
                @php $hasAny = false; @endphp
                @foreach ($weekDays as $day)
                    @php
                        $dayStr = $day->toDateString();
                        $daySessions = $grouped[$dayStr] ?? collect();
                    @endphp
                    @if ($daySessions->isNotEmpty())
                        @php $hasAny = true; $isToday = $dayStr === $todayStr; @endphp
                        <div class="plan-daygroup-m">
                            <div class="plan-dayhead-m">
                                <span class="num" style="font-size:18px;{{ $isToday ? 'color:var(--accent)' : '' }}">{{ $day->format('j') }}</span>
                                <span class="dsp-7" style="font-size:16px;text-transform:capitalize">{{ $day->locale('fr')->isoFormat('dddd') }}</span>
                                @if ($isToday)<span class="chip chip-sm chip-pink">Aujourd'hui</span>@endif
                                <span class="meta mlauto">{{ $daySessions->count() }}</span>
                            </div>
                            <div class="plan-daylist-m">
                                @foreach ($daySessions as $s)
                                    <x-session-card :key="'m-'.$s->id" :session="$s" :tz="$tz" variant="row"
                                        :viewAs="$subjectUser->id" :subjectName="$subjectFirstName" />
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
                @unless ($hasAny)
                    <div class="planning-empty">Aucune séance cette semaine.</div>
                @endunless
            @else
                @include('livewire.partials.plan-body', ['scope' => 'mo'])
            @endif
        </div>

        {{-- Barre de vue (bas). Regroupe TOUT le contexte enfant (§4.2) pour un compte garant :
             le bandeau « Tu agis pour X » (info de sécurité, si un enfant est sélectionné) au-dessus,
             puis la rangée pastilles-avatars (gauche) + segment de vue (droite).
             Pas de wire:navigate ici : setSubject est un simple wire:click (persistance en session,
             pas d'URL) → aucun conflit avec un lien de navigation (cf. pièges wire:navigate/click). --}}
        <div class="plan-viewbar-m {{ $subjectWards->isNotEmpty() ? 'has-subj' : '' }}">
            @if ($subjectWards->isNotEmpty())
                @include('livewire.partials.subject-banner', ['inline' => true])
            @endif
            <div class="plan-viewbar-row">
                @if ($subjectWards->isNotEmpty())
                    <div class="plan-subj-m" role="group" aria-label="Personne consultée">
                        <button type="button" wire:click="setSubject(null)" wire:loading.attr="disabled" wire:target="setSubject"
                                class="plan-subj-pill {{ $subjectUser->id === auth()->id() ? 'on' : '' }}"
                                aria-pressed="{{ $subjectUser->id === auth()->id() ? 'true' : 'false' }}" aria-label="Moi" title="Moi">
                            <x-avatar :name="auth()->user()->fullName()" size="sm" tint="tint-bike" />
                        </button>
                        @foreach ($subjectWards as $i => $ward)
                            <button type="button" wire:click="setSubject({{ $ward->id }})" wire:loading.attr="disabled" wire:target="setSubject"
                                    class="plan-subj-pill {{ $subjectUser->id === $ward->id ? 'on' : '' }}"
                                    aria-pressed="{{ $subjectUser->id === $ward->id ? 'true' : 'false' }}" aria-label="{{ $ward->first_name }}" title="{{ $ward->first_name }}">
                                <x-avatar :name="$ward->fullName()" size="sm" :tint="['tint-swim', 'tint-run', 'tint-bike'][$i % 3]" />
                            </button>
                        @endforeach
                    </div>
                @endif
                <x-segmented wire-method="setView" :value="$view" :items="$viewItems" />
            </div>
        </div>
    </div>
</div>
