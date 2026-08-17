{{-- Corps planning partagé desktop/mobile pour les vues Mois / Jour.
     Semaine est rendue par les coquilles (grille desktop / liste-jour mobile). --}}

@if ($view === 'month')
    {{-- ─── Vue Mois : col n° semaine (clic → vue Semaine) + grille 7 jours ───
         Mobile : pastilles (fidèle au proto PlanMonth) — pleines = le sujet participe,
         creuses = séance ouverte sans inscription.
         Desktop : mini-cartes <x-session-card variant="pill"> (heure + titre + statut).
         Un seul arbre DOM, bascule en CSS au breakpoint 768px. --}}
    @php
        $monthWeeks = collect($monthGrid)->chunk(7);
        // Sujet consulté (parent → enfant, §4.2) : c'est SON inscription qui pilote les marqueurs.
        $uid = $subjectUser->id ?? auth()->id();
        $dotsMax = 4;   // mobile : au-delà, un « +N » compact
        $pillsMax = 3;  // desktop : au-delà, un « +N autres »
    @endphp
    <div class="plan-month">
        {{-- En-tête : cellule vide (col semaine) + 7 jours --}}
        <div class="plan-month-row plan-month-dow">
            <div class="plan-month-wnum-head"></div>
            <div class="plan-month-grid f1">
                @foreach (['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'] as $dow)
                    <div class="eyebrow tc" style="font-size:10px">{{ $dow }}</div>
                @endforeach
            </div>
        </div>
        {{-- Une ligne par semaine ISO : [n° semaine cliquable | 7 jours] --}}
        @foreach ($monthWeeks as $week)
            @php $weekNum = $week->first()->isoWeek; $weekAnchor = $week->first()->toDateString(); @endphp
            <div class="plan-month-row">
                <button wire:click="goToWeek('{{ $weekAnchor }}')" class="plan-month-wnum" title="Voir la semaine {{ $weekNum }}">S{{ $weekNum }}</button>
                <div class="plan-month-grid f1">
                    @foreach ($week as $day)
                        @php
                            $dayStr = $day->toDateString();
                            $out = $day->month !== $from->month;
                            $isToday = $dayStr === $todayStr;
                            $daySessions = $grouped[$dayStr] ?? collect();
                        @endphp
                        {{-- La cellule est un <div> (et non un <button>) : les mini-cartes desktop sont
                             des <a>, qu'on ne peut pas imbriquer dans un bouton. Le passage en vue Jour
                             porte donc sur le numéro du jour (+ la cellule entière sur mobile, où les
                             mini-cartes sont masquées : cf. .plan-month-cell-tap). --}}
                        <div class="plan-month-cell {{ $isToday ? 'is-today' : '' }} {{ $out ? 'is-out' : '' }} {{ $daySessions->isEmpty() ? 'is-empty' : '' }}">
                            @if ($daySessions->isEmpty())
                                {{-- Jour sans séance : pas d'action (la vue Jour serait vide) → simple libellé. --}}
                                <div class="plan-month-daynum num" style="{{ $isToday ? 'color:var(--accent-700)' : '' }}">{{ $day->format('j') }}</div>
                            @else
                                <button type="button" wire:click="goToDay('{{ $dayStr }}')" class="plan-month-daynum num"
                                        aria-label="Voir le {{ $day->locale('fr')->isoFormat('dddd D MMMM') }}"
                                        style="{{ $isToday ? 'color:var(--accent-700)' : '' }}">{{ $day->format('j') }}</button>

                                {{-- Mobile : pastilles. Pleine = participe · creuse = non inscrit · point = liste d'attente. --}}
                                <div class="plan-month-dots">
                                    @foreach ($daySessions->take($dotsMax) as $s)
                                        @php
                                            // Deux dimensions cumulables : l'inscription (plein/creux) ET
                                            // l'annulation (barrée) — une séance annulée où l'on était inscrit
                                            // ne doit pas continuer à se lire « tu participes ».
                                            $state = match ($s->statusFor($uid)) {
                                                'participating' => 'is-in',
                                                'waitlist' => 'is-wait',
                                                default => 'is-free',
                                            };
                                        @endphp
                                        <span class="dot dot-{{ $s->colorClass() }} plan-month-dot {{ $state }} {{ $s->isCancelled() ? 'is-cancelled' : '' }}"></span>
                                    @endforeach
                                    @if ($daySessions->count() > $dotsMax)
                                        <span class="plan-month-more">+{{ $daySessions->count() - $dotsMax }}</span>
                                    @endif
                                </div>
                                {{-- Apéro : marqueur de cellule côté mobile seulement — en desktop chaque
                                     mini-carte porte déjà sa propre chope. --}}
                                @if ($daySessions->contains(fn ($s) => $s->hasApero()))
                                    <x-chope :size="11" class="plan-month-apero" style="color:var(--apero)" />
                                @endif

                                {{-- Desktop : mini-cartes (liseré discipline + heure + titre + statut perso). --}}
                                <div class="plan-month-pills">
                                    @foreach ($daySessions->take($pillsMax) as $s)
                                        <x-session-card :session="$s" :tz="$tz" variant="pill"
                                                        :view-as="$uid" :subject-name="$subjectFirstName ?? null" />
                                    @endforeach
                                    @if ($daySessions->count() > $pillsMax)
                                        @php $rest = $daySessions->count() - $pillsMax; @endphp
                                        <button type="button" wire:click="goToDay('{{ $dayStr }}')" class="plan-month-more plan-month-more-btn">
                                            +{{ $rest }} autre{{ $rest > 1 ? 's' : '' }}
                                        </button>
                                    @endif
                                </div>

                                {{-- Zone de tap plein-cellule (mobile uniquement) → vue Jour. --}}
                                <button type="button" wire:click="goToDay('{{ $dayStr }}')" class="plan-month-cell-tap"
                                        tabindex="-1" aria-hidden="true"></button>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
        {{-- Légende : disciplines + convention plein/creux (sinon la nuance n'est pas découvrable). --}}
        <div class="flex g12 wrap" style="margin-top:14px">
            @foreach ($disciplines as $d)
                <span class="flex ac g6 meta" style="font-size:12px"><span class="dot dot-{{ $d->colorClass() }}"></span> {{ $d->label }}</span>
            @endforeach
        </div>
        <div class="flex g12 wrap plan-month-legend-state" style="margin-top:8px">
            <span class="flex ac g6 meta" style="font-size:12px">
                <span class="dot dot-prep plan-month-legend-dot is-in"></span> {{ $subjectFirstName ?? null ? $subjectFirstName.' participe' : 'Tu participes' }}
            </span>
            <span class="flex ac g6 meta" style="font-size:12px">
                <span class="dot dot-prep plan-month-legend-dot is-free"></span> Pas inscrit
            </span>
            <span class="flex ac g6 meta" style="font-size:12px">
                <span class="dot dot-prep plan-month-legend-dot is-wait"></span> Liste d'attente
            </span>
        </div>
    </div>

@elseif ($view === 'day')
    {{-- ─── Vue Jour : timeline horaire (proto PlanDay) ─── --}}
    @php
        $h0 = 8; $h1 = 22; $rowH = 56; // 56px / heure
        $daySessions = $grouped[$from->toDateString()] ?? collect();
    @endphp
    <div class="plan-day">
        <div class="plan-weeknav" style="margin-bottom:14px;border:none;padding:0;background:none">
            <button wire:click="previous" class="iconbtn" aria-label="Précédent"><x-icon name="chevron-left" /></button>
            <div class="tc f1" style="min-width:0">
                <div class="dsp-7" style="font-size:15px;line-height:1.1;text-transform:capitalize">{{ $from->locale('fr')->isoFormat('dddd D MMMM') }}</div>
                <div class="meta" style="font-size:11px;text-transform:capitalize">{{ $from->locale('fr')->isoFormat('MMMM YYYY') }}</div>
            </div>
            <button wire:click="next" class="iconbtn" aria-label="Suivant"><x-icon name="chevron-right" /></button>
        </div>
        <div class="plan-day-timeline" style="height:{{ ($h1 - $h0) * $rowH }}px">
            @for ($h = $h0; $h <= $h1; $h += 2)
                <div class="plan-day-hour" style="top:{{ ($h - $h0) * $rowH }}px">
                    <span class="plan-day-hourlabel meta">{{ sprintf('%02d:00', $h) }}</span>
                </div>
            @endfor
            {{-- Le sujet consulté (parent → enfant, §4.2) pilote statut perso + actions +/−. --}}
            @php $uid = $subjectUser->id ?? auth()->id(); $subjName = $subjectFirstName ?? null; @endphp
            @foreach ($daySessions as $s)
                @php
                    $st = $s->start_at->copy()->setTimezone($tz);
                    $cls = $s->colorClass();
                    // Cf. session-show : 'competition' n'a pas de token de teinte → repli --accent.
                    $border = $cls === 'competition' ? 'accent' : $cls;
                    $top = max(0, ($st->hour + $st->minute / 60 - $h0) * $rowH);
                    $height = max(40, ($s->duration_min / 60) * $rowH - 4);
                    $loc = $s->location_text ?: $s->location?->name;
                    $participating = $s->registrations->where('status', 'participating')->count();
                    $full = $s->capacity && $participating >= $s->capacity;
                    $insLabel = $s->capacity ? ($full ? 'complet' : $participating.'/'.$s->capacity) : $participating.' inscrit'.($participating > 1 ? 's' : '');
                    $mineCoach = ! $subjName && auth()->id() && $s->relationLoaded('coaches') && $s->coaches->contains('id', auth()->id());
                    $mineStatus = $s->statusFor($uid);
                @endphp
                <div class="plan-day-event"
                   style="top:{{ $top }}px;min-height:{{ $height }}px;background:var(--{{ $cls }}-50, var(--slate-50));border-left:4px solid var(--{{ $border }})">
                    <a href="{{ route('sessions.show', $s) }}" wire:navigate style="display:block;text-decoration:none;border-bottom:none;color:inherit">
                        <div style="font-weight:700;font-size:13px">{{ $st->format('H:i') }} · {{ $s->title }}</div>
                        <div class="meta">{{ $loc ? $loc.' · ' : '' }}{{ $insLabel }}</div>
                        {{-- Statut perso en chip (cohérent avec la vue Semaine — session-card variant week). --}}
                        @if ($mineCoach)
                            <span class="chip chip-sm" style="margin-top:6px;background:var(--ink);color:var(--paper)"><x-icon name="whistle" :size="11" /> Tu encadres</span>
                        @elseif ($mineStatus === 'participating')
                            <span class="chip chip-sm chip-green" style="margin-top:6px"><x-icon name="check" :size="11" /> {{ $subjName ? $subjName.' participe' : 'Tu participes' }}</span>
                        @elseif ($mineStatus === 'waitlist')
                            <span class="chip chip-sm chip-warn" style="margin-top:6px"><x-icon name="clock" :size="11" /> Liste d'attente</span>
                        @endif
                    </a>
                    @if ($s->hasApero())<x-chope :size="13" style="position:absolute;bottom:7px;right:7px;color:var(--apero)" />@endif
                </div>
            @endforeach
        </div>
    </div>

@endif
