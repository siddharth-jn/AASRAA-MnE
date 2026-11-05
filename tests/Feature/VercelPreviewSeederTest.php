<?php

namespace Tests\Feature;

use App\Models\ApprovalAction;
use App\Models\Centre;
use App\Models\CentreDonor;
use App\Models\EntryRevision;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\TataIndicatorService;
use Database\Seeders\VercelPreviewSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VercelPreviewSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_complete_synthetic_preview_with_the_tata_catalogue(): void
    {
        $this->seed(VercelPreviewSeeder::class);

        $tataCodes = collect(app(TataIndicatorService::class)->specifications())->pluck('code');

        $this->assertSame(13, IndicatorDefinition::query()->whereIn('code', $tataCodes)->count());
        $this->assertSame(41, IndicatorAssignment::query()->whereHas('indicator', fn ($query) => $query->whereIn('code', $tataCodes))->count());
        $this->assertSame(492, IndicatorTarget::query()->whereHas('assignment.indicator', fn ($query) => $query->whereIn('code', $tataCodes))->count());
        $this->assertSame(0, IndicatorEntry::query()->whereHas('assignment.indicator', fn ($query) => $query->whereIn('code', $tataCodes))->count());
        $this->assertSame(13, CentreDonor::query()->whereHas('donor', fn ($query) => $query->where('code', 'TATA'))->count());
        $this->assertSame(11, Programme::query()->count());
        $this->assertSame(11, Project::query()->where('active', true)->count());
        $this->assertSame(33, Centre::query()->where('active', true)->count());
        $this->assertSame(66, IndicatorDefinition::query()->where('code', 'like', 'P%-I%')->count());
        $this->assertSame(384, IndicatorAssignment::query()->whereHas('indicator', fn ($query) => $query->where('code', 'like', 'P%-I%'))->count());
        $this->assertSame(2304, IndicatorTarget::query()->whereHas('assignment.indicator', fn ($query) => $query->where('code', 'like', 'P%-I%'))->count());
        $this->assertSame(2196, IndicatorEntry::query()->whereHas('assignment.indicator', fn ($query) => $query->where('code', 'like', 'P%-I%'))->count());
        $this->assertSame(2196, EntryRevision::query()->whereHas('entry.assignment.indicator', fn ($query) => $query->where('code', 'like', 'P%-I%'))->count());
        $this->assertSame(2100, ApprovalAction::query()->whereHas('revision.entry.assignment.indicator', fn ($query) => $query->where('code', 'like', 'P%-I%'))->count());

        foreach (config('impact.roles') as $role) {
            $this->assertTrue(User::query()->where('role', $role)->exists(), "Missing preview account for {$role}.");
        }

        $this->assertDatabaseHas('users', [
            'name' => 'Sandeep Preview Administrator',
            'email' => 'sandeep@aasra.demo',
            'role' => 'ADMIN',
            'active' => true,
            'is_demo' => true,
        ]);

        $this->assertDatabaseHas('indicator_definitions', [
            'code' => 'KPI-SQEP-FLN',
            'name' => '% of students of classes 3 - 5 in 6 government schools (SQEP) achieving FLN competencies in Hindi & Maths',
            'reporting_scope' => 'programme',
            'is_demo' => true,
        ]);
        $this->assertDatabaseHas('indicator_definitions', [
            'code' => 'P01-I03',
            'name' => 'Literacy Sessions Conducted',
            'time_aggregation' => 'SUM',
            'is_demo' => true,
        ]);

        $countsAfterFirstSeed = [
            User::count(),
            IndicatorDefinition::count(),
            IndicatorAssignment::count(),
            IndicatorTarget::count(),
            IndicatorEntry::count(),
            EntryRevision::count(),
            ApprovalAction::count(),
        ];

        $this->seed(VercelPreviewSeeder::class);

        $this->assertSame($countsAfterFirstSeed, [
            User::count(),
            IndicatorDefinition::count(),
            IndicatorAssignment::count(),
            IndicatorTarget::count(),
            IndicatorEntry::count(),
            EntryRevision::count(),
            ApprovalAction::count(),
        ]);
    }

    public function test_preview_initialization_command_is_repeatable(): void
    {
        $this->artisan('impact:initialize-vercel-preview')->assertSuccessful();
        $this->artisan('impact:initialize-vercel-preview')->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'sandeep@aasra.demo',
            'role' => 'ADMIN',
            'active' => true,
        ]);
        $this->assertSame(13, IndicatorDefinition::query()
            ->whereIn('code', collect(app(TataIndicatorService::class)->specifications())->pluck('code'))
            ->count());
    }

    public function test_preview_initialization_preserves_an_existing_database(): void
    {
        User::factory()->create([
            'email' => 'administrator@aasraatrust.org',
            'role' => 'ADMIN',
            'active' => true,
            'is_demo' => false,
        ]);

        $this->artisan('impact:initialize-vercel-preview')->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame(0, Programme::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'sandeep@aasra.demo']);
    }

    public function test_preview_initialization_does_not_restore_changes_after_initial_setup(): void
    {
        $this->seed(VercelPreviewSeeder::class);

        Programme::query()->where('name', 'Street Smart')->update(['name' => 'Street Smart - Reviewed']);
        IndicatorTarget::query()->whereHas('assignment.indicator', fn ($query) => $query->where('code', 'P01-I01'))->firstOrFail()->delete();

        $this->seed(VercelPreviewSeeder::class);

        $this->assertDatabaseHas('programmes', ['name' => 'Street Smart - Reviewed']);
        $this->assertDatabaseMissing('programmes', ['name' => 'Street Smart']);
        $this->assertSame(2303, IndicatorTarget::query()->whereHas('assignment.indicator', fn ($query) => $query->where('code', 'like', 'P%-I%'))->count());
    }

    public function test_preview_reports_render_before_any_achievement_is_entered(): void
    {
        $this->seed(VercelPreviewSeeder::class);

        $administrator = User::query()->where('email', 'sandeep@aasra.demo')->firstOrFail();

        $this->actingAs($administrator)
            ->get(route('reports.index'))
            ->assertOk()
            ->assertSee('Target and approved performance report')
            ->assertSee('Wings of Doon')
            ->assertSee('Outreach');

        $this->actingAs($administrator)
            ->get(route('reports.index', ['anchor' => '2026-09']))
            ->assertOk()
            ->assertSee('Target and approved performance report')
            ->assertSee('Literacy Sessions Conducted')
            ->assertSee('Meals Distributed');
    }
}
