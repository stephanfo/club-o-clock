<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\User;
use App\Services\ConsentService;
use App\Support\Trombi\ConsentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

// Registre des accords du trombinoscope (#72, PRD §4.23.1, §4.23.4, cadrage §7.18).
class TrombiConsentTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ConsentService
    {
        return app(ConsentService::class);
    }

    private function minorOf(User $guardian, bool $withAccount = false): User
    {
        $minor = User::factory()->minorP1()->create(['guardian_id' => $guardian->id]);
        if ($withAccount) {
            $minor->update(['email' => 'ado'.$minor->id.'@exemple.test']);
        }

        return $minor->fresh();
    }

    public function test_un_adherent_accorde_pour_lui_meme_une_seule_ligne_active(): void
    {
        $u = User::factory()->create();

        $this->service()->grant($u, ConsentType::Photo, $u);
        $this->service()->grant($u, ConsentType::Photo, $u);

        $this->assertTrue($u->fresh()->hasConsent(ConsentType::Photo));
        $this->assertFalse($u->fresh()->hasConsent(ConsentType::Email));
        $row = Consent::sole();
        $this->assertSame($u->id, $row->granted_by);
        $this->assertSame(ConsentType::Photo->version(), $row->version);
    }

    public function test_personne_n_accorde_pour_un_autre_majeur_pas_meme_l_admin_dans_l_app(): void
    {
        $u = User::factory()->create();
        $other = User::factory()->create();
        $admin = User::factory()->admin()->create();

        foreach ([$other, $admin] as $actor) {
            try {
                $this->service()->grant($u, ConsentType::Photo, $actor);
                $this->fail('Accord pour un tiers accepté.');
            } catch (RuntimeException) {
            }
            $this->assertFalse($this->service()->canRevoke($actor, $u));
        }
        $this->assertSame(0, Consent::count());
    }

    public function test_retirer_garde_la_ligne_et_reaccorder_en_cree_une_nouvelle(): void
    {
        $u = User::factory()->create();
        $this->service()->grant($u, ConsentType::Birthday, $u);
        $this->service()->revoke($u, ConsentType::Birthday, $u);

        $this->assertFalse($u->fresh()->hasConsent(ConsentType::Birthday));
        $this->assertNotNull(Consent::sole()->revoked_at);

        $this->service()->grant($u, ConsentType::Birthday, $u);
        $this->assertSame(2, Consent::count());
        $this->assertTrue($u->fresh()->hasConsent(ConsentType::Birthday));
    }

    public function test_le_telephone_exige_un_numero(): void
    {
        $u = User::factory()->create();

        try {
            $this->service()->grant($u, ConsentType::Phone, $u);
            $this->fail('Accord téléphone sans numéro accepté.');
        } catch (RuntimeException) {
        }

        $u->forceFill(['phone' => '+33612345678'])->save();
        $this->service()->grant($u, ConsentType::Phone, $u);
        $this->assertTrue($u->fresh()->hasConsent(ConsentType::Phone));
    }

    public function test_tout_allume_ce_qui_s_applique_au_meme_instant_et_eteint_tout(): void
    {
        $u = User::factory()->create();
        Carbon::setTestNow('2026-11-02 10:00:00');

        $changed = $this->service()->setAll($u, true, $u);

        $this->assertCount(5, $changed);
        $this->assertNotContains(ConsentType::Phone, $changed, 'Téléphone sans numéro : laissé éteint.');
        $this->assertSame(1, Consent::query()->distinct()->count('granted_at'));

        $this->service()->setAll($u, false, $u);
        $this->assertSame(0, Consent::query()->whereNull('revoked_at')->count());
        $this->assertSame(5, Consent::count(), 'Les lignes restent comme preuve.');
    }

    public function test_le_parent_garant_accorde_pour_son_enfant_sans_coordonnees(): void
    {
        $parent = User::factory()->create();
        $child = $this->minorOf($parent);

        $this->service()->grant($child, ConsentType::Photo, $parent);
        $this->assertTrue($child->fresh()->hasConsent(ConsentType::Photo));

        $child->forceFill(['phone' => '+33612345678'])->save();
        foreach ([ConsentType::Email, ConsentType::Phone] as $type) {
            try {
                $this->service()->grant($child, $type, $parent);
                $this->fail("Coordonnée {$type->value} accordée pour un mineur.");
            } catch (RuntimeException) {
            }
        }

        // « Tout » : nom complet, catégorie, anniversaire (la photo l'était déjà), jamais de coordonnées.
        $this->assertCount(3, $this->service()->setAll($child, true, $parent));
        $this->assertFalse($child->fresh()->hasConsent(ConsentType::Email));
    }

    public function test_l_ado_retire_mais_n_accorde_pas_et_un_mineur_sans_garant_non_plus(): void
    {
        $parent = User::factory()->create();
        $ado = $this->minorOf($parent, withAccount: true);
        $this->service()->grant($ado, ConsentType::Photo, $parent);

        $this->assertFalse($this->service()->canGrant($ado, $ado));
        $this->service()->revoke($ado, ConsentType::Photo, $ado);
        $this->assertFalse($ado->fresh()->hasConsent(ConsentType::Photo));

        $alone = User::factory()->minorP1()->create(['email' => 'seul@exemple.test']);
        $this->assertFalse($this->service()->canGrant($alone, $alone));
        $this->assertTrue($this->service()->canRevoke($alone, $alone));

        // Un autre adulte n'est pas son garant.
        $this->assertFalse($this->service()->canGrant(User::factory()->create(), $ado));
    }

    public function test_un_accord_d_une_ancienne_version_n_est_pas_actif_et_se_ferme_au_nouvel_accord(): void
    {
        $u = User::factory()->create();
        Consent::create([
            'user_id' => $u->id, 'type' => ConsentType::Photo, 'version' => 0,
            'granted_at' => Carbon::now()->subYear(), 'granted_by' => $u->id,
        ]);

        $this->assertFalse($u->fresh()->hasConsent(ConsentType::Photo));

        $this->service()->grant($u, ConsentType::Photo, $u);
        $this->assertSame(1, Consent::query()->whereNull('revoked_at')->count());
        $this->assertTrue($u->fresh()->hasConsent(ConsentType::Photo));
    }

    public function test_revoquer_les_versions_perimees(): void
    {
        $u = User::factory()->create();
        Consent::create([
            'user_id' => $u->id, 'type' => ConsentType::Email, 'version' => 0,
            'granted_at' => Carbon::now()->subYear(), 'granted_by' => $u->id,
        ]);
        $this->service()->grant($u, ConsentType::Photo, $u);

        $this->assertSame(1, $this->service()->revokeOutdated(ConsentType::Email));
        $this->assertSame(0, $this->service()->revokeOutdated(ConsentType::Photo));
        $this->assertTrue($u->fresh()->hasConsent(ConsentType::Photo));
    }

    public function test_l_admin_enregistre_un_accord_avec_source_trace(): void
    {
        $u = User::factory()->create();
        $admin = User::factory()->admin()->create();

        try {
            $this->service()->recordByAdmin($u, [ConsentType::Photo], $admin, '   ');
            $this->fail('Enregistrement sans source accepté.');
        } catch (RuntimeException) {
        }
        try {
            $this->service()->recordByAdmin($u, [ConsentType::Photo], User::factory()->coach()->create(), 'Bulletin');
            $this->fail('Enregistrement par un non-admin accepté.');
        } catch (RuntimeException) {
        }
        $this->assertSame(0, Consent::count());

        $recorded = $this->service()->recordByAdmin($u, [ConsentType::Photo, ConsentType::Email], $admin, 'Bulletin d\'adhésion 2027');

        $this->assertSame([ConsentType::Photo, ConsentType::Email], $recorded);
        $row = Consent::query()->where('type', ConsentType::Photo->value)->sole();
        $this->assertSame($admin->id, $row->granted_by);
        $this->assertSame('Bulletin d\'adhésion 2027', $row->source);
        $audit = AuditLog::query()->where('action', 'consent_recorded_by_admin')->sole();
        $this->assertSame($u->id, $audit->target_id);
    }

    public function test_l_admin_n_enregistre_pas_de_coordonnees_pour_un_mineur(): void
    {
        $child = $this->minorOf(User::factory()->create());

        $this->expectException(RuntimeException::class);
        try {
            $this->service()->recordByAdmin($child, [ConsentType::Photo, ConsentType::Email], User::factory()->admin()->create(), 'Bulletin');
        } finally {
            $this->assertSame(0, Consent::count(), 'Rien d\'écrit : la transaction est annulée en entier.');
        }
    }

    public function test_l_admin_retire_un_accord_trace(): void
    {
        $u = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->service()->grant($u, ConsentType::Email, $u);

        $this->service()->revokeByAdmin($u, ConsentType::Email, $admin);

        $this->assertFalse($u->fresh()->hasConsent(ConsentType::Email));
        $this->assertSame($admin->id, Consent::sole()->revoked_by);
        $this->assertSame(1, AuditLog::query()->where('action', 'consent_revoked_by_admin')->count());

        // Rien à retirer : pas de trace.
        $this->service()->revokeByAdmin($u, ConsentType::Email, $admin);
        $this->assertSame(1, AuditLog::query()->where('action', 'consent_revoked_by_admin')->count());
    }

    public function test_les_accords_venus_d_un_autre_tombent_ceux_de_l_interesse_restent(): void
    {
        $parent = User::factory()->create();
        $ado = $this->minorOf($parent, withAccount: true);
        $this->service()->grant($ado, ConsentType::Photo, $parent);
        $this->service()->recordByAdmin($ado, [ConsentType::Birthday], User::factory()->admin()->create(), 'Bulletin');
        // L'ado devenu majeur s'accorde lui-même son nom complet.
        $ado->update(['dob' => Carbon::now()->subYears(19)->toDateString()]);
        $this->service()->grant($ado, ConsentType::FullName, $ado);

        $this->assertSame(2, $this->service()->revokeGrantedByOthers($ado, $parent));

        $fresh = $ado->fresh();
        $this->assertTrue($fresh->hasConsent(ConsentType::FullName));
        $this->assertFalse($fresh->hasConsent(ConsentType::Photo));
        $this->assertFalse($fresh->hasConsent(ConsentType::Birthday));
    }
}
