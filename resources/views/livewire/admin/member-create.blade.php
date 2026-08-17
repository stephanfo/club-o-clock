{{-- « Ajouter un adhérent » (PRD §4.17.1) — porté de screen-adherent-create.jsx.
     Catégorie principale dérivée de la date de naissance ; mineurs → bloc parent garant P1/P2 ;
     aperçu vivant à droite. Admin uniquement. --}}
@php
    $fullName = trim($this->first_name.' '.$this->last_name);
    $roleChips = array_keys(array_filter($this->roles));
    $tint = $this->roles['coach'] ? 'tint-swim' : ($this->roles['admin'] ? 'tint-bike' : 'tint-run');
@endphp
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar ─── --}}
    <div class="dk-topbar">
        <a href="{{ route('admin.members') }}" class="btn btn-ghost btn-sm" wire:navigate><x-icon name="chevron-left" :size="15" /> Adhérents</a>
        <div class="f1">
            <div class="dsp" style="font-size:24px">Ajouter un adhérent</div>
            <div class="meta">Création manuelle · un adhérent à la fois</div>
        </div>
        <a href="{{ route('admin.members') }}" class="btn btn-ghost btn-sm" wire:navigate>Annuler</a>
        <button type="button" wire:click="create" class="btn btn-primary btn-sm{{ $this->ready ? '' : ' is-disabled' }}" @disabled(! $this->ready)>
            <x-icon name="user-plus" :size="15" /> Créer l'adhérent
        </button>
    </div>

    <div class="dk-body">
        <div class="mc-create-grid">
            {{-- ── Colonne principale ── --}}
            <div style="display:flex;flex-direction:column;gap:18px">

                {{-- Identité --}}
                <div class="card card-pad">
                    <div class="flex ac jb"><span class="sect-title">Identité</span><x-icon name="user" :size="16" class="muted" /></div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:12px">
                        <div>
                            <label class="field-label">Prénom</label>
                            <input class="input" wire:model.live.debounce.300ms="first_name" placeholder="Camille" autofocus>
                            @error('first_name') <div class="meta" style="color:var(--danger);margin-top:4px">{{ $message }}</div> @enderror
                        </div>
                        <div>
                            <label class="field-label">Nom</label>
                            <input class="input" wire:model.live.debounce.300ms="last_name" placeholder="Vincent">
                            @error('last_name') <div class="meta" style="color:var(--danger);margin-top:4px">{{ $message }}</div> @enderror
                        </div>
                    </div>
                </div>

                {{-- Date de naissance --}}
                <div class="card card-pad">
                    <div class="flex ac jb"><span class="sect-title">Date de naissance</span><x-icon name="calendar-days" :size="16" class="muted" /></div>
                    <div class="flex ac g10" style="margin-top:12px">
                        <input type="date" class="input f1" wire:model.live="dob" max="{{ now()->toDateString() }}">
                        @if ($this->age !== null)<span class="chip chip-line" style="flex:0 0 auto">{{ $this->age }} ans</span>@endif
                    </div>
                    @error('dob') <div class="meta" style="color:var(--danger);margin-top:8px">{{ $message }}</div> @enderror
                    @if ($primaryCat)
                        <div class="meta flex ac g6" style="margin-top:10px"><x-icon name="check" :size="13" style="color:var(--brand-700)" /> Catégorie principale dérivée : <b style="color:var(--ink)">{{ $primaryCat->label }}</b>@if ($this->isMinor) · mineur·e @endif</div>
                    @else
                        <div class="meta" style="margin-top:10px">La catégorie principale est calculée automatiquement à partir de l'âge (année sportive sept → août).</div>
                    @endif
                </div>

                {{-- Email & connexion --}}
                <div class="card card-pad">
                    <div class="flex ac jb"><span class="sect-title">Email & connexion</span><x-icon name="mail" :size="16" class="muted" /></div>
                    <input class="input" style="margin-top:12px;opacity:{{ $this->isP1 ? '0.5' : '1' }}" wire:model.live.debounce.300ms="email"
                        placeholder="prenom.nom@mail.fr" type="email" @disabled($this->isP1)>
                    @error('email') <div class="meta" style="color:var(--danger);margin-top:4px">{{ $message }}</div> @enderror
                    @if ($this->isP1)
                        <x-banner kind="info" style="margin-top:10px"><div>Mineur <b>P1</b> sans email — aucun compte propre. L'accès est géré par le <b>parent garant</b> ci-dessous.</div></x-banner>
                    @else
                        <div class="meta" style="margin-top:10px">Un <b>lien magique de confirmation</b> sera envoyé à cette adresse — l'adhérent active son accès et se connecte sans mot de passe.</div>
                    @endif
                </div>

                {{-- Parent garant — mineurs uniquement --}}
                @if ($this->isMinor)
                    <div class="card card-pad" style="border-color:var(--info-200)">
                        <div class="flex ac jb"><span class="sect-title">Parent garant</span><x-icon name="shield" :size="16" style="color:var(--info)" /></div>
                        <label class="field-label" style="margin-top:12px">Phase de tutelle</label>
                        <x-segmented :items="[['v' => 'P1', 'l' => 'P1 · géré par le parent'], ['v' => 'P2', 'l' => 'P2 · compte + parent']]" :value="$phase" wire-set="phase" />
                        <label class="field-label" style="margin-top:14px">Rattacher à</label>
                        <div class="input" style="padding:0;display:flex;align-items:center">
                            <x-icon name="user" :size="15" style="color:var(--fg-muted);margin:0 0 0 13px;flex:0 0 auto" />
                            <select class="mc-select" wire:model.live="guardian_id">
                                <option value="">Choisir un parent garant…</option>
                                @foreach ($guardians as $g)
                                    <option value="{{ $g->id }}">{{ $g->fullName() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="meta" style="font-size:12.5px;margin-top:10px;line-height:1.5">
                            @if ($phase === 'P1')
                                En <b>P1</b>, {{ $first_name ?: "l'enfant" }} n'a pas de compte : le parent gère ses inscriptions depuis le sien.
                            @else
                                En <b>P2</b>, {{ $first_name ?: "l'enfant" }} a son propre compte (email requis) et le parent reste co-pilote.
                            @endif
                        </div>
                    </div>
                @endif

                {{-- Catégories --}}
                <div class="card card-pad">
                    <div class="flex ac jb"><span class="sect-title">Catégories</span><span class="meta">M:N · surclassements manuels</span></div>
                    <div style="margin-top:12px">
                        <label class="field-label">Principale · dérivée de l'âge</label>
                        @if ($primaryCat)
                            <div class="flex ac g8"><span class="chip chip-ink">{{ $primaryCat->label }}</span><span class="chip chip-sm chip-line flex ac g4"><x-icon name="lock" :size="11" /> auto</span></div>
                        @else
                            <span class="meta">Renseigne la date de naissance pour la dériver.</span>
                        @endif
                    </div>
                    <div style="margin-top:14px">
                        <label class="field-label">Surclassements ({{ count($surclassements) }})</label>
                        <div class="flex ac g6 wrap">
                            @if ($chosenCats->isEmpty() && ! $addingCat)<span class="meta">Aucun surclassement.</span>@endif
                            @foreach ($chosenCats as $c)
                                <span class="chip chip-green flex ac g4">+ {{ $c->label }}<button type="button" wire:click="removeSurclassement({{ $c->id }})" style="display:inline-flex;border:none;background:none;padding:0;margin:0 -2px 0 1px;cursor:pointer;color:inherit;opacity:0.7" aria-label="Retirer {{ $c->label }}"><x-icon name="x" :size="12" /></button></span>
                            @endforeach
                            @if (! $addingCat && $primaryCat && $availableCats->isNotEmpty())
                                <button type="button" class="chip chip-line flex ac g4" wire:click="$set('addingCat', true)"><x-icon name="plus" :size="12" /> Ajouter</button>
                            @endif
                        </div>
                        @if ($addingCat)
                            <div class="card card-soft" style="margin-top:8px;padding:10px">
                                <div class="meta" style="margin-bottom:6px">Choisir une catégorie à ajouter :</div>
                                <div class="flex g6 wrap">
                                    @foreach ($availableCats as $c)
                                        <button type="button" class="chip chip-line" wire:click="addSurclassement({{ $c->id }})">{{ $c->label }}</button>
                                    @endforeach
                                    <button type="button" class="chip" wire:click="$set('addingCat', false)">Annuler</button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Rôles --}}
                <div class="card card-pad">
                    <div class="flex ac jb"><span class="sect-title">Rôles</span><span class="meta">cumulables</span></div>
                    <div style="margin-top:6px">
                        @php $roleDefs = [['k' => 'athlete', 'l' => 'Athlète', 'sub' => "S'inscrit aux séances, file d'attente, quotas."], ['k' => 'coach', 'l' => 'Coach', 'sub' => 'Encadre, crée des séances, gère les waitlists.'], ['k' => 'admin', 'l' => 'Admin', 'sub' => 'Adhérents, modèles, journaux, paramètres.']]; @endphp
                        @foreach ($roleDefs as $i => $r)
                            <div class="flex ac g12" style="padding:12px 0;{{ $i < 2 ? 'border-bottom:1px solid var(--divider)' : '' }}">
                                <div class="f1"><div style="font-weight:700;font-size:14px">{{ $r['l'] }}</div><div class="meta" style="font-size:12px">{{ $r['sub'] }}</div></div>
                                <x-toggle :on="$roles[$r['k']]" wire:click="toggleRole('{{ $r['k'] }}')" />
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- Qualifications — pertinent surtout pour les coachs --}}
                @if ($roles['coach'])
                    <div class="card card-pad">
                        <div class="flex ac jb"><span class="sect-title">Qualifications</span><span class="meta">expiration réglable ensuite</span></div>
                        <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
                            @if ($chosenQuals->isEmpty())<span class="meta">Aucune qualification enregistrée.</span>@endif
                            @foreach ($chosenQuals as $q)
                                <div class="flex ac g10" style="padding:10px 12px;background:var(--bg-alt);border-radius:var(--radius-md)">
                                    <x-icon name="award" :size="18" style="color:var(--fg-soft);flex:0 0 auto" />
                                    <div class="f1" style="min-width:0"><div style="font-weight:700;font-size:14px">{{ $q->label }}</div><span class="chip chip-sm chip-line" style="margin-top:4px">sans expiration</span></div>
                                    <button type="button" class="iconbtn" wire:click="removeQualification({{ $q->id }})" style="flex:0 0 auto" aria-label="Retirer la qualification"><x-icon name="x" :size="16" style="color:var(--fg-muted)" /></button>
                                </div>
                            @endforeach
                        </div>
                        @if (! $addingQual)
                            <button type="button" class="btn btn-ghost btn-sm" style="margin-top:10px" wire:click="$set('addingQual', true)" @disabled($availableQuals->isEmpty())><x-icon name="plus" :size="14" /> Ajouter une qualification</button>
                        @else
                            <div class="card card-soft" style="margin-top:10px;padding:10px">
                                <div class="meta" style="margin-bottom:6px">Sélectionner une qualification :</div>
                                <div class="flex g6 wrap">
                                    @foreach ($availableQuals as $q)
                                        <button type="button" class="chip chip-line" wire:click="addQualification({{ $q->id }})">{{ $q->label }}</button>
                                    @endforeach
                                    <button type="button" class="chip" wire:click="$set('addingQual', false)">Annuler</button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- ── Colonne droite : aperçu vivant ── --}}
            <div style="display:flex;flex-direction:column;gap:18px;position:sticky;top:0">
                <div class="card card-pad" style="position:relative;overflow:hidden">
                    <span style="position:absolute;left:0;top:0;bottom:0;width:5px;background:var(--brand)"></span>
                    <div class="eyebrow" style="margin-bottom:12px;padding-left:6px">Aperçu de la fiche</div>
                    <div class="flex ac g14" style="padding-left:6px">
                        <x-avatar :name="$fullName ?: '? ?'" size="lg" :tint="$tint" />
                        <div class="f1" style="min-width:0">
                            <div class="dsp" style="font-size:22px;line-height:1.05">{{ $fullName ?: '' }}@if (! $fullName)<span style="color:var(--fg-muted)">Nom à venir</span>@endif</div>
                            <div class="flex ac g6 wrap" style="margin-top:8px">
                                <span class="chip chip-green">● Accès {{ $this->isP1 ? 'parental' : 'à activer' }}</span>
                                @if ($primaryCat)<span class="chip chip-ink">{{ $primaryCat->label }}</span>@endif
                                @foreach ($chosenCats as $c)<span class="chip chip-line">+ {{ $c->label }}</span>@endforeach
                            </div>
                            <div class="flex ac g6 wrap" style="margin-top:8px">
                                @foreach ($roleChips as $r)<span class="chip chip-sm chip-line">{{ $r }}</span>@endforeach
                            </div>
                        </div>
                    </div>
                    @if ($this->isMinor && $guardian_id)
                        @php $g = $guardians->firstWhere('id', $guardian_id); @endphp
                        @if ($g)<div class="meta flex ac g4" style="margin-top:12px;padding-left:6px"><x-icon name="shield" :size="13" style="color:var(--info)" /> Parent garant · {{ $g->fullName() }} <span class="chip chip-sm chip-blue">{{ $phase }}</span></div>@endif
                    @endif
                </div>

                <div class="card card-pad card-soft">
                    <div class="eyebrow" style="margin-bottom:10px">À la création</div>
                    <div style="display:flex;flex-direction:column;gap:12px">
                        <x-conseq-row :icon="$this->isP1 ? 'shield' : 'mail'" label="Accès" tone="green">
                            @if ($this->isP1)
                                <span>Aucun compte créé — <b>{{ optional($guardians->firstWhere('id', $guardian_id))->fullName() ?? 'le parent garant' }}</b> gère les inscriptions.</span>
                            @else
                                <span>Lien magique de confirmation envoyé à <b>{{ $email ?: "l'email saisi" }}</b>.</span>
                            @endif
                        </x-conseq-row>
                        <x-conseq-row icon="calendar" label="Catégorie">
                            @if ($primaryCat)
                                <span>Affecté·e à <b>{{ $primaryCat->label }}</b>@if (count($surclassements)) + {{ count($surclassements) }} surclassement{{ count($surclassements) > 1 ? 's' : '' }}@endif.</span>
                            @else
                                <span>En attente de la date de naissance.</span>
                            @endif
                        </x-conseq-row>
                        <x-conseq-row icon="file-text" label="Journal">
                            <span>Action <span class="mono">create_member</span> tracée dans les journaux du bureau.</span>
                        </x-conseq-row>
                    </div>
                </div>

                @unless ($this->ready)
                    <x-banner kind="info"><div>Renseigne <b>prénom</b>, <b>nom</b>, <b>date de naissance</b>@unless ($this->isP1) et <b>email</b>@endunless pour activer la création.</div></x-banner>
                @endunless
            </div>
        </div>
    </div>
</div>
