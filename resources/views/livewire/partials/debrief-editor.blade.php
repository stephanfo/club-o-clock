{{-- Éditeur de débrief (modale) — porté de screen-debriefs.jsx <DebriefEditorModal>.
     Embarque l'îlot WYSIWYG <x-wysiwyg> ; rendu uniquement quand $debriefOpen.
     Le markdown est synchronisé dans $debriefMarkdown puis sanitisé serveur (§4.12.1). --}}
@if ($debriefOpen)
    <div class="scrim" wire:key="debrief-editor">
        <div class="dialog debrief-dialog" wire:click.stop x-on:click.stop style="padding:0">
            <div class="debrief-editor">
                <div class="debrief-editor-head">
                    <div class="f1">
                        <div class="dsp-7" style="font-size:19px">{{ $debriefId ? 'Modifier le débrief' : 'Rédiger mon débrief' }}</div>
                        <div class="meta" style="font-size:12px">{{ $session->title }} · {{ $session->start_at->copy()->setTimezone($tz)->locale('fr')->isoFormat('ddd D MMM') }}</div>
                    </div>
                    <button type="button" class="iconbtn" wire:click="closeDebrief" aria-label="Fermer"><x-icon name="x" /></button>
                </div>

                <div class="debrief-editor-body">
                    <x-wysiwyg model="debriefMarkdown" :markdown="$debriefInitialMarkdown"
                               placeholder="Raconte ta course : ton ressenti, tes chronos, un mot pour le club…" />
                </div>

                <div class="debrief-editor-foot">
                    <span class="wys-hint"><x-icon name="info" :size="13" /> Pas de photo ici — elles vivent dans l'album.</span>
                    <button type="button" class="btn btn-ghost" wire:click="closeDebrief">Annuler</button>
                    <button type="button" class="btn btn-pink" wire:click="saveDebrief">{{ $debriefId ? 'Enregistrer' : 'Publier' }}</button>
                </div>
            </div>
        </div>
    </div>
@endif

{{-- Confirmation d'archivage (admin, soft-delete §4.12.5) --}}
@if ($debriefArchiveId)
    <x-dialog title="Archiver ce débrief ?" :width="420" close="cancelArchiveDebrief">
        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="cancelArchiveDebrief">Annuler</button>
            <button type="button" class="btn btn-dark" wire:click="archiveDebrief({{ $debriefArchiveId }})">
                <x-icon name="archive" :size="15" /> Archiver
            </button>
        </x-slot:footer>
        <x-banner kind="warn"><div>Le débrief <b>disparaît de la liste publique</b> mais n'est <b>pas supprimé</b> : tu pourras le réactiver à tout moment depuis cette fiche.</div></x-banner>
    </x-dialog>
@endif
