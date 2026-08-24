<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lignes de langue de réinitialisation de mot de passe
    |--------------------------------------------------------------------------
    |
    | Messages du password broker, rendus tels quels par les écrans Fortify
    | (`forgot-password`, `reset-password`). Source de vérité des clés :
    | vendor/laravel/framework/.../lang/en/passwords.php.
    |
    | `user` est volontairement MUET sur l'existence du compte. Le message
    | anglais d'origine (« We can't find a user with that email address »)
    | confirmerait à qui le demande qu'une adresse est — ou n'est pas — inscrite
    | au club, ce que le lien magique se donne justement du mal à taire (§4.1.1).
    | Un refus sans cause nommée couvre aussi bien l'adresse inconnue que le
    | compte fermé ou anonymisé.
    |
    */

    'reset' => 'Ton mot de passe a été réinitialisé.',
    'sent' => 'Si un compte existe pour cette adresse, un lien de réinitialisation vient de partir.',
    'throttled' => 'Un lien vient déjà d’être envoyé — patiente une minute avant de réessayer.',
    'token' => 'Ce lien de réinitialisation est invalide ou expiré. Redemande-en un.',
    'user' => 'Impossible d’envoyer un lien de réinitialisation pour cette adresse.',

];
