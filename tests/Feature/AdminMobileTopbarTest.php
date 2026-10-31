<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// #151 — Administration au téléphone, le socle : le dk-topbar est masqué sous 768px, chaque écran
// admin pose donc sa topbar mobile (titre, retour, action principale), sans quoi il n'a ni titre ni
// retour, et ses actions disparaissent.
class AdminMobileTopbarTest extends TestCase
{
    use RefreshDatabase;

    /** Écran => [titre, aria-label du retour, action attendue ou null]. */
    private function ecrans(User $membre): array
    {
        return [
            route('admin.dashboard') => ['Dashboard admin', 'Retour au club', null],
            route('admin.members') => ['Adhérents', 'Retour au club', 'Ajouter un adhérent'],
            route('admin.members.create') => ['Ajouter un adhérent', 'Retour aux adhérents', "Créer l'adhérent"],
            route('admin.members.show', $membre) => [$membre->fullName(), 'Retour aux adhérents', null],
            route('admin.templates') => ['Modèles', 'Retour au club', 'Nouveau modèle'],
            route('admin.templates.create') => ['Nouveau modèle', 'Retour aux modèles', 'Générer &amp; enregistrer'],
            route('admin.journal') => ['Journaux', 'Retour au club', null],
            route('admin.outbox') => ['Envois', 'Retour au club', 'Annuler tous les en attente'],
            route('admin.infos') => ['Pages d&#039;info', 'Retour au club', 'Nouvelle page'],
            route('admin.infos.create') => ['Nouvelle page d’info', 'Retour aux pages', 'Enregistrer'],
            route('admin.settings') => ['Paramètres du club', 'Retour au club', null],
            route('admin.catalogues', 'allure_zone') => ['Zones d’allure course', 'Retour aux paramètres', 'Ajouter'],
            route('admin.allure-levels') => ['Allures cibles', 'Retour aux paramètres', 'Enregistrer'],
        ];
    }

    public function test_chaque_ecran_admin_a_sa_topbar_mobile(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        $membre = User::factory()->create(['first_name' => 'Léa', 'last_name' => 'Pereira']);

        foreach ($this->ecrans($membre) as $url => [$titre, $retour, $action]) {
            $r = $this->actingAs($admin)->get($url)->assertOk();
            $topbar = $this->topbar($r->getContent(), $url);
            $this->assertStringContainsString('<div class="topbar-title">'.$titre.'</div>', $topbar, $url);
            $this->assertStringContainsString('aria-label="'.$retour.'"', $topbar, $url);
            if ($action !== null) {
                $this->assertStringContainsString('aria-label="'.$action.'"', $topbar, $url);
            }
            // Tout bouton serveur de la topbar est protégé du double tap (CLAUDE.md, CTA serveur).
            preg_match_all('/<button\b[^>]*>/', $topbar, $boutons);
            foreach ($boutons[0] as $bouton) {
                $this->assertStringContainsString('wire:loading.attr="disabled"', $bouton, "{$url} : {$bouton}");
                $this->assertStringContainsString('wire:target=', $bouton, "{$url} : {$bouton}");
            }
        }
    }

    public function test_les_listes_atteintes_apres_enregistrement_visent_le_hub(): void
    {
        // Pages d'info et modèles : on y revient par redirection navigate après un enregistrement ;
        // sans cible, le chevron rouvrirait le formulaire laissé dans la pile.
        $admin = User::factory()->create(['roles' => ['admin']]);

        foreach ([route('admin.infos'), route('admin.templates')] as $url) {
            $topbar = $this->topbar($this->actingAs($admin)->get($url)->getContent(), $url);
            $this->assertStringContainsString('window.clubBack?.(&#039;'.route('club').'&#039;)', $topbar, $url);
        }
        // Contrôle : un écran sans redirection d'arrivée garde le retour arrière ordinaire.
        $journal = $this->topbar($this->actingAs($admin)->get(route('admin.journal'))->getContent(), 'journal');
        $this->assertStringContainsString('window.clubBack?.()', $journal);
    }

    public function test_l_import_csv_et_l_export_restent_au_bureau(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);

        $liste = $this->actingAs($admin)->get(route('admin.members'))->getContent();
        $this->assertStringContainsString('Import CSV', $liste);
        $this->assertStringNotContainsString('openImport', $this->topbar($liste, 'adhérents'));

        $journal = $this->actingAs($admin)->get(route('admin.journal'))->getContent();
        $this->assertStringContainsString('wire:click="export"', $journal);
        $this->assertStringNotContainsString('export', $this->topbar($journal, 'journal'));
    }

    public function test_l_invitation_groupee_n_apparait_que_s_il_reste_des_comptes_en_attente(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        $topbar = fn () => $this->topbar($this->actingAs($admin)->get(route('admin.members'))->getContent(), 'adhérents');

        $this->assertStringContainsString('aria-label="Ajouter un adhérent"', $topbar());
        $this->assertStringNotContainsString('confirmBulkInvite', $topbar());

        User::factory()->create(['email' => 'jamais@club.test', 'password' => null]);
        $this->assertStringContainsString('aria-label="Inviter 1 en attente"', $topbar());
    }

    public function test_l_onglet_club_reste_allume_dans_l_administration(): void
    {
        $admin = User::factory()->create(['roles' => ['admin']]);
        $onglet = 'href="'.route('club').'" wire:navigate class="botnav-item on"';

        $this->actingAs($admin)->get(route('admin.members'))->assertSee($onglet, false);
        $this->actingAs($admin)->get(route('admin.settings'))->assertSee($onglet, false);
        $this->actingAs($admin)->get(route('planning'))->assertDontSee($onglet, false);
    }

    public function test_un_non_admin_n_y_accede_toujours_pas(): void
    {
        $this->actingAs(User::factory()->create(['roles' => ['coach']]))
            ->get(route('admin.members'))->assertForbidden();
    }

    /** Le bloc .topbar de la page (topbar mobile), pour ne pas confondre avec le dk-topbar. */
    private function topbar(string $html, string $ecran): string
    {
        $debut = strpos($html, '<div class="topbar">');
        $this->assertNotFalse($debut, "pas de topbar mobile : {$ecran}");
        $fin = strpos($html, '<div class="dk-topbar', $debut);

        return substr($html, $debut, $fin === false ? null : $fin - $debut);
    }
}
