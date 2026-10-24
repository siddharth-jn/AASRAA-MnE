<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\AuthoritativeDataTransitionService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthoritativeDataTransitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_preserves_authoritative_projects_and_removes_synthetic_workflow_data(): void
    {
        Storage::fake('local');
        $this->seed(DemoSeeder::class);

        $realUser = User::create([
            'name' => 'Operational administrator',
            'email' => 'operations@example.org',
            'password' => 'password',
            'role' => 'ADMIN',
            'active' => true,
            'is_demo' => false,
        ]);
        $streetSmart = Project::query()->where('name', 'Street Smart')->firstOrFail();
        $authoritativeProject = Centre::create([
            'source_project_id' => 68,
            'code' => 'ORG-68',
            'name' => 'CBLC ISBT (SS)',
            'type' => 'Education Centre',
            'region' => 'Not specified',
            'source_program' => 'Street Smart',
            'source_status' => 'Active',
            'active' => true,
            'is_demo' => false,
        ]);
        $authoritativeProject->projects()->attach($streetSmart, [
            'is_demo' => false,
            'provenance' => 'organisation database programme mapping',
        ]);
        $realUser->projects()->attach($streetSmart, ['is_demo' => false]);
        $realUser->centres()->attach(Centre::query()->where('is_demo', true)->firstOrFail(), ['is_demo' => false]);

        $statistics = app(AuthoritativeDataTransitionService::class)->transition();

        $this->assertSame(1, Centre::query()->count());
        $this->assertSame($authoritativeProject->id, Centre::query()->sole()->id);
        $this->assertSame(1, Project::query()->count());
        $this->assertSame(1, Programme::query()->count());
        $this->assertSame(0, Project::query()->where('is_demo', true)->count());
        $this->assertSame(1, User::query()->count());
        $this->assertSame($realUser->id, User::query()->sole()->id);
        $this->assertDatabaseCount('project_centres', 1);
        $this->assertDatabaseHas('project_user_assignments', [
            'user_id' => $realUser->id,
            'project_id' => $streetSmart->id,
            'is_demo' => false,
        ]);
        $this->assertDatabaseCount('centre_user_assignments', 0);
        $this->assertDatabaseCount('indicator_definitions', 0);
        $this->assertDatabaseCount('indicator_targets', 0);
        $this->assertDatabaseCount('indicator_entries', 0);
        $this->assertDatabaseCount('entry_revisions', 0);
        $this->assertDatabaseCount('approval_actions', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(20, $statistics['removed_demo_projects']);
        $this->assertSame(5, $statistics['removed_demo_users']);
        $this->assertSame(66, $statistics['removed_indicators']);
        $this->assertNotEmpty($statistics['evidence_paths']);
    }

    public function test_transition_dry_run_rolls_back_database_changes(): void
    {
        $programme = Programme::create([
            'name' => 'Street Smart',
            'description' => 'Demo',
            'is_demo' => true,
        ]);
        $project = Project::create([
            'programme_id' => $programme->id,
            'code' => 'PRG-01',
            'name' => 'Street Smart',
            'description' => 'Demo',
            'active' => true,
            'is_demo' => true,
        ]);
        $authoritativeProject = Centre::create([
            'source_project_id' => 68,
            'code' => 'ORG-68',
            'name' => 'CBLC ISBT (SS)',
            'type' => 'Education Centre',
            'region' => 'Not specified',
            'active' => true,
            'is_demo' => false,
        ]);
        $demoProject = Centre::create([
            'code' => 'DEMO',
            'name' => 'Demo project',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
            'active' => true,
            'is_demo' => true,
        ]);
        $authoritativeProject->projects()->attach($project, [
            'is_demo' => false,
            'provenance' => 'organisation database programme mapping',
        ]);

        $this->artisan('impact:transition-authoritative-data', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertTrue($project->fresh()->is_demo);
        $this->assertNotNull($demoProject->fresh());
    }
}
