{{-- Vue « Courses » du planning (#106, PRD §4.7) — une liste, pas une grille. À venir : toutes les
     compétitions à partir d'aujourd'hui, date croissante. Passées : saison en cours, la plus récente
     en tête, avec débriefs et album (archive des débriefs ; la nouveauté est sur l'accueil, #104).
     Carte .scard-row comme les autres listes du planning ; la colonne date porte le MOIS, la liste
     couvrant plusieurs mois. Reçoit $coursesUpcoming, $coursesPast, $courseNames, $seasonStart, $tz. --}}
@php
    $uid = $subjectUser->id ?? auth()->id();
    $participeLabel = $subjectFirstName ? $subjectFirstName.' participe' : 'Tu participes';
    $etaitLabel = $subjectFirstName ? $subjectFirstName.' y était' : 'Tu y étais';
    $namesMax = 4;
    $sections = [
        ['key' => 'up', 'title' => 'À venir', 'items' => $coursesUpcoming, 'past' => false,
         'empty' => 'Aucune compétition prévue pour le moment.'],
        ['key' => 'past', 'title' => 'Passées · saison '.$seasonStart->year.'-'.($seasonStart->year + 1), 'items' => $coursesPast, 'past' => true,
         'empty' => 'Aucune compétition depuis le début de la saison.'],
    ];
@endphp
<div class="plan-courses">
    @foreach ($sections as $sec)
        <section data-courses="{{ $sec['key'] }}">
            <div class="sect-head"><span class="sect-title">{{ $sec['title'] }}</span><span class="meta">{{ $sec['items']->count() }}</span></div>
            @if ($sec['items']->isEmpty())
                <div class="planning-empty">{{ $sec['empty'] }}</div>
            @else
                <div class="plan-courses-list">
                    @foreach ($sec['items'] as $s)
                        @php
                            $start = $s->start_at->copy()->setTimezone($tz);
                            $cancelled = $s->isCancelled();
                            $status = $s->statusFor($uid);
                            $names = $courseNames[$s->id] ?? [];
                            $n = count($names);
                            $desc = collect([$s->eventType?->label, $s->distance, $s->placeLabel()])->filter()->implode(' · ');
                            $debriefs = (int) ($s->debriefs_count ?? 0);
                            $href = $sec['past'] && $debriefs
                                ? route('sessions.show', ['session' => $s, 'tab' => 'debriefs'])
                                : route('sessions.show', $s);
                        @endphp
                        <a href="{{ $href }}" wire:navigate wire:key="course-{{ $s->id }}"
                           class="scard {{ $s->colorClass() }} scard-row {{ $cancelled ? 'scard-cancelled' : '' }}">
                            <div class="scard-row-date">
                                <div class="eyebrow" style="font-size:10px">{{ $start->locale('fr')->isoFormat('ddd') }}</div>
                                <div class="num" style="font-size:22px">{{ $start->format('j') }}</div>
                                <div class="meta" style="font-size:11px">{{ $start->locale('fr')->isoFormat('MMM') }}</div>
                            </div>
                            <div class="f1" style="min-width:0">
                                <span class="scard-row-title">{{ $s->title }}</span>
                                @if ($desc !== '')<div class="meta" style="margin-top:2px">{{ $desc }}</div>@endif
                                <div class="meta" style="margin-top:2px">
                                    @if ($n === 0)
                                        {{ $sec['past'] ? 'Aucun membre du club' : 'Personne du club pour l\'instant' }}
                                    @else
                                        {{ $n }} du club : {{ implode(', ', array_slice($names, 0, $namesMax)) }}{{ $n > $namesMax ? ' +'.($n - $namesMax) : '' }}
                                    @endif
                                </div>
                                <div class="flex ac g6 wrap" style="margin-top:6px">
                                    @if ($cancelled)
                                        <span class="chip chip-sm chip-pink">Annulée</span>
                                    @elseif ($status === 'participating')
                                        <span class="chip chip-sm chip-green"><x-icon name="check" :size="12" /> {{ $sec['past'] ? $etaitLabel : $participeLabel }}</span>
                                    @elseif ($status === 'waitlist')
                                        <span class="chip chip-sm chip-warn"><x-icon name="clock" :size="12" /> Liste d'attente</span>
                                    @endif
                                    @if ($sec['past'])
                                        @if ($debriefs)
                                            <span class="chip chip-sm"><x-icon name="file-text" :size="12" /> {{ $debriefs }} débrief{{ $debriefs > 1 ? 's' : '' }}</span>
                                        @endif
                                        @if ($s->photos_album_url)
                                            <span class="chip chip-sm"><x-icon name="image" :size="12" /> Album</span>
                                        @endif
                                    @endif
                                </div>
                            </div>
                            <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach
</div>
