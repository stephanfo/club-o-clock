{{-- Page « Adhérents » (PRD §4.17.1) — porté de screen-admin.jsx AdminAdherents.
     Compteurs d'en-tête, recherche + filtres, table paginée. Admin uniquement. --}}
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar ─── --}}
    <div class="dk-topbar">
        <div class="f1">
            <div class="dsp" style="font-size:24px">Adhérents</div>
            <div class="meta">{{ $counts['active'] }} actifs · {{ $counts['suspended'] }} suspendus · {{ $counts['eligible'] }} éligible{{ $counts['eligible'] > 1 ? 's' : '' }} suppression</div>
        </div>
        {{-- Import CSV adhérents (§3.1, §4.2 — J6.5). --}}
        <button type="button" wire:click="openImport" class="btn btn-ghost btn-sm"><x-icon name="upload" :size="15" /> Import CSV</button>
        {{-- Rattrapage (§4.1.3) : comptes jamais entrés, typiquement un import fait sans envoi. --}}
        @if ($awaiting > 0)
            <button type="button" wire:click="confirmBulkInvite" class="btn btn-ghost btn-sm">
                <x-icon name="mail" :size="15" /> Inviter {{ $awaiting }} en attente
            </button>
        @endif
        <a href="{{ route('admin.members.create') }}" class="btn btn-primary btn-sm" wire:navigate>
            <x-icon name="plus" :size="15" /> Ajouter
        </a>
    </div>

    <div class="dk-body">
        {{-- ═══ Compteurs ═══ --}}
        <div class="flex g12 ac wrap" style="margin-bottom:16px">
            <div class="stat" style="padding:12px;min-width:110px">
                <div class="n" style="font-size:28px">{{ $counts['active'] }}</div><div class="l">actifs</div>
            </div>
            <div class="stat" style="padding:12px;min-width:110px">
                <div class="n" style="font-size:28px">{{ $counts['suspended'] }}</div><div class="l">suspendus</div>
            </div>
            @if ($counts['pending'])
                <div class="stat" style="padding:12px;min-width:110px;border-color:var(--warning-border)">
                    <div class="n" style="font-size:28px;color:var(--warning-text)">{{ $counts['pending'] }}</div><div class="l">suppr. en cours</div>
                </div>
            @endif
            <div class="stat" style="padding:12px;min-width:110px{{ $counts['eligible'] ? ';border-color:var(--danger)' : '' }}">
                <div class="n" style="font-size:28px{{ $counts['eligible'] ? ';color:var(--danger)' : '' }}">{{ $counts['eligible'] }}</div><div class="l">éligible suppr.</div>
            </div>
            <div class="stat" style="padding:12px;min-width:110px">
                <div class="n" style="font-size:28px">{{ $counts['guardians'] }}</div><div class="l">parents garants</div>
            </div>
            <div class="f1"></div>
            {{-- Actions de saison (recalcul catégories §4.5 · bascule §4.4) : centralisées dans
                 « Paramètres du club » (PRD §4.17), retirées d'ici pour éviter le doublon. --}}
        </div>

        {{-- ═══ Table ═══ --}}
        <div class="card" style="overflow:hidden">
            <div class="flex g8 ac wrap" style="padding:12px;border-bottom:1px solid var(--divider)">
                <div class="input flex ac g8 f1" style="min-width:220px">
                    <x-icon name="search" :size="16" style="color:var(--fg-muted)" />
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Rechercher nom, email, catégorie…"
                        style="border:none;background:none;outline:none;width:100%;font:inherit;color:inherit">
                </div>
                <button type="button" wire:click="setAccess('all')" class="chip{{ $access === 'all' ? ' is-active' : '' }}">Tous</button>
                <button type="button" wire:click="setAccess('active')" class="chip{{ $access === 'active' ? ' is-active' : '' }}">Actifs</button>
                <button type="button" wire:click="setAccess('suspended')" class="chip{{ $access === 'suspended' ? ' is-active' : '' }}">Suspendus</button>
                <button type="button" wire:click="setAccess('pending')" class="chip{{ $access === 'pending' ? ' is-active' : '' }}">Suppr. en cours</button>
                <button type="button" wire:click="setAccess('eligible')" class="chip{{ $access === 'eligible' ? ' is-active' : '' }}">Éligible suppr.</button>
                <div x-data="{ open: false }" style="position:relative">
                    <button type="button" x-on:click="open = !open" class="chip{{ $role !== 'all' ? ' is-active' : '' }}">
                        Rôle{{ $role !== 'all' ? ' · '.$role : '' }} ▾
                    </button>
                    <div x-show="open" x-on:click.outside="open = false" x-cloak
                        class="card card-pad" style="position:absolute;right:0;top:calc(100% + 6px);z-index:20;min-width:160px;display:flex;flex-direction:column;gap:4px">
                        @foreach (['all' => 'Tous les rôles', 'athlete' => 'Athlète', 'coach' => 'Coach', 'admin' => 'Admin'] as $val => $lbl)
                            <button type="button" wire:click="setRole('{{ $val }}')" x-on:click="open = false"
                                class="chip{{ $role === $val ? ' is-active' : '' }}" style="justify-content:flex-start">{{ $lbl }}</button>
                        @endforeach
                    </div>
                </div>
            </div>

            @if ($members->isEmpty())
                <div class="meta tc" style="padding:32px">Aucun adhérent ne correspond à ces critères.</div>
            @else
                <div style="padding:0 14px">
                    <table class="tbl">
                        <thead><tr><th></th><th>Nom</th><th>Email</th><th>Cat.</th><th>Rôles</th><th>Accès</th><th>Parent</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($members as $m)
                                @php
                                    $primary = $m->primaryCategory();
                                    $eligible = $m->isDeletionEligible();
                                    $pending = $m->isDeletionPending();
                                @endphp
                                <tr class="row-press" style="cursor:pointer" wire:key="m-{{ $m->id }}"
                                    onclick="window.location='{{ route('admin.members.show', $m) }}'">
                                    <td><x-avatar :name="$m->fullName()" size="sm" /></td>
                                    <td style="font-weight:700">{{ $m->fullName() }}</td>
                                    <td class="meta" style="font-size:13px">{{ $m->email ?? '—' }}</td>
                                    <td>{{ $primary?->label ?? '—' }}</td>
                                    <td>
                                        <div class="flex wrap" style="gap:3px">
                                            @foreach ($m->roles ?? [] as $r)
                                                <span class="chip chip-sm chip-line">{{ $r }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td>
                                        @if ($eligible)
                                            <span class="chip chip-sm chip-danger">! éligible suppr.</span>
                                        @elseif ($pending)
                                            <span class="chip chip-sm chip-warn">⏳ suppr. en cours</span>
                                        @elseif ($m->athlete_access_suspended)
                                            <span class="chip chip-sm chip-warn">○ suspendu</span>
                                        @else
                                            <span class="chip chip-sm chip-green">● actif</span>
                                        @endif
                                    </td>
                                    <td class="meta" style="font-size:12px">{{ $m->guardian?->fullName() ?? '—' }}</td>
                                    <td style="text-align:right">
                                        <a href="{{ route('admin.members.show', $m) }}" class="iconbtn" wire:navigate aria-label="Voir la fiche" onclick="event.stopPropagation()">
                                            <x-icon name="more-horizontal" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="meta tc" style="padding:12px">
                    {{ $members->count() }} / {{ $total }}
                    @if ($members->count() < $total)
                        · <button type="button" wire:click="loadMore" class="underline-link" style="border:none;background:none;cursor:pointer;font:inherit;color:inherit">charger plus</button>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- ── Modale : import CSV adhérents (§3.1, §4.2 — J6.5) — porté de ImportCSVModal ── --}}
    @if ($showImport)
        @php
            $rep = $importReport;
            $clean = $rep && ! $rep['fatal'] && empty($rep['errors']) && $rep['total'] > 0;
        @endphp
        <x-dialog title="Import CSV adhérents" sub="Début de saison · 6 colonnes attendues" :width="460" close="closeImport">
            {{-- Dropzone --}}
            <label class="imgph" style="height:80px;margin-bottom:14px;cursor:pointer;display:flex">
                <input type="file" wire:model="csvFile" accept=".csv,text/csv,text/plain" style="display:none" />
                <div wire:loading.remove wire:target="csvFile">
                    <x-icon name="upload" :size="22" style="margin:0 auto 4px;display:block" />
                    {{ $csvFile ? $csvFile->getClientOriginalName() : 'Déposer adherents.csv (nom, prénom, email, catégorie, date_nais, parent_email)' }}
                </div>
                <div wire:loading wire:target="csvFile">Analyse…</div>
            </label>
            @error('csvFile')<x-banner kind="danger" style="margin-bottom:12px"><div>{{ $message }}</div></x-banner>@enderror

            @if ($rep)
                @if ($rep['fatal'])
                    <x-banner kind="danger"><div>{{ $rep['fatal'] }}</div></x-banner>
                @else
                    {{-- Aperçu des 3 premières lignes --}}
                    <div class="eyebrow" style="margin-bottom:6px">Aperçu · {{ count($rep['preview']) }} première{{ count($rep['preview']) > 1 ? 's' : '' }} ligne{{ count($rep['preview']) > 1 ? 's' : '' }}</div>
                    <div class="card card-pad card-soft mono" style="font-size:11px;line-height:1.7;overflow-x:auto">
                        <div class="muted">nom,prénom,email,date_nais,parent_email</div>
                        @foreach ($rep['preview'] as $row)
                            <div>{{ $row['last_name'] }},{{ $row['first_name'] }},{{ $row['email'] ?: '—' }},{{ $row['dob'] }},{{ $row['parent_email'] ?: '' }}</div>
                        @endforeach
                    </div>

                    <x-banner kind="info" style="margin-top:12px"><div>Catégorie <b>dérivée de la date de naissance</b> (colonne CSV ignorée). Email facultatif pour les mineurs en phase <b>P1</b> (créés sans compte propre) ; avec un email + un parent garant, l'enfant démarre en <b>P2</b>.</div></x-banner>

                    @if (! empty($rep['errors']))
                        <x-banner kind="danger" style="margin-top:12px"><div><b>{{ count($rep['errors']) }} erreur{{ count($rep['errors']) > 1 ? 's' : '' }}</b> — import bloqué (tout ou rien). Corrige le fichier et redépose-le.</div></x-banner>
                        <div class="card card-pad card-soft" style="margin-top:10px;max-height:180px;overflow-y:auto">
                            @foreach ($rep['errors'] as $err)
                                <div class="flex ac g8" style="font-size:13px;padding:3px 0">
                                    <span class="chip" style="flex:0 0 auto">ligne {{ $err['line'] }}</span>
                                    <span style="color:var(--danger)">{{ $err['message'] }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="meta" style="font-size:13px;margin-top:10px"><b>{{ $rep['total'] }} ligne{{ $rep['total'] > 1 ? 's' : '' }}</b> · {{ $rep['new'] }} nouveau{{ $rep['new'] > 1 ? 'x' : '' }} · {{ $rep['update'] }} mise{{ $rep['update'] > 1 ? 's' : '' }} à jour · 0 erreur</div>
                    @endif
                @endif
            @endif

            {{-- Les invitations d'un import partent en FILE (outbox), pas en direct : voir
                 MemberList::import(). Décocher importe en silence, à rattraper plus tard. --}}
            <label class="flex ac g8" style="margin-top:12px;font-size:13px">
                <input type="checkbox" wire:model="sendInvitations"> Envoyer les invitations aux comptes créés
            </label>

            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="closeImport">Annuler</button>
                <button type="button" class="btn btn-primary{{ $clean ? '' : ' is-disabled' }}"
                        @if ($clean) wire:click="import" @endif wire:loading.attr="disabled" wire:target="import">
                    <x-icon name="upload" :size="15" />
                    {{ $clean ? 'Importer '.$rep['total'].' ligne'.($rep['total'] > 1 ? 's' : '') : 'Importer' }}
                </button>
            </x-slot:footer>
        </x-dialog>
    @endif

    {{-- Envoi de masse : notifie des tiers → confirmation à conséquences (conventions UI). --}}
    @if ($confirmingBulkInvite)
        {{-- Ce clic est PLAFONNÉ (MemberList::BULK_INVITE_CAP) : la modale annonce ce qui va
             réellement partir, pas le total en attente. Sinon l'admin d'un gros import lisait
             « 800 invitations mises en file » avant de cliquer, et 300 étaient omises en silence.
             Le reliquat est dit explicitement — l'action est réexécutable. --}}
        @php($lot = min($awaiting, $inviteCap))
        @php($reste = $awaiting - $lot)
        <x-dialog title="Inviter les adhérents en attente" :sub="$awaiting.' compte'.($awaiting > 1 ? 's' : '').' concerné'.($awaiting > 1 ? 's' : '')" :width="460" close="$set('confirmingBulkInvite', false)">
            <div style="display:flex;flex-direction:column;gap:12px">
                <x-conseq-row icon="mail" label="Envoi" tone="green">
                    <span><b>{{ $lot }}</b> invitation{{ $lot > 1 ? 's' : '' }} mise{{ $lot > 1 ? 's' : '' }} en file — elles partent au fil du cron, visibles dans <b>Admin → Envois</b>.</span>
                </x-conseq-row>
                @if ($reste > 0)
                    <x-conseq-row icon="clock" label="Reste" tone="warn">
                        <span>Envoi limité à <b>{{ $inviteCap }}</b> par clic pour ne pas saturer la file. <b>{{ $reste }}</b> compte{{ $reste > 1 ? 's' : '' }} restera{{ $reste > 1 ? 'nt' : '' }} en attente : relance l'action pour les traiter.</span>
                    </x-conseq-row>
                @endif
                <x-conseq-row icon="users" label="Qui" tone="ink">
                    <span>Uniquement les comptes actifs, avec email, <b>jamais entrés</b> et sans invitation en cours. Personne n'est sollicité deux fois.</span>
                </x-conseq-row>
            </div>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="$set('confirmingBulkInvite', false)">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="sendPendingInvitations"
                        wire:loading.attr="disabled" wire:target="sendPendingInvitations">
                    <x-icon name="mail" :size="15" /> Envoyer
                </button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
