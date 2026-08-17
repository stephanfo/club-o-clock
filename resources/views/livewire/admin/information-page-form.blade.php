{{-- Création / édition d'une page d'information. Éditeur WYSIWYG partagé (x-wysiwyg),
     visibilité par niveau cumulatif, épinglage bannière. Admin uniquement. --}}
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar ─── --}}
    <div class="dk-topbar">
        <a href="{{ route('admin.infos') }}" class="iconbtn" wire:navigate aria-label="Retour">
            <x-icon name="chevron-left" />
        </a>
        <div class="f1">
            <div class="dsp" style="font-size:24px">{{ $isEdit ? 'Modifier la page' : 'Nouvelle page d’info' }}</div>
            <div class="meta">Bon d'achat, code partenaire, info générale.</div>
        </div>
        <button type="button" wire:click="save" class="btn btn-primary btn-sm">
            <x-icon name="check" :size="15" /> Enregistrer
        </button>
    </div>

    <div class="dk-body">
        <form wire:submit.prevent="save" style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:18px">
            {{-- Titre --}}
            <div>
                <label class="field-label" for="ip-title">Titre</label>
                <div class="ifield">
                    <input id="ip-title" class="ifield-input" type="text" wire:model="title" maxlength="160"
                           placeholder="ex. Bon d'achat chez notre partenaire">
                </div>
                @error('title')<div class="meta" style="color:var(--danger);margin-top:4px">{{ $message }}</div>@enderror
            </div>

            {{-- Visibilité (niveau cumulatif) --}}
            <div>
                <label class="field-label">Visible par</label>
                <x-segmented wireSet="visibility" :value="$visibility" :items="[
                    ['v' => 'all', 'l' => 'Tous'],
                    ['v' => 'coach', 'l' => 'Coachs + admin'],
                    ['v' => 'admin', 'l' => 'Admin'],
                ]" />
                @error('visibility')<div class="meta" style="color:var(--danger);margin-top:4px">{{ $message }}</div>@enderror
            </div>

            {{-- Contenu WYSIWYG --}}
            <div>
                <div class="sect-head"><span class="sect-title">Contenu</span></div>
                <x-wysiwyg model="content_markdown" :markdown="$content_markdown"
                           placeholder="Détails, code, conditions… (mise en forme avec la barre d'outils)" />
            </div>

            {{-- Épinglage bannière d'accueil --}}
            <label class="flex ac g10" style="cursor:pointer">
                <input type="checkbox" wire:model="pinned">
                <span>
                    <span style="font-weight:700">Épingler en bannière d'accueil</span>
                    <span class="meta" style="display:block">Affichée en haut de l'accueil des membres qui peuvent la voir.</span>
                </span>
            </label>
        </form>
    </div>
</div>
