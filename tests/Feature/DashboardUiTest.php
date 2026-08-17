<?php

namespace Tests\Feature;

use App\Livewire\Admin\Dashboard;
use App\Models\Discipline;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// Dashboard bureau J6.6 — câblage Livewire : rendu, filtres, export XLSX, garde admin (PRD §4.16).
class DashboardUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 6, 20, 12));
        Discipline::create(['label' => 'Natation', 'sort_order' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_renders_for_admin(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertOk()
            ->assertSee('Dashboard admin')
            ->assertSee('taux de remplissage')
            ->assertSee('Activité coachs');
    }

    public function test_non_admin_is_forbidden(): void
    {
        Livewire::actingAs(User::factory()->create()) // athlète simple
            ->test(Dashboard::class)
            ->assertForbidden();
    }

    public function test_period_and_filters_are_settable(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->assertSet('period', 'season')
            ->call('setPeriod', '90d')
            ->assertSet('period', '90d')
            ->call('setPeriod', 'bidon') // valeur invalide ignorée
            ->assertSet('period', '90d');
    }

    public function test_export_returns_xlsx_download(): void
    {
        Livewire::actingAs(User::factory()->admin()->create())
            ->test(Dashboard::class)
            ->call('export')
            ->assertFileDownloaded('stats-bureau-2026-06-20.xlsx');
    }
}
