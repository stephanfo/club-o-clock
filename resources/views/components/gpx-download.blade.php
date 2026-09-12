{{-- Bouton de téléchargement d'un GPX, partagé par la fiche parcours et l'onglet Parcours d'une séance.

     Un simple `<a download>` NE SUFFIT PAS en PWA iOS installée : WebKit ignore l'attribut, présente
     le fichier en aperçu plein écran et n'offre aucun moyen d'en sortir (#44, vérifié sur iPhone).
     Le composant Alpine `gpxDownload` détecte ce seul contexte et y substitue la feuille de partage
     du système, qui, elle, a un bouton Annuler. Ailleurs il ne fait rien et le lien natif s'applique :
     d'où le href et le download conservés tels quels, qui restent la voie normale et le repli. --}}
@props(['route'])
<a class="btn btn-ghost btn-block"
   href="{{ route('gpx-routes.gpx', $route) }}"
   download="{{ $route->downloadFilename() }}"
   x-data="gpxDownload({ url: '{{ route('gpx-routes.gpx', $route) }}', name: '{{ $route->downloadFilename() }}' })"
   x-on:click="partager($event)">
    <x-icon name="download" :size="15" /> Télécharger le GPX
    @if ($route->gpx_size_ko)<span class="meta" style="font-size:12px;margin-left:4px">· {{ $route->gpx_size_ko }} Ko</span>@endif
</a>
