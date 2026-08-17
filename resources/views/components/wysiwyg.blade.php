{{-- Éditeur WYSIWYG (îlot TipTap) — PRD §4.12.1. Périmètre figé : gras/ital/barré, listes,
     liens, h2/h3, citation. Réutilisé pour le contenu de séance, l'agenda et les débriefs.
     Toolbar + classes portées de screen-debriefs.jsx (.wys-toolbar/.wys-btn/.wys-area).

     Props :
       model       — nom de la propriété Livewire à synchroniser (markdown).
       markdown    — markdown brut initial (TipTap le parse via tiptap-markdown).
       placeholder — texte d'invite (data-ph sur la zone vide).
       minHeight   — hauteur minimale de la zone d'édition (px).

     wire:ignore sur la zone TipTap (et elle seule) : Livewire ne morphe pas le DOM que ProseMirror
     gère → pas de « mismatched transaction ». La toolbar reste réactive (Alpine). --}}
@props(['model', 'markdown' => '', 'placeholder' => '', 'minHeight' => 160])
<div class="card wysiwyg" style="overflow:hidden"
     x-data="wysiwyg({ model: @js($model), markdown: @js((string) $markdown), placeholder: @js($placeholder), minHeight: {{ (int) $minHeight }} })">
    <div class="wys-toolbar" role="toolbar" aria-label="Mise en forme du texte">
        <button type="button" class="wys-btn" :class="{ 'is-on': active.bold }" title="Gras (Ctrl+B)" aria-label="Gras"
                x-on:mousedown.prevent x-on:click="cmd('bold')"><x-icon name="bold" /></button>
        <button type="button" class="wys-btn" :class="{ 'is-on': active.italic }" title="Italique (Ctrl+I)" aria-label="Italique"
                x-on:mousedown.prevent x-on:click="cmd('italic')"><x-icon name="italic" /></button>
        <button type="button" class="wys-btn" :class="{ 'is-on': active.strike }" title="Barré" aria-label="Barré"
                x-on:mousedown.prevent x-on:click="cmd('strike')"><x-icon name="strikethrough" /></button>
        <span class="wys-sep"></span>
        <button type="button" class="wys-btn" :class="{ 'is-on': active.h2 }" title="Titre niveau 2" aria-label="Titre niveau 2"
                x-on:mousedown.prevent x-on:click="cmd('h2')">H2</button>
        <button type="button" class="wys-btn" :class="{ 'is-on': active.h3 }" title="Titre niveau 3" aria-label="Titre niveau 3"
                x-on:mousedown.prevent x-on:click="cmd('h3')">H3</button>
        <span class="wys-sep"></span>
        <button type="button" class="wys-btn" :class="{ 'is-on': active.ul }" title="Liste à puces" aria-label="Liste à puces"
                x-on:mousedown.prevent x-on:click="cmd('ul')"><x-icon name="list" /></button>
        <button type="button" class="wys-btn" :class="{ 'is-on': active.ol }" title="Liste numérotée" aria-label="Liste numérotée"
                x-on:mousedown.prevent x-on:click="cmd('ol')"><x-icon name="list-ordered" /></button>
        <button type="button" class="wys-btn" :class="{ 'is-on': active.blockquote }" title="Citation" aria-label="Citation"
                x-on:mousedown.prevent x-on:click="cmd('blockquote')"><x-icon name="quote" /></button>
        <span class="wys-sep"></span>
        <button type="button" class="wys-btn" :class="{ 'is-on': active.link }" title="Lien (Ctrl+K)" aria-label="Lien"
                x-on:mousedown.prevent x-on:click="setLink()"><x-icon name="link" /></button>
    </div>
    <div wire:ignore x-ref="editor"></div>
</div>
