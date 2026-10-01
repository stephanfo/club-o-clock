{{-- Carte d'un débrief — porté de screen-debriefs.jsx <DebriefCard>.
     Reçoit : $d (Debrief), $label (auteur selon viewer §4.9.4), $mine, $archived, $tz, $isAdmin,
     $reactionLabels (réacteurs selon viewer §4.9.4). --}}
@php
    // Réactions (#101) : « Toi, Léa M. et 3 autres », du plus récent au plus ancien.
    $reactions = $d->reactions->sortByDesc('created_at')->values();
    $nbReactions = $reactions->count();
    $iLike = auth()->check() && $reactions->contains('user_id', auth()->id());
    $noms = $reactions->map(fn ($r) => $r->user_id === auth()->id() ? 'Toi' : ($reactionLabels[$r->user_id] ?? 'Membre'))
        ->sortBy(fn ($nom) => $nom === 'Toi' ? 0 : 1)->values();
    $affiches = $noms->take(3);
    $reste = $nbReactions - $affiches->count();
    $qui = $reste > 0
        ? $affiches->implode(', ').' et '.$reste.' autre'.($reste > 1 ? 's' : '')
        : ($affiches->count() > 1 ? $affiches->slice(0, -1)->implode(', ').' et '.$affiches->last() : $affiches->first());
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

    {{-- Réactions (#101) : masquées avec le débrief archivé, conservées pour sa réactivation. --}}
    @if (! $archived && (! $mine || $nbReactions > 0))
        <div class="debrief-react">
            @if (! $mine)
                <button type="button" class="react-btn {{ $iLike ? 'on' : '' }}" aria-pressed="{{ $iLike ? 'true' : 'false' }}"
                        wire:click="toggleReaction({{ $d->id }})" wire:loading.attr="disabled" wire:target="toggleReaction({{ $d->id }})">
                    <x-icon name="heart" :size="15" /> J'aime{{ $nbReactions > 0 ? ' · '.$nbReactions : '' }}
                </button>
            @else
                <span class="react-count"><x-icon name="heart" :size="15" /> {{ $nbReactions }}</span>
            @endif
            @if ($nbReactions > 0)
                <span class="meta react-who">{{ $qui }}</span>
            @endif
        </div>
    @endif

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
