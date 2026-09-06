{{-- Entrée « Supprimer définitivement » (§4.7) — admin, séance déjà annulée (SessionPolicy::delete).
     Partagée par les deux formats pour qu'ils ne divergent pas : le geste appelle la même méthode et
     dit la même chose, seul l'entourage change (colonne droite en desktop, bloc « Gestion » de
     l'onglet Infos en mobile).

     Jamais dans la barre d'action collante du mobile : elle porte le geste RÉVERSIBLE (« Restaurer
     la séance »), celui qu'on veut sous le pouce. L'irréversible se mérite d'un défilement — même
     raison qui a tenu l'annulation hors de cette barre.

     Le blocage par débrief s'AFFICHE plutôt que de se découvrir au clic : la policy dit qui a le
     droit, le service dit ce qui reste rattaché (cf. le précédent GpxRouteShow::canDelete).

     Le `@can` est porté par les APPELANTS et non par ce partiel : ils ouvrent chacun un entourage
     (séparateur en desktop, titre « Gestion » en mobile) qui, sans garde extérieure, s'afficherait
     vide pour un coach — un bloc vide est précisément ce qu'une relecture de captures traque. --}}
@if ($deleteBlockers['debriefs'] > 0)
    <div class="meta" style="font-size:var(--text-xs)">
        Suppression impossible : {{ $deleteBlockers['debriefs'] }} débrief{{ $deleteBlockers['debriefs'] > 1 ? 's' : '' }}
        {{ $deleteBlockers['debriefs'] > 1 ? 'sont rattachés' : 'est rattaché' }} à cette séance.
    </div>
@else
    <button wire:click="openDeleteConfirm" class="btn btn-ghost btn-block" style="color:var(--danger)"
            wire:loading.attr="disabled" wire:target="openDeleteConfirm">
        <x-icon name="trash" :size="15" /> Supprimer définitivement
    </button>
    <div class="meta" style="font-size:var(--text-xs);margin-top:var(--space-2)">Efface la séance de la base. Sans retour possible.</div>
@endif
