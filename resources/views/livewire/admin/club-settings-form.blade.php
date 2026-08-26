{{-- Paramètres du club (PRD §4.17) — porté de screen-admin.jsx AdminParametres.
     Réglages (fuseau, durée d'invitation) + hub des catalogues + actions de saison
     (§4.5 recalcul catégories · §4.4 suspension de masse) via SeasonService. --}}
@php
    $catalogues = [
        ['type' => 'category', 'label' => 'Catégories d’âge', 'sub' => 'Poussins, Benjamins, Cadets, Seniors…'],
        ['type' => 'quota_tag', 'label' => 'Tags de quota', 'sub' => '#piscine, #CAP, #vélo…'],
        ['type' => 'qualification', 'label' => 'Qualifications', 'sub' => 'BF5, BNSSA, PSC1…'],
        ['type' => 'discipline', 'label' => 'Disciplines', 'sub' => 'Natation, Vélo, Course, Enchaînement…'],
        ['type' => 'event_type', 'label' => 'Types d’épreuve', 'sub' => 'Triathlon, Duathlon, Trail…'],
        ['type' => 'location', 'label' => 'Lieux', 'sub' => 'Piscine Olympique, Stade Léo…'],
    ];
@endphp
<div class="form-screen">
    {{-- Feedback d'action global (revue UX 2026-07-11) : bannière flottante auto-masquée,
         flash('status') = succès (vert) · flash('warn') = refus (orange). --}}
    <x-flash-float />
    <div class="dk-topbar">
        <div class="f1">
            <div class="dsp" style="font-size:24px">Paramètres du club</div>
            <div class="meta">Catalogues éditables · identité · bascule saison</div>
        </div>
    </div>

    <div class="dk-body">
        <div class="settings-grid">
            {{-- ── Identité (édition singleton) ── --}}
            <form wire:submit="save" class="card card-pad">
                <div class="eyebrow" style="margin-bottom:12px">Identité</div>

                {{-- Logo (upload disque public) + nom éditables + nuancier disciplines (proto). --}}
                <div class="flex ac g12">
                    <label class="disc-badge" style="width:56px;height:56px;background:var(--slate-50);border:2px solid var(--brand);cursor:pointer;overflow:hidden">
                        {{-- isPreviewable() en garde : temporaryUrl() lève sur un type non
                             affichable, et l'exception casserait tout l'écran (500) au lieu de
                             laisser voir l'erreur de validation. --}}
                        @if ($logo && $logo->isPreviewable())
                            <img src="{{ $logo->temporaryUrl() }}" alt="" style="width:100%;height:100%;object-fit:cover">
                        @elseif (($logoPath ?? null))
                            <img src="{{ \App\Models\ClubSettings::current()->logoThumbUrl(128) }}" alt="" style="width:100%;height:100%;object-fit:cover">
                        @else
                            <x-icon name="image" :size="24" style="color:var(--fg-muted)" />
                        @endif
                        <input type="file" wire:model="logo" accept="image/*" style="display:none">
                    </label>
                    <div class="f1" style="min-width:0">
                        <div class="ifield"><input class="ifield-input" type="text" maxlength="255" wire:model.blur="name" placeholder="Nom du club" style="font-weight:800;font-size:16px"></div>
                        {{-- Nuancier des disciplines : --swim/--bike/--run sont des ALIAS de
                             --info/--brand/--accent (club-app.css). Il montre donc l'effet de la
                             palette éditée juste en dessous, dans l'ordre des disciplines et non
                             dans celui des champs — d'où le libellé, sans lequel ces trois carrés
                             se lisent à tort comme un aperçu de la palette elle-même. --}}
                        <label class="field-label" style="margin-top:10px">Disciplines</label>
                        <div class="flex g4">
                            @foreach (['swim' => 'Natation', 'bike' => 'Vélo', 'run' => 'Course à pied'] as $c => $label)
                                <span title="{{ $label }}" style="width:26px;height:26px;border-radius:6px;background:var(--{{ $c }});border:1px solid var(--hair)"></span>
                            @endforeach
                        </div>
                    </div>
                </div>
                @error('name')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror
                @error('logo')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror

                {{-- Baseline de l'écran de connexion. Vide = celle du produit (placeholder) : le
                     champ n'est pas prérempli, pour que « je n'ai rien saisi » reste lisible. --}}
                <label class="field-label" style="margin-top:14px">Baseline</label>
                <div class="ifield">
                    <input class="ifield-input" type="text" maxlength="120" wire:model.blur="tagline"
                           placeholder="{{ \App\Models\ClubSettings::DEFAULT_TAGLINE }}">
                </div>
                <div class="meta" style="font-size:var(--text-xs);margin-top:4px">
                    Affichée sous le nom du club sur l’écran de connexion. Laisse vide pour garder celle par défaut.
                </div>
                @error('tagline')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror

                {{-- Champs rangés dans l'ordre des DISCIPLINES (natation, vélo, CAP) pour tomber
                     en face des pastilles ci-dessus, et non dans l'ordre des rôles de la palette.
                     Le libellé garde le rôle en titre : ces couleurs ne servent pas qu'aux
                     disciplines (la principale pilote aussi la topbar et les boutons, l'accent les
                     mises en avant) — la discipline n'est qu'un repère de lecture. --}}
                <div class="eyebrow" style="margin-top:18px;margin-bottom:10px">Palette</div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px">
                    <div>
                        <label class="field-label">Info <span style="font-weight:400;text-transform:none;letter-spacing:0">· natation</span></label>
                        <div class="ifield"><input class="ifield-input" type="color" wire:model="info_color" style="padding:2px;height:38px"></div>
                    </div>
                    <div>
                        <label class="field-label">Principale <span style="font-weight:400;text-transform:none;letter-spacing:0">· vélo</span></label>
                        <div class="ifield"><input class="ifield-input" type="color" wire:model="primary_color" style="padding:2px;height:38px"></div>
                    </div>
                    <div>
                        <label class="field-label">Accent <span style="font-weight:400;text-transform:none;letter-spacing:0">· course</span></label>
                        <div class="ifield"><input class="ifield-input" type="color" wire:model="accent_color" style="padding:2px;height:38px"></div>
                    </div>
                </div>
                <div class="meta" style="font-size:12px;margin-top:6px">Laisser vide pour conserver la palette par défaut.</div>

                <label class="field-label" style="margin-top:14px">Fuseau</label>
                <select class="input" wire:model="timezone">
                    @foreach ($timezones as $tz)<option value="{{ $tz }}">{{ $tz }}</option>@endforeach
                </select>

                <label class="field-label" style="margin-top:12px">Durée du lien d'invitation (jours)</label>
                <div class="ifield"><input class="ifield-input" type="number" min="1" max="365" wire:model.blur="invitation_link_days"></div>

                <label class="field-label" style="margin-top:12px">Mois de bascule de saison</label>
                <select class="input" wire:model="season_start_month">
                    @foreach ($months as $num => $label)<option value="{{ $num }}">{{ $label }}</option>@endforeach
                </select>

                {{-- ── Icônes PWA (§4.17, cadrage §7.16) ──
                     Trois fichiers distincts, et non trois tailles d'un même rendu : les deux
                     formats du manifest sont rognés en cercle par Android (d'où la zone de
                     sécurité), l'icône iOS doit être opaque. Vide = jeu livré avec l'application,
                     de sorte qu'une instance neuve reste installable sans rien téléverser. --}}
                <label class="field-label" style="margin-top:14px">Icônes de l'application (PWA)</label>
                <div class="meta" style="font-size:12px;margin-bottom:8px">
                    PNG aux dimensions exactes. Pour les deux premières, garder le motif dans les 80&nbsp;% centraux :
                    Android les rogne en cercle. L'icône iOS n'est pas rognée (coins arrondis par le système) et son
                    fond transparent est remplacé par du blanc.
                </div>
                {{-- L'aperçu imite la forme que le SYSTÈME applique, et elle diffère : Android rogne
                     les formats `maskable` en cercle, iOS applique un squircle (carré à coins
                     arrondis) et jamais un cercle. Un aperçu uniformément rond ferait cadrer
                     l'icône iOS pour un rognage qui n'aura pas lieu. D'où le rayon par variante,
                     et non la classe `disc-badge` (border-radius:50%) réutilisée telle quelle. --}}
                <div class="flex g12" style="flex-wrap:wrap">
                    @foreach ([
                        'icon_192' => ['label' => '192×192', 'hint' => 'Android', 'radius' => '50%'],
                        'icon_512' => ['label' => '512×512', 'hint' => 'Écran de démarrage', 'radius' => '50%'],
                        'icon_apple' => ['label' => '180×180', 'hint' => 'iOS', 'radius' => 'var(--radius-lg)'],
                    ] as $variant => $meta)
                        <div style="text-align:center">
                            <label style="display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:{{ $meta['radius'] }};background:var(--slate-50);border:2px solid var(--brand);cursor:pointer;overflow:hidden">
                                {{-- isPreviewable() en garde, même raison que pour le logo. --}}
                                @if ($$variant && $$variant->isPreviewable())
                                    <img src="{{ $$variant->temporaryUrl() }}" alt="" style="width:100%;height:100%;object-fit:cover">
                                @else
                                    <img src="{{ \App\Models\ClubSettings::current()->pwaIconUrl($variant) }}" alt="" style="width:100%;height:100%;object-fit:cover">
                                @endif
                                <input type="file" wire:model="{{ $variant }}" accept="image/png" style="display:none">
                            </label>
                            <div class="meta" style="font-size:11px;margin-top:4px">{{ $meta['label'] }}</div>
                            <div class="meta" style="font-size:11px;color:var(--fg-muted)">{{ $meta['hint'] }}</div>
                        </div>
                    @endforeach
                </div>
                @error('icon_192')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror
                @error('icon_512')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror
                @error('icon_apple')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror
                @if ($pwaIconsCustomised ?? false)
                    <button type="button" class="btn btn-ghost btn-sm" style="margin-top:8px"
                            wire:click="resetPwaIcons" wire:target="resetPwaIcons" wire:loading.attr="disabled"
                            wire:confirm="Rétablir les icônes livrées avec l'application ?">
                        Rétablir les icônes par défaut
                    </button>
                @endif

                <div class="flex" style="justify-content:flex-end;margin-top:16px">
                    <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="save,logo,icon_192,icon_512,icon_apple">Enregistrer</button>
                </div>
            </form>

            {{-- ── Mentions légales (OS3) ── --}}
            {{-- Contenu propre à l'instance : saisi ici, JAMAIS dans la vue publique — un club ne
                 doit pas éditer le code pour publier ses mentions. Vide = la page affiche
                 « [À COMPLÉTER PAR LE CLUB] » + un avertissement. --}}
            <form wire:submit="save" class="card card-pad">
                <div class="eyebrow" style="margin-bottom:12px">Mentions légales</div>
                <div class="meta" style="font-size:var(--text-xs);margin-bottom:12px">
                    Publiées sur <a href="{{ route('legal') }}" target="_blank" rel="noopener">/mentions-legales</a>,
                    page accessible sans connexion. À compléter avant la mise en production.
                </div>

                <label class="field-label">Éditeur du site</label>
                <div class="ifield"><input class="ifield-input" type="text" maxlength="500" wire:model.blur="legal_publisher" placeholder="Association…, siège social…, SIRET/RNA…"></div>
                @error('legal_publisher')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror

                <label class="field-label" style="margin-top:12px">Hébergeur</label>
                <div class="ifield"><input class="ifield-input" type="text" maxlength="500" wire:model.blur="legal_host" placeholder="Nom et adresse de l'hébergeur"></div>
                @error('legal_host')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror

                <label class="field-label" style="margin-top:12px">Directeur de la publication</label>
                <div class="ifield"><input class="ifield-input" type="text" maxlength="255" wire:model.blur="legal_director" placeholder="Président·e ou personne désignée"></div>
                @error('legal_director')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror

                <label class="field-label" style="margin-top:12px">Email de contact (RGPD)</label>
                <div class="ifield"><input class="ifield-input" type="email" maxlength="255" wire:model.blur="legal_contact_email" placeholder="contact@monclub.fr"></div>
                @error('legal_contact_email')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror

                <label class="field-label" style="margin-top:12px">Fournisseur d'email transactionnel</label>
                <div class="ifield"><input class="ifield-input" type="text" maxlength="255" wire:model.blur="legal_mail_provider" placeholder="Nom du service d'envoi retenu"></div>
                @error('legal_mail_provider')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror

                {{-- AGPL-3.0 §13 : informer l'utilisateur d'un service en réseau de son droit
                     d'accès au code source de la version déployée. --}}
                <label class="field-label" style="margin-top:12px">URL du code source <span style="font-weight:400;text-transform:none;letter-spacing:0">· obligation AGPL</span></label>
                <div class="ifield"><input class="ifield-input" type="url" maxlength="255" wire:model.blur="legal_source_url" placeholder="https://github.com/…"></div>
                @error('legal_source_url')<div class="meta" style="color:var(--danger);margin-top:6px">{{ $message }}</div>@enderror

                <div class="flex" style="justify-content:flex-end;margin-top:16px">
                    <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled" wire:target="save">Enregistrer</button>
                </div>
            </form>

            {{-- ── Notifications : interrupteurs de canal (§4.17) ──
                 Persistés au clic, sans submit : ce sont des bascules, pas des champs de saisie. --}}
            <div class="card card-pad">
                <div class="eyebrow" style="margin-bottom:6px">Notifications</div>
                <div class="meta" style="font-size:12px;margin-bottom:6px;line-height:1.4">
                    Coupe un canal pour tout le club. Les préférences individuelles restent en place et
                    reprennent effet à la réactivation. Les emails porteurs d'un accès au compte ne sont
                    jamais coupés : connexion (lien magique, mot de passe oublié) et invitations
                    d'activation — sans elles, aucun nouvel adhérent ne pourrait entrer.
                </div>

                @foreach ([
                    ['push', 'Notifications push', 'Alertes sur l’appareil, application fermée (PWA installée)', $notif_push_enabled],
                    ['email', 'Notifications par email', 'Alertes du club envoyées par email', $notif_email_enabled],
                ] as [$ch, $chLabel, $chDesc, $chOn])
                    <div class="flex ac g10" style="margin-top:12px">
                        <x-toggle :on="$chOn" wire:click="toggleChannel('{{ $ch }}')"
                            wire:loading.attr="disabled" wire:target="toggleChannel" />
                        <div class="f1">
                            <div style="font-weight:700;font-size:14px">{{ $chLabel }}</div>
                            <div class="meta" style="font-size:12px">{{ $chDesc }}</div>
                        </div>
                    </div>
                @endforeach

                @if (! $notif_push_enabled && ! $notif_email_enabled)
                    <x-banner kind="warn" style="margin-top:14px">
                        Les deux canaux sont coupés : plus aucune notification ne part. Les emails de
                        connexion (lien magique) et les invitations d'activation continuent de partir —
                        ils portent l'accès au compte, pas une alerte.
                    </x-banner>
                @endif
            </div>

            {{-- ── Moyens de connexion (§4.17) ──
                 Le mot de passe n'a pas d'interrupteur : il reste la voie garantie. Couper un moyen
                 est refusé côté serveur si des comptes n'ont plus aucun autre accès (§4.1.2). --}}
            <div class="card card-pad">
                <div class="eyebrow" style="margin-bottom:6px">Moyens de connexion</div>
                <div class="meta" style="font-size:12px;margin-bottom:6px;line-height:1.4">
                    Ce que l'écran de connexion propose. La connexion par mot de passe reste toujours
                    disponible.
                </div>

                @foreach ([
                    ['magic_link', 'Lien magique', 'Connexion sans mot de passe, par lien reçu en email', $auth_magic_link_enabled],
                    ['google', 'Connexion Google', 'Nécessite un client OAuth configuré par le club', $auth_google_enabled],
                ] as [$m, $mLabel, $mDesc, $mOn])
                    <div class="flex ac g10" style="margin-top:12px">
                        <x-toggle :on="$mOn" wire:click="toggleAuthMethod('{{ $m }}')"
                            wire:loading.attr="disabled" wire:target="toggleAuthMethod" />
                        <div class="f1">
                            <div style="font-weight:700;font-size:14px">{{ $mLabel }}</div>
                            <div class="meta" style="font-size:12px">{{ $mDesc }}</div>
                        </div>
                    </div>
                @endforeach

                @if ($magicLinkOnly > 0)
                    <x-banner kind="info" style="margin-top:14px">
                        {{ $magicLinkOnly }} compte(s) actif(s) n'ont que le lien magique pour se
                        connecter (ni mot de passe, ni Google) : le couper leur retirerait tout accès,
                        la bascule sera refusée.
                    </x-banner>
                @endif

                @if ($googleMisconfigured)
                    <x-banner kind="warn" style="margin-top:14px">
                        Aucun client OAuth Google n'est configuré sur cette instance : le bouton n'est
                        pas affiché sur l'écran de connexion, même interrupteur ouvert. Voir doc/INSTALL.md.
                    </x-banner>
                @endif
            </div>

            {{-- ── Catalogues (hub vers CatalogueManager) ── --}}
            <div class="card card-pad">
                <div class="eyebrow" style="margin-bottom:12px">Catalogues</div>
                <div style="display:flex;flex-direction:column">
                    @foreach ($catalogues as $i => $c)
                        <a href="{{ route('admin.catalogues', $c['type']) }}" wire:navigate
                           class="flex ac jb row-press" style="padding:11px 4px;{{ $i < count($catalogues) - 1 ? 'border-bottom:1px solid var(--divider)' : '' }};text-decoration:none;color:inherit">
                            <div>
                                <div style="font-weight:700;font-size:14px">{{ $c['label'] }} <span class="meta" style="font-size:12px">· {{ $counts[$c['type']] ?? 0 }}</span></div>
                                <div class="meta" style="font-size:12px">{{ $c['sub'] }}</div>
                            </div>
                            <x-icon name="chevron-right" class="muted" />
                        </a>
                    @endforeach
                </div>
            </div>

            {{-- ── Actions de saison (§4.5 recalcul catégories · §4.4 suspension de masse) ── --}}
            <div class="card card-pad settings-span2" style="border-color:var(--brand-200);background:var(--brand-50)">
                <div class="notice-row">
                    <x-icon name="refresh-cw" :size="26" style="color:var(--brand-700)" />
                    <div class="f1">
                        {{-- Libellé dérivé du mois de bascule choisi ci-dessus, jamais figé : sinon
                             l'écran contredirait le réglage qu'il propose. --}}
                        <div class="eyebrow" style="color:var(--brand-700)">Année sportive · {{ $this->seasonLabel }}</div>
                        <div style="font-size:14px;margin-top:4px;line-height:1.5">Recalcule la <b>catégorie principale</b> de chaque adhérent depuis sa date de naissance et efface les surclassements manuels. <b>Les comptes restent actifs</b> et les inscriptions futures conservées.</div>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="$set('showNouvelleAnnee', true)"
                            wire:loading.attr="disabled" wire:target="$set">Démarrer…</button>
                </div>
            </div>

            <div class="card card-pad settings-span2" style="border-color:var(--warning-border);background:var(--warning-bg-soft)">
                <div class="notice-row">
                    <x-icon name="alert-triangle" :size="26" style="color:var(--warning-text)" />
                    <div class="f1">
                        <div class="eyebrow" style="color:var(--warning-text)">Bascule de saison</div>
                        <div style="font-size:14px;margin-top:4px;line-height:1.5">Désactive tous les comptes athlètes et annule leurs inscriptions futures. Action irréversible, typiquement <b>fin août</b> avant la rentrée.</div>
                    </div>
                    <button type="button" class="btn btn-sm" wire:click="openBascule"
                            wire:loading.attr="disabled" wire:target="openBascule">Basculer la saison…</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Modale : bascule de saison / suspension de masse (§4.4) ── --}}
    {{-- $impact n'est calculé par render() que modale ouverte : la garde évite un accès null. --}}
    @if ($showBascule && $impact)
        <x-dialog title="Bascule de saison" sub="Action irréversible · double validation" :danger="true" :width="440" close="$set('showBascule', false)">
            <x-banner kind="danger"><div>Tu vas <b>suspendre {{ $impact['athletes'] }} compte{{ $impact['athletes'] > 1 ? 's' : '' }} athlète</b> et <b>annuler {{ $impact['future_registrations'] }} inscription{{ $impact['future_registrations'] > 1 ? 's' : '' }} future{{ $impact['future_registrations'] > 1 ? 's' : '' }}</b>. Les comptes coach/admin ne sont pas affectés.</div></x-banner>
            <label class="field-label" style="margin-top:14px">Motif (visible dans le bandeau inscrit)</label>
            <textarea class="input" style="margin-top:6px" rows="2" wire:model.blur="basculeMotif" placeholder="Saison N — réactive ton accès depuis ton profil après réinscription."></textarea>
            {{-- Rangée cliquable (div, pas label) : un <label> enveloppant le <button> de x-check
                 propagerait le clic au bouton → double $toggle qui s'annule. Le toggle est porté
                 par la rangée ; le x-check est neutralisé aux clics (pointer-events:none) et sert
                 uniquement d'indicateur visuel. --}}
            <div style="display:flex;flex-direction:column;gap:10px;margin-top:14px">
                <div class="flex ac g10" style="font-size:14px;cursor:pointer" wire:click="$toggle('basculeCheck1')"><x-check :on="$basculeCheck1" tabindex="-1" style="pointer-events:none" /> Je comprends que {{ $impact['athletes'] }} comptes seront suspendus</div>
                <div class="flex ac g10" style="font-size:14px;cursor:pointer" wire:click="$toggle('basculeCheck2')"><x-check :on="$basculeCheck2" tabindex="-1" style="pointer-events:none" /> Je comprends que {{ $impact['future_registrations'] }} inscriptions seront annulées</div>
            </div>
            @error('bascule')<div class="meta" style="color:var(--danger);margin-top:8px">{{ $message }}</div>@enderror
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="$set('showBascule', false)">Annuler</button>
                <button type="button" class="btn btn-danger-solid{{ ($basculeCheck1 && $basculeCheck2) ? '' : ' is-disabled' }}"
                        @if ($basculeCheck1 && $basculeCheck2) wire:click="deactivateAllAthletes" @endif>Basculer la saison</button>
            </x-slot:footer>
        </x-dialog>
    @endif

    {{-- ── Modale : démarrer la nouvelle année sportive (§4.5) ── --}}
    @if ($showNouvelleAnnee && $impact)
        <x-dialog title="Démarrer la nouvelle année sportive" sub="Recalcul des catégories · {{ $this->seasonLabel }}" :width="470" close="$set('showNouvelleAnnee', false)">
            <x-banner kind="info"><div>Action <b>distincte de la bascule de saison</b> : les comptes restent <b>actifs</b> et aucune inscription n'est annulée.</div></x-banner>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:14px 0">
                <div class="card card-pad card-soft tc"><div class="num" style="font-size:30px">{{ $impact['recalculable'] }}</div><div class="meta" style="font-size:12px;margin-top:5px;line-height:1.35">catégories principales recalculées</div></div>
                <div class="card card-pad card-soft tc"><div class="num" style="font-size:30px;color:var(--accent)">{{ $impact['surclassements'] }}</div><div class="meta" style="font-size:12px;margin-top:5px;line-height:1.35">surclassements manuels effacés</div></div>
            </div>
            <div class="eyebrow" style="margin-bottom:6px">Ce qui se passe</div>
            <ul style="padding-left:18px;margin:0;font-size:14px;line-height:1.65">
                <li>Catégorie principale recalculée depuis la <b>date de naissance</b> et l'année sportive (<b>{{ $this->seasonLabel }}</b>).</li>
                <li>Les <b>surclassements manuels</b> sont effacés — à re-saisir au besoin sur chaque fiche.</li>
                <li>Les <b>inscriptions futures</b> déjà prises sont conservées (<em>grandfathered</em>).</li>
            </ul>
            <x-banner kind="green" style="margin-top:12px"><div>Confirmation simple, sans suspension de comptes. Réversible adhérent par adhérent via les fiches.</div></x-banner>
            <x-slot:footer>
                <button type="button" class="btn btn-ghost" wire:click="$set('showNouvelleAnnee', false)">Annuler</button>
                <button type="button" class="btn btn-primary" wire:click="startNewSeason"><x-icon name="refresh-cw" :size="15" /> Démarrer</button>
            </x-slot:footer>
        </x-dialog>
    @endif
</div>
