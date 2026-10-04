{{-- Niveau de coureur (#114) : choisi ici, il s'applique à l'estimation de VMA et à la projection
     de temps — d'où un bloc à part, au-dessus des deux. Seulement si le club propose plusieurs niveaux. --}}
@if ($levels->count() > 1)
    <div class="card card-pad" wire:key="al-niveau">
        <div class="eyebrow" style="margin-bottom:4px">Mon niveau</div>
        <div class="meta" style="margin-bottom:10px">Sert à estimer ta VMA depuis une course et à projeter tes temps de course.</div>
        <div class="seg" role="radiogroup" aria-label="Niveau" style="flex-wrap:wrap">
            @foreach ($levels as $l)
                <button type="button" role="radio" aria-checked="{{ $level?->is($l) ? 'true' : 'false' }}"
                    class="seg-item{{ $level?->is($l) ? ' on' : '' }}" wire:click="$set('levelId', {{ $l->id }})">{{ $l->label }}</button>
            @endforeach
        </div>
    </div>
@endif
