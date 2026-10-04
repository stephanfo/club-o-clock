{{-- Métadonnée d'une ligne de catalogue (lecture), selon $type. Reçoit : $it. --}}
@switch($type)
    @case('category')
        <span class="meta">{{ $it->age_min }}–{{ $it->age_max }} ans</span>
        @break
    @case('quota_tag')
        <span class="meta">max {{ $it->max_per_week }}/sem</span>
        @break
    @case('qualification')
        @if ($it->code)<span class="chip chip-sm chip-line">{{ $it->code }}</span>@else<span class="meta">sans code</span>@endif
        @break
    @case('discipline')
        <span class="meta">couleur charte{{ ($r = $it->referentielEnum()) ? ' · allures '.mb_strtolower($r->label()) : '' }}</span>
        @break
    @case('allure_zone')
        <span class="meta">{{ $it->range() }} de la VMA{{ $it->aliases ? ' · alias '.implode(', ', $it->aliasList()) : '' }}</span>
        @break
    @case('event_type')
        <span class="meta">type de compétition</span>
        @break
    @case('location')
        <span class="meta">{{ $it->kind ?: 'lieu' }}@if ($it->address) · {{ $it->address }}@endif</span>
        @break
@endswitch
