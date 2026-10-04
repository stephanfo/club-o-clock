{{-- Estimation de la VMA depuis un résultat de course (#114). Le temps saisi n'est jamais enregistré. --}}
@use('App\Support\Allures\Calculateur', 'C')
<div class="card card-pad">
    <div class="eyebrow" style="margin-bottom:4px">Estimer ma VMA depuis une course</div>
    <div class="meta" style="margin-bottom:12px">Le temps saisi sert au calcul et n'est pas enregistré.</div>
    <div class="seg" style="flex-wrap:wrap;margin-bottom:10px">
        @foreach ($distances as $key => [$label])
            <button type="button" class="seg-item{{ $estDistance === $key ? ' on' : '' }}" wire:click="$set('estDistance', '{{ $key }}')">{{ $label }}</button>
        @endforeach
    </div>
    <div class="flex g8 wrap" style="align-items:flex-end">
        <div style="width:140px">
            <label class="field-label" for="al-time">Temps</label>
            <div class="ifield"><input id="al-time" class="ifield-input" type="text" wire:model.live.debounce.400ms="estTime" placeholder="47:45"></div>
        </div>
        @if ($levels->isNotEmpty())
            <div style="min-width:180px">
                <label class="field-label" for="al-level">Niveau</label>
                <div class="ifield">
                    <select id="al-level" class="ifield-input" wire:model.live="levelId">
                        @foreach ($levels as $l)
                            <option value="{{ $l->id }}">{{ $l->label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        @endif
    </div>
    @if ($estTimeInvalid)
        <div class="meta" style="color:var(--warning-text);margin-top:6px">Temps illisible : écris par exemple 47:45 ou 1:49:00.</div>
    @elseif ($estimate)
        @if ($estimate['improbable'])
            <x-banner kind="warn" style="margin-top:12px"><div><b>Temps improbable.</b> Il correspond à une vitesse moyenne de {{ C::formatVma($estimate['speed']) }} km/h : vérifie la distance et le temps.</div></x-banner>
        @else
            <div class="flex ac jb g8 wrap" style="margin-top:12px">
                <div>
                    <span class="meta">VMA estimée</span>
                    <span class="num" style="font-size:24px;margin-left:6px">
                        @if (abs($estimate['high'] - $estimate['low']) < 0.05)
                            {{ C::formatVma($estimate['mid']) }}
                        @else
                            {{ C::formatVma($estimate['low']) }} – {{ C::formatVma($estimate['high']) }}
                        @endif
                    </span>
                    <span class="meta">km/h</span>
                </div>
                <button type="button" class="btn btn-primary btn-sm" wire:click="useEstimate" wire:loading.attr="disabled" wire:target="useEstimate">
                    Utiliser {{ C::formatVma($estimate['mid']) }} km/h
                </button>
            </div>
        @endif
    @endif
    <div class="meta" style="font-size:12px;margin-top:10px">{{ $model }}. Une estimation : seul un test de terrain donne ta VMA réelle.</div>
</div>
