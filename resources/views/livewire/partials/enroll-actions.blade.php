{{-- Actions d'inscription athlète (§4.9, §4.10), partagées fiche mobile / desktop.
     Reçoit : $myStatus, $isFull, $canEnroll, $hasConflict, $confirmingQuota, $variant,
     et le sujet consulté ($subjName — parent → enfant, §4.2). --}}
@php($block = ($variant ?? 'mobile') === 'desktop' ? 'btn-block' : 'f1')
@php($subjName = $subjName ?? null)

{{-- Bascule de rôle pour SOI sur une séance training (§4.11.5 cas 1/2), porté de SelfRoleToggle.
     Visible quand je suis encadrant ici : « Je participe » déclenche le dialog de bascule.
     Réservé à qui a le rôle athlète (§2) : un coach-pur n'a pas d'existence athlète à activer,
     la bascule échouerait en NOT_AN_ATHLETE. Même garde qu'en voie tiers (fiche-encadrement).
     Conditionné en plus à $canEnroll : la bascule se termine par un register(), elle hérite donc
     des mêmes gardes que l'inscription directe (§4.5 catégorie, §4.4 suspension). Sans ça elle
     échouait en CATEGORY_MISMATCH / SUSPENDED APRÈS confirmation — bouton apparemment mort. --}}
@if (($iAmCoachHere ?? false) && auth()->user()?->hasRole('athlete') && ($canEnroll ?? false)
     && $session->kind === 'training' && ! $session->isCancelled())
    <div style="margin-bottom:12px;{{ ($variant ?? 'mobile') === 'mobile' ? 'flex:1' : '' }}">
        <div class="eyebrow" style="margin-bottom:6px">Mon inscription</div>
        <div class="flex g6">
            <button class="btn btn-dark f1" style="cursor:default" aria-pressed="true"><x-icon name="whistle" :size="14" /> J’encadre</button>
            <button class="btn btn-ghost f1" wire:click="flipToAthlete({{ auth()->id() }})">Je participe</button>
        </div>
        <div class="meta" style="font-size:11.5px;margin-top:6px;line-height:1.4">Tu encadres cette séance. Participer comme athlète demande une confirmation (place &amp; quota) et te retire de l’encadrement — un seul rôle par séance.</div>
    </div>
@endif

{{-- Un encadrant ici ne peut pas être athlète sur la même séance : la voie « Je participe »
     ci-dessus (bascule §4.11.5) remplace les boutons d'inscription athlète. Si cette voie est
     fermée (pas le rôle athlète, ou inscription bloquée §4.4/§4.5), on affiche le motif plutôt
     que rien : masquer le bouton sans explication laisserait une zone muette (PRD §4.5 l.281). --}}
@if ($iAmCoachHere ?? false)
    @if (! auth()->user()?->hasRole('athlete'))
        <div class="meta {{ ($variant ?? 'mobile') === 'desktop' ? '' : 'f1' }}" style="font-size:var(--text-xs);align-self:center">Tu encadres cette séance.</div>
    @elseif (! ($canEnroll ?? false))
        @include('livewire.partials.enroll-block-reason')
    @endif
@elseif ($myStatus === 'participating')
    <button wire:key="enr-act-unenroll-{{ $session->id }}" wire:click="unenroll" wire:loading.attr="disabled" wire:target="unenroll"
            wire:confirm="{{ $subjName ? "Désinscrire {$subjName} de cette séance ?" : 'Te désinscrire de cette séance ?' }}"
            class="btn btn-ghost {{ $block }}">{{ $subjName ? "Désinscrire {$subjName}" : 'Se désinscrire' }}</button>
@elseif ($myStatus === 'waitlist')
    <button wire:key="enr-act-leavewl-{{ $session->id }}" wire:click="unenroll" wire:loading.attr="disabled" wire:target="unenroll"
            wire:confirm="{{ $subjName ? "Retirer {$subjName} de la liste d'attente ?" : "Quitter la liste d'attente ?" }}"
            class="btn btn-ghost {{ $block }}">{{ $subjName ? "Retirer {$subjName} de la liste d'attente" : "Quitter la liste d'attente" }}</button>
@elseif (! $canEnroll)
    @include('livewire.partials.enroll-block-reason')
@elseif ($isFull)
    <button wire:key="enr-act-joinwl-{{ $session->id }}" wire:click="enroll" wire:loading.attr="disabled" wire:target="enroll"
            @if ($hasConflict) wire:confirm="{{ $subjName ? "{$subjName} est déjà inscrit·e à une séance qui chevauche ce créneau. Rejoindre quand même la liste d'attente ?" : "Tu es déjà inscrit·e à une séance qui chevauche ce créneau. Rejoindre quand même la liste d'attente ?" }}" @endif
            class="btn btn-primary {{ $block }}">
        <x-icon name="clock" :size="15" /> {{ $subjName ? "Mettre {$subjName} en liste d'attente" : "Rejoindre la liste d'attente" }}
    </button>
@else
    <button wire:key="enr-act-enroll-{{ $session->id }}" wire:click="enroll" wire:loading.attr="disabled" wire:target="enroll"
            @if ($hasConflict) wire:confirm="{{ $subjName ? "{$subjName} est déjà inscrit·e à une séance qui chevauche ce créneau. L'inscrire quand même ?" : "Tu es déjà inscrit·e à une séance qui chevauche ce créneau. T'inscrire quand même ?" }}" @endif
            class="btn btn-primary {{ $block }}">
        <x-icon name="check" :size="15" /> {{ $subjName ? "Inscrire {$subjName}" : "S'inscrire" }}
    </button>
@endif
