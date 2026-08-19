{{-- Motif de blocage d'inscription (§4.4 suspension, §4.5 catégorie), partagé par la voie athlète
     normale et par la voie encadrant (bascule §4.11.5 fermée). Reçoit du scope parent :
     $enrollBlockReason, $variant, $subjName (parent → enfant, §4.2). --}}
@php($reason = $enrollBlockReason ?? 'suspended')
<div class="meta {{ ($variant ?? 'mobile') === 'desktop' ? '' : 'f1' }}" style="font-size:var(--text-xs);align-self:center">
    @if ($reason === 'no_category')
        {{-- §4.5 : athlète sans catégorie active — inscription bloquée partout. --}}
        {{ $subjName ? "Aucune catégorie attribuée au compte de {$subjName} — contacte l'admin." : 'Aucune catégorie attribuée à ton compte — contacte l\'admin.' }}
    @elseif ($reason === 'category_mismatch')
        {{-- §4.5 : la séance ne cible aucune des catégories de l'athlète. --}}
        {{ $subjName ? "Cette séance ne concerne pas la catégorie de {$subjName}." : "Cette séance ne concerne pas ta catégorie." }}
    @else
        {{ $subjName ? "L'accès aux inscriptions de {$subjName} est suspendu — contacte le bureau." : 'Ton accès aux inscriptions est suspendu — contacte le bureau.' }}
    @endif
</div>
