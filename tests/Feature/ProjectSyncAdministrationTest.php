<?php

namespace Tests\Feature;

use App\Models\AuthoritativeProject;
use App\Models\Programme;
use App\Models\Project;
use App\Models\SourceSyncRun;
use App\Models\User;
use App\Services\AuthoritativeProjectSourceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery\MockInterface;
use Tests\TestCase;

class ProjectSyncAdministrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_sync_is_hidden_when_the_feature_is_disabled(): void
    {
        config()->set('impact.authoritative_project_sync_enabled', false);
        $administrator = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($administrator)
            ->get('/administration/project-sync')
            ->assertNotFound();
        $this->actingAs($administrator)
            ->post('/administration/project-sync')
            ->assertNotFound();
        $this->actingAs($administrator)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Project sync');

        $this->artisan('impact:sync-authoritative-projects', ['--dry-run' => true])
            ->assertFailed();
    }

    public function test_only_an_administrator_can_open_project_sync(): void
    {
        config()->set('impact.authoritative_project_sync_enabled', true);
        $viewer = User::factory()->create(['role' => 'VIEWER']);

        $this->actingAs($viewer)
            ->get(route('administration.project-sync.index'))
            ->assertForbidden();
    }

    public function test_an_administrator_can_preview_project_changes_without_writing_them(): void
    {
        config()->set('impact.authoritative_project_sync_enabled', true);
        $administrator = User::factory()->create(['role' => 'ADMIN']);
        $this->createProject('Street Smart', 'PRG-01');
        $sourceProject = $this->sourceProject(70, 'Kabadi Bazar (MLC)', 'Mobile Learning Centre');
        $this->mockSource(collect([$sourceProject]), collect([$sourceProject]));

        $this->actingAs($administrator)
            ->get(route('administration.project-sync.index'))
            ->assertOk()
            ->assertSee('Kabadi Bazar (MLC)')
            ->assertSee('New project')
            ->assertSee('Apply displayed changes');

        $this->assertDatabaseMissing('centres', ['source_project_id' => 70]);
        $this->assertDatabaseCount('source_sync_runs', 0);
    }

    public function test_an_administrator_can_apply_the_displayed_project_changes(): void
    {
        config()->set('impact.authoritative_project_sync_enabled', true);
        $administrator = User::factory()->create(['role' => 'ADMIN']);
        $this->createProject('Street Smart', 'PRG-01');
        $sourceProject = $this->sourceProject(70, 'Kabadi Bazar (MLC)', 'Mobile Learning Centre');
        $this->mockSource(collect([$sourceProject]), collect([$sourceProject]));

        $this->actingAs($administrator)
            ->post(route('administration.project-sync.store'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('centres', [
            'source_project_id' => 70,
            'name' => 'Kabadi Bazar (MLC)',
            'active' => true,
        ]);
        $run = SourceSyncRun::query()->sole();
        $this->assertSame('completed', $run->status);
        $this->assertSame($administrator->id, $run->user_id);
        $this->assertSame(1, $run->summary['applied']['created']);
    }

    /**
     * @param  Collection<int, AuthoritativeProject>  $projects
     * @param  Collection<int, AuthoritativeProject>  $statuses
     */
    private function mockSource(Collection $projects, Collection $statuses): void
    {
        $this->mock(AuthoritativeProjectSourceService::class, function (MockInterface $mock) use ($projects, $statuses): void {
            $mock->shouldReceive('operationalProjects')->once()->andReturn($projects);
            $mock->shouldReceive('projectStatuses')->once()->andReturn($statuses);
            $mock->shouldReceive('snapshotHash')->zeroOrMoreTimes()->andReturn(str_repeat('a', 64));
        });
    }

    private function createProject(string $name, string $code): Project
    {
        $programme = Programme::create([
            'name' => $name,
            'description' => $name,
        ]);

        return Project::create([
            'programme_id' => $programme->id,
            'code' => $code,
            'name' => $name,
            'description' => $name,
            'active' => true,
        ]);
    }

    private function sourceProject(int $id, string $name, string $program): AuthoritativeProject
    {
        $sourceProject = new AuthoritativeProject([
            'name' => $name,
            'program' => $program,
            'status' => 'Active',
            'timing_slot' => 'Morning',
            'donor_id' => 3,
            'donor_name' => 'Example donor',
        ]);
        $sourceProject->setAttribute('id', $id);

        return $sourceProject;
    }
}
