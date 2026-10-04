{{-- Projection de temps de course (#114), selon la VMA et le niveau choisi. --}}
@if ($vmaValue)
    {{-- ── Projection ── --}}
    <div class="card card-pad" wire:key="al-projection">
        <div class="eyebrow" style="margin-bottom:8px">Projection de temps de course</div>
        @foreach ($distances as $key => [$label])
            @isset($projection[$key])
                <div class="flex ac jb" style="padding:8px 0;border-bottom:1px solid var(--divider)">
                    <span>{{ $label }}</span>
                    <span style="font-weight:700">{{ $range($projection[$key]) }}</span>
                </div>
            @endisset
        @endforeach
        <div class="meta" style="font-size:12px;margin-top:10px">{{ $model }}.</div>
    </div>
@endif
