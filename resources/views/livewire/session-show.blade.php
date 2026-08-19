@php
    $kindLabels = ['training' => 'Entraînement', 'competition' => 'Compétition', 'club_event' => 'Événement club'];
    $cls = $session->colorClass();
    $icon = $session->discipline?->icon() ?? 'calendar';
    $participating = $session->registrations->where('status', 'participating')->sortBy('registered_at')->values();
    // Waitlists triées FIFO (registered_at ASC) ; ->values() réindexe pour un rang d'affichage juste.
    $wlCap = $session->registrations->where('status', 'waitlist')->where('waitlist_reason', 'capacity')->sortBy('registered_at')->values();
    $wlQuota = $session->registrations->where('status', 'waitlist')->where('waitlist_reason', 'quota_exceeded')->sortBy('registered_at')->values();
    // Une compétition sans discipline retombe sur la classe 'competition', qui n'a pas de token de
    // teinte : on emprunte --accent (ex- --hibiscus, renommé au passage en palette d'instance).
    $borderColor = $cls === 'competition' ? 'accent' : $cls;
    $eyebrow = ($session->discipline?->label ?? $kindLabels[$session->kind])
        . ($session->kind === 'training' && $session->quotaTag ? ' · #'.$session->quotaTag->code : '');
    $startLocal = $session->start_at->copy()->setTimezone($tz);
    $endLocal   = $startLocal->copy()->addMinutes($session->duration_min);
    $dateLine = $startLocal->locale('fr')->isoFormat('ddd D MMM YYYY · HH:mm') . ' — ' . $endLocal->format('H:i');

    // Retour planning : réancre la vue hebdo sur la semaine de la séance (pas la semaine courante).
    $planningUrl = route('planning', ['view' => 'week', 'anchor' => $startLocal->toDateString()]);

    // État d'inscription du SUJET consulté (§4.9) : soi, ou l'enfant garanti sélectionné (§4.2).
    $me = auth()->user();
    $subj = $subjectUser ?? $me;
    $subjName = $subjectFirstName ?? null; // prénom si le sujet est un enfant, sinon null
    $myReg = $session->registrations->firstWhere('user_id', $subj?->id);
    $myStatus = $myReg && $myReg->status !== 'cancelled' ? $myReg->status : null; // participating | waitlist | null
    $isFull = $session->capacity !== null && $participating->count() >= $session->capacity;
    $started = $session->start_at->isPast();
    $canEnroll = $me?->can('enroll', [$session, $subj]) ?? false;
    // Motif de blocage (§4.4 suspension, §4.5 catégorie) pour afficher le bon message à l'athlète.
    // Ignoré si $canEnroll ou si déjà inscrit (le grandfathering rend canEnroll vrai de toute façon).
    $enrollBlockReason = null;
    if (! $canEnroll && $subj) {
        // L'absence de rôle athlète (§2) prime : un coach-pur n'a pas de catégorie et n'en aura
        // jamais — lui dire « contacte l'admin » l'enverrait vers une démarche sans issue.
        $enrollBlockReason = ! $subj->hasRole('athlete')
            ? 'not_athlete'
            : ($subj->athlete_access_suspended
                ? 'suspended'
                : (! $subj->hasActiveCategory() ? 'no_category' : 'category_mismatch'));
    }
    // Éligibilité de MOI, indépendante du sujet consulté (§4.2) : la bascule de rôle agit sur
    // auth()->user(), jamais sur l'enfant sélectionné. Sans ça, un parent coach+athlète perdait
    // « Je participe » dès qu'il consultait un enfant non inscriptible.
    $meCanEnroll = $me && $subj && $me->id === $subj->id
        ? $canEnroll
        : ($me?->can('enroll', [$session, $me]) ?? false);

    // Motif de blocage me concernant, pour le bloc encadrant (même logique que $enrollBlockReason).
    $meBlockReason = null;
    if (! $meCanEnroll && $me) {
        $meBlockReason = ! $me->hasRole('athlete')
            ? 'not_athlete'
            : ($me->athlete_access_suspended
                ? 'suspended'
                : (! $me->hasActiveCategory() ? 'no_category' : 'category_mismatch'));
    }

    $myWlPos = null;
    if ($myStatus === 'waitlist') {
        $pos = $wlCap->search(fn ($r) => $r->user_id === $subj->id); // $wlCap est déjà trié FIFO + réindexé
        $myWlPos = $pos === false ? null : $pos + 1;
    }
    $wlLabel = ($subjName ? "{$subjName} en liste d'attente" : "En liste d'attente") . ($myWlPos ? " · {$myWlPos}ᵉ" : '');
    $participeChip = $subjName ? "{$subjName} participe" : 'Tu participes';

    // Vue coach/admin (§4.10.5/.7) : surcapacité, badges override, déblocage quota.
    $isStaff = $me && ($me->hasRole('coach') || $me->hasRole('admin'));
    $overCapacity = $session->capacity !== null ? max(0, $participating->count() - $session->capacity) : 0;
    $canFillQuota = $wlCap->isEmpty() && $wlQuota->isNotEmpty()
        && ($session->capacity === null || $participating->count() < $session->capacity);

    // Onglets fiche mobile — n'afficher que ceux qui portent quelque chose d'utile.
    // Règle : Infos + Apéro toujours (l'apéro garde son CTA « J'offre l'apéro » même vide).
    // Inscrits/Encadrement : toujours pour le staff (gestes de gestion — inscrire, constater
    // l'absence d'encadrant), sinon seulement si non vide. Waitlist : uniquement si une file
    // est peuplée (une waitlist vide n'offre aucun geste, même staff). Parcours : si un parcours
    // est associé. Débriefs : dès qu'il s'agit d'une compétition (le staff y crée les débriefs).
    $activeDebriefs = $session->debriefs->whereNull('archived_at')->count();
    $coachCount = $session->coaches->count();
    $wlTotal = $wlCap->count() + $wlQuota->count();
    $hasRoute = (bool) ($session->route_openrunner_embed_url || $session->route_openrunner_public_url || $session->route_id);

    $tabs = [['v' => 'infos', 'l' => 'Infos']];
    if ($isStaff || $coachCount > 0) {
        $tabs[] = ['v' => 'encadrement', 'l' => 'Encadrement', 'badge' => $coachCount ?: null];
    }
    if ($isStaff || $participating->count() > 0) {
        $tabs[] = ['v' => 'inscrits', 'l' => 'Inscrits', 'badge' => $participating->count() ?: null];
    }
    if ($wlTotal > 0) {
        $tabs[] = ['v' => 'waitlist', 'l' => 'Waitlist', 'badge' => $wlTotal];
    }
    if ($hasRoute) {
        $tabs[] = ['v' => 'parcours', 'l' => 'Parcours'];
    }
    $tabs[] = ['v' => 'apero', 'l' => 'Apéro', 'badge' => $aperoPayers->count() ?: null];
    if ($session->kind === 'competition') {
        $tabs[] = ['v' => 'debriefs', 'l' => 'Débriefs', 'badge' => $activeDebriefs ?: null];
    }
    // Source unique de visibilité : un panneau n'est rendu que si son onglet existe (évite un
    // onglet cliquable sans panneau, ou un panneau orphelin, quand une condition évolue).
    $shown = array_column($tabs, 'v');
@endphp
<div class="fiche-screen" x-data="{ tab: 'infos' }">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />

    {{-- ═══════════════ MOBILE : mini-barre fixe + héros fondu + tabs + barre collante ═══════════════ --}}
    <div class="fiche-mobile">
        {{-- Mini-barre verte FIXE (retour + cloche + éditer) — même modèle que l'accueil : elle couvre
             la safe-area iOS et reste collée, le héros ci-dessous scrolle. Elle se fond dans le héros
             fondu (même dégradé vert), le libellé n'est donc pas nécessaire ici. --}}
        <div class="topbar topbar-fiche">
            {{-- Retour historique d'abord (restaure la vue et les filtres du planning tels quels),
                 $planningUrl en repli. Pas de wire:navigate : cf. components/topbar.blade.php. --}}
            <a href="{{ $planningUrl }}" class="iconbtn" aria-label="Retour planning"
               onclick="return !window.clubBack?.()"><x-icon name="chevron-left" /></a>
            <span class="topbar-spacer"></span>
            {{-- Barre composée à la main (pas <x-topbar>) : cf. la même insertion sur l'accueil. --}}
            <x-demo-badge mode="bar" />
            @if ($aperoPayers->isNotEmpty())
                <span class="iconbtn" aria-label="Apéro offert" title="Apéro offert"><x-chope :size="18" style="color:var(--accent-200)" /></span>
            @endif
            <x-alert-bell dark />
        </div>

        {{-- En-tête séance = héros fondu (disc-badge + titre + date + chips), scrolle sous la
             mini-barre. Fond = même dégradé vert horizontal que la topbar → jonction invisible. --}}
        <div class="fiche-head-m">
            <div class="flex ac g8">
                <x-disc-badge :cls="$cls" :icon="$icon" />
                <div class="f1">
                    <div class="eyebrow" style="color:var(--brand-200)">{{ $eyebrow }}</div>
                    <div class="dsp" style="font-size:30px;margin-top:1px;color:var(--paper)">{{ $session->title }}</div>
                </div>
                {{-- Éditer (staff) — icône à droite du titre (revue coh. 2026-07-12) : compacte, rattachée
                     à CETTE séance, plus dans la topbar où elle jouxtait la cloche. Icône claire sur le vert. --}}
                @can('update', $session)
                    @unless ($session->isCancelled())
                        <a href="{{ route('sessions.edit', $session) }}" wire:navigate class="fiche-edit-m" aria-label="Modifier la séance">
                            <x-icon name="pencil" :size="18" />
                        </a>
                    @endunless
                @endcan
            </div>
            <div class="meta" style="margin-top:var(--space-2);color:var(--fg-on-dark-soft)">{{ $dateLine }}</div>

            @if ($session->isCancelled())
                <div class="cancel-strip" style="margin-top:var(--space-3)">
                    <x-icon name="x" :size="18" />
                    <div class="f1"><div class="ct">Séance annulée</div></div>
                </div>
            @else
                <div class="flex ac g6 wrap" style="margin-top:var(--space-3)">
                    @if ($session->capacity)
                        <span class="chip chip-sm {{ $participating->count() >= $session->capacity ? 'chip-pink' : 'chip-line' }}">
                            {{ $participating->count() >= $session->capacity ? 'Complet' : 'Places' }} · {{ $participating->count() }}/{{ $session->capacity }}
                        </span>
                    @else
                        <span class="chip chip-sm chip-line">{{ $participating->count() }} inscrit·e·s</span>
                    @endif
                    @if ($myStatus === 'participating')
                        <span class="chip chip-sm chip-green"><x-icon name="check" :size="12" /> {{ $participeChip }}</span>
                    @elseif ($myStatus === 'waitlist')
                        <span class="chip chip-sm chip-warn">{{ $wlLabel }}</span>
                    @endif
                </div>
            @endif
        </div>

        <x-tabs :items="$tabs" alpine="tab" />

        <div class="fiche-scroll-m">
            {{-- Infos --}}
            <div x-show="tab === 'infos'">
                <div style="display:flex;flex-direction:column;gap:var(--space-4)">
                @include('livewire.partials.fiche-infos', ['session' => $session, 'tz' => $tz])
                </div>
            </div>
            {{-- Encadrement — onglet masqué si vide hors staff (cf. $tabs) : ne rendre le panneau
                 que s'il existe, pour ne pas laisser un x-show mort dans le DOM. --}}
            @if (in_array('encadrement', $shown))
            <div x-show="tab === 'encadrement'" x-cloak>
                @include('livewire.partials.fiche-encadrement')
            </div>
            @endif
            {{-- Inscrits --}}
            @if (in_array('inscrits', $shown))
            <div x-show="tab === 'inscrits'" x-cloak>
                <div style="display:flex;flex-direction:column;gap:var(--space-3)">
                {{-- Inscription d'un athlète par le bureau (§4.9.7) — porté du proto FInscrits. --}}
                @if ($canEnrollOther)
                    <button wire:click="openAthletePicker" class="btn btn-primary btn-block">
                        <x-icon name="user-plus" :size="16" /> Inscrire un athlète
                    </button>
                @endif
                @include('livewire.partials.registrant-block', ['title' => 'Inscrits', 'sub' => $session->capacity ? 'Capacité '.$session->capacity : 'Sans limite', 'list' => $participating, 'removeMethod' => 'removeAthlete'])
                </div>
            </div>
            @endif
            {{-- Waitlist — onglet présent uniquement si une file est peuplée. --}}
            @if (in_array('waitlist', $shown))
            <div x-show="tab === 'waitlist'" x-cloak>
                <div style="display:flex;flex-direction:column;gap:var(--space-3)">
                @if ($wlCap->isNotEmpty())
                    @include('livewire.partials.registrant-block', ['title' => 'Séance pleine', 'sub' => 'Capacité · FIFO', 'list' => $wlCap, 'removeMethod' => 'removeAthlete'])
                @endif
                @if ($wlQuota->isNotEmpty())
                    @include('livewire.partials.registrant-block', ['title' => 'Quota dépassé', 'sub' => 'quota_exceeded · FIFO', 'list' => $wlQuota, 'removeMethod' => 'removeAthlete'])
                    @can('update', $session)
                        {{-- Mécanisme C (§4.10.4) : déblocage coach de la file quota. --}}
                        <button wire:click="fillQuota" @disabled(! $canFillQuota)
                                class="btn btn-primary btn-block {{ $canFillQuota ? '' : 'is-disabled' }}">
                            <x-icon name="chevron-up" :size="16" /> Remplir avec la file quota
                        </button>
                        @unless ($canFillQuota)
                            <div class="meta tc" style="font-size:var(--text-xs)">Disponible quand la file « séance pleine » est vide et qu'il reste des places.</div>
                        @endunless
                    @endcan
                @endif
                </div>
            </div>
            @endif
            {{-- Parcours (§4.13) — onglet présent uniquement si un parcours est associé. --}}
            @if (in_array('parcours', $shown))
            <div x-show="tab === 'parcours'" x-cloak>
                @include('livewire.partials.fiche-parcours')
            </div>
            @endif
            {{-- Apéro (§4.14) --}}
            <div x-show="tab === 'apero'" x-cloak>
                @include('livewire.partials.fiche-apero')
            </div>
            {{-- Débriefs (§4.12.5) — compétition --}}
            @if ($session->kind === 'competition')
                <div x-show="tab === 'debriefs'" x-cloak>
                    @include('livewire.partials.fiche-debriefs')
                </div>
            @endif
        </div>

        {{-- Barre d'action collante --}}
        <div class="fiche-actions-m">
            @if ($session->isCancelled())
                @can('restore', $session)
                    <button wire:click="restore" class="btn btn-primary btn-block">
                        <x-icon name="rotate-ccw" :size="16" /> Restaurer la séance
                    </button>
                @else
                    <x-banner kind="danger" style="margin:0;flex:1">Tu as été notifié·e de l'annulation.</x-banner>
                @endcan
            @elseif ($started)
                <div class="meta f1" style="font-size:var(--text-xs);align-self:center">Séance commencée — inscriptions closes.</div>
            @else
                {{-- Le partial porte lui-même le repli encadrant (coach-pur, ou inscription bloquée
                     §4.4/§4.5) : sans texte, cette barre `position: fixed` s'afficherait vide. --}}
                @include('livewire.partials.enroll-actions', ['variant' => 'mobile'])
            @endif
        </div>
    </div>

    {{-- ═══════════════ DESKTOP : topbar + 2 colonnes ═══════════════ --}}
    <div class="fiche-desktop">
        <div class="dk-topbar">
            <a href="{{ $planningUrl }}" class="btn btn-ghost btn-sm"
               onclick="return !window.clubBack?.()"><x-icon name="chevron-left" :size="15" /> Planning</a>
            <span class="meta">{{ $dateLine }}</span>
            @if ($session->isCancelled())
                <span class="chip chip-sm chip-cancel"><x-icon name="x" :size="12" /> Annulée</span>
            @endif
            @if ($aperoPayers->isNotEmpty())
                <span title="Apéro offert" aria-label="Apéro offert"><x-chope :size="18" style="color:var(--apero)" /></span>
            @endif
            <span class="mlauto"></span>
            @can('update', $session)
                <span class="role-badge">Vue coach</span>
                @unless ($session->isCancelled())
                    <a href="{{ route('sessions.edit', $session) }}" wire:navigate class="btn btn-ghost btn-sm"><x-icon name="edit" :size="14" /> Modifier</a>
                @endunless
            @endcan
        </div>

        <div class="dk-body">
            <div class="fiche-grid">
                {{-- Colonne gauche --}}
                <div class="fiche-col">
                    <div class="fiche-hero" style="background:var(--{{ $cls }}-50, var(--slate-50));border-left:5px solid var(--{{ $borderColor }})">
                        <div class="flex ac g8">
                            <x-disc-badge :cls="$cls" :icon="$icon" />
                            <div>
                                <div class="eyebrow">{{ $eyebrow }}</div>
                                <div class="dsp" style="font-size:30px;margin-top:1px">{{ $session->title }}</div>
                            </div>
                        </div>
                        <div class="meta" style="margin-top:var(--space-2)">
                            {{ $startLocal->format('H:i') }} — {{ $endLocal->format('H:i') }} · {{ $session->duration_min }} min
                            @if ($session->location_text || $session->location) · {{ $session->location_text ?: $session->location?->name }}@endif
                        </div>
                    </div>

                    {{-- noWeather=true : météo déplacée dans la colonne droite desktop (screen-fiche.jsx:394). --}}
                    @include('livewire.partials.fiche-infos', ['session' => $session, 'tz' => $tz, 'noLead' => true, 'noWeather' => true])
                    @if ($session->route_openrunner_embed_url || $session->route_openrunner_public_url || $session->route_id)
                        <div>
                            <div class="sect-head"><span class="sect-title">Parcours</span></div>
                            @include('livewire.partials.fiche-parcours')
                        </div>
                    @endif
                    @include('livewire.partials.fiche-encadrement')
                    @if ($session->kind === 'competition')
                        @include('livewire.partials.fiche-debriefs')
                    @endif
                </div>

                {{-- Colonne droite --}}
                <div class="fiche-side">
                    <div class="card card-pad">
                        @if ($session->isCancelled())
                            <div class="flex ac g8" style="margin-bottom:var(--space-3)">
                                <x-icon name="x" :size="16" style="color:var(--danger)" />
                                <span class="eyebrow" style="color:var(--danger)">Séance annulée</span>
                            </div>
                            @can('restore', $session)
                                <button wire:click="restore" class="btn btn-primary btn-block" style="margin-bottom:var(--space-2)">
                                    <x-icon name="rotate-ccw" :size="15" /> Restaurer la séance
                                </button>
                                <div class="meta" style="font-size:var(--text-xs)">Possible tant que le créneau n'est pas dépassé. Les inscriptions seront rétablies.</div>
                            @else
                                <x-banner kind="danger">Tu as été notifié·e de l'annulation.</x-banner>
                            @endcan
                        @else
                            <div class="flex g6 wrap" style="margin-bottom:var(--space-3)">
                                @if ($session->capacity)
                                    <span class="chip chip-sm {{ $participating->count() >= $session->capacity ? 'chip-pink' : 'chip-line' }}">
                                        {{ $participating->count() >= $session->capacity ? 'Complet' : 'Places' }} · {{ $participating->count() }}/{{ $session->capacity }}
                                    </span>
                                @else
                                    <span class="chip chip-sm chip-line">{{ $participating->count() }} inscrit·e·s</span>
                                @endif
                                @if ($overCapacity > 0)
                                    <span class="chip chip-sm chip-warn">surcapacité +{{ $overCapacity }}</span>
                                @endif
                                @if ($myStatus === 'participating')
                                    <span class="chip chip-sm chip-green"><x-icon name="check" :size="12" /> {{ $participeChip }}</span>
                                @elseif ($myStatus === 'waitlist')
                                    <span class="chip chip-sm chip-warn">{{ $wlLabel }}</span>
                                @endif
                            </div>

                            @if ($started)
                                <div class="meta" style="font-size:var(--text-xs)">Séance commencée — inscriptions closes.</div>
                            @else
                                @include('livewire.partials.enroll-actions', ['variant' => 'desktop'])
                            @endif

                            @can('update', $session)
                                <div class="eyebrow" style="margin:14px 0 6px">Gestion</div>
                                {{-- Inscription d'un athlète par le bureau (§4.9.7). --}}
                                @if ($canEnrollOther)
                                    <button wire:click="openAthletePicker" class="btn btn-primary btn-block" style="margin-bottom:var(--space-2)">
                                        <x-icon name="user-plus" :size="16" /> Inscrire un athlète
                                    </button>
                                @endif
                                {{-- Mécanisme C (§4.10.4) : déblocage de la file quota_exceeded. --}}
                                @if ($wlQuota->isNotEmpty())
                                    <button wire:click="fillQuota" @disabled(! $canFillQuota)
                                            class="btn btn-primary btn-block {{ $canFillQuota ? '' : 'is-disabled' }}" style="margin-bottom:var(--space-2)">
                                        <x-icon name="chevron-up" :size="16" /> Remplir avec la file quota
                                    </button>
                                    @unless ($canFillQuota)
                                        <div class="meta" style="font-size:var(--text-xs);margin-bottom:var(--space-2)">
                                            Disponible quand la file « séance pleine » est vide et qu'il reste des places.
                                        </div>
                                    @endunless
                                @endif
                                <button wire:click="openCancelConfirm" class="btn btn-danger btn-block">
                                    <x-icon name="x" :size="15" /> Annuler la séance
                                </button>
                            @endcan
                        @endif
                    </div>

                    {{-- Météo (§4.13.5) : colonne droite desktop (cf. screen-fiche.jsx:394) --}}
                    @if (($weatherState ?? 'none') !== 'none')
                        <div>
                            <div class="eyebrow" style="margin-bottom:8px">Météo · J-16</div>
                            @include('livewire.partials.weather-cartouche')
                        </div>
                    @endif

                    @include('livewire.partials.registrant-block', ['title' => 'Inscrits', 'sub' => $session->capacity ? 'Capacité '.$session->capacity : 'Sans limite', 'list' => $participating, 'removeMethod' => 'removeAthlete'])

                    @if ($wlCap->isNotEmpty())
                        @include('livewire.partials.registrant-block', ['title' => 'Séance pleine', 'sub' => 'Capacité · FIFO', 'list' => $wlCap, 'removeMethod' => 'removeAthlete'])
                    @endif
                    @if ($wlQuota->isNotEmpty())
                        @include('livewire.partials.registrant-block', ['title' => 'Quota dépassé', 'sub' => 'quota_exceeded · FIFO', 'list' => $wlQuota, 'removeMethod' => 'removeAthlete'])
                    @endif

                    {{-- Apéro (§4.14) --}}
                    @include('livewire.partials.fiche-apero')
                </div>
            </div>
        </div>
    </div>

    {{-- Dialog « Annuler la séance » (§4.7) — action structurante : dialog avec conséquences,
         pas un confirm() natif (revue UX 2026-07-11, constat n°4). --}}
    @if ($confirmingCancel)
        <x-dialog title="Annuler la séance" sub="{{ $session->title }}" danger :width="460" close="dismissCancelConfirm">
            <div style="display:flex;flex-direction:column;gap:12px">
                <x-conseq-row icon="bell" label="Inscrits" tone="warn">Tous les inscrits sont notifiés de l'annulation.</x-conseq-row>
                <x-conseq-row icon="bar-chart" label="Quotas">La séance ne compte plus dans les quotas — les files des autres séances sont débloquées.</x-conseq-row>
                <x-conseq-row icon="rotate-ccw" label="Réversible">Tu peux réactiver la séance tant qu'elle n'a pas commencé — inscriptions et apéros sont rétablis.</x-conseq-row>
            </div>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="dismissCancelConfirm">Garder la séance</button>
                <button type="button" class="btn btn-danger" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel">
                    <x-icon name="x" :size="14" /> Annuler la séance
                </button>
            </x-slot:footer>
        </x-dialog>
    @endif

    {{-- Dialogs d'encadrement (§4.11) — pilotés par les propriétés Livewire. --}}
    @include('livewire.partials.coach-dialogs')

    {{-- Éditeur de débrief + confirmation d'archivage (§4.12.5). --}}
    @if ($session->kind === 'competition')
        @include('livewire.partials.debrief-editor')
    @endif
</div>
