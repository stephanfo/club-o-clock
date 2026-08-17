{{-- Surcharge de palette d'instance (plan open source OS2) : générée depuis les couleurs
     personnalisées en admin (primary/accent/info). Vide par défaut → palette neutre de
     tokens.css inchangée. Doit rester APRÈS @vite pour l'emporter sur tokens.css. --}}
@php($settings = \App\Models\ClubSettings::current())
@php($paletteCss = \App\Support\ClubPalette::overrideCss($settings))
@if ($paletteCss)
    <style>{!! $paletteCss !!}</style>
@endif

{{-- Filigrane de logo (topbar mobile + héros « prochaine séance ») : générique, basé sur le
     logo du club (custom ou logo neutre par défaut) plutôt que sur la fleur hibiscus figée du
     design d'origine — cf. .topbar::before / .fiche-screen .fiche-head-m::before / .home-hero-m
     ::before dans app.css. Toujours défini (logo par défaut si le club n'a rien uploadé). --}}
{{-- Vignette 128 px, pas l'original : ce filigrane est rendu à 150-170 px et 12 % d'opacité, or
     l'upload autorise 2 Mo — servir la pleine résolution ferait payer ce poids sur CHAQUE écran
     mobile, exactement ce que les vignettes de ClubBrandingService existent pour éviter. --}}
<style>:root { --club-logo-url: url('{{ $settings->logoThumbUrl(128) }}'); }</style>
