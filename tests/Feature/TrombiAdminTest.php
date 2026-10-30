<?php

namespace Tests\Feature;

use App\Livewire\Admin\MemberTrombi;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Notifications\NotificationRenderer;
use App\Notifications\NotificationType;
use App\Services\ConsentService;
use App\Services\TrombiService;
use App\Support\Trombi\BoardRole;
use App\Support\Trombi\ConsentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

// Maintenance du trombinoscope par l'admin (#72, PRD §4.23.1, §4.23.3, §4.17.1).
class TrombiAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->admin()->create();
    }

    public function test_reserve_a_l_admin(): void
    {
        $u = User::factory()->create();

        Livewire::actingAs(User::factory()->coach()->create())->test(MemberTrombi::class, ['memberId' => $u->id])->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.members.show', $u).'?tab=trombi')->assertOk();
    }

    public function test_fonction_au_bureau_tracee(): void
    {
        $u = User::factory()->create();

        Livewire::actingAs($this->admin)->test(MemberTrombi::class, ['memberId' => $u->id])
            ->set('boardRole', BoardRole::VicePresident->value)->call('saveBoardRole')
            ->set('boardRole', 'roi')->call('saveBoardRole');

        $this->assertSame(BoardRole::VicePresident, $u->fresh()->board_role);
        $this->assertSame(1, AuditLog::query()->where('action', 'board_role_changed')->where('target_id', $u->id)->count());

        Livewire::actingAs($this->admin)->test(MemberTrombi::class, ['memberId' => $u->id])
            ->set('boardRole', '')->call('saveBoardRole');
        $this->assertNull($u->fresh()->board_role);
    }

    public function test_retirer_un_accord_a_la_demande(): void
    {
        $u = User::factory()->create();
        app(ConsentService::class)->grant($u, ConsentType::Email, $u);

        Livewire::actingAs($this->admin)->test(MemberTrombi::class, ['memberId' => $u->id])
            ->assertSee('actif')->call('revokeConsent', ConsentType::Email->value);

        $this->assertFalse($u->fresh()->hasConsent(ConsentType::Email));
        $this->assertSame(1, AuditLog::query()->where('action', 'consent_revoked_by_admin')->count());
    }

    public function test_enregistrer_exige_source_et_accuse_de_reception_puis_previent(): void
    {
        $u = User::factory()->create(['email' => 'paul@exemple.test']);
        $c = Livewire::actingAs($this->admin)->test(MemberTrombi::class, ['memberId' => $u->id]);

        $c->set('recordTypes', [ConsentType::Photo->value])->call('openRecord')->assertHasErrors('recordSource')->assertSet('confirmingRecord', false);

        $c->set('recordSource', 'Bulletin d\'adhésion 2027')->call('openRecord')->assertSet('confirmingRecord', true)->assertSet('recordCheck', false);
        $c->call('recordConsents');
        $this->assertSame(0, Consent::count(), 'Sans accusé de réception, rien n\'est écrit.');

        $c->set('recordCheck', true)->call('recordConsents')->assertSet('confirmingRecord', false);

        $row = Consent::sole();
        $this->assertSame('Bulletin d\'adhésion 2027', $row->source);
        $this->assertSame($this->admin->id, $row->granted_by);
        $line = NotificationOutbox::query()->where('type', NotificationType::ConsentRecorded->value)->where('user_id', $u->id)->first();
        $this->assertNotNull($line);
        $rendered = app(NotificationRenderer::class)->render($line);
        $this->assertStringContainsString('Photo d\'après « Bulletin d\'adhésion 2027 »', $rendered['body']);
        $this->assertSame(route('profil', ['tab' => 'trombi']), $rendered['url']);
    }

    public function test_pour_un_mineur_le_parent_est_prevenu_et_aucune_coordonnee(): void
    {
        $parent = User::factory()->create(['roles' => []]);
        $child = User::factory()->minorP1()->create(['guardian_id' => $parent->id, 'first_name' => 'Lou']);

        Livewire::actingAs($this->admin)->test(MemberTrombi::class, ['memberId' => $child->id])
            ->assertDontSee(ConsentType::Email->label())
            ->set('recordTypes', [ConsentType::Email->value])->set('recordSource', 'Bulletin')
            ->call('openRecord')->set('recordCheck', true)->call('recordConsents');
        $this->assertSame(0, Consent::count());

        Livewire::actingAs($this->admin)->test(MemberTrombi::class, ['memberId' => $child->id])
            ->set('recordTypes', [ConsentType::Photo->value, ConsentType::Birthday->value])->set('recordSource', 'Bulletin')
            ->call('openRecord')->set('recordCheck', true)->call('recordConsents');

        $this->assertTrue($child->fresh()->hasConsent(ConsentType::Birthday));
        $line = NotificationOutbox::query()->where('type', NotificationType::ConsentRecorded->value)->where('user_id', $parent->id)->first();
        $this->assertNotNull($line);
        $rendered = app(NotificationRenderer::class)->render($line);
        $this->assertStringStartsWith('Lou · ', $rendered['title']);
        $this->assertSame(route('children.trombi', $child->id), $rendered['url']);
    }

    public function test_l_admin_ne_voit_ni_le_numero_ni_la_photo_masquee_et_modere(): void
    {
        $u = User::factory()->create();
        app(TrombiService::class)->setPhone($u, '0612345678', $u);
        $img = imagecreatetruecolor(40, 40);
        $file = tempnam(sys_get_temp_dir(), 'trombi');
        imagejpeg($img, $file);
        app(TrombiService::class)->storePhoto($u, $file, $u);

        Livewire::actingAs($this->admin)->test(MemberTrombi::class, ['memberId' => $u->id])
            ->assertSee('Photo : déposée')->assertSee('Téléphone : saisi')
            ->assertDontSee('12 34 56 78')->assertDontSee('trombi-photo')
            ->set('confirmingPhotoRemoval', true)->call('removePhoto');

        $this->assertNull($u->fresh()->photo_path);
        $this->assertSame(1, AuditLog::query()->where('action', 'trombi_photo_removed')->count());
    }
}
