{{-- Création / édition d'un modèle de génération — porté de screen-modele-create.jsx (§4.8).
     Un modèle = une récurrence (jour ISO + créneau + plage) qui génère N Session INDÉPENDANTES
     à l'enregistrement. Les éditions futures ne re-propagent pas. Admin uniquement. --}}
@php
    $edit = $template?->exists;
    // Jours ISO 1..7 → libellés (DAYS du proto, ré-indexés sur l'ISO).
    $days = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'];
    $daysFull = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
    $n = $this->occurrenceCount;
    $selectedCoaches = $coaches->whereIn('id', $coach_ids);
@endphp
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar (porté de AdminModeleCreate dk-topbar) ─── --}}
    <div class="dk-topbar">
        <a href="{{ route('admin.templates') }}" class="btn btn-ghost btn-sm" wire:navigate>
            <x-icon name="chevron-left" :size="15" /> Modèles
        </a>
        <div class="f1">
            <div class="dsp" style="font-size:24px">{{ $edit ? 'Éditer le modèle' : 'Nouveau modèle' }}</div>
            <div class="meta">{{ $edit ? $label.' · génération récurrente' : 'Génère des séances en lot · une récurrence hebdomadaire' }}</div>
        </div>
        <a href="{{ route('admin.templates') }}" class="btn btn-ghost btn-sm" wire:navigate>Annuler</a>
        <button type="submit" form="template-form" class="btn btn-primary btn-sm">
            <x-icon name="layers" :size="15" /> {{ $edit ? 'Enregistrer le modèle' : 'Générer & enregistrer' }}
        </button>
    </div>

    <div class="dk-body">
        <form id="template-form" wire:submit="save" class="tpl-grid">
            @if ($errors->any())
                <x-banner kind="danger" style="grid-column:1/-1;margin-bottom:var(--space-2)">
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </x-banner>
            @endif

            {{-- ═══ Colonne principale ═══ --}}
            <div class="tpl-main">
                {{-- Discipline — chips à puces --}}
                <div>
                    <label class="field-label">Discipline</label>
                    <div class="flex g6 wrap">
                        @foreach ($disciplines as $d)
                            <button type="button" wire:click="$set('discipline_id', {{ $d->id }})"
                                class="chip{{ $discipline_id === $d->id ? ' is-active' : '' }}">
                                <span class="dot dot-{{ $d->colorClass() }}"></span> {{ $d->label }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="field-label">Titre du modèle</label>
                    <div class="ifield"><input class="ifield-input" type="text" wire:model.blur="label" placeholder="Ex. Natation seuil"></div>
                </div>

                {{-- Récurrence — le cœur d'un modèle --}}
                <div class="card card-pad">
                    <div class="flex ac jb" style="margin-bottom:14px">
                        <div class="sect-title flex ac g8"><x-icon name="repeat" :size="16" style="color:var(--brand-700)" /> Récurrence</div>
                        <span class="meta" style="font-size:12px">hebdomadaire</span>
                    </div>
                    <label class="field-label">Jour de la semaine</label>
                    <div class="flex g6 wrap" style="margin-bottom:16px">
                        @foreach ($days as $i => $d)
                            <button type="button" wire:click="setDay({{ $i }})"
                                class="chip{{ $day_of_week === $i ? ' is-active' : '' }}" style="min-width:50px;justify-content:center">{{ $d }}</button>
                        @endforeach
                    </div>
                    <div class="tpl-row2">
                        <div>
                            <label class="field-label">Heure de début</label>
                            <div class="ifield"><input class="ifield-input" type="time" wire:model.blur="start_time_of_day"></div>
                        </div>
                        <div>
                            <label class="field-label">Durée (min)</label>
                            <div class="ifield"><input class="ifield-input" type="number" min="1" wire:model.blur="duration_min"></div>
                        </div>
                        <div>
                            <label class="field-label">Date de début</label>
                            <div class="ifield"><input class="ifield-input" type="date" wire:model.live="generation_start_date"></div>
                        </div>
                        <div>
                            <label class="field-label">Date de fin</label>
                            <div class="ifield"><input class="ifield-input" type="date" wire:model.live="generation_end_date"></div>
                        </div>
                    </div>
                </div>

                {{-- Lieu + capacité --}}
                <div class="tpl-row2">
                    <div>
                        <label class="field-label">Lieu</label>
                        <select class="input" wire:model="location_id">
                            <option value="">—</option>
                            @foreach ($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="field-label">Capacité</label>
                        <div class="ifield"><input class="ifield-input" type="number" min="1" wire:model.blur="capacity" placeholder="16"></div>
                    </div>
                </div>

                {{-- Tag de quota fair-share (training only — porté chips) --}}
                @if ($kind === 'training')
                    <div>
                        <label class="field-label">Tag de quota fair-share</label>
                        <div class="flex g6 wrap">
                            @foreach ($quotaTags as $tag)
                                <button type="button" wire:click="$set('quota_tag_id', {{ $tag->id }})"
                                    class="chip chip-tag{{ $quota_tag_id === $tag->id ? ' is-active' : '' }}">{{ $tag->label }}</button>
                            @endforeach
                            <button type="button" wire:click="$set('quota_tag_id', null)"
                                class="chip{{ $quota_tag_id === null ? ' is-active' : '' }}" style="font-style:italic">aucun</button>
                        </div>
                    </div>
                @endif

                {{-- Catégories ciblées — chips toggle --}}
                <div>
                    <label class="field-label">Catégories ciblées</label>
                    <div class="flex g6 wrap">
                        @foreach ($categories as $c)
                            <button type="button" wire:click="toggleCategory({{ $c->id }})"
                                class="chip{{ in_array($c->id, $category_ids) ? ' is-active' : '' }}">{{ $c->label }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ═══ Colonne droite ═══ --}}
            <div class="tpl-side">
                {{-- Aperçu génération --}}
                <div class="card card-pad" style="border-color:var(--brand-200);background:var(--brand-50)">
                    <div class="eyebrow" style="color:var(--brand-700);margin-bottom:10px">À l'enregistrement</div>
                    <div style="margin-bottom:6px">
                        <div class="flex" style="align-items:baseline;gap:8px;flex-wrap:nowrap">
                            <span class="num" style="font-size:46px;line-height:1;color:var(--brand-700);flex:0 0 auto">{{ $n }}</span>
                            <span style="font-weight:700;font-size:var(--text-base);white-space:nowrap">séances</span>
                        </div>
                        <div class="meta" style="font-size:13px;margin-top:5px">{{ $n ? mb_strtolower($daysFull[$day_of_week]).' · '.$start_time_of_day : 'aucune dans la plage' }}</div>
                    </div>
                </div>

                <x-banner kind="warn"><div><b>{{ $n }} séances indépendantes</b> seront créées. Modifier le modèle <b>plus tard ne propage pas</b> aux séances déjà générées — chacune s'édite séparément.</div></x-banner>

                @if ($this->pastCount > 0)
                    <x-banner kind="danger"><div><b>{{ $this->pastCount }} dans le passé.</b> La plage commence avant aujourd'hui : ces séances seront créées mais avec inscriptions déjà fermées. Ajuste la date de début si ce n'est pas voulu.</div></x-banner>
                @endif

                {{-- Coachs par défaut — chips toggle (préaffectation §4.8) --}}
                <div class="card card-pad">
                    <div class="eyebrow" style="margin-bottom:10px">Coachs par défaut</div>
                    <div class="flex g6 wrap">
                        @forelse ($selectedCoaches as $c)
                            <button type="button" wire:click="toggleCoach({{ $c->id }})" class="chip chip-ink flex ac g4">
                                {{ $c->fullName() }} <x-icon name="x" :size="12" />
                            </button>
                        @empty
                            <span class="meta" style="font-size:12px">Aucun coach pré-affecté.</span>
                        @endforelse
                    </div>
                    @php($availableCoaches = $coaches->whereNotIn('id', $coach_ids))
                    @if ($availableCoaches->isNotEmpty())
                        <div class="flex g6 wrap" style="margin-top:10px">
                            @foreach ($availableCoaches as $c)
                                <button type="button" wire:click="toggleCoach({{ $c->id }})" class="chip chip-line" style="border-style:dashed">
                                    <x-icon name="plus" :size="12" /> {{ $c->fullName() }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                    <div class="meta" style="font-size:12px;margin-top:10px">Pré-inscrits comme encadrants sur chaque séance générée. Modifiable séance par séance.</div>
                </div>
            </div>
        </form>
    </div>
</div>
