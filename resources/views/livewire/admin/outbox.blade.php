{{-- Écran bureau de gestion de l'outbox (PRD §4.15.6 — J8.3). Consultation filtrée (statut, canal,
     type, destinataire) + détail, rattrapage (annulation pending), envoi manuel immédiat, rejeu des
     échecs. Admin uniquement. Pas de proto dédié → calqué sur la page Journaux (filtres + drawer). --}}
@php
    use App\Models\NotificationOutbox;
    use App\Notifications\NotificationType;

    $statusChip = ['pending' => 'chip-warn', 'sent' => 'chip-green', 'failed' => 'chip-danger', 'cancelled' => 'chip-cancel'];
    $typeLabel = fn (string $v) => NotificationType::tryFrom($v)?->label() ?? $v;
    $selectedCount = count($selected);
@endphp
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    {{-- ─── Topbar ─── --}}
    <div class="dk-topbar">
        <div class="f1">
            <div class="dsp" style="font-size:24px">Envois sortants</div>
            {{-- L'état sain se dit ici, en sourdine : un bandeau permanent pousserait les filtres
                 vers le bas à chaque visite pour ne rien apprendre. Les états anormaux, eux, prennent
                 un bandeau pleine largeur sous la topbar. --}}
            <div class="meta">
                file de notifications · push + email
                @if ($scheduler['state'] === 'ok')
                    · traitement automatique actif
                    ({{ $scheduler['age'] }})
                @endif
            </div>
        </div>
        <div class="flex g8 ac">
            <button type="button" wire:click="pushAllPending" class="btn btn-ghost btn-sm" wire:loading.attr="disabled">
                <x-icon name="send" :size="15" /> Pousser tous les en attente
            </button>
            <button type="button" wire:click="askCancel('all')" class="btn btn-ghost btn-sm">
                <x-icon name="x" :size="15" /> Annuler tous les en attente
            </button>
        </div>
    </div>

    <div class="dk-body">
        {{-- ═══ Supervision du traitement automatique (cron, INSTALL §5.4) ═══
             Tout l'envoi différé dépend d'un cron unique appelant `schedule:run`. S'il meurt (quota
             mutualisé, chemin PHP changé, crontab perdue au transfert), les lignes s'accumulent en
             « en attente » et RIEN ne le signale : le premier symptôme est un adhérent non prévenu
             d'une annulation. Ce bandeau rend la panne visible là où l'admin vient déjà quand il
             doute d'un envoi.
             Trois états — jamais d'alerte sur une installation neuve (« unknown »). --}}
        @if ($scheduler['state'] === 'stale')
            <div class="banner banner-danger" style="margin-bottom:14px">
                <x-icon name="alert-triangle" class="ic" :size="18" />
                <div>
                    <strong>Traitement automatique interrompu.</strong>
                    Dernier passage {{ $scheduler['last']->copy()->setTimezone($tz)->format('d/m/Y H:i') }}
                    ({{ $scheduler['age'] }}),
                    alors qu'un passage est attendu toutes les 5 minutes.
                    <div style="margin-top:4px">
                        Les notifications ne partent plus. Vérifier la tâche planifiée (cron) sur
                        l'hébergement — voir <em>INSTALL §5.4</em>. En attendant, le bouton
                        « Pousser tous les en attente » ci-dessus envoie la file manuellement.
                    </div>
                </div>
            </div>
        @elseif ($scheduler['state'] === 'unknown')
            <div class="banner banner-info" style="margin-bottom:14px">
                <x-icon name="info" class="ic" :size="18" />
                <div>
                    <strong>Traitement automatique jamais observé.</strong>
                    Normal sur une installation récente : le voyant s'allumera au premier passage du
                    cron (moins de 5 minutes). S'il reste ainsi, la tâche planifiée n'est pas en
                    place — voir <em>INSTALL §5.4</em>.
                </div>
            </div>
        @endif

        {{-- ═══ Filtres (§4.15.6) ═══ --}}
        <div class="flex g8 ac wrap" style="margin-bottom:14px">
            <x-segmented :items="[['v'=>'','l'=>'Tous'],['v'=>'pending','l'=>'En attente'],['v'=>'sent','l'=>'Envoyées'],['v'=>'failed','l'=>'En échec'],['v'=>'cancelled','l'=>'Annulées']]"
                         :value="$status" wire-set="status" />

            <x-segmented :items="[['v'=>'','l'=>'Tous canaux'],['v'=>'push','l'=>'Push'],['v'=>'email','l'=>'Email']]"
                         :value="$channel" wire-set="channel" />

            {{-- Type — liste déroulante. --}}
            <div x-data="{ open: false }" style="position:relative">
                <button type="button" x-on:click="open = !open" class="chip{{ $type ? ' is-active' : '' }}">
                    type{{ $type ? ' · '.$typeLabel($type) : '' }} ▾
                </button>
                <div x-show="open" x-on:click.outside="open = false" x-cloak
                    class="card card-pad" style="position:absolute;left:0;top:calc(100% + 6px);z-index:20;min-width:250px;max-height:340px;overflow:auto;display:flex;flex-direction:column;gap:2px">
                    <button type="button" wire:click="$set('type', '')" x-on:click="open = false" class="chip{{ $type === '' ? ' is-active' : '' }}" style="justify-content:flex-start">Tous les types</button>
                    @foreach ($typeOptions as $t)
                        <button type="button" wire:click="$set('type', '{{ $t->value }}')" x-on:click="open = false"
                            class="chip{{ $type === $t->value ? ' is-active' : '' }}" style="justify-content:flex-start">{{ $t->label() }}</button>
                    @endforeach
                </div>
            </div>

            {{-- Destinataire — autocomplete. --}}
            <div style="position:relative">
                @if ($userId)
                    <span class="chip is-active" style="gap:6px">
                        <x-icon name="user" :size="13" /> {{ $userLabel }}
                        <button type="button" wire:click="clearUser" style="border:none;background:none;cursor:pointer;color:inherit;font-size:15px;line-height:1">×</button>
                    </span>
                @else
                    <div class="input flex ac g8" style="min-width:190px;padding:6px 10px">
                        <x-icon name="search" :size="15" style="color:var(--fg-muted)" />
                        <input type="text" wire:model.live.debounce.350ms="userQuery" placeholder="Destinataire…"
                            style="border:none;background:none;outline:none;width:100%;font:inherit;color:inherit">
                    </div>
                    @if (count($userSuggestions))
                        <div class="card card-pad" style="position:absolute;left:0;top:calc(100% + 6px);z-index:20;min-width:210px;display:flex;flex-direction:column;gap:2px">
                            @foreach ($userSuggestions as $s)
                                <button type="button" wire:click="selectUser({{ $s['id'] }})" class="chip" style="justify-content:flex-start">{{ $s['label'] }}</button>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>

            <div class="f1"></div>
            @if ($status || $channel || $type || $userId)
                <button type="button" wire:click="resetFilters" class="chip">réinitialiser</button>
            @endif
        </div>

        {{-- ═══ Barre d'actions sur la sélection (§4.15.6) ═══ --}}
        @if ($selectedCount)
            <div class="card card-pad flex ac jb" style="margin-bottom:12px;gap:12px">
                <span class="meta">{{ $selectedCount }} sélectionné(s)</span>
                <div class="flex g8 ac">
                    <button type="button" wire:click="pushSelected" class="btn btn-primary btn-sm"><x-icon name="send" :size="14" /> Pousser</button>
                    <button type="button" wire:click="retrySelected" class="btn btn-ghost btn-sm"><x-icon name="refresh-cw" :size="14" /> Rejouer</button>
                    <button type="button" wire:click="askCancel('selected')" class="btn btn-ghost btn-sm"><x-icon name="x" :size="14" /> Annuler</button>
                </div>
            </div>
        @endif

        {{-- ═══ Table ═══ --}}
        <div class="card" style="overflow:hidden">
            @if ($rows->isEmpty())
                <div class="meta tc" style="padding:32px">Aucun envoi ne correspond à ces filtres.</div>
            @else
                <div style="padding:0 14px">
                    <table class="tbl">
                        <thead><tr><th></th><th>Date</th><th>Statut</th><th>Canal</th><th>Type</th><th>Destinataire</th><th>Tentatives</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr wire:key="ob-{{ $r->id }}" wire:click="showDetail({{ $r->id }})" style="cursor:pointer">
                                    <td style="width:28px" x-on:click.stop>
                                        <input type="checkbox" wire:model.live="selected" value="{{ $r->id }}" x-on:click.stop>
                                    </td>
                                    <td class="meta mono" style="font-size:12px;white-space:nowrap">{{ $r->created_at->copy()->setTimezone($tz)->format('d/m H:i') }}</td>
                                    <td><span class="chip chip-sm {{ $statusChip[$r->status] ?? 'chip-line' }}">{{ $statusLabels[$r->status] ?? $r->status }}</span></td>
                                    <td><span class="chip chip-sm chip-line">{{ $r->channel }}</span></td>
                                    <td style="font-size:13px">{{ $typeLabel($r->type) }}</td>
                                    <td>{{ $r->user?->fullName() ?? '—' }}</td>
                                    <td class="meta mono" style="font-size:12px">{{ $r->attempts }}</td>
                                    <td style="text-align:right"><x-icon name="chevron-right" :size="16" class="muted" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="meta tc" style="padding:12px">
                    {{ $rows->count() }} / {{ $total }}
                    @if ($rows->count() < $total)
                        · <button type="button" wire:click="loadMore" class="underline-link">charger plus</button>
                    @endif
                </div>
            @endif
        </div>
    </div>

    {{-- ═══ Drawer détail ═══ --}}
    @if ($detail)
        <x-dialog title="Détail · envoi #{{ $detail->id }}" :width="440" close="closeDetail">
            <div style="display:flex;flex-direction:column;gap:10px">
                @foreach ([
                    'Créé le' => $detail->created_at->copy()->setTimezone($tz)->format('d/m/Y H:i'),
                    'Type' => $typeLabel($detail->type),
                    'Canal' => $detail->channel,
                ] as $label => $value)
                    <div class="flex jb g12" style="border-bottom:1px solid var(--divider);padding-bottom:8px">
                        <span class="meta" style="font-size:12px;flex-shrink:0">{{ $label }}</span>
                        <span style="text-align:right;min-width:0;word-break:break-word">{{ $value }}</span>
                    </div>
                @endforeach

                {{-- Destinataire — lié vers sa fiche membre si le compte existe encore et n'est pas
                     anonymisé (le tombstone « Compte supprimé » reste affiché en texte inerte). --}}
                <div class="flex jb g12" style="border-bottom:1px solid var(--divider);padding-bottom:8px">
                    <span class="meta" style="font-size:12px;flex-shrink:0">Destinataire</span>
                    <span style="text-align:right;min-width:0;word-break:break-word">
                        @if ($detail->user && $detail->user->anonymized_at === null)
                            <a href="{{ route('admin.members.show', $detail->user_id) }}" wire:navigate class="underline-link">{{ $detail->user->fullName() }}</a>
                        @elseif ($detail->user)
                            {{ $detail->user->fullName() }}
                        @else
                            —
                        @endif
                    </span>
                </div>

                @foreach ([
                    'Statut' => $statusLabels[$detail->status] ?? $detail->status,
                    'Tentatives' => (string) $detail->attempts,
                    'Prochaine tentative' => $detail->available_at?->copy()->setTimezone($tz)->format('d/m/Y H:i') ?? '—',
                    'Envoyé le' => $detail->sent_at?->copy()->setTimezone($tz)->format('d/m/Y H:i') ?? '—',
                ] as $label => $value)
                    <div class="flex jb g12" style="border-bottom:1px solid var(--divider);padding-bottom:8px">
                        <span class="meta" style="font-size:12px;flex-shrink:0">{{ $label }}</span>
                        <span style="text-align:right;min-width:0;word-break:break-word">{{ $value }}</span>
                    </div>
                @endforeach
                <div>
                    <div class="meta" style="font-size:12px;margin-bottom:4px">Contenu (payload)</div>
                    {{-- redactedPayload : un jeton d'invitation en clair vaut la prise du compte.
                         Masqué quel que soit le statut — sur une ligne encore en attente, il est
                         vivant. --}}
                    <pre class="card card-pad mono" style="font-size:12px;white-space:pre-wrap;overflow:auto">{{ json_encode($detail->redactedPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>

            {{-- Actions contextuelles selon le statut (§4.15.6). --}}
            <x-slot:footer>
                @if ($detail->status === 'pending')
                    <button type="button" wire:click="pushDetail" wire:loading.attr="disabled" wire:target="pushDetail" class="btn btn-primary btn-sm f1"><x-icon name="send" :size="14" /> Pousser</button>
                    <button type="button" wire:click="askCancel('detail')" class="btn btn-ghost btn-sm f1"><x-icon name="x" :size="14" /> Annuler</button>
                @elseif ($detail->status === 'failed')
                    <button type="button" wire:click="retryDetail" wire:loading.attr="disabled" wire:target="retryDetail" class="btn btn-primary btn-sm f1"><x-icon name="refresh-cw" :size="14" /> Rejouer</button>
                @endif
                <button type="button" wire:click="closeDetail" class="btn btn-ghost btn-sm">Fermer</button>
            </x-slot:footer>
        </x-dialog>
    @endif

    {{-- Dialog « Annuler des envois » — irréversible et silencieux pour les destinataires :
         dialog stylé avec portée explicite (revue UX 2026-07-11, constat n°4). --}}
    @if ($cancelConfirm)
        {{-- 520 : « Garder les envois » + « Annuler les envois » ne laissaient que 2px de marge. --}}
        <x-dialog title="Annuler les envois" danger :width="520" close="dismissCancel"
                  sub="{{ ['all' => 'Tous les envois en attente correspondant aux filtres courants.', 'selected' => $cancellableSelected.' envoi(s) en attente sur '.count($selected).' sélectionné(s).', 'detail' => 'Cet envoi uniquement.'][$cancelConfirm] }}">
            <x-banner kind="danger">
                Action irréversible : les envois annulés ne partiront jamais et les destinataires ne sont pas prévenus. Seuls les envois encore « en attente » sont concernés.
            </x-banner>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="dismissCancel">Garder les envois</button>
                <button type="button" class="btn btn-danger" wire:click="confirmCancel" wire:loading.attr="disabled" wire:target="confirmCancel">
                    <x-icon name="x" :size="14" /> Annuler les envois
                </button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
