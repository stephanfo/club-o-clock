<?php

namespace Tests\Feature;

use App\Models\ClubSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Mémoïsation par requête du singleton ClubSettings (NF §4.10.1 « temps quasi-constant ») :
// current() ne doit toucher la DB qu'une fois, et une mutation doit invalider le cache.
class ClubSettingsCacheTest extends TestCase
{
    use RefreshDatabase;

    private function countSelects(callable $fn): int
    {
        $hits = 0;
        DB::listen(function ($q) use (&$hits) {
            if (str_contains($q->sql, 'club_settings') && str_starts_with(ltrim($q->sql), 'select')) {
                $hits++;
            }
        });
        $fn();

        return $hits;
    }

    public function test_repeated_current_hits_db_once(): void
    {
        ClubSettings::current(); // amorce (création éventuelle du singleton)

        $selects = $this->countSelects(function () {
            for ($i = 0; $i < 10; $i++) {
                ClubSettings::current();
            }
        });

        $this->assertSame(0, $selects, '10 appels après amorçage ne doivent produire aucun SELECT.');
    }

    public function test_first_current_reads_once(): void
    {
        ClubSettings::flushCache();

        $selects = $this->countSelects(fn () => ClubSettings::current());

        $this->assertSame(1, $selects);
    }

    public function test_saving_invalidates_cache(): void
    {
        $settings = ClubSettings::current();
        $settings->update(['name' => 'Tri Club']); // saved → flushCache

        $selects = $this->countSelects(fn () => $fresh = ClubSettings::current());

        $this->assertSame(1, $selects, 'Une mutation doit périmer le cache : la relecture refait 1 SELECT.');
        $this->assertSame('Tri Club', ClubSettings::current()->name);
    }
}
