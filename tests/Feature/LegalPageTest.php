<?php

namespace Tests\Feature;

use Tests\TestCase;

// Mentions légales & confidentialité (plan open source OS3) : page publique, accessible sans
// connexion (RGPD, données de mineurs — un visiteur doit pouvoir la lire avant de s'inscrire).
class LegalPageTest extends TestCase
{
    public function test_legal_page_is_accessible_to_a_guest(): void
    {
        $this->get(route('legal'))->assertOk();
    }
}
