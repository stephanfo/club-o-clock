{{-- Fiche détail d'un adhérent (PRD §4.1.3, §4.11.3) — porté de screen-adherent.jsx.
     Édition action-immédiate par section. Carte Accès & sécurité = suppression RGPD voie admin (J6.3) ;
     carte Tutelle = autonomisation P1→P2 + rupture P2→P3 (J7.7, §4.2). --}}
@php
    $u = $user;
    $age = $u->dob ? \App\Support\AgeCategory::seasonAge($u->dob) : null;
    $suspended = $u->athlete_access_suspended;
    // Cycle de vie suppression (§4.3) : demande en cours (tampon) vs éligible (J+7 écoulé).
    $pending = $u->isDeletionPending();
    $eligible = $u->isDeletionEligible();
    $buffer = \App\Models\User::DELETION_BUFFER_DAYS;
    // $dayN = jours réels écoulés depuis la demande (affichage) ; $progress = plafonné pour la barre.
    $dayN = $u->deletion_requested_at ? (int) $u->deletion_requested_at->startOfDay()->diffInDays(\Illuminate\Support\Carbon::now()->startOfDay()) : 0;
    $progress = min($buffer, $dayN);
    $remaining = max(0, $buffer - $dayN);
    $accent = $eligible ? 'var(--danger)' : (($pending || $suspended) ? 'var(--warning)' : 'var(--brand)');
    $roleChips = $u->roles ?? [];
    $tint = in_array('coach', $roleChips) ? 'tint-swim' : 'tint-run';

    $regStatus = [
        'participating' => ['l' => 'Inscrit', 'cls' => 'chip-green'],
        'waitlist' => ['l' => 'File d\'attente', 'cls' => 'chip-warn'],
        'cancelled' => ['l' => 'Annulé', 'cls' => 'chip-line'],
    ];
    $qualLabels = ['valid' => 'Valide', 'soon' => 'Expire bientôt', 'expired' => 'Expirée', 'none' => 'Sans expiration'];
@endphp
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar ─── --}}
    <div class="dk-topbar">
        <a href="{{ route('admin.members') }}" class="btn btn-ghost btn-sm" wire:navigate><x-icon name="chevron-left" :size="15" /> Adhérents</a>
        <span class="meta" style="white-space:nowrap">Fiche adhérent</span>
        <span class="mlauto" style="flex:1"></span>
        <span class="role-badge" style="white-space:nowrap">Vue admin</span>
    </div>

    <div class="dk-body">
        <div style="max-width:1080px;margin:0 auto;display:flex;flex-direction:column;gap:18px">

            {{-- ═══ Header ═══ --}}
            <div class="card card-pad" style="position:relative;overflow:hidden">
                <span style="position:absolute;left:0;top:0;bottom:0;width:5px;background:{{ $accent }}"></span>
                <div class="flex ac g16 wrap" style="padding-left:6px">
                    <x-avatar :name="$u->fullName()" size="xl" :tint="$tint" />
                    <div class="f1" style="min-width:0">
                        <div class="dsp" style="font-size:30px;line-height:1.02">{{ $u->fullName() }}</div>
                        <div class="flex ac g6 wrap" style="margin-top:10px">
                            @if ($eligible)
                                <span class="chip chip-danger flex ac g4"><x-icon name="alert-triangle" :size="12" /> Éligible suppression</span>
                            @elseif ($pending)
                                <span class="chip chip-warn flex ac g4"><x-icon name="trash" :size="12" /> Suppression en cours</span>
                            @elseif ($suspended)
                                <span class="chip chip-warn">○ Accès suspendu</span>
                            @else
                                <span class="chip chip-green">● Accès actif</span>
                            @endif
                            <span style="width:1px;height:16px;background:var(--divider);margin:0 2px"></span>
                            @if ($primary)<span class="chip chip-ink">{{ $primary->label }}</span>@endif
                            @foreach ($surclassements as $c)<span class="chip chip-line">+ {{ $c->label }}</span>@endforeach
                            @foreach ($roleChips as $r)<span class="chip chip-line">{{ $r }}</span>@endforeach
                        </div>
                        <div class="flex ac g14 wrap" style="margin-top:10px">
                            <span class="meta flex ac g4" style="white-space:nowrap"><x-icon name="calendar" :size="13" /> Créé le {{ $u->created_at?->translatedFormat('j M Y') }}</span>
                            @if ($u->guardian)
                                <span class="meta flex ac g4" style="white-space:nowrap"><x-icon name="shield" :size="13" style="color:var(--info)" /> Parent garant · {{ $u->guardian->fullName() }} <span class="chip chip-sm chip-blue">{{ $u->is_minor ? 'P1/P2' : '—' }}</span></span>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- ═══ Onglets ═══ --}}
            <x-tabs :items="[['v' => 'profil', 'l' => 'Profil & accès'], ['v' => 'histo', 'l' => 'Historique d\'inscriptions', 'badge' => $future->count() + $past->count()]]" :value="$tab" wire-set="tab" />

            @if ($tab === 'profil')
                <div class="mc-show-grid">
                    {{-- ── Colonne gauche : sections éditables ── --}}
                    <div style="display:flex;flex-direction:column;gap:18px">

                        {{-- Email & connexion --}}
                        <div class="card card-pad">
                            <div class="flex ac jb"><span class="sect-title">Email & connexion</span><x-icon name="mail" :size="16" class="muted" /></div>
                            @if ($u->email)
                                <div class="input flex ac jb" style="margin-top:12px"><span>{{ $u->email }}</span></div>
                                @if ($u->email_verified_at)
                                    <div class="meta flex ac g4" style="margin-top:8px;color:var(--brand-700)"><x-icon name="check" :size="13" /> Email confirmé · connexion par lien magique active</div>
                                @else
                                    <x-banner kind="info" style="margin-top:8px"><div>Email non confirmé · en attente d'activation.</div></x-banner>
                                @endif
                            @else
                                <x-banner kind="info" style="margin-top:12px"><div>Mineur <b>P1</b> sans email — accès géré par le parent garant. Aucune connexion directe.</div></x-banner>
                            @endif
                        </div>

                        {{-- Tutelle (§4.2) — autonomisation P1→P2 / rupture P2→P3 --}}
                        @if ($u->guardian)
                            <div class="card card-pad">
                                <div class="flex ac jb"><span class="sect-title">Tutelle</span><x-icon name="shield" :size="16" class="muted" /></div>
                                <div class="meta flex ac g4" style="margin-top:12px"><x-icon name="shield" :size="13" style="color:var(--info)" /> Parent garant · <b>{{ $u->guardian->fullName() }}</b></div>

                                @if (! $u->email && ! $u->is_minor)
                                    {{-- Devenu majeur en gardant son garant (MemberService::updateDob) :
                                         GuardianshipService::invite refuse — l'autonomisation ne vaut que
                                         pour un mineur. Le geste attendu est la rupture de tutelle. --}}
                                    <x-banner kind="warn" style="margin-top:12px"><div>Ce pupille est <b>majeur</b> : l'ouverture d'un compte autonome ne s'applique plus. Romps le lien de tutelle pour le rendre indépendant.</div></x-banner>
                                @elseif (! $u->email)
                                    {{-- P1 → P2 : ouverture du compte autonome (§4.2.1) --}}
                                    <div class="meta" style="margin-top:12px;line-height:1.5">Ouvre le compte autonome de l'enfant : saisis son email — l'email doit appartenir à l'enfant. Une invitation d'activation lui sera envoyée ; le lien de tutelle est conservé.</div>
                                    <div class="ifield" style="margin-top:10px"><x-icon name="mail" :size="15" class="muted" /><input class="ifield-input" type="email" wire:model="wardEmail" placeholder="email de l'enfant"></div>
                                    @error('wardEmail')<div class="meta" style="margin-top:6px;color:var(--danger)">{{ $message }}</div>@enderror
                                    <button type="button" class="btn btn-primary btn-block" style="margin-top:10px" wire:click="inviteWard">
                                        <x-icon name="user" :size="15" /> Inviter à activer son compte
                                    </button>
                                @else
                                    <x-banner kind="info" style="margin-top:12px"><div>Compte autonome (<b>P2</b>) — l'enfant se connecte et s'inscrit lui-même ; le parent garant reçoit les notifs en parallèle et peut agir.</div></x-banner>
                                @endif

                                {{-- P2 → P3 : rupture du lien de tutelle (§4.2.2).
                                     Masqué pour un P1 MINEUR : sever() le refuse (il resterait sans
                                     garant ET sans accès) — le geste attendu est l'autonomisation,
                                     offerte juste au-dessus. Un pupille majeur sans email garde le
                                     bouton : invite() ne s'applique plus à lui, la rupture est sa
                                     seule sortie (cf. le bandeau « majeur » ci-dessus). --}}
                                @if ($u->email || ! $u->is_minor)
                                <hr class="divider" style="margin:14px 0">
                                @if ($confirmingSever)
                                    <x-banner kind="warn"><div>Rompre le lien de tutelle : le parent ne recevra plus les notifs, ne verra plus l'historique et ne pourra plus agir. Action manuelle, tracée. Confirmer ?</div></x-banner>
                                    <div class="flex g8" style="margin-top:10px">
                                        <button type="button" class="btn btn-ghost f1" wire:click="$set('confirmingSever', false)">Annuler</button>
                                        <button type="button" class="btn btn-danger f1" wire:click="severGuardianship"><x-icon name="x" :size="15" /> Rompre la tutelle</button>
                                    </div>
                                @else
                                    <button type="button" class="btn btn-ghost btn-block" wire:click="$set('confirmingSever', true)">
                                        <x-icon name="x" :size="15" /> Rompre le lien de tutelle (P3)
                                    </button>
                                @endif
                                @endif
                            </div>
                        @elseif ($u->is_minor && ! $u->anonymized_at)
                            {{-- Mineur SANS garant (autonome / orphelin de tutelle) : rattachement admin (§4.2). --}}
                            <div class="card card-pad">
                                <div class="flex ac jb"><span class="sect-title">Tutelle</span><x-icon name="shield" :size="16" class="muted" /></div>
                                <x-banner kind="warn" style="margin-top:12px"><div>Mineur <b>sans parent garant</b>. Rattache un adulte actif pour rétablir la tutelle (P1 sans email, P2 sinon).</div></x-banner>
                                <select class="input" style="margin-top:10px;width:100%" wire:model="linkGuardianId">
                                    <option value="">— Choisir un garant —</option>
                                    @foreach ($guardianCandidates as $cand)
                                        <option value="{{ $cand->id }}">{{ $cand->fullName() }}{{ $cand->email ? ' · '.$cand->email : '' }}</option>
                                    @endforeach
                                </select>
                                @error('linkGuardianId')<div class="meta" style="margin-top:6px;color:var(--danger)">{{ $message }}</div>@enderror
                                <button type="button" class="btn btn-primary btn-block" style="margin-top:10px" wire:click="linkGuardian">
                                    <x-icon name="shield" :size="15" /> Lier ce garant
                                </button>
                            </div>
                        @endif

                        {{-- Pupilles (§4.2) — enfants dont cet adhérent est garant + rattachement d'un pupille --}}
                        @if ($wards->isNotEmpty() || $canBeGuardian)
                            <div class="card card-pad">
                                <div class="flex ac jb"><span class="sect-title">Pupilles</span><span class="meta">{{ $wards->count() }} enfant{{ $wards->count() > 1 ? 's' : '' }} sous tutelle</span></div>
                                @if ($wards->isNotEmpty())
                                    <div style="margin-top:12px;display:flex;flex-direction:column;gap:10px">
                                        @foreach ($wards as $ward)
                                            <a href="{{ route('admin.members.show', $ward) }}" wire:navigate wire:key="ward-{{ $ward->id }}" class="flex ac g10" style="text-decoration:none;color:inherit">
                                                <x-avatar :name="$ward->fullName()" size="sm" />
                                                <span class="f1" style="min-width:0">
                                                    <span style="font-weight:700;font-size:14px;display:block">{{ $ward->fullName() }}</span>
                                                    <span class="meta" style="font-size:12px">{{ $ward->primaryCategory()?->label ?? 'Sans catégorie' }}</span>
                                                </span>
                                                <span class="chip chip-sm {{ $ward->email === null ? 'chip-tag' : 'chip-blue' }}">{{ $ward->email === null ? 'P1' : 'P2' }}</span>
                                                <x-icon name="chevron-right" :size="15" style="color:var(--fg-muted)" />
                                            </a>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="meta" style="margin-top:12px">Aucun enfant sous tutelle.</div>
                                @endif

                                {{-- Rattacher un pupille (mineur sans garant) à cet adhérent — reparentage admin (§4.2). --}}
                                @if ($canBeGuardian)
                                    @if (! $addingWard)
                                        <button type="button" class="btn btn-ghost btn-sm" style="margin-top:12px" wire:click="$set('addingWard', true)" @disabled($wardCandidates->isEmpty())>
                                            <x-icon name="user-plus" :size="14" /> Ajouter un pupille
                                        </button>
                                        @if ($wardCandidates->isEmpty())
                                            <div class="meta" style="margin-top:6px;font-size:12px">Aucun mineur sans garant à rattacher.</div>
                                        @endif
                                    @else
                                        <div class="card card-soft" style="margin-top:12px;padding:10px">
                                            <div class="meta" style="margin-bottom:6px">Rattacher un mineur sans garant à {{ $u->first_name }} :</div>
                                            <select class="input" style="width:100%" wire:model="linkWardId">
                                                <option value="">— Choisir un enfant —</option>
                                                @foreach ($wardCandidates as $cand)
                                                    <option value="{{ $cand->id }}">{{ $cand->fullName() }}{{ $cand->dob ? ' · '.$cand->dob->age.' ans' : '' }}</option>
                                                @endforeach
                                            </select>
                                            @error('linkWardId')<div class="meta" style="margin-top:6px;color:var(--danger)">{{ $message }}</div>@enderror
                                            <div class="flex g8" style="margin-top:10px">
                                                <button type="button" class="btn btn-primary btn-sm" wire:click="linkWard"><x-icon name="check" :size="14" /> Rattacher</button>
                                                <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('addingWard', false)">Annuler</button>
                                            </div>
                                        </div>
                                    @endif
                                @endif

                                @if ($wards->isNotEmpty())
                                    <div class="meta" style="margin-top:10px;font-size:12px;line-height:1.4">La suppression de ce compte est bloquée tant qu'un pupille P1 lui est rattaché (autonomiser ou reparenter d'abord).</div>
                                @endif
                            </div>
                        @endif

                        {{-- Date de naissance — édition action-immédiate (recalcule la catégorie d'âge) --}}
                        <div class="card card-pad">
                            <div class="flex ac jb">
                                <span class="sect-title">Date de naissance</span>
                                @unless ($editingDob)
                                    <button wire:click="editDob" class="iconbtn" aria-label="Modifier la date de naissance"><x-icon name="edit" :size="15" /></button>
                                @else
                                    <x-icon name="calendar-days" :size="16" class="muted" />
                                @endunless
                            </div>
                            @if ($editingDob)
                                <div class="flex ac g10" style="margin-top:12px">
                                    <input type="date" class="input f1" wire:model="dob" max="{{ now()->toDateString() }}">
                                </div>
                                @error('dob') <div class="meta" style="color:var(--danger);margin-top:8px">{{ $message }}</div> @enderror
                                <div class="flex ac g8" style="margin-top:12px">
                                    <button wire:click="saveDob" class="btn btn-primary btn-sm">Enregistrer</button>
                                    <button wire:click="cancelEditDob" class="btn btn-ghost btn-sm">Annuler</button>
                                </div>
                            @else
                                <div class="flex ac g10" style="margin-top:12px">
                                    <div class="input f1">{{ $u->dob?->translatedFormat('j F Y') ?: '—' }}</div>
                                    @if ($age !== null)<span class="chip chip-line" style="flex:0 0 auto">{{ $age }} ans</span>@endif
                                </div>
                            @endif
                            <div class="meta" style="margin-top:8px">Détermine la catégorie d'âge dérivée pour la saison sportive.</div>
                        </div>

                        {{-- Catégories --}}
                        <div class="card card-pad">
                            <div class="flex ac jb"><span class="sect-title">Catégories</span><span class="meta">M:N · surclassements manuels</span></div>
                            <div style="margin-top:12px">
                                <label class="field-label">Principale · dérivée de l'âge</label>
                                @if ($primary)
                                    <div class="flex ac g8"><span class="chip chip-ink">{{ $primary->label }}</span><span class="chip chip-sm chip-line flex ac g4"><x-icon name="lock" :size="11" /> auto</span></div>
                                @elseif ($u->dob === null)
                                    {{-- Sans date de naissance, la dérivation §4.5 ne peut rien produire :
                                         parler d'âge ici serait trompeur (cas des comptes coach-pur). --}}
                                    <span class="meta">Aucune date de naissance saisie — catégorie non déterminable.</span>
                                @elseif ($derivedCat && $u->hasRole('athlete'))
                                    {{-- Incohérence, mais SEULEMENT pour un athlète : l'âge est couvert par une
                                         catégorie active alors qu'aucun rattachement principal n'existe (pivot
                                         périmé, ou jamais posé). Un coach-pur avec une dob n'a pas vocation à
                                         porter une catégorie — sans la garde de rôle, l'avertissement serait un
                                         faux positif sur tous les encadrants non-athlètes. --}}
                                    <span class="meta" style="color:var(--warning-text)">Catégorie « {{ $derivedCat->label }} » attendue mais non rattachée — réenregistre la date de naissance pour corriger.</span>
                                @elseif (! $u->hasRole('athlete'))
                                    {{-- Non-athlète (coach-pur, parent-pur) : la catégorie d'âge ne s'applique
                                         pas à ce compte, ce n'est ni une anomalie ni un trou de barème. --}}
                                    <span class="meta">Compte sans rôle athlète — la catégorie d'âge ne s'applique pas.</span>
                                @else
                                    <span class="meta">Aucune catégorie active ne couvre cet âge — compte sans catégorie (contacte le catalogue).</span>
                                @endif
                            </div>
                            <div style="margin-top:14px">
                                <label class="field-label">Surclassements ({{ $surclassements->count() }})</label>
                                <div class="flex ac g6 wrap">
                                    @if ($surclassements->isEmpty() && ! $addingCat)<span class="meta">Aucun surclassement.</span>@endif
                                    @foreach ($surclassements as $c)
                                        <span class="chip chip-green flex ac g4">+ {{ $c->label }}<button type="button" wire:click="removeSurclassement({{ $c->id }})" style="display:inline-flex;border:none;background:none;padding:0;margin:0 -2px 0 1px;cursor:pointer;color:inherit;opacity:0.7" aria-label="Retirer {{ $c->label }}"><x-icon name="x" :size="12" /></button></span>
                                    @endforeach
                                    @if (! $addingCat && $availableCats->isNotEmpty())
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

                        {{-- Lien de tutelle (lecture seule — gestion fine → jalon ultérieur) --}}
                        @if ($u->guardian)
                            <div class="card card-pad">
                                <div class="flex ac jb"><span class="sect-title">Lien de tutelle</span><x-icon name="shield" :size="16" class="muted" /></div>
                                <div class="card card-soft card-pad flex ac g10" style="margin-top:12px">
                                    <x-avatar :name="$u->guardian->fullName()" size="sm" tint="tint-bike" />
                                    <div class="f1" style="min-width:0"><div style="font-weight:700;font-size:14px">{{ $u->guardian->fullName() }}</div><div class="meta" style="font-size:12px">parent garant</div></div>
                                </div>
                                <div class="meta" style="font-size:12.5px;margin:12px 0;line-height:1.5">Gestion de la tutelle (accès autonome / rupture) — bientôt disponible.</div>
                                <span class="btn btn-ghost btn-block is-disabled"><x-icon name="user-plus" :size="15" /> Gérer le lien de tutelle</span>
                            </div>
                        @endif

                        {{-- Rôles --}}
                        <div class="card card-pad">
                            <div class="flex ac jb"><span class="sect-title">Rôles</span><span class="meta">cumulables</span></div>
                            <div style="margin-top:6px">
                                @php $roleDefs = [['k' => 'athlete', 'l' => 'Athlète', 'sub' => "S'inscrit aux séances, file d'attente, quotas."], ['k' => 'coach', 'l' => 'Coach', 'sub' => 'Encadre, crée des séances, gère les waitlists.'], ['k' => 'admin', 'l' => 'Admin', 'sub' => 'Adhérents, modèles, journaux, paramètres.']]; @endphp
                                @foreach ($roleDefs as $i => $r)
                                    <div class="flex ac g12" style="padding:12px 0;{{ $i < 2 ? 'border-bottom:1px solid var(--divider)' : '' }}">
                                        <div class="f1"><div style="font-weight:700;font-size:14px">{{ $r['l'] }}</div><div class="meta" style="font-size:12px">{{ $r['sub'] }}</div></div>
                                        <x-toggle :on="in_array($r['k'], $roleChips)" wire:click="toggleRole('{{ $r['k'] }}')" />
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Qualifications --}}
                        <div class="card card-pad">
                            <div class="flex ac jb"><span class="sect-title">Qualifications</span><span class="meta">expiration optionnelle</span></div>
                            <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
                                @if ($u->qualifications->isEmpty())<span class="meta">Aucune qualification enregistrée.</span>@endif
                                @foreach ($u->qualifications as $q)
                                    @php $s = \App\Support\QualificationDisplay::status($q->pivot->expires_at ? \Illuminate\Support\Carbon::parse($q->pivot->expires_at) : null); @endphp
                                    <div wire:key="qual-{{ $q->id }}" style="padding:10px 12px;background:var(--bg-alt);border-radius:var(--radius-md)">
                                        <div class="flex ac g10">
                                            <x-icon name="award" :size="18" style="color:var(--fg-soft);flex:0 0 auto" />
                                            <div class="f1" style="min-width:0">
                                                <div style="font-weight:700;font-size:14px">{{ $q->label }}</div>
                                                <span class="chip chip-sm {{ $s['cls'] }}" style="margin-top:4px">{{ $qualLabels[$s['status']] }}{{ $q->pivot->expires_at ? ' · '.\Illuminate\Support\Carbon::parse($q->pivot->expires_at)->format('d/m/Y') : '' }}</span>
                                            </div>
                                            <button type="button" class="iconbtn" wire:click="startEditQualExpiry({{ $q->id }})" style="flex:0 0 auto" aria-label="Modifier l'expiration"><x-icon name="edit" :size="15" style="color:var(--fg-muted)" /></button>
                                            <button type="button" class="iconbtn" wire:click="removeQualification({{ $q->id }})" style="flex:0 0 auto" aria-label="Retirer"><x-icon name="x" :size="16" style="color:var(--fg-muted)" /></button>
                                        </div>
                                        @if ($editingQualId === $q->id)
                                            <div class="flex ac g8" style="margin-top:10px">
                                                <input type="date" class="input f1" wire:model="editQualExpiry">
                                                <button type="button" class="btn btn-primary btn-sm" wire:click="saveQualExpiry">OK</button>
                                                <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelQualExpiry">Annuler</button>
                                            </div>
                                            <div class="meta" style="margin-top:6px;font-size:12px">Vide = sans expiration.</div>
                                            @error('editQualExpiry')<div class="meta" style="margin-top:4px;color:var(--danger)">{{ $message }}</div>@enderror
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                            @if (! $addingQual)
                                <button type="button" class="btn btn-ghost btn-sm" style="margin-top:10px" wire:click="$set('addingQual', true)" @disabled($availableQuals->isEmpty())><x-icon name="plus" :size="14" /> Ajouter une qualification</button>
                            @else
                                <div class="card card-soft" style="margin-top:10px;padding:10px">
                                    {{-- Étape 1 : choisir la qualification à attribuer. --}}
                                    <div class="meta" style="margin-bottom:6px">Sélectionner la qualification à attribuer :</div>
                                    <div class="flex g6 wrap">
                                        @foreach ($availableQuals as $q)
                                            <button type="button" wire:key="add-qual-{{ $q->id }}" class="chip {{ $pendingQualId === $q->id ? 'chip-blue' : 'chip-line' }}" wire:click="selectQualification({{ $q->id }})">{{ $q->label }}</button>
                                        @endforeach
                                    </div>
                                    {{-- Étape 2 : fixer la date d'expiration (au besoin) puis valider. --}}
                                    @if ($pendingQualId !== null)
                                        <div class="meta" style="margin:12px 0 6px">Expiration (optionnelle, modifiable ensuite via le crayon) :</div>
                                        <input type="date" class="input" style="width:100%;margin-bottom:4px" wire:model="newQualExpiry">
                                        <div class="meta" style="margin-bottom:8px;font-size:12px">Vide = sans expiration.</div>
                                        @error('newQualExpiry')<div class="meta" style="margin-bottom:6px;color:var(--danger)">{{ $message }}</div>@enderror
                                        <div class="flex g8">
                                            <button type="button" class="btn btn-primary btn-sm" wire:click="addQualification"><x-icon name="check" :size="14" /> Attribuer</button>
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelAddQualification">Annuler</button>
                                        </div>
                                    @else
                                        <button type="button" class="chip" style="margin-top:10px" wire:click="cancelAddQualification">Annuler</button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- ── Colonne droite : Accès & sécurité (suppression RGPD voie admin, §4.3) ── --}}
                    <div style="display:flex;flex-direction:column;gap:18px;position:sticky;top:0">
                        <div class="card card-pad" style="border-color:{{ $pending ? 'var(--danger)' : 'var(--hair)' }}">
                            <div class="sect-title" style="margin-bottom:12px">Accès & sécurité</div>

                            @if ($suspended)
                                <x-banner kind="warn" style="margin-bottom:12px"><div>Accès athlète <b>suspendu</b>.</div></x-banner>
                                <button type="button" class="btn btn-primary btn-block" style="margin-bottom:12px" wire:click="reactivateAccess">
                                    <x-icon name="refresh-cw" :size="15" /> Réactiver l'accès athlète
                                </button>
                            @endif

                            {{-- Dépannage d'accès (§4.1.5). Le bureau DÉCLENCHE l'envoi ; il ne voit
                                 ni ne choisit jamais le mot de passe — le secret ne transite que par
                                 la boîte mail de l'adhérent. --}}
                            @if ($u->email && $u->is_active && ! $u->anonymized_at)
                                <button type="button" class="btn btn-ghost btn-block" style="margin-bottom:12px"
                                    wire:click="sendPasswordReset"
                                    wire:confirm="Envoyer un lien de réinitialisation à {{ $u->email }} ?"
                                    wire:loading.attr="disabled" wire:target="sendPasswordReset">
                                    <x-icon name="mail" :size="15" /> Envoyer un lien de réinitialisation
                                </button>
                            @endif

                            @if ($pending)
                                <div class="card card-soft" style="padding:12px;border-radius:var(--radius-md);margin-bottom:12px">
                                    <div class="flex ac jb g8">
                                        <span style="font-family:var(--font-display);font-weight:700;text-transform:uppercase;letter-spacing:0.02em;font-size:11.5px;color:var(--danger);white-space:nowrap">Demande de suppression</span>
                                        <x-icon name="trash" :size="16" style="color:var(--danger);flex:0 0 auto" />
                                    </div>
                                    <div class="meta" style="margin-top:6px;font-size:12.5px;line-height:1.5">Déclenchée le <b>{{ $u->deletion_requested_at->locale('fr')->isoFormat('D MMM YYYY') }}</b> · à l’initiative du bureau.</div>
                                    @if ($eligible)
                                        <x-banner kind="danger" style="margin-top:10px"><div><b>Délai de 7 jours écoulé</b> ({{ $dayN }} j). Suppression définitive possible.</div></x-banner>
                                    @else
                                        <div style="margin-top:10px">
                                            <div class="flex ac jb" style="font-size:12px;margin-bottom:6px"><span class="meta">Tampon RGPD · J+{{ $dayN }}/{{ $buffer }}</span><span class="num" style="font-size:15px;color:var(--warning-text)">{{ $remaining }} j restant{{ $remaining > 1 ? 's' : '' }}</span></div>
                                            <div class="qbar"><i style="width:{{ $buffer ? round($progress / $buffer * 100) : 0 }}%;background:var(--warning)"></i></div>
                                        </div>
                                    @endif
                                </div>
                                <button type="button" class="btn btn-ghost btn-block" style="margin-bottom:8px" wire:click="cancelDeletion">
                                    <x-icon name="refresh-cw" :size="15" /> Annuler la demande de suppression
                                </button>
                                <button type="button" class="btn btn-danger-solid btn-block{{ $eligible ? '' : ' is-disabled' }}"
                                        @if ($eligible) wire:click="$set('confirmingFinal', true)" @endif>
                                    <x-icon name="trash" :size="15" /> Confirmer la suppression définitive
                                </button>
                                @unless ($eligible)
                                    <div class="meta tc" style="font-size:11.5px;margin-top:6px">Cliquable dans {{ $remaining }} jour{{ $remaining > 1 ? 's' : '' }} (J+{{ $buffer }}).</div>
                                @endunless
                                @error('deletion')<div class="meta tc" style="color:var(--danger);font-size:11.5px;margin-top:6px">{{ $message }}</div>@enderror
                            @else
                                <x-banner kind="green"><div>Compte <b>actif</b>. Aucune demande de suppression en cours.</div></x-banner>
                                <button type="button" class="btn btn-ghost btn-block" style="margin-top:12px;color:var(--danger)" wire:click="$set('confirmingRequest', true)">
                                    <x-icon name="trash" :size="15" /> Supprimer ce compte
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @else
                {{-- ═══ Historique d'inscriptions ═══ --}}
                @php
                    $presences = $past->where('status', 'participating')->count();
                    $annulees = $past->where('status', 'cancelled')->count();
                @endphp
                <div style="display:flex;flex-direction:column;gap:18px">
                    <div class="flex g10 wrap">
                        @foreach ([[$future->count(), 'à venir'], [$presences, 'présences'], [$annulees, 'annulées'], [$past->count(), 'passées']] as [$n, $l])
                            <div class="stat" style="padding:12px;min-width:100px;flex:1"><div class="n" style="font-size:26px">{{ $n }}</div><div class="l">{{ $l }}</div></div>
                        @endforeach
                    </div>

                    <div class="card" style="overflow:hidden">
                        <div class="flex ac jb" style="padding:12px 16px;background:var(--brand-50);border-bottom:1px solid var(--divider)">
                            <span class="sect-title" style="color:var(--brand-700)">Inscriptions futures</span><span class="chip chip-sm chip-green">{{ $future->count() }}</span>
                        </div>
                        <div style="padding:4px 16px">
                            @forelse ($future as $r)
                                @include('livewire.admin.partials.member-insc-row', ['r' => $r, 'regStatus' => $regStatus])
                            @empty
                                <div class="meta tc" style="padding:16px">Aucune inscription à venir.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="card" style="overflow:hidden">
                        <div class="flex ac jb" style="padding:12px 16px;background:var(--bg-alt);border-bottom:1px solid var(--divider)">
                            <span class="sect-title">Inscriptions passées</span><span class="chip chip-sm chip-line">{{ $past->count() }}</span>
                        </div>
                        <div style="padding:4px 16px">
                            @forelse ($past as $r)
                                @include('livewire.admin.partials.member-insc-row', ['r' => $r, 'regStatus' => $regStatus])
                            @empty
                                <div class="meta tc" style="padding:16px">Aucune inscription passée.</div>
                            @endforelse
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ── Modale : demande de suppression (voie admin §4.3) — saisie du nom + double validation ── --}}
    @if ($confirmingRequest)
        <x-dialog title="Supprimer ce compte" :danger="true" :width="460" close="$set('confirmingRequest', false)">
            <x-banner kind="danger">
                <div>Cette action ouvre un <b>tampon RGPD de 7 jours</b>. Le compte est désactivé
                immédiatement (connexion impossible) ; la suppression définitive ne sera confirmable
                qu’après J+{{ $buffer }}, et reste annulable jusque-là.</div>
            </x-banner>
            <div style="margin-top:14px">
                <label class="field-label">Pour confirmer, saisissez le nom complet : <b>{{ $u->fullName() }}</b></label>
                <input class="input" style="margin-top:8px" wire:model="deleteConfirmName" placeholder="{{ $u->fullName() }}" autocomplete="off">
                @error('deleteConfirmName')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror
            </div>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="$set('confirmingRequest', false)">Annuler</button>
                <button type="button" class="btn btn-danger" wire:click="requestDeletion">Demander la suppression</button>
            </x-slot:footer>
        </x-dialog>
    @endif

    {{-- ── Modale : confirmation définitive (J+7, §4.3) — confirmation forte ── --}}
    @if ($confirmingFinal)
        <x-dialog title="Suppression définitive" :danger="true" :width="460" close="$set('confirmingFinal', false)">
            <x-banner kind="danger">
                <div>Le compte de <b>{{ $u->fullName() }}</b> et toutes ses données personnelles vont être
                <b>définitivement supprimés</b>. Les journaux sont conservés mais anonymisés (corrélation
                préservée, identité effacée). <b>Action irréversible.</b></div>
            </x-banner>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="$set('confirmingFinal', false)">Annuler</button>
                <button type="button" class="btn btn-danger-solid" wire:click="confirmDeletion">Supprimer définitivement</button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
