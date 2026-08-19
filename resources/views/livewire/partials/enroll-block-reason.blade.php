{{-- Motif de blocage d'inscription (§4.4 suspension, §4.5 catégorie), partagé par la voie athlète
     normale et par la voie encadrant (bascule §4.11.5 fermée). Reçoit du scope parent :
     $enrollBlockReason, $variant, $subjName (parent → enfant, §4.2). --}}
@php($reason = $enrollBlockReason ?? 'suspended')
{{-- Coach-pur (§2) : l'inscription athlète ne le concerne pas, mais l'encadrement si — on propose
     le geste utile plutôt qu'un simple constat. Mêmes conditions que « M'inscrire comme coach » de
     l'onglet Encadrement : rôle coach RÉEL (canManageCoaches vaut aussi pour un admin-pur, que
     CoachRegistrationService::register refuse — « n'a pas le rôle coach »), training ouvert, et
     pas déjà encadrant.
     ! $subjName : en voie parent → enfant, le sujet est l'enfant alors que registerCoachSelf
     agirait sur le parent — on s'en tient au message dans ce cas. --}}
@if ($reason === 'not_athlete' && ! $subjName
     && auth()->user()?->hasRole('coach') && ! ($iAmCoachHere ?? false)
     && $session->kind === 'training' && ! $session->isCancelled() && ! $session->hasStarted())
    <button wire:click="registerCoachSelf" wire:loading.attr="disabled" wire:target="registerCoachSelf"
            class="btn btn-dark {{ ($variant ?? 'mobile') === 'desktop' ? 'btn-block' : 'f1' }}">
        <x-icon name="whistle" :size="15" /> M’inscrire comme coach
    </button>
@else
<div class="meta {{ ($variant ?? 'mobile') === 'desktop' ? '' : 'f1' }}" style="font-size:var(--text-xs);align-self:center">
    @if ($reason === 'not_athlete')
        {{-- §2 : sans rôle athlète, aucune catégorie ne débloquera l'inscription. --}}
        {{ $subjName ? "Le compte de {$subjName} n'est pas athlète." : "Ton compte n'est pas athlète : l'inscription aux séances ne te concerne pas." }}
    @elseif ($reason === 'no_category')
        {{-- §4.5 : athlète sans catégorie active — inscription bloquée partout. --}}
        {{ $subjName ? "Aucune catégorie attribuée au compte de {$subjName} — contacte l'admin." : 'Aucune catégorie attribuée à ton compte — contacte l\'admin.' }}
    @elseif ($reason === 'category_mismatch')
        {{-- §4.5 : la séance ne cible aucune des catégories de l'athlète. --}}
        {{ $subjName ? "Cette séance ne concerne pas la catégorie de {$subjName}." : "Cette séance ne concerne pas ta catégorie." }}
    @else
        {{ $subjName ? "L'accès aux inscriptions de {$subjName} est suspendu — contacte le bureau." : 'Ton accès aux inscriptions est suspendu — contacte le bureau.' }}
    @endif
</div>
@endif
