{{-- Carte d'un débrief — porté de screen-debriefs.jsx <DebriefCard>.
     Reçoit : $d (Debrief), $label (auteur selon viewer §4.9.4), $mine, $archived, $tz, $isAdmin. --}}
@php
    $edited = $d->updated_at && $d->created_at && $d->updated_at->ne($d->created_at);
    $dateLine = $d->created_at?->copy()->setTimezone($tz)->locale('fr')->isoFormat('D MMM YYYY');
@endphp
<div class="card card-pad debrief-card {{ $archived ? 'debrief-archived' : '' }}">
    <div class="debrief-head">
        <x-avatar :name="$label" />
        <div class="f1" style="min-width:0">
            <div class="flex ac g6 wrap">
                <span style="font-weight:700;font-size:14.5px">{{ $label }}</span>
                @if ($mine)<span class="chip chip-sm chip-pink">toi</span>@endif
                @if ($archived)<span class="chip chip-sm chip-line flex ac g4"><x-icon name="archive" :size="12" /> archivé</span>@endif
            </div>
            <div class="meta">{{ $dateLine }}{{ $edited ? ' · modifié' : '' }}</div>
        </div>
    </div>
    <div style="margin-top:12px"><div class="db-prose">{!! \App\Support\Markup::render($d->content_markdown) !!}</div></div>

    @if ($mine || $isAdmin)
        <div class="debrief-foot">
            @if ($archived)
                <span class="meta" style="font-size:12px;margin-right:auto">Archivé par {{ $d->archiver?->fullName() ?? 'admin' }}</span>
                {{-- Réactivation = DebriefPolicy::archive, admin STRICT (l'auteur n'est pas exempté,
                     contrairement à update()). Inatteignable aujourd'hui — la section « Archivés »
                     n'est rendue qu'aux admins — mais la garde doit être juste ici aussi. --}}
                @if ($isAdmin)
                <button type="button" class="btn btn-ghost btn-sm" wire:click="restoreDebrief({{ $d->id }})">
                    <x-icon name="rotate-ccw" :size="14" /> Réactiver
                </button>
                @endif
            @else
                <span class="meta" style="font-size:12px;margin-right:auto">{{ $mine ? 'Visible par tous les membres du club' : 'Vue admin' }}</span>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="openDebrief({{ $d->id }})">
                    <x-icon name="edit" :size="14" /> Éditer
                </button>
                @if ($isAdmin && ! $mine)
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="confirmArchiveDebrief({{ $d->id }})">
                        <x-icon name="archive" :size="14" /> Archiver
                    </button>
                @endif
            @endif
        </div>
    @endif
</div>
