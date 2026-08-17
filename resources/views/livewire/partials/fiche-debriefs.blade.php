{{-- Section « Débriefs » de la fiche compétition — porté de screen-debriefs.jsx <DebriefSection>.
     Reçoit : $session, $debriefLabels, $canWriteDebrief, $tz. --}}
@php
    $me = auth()->user();
    $isAdmin = $me?->hasRole('admin') ?? false;
    $isCoach = ($me?->hasRole('coach') ?? false) || $isAdmin;
    $started = $session->hasStarted();
    $myReg = $me ? $session->registrations->firstWhere('user_id', $me->id) : null;
    $iParticipate = $myReg !== null && $myReg->status === 'participating';

    $active = $session->debriefs->whereNull('archived_at')->sortByDesc('created_at')->values();
    $archived = $session->debriefs->whereNotNull('archived_at')->sortByDesc('archived_at')->values();
    $count = $active->count();
@endphp
<div>
    <div class="sect-head"><span class="sect-title">Débriefs</span><span class="meta mlauto">{{ $count }}</span></div>
    <div style="display:flex;flex-direction:column;gap:12px">

        {{-- Zone de rédaction (§4.12.5 : participant + après le départ) --}}
        @if ($canWriteDebrief)
            <button type="button" class="debrief-cta" wire:click="openDebrief">
                <span class="db-cta-ic"><x-icon name="pen-line" :size="20" /></span>
                <span class="f1" style="min-width:0">
                    <span style="display:block;font-weight:700;font-size:15px">Rédiger mon débrief</span>
                    <span class="meta" style="font-size:12.5px">Partage ton ressenti sur la course avec le club.</span>
                </span>
                <x-icon name="chevron-right" style="color:var(--fg-muted);flex:0 0 auto" />
            </button>
        @elseif ($iParticipate && ! $started)
            <x-banner kind="info"><div>Les débriefs s'ouvriront <b>après le départ</b> de la compétition. Reviens après la course pour partager ton ressenti.</div></x-banner>
        @elseif ($me && ! $isCoach && ! $iParticipate)
            <x-banner kind="info"><div>Écrire un débrief est réservé aux <b>membres ayant participé</b>. Tu peux lire ceux des autres ci-dessous.</div></x-banner>
        @elseif ($isCoach)
            <x-banner kind="info"><div>Les débriefs sont rédigés par les <b>participants</b>.{{ $isAdmin ? ' Tu peux éditer ou archiver chaque débrief.' : '' }}</div></x-banner>
        @endif

        {{-- Débriefs publics (non archivés) --}}
        @forelse ($active as $d)
            @include('livewire.partials.debrief-card', [
                'd' => $d,
                'label' => $debriefLabels[$d->author_id] ?? 'Membre',
                'mine' => $me && $d->author_id === $me->id,
                'archived' => false,
                'isAdmin' => $isAdmin,
            ])
        @empty
            <div class="card card-pad meta tc" style="padding:22px 16px">
                {{ $started ? 'Aucun débrief pour l\'instant.' : 'Les débriefs apparaîtront ici après la compétition.' }}
            </div>
        @endforelse

        {{-- Archivés (admin uniquement) --}}
        @if ($isAdmin && $archived->isNotEmpty())
            <div>
                <div class="eyebrow" style="margin:6px 0 8px">Archivés · {{ $archived->count() }}</div>
                <div style="display:flex;flex-direction:column;gap:12px">
                    @foreach ($archived as $d)
                        @include('livewire.partials.debrief-card', [
                            'd' => $d,
                            'label' => $debriefLabels[$d->author_id] ?? 'Membre',
                            'mine' => $me && $d->author_id === $me->id,
                            'archived' => true,
                            'isAdmin' => $isAdmin,
                        ])
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Convention d'affichage des auteurs (§4.9.4) --}}
        @if ($count > 0)
            <div class="meta" style="font-size:12px">
                {{ $isCoach ? 'Vue coach/admin : auteurs affichés en nom complet.' : 'Auteurs affichés en prénom + initiale (étendue en cas d\'homonymie).' }}
            </div>
        @endif
    </div>
</div>
