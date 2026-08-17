{{-- Panneau héros gauche (desktop) — porté de screen-auth.jsx AuthDesktop.
     Décor hibiscus retiré par défaut (plan open source OS2) : aucun équivalent générique fourni
     pour une instance neutre ; un club peut le réintroduire via son propre habillage. --}}
@php($club = \App\Models\ClubSettings::current())
<div class="auth-dk-left fondu">
    <div style="position:relative;z-index:1;height:100%;display:flex;flex-direction:column">
        <x-logo dark />
        <div style="flex:1;display:flex;flex-direction:column;justify-content:center">
            <div class="eyebrow" style="color:var(--brand-200)">{{ $club->name }} · Espace adhérent·e</div>
            {{-- Accroche = la baseline éditable en admin (Paramètres du club), qui retombe sur
                 celle du produit tant que le club n'a rien saisi. Le design de référence portait
                 ici « Trois disciplines, un seul club. » en dur — accroche fausse pour un club qui
                 ne fait pas de triathlon.
                 Deux écarts assumés par rapport à `.dsp` brut, imposés par un texte de longueur
                 libre : `text-transform:none` (le club écrit sa casse, et 49 caractères capitalisés
                 hurlent), et une taille FLUIDE via clamp() plutôt que 60px fixes — une phrase
                 longue déborderait du panneau, qui ne fait que ~432px utiles. --}}
            <div class="dsp"
                 style="font-size:clamp(34px, 4.2vw, 56px);text-transform:none;color:var(--paper);margin-top:10px;line-height:0.98;text-wrap:balance">
                {{ $club->effectiveTagline() }}
            </div>
            <div style="font-size:15px;color:var(--fg-on-dark-soft);margin-top:18px;max-width:320px">
                Inscriptions aux séances, quotas, alertes et vie du club — au même endroit.
            </div>
        </div>
        {{-- Le design de référence affiche ici la ville du club. Aucun champ de localisation
             n'existe dans ClubSettings : à la place, un rappel neutre de ce que fait l'app. --}}
        <div class="flex ac g8" style="color:var(--fg-on-dark-faint);font-size:var(--text-xs)">
            <x-icon name="calendar" :size="14" /> Planning, inscriptions et alertes du club
        </div>
    </div>
</div>
