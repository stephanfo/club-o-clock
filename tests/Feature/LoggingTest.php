<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Logging\ActivityLogger;
use App\Support\Logging\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_logger_snapshots_actor_role(): void
    {
        $admin = User::factory()->admin()->create();

        $log = AuditLogger::record('category_archived', $admin, ['target_type' => 'Category', 'target_id' => 1]);

        $this->assertSame('admin', $log->actor_role);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category_archived', 'target_id' => 1]);
    }

    public function test_activity_logger_marks_system_actor(): void
    {
        $log = ActivityLogger::system('auto_promoted_self_quota', ['resulting_status' => 'participating']);

        $this->assertNull($log->actor_id);
        $this->assertTrue($log->actor_is_system);
        $this->assertSame('participating', $log->resulting_status);
    }
}
