<?php

namespace Tests\Feature;

use App\Models\ClubSettings;
use App\Models\Registration;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Invariants de cohérence du jeu de démo (DemoSeeder). Le seeder mélange des inscriptions passant
 * par RegistrationService (garde catégorielle §4.5 appliquée) et des écritures directes
 * (Registration::firstOrCreate) qui la court-circuitent. Ce test verrouille l'invariant : aucune
 * inscription active de démo ne doit violer le ciblage catégoriel de sa séance.
 */
class DemoSeederIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Revue open source 2026-08-08, constat n°9 — le seeder écrasait inconditionnellement nom et
     * palette (TEAM44, indigo/ambre/cyan), alors que tout le reste du fichier est additif et qu'il
     * est documenté comme rejouable. Un re-run sur une instance de démo ou de recette où l'admin
     * avait personnalisé son identité la remplaçait sans avertissement.
     */
    public function test_le_seeder_ne_remplace_pas_une_identite_deja_personnalisee(): void
    {
        $this->seed(CatalogSeeder::class);

        ClubSettings::current()->update(['name' => 'Mon Club', 'primary_color' => '#123456']);
        ClubSettings::flushCache();

        $this->seed(DemoSeeder::class);

        $settings = ClubSettings::current()->fresh();
        $this->assertSame('Mon Club', $settings->name);
        $this->assertSame('#123456', $settings->primary_color);
    }

    /** Sur une identité encore neutre, le jeu de démo pose bien la sienne (comportement attendu). */
    public function test_le_seeder_pose_son_identite_sur_une_base_neutre(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame('TEAM44', ClubSettings::current()->fresh()->name);
    }

    public function test_aucune_inscription_active_ne_viole_le_ciblage_categoriel(): void
    {
        $this->seed(CatalogSeeder::class); // qualifications/disciplines de référence attendues par DemoSeeder.
        $this->seed(DemoSeeder::class);

        $active = Registration::query()
            ->whereIn('status', ['participating', 'waitlist'])
            ->with(['user.categories', 'session.categories'])
            ->get();

        $this->assertNotEmpty($active, 'Le seeder devrait produire des inscriptions actives.');

        $violations = $active->filter(function (Registration $reg) {
            $session = $reg->session;
            $user = $reg->user;

            // Séance sans ciblage = ouverte à toutes les catégories : jamais une violation.
            if ($session->categories->isEmpty()) {
                return false;
            }

            // Une inscription active est légitime si l'athlète est ciblé par la séance (au moins une
            // catégorie active en commun). isTargetedBy() est la logique métier de référence (§4.5).
            return ! $user->isTargetedBy($session);
        });

        $this->assertTrue(
            $violations->isEmpty(),
            'Inscriptions démo hors catégorie ciblée : '.$violations->map(
                fn (Registration $r) => "{$r->user->first_name} {$r->user->last_name} → «{$r->session->title}»"
            )->implode(', ')
        );
    }

    /**
     * L'écran de connexion de la démo propose des comptes en dur (auth/partials/demo-accounts).
     * Rien ne reliait cette liste au seeder : un email retouché d'un côté et pas de l'autre offrait
     * au visiteur un identifiant qui ne connecte à rien — l'échec le plus coûteux d'une démo, dès
     * le premier geste. Le test relie enfin la vitrine aux données.
     */
    /**
     * Tout athlète du jeu de démo ayant une date de naissance doit porter sa catégorie principale
     * dérivée (§4.5). Sans elle, hasActiveCategory() est faux et le compte ne peut s'inscrire à
     * AUCUNE séance : la fiche propose des actions que le serveur refuse (CATEGORY_MISMATCH).
     * Constaté sur mathieu@demo.club (coach+athlète), créé hors de la boucle des athlètes, ainsi
     * que sur florence/brigitte/gilles/daniel. Formulé génériquement pour couvrir les ajouts futurs.
     */
    public function test_tout_athlete_avec_une_dob_porte_sa_categorie_principale(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(DemoSeeder::class);

        $sansCategorie = User::query()
            ->whereNotNull('dob')
            ->with('categories')
            ->get()
            ->filter(fn (User $u) => $u->hasRole('athlete') && $u->primaryCategory() === null)
            ->map(fn (User $u) => $u->email ?? $u->fullName())
            ->values()
            ->all();

        $this->assertSame([], $sansCategorie,
            'Athlètes de démo sans catégorie principale : '.implode(', ', $sansCategorie));
    }

    public function test_les_comptes_proposes_a_la_connexion_existent_tous(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(DemoSeeder::class);

        foreach (['admin', 'vincent', 'marie', 'olivier', 'sandrine'] as $handle) {
            $user = User::where('email', "{$handle}@demo.club")->first();

            $this->assertNotNull($user, "Compte proposé à la connexion mais absent du seeder : {$handle}@demo.club");
            $this->assertTrue(
                Hash::check('password', (string) $user->password),
                "Le compte {$handle}@demo.club ne se connecte pas avec le mot de passe annoncé."
            );
        }
    }

    /**
     * Sandrine est le seul compte de démonstration à porter DEUX pupilles à des niveaux d'autonomie
     * différents (P1 sans compte, P2 avec le sien) tout en étant athlète : c'est ce qui justifie sa
     * place sur l'écran de connexion à côté du garant « pur ». Si le seeder perdait l'un des deux
     * cas, le compte n'aurait plus rien à démontrer et la vitrine mentirait en silence.
     */
    public function test_sandrine_porte_bien_les_deux_niveaux_de_tutelle(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(DemoSeeder::class);

        $sandrine = User::where('email', 'sandrine@demo.club')->firstOrFail();
        $wards = $sandrine->wards; // relation de référence (§4.2), plutôt qu'un where() sur guardian_id

        $this->assertCount(2, $wards, 'Sandrine doit avoir exactement 2 pupilles.');
        $this->assertCount(1, $wards->whereNull('email'), 'Il manque le pupille P1 (mineur sans compte propre).');
        $this->assertCount(1, $wards->whereNotNull('email'), 'Il manque le pupille P2 (mineur avec son compte).');

        // Être garant est une RELATION, pas un rôle : Sandrine s'entraîne comme les autres, et
        // c'est précisément ce que le compte donne à voir.
        $this->assertTrue($sandrine->hasRole('athlete'), 'Sandrine doit rester athlète du club.');
    }

    /**
     * `isActive = false` est réservé au tampon de suppression (§4.3) — la bascule de saison a son
     * propre drapeau, séparé (§4.4). Un compte seedé hors de ce cadre fabriquait un état que
     * l'application ne sait ni produire ni défaire : la liste comme la fiche l'affichaient
     * « ● actif » (leurs pastilles se dérivent de la suspension et de la suppression, jamais de
     * isActive) alors que les quatre voies de connexion le refusaient. Constaté sur
     * brigitte@demo.club, formulé sur tout le jeu pour couvrir les ajouts futurs.
     */
    public function test_aucun_compte_inactif_hors_tampon_de_suppression(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(DemoSeeder::class);

        $incoherents = User::query()
            ->where('is_active', false)
            ->whereNull('deletion_requested_at')
            ->whereNull('anonymized_at')
            ->pluck('email')
            ->all();

        $this->assertSame([], $incoherents,
            'Comptes inactifs sans demande de suppression (état invisible dans l\'admin) : '
            .implode(', ', $incoherents));

        // Contrôle positif : le tampon RGPD, lui, pose bien isActive=false — sans quoi l'assertion
        // ci-dessus passerait aussi sur un jeu où plus personne n'est inactif.
        $this->assertFalse(
            (bool) User::where('email', 'gilles@demo.club')->firstOrFail()->is_active,
            'Gilles (suppression demandée) doit être inactif : c\'est le seul cas légitime.'
        );
    }
}
