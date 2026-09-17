{{-- Abonnement agenda (#39, PRD §4.21.2) — bloc de l'onglet Notifs. Aucune adresse tant que
     l'adhérent n'en a pas demandé ; révocation en confirmation niveau 1 (on régénère). --}}
<div>
    <div class="sect-head"><span class="sect-title">Mon agenda</span></div>

    @if ($feed === null)
        <div class="card card-pad card-soft">
            <div style="font-weight:700;font-size:14px">Abonner mon agenda à mes séances</div>
            <div class="meta" style="font-size:12px;margin-top:2px;line-height:1.4">
                Une adresse personnelle que Google Agenda, Apple Calendrier ou Outlook relisent d'eux-mêmes :
                tes inscriptions y apparaissent et suivent les changements.
            </div>
            <button type="button" class="btn btn-primary btn-block" style="margin-top:12px"
                wire:click="regenerateFeed" wire:loading.attr="disabled" wire:target="regenerateFeed">
                <x-icon name="calendar" :size="15" /> Créer mon adresse d'abonnement
            </button>
        </div>
    @else
        @php
            $feedUrl = $feed->url();
            $webcal = preg_replace('#^https?://#', 'webcal://', $feedUrl);
        @endphp
        <div class="card card-pad card-soft" style="display:flex;flex-direction:column;gap:12px">
            <div x-data="{ copie: false }">
                <label class="eyebrow" for="agenda-url" style="font-size:10px">Adresse d'abonnement</label>
                <div class="ifield" style="margin-top:6px">
                    <input id="agenda-url" class="ifield-input" type="text" readonly value="{{ $feedUrl }}"
                        x-ref="url" x-on:focus="$event.target.select()">
                    <button type="button" class="btn btn-ghost btn-sm"
                        x-on:click="const champ = $refs.url;
                            const repli = () => { champ.select(); return document.execCommand('copy') || Promise.reject(); };
                            (navigator.clipboard ? navigator.clipboard.writeText(champ.value) : Promise.reject())
                                .catch(repli)
                                .then(() => { copie = true; setTimeout(() => copie = false, 2000); })
                                // Échec des deux voies (iOS hors geste utilisateur) : le champ reste
                                // sélectionné pour une copie à la main — ne jamais annoncer une copie qui
                                // n'a pas eu lieu.
                                .catch(() => { champ.select(); })">
                        <x-icon name="link" :size="14" /> <span x-text="copie ? 'Copiée' : 'Copier'">Copier</span>
                    </button>
                </div>
                <div class="meta" style="font-size:12px;margin-top:6px;line-height:1.4">
                    Garde-la pour toi : quiconque la connaît voit tes séances.
                </div>
            </div>

            <div class="flex g6" style="flex-wrap:wrap">
                <a class="btn btn-ghost btn-sm" target="_blank" rel="noopener"
                    href="https://calendar.google.com/calendar/render?cid={{ urlencode($webcal) }}">
                    <x-icon name="calendar-days" :size="14" /> Ajouter à Google Agenda
                </a>
                <a class="btn btn-ghost btn-sm" href="{{ $webcal }}">
                    <x-icon name="calendar" :size="14" /> Ouvrir dans Apple Calendrier
                </a>
            </div>

            <x-banner kind="info">
                Un agenda abonné se met à jour avec retard — jusqu'à 24 h chez Google. Ce n'est pas un canal
                d'alerte : les notifications restent là pour les changements de dernière minute.
            </x-banner>

            <div>
                <div class="eyebrow" style="font-size:10px;margin-bottom:6px">Contenu</div>
                <div class="flex ac g10" style="padding:6px 0">
                    <x-toggle :on="true" disabled style="opacity:.45" />
                    <div class="f1" style="font-size:14px;font-weight:600">Mes inscriptions <span class="meta" style="font-size:12px;font-weight:400">— toujours incluses</span></div>
                </div>
                <div class="flex ac g10" style="padding:6px 0">
                    <x-toggle :on="$feed->include_waitlist" wire:click="toggleFeedSource('include_waitlist')" />
                    <div class="f1" style="font-size:14px;font-weight:600">Mes places en liste d'attente</div>
                </div>
                @if ($user->hasRole('coach'))
                    <div class="flex ac g10" style="padding:6px 0">
                        <x-toggle :on="$feed->include_coaching" wire:click="toggleFeedSource('include_coaching')" />
                        <div class="f1" style="font-size:14px;font-weight:600">Les séances que j'encadre</div>
                    </div>
                @endif
                @if ($feedHasWards)
                    <div class="flex ac g10" style="padding:6px 0">
                        <x-toggle :on="$feed->include_wards" wire:click="toggleFeedSource('include_wards')" />
                        <div class="f1" style="font-size:14px;font-weight:600">Les séances de mes enfants</div>
                    </div>
                @endif
            </div>

            <div>
                <div class="eyebrow" style="font-size:10px;margin-bottom:6px">Rappel</div>
                <div class="seg" style="display:flex;flex-wrap:wrap">
                    @foreach (\App\Models\CalendarFeed::RAPPELS as $valeur => $libelle)
                        <button type="button" class="seg-item{{ (string) $feed->reminder === (string) $valeur ? ' on' : '' }}" style="flex:1"
                            wire:click="setFeedReminder('{{ $valeur }}')">{{ $libelle }}</button>
                    @endforeach
                </div>
                <div class="meta" style="font-size:12px;margin-top:6px;line-height:1.4">
                    Apple Calendrier honore ce rappel ; Google Agenda l'ignore sur un agenda abonné.
                </div>
            </div>

            <div class="flex g6" style="flex-wrap:wrap">
                <button type="button" class="btn btn-ghost btn-sm" wire:click="regenerateFeed"
                    wire:confirm="Créer une nouvelle adresse ? L'ancienne cessera de fonctionner et devra être remplacée dans ton agenda."
                    wire:loading.attr="disabled" wire:target="regenerateFeed">
                    <x-icon name="refresh-cw" :size="14" /> Nouvelle adresse
                </button>
                <button type="button" class="btn btn-ghost btn-sm" wire:click="revokeFeed"
                    wire:confirm="Révoquer le lien ? Ton agenda ne recevra plus les séances. Tu pourras en créer un nouveau."
                    wire:loading.attr="disabled" wire:target="revokeFeed">
                    <x-icon name="x" :size="14" /> Révoquer le lien
                </button>
            </div>
        </div>
    @endif
</div>
