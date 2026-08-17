@php($u = auth()->user())
{{-- Accueil — porté de screen-home.jsx (HomeAthlete mobile + HomeDesktop).
     Deux coquilles, une seule visible par breakpoint (CSS, comme l'auth). --}}
<div class="home-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />

    {{-- ─── MOBILE : héros fondu (logo + titre + prochaine séance) puis body ─── --}}
    <div class="home-mobile">
        {{-- Topbar verte fixe (logo + cloche). Même vert que le haut du fondu → elle se fond
             dans le héros, tout en restant collée (safe-area iOS derrière l'heure). Le reste du
             fondu (salutation + carte vedette) scrolle dessous : le design actuel est préservé. --}}
        <div class="topbar topbar-home">
            <x-logo dark sm />
            <span class="topbar-spacer"></span>
            {{-- Cet écran compose sa barre à la main (pas <x-topbar>) : la pastille de démo s'y
                 insère explicitement, sinon l'accueil serait le seul écran sans rappel. --}}
            <x-demo-badge mode="bar" />
            <x-alert-bell dark />
        </div>
        <div class="fondu home-hero-m">
            {{-- titre + carte vedette --}}
            <div style="position:relative;z-index:1">
                <div class="eyebrow" style="color:var(--brand-200)">{{ $now->locale('fr')->isoFormat('dddd D MMMM') }} · semaine {{ $now->isoWeek }}</div>
                <div class="dsp" style="font-size:40px;color:var(--paper);margin-top:4px">Bonjour<br>{{ mb_strtoupper($u->first_name) }}</div>

                {{-- Sélecteur de sujet parent (§4.2) — variante sombre sur le fondu (proto). --}}
                <div style="margin-top:14px">
                    @include('livewire.partials.subject-switcher', ['dark' => true])
                </div>

                @if ($next)
                    <a href="{{ route('sessions.show', $next) }}" wire:navigate class="home-featured">
                        <div class="flex ac jb">
                            <span class="eyebrow" style="color:var(--brand-200);white-space:nowrap">
                                <x-icon name="zap" :size="13" style="display:inline;vertical-align:-2px" /> Prochaine · {{ $next->start_at->copy()->setTimezone($tz)->locale('fr')->isoFormat('ddd HH:mm') }}
                            </span>
                            @if ($next->hasApero())<x-chope :size="20" style="color:var(--accent-200)" />@endif
                        </div>
                        <div class="dsp-7" style="font-size:26px;margin-top:6px">{{ $next->title }}</div>
                        <div style="font-size:var(--text-sm);color:var(--fg-on-dark-soft);margin-top:4px">
                            {{ $next->duration_min }} min
                            @if ($next->location_text || $next->location) · {{ $next->location_text ?: $next->location?->name }}@endif
                        </div>
                        <div class="flex ac g6 wrap" style="margin-top:12px">
                            @if ($nextIsCoach)
                                <span class="chip chip-sm" style="background:var(--paper);color:var(--ink)"><x-icon name="whistle" :size="12" /> Tu encadres</span>
                            @elseif ($nextIsParticipant)
                                <span class="chip chip-sm" style="background:var(--brand);color:var(--fg-on-primary)"><x-icon name="check" :size="12" /> {{ $subjectFirstName ? $subjectFirstName.' participe' : 'Tu participes' }}</span>
                            @else
                                <span class="chip chip-sm" style="background:var(--chip-on-dark);color:var(--paper)">
                                    @if ($next->capacity){{ $next->registrations->where('status', 'participating')->count() }}/{{ $next->capacity }} inscrits @else à venir @endif
                                </span>
                            @endif
                            <span class="mlauto" style="font-size:var(--text-sm);font-weight:700;display:inline-flex;align-items:center;gap:4px;color:var(--paper)">
                                Détails <x-icon name="chevron-right" :size="15" />
                            </span>
                        </div>
                    </a>
                @endif
            </div>
        </div>

        {{-- body --}}
        <div class="home-body-m">
            @include('livewire.partials.subject-banner')
            @foreach ($infoBanners as $b)
                <a href="{{ route('infos') }}#page-{{ $b->id }}" wire:navigate style="text-decoration:none;border:0;display:block;margin-bottom:14px">
                    <x-banner kind="info" icon="star"><div>{{ $b->title }}</div></x-banner>
                </a>
            @endforeach
            @if ($rolloverReminder)
                <a href="{{ route('admin.members') }}" wire:navigate style="text-decoration:none;border:0;display:block;margin-bottom:14px">
                    <x-banner kind="warn"><div><b>Rentrée sportive</b> — pense à démarrer la nouvelle année sportive pour recalculer les catégories (date de naissance · sept → août).</div></x-banner>
                </a>
            @endif
            @if ($eligibleDeletions > 0)
                <a href="{{ route('admin.members', ['access' => 'eligible']) }}" wire:navigate style="text-decoration:none;border:0;display:block;margin-bottom:14px">
                    <x-banner kind="warn"><div><b>{{ $eligibleDeletions }}</b> compte{{ $eligibleDeletions > 1 ? 's' : '' }} éligible{{ $eligibleDeletions > 1 ? 's' : '' }} à suppression (tampon écoulé) — à traiter.</div></x-banner>
                </a>
            @endif
            <div>
                <div class="sect-head"><span class="sect-title">Mes prochaines séances</span><span class="meta mlauto">{{ $myUpcoming->count() }}</span></div>
                @if ($myUpcoming->isEmpty())
                    <div class="card card-pad meta" style="text-align:center">{{ $subjectFirstName ? "Aucune séance à venir où {$subjectFirstName} est inscrit·e." : 'Aucune séance à venir où tu es inscrit·e.' }}</div>
                @else
                    <div style="display:flex;flex-direction:column;gap:10px">
                        @foreach ($myUpcoming as $s)
                            <x-session-card :session="$s" :tz="$tz" variant="row"
                                :viewAs="$subjectUser->id" :subjectName="$subjectFirstName" />
                        @endforeach
                    </div>
                @endif
            </div>
            @include('livewire.partials.home-quotas')
            @include('livewire.partials.home-apero')
        </div>
    </div>

    {{-- ─── DESKTOP : topbar + grille (héros = carte prochaine séance) ─── --}}
    <div class="home-desktop">
        <div class="dk-topbar">
            <div class="f1">
                <div class="dsp" style="font-size:30px">Bonjour {{ mb_strtoupper($u->first_name) }}</div>
                <div class="meta" style="margin-top:2px">
                    {{ $now->locale('fr')->isoFormat('dddd D MMMM') }} · semaine {{ $now->isoWeek }} · {{ $weekCount }} séance{{ $weekCount > 1 ? 's' : '' }} cette semaine
                </div>
                {{-- Sélecteur de sujet parent (§4.2) — inline dans le bloc titre (proto). --}}
                <div style="margin-top:10px">
                    @include('livewire.partials.subject-switcher', ['inline' => true])
                </div>
                @include('livewire.partials.subject-banner', ['inline' => true])
            </div>
        </div>

        <div class="dk-body">
            @foreach ($infoBanners as $b)
                <a href="{{ route('infos') }}#page-{{ $b->id }}" wire:navigate style="text-decoration:none;border:0;display:block;margin-bottom:var(--space-4)">
                    <x-banner kind="info" icon="star"><div>{{ $b->title }}</div></x-banner>
                </a>
            @endforeach
            @if ($rolloverReminder)
                <a href="{{ route('admin.members') }}" wire:navigate style="text-decoration:none;border:0;display:block;margin-bottom:var(--space-4)">
                    <x-banner kind="warn"><div><b>Rentrée sportive</b> — pense à démarrer la nouvelle année sportive pour recalculer les catégories (date de naissance · sept → août).</div></x-banner>
                </a>
            @endif
            @if ($eligibleDeletions > 0)
                <a href="{{ route('admin.members', ['access' => 'eligible']) }}" wire:navigate style="text-decoration:none;border:0;display:block;margin-bottom:var(--space-4)">
                    <x-banner kind="warn"><div><b>{{ $eligibleDeletions }}</b> compte{{ $eligibleDeletions > 1 ? 's' : '' }} éligible{{ $eligibleDeletions > 1 ? 's' : '' }} à suppression (tampon écoulé) — à traiter sur la page Adhérents.</div></x-banner>
                </a>
            @endif
            <div class="home-grid">
                <div class="home-col">
                    {{-- Héros : prochaine séance --}}
                    @if ($next)
                        <a href="{{ route('sessions.show', $next) }}" wire:navigate class="home-hero fondu">
                            <div class="home-hero-body">
                                <div class="flex ac jb">
                                    <div class="eyebrow" style="color:var(--brand-200)">
                                        Prochaine · {{ $next->start_at->copy()->setTimezone($tz)->locale('fr')->isoFormat('ddd HH:mm') }}
                                    </div>
                                    @if ($next->hasApero())<x-chope :size="22" style="color:var(--accent-200)" />@endif
                                </div>
                                <div class="dsp" style="font-size:38px;color:var(--paper);margin-top:6px;line-height:1">{{ $next->title }}</div>
                                <div style="color:var(--fg-on-dark-soft);margin-top:10px;font-size:var(--text-sm)">
                                    {{ $next->duration_min }} min
                                    @if ($next->location_text || $next->location) · {{ $next->location_text ?: $next->location?->name }}@endif
                                </div>
                                <div class="flex ac jb" style="margin-top:var(--space-4)">
                                    @if ($nextIsCoach)
                                        <span class="chip chip-sm" style="background:var(--paper);color:var(--ink)"><x-icon name="whistle" :size="12" /> Tu encadres</span>
                                    @elseif ($nextIsParticipant)
                                        <span class="chip chip-sm" style="background:var(--brand);color:var(--fg-on-primary)"><x-icon name="check" :size="12" /> {{ $subjectFirstName ? $subjectFirstName.' participe' : 'Tu participes' }}</span>
                                    @else
                                        <span class="chip chip-sm chip-line">
                                            @if ($next->capacity){{ $next->registrations->where('status', 'participating')->count() }}/{{ $next->capacity }}@else à venir @endif
                                        </span>
                                    @endif
                                    <span style="color:var(--paper);font-weight:700;font-size:var(--text-sm)">Voir la fiche ›</span>
                                </div>
                            </div>
                        </a>
                    @else
                        <div class="card card-pad"><div class="meta">Aucune séance à venir pour le moment.</div></div>
                    @endif

                    {{-- Prochaines séances --}}
                    <div class="sect-head" style="margin-top:var(--space-5)"><span class="sect-title">Mes prochaines séances</span></div>
                    @if ($myUpcoming->isEmpty())
                        <div class="card card-pad"><div class="meta">{{ $subjectFirstName ? "Aucune séance à venir où {$subjectFirstName} est inscrit·e." : 'Aucune séance à venir où tu es inscrit·e.' }}</div></div>
                    @else
                        <div class="home-cards">
                            @foreach ($myUpcoming as $s)
                                <x-session-card :session="$s" :tz="$tz" variant="row"
                                    :viewAs="$subjectUser->id" :subjectName="$subjectFirstName" />
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Colonne droite --}}
                <div class="home-side">
                    <div class="card card-pad">
                        <div class="eyebrow" style="margin-bottom:10px">Cette semaine</div>
                        <div class="dsp" style="font-size:40px">{{ $weekCount }}</div>
                        <div class="meta">séance{{ $weekCount > 1 ? 's' : '' }} programmée{{ $weekCount > 1 ? 's' : '' }}</div>
                        <a href="{{ route('planning') }}" wire:navigate class="btn btn-ghost btn-block" style="margin-top:14px">
                            <x-icon name="calendar" :size="16" /> Voir le planning
                        </a>
                    </div>
                    @include('livewire.partials.home-quotas')
                    @include('livewire.partials.home-apero')
                </div>
            </div>
        </div>
    </div>
</div>
