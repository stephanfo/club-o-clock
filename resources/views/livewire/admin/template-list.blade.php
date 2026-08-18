{{-- Écran admin « Modèles de génération » — porté de screen-admin.jsx AdminModeles (§4.8).
     Master/détail : liste actifs + archivés à gauche, panneau du modèle sélectionné à droite
     (relance / archive / regénération / édition). Admin uniquement. --}}
@php
    $days = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
@endphp
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar ─── --}}
    <div class="dk-topbar">
        <div class="f1">
            <div class="dsp" style="font-size:24px">Modèles de génération</div>
            <div class="meta">{{ $active->count() }} actifs · {{ $archived->count() }} archivés · génération en lot à l'enregistrement</div>
        </div>
        <a href="{{ route('admin.templates.create') }}" class="btn btn-primary btn-sm" wire:navigate>
            <x-icon name="plus" :size="15" /> Nouveau modèle
        </a>
    </div>

    <div class="dk-body">
        <div class="tpl-list-grid">
            {{-- ═══ Colonne gauche : actifs + archivés ═══ --}}
            <div style="display:flex;flex-direction:column;gap:18px">
                <div>
                    <div class="eyebrow" style="margin-bottom:10px">Modèles actifs ({{ $active->count() }})</div>
                    @if ($active->isEmpty())
                        <div class="card card-pad meta" style="font-size:12px;text-align:center;border-style:dashed">Aucun modèle actif. Crée ton premier modèle.</div>
                    @else
                        <div style="display:flex;flex-direction:column;gap:8px">
                            @foreach ($active as $mo)
                                <button type="button" wire:click="select({{ $mo->id }})"
                                    class="card card-pad flex ac g10{{ $selected && $selected->id === $mo->id ? ' is-selected' : '' }}" style="text-align:left;width:100%;cursor:pointer">
                                    @if ($mo->discipline)<span class="dot dot-{{ $mo->discipline->colorClass() }}"></span>@endif
                                    <div class="f1" style="min-width:0">
                                        <div style="font-weight:700;font-size:13px">{{ $mo->label }}</div>
                                        <div class="meta" style="font-size:11px">{{ $days[$mo->day_of_week] }} · {{ \Illuminate\Support\Str::substr($mo->start_time_of_day, 0, 5) }}</div>
                                    </div>
                                    <span class="meta" style="font-size:11px">{{ $mo->sessions_count }} séances</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Archivés : réutilisables, réactivables --}}
                <div>
                    <div class="eyebrow flex ac g6" style="margin-bottom:10px;white-space:nowrap"><x-icon name="layers" :size="13" style="color:var(--fg-muted)" /> Archivés ({{ $archived->count() }})</div>
                    @if ($archived->isEmpty())
                        <div class="card card-pad meta" style="font-size:12px;text-align:center;border-style:dashed;color:var(--fg-muted)">Aucun modèle archivé.</div>
                    @else
                        <div style="display:flex;flex-direction:column;gap:8px">
                            @foreach ($archived as $mo)
                                <div class="card card-pad" style="background:var(--bg-alt)">
                                    <div class="flex ac g10">
                                        @if ($mo->discipline)<span class="dot dot-{{ $mo->discipline->colorClass() }}" style="opacity:.5"></span>@endif
                                        <div class="f1" style="min-width:0">
                                            <div style="font-weight:700;font-size:13px;color:var(--fg-soft)">{{ $mo->label }}</div>
                                            <div class="meta" style="font-size:11px">{{ $days[$mo->day_of_week] }} · {{ \Illuminate\Support\Str::substr($mo->start_time_of_day, 0, 5) }}</div>
                                        </div>
                                        <span class="chip chip-sm chip-line" style="flex:0 0 auto">archivé</span>
                                    </div>
                                    <div class="flex ac jb" style="margin-top:10px">
                                        <span class="meta" style="font-size:11px">{{ $mo->sessions_count }} séances générées</span>
                                        <div class="flex g6" style="flex:0 0 auto">
                                            <button type="button" wire:click="reactivate({{ $mo->id }})" class="btn btn-primary btn-sm" style="padding:5px 10px">
                                                <x-icon name="rotate-ccw" :size="13" /> Réactiver
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    <div class="meta" style="font-size:11.5px;margin-top:8px;line-height:1.5">Un modèle archivé arrête de générer de nouvelles séances. <b>Réactiver</b> le remet en service. Les séances déjà générées restent intactes.</div>
                </div>
            </div>

            {{-- ═══ Panneau détail du modèle sélectionné ═══ --}}
            @if ($selected)
                <div class="card card-pad">
                    <div class="flex ac jb"><div class="dsp-7" style="font-size:19px">{{ $selected->label }}</div><span class="meta" style="font-size:12px">{{ $selected->sessions_count }} générées</span></div>
                    <hr class="divider" style="margin:12px 0">
                    <div class="tpl-row2">
                        <div><label class="field-label">Jour</label><div class="input">{{ $days[$selected->day_of_week] }}</div></div>
                        <div><label class="field-label">Créneau</label><div class="input">{{ \Illuminate\Support\Str::substr($selected->start_time_of_day, 0, 5) }} · {{ $selected->duration_min }} min</div></div>
                        <div><label class="field-label">Date début</label><div class="input">{{ $selected->generation_start_date->locale('fr')->isoFormat('D MMM YYYY') }}</div></div>
                        <div><label class="field-label">Date fin</label><div class="input">{{ $selected->generation_end_date->locale('fr')->isoFormat('D MMM YYYY') }}</div></div>
                    </div>

                    @if ($selected->kind === 'training' && $selected->quotaTag)
                        <label class="field-label" style="margin-top:12px">Tag de quota</label>
                        <div class="input flex ac jb"><span class="chip chip-sm chip-tag">{{ $selected->quotaTag->label }}</span></div>
                    @endif

                    <label class="field-label" style="margin-top:12px">Coachs par défaut</label>
                    <div class="input flex g4 wrap">
                        @forelse ($selected->defaultCoaches as $c)<span class="chip chip-sm chip-ink">{{ $c->fullName() }}</span>@empty<span class="meta" style="font-size:12px">Aucun</span>@endforelse
                    </div>

                    <x-banner kind="warn"><div>« Générer & enregistrer » crée <b>des séances indépendantes</b> sur la plage du modèle. Les modifications futures ne propagent <b>pas</b> aux séances déjà générées.</div></x-banner>

                    <button type="button" wire:click="openRelaunch({{ $selected->id }})" class="btn btn-ghost btn-block" style="margin-top:12px;border-color:var(--brand-200);color:var(--brand-700)">
                        <x-icon name="repeat" :size="15" /> Relancer / prolonger la saison
                    </button>
                    <div class="meta" style="font-size:11.5px;margin-top:6px;line-height:1.5">Conserve le modèle et génère de <b>nouvelles</b> séances sur une autre plage (nouvelle saison, prolongation) — sans toucher aux existantes.</div>

                    <div class="flex g8" style="margin-top:14px;justify-content:flex-end">
                        <button type="button" wire:click="archive({{ $selected->id }})" wire:confirm="Archiver ce modèle ? Il ne générera plus de séances." class="btn btn-ghost btn-sm">
                            <x-icon name="layers" :size="14" /> Archiver
                        </button>
                        <a href="{{ route('admin.templates.edit', $selected) }}" class="btn btn-ghost btn-sm" wire:navigate>
                            <x-icon name="edit" :size="14" /> Éditer
                        </a>
                        <button type="button" wire:click="generate({{ $selected->id }})" wire:loading.attr="disabled" wire:target="generate" class="btn btn-primary btn-sm">Générer &amp; enregistrer</button>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Modale de relance / prolongation (porté de RelanceModal §4.8) ── --}}
    @if ($relaunchTpl)
        <x-dialog title="Relancer le modèle" :sub="$relaunchTpl->label.' · '.\Illuminate\Support\Str::lower($days[$relaunchTpl->day_of_week])" :width="480" close="closeRelaunch">
            <div style="font-size:13.5px;line-height:1.55;margin-bottom:14px">Régénère ce modèle sur une <b>nouvelle plage de dates</b> (nouvelle saison ou prolongation). Les séances déjà générées <b>ne sont pas modifiées</b>.</div>

            <div class="tpl-row2">
                <div><label class="field-label">Nouvelle date de début</label><div class="ifield"><input class="ifield-input" type="date" wire:model.live="relaunchStart"></div></div>
                <div><label class="field-label">Nouvelle date de fin</label><div class="ifield"><input class="ifield-input" type="date" wire:model.live="relaunchEnd"></div></div>
            </div>

            <div class="card card-pad" style="border-color:var(--brand-200);background:var(--brand-50);margin-top:14px">
                <div class="flex ac" style="gap:10px">
                    <span class="num" style="font-size:42px;line-height:1;color:var(--brand-700);flex:0 0 auto">{{ $this->relaunchCount }}</span>
                    <div><div style="font-weight:700;font-size:15px">nouvelles séances</div><div class="meta" style="font-size:12.5px;margin-top:2px">s'ajoutent aux existantes</div></div>
                </div>
            </div>

            <x-banner kind="warn"><div>Ces séances seront créées et s'ajouteront aux existantes. Les séances déjà générées (saisons précédentes) <b>restent intactes</b>.</div></x-banner>

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="closeRelaunch">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="relaunch" wire:loading.attr="disabled" wire:target="relaunch" @disabled($this->relaunchCount === 0)>
                    <x-icon name="repeat" :size="15" /> Relancer · {{ $this->relaunchCount }} séances
                </button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
