{{-- Marqueur « champ obligatoire » — astérisque rouge. show = false → ne rend rien
     (permet de conditionner l'obligation selon le kind de séance, cf. rules()). --}}
@props(['show' => true])
@if ($show)
<span class="req-mark" title="Champ obligatoire" aria-label="obligatoire">*</span>
@endif
