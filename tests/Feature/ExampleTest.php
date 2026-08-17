<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    // La racine « / » ne sert plus de page d'accueil (welcome supprimé) : elle redirige le visiteur
    // vers la connexion, l'utilisateur authentifié vers son tableau de bord (cf. routes/web.php).
    public function test_root_redirects_guest_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
