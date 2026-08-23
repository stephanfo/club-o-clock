{{-- Enregistrement du service worker (PWA). Partagé par les layouts app ET guest : sans lui sur
     l'écran de connexion, un visiteur qui installe l'application depuis cet écran n'a aucun service
     worker avant sa première page authentifiée. Même raison d'être que partials/head-meta.

     data-navigate-once : wire:navigate ré-exécute les scripts du body à chaque swap ;
     l'enregistrement n'a de sens qu'au chargement initial. --}}
<script data-navigate-once>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
    }
</script>
