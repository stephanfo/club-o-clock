{{-- Écran admin « Pages d'information ». Liste actives + archivées, actions
     épingler / archiver / restaurer / supprimer. Admin uniquement. --}}
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar ─── --}}
    <div class="dk-topbar">
        <div class="f1">
            <div class="dsp" style="font-size:24px">Pages d'information</div>
            <div class="meta">{{ $active->count() }} active{{ $active->count() > 1 ? 's' : '' }} · {{ $archived->count() }} archivée{{ $archived->count() > 1 ? 's' : '' }}</div>
        </div>
        <a href="{{ route('admin.infos.create') }}" class="btn btn-primary btn-sm" wire:navigate>
            <x-icon name="plus" :size="15" /> Nouvelle page
        </a>
    </div>

    <div class="dk-body">
        <div style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:18px">
            {{-- ═══ Actives ═══ --}}
            <div>
                <div class="eyebrow" style="margin-bottom:10px">Actives ({{ $active->count() }})</div>
                @if ($active->isEmpty())
                    <div class="card card-pad meta" style="text-align:center;border-style:dashed">Aucune page. Crée ta première page d'info.</div>
                @else
                    <div style="display:flex;flex-direction:column;gap:8px">
                        @foreach ($active as $page)
                            <div class="card card-pad">
                                <div class="flex ac g10">
                                    {{-- Réordonnancement manuel : ↑/↓ échangent la position avec la voisine.
                                         Dimensions réduites en ligne (cf. override .weeknav .iconbtn). --}}
                                    <div class="flex" style="flex-direction:column;gap:2px">
                                        <button type="button" wire:click="moveUp({{ $page->id }})" class="iconbtn"
                                            style="width:28px;height:24px" @disabled($loop->first) title="Monter" aria-label="Monter">
                                            <x-icon name="chevron-up" :size="16" />
                                        </button>
                                        <button type="button" wire:click="moveDown({{ $page->id }})" class="iconbtn"
                                            style="width:28px;height:24px" @disabled($loop->last) title="Descendre" aria-label="Descendre">
                                            <x-icon name="chevron-down" :size="16" />
                                        </button>
                                    </div>
                                    <div class="f1" style="min-width:0">
                                        <div class="flex ac g6 wrap">
                                            @if ($page->pinned)<span class="chip chip-sm"><x-icon name="star" :size="12" /> Épinglée</span>@endif
                                            <span style="font-weight:700;font-size:14px">{{ $page->title }}</span>
                                        </div>
                                        <div class="meta" style="font-size:11px;margin-top:2px">{{ $page->visibilityLabel() }}</div>
                                    </div>
                                    <div class="flex ac g6">
                                        <button type="button" wire:click="togglePin({{ $page->id }})" class="btn btn-ghost btn-sm"
                                            title="{{ $page->pinned ? 'Désépingler' : 'Épingler en bannière' }}">
                                            <x-icon name="star" :size="14" /> {{ $page->pinned ? 'Désépingler' : 'Épingler' }}
                                        </button>
                                        <a href="{{ route('admin.infos.edit', $page) }}" class="btn btn-ghost btn-sm" wire:navigate>
                                            <x-icon name="edit" :size="14" /> Modifier
                                        </a>
                                        <button type="button" wire:click="archive({{ $page->id }})" class="btn btn-ghost btn-sm">
                                            <x-icon name="archive" :size="14" /> Archiver
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- ═══ Archivées ═══ --}}
            <div>
                <div class="eyebrow flex ac g6" style="margin-bottom:10px"><x-icon name="layers" :size="13" style="color:var(--fg-muted)" /> Archivées ({{ $archived->count() }})</div>
                @if ($archived->isEmpty())
                    <div class="card card-pad meta" style="font-size:12px;text-align:center;border-style:dashed;color:var(--fg-muted)">Aucune page archivée.</div>
                @else
                    <div style="display:flex;flex-direction:column;gap:8px">
                        @foreach ($archived as $page)
                            <div class="card card-pad" style="background:var(--bg-alt)">
                                <div class="flex ac g10">
                                    <div class="f1" style="min-width:0">
                                        <div style="font-weight:700;font-size:13px;color:var(--fg-soft)">{{ $page->title }}</div>
                                        <div class="meta" style="font-size:11px">{{ $page->visibilityLabel() }}</div>
                                    </div>
                                    <div class="flex ac g6">
                                        <button type="button" wire:click="restore({{ $page->id }})" class="btn btn-ghost btn-sm">
                                            <x-icon name="rotate-ccw" :size="14" /> Restaurer
                                        </button>
                                        <button type="button" wire:click="confirmDelete({{ $page->id }})" class="btn btn-ghost btn-sm">
                                            <x-icon name="trash" :size="14" /> Supprimer
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Modale : suppression définitive (hard delete irréversible §4.13) — cohérence avec member-show ── --}}
    @if ($deleting)
        <x-dialog title="Supprimer définitivement" :danger="true" :width="460" close="cancelDelete">
            <x-banner kind="danger">
                <div>La page <b>{{ $deleting->title }}</b> va être <b>définitivement supprimée</b>. Cette action est <b>irréversible</b>.</div>
            </x-banner>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="cancelDelete">Annuler</button>
                <button type="button" class="btn btn-danger-solid" wire:click="delete({{ $deleting->id }})"
                    wire:loading.attr="disabled" wire:target="delete">Supprimer définitivement</button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
