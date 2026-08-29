{{-- Dialog modal — porté de ui.jsx <Dialog>.
     title, sub, danger, width. close = expression de fermeture injectée dans wire:click/x-on:click
     (ex. close="$set('showDialog', false)" ou close="open=false"). Slot 'footer' optionnel.
     footStack : empile les actions du pied sur toute la largeur, au lieu de les ranger en ligne à
     droite. Réservé aux pieds portant TROIS choix ou plus — une rangée les serre au point de sortir
     du cadre, et trois actions aux conséquences différentes se lisent mieux l'une sous l'autre. --}}
@props(['title' => '', 'sub' => null, 'danger' => false, 'width' => null, 'close' => null, 'footStack' => false])
<div class="scrim" @if ($close) wire:click="{{ $close }}" @endif>
    <div {{ $attributes->merge(['class' => 'dialog']) }} @if ($width) style="max-width:{{ $width }}px" @endif
         wire:click.stop x-on:click.stop>
        <div class="dialog-head{{ $danger ? ' danger' : '' }}">
            <div class="flex ac jb g8">
                <div class="dialog-title{{ $danger ? ' danger' : '' }}">{{ $title }}</div>
                <button type="button" class="iconbtn" aria-label="Fermer" @if ($close) wire:click="{{ $close }}" @endif><x-icon name="x" /></button>
            </div>
            @if ($sub)<div class="dialog-sub">{{ $sub }}</div>@endif
        </div>
        <div class="dialog-body">{{ $slot }}</div>
        @if (isset($footer))<div class="dialog-foot{{ $footStack ? ' dialog-foot-stack' : '' }}">{{ $footer }}</div>@endif
    </div>
</div>
