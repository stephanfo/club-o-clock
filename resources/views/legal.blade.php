@extends('layouts.guest')

{{-- Page publique mentions légales + confidentialité (plan open source OS3).
     Les faits techniques (données traitées, flux sortants) sont écrits ici : ils décrivent le
     CODE, identique pour toute instance. Ce qui identifie l'exploitant vient de ClubSettings et se
     saisit dans Admin → Paramètres du club → Mentions légales (revue open source, constat n°11) :
     un club n'a jamais à éditer ce fichier, son fork ne diverge donc pas. --}}
@php
    // Valeur saisie par le club, ou marqueur explicite si la section n'a pas encore été remplie.
    $legal = fn (?string $value, string $hint) => filled($value)
        ? e($value)
        : '<b>[À COMPLÉTER PAR LE CLUB]</b> — '.e($hint);
@endphp
@section('content')
<div style="max-width:760px;margin:0 auto;padding:var(--space-8) var(--space-5) var(--space-16)">
    {{-- Motif de retour imposé (CLAUDE.md, resources/js/back.js) : window.clubBack() d'abord, href
         de repli si l'historique ne permet pas de revenir. JAMAIS wire:navigate ici — il naviguerait
         dès mousedown, donc avant l'onclick, et le repli partirait toujours. url()->previous()
         serait faux en accès direct (Referer absent, nouvel onglet, réglage de confidentialité). --}}
    <a href="{{ route('home') }}" onclick="return !window.clubBack?.()" class="auth-fine">&larr; Retour</a>

    <div class="dsp" style="font-size:var(--text-2xl);margin-top:var(--space-5)">Mentions légales &amp; confidentialité</div>

    @if ($settings->legalNoticeIncomplete())
        <div class="card card-pad" style="margin-top:var(--space-5);border-style:dashed">
            <p class="meta">
                Certaines sections ne sont pas encore renseignées. Un administrateur du club les
                complète dans <b>Paramètres du club → Mentions légales</b> — sans modifier le code
                source. À faire <b>avant la mise en production</b>.
            </p>
        </div>
    @endif

    <h3 style="margin-top:var(--space-8)">Éditeur du site</h3>
    <p>
        {!! $legal($settings->legal_publisher, "nom de l'association, adresse du siège social, numéro SIRET/RNA.") !!}
    </p>

    <h3 style="margin-top:var(--space-6)">Hébergement</h3>
    <p>
        {!! $legal($settings->legal_host, "nom et adresse de l'hébergeur du serveur sur lequel l'application est installée.") !!}
    </p>

    <h3 style="margin-top:var(--space-6)">Directeur de la publication</h3>
    <p>{!! $legal($settings->legal_director, 'président·e ou personne désignée.') !!}</p>

    <h3 style="margin-top:var(--space-6)">Données personnelles traitées</h3>
    <p>
        L'application traite les données nécessaires à la gestion du planning d'entraînement et
        des inscriptions aux séances : identité (nom, prénom, email), rôle au sein du club,
        historique de participation, et, pour les mineurs, l'identité du ou des représentants
        légaux. Aucun numéro de téléphone ni certificat médical n'est stocké par l'application.
    </p>
    <p>
        Les comptes des mineurs sont créés et gérés par un représentant légal jusqu'à leur
        autonomisation (invitation dédiée). Les données sont conservées le temps de l'adhésion
        au club, avec un délai de grâce de 7 jours après une demande de suppression de compte
        avant anonymisation définitive.
    </p>

    <h3 style="margin-top:var(--space-6)">Flux réseau sortants</h3>
    <p>
        Pour fonctionner, une instance de l'application peut échanger avec les services tiers
        suivants (documentés en détail dans le README du projet, section « Flux réseau
        sortants ») :
    </p>
    <ul>
        <li><b>Open-Meteo</b> (prévisions météo des séances) — service européen, gratuit, sans clé.</li>
        <li><b>Nominatim / OpenStreetMap</b> (géocodage d'une adresse saisie par l'admin lors de la création d'un lieu) — service européen.</li>
        <li><b>Service d'envoi d'email transactionnel</b> (notifications, liens de connexion) — {!! $legal($settings->legal_mail_provider, 'nom du fournisseur retenu.') !!}</li>
        <li><b>Web Push (VAPID)</b> — notifications navigateur, sans intermédiaire tiers (protocole standard, pas de service commercial).</li>
        <li><b>Google OAuth</b> — uniquement si le club active la connexion via Google (optionnelle).</li>
    </ul>

    <h3 style="margin-top:var(--space-6)">Droits des personnes concernées</h3>
    <p>
        Conformément au RGPD, chaque adhérent (ou son représentant légal pour un mineur) dispose
        d'un droit d'accès, de rectification et de suppression de ses données, exerçable depuis
        son profil dans l'application ou en contactant
        @if (filled($settings->legal_contact_email))
            <a href="mailto:{{ $settings->legal_contact_email }}">{{ $settings->legal_contact_email }}</a>.
        @else
            {!! $legal(null, 'email de contact du club.') !!}
        @endif
    </p>

    <h3 style="margin-top:var(--space-6)">Cookies</h3>
    <p>
        L'application utilise uniquement un cookie de session technique, strictement nécessaire
        à la connexion. Aucun cookie de mesure d'audience ou publicitaire n'est déposé.
    </p>

    {{-- Mention du logiciel : au-delà du crédit, l'AGPL-3.0 impose d'informer l'utilisateur d'un
         service en réseau de son droit d'accès au code source (§13). --}}
    <h3 style="margin-top:var(--space-6)">Logiciel</h3>
    <p>
        {{-- Baseline du PRODUIT (constante), pas celle du club : cette mention identifie le
             logiciel, elle ne doit pas suivre la personnalisation d'instance. Référencée plutôt
             que recopiée pour ne pas figer ici un slogan que le produit ferait évoluer. --}}
        Ce site fonctionne avec <b>Club'O'Clock</b> — <i>{{ \App\Models\ClubSettings::DEFAULT_TAGLINE }}</i>
        — un logiciel libre de gestion du planning d'entraînement pour club de
        triathlon, distribué sous licence
        <a href="https://www.gnu.org/licenses/agpl-3.0.html" rel="noopener" target="_blank">AGPL-3.0</a>.
    </p>
    <p>
        Conformément à cette licence, le code source de la version déployée ici est mis à
        disposition de toute personne qui utilise ce service :
        @if (filled($settings->legal_source_url))
            <a href="{{ $settings->legal_source_url }}" rel="noopener" target="_blank">{{ $settings->legal_source_url }}</a>.
        @else
            {!! $legal(null, 'adresse du dépôt public, ou adresse à laquelle en faire la demande.') !!}
        @endif
    </p>
</div>
@endsection
