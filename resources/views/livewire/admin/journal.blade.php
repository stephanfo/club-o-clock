{{-- Page « Journaux » (PRD §4.18.5 — J6.7) — porté de screen-admin.jsx AdminJournaux.
     Audit/Activity/Tous + filtres (acteur, action, cible, séance, période) + drawer détail + export
     XLSX. Admin uniquement. Anonymisation déjà portée par le tombstone (acteur → « Compte supprimé »). --}}
@php
    $periodLabels = ['30d' => '30 jours', '90d' => '90 jours', 'season' => 'Saison', 'all' => 'Tout'];
@endphp
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar ─── --}}
    <div class="dk-topbar">
        <div class="f1">
            <div class="dsp" style="font-size:24px">Journaux</div>
            <div class="meta">audit + activity · {{ $periodLabel }}</div>
        </div>
        <button type="button" wire:click="export" class="btn btn-ghost btn-sm" wire:loading.attr="disabled" wire:target="export">
            <x-icon name="download" :size="15" /> XLSX
        </button>
    </div>

    <div class="dk-body">
        {{-- ═══ Filtres (§4.18.5) ═══ --}}
        <div class="flex g8 ac wrap" style="margin-bottom:14px">
            <x-segmented :items="[['v'=>'all','l'=>'Tous'],['v'=>'audit','l'=>'Audit'],['v'=>'activity','l'=>'Activity']]"
                         :value="$source" wire-method="setSource" />

            {{-- Acteur — autocomplete (id exact). --}}
            <div style="position:relative">
                @if ($actorId)
                    <span class="chip is-active" style="gap:6px">
                        <x-icon name="user" :size="13" /> {{ $actorLabel }}
                        <button type="button" wire:click="clearActor" style="border:none;background:none;cursor:pointer;color:inherit;font-size:15px;line-height:1">×</button>
                    </span>
                @else
                    <div class="input flex ac g8" style="min-width:190px;padding:6px 10px">
                        <x-icon name="search" :size="15" style="color:var(--fg-muted)" />
                        <input type="text" wire:model.live.debounce.350ms="actorQuery" placeholder="Acteur…"
                            style="border:none;background:none;outline:none;width:100%;font:inherit;color:inherit">
                    </div>
                    @if (count($actorSuggestions))
                        <div class="card card-pad" style="position:absolute;left:0;top:calc(100% + 6px);z-index:20;min-width:210px;display:flex;flex-direction:column;gap:2px">
                            @foreach ($actorSuggestions as $s)
                                <button type="button" wire:click="selectActor({{ $s['id'] }})" class="chip" style="justify-content:flex-start">{{ $s['label'] }}</button>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>

            {{-- Action — multi-select cochable. --}}
            <div x-data="{ open: false }" style="position:relative">
                <button type="button" x-on:click="open = !open" class="chip{{ count($actions) ? ' is-active' : '' }}">
                    action{{ count($actions) ? ' · '.count($actions) : '' }} ▾
                </button>
                <div x-show="open" x-on:click.outside="open = false" x-cloak
                    class="card card-pad" style="position:absolute;left:0;top:calc(100% + 6px);z-index:20;min-width:250px;max-height:320px;overflow:auto;display:flex;flex-direction:column;gap:2px">
                    @if ($source !== 'activity')
                        <div class="eyebrow" style="margin:2px 0 4px">Audit</div>
                        @forelse ($actionOptions['audit'] as $a)
                            <button type="button" wire:click="toggleAction('{{ $a }}')" class="chip{{ in_array($a, $actions, true) ? ' is-active' : '' }}" style="justify-content:flex-start;gap:6px">
                                @if (in_array($a, $actions, true))<x-icon name="check" :size="13" />@endif <span class="mono" style="font-size:12px">{{ $a }}</span>
                            </button>
                        @empty
                            <div class="meta" style="font-size:12px">Aucune</div>
                        @endforelse
                    @endif
                    @if ($source !== 'audit')
                        <div class="eyebrow" style="margin:8px 0 4px">Activity</div>
                        @forelse ($actionOptions['activity'] as $a)
                            <button type="button" wire:click="toggleAction('{{ $a }}')" class="chip{{ in_array($a, $actions, true) ? ' is-active' : '' }}" style="justify-content:flex-start;gap:6px">
                                @if (in_array($a, $actions, true))<x-icon name="check" :size="13" />@endif <span class="mono" style="font-size:12px">{{ $a }}</span>
                            </button>
                        @empty
                            <div class="meta" style="font-size:12px">Aucune</div>
                        @endforelse
                    @endif
                </div>
            </div>

            {{-- Type de cible — AuditLog uniquement (l'Activity n'a pas de cible polymorphe). --}}
            @if ($source !== 'activity' && count($targetTypeOptions))
                <div x-data="{ open: false }" style="position:relative">
                    <button type="button" x-on:click="open = !open" class="chip{{ $targetType ? ' is-active' : '' }}">
                        cible{{ $targetType ? ' · '.($targetTypeOptions[$targetType] ?? '') : '' }} ▾
                    </button>
                    <div x-show="open" x-on:click.outside="open = false" x-cloak
                        class="card card-pad" style="position:absolute;left:0;top:calc(100% + 6px);z-index:20;min-width:160px;display:flex;flex-direction:column;gap:2px">
                        <button type="button" wire:click="setTargetType('')" x-on:click="open = false" class="chip{{ $targetType === null ? ' is-active' : '' }}" style="justify-content:flex-start">Toutes</button>
                        @foreach ($targetTypeOptions as $short)
                            <button type="button" wire:click="setTargetType('{{ $short }}')" x-on:click="open = false" class="chip{{ ($targetTypeOptions[$targetType] ?? null) === $short ? ' is-active' : '' }}" style="justify-content:flex-start">{{ $short }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Séance — autocomplete. --}}
            <div style="position:relative">
                @if ($sessionId)
                    <span class="chip is-active" style="gap:6px">
                        <x-icon name="calendar" :size="13" /> {{ \Illuminate\Support\Str::limit($sessionLabel, 28) }}
                        <button type="button" wire:click="clearSession" style="border:none;background:none;cursor:pointer;color:inherit;font-size:15px;line-height:1">×</button>
                    </span>
                @else
                    <div class="input flex ac g8" style="min-width:170px;padding:6px 10px">
                        <x-icon name="search" :size="15" style="color:var(--fg-muted)" />
                        <input type="text" wire:model.live.debounce.350ms="sessionQuery" placeholder="Séance…"
                            style="border:none;background:none;outline:none;width:100%;font:inherit;color:inherit">
                    </div>
                    @if (count($sessionSuggestions))
                        <div class="card card-pad" style="position:absolute;left:0;top:calc(100% + 6px);z-index:20;min-width:240px;display:flex;flex-direction:column;gap:2px">
                            @foreach ($sessionSuggestions as $s)
                                <button type="button" wire:click="selectSession({{ $s['id'] }})" class="chip" style="justify-content:flex-start">{{ $s['label'] }}</button>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>

            {{-- Période. --}}
            <div x-data="{ open: false }" style="position:relative">
                <button type="button" x-on:click="open = !open" class="chip{{ $period !== '30d' ? ' is-active' : '' }}">{{ $periodLabels[$period] }} ▾</button>
                <div x-show="open" x-on:click.outside="open = false" x-cloak
                    class="card card-pad" style="position:absolute;left:0;top:calc(100% + 6px);z-index:20;min-width:140px;display:flex;flex-direction:column;gap:2px">
                    @foreach ($periodLabels as $v => $l)
                        <button type="button" wire:click="setPeriod('{{ $v }}')" x-on:click="open = false" class="chip{{ $period === $v ? ' is-active' : '' }}" style="justify-content:flex-start">{{ $l }}</button>
                    @endforeach
                </div>
            </div>

            <div class="f1"></div>
            @if ($source !== 'all' || $actorId || $sessionId || count($actions) || $targetType || $period !== '30d')
                <button type="button" wire:click="resetFilters" class="chip">réinitialiser</button>
            @endif
        </div>

        {{-- ═══ Table ═══ --}}
        <div class="card" style="overflow:hidden">
            @if (empty($rows))
                <div class="meta tc" style="padding:32px">Aucune entrée ne correspond à ces filtres.</div>
            @else
                <div style="padding:0 14px">
                    <table class="tbl">
                        <thead><tr><th>Date</th><th></th><th>Acteur</th><th>Action</th><th>Cible</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr wire:key="{{ $r['source'] }}-{{ $r['id'] }}" wire:click="showDetail('{{ $r['source'] }}', {{ $r['id'] }})" style="cursor:pointer">
                                    <td class="meta mono" style="font-size:12px;white-space:nowrap">{{ $r['at']->copy()->setTimezone($tz)->format('d/m H:i') }}</td>
                                    <td><span class="chip chip-sm {{ $r['source'] === 'audit' ? 'chip-pink' : 'chip-line' }}">{{ $r['source'] }}</span></td>
                                    <td>{{ $r['actor'] }}@if ($r['actor_role'])<span class="muted"> ({{ $r['actor_role'] }})</span>@endif</td>
                                    <td class="mono" style="font-size:12px">{{ $r['action'] }}</td>
                                    <td>
                                        {{ $r['target'] }}
                                        @if ($r['session'])<div class="meta" style="font-size:11px">{{ \Illuminate\Support\Str::limit($r['session'], 36) }}</div>@endif
                                    </td>
                                    <td style="text-align:right"><x-icon name="chevron-right" :size="16" class="muted" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="meta tc" style="padding:12px">
                    {{ count($rows) }} / {{ $total }}
                    @if (count($rows) < $total)
                        · <button type="button" wire:click="loadMore" class="underline-link">charger plus</button>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- ═══ Dialog détail (chevron) ═══ --}}
    @if ($detail)
        <x-dialog title="Détail · journal {{ $detail['source'] }}" :width="440" close="closeDetail">
            <div style="display:flex;flex-direction:column;gap:10px">
                {{-- Date --}}
                <div class="flex jb g12" style="border-bottom:1px solid var(--divider);padding-bottom:8px">
                    <span class="meta" style="font-size:12px;flex-shrink:0">Date</span>
                    <span style="text-align:right;min-width:0;word-break:break-word">{{ $detail['at']->copy()->setTimezone($tz)->format('d/m/Y H:i') }}</span>
                </div>

                {{-- Acteur — lié vers sa fiche membre si le compte existe encore. --}}
                <div class="flex jb g12" style="border-bottom:1px solid var(--divider);padding-bottom:8px">
                    <span class="meta" style="font-size:12px;flex-shrink:0">Acteur</span>
                    <span style="text-align:right;min-width:0;word-break:break-word">
                        @if ($detail['actor_id'])
                            <a href="{{ route('admin.members.show', $detail['actor_id']) }}" wire:navigate class="underline-link">{{ $detail['actor'] }}</a>
                        @else
                            {{ $detail['actor'] }}
                        @endif
                        @if ($detail['actor_role'])<span class="meta" style="font-size:12px"> ({{ $detail['actor_role'] }})</span>@endif
                    </span>
                </div>

                {{-- Action --}}
                <div class="flex jb g12" style="border-bottom:1px solid var(--divider);padding-bottom:8px">
                    <span class="meta" style="font-size:12px;flex-shrink:0">Action</span>
                    <span style="text-align:right;min-width:0;word-break:break-word;font-family:var(--font-mono);font-size:13px">{{ $detail['action'] }}</span>
                </div>

                {{-- Cible — lien vers la fiche membre quand la cible est un utilisateur résolu. --}}
                <div class="flex jb g12" style="border-bottom:1px solid var(--divider);padding-bottom:8px">
                    <span class="meta" style="font-size:12px;flex-shrink:0">Cible</span>
                    <span style="text-align:right;min-width:0;word-break:break-word">
                        @if ($detail['target_user_id'])
                            <a href="{{ route('admin.members.show', $detail['target_user_id']) }}" wire:navigate class="underline-link">{{ $detail['target'] }}</a>
                        @else
                            {{ $detail['target'] }}
                        @endif
                    </span>
                </div>

                {{-- Séance liée — lien vers la fiche séance si elle existe encore. --}}
                <div class="flex jb g12" style="border-bottom:1px solid var(--divider);padding-bottom:8px">
                    <span class="meta" style="font-size:12px;flex-shrink:0">Séance liée</span>
                    <span style="text-align:right;min-width:0;word-break:break-word">
                        @if ($detail['session_id'])
                            <a href="{{ route('sessions.show', $detail['session_id']) }}" wire:navigate class="underline-link">{{ $detail['session'] }}</a>@if ($detail['session_at'])<span class="meta" style="font-size:12px"> · {{ $detail['session_at']->copy()->setTimezone($tz)->format('d/m/Y') }}</span>@endif
                        @else
                            {{ $detail['session'] ?: '—' }}
                        @endif
                    </span>
                </div>

                @if ($detail['source'] === 'audit')
                    <div class="flex jb g12" style="border-bottom:1px solid var(--divider);padding-bottom:8px">
                        <span class="meta" style="font-size:12px;flex-shrink:0">Motif</span>
                        <span style="text-align:right;min-width:0;word-break:break-word">{{ $detail['motif'] ?: '—' }}</span>
                    </div>
                @endif
            </div>
            <x-slot:footer>
                <button type="button" wire:click="closeDetail" class="btn btn-ghost btn-sm">Fermer</button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
