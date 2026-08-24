<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lignes de langue d'authentification
    |--------------------------------------------------------------------------
    |
    | Messages émis par le garde et par Fortify (AttemptToAuthenticate,
    | LockoutResponse). Source de vérité des clés :
    | vendor/laravel/framework/.../lang/en/auth.php.
    |
    | Sans ce fichier, l'application n'ayant que `lang/fr/validation.php` et
    | tournant en locale `fr` avec `fr` en repli, ces clés n'étaient traduites
    | par personne : l'écran de connexion affichait littéralement « auth.failed »
    | à qui se trompait de mot de passe.
    |
    | Formulation NEUTRE sur l'existence du compte, comme le lien magique
    | (§4.1.1) : « ces identifiants » ne dit pas si c'est l'adresse ou le mot de
    | passe qui est faux, donc l'écran ne sert pas d'oracle d'énumération.
    |
    */

    'failed' => 'Ces identifiants ne correspondent à aucun compte.',
    'password' => 'Mot de passe incorrect.',
    'throttle' => 'Trop de tentatives de connexion. Réessaie dans :seconds secondes.',

];
