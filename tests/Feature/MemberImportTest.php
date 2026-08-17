<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberList;
use App\Models\Category;
use App\Models\User;
use App\Services\MemberImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

// Import CSV adhérents J6.5 (PRD §3.1, §4.2) : parsing, validation tout-ou-rien, classification
// création/màj par email, dérivation catégorie depuis le DOB, lien garant en deux passes.
class MemberImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Horloge figée : la dérivation de catégorie/minorité dépend de l'année sportive (sept→août).
        Carbon::setTestNow(Carbon::create(2026, 6, 20, 12));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedCategories(): void
    {
        Category::create(['label' => 'Poussin', 'age_min' => 8, 'age_max' => 13, 'sort_order' => 1]);
        Category::create(['label' => 'Cadet', 'age_min' => 14, 'age_max' => 17, 'sort_order' => 2]);
        Category::create(['label' => 'Sénior', 'age_min' => 18, 'age_max' => 39, 'sort_order' => 3]);
        Category::create(['label' => 'Master', 'age_min' => 40, 'age_max' => 99, 'sort_order' => 4]);
    }

    private function service(): MemberImportService
    {
        return app(MemberImportService::class);
    }

    public function test_clean_file_classifies_new_and_update_by_email(): void
    {
        $this->seedCategories();
        User::factory()->create(['email' => 'alice@club.fr', 'first_name' => 'Alice', 'last_name' => 'Ancien', 'dob' => '1992-04-12']);

        $csv = <<<'CSV'
        nom,prénom,email,catégorie,date_nais,parent_email
        Durand,Alice,alice@club.fr,Sénior,1992-04-12,
        Pereira,Léa,lea@club.fr,Sénior,1990-01-01,
        Fortin,Pierre,pierre@club.fr,Master,1980-05-05,
        Fortin,Hugo,,Poussin,2014-09-03,pierre@club.fr
        Fortin,Manon,manon@club.fr,Cadet,2010-11-24,pierre@club.fr
        CSV;

        $report = $this->service()->analyze($csv);

        $this->assertNull($report['fatal']);
        $this->assertSame([], $report['errors']);
        $this->assertSame(5, $report['total']);
        $this->assertSame(4, $report['new']);   // Léa, Pierre, Hugo, Manon
        $this->assertSame(1, $report['update']); // Alice (email déjà connu)
    }

    public function test_commit_creates_updates_and_links_guardians(): void
    {
        $this->seedCategories();
        $admin = User::factory()->admin()->create();
        $existing = User::factory()->create(['email' => 'alice@club.fr', 'first_name' => 'Alice', 'last_name' => 'Ancien', 'dob' => '1995-01-01']);

        $csv = <<<'CSV'
        nom,prénom,email,catégorie,date_nais,parent_email
        Durand,Alice,alice@club.fr,Sénior,1992-04-12,
        Fortin,Pierre,pierre@club.fr,Master,1980-05-05,
        Fortin,Hugo,,Poussin,2014-09-03,pierre@club.fr
        Fortin,Manon,manon@club.fr,Cadet,2010-11-24,pierre@club.fr
        CSV;

        $result = $this->service()->commit($this->service()->analyze($csv), $admin);

        $this->assertSame(3, $result['created']);
        $this->assertSame(1, $result['updated']);

        // Mise à jour : nom + DOB rafraîchis sur le compte existant (matché par email).
        $existing->refresh();
        $this->assertSame('Durand', $existing->last_name);
        $this->assertSame('1992-04-12', $existing->dob->toDateString());

        $pierre = User::where('email', 'pierre@club.fr')->firstOrFail();

        // Mineur P1 sans email, garant = parent du CSV (résolu en 2e passe).
        $hugo = User::where('first_name', 'Hugo')->firstOrFail();
        $this->assertNull($hugo->email);
        $this->assertTrue($hugo->is_minor);
        $this->assertSame($pierre->id, $hugo->guardian_id);
        // Catégorie dérivée du DOB (Poussin 8-13), pas de la colonne CSV.
        $this->assertSame('Poussin', $hugo->categories()->wherePivot('is_primary', true)->first()->label);

        // Mineur P2 avec email, même garant.
        $manon = User::where('first_name', 'Manon')->firstOrFail();
        $this->assertSame('manon@club.fr', $manon->email);
        $this->assertSame($pierre->id, $manon->guardian_id);
    }

    public function test_french_date_format_is_accepted(): void
    {
        $this->seedCategories();
        $admin = User::factory()->admin()->create();

        // JJ/MM/AAAA (saisie FR courante) doit être accepté au même titre que AAAA-MM-JJ.
        $csv = "nom,prénom,email,catégorie,date_nais,parent_email\nBlanc,Sophie,sophie@club.fr,Sénior,07/02/1991,";

        $report = $this->service()->analyze($csv);
        $this->assertSame([], $report['errors']);
        $this->assertSame(1, $report['new']);

        $this->service()->commit($report, $admin);
        $this->assertSame('1991-02-07', User::where('email', 'sophie@club.fr')->firstOrFail()->dob->toDateString());
    }

    public function test_update_writes_audit_log(): void
    {
        $this->seedCategories();
        $admin = User::factory()->admin()->create();
        $existing = User::factory()->create(['email' => 'maj@club.fr', 'last_name' => 'Avant', 'dob' => '1990-01-01']);

        $csv = "nom,prénom,email,catégorie,date_nais,parent_email\nApres,Marc,maj@club.fr,Sénior,1988-08-08,";
        $this->service()->commit($this->service()->analyze($csv), $admin);

        // Acte sensible (identité) tracé en AuditLog, survit à l'anonymisation.
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'member_updated',
            'target_id' => $existing->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_minor_without_parent_email_is_created_without_guardian(): void
    {
        $this->seedCategories();
        $admin = User::factory()->admin()->create();

        $csv = "nom,prénom,email,catégorie,date_nais,parent_email\nSeul,Tom,,Poussin,2015-01-01,";

        $report = $this->service()->analyze($csv);
        $this->assertSame([], $report['errors']);

        $this->service()->commit($report, $admin);
        $tom = User::where('first_name', 'Tom')->firstOrFail();
        $this->assertTrue($tom->is_minor);
        $this->assertNull($tom->guardian_id);
    }

    public function test_all_or_nothing_blocks_on_any_error(): void
    {
        $this->seedCategories();
        $admin = User::factory()->admin()->create();

        $csv = <<<'CSV'
        nom,prénom,email,catégorie,date_nais,parent_email
        Bon,Valide,valide@club.fr,Sénior,1990-01-01,
        Mauvais,Date,bad@club.fr,Sénior,not-a-date,
        Adulte,SansMail,,Sénior,1985-01-01,
        Enfant,Orphelin,,Poussin,2015-01-01,inconnu@club.fr
        CSV;

        $report = $this->service()->analyze($csv);

        $this->assertNotEmpty($report['errors']);
        $messages = implode(' | ', array_column($report['errors'], 'message'));
        $this->assertStringContainsString('date de naissance invalide', $messages);
        $this->assertStringContainsString('email requis pour un adulte', $messages);
        $this->assertStringContainsString('garant introuvable', $messages);

        // Tout ou rien : le commit refuse, aucun compte (même la ligne valide) n'est créé.
        $this->expectException(RuntimeException::class);
        try {
            $this->service()->commit($report, $admin);
        } finally {
            $this->assertDatabaseMissing('users', ['email' => 'valide@club.fr']);
        }
    }

    public function test_duplicate_email_within_file_is_error(): void
    {
        $this->seedCategories();

        $csv = <<<'CSV'
        nom,prénom,email,catégorie,date_nais,parent_email
        Un,Premier,clone@club.fr,Sénior,1990-01-01,
        Deux,Second,clone@club.fr,Sénior,1991-01-01,
        CSV;

        $report = $this->service()->analyze($csv);

        $this->assertNotEmpty($report['errors']);
        $this->assertStringContainsString('en double dans le fichier', $report['errors'][0]['message']);
    }

    public function test_guardian_pointing_to_a_minor_row_is_unresolvable(): void
    {
        $this->seedCategories();

        // Le « parent » référencé est lui-même un mineur du CSV → ne peut pas être garant.
        $csv = <<<'CSV'
        nom,prénom,email,catégorie,date_nais,parent_email
        Faux,Parent,faux@club.fr,Poussin,2014-01-01,
        Vrai,Enfant,,Poussin,2015-01-01,faux@club.fr
        CSV;

        $report = $this->service()->analyze($csv);

        $messages = implode(' | ', array_column($report['errors'], 'message'));
        $this->assertStringContainsString('garant introuvable', $messages);
    }

    public function test_missing_required_header_is_fatal(): void
    {
        $csv = "prénom,email,catégorie,parent_email\nLéa,lea@club.fr,Sénior,";
        $report = $this->service()->analyze($csv);

        $this->assertNotNull($report['fatal']);
        $this->assertStringContainsString('nom', $report['fatal']);
        $this->assertStringContainsString('date_nais', $report['fatal']);
    }

    public function test_semicolon_delimiter_is_detected(): void
    {
        $this->seedCategories();
        $csv = "nom;prénom;email;catégorie;date_nais;parent_email\nDupont;Marie;marie@club.fr;Sénior;1990-06-01;";

        $report = $this->service()->analyze($csv);
        $this->assertNull($report['fatal']);
        $this->assertSame(1, $report['new']);
    }

    public function test_livewire_upload_then_import(): void
    {
        $this->seedCategories();
        $admin = User::factory()->admin()->create();

        $csv = "nom,prénom,email,catégorie,date_nais,parent_email\nMartin,Paul,paul@club.fr,Sénior,1988-03-03,";
        $file = UploadedFile::fake()->createWithContent('adherents.csv', $csv);

        Livewire::actingAs($admin)
            ->test(MemberList::class)
            ->call('openImport')
            ->assertSet('showImport', true)
            ->set('csvFile', $file)
            ->assertSet('importReport.new', 1)
            ->assertSet('importReport.errors', [])
            ->call('import')
            ->assertSet('showImport', false);

        $this->assertDatabaseHas('users', ['email' => 'paul@club.fr', 'first_name' => 'Paul']);
    }
}
