{{-- Marqueur « champ structurant » (§4.7) — porté de screen-creation <StructTag>.
     Modifier un champ ainsi marqué proposera de notifier les inscrits (dialog de sauvegarde).
     show = false → ne rend rien (le marqueur n'a de sens qu'en édition d'une séance existante). --}}
@props(['show' => true])
@if ($show)
<span title="Champ structurant" style="display:inline-flex;vertical-align:middle;margin-left:5px;color:var(--accent)"><x-icon name="bell" :size="12" /></span>
@endif
