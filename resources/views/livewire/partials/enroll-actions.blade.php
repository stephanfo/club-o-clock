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
    {{-- Plus de marge basse : le bloc finit sur le segment, et la barre fixe mobile a déjà son
         propre padding — la marge s'y ajoutait et mangeait de la hauteur pour rien. --}}
    <div style="{{ ($variant ?? 'mobile') === 'mobile' ? 'flex:1' : 'margin-bottom:12px' }}">
        {{-- Titre en desktop seulement : la colonne enchaîne sur la section « Gestion » (son propre
             eyebrow), il sépare deux groupes. Dans la barre fixe mobile il n'y a rien à séparer —
             aucun autre état de cette barre n'est titré, et le segment se lit seul. --}}
        @if (($variant ?? 'mobile') === 'desktop')
            <div class="eyebrow" style="margin-bottom:6px">Mon inscription</div>
        @endif
        {{-- Contrôle segmenté (seg/seg-item) plutôt que deux boutons : « J'encadre » est un ÉTAT,
             pas une action — rendu en <span>, il ne peut plus être pris pour un bouton cliquable.
             Seul « Je participe » est actionnable, et la forme segmentée dit « voici mon rôle,
             voici l'autre » sans avoir à le lire dans le texte d'aide.
             Pas de texte de conséquence sous le contrôle : le dialog de bascule (coach-dialogs)
             l'énonce déjà avant toute action — le répéter en permanence coûtait quatre lignes sur
             la barre mobile, qui masquaient le bas de la page. --}}
        <div class="seg seg-roles" role="group" aria-label="Mon rôle sur cette séance">
            <span class="seg-item on" aria-current="true"><x-icon name="whistle" :size="14" /> J’encadre</span>
            <button type="button" class="seg-item" wire:click="flipToAthlete({{ auth()->id() }})"
                    wire:loading.attr="disabled" wire:target="flipToAthlete">Je participe</button>
        </div>
        {{-- Quitter la séance sans y participer : le segment ne couvre que le choix ENTRE les deux
             rôles, pas le retrait pur. Discret (lien) pour ne pas concurrencer la bascule. --}}
        @if (($canManageCoaches ?? false) && ! $session->hasStarted())
            <button wire:click="unregisterCoach({{ auth()->id() }})" wire:loading.attr="disabled" wire:target="unregisterCoach"
                    class="auth-link" style="margin-top:8px">Me retirer de l’encadrement</button>
        @endif
    </div>
@endif

{{-- Un encadrant ici ne peut pas être athlète sur la même séance : la voie « Je participe »
     ci-dessus (bascule §4.11.5) remplace les boutons d'inscription athlète. Si cette voie est
     fermée (pas le rôle athlète, ou inscription bloquée §4.4/§4.5), on affiche le motif plutôt
     que rien : masquer le bouton sans explication laisserait une zone muette (PRD §4.5 l.281). --}}
@if ($iAmCoachHere ?? false)
    @php($canLeaveCoaching = ($canManageCoaches ?? false) && $session->kind === 'training'
        && ! $session->isCancelled() && ! $session->hasStarted())
    @if (! auth()->user()?->hasRole('athlete'))
        {{-- Coach-pur encadrant : pas d'inscription athlète possible, mais le retrait de
             l'encadrement lui est offert ici — symétrique de « M'inscrire comme coach ». Il n'était
             atteignable que par l'icône de l'onglet Encadrement. Le dialog « dernier coach » et la
             garde serveur restent ceux de unregisterCoach. --}}
        @if ($canLeaveCoaching)
            <button wire:click="unregisterCoach({{ auth()->id() }})" wire:loading.attr="disabled" wire:target="unregisterCoach"
                    class="btn btn-ghost {{ ($variant ?? 'mobile') === 'desktop' ? 'btn-block' : 'f1' }}">
                <x-icon name="user-minus" :size="15" /> Me retirer de l’encadrement
            </button>
        @else
            <div class="meta {{ ($variant ?? 'mobile') === 'desktop' ? '' : 'f1' }}" style="font-size:var(--text-xs);align-self:center">Tu encadres cette séance.</div>
        @endif
    @elseif (! ($canEnroll ?? false))
        @include('livewire.partials.enroll-block-reason')
        @if ($canLeaveCoaching)
            <button wire:click="unregisterCoach({{ auth()->id() }})" wire:loading.attr="disabled" wire:target="unregisterCoach"
                    class="btn btn-ghost {{ ($variant ?? 'mobile') === 'desktop' ? 'btn-block' : 'f1' }}"
                    @if (($variant ?? 'mobile') === 'desktop') style="margin-top:var(--space-2)" @endif>
                <x-icon name="user-minus" :size="15" /> Me retirer de l’encadrement
            </button>
        @endif
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
