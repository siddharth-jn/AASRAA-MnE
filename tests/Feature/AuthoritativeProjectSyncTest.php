<?php

namespace Tests\Feature;

use App\Models\AuthoritativeProject;
use App\Models\Centre;
use App\Models\Programme;
use App\Models\Project;
use App\Services\AuthoritativeProjectSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthoritativeProjectSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_scope_qualifies_columns_when_donors_are_joined(): void
    {
        $sql = AuthoritativeProject::query()
            ->operational()
            ->leftJoin('donors', 'donors.id', '=', 'projects.donor_id')
            ->toSql();

        $this->assertStringContainsString('projects`.`status', $sql);
        $this->assertStringContainsString('projects`.`program', $sql);
    }

    public function test_it_links_an_existing_project_without_replacing_its_relationships(): void
    {
        $mainstream = $this->createProject('Mainstreaming / SQEP', 'PRG-01');
        $otherProgramme = $this->createProject('Healthcare', 'PRG-02');
        $centre = Centre::create([
            'code' => 'GPS-AR',
            'name' => 'GPS Araghar (MS - SQEP)',
            'type' => 'Partner School',
            'region' => 'Dehradun',
            'provenance' => 'local configuration',
        ]);
        $centre->projects()->attach($otherProgramme, [
            'is_demo' => false,
            'provenance' => 'manual assignment',
        ]);

        $statistics = app(AuthoritativeProjectSyncService::class)->sync([
            $this->sourceProject(34, 'GPS Araghar (MS - SQEP)', 'Mainstream - SQEP'),
        ]);

        $centre->refresh();
        $this->assertSame(34, $centre->source_project_id);
        $this->assertSame('Mainstream - SQEP', $centre->source_program);
        $this->assertSame('GPS-AR', $centre->code);
        $this->assertSame('local configuration', $centre->provenance);
        $this->assertCount(2, $centre->projects);
        $this->assertDatabaseHas('project_centres', [
            'project_id' => $otherProgramme->id,
            'centre_id' => $centre->id,
            'provenance' => 'manual assignment',
        ]);
        $this->assertDatabaseHas('project_centres', [
            'project_id' => $mainstream->id,
            'centre_id' => $centre->id,
            'provenance' => 'organisation database programme mapping',
        ]);
        $this->assertSame(1, $statistics['matched_by_name']);
        $this->assertSame(1, $statistics['programme_links_created']);
    }

    public function test_it_creates_new_projects_idempotently(): void
    {
        $this->createProject('Street Smart', 'PRG-01');
        $sourceProject = $this->sourceProject(70, 'Kabadi Bazar (MLC)', 'Mobile Learning Centre');
        $service = app(AuthoritativeProjectSyncService::class);

        $firstSync = $service->sync([$sourceProject]);
        $secondSync = $service->sync([$sourceProject]);

        $this->assertSame(1, $firstSync['created']);
        $this->assertSame(0, $secondSync['created']);
        $this->assertSame(1, Centre::query()->where('source_project_id', 70)->count());
        $this->assertDatabaseHas('centres', [
            'source_project_id' => 70,
            'code' => 'ORG-70',
            'name' => 'Kabadi Bazar (MLC)',
            'type' => 'Mobile Learning Unit',
            'region' => 'Not specified',
            'source_status' => 'Active',
            'source_timing_slot' => 'Morning',
            'source_donor_id' => 3,
            'source_donor_name' => 'Example donor',
            'active' => true,
        ]);
        $this->assertDatabaseCount('project_centres', 1);
    }

    public function test_it_does_not_remove_relationships_when_the_source_programme_is_unmapped(): void
    {
        $programme = $this->createProject('Healthcare', 'PRG-01');
        $centre = Centre::create([
            'source_project_id' => 99,
            'code' => 'ORG-99',
            'name' => 'Old name',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
        ]);
        $centre->projects()->attach($programme, [
            'is_demo' => false,
            'provenance' => 'manual assignment',
        ]);

        $statistics = app(AuthoritativeProjectSyncService::class)->sync([
            $this->sourceProject(99, 'Demo1', 'Demo'),
        ]);

        $this->assertDatabaseHas('project_centres', [
            'project_id' => $programme->id,
            'centre_id' => $centre->id,
            'provenance' => 'manual assignment',
        ]);
        $this->assertSame(['Demo'], $statistics['unmapped_source_programmes']);
    }

    public function test_it_reconciles_status_only_for_projects_already_mirrored_locally(): void
    {
        $centre = Centre::create([
            'source_project_id' => 34,
            'code' => 'ORG-34',
            'name' => 'GPS Araghar',
            'type' => 'Partner School',
            'region' => 'Not specified',
            'source_status' => 'Active',
            'active' => true,
        ]);

        $inactiveSourceProject = $this->sourceProject(34, 'GPS Araghar', 'Mainstream - SQEP');
        $inactiveSourceProject->setAttribute('status', 'Inactive');
        $unknownInactiveProject = $this->sourceProject(999, 'Historical project', 'Mainstream');
        $unknownInactiveProject->setAttribute('status', 'Inactive');

        $updated = app(AuthoritativeProjectSyncService::class)->reconcileStatuses([
            $inactiveSourceProject,
            $unknownInactiveProject,
        ]);

        $this->assertSame(1, $updated);
        $this->assertFalse($centre->fresh()->active);
        $this->assertSame('Inactive', $centre->fresh()->source_status);
        $this->assertDatabaseMissing('centres', ['source_project_id' => 999]);
    }

    public function test_preview_matches_by_permanent_source_id_and_highlights_a_name_change(): void
    {
        $programme = $this->createProject('Street Smart', 'PRG-01');
        $centre = Centre::create([
            'source_project_id' => 70,
            'code' => 'ORG-70',
            'name' => 'Old project name',
            'type' => 'Mobile Learning Unit',
            'region' => 'Not specified',
            'source_program' => 'Mobile Learning Centre',
            'source_status' => 'Active',
            'source_timing_slot' => 'Morning',
            'source_donor_id' => 3,
            'source_donor_name' => 'Example donor',
            'active' => true,
        ]);
        $centre->projects()->attach($programme, [
            'is_demo' => false,
            'provenance' => 'organisation database programme mapping',
        ]);
        $sourceProject = $this->sourceProject(70, 'New project name', 'Mobile Learning Centre');

        $preview = app(AuthoritativeProjectSyncService::class)->preview([$sourceProject], [$sourceProject]);

        $this->assertSame(0, $preview['created']);
        $this->assertSame(1, $preview['updated']);
        $this->assertFalse($preview['blocked']);
        $this->assertSame('Updated project', $preview['changes'][0]['type']);
        $this->assertContains([
            'field' => 'Name',
            'before' => 'Old project name',
            'after' => 'New project name',
        ], $preview['changes'][0]['details']);
    }

    public function test_preview_highlights_deactivation_without_deleting_the_local_project(): void
    {
        Centre::create([
            'source_project_id' => 34,
            'code' => 'ORG-34',
            'name' => 'GPS Araghar',
            'type' => 'Partner School',
            'region' => 'Not specified',
            'source_status' => 'Active',
            'active' => true,
        ]);
        $inactiveSourceProject = $this->sourceProject(34, 'GPS Araghar', 'Mainstream - SQEP');
        $inactiveSourceProject->setAttribute('status', 'Inactive');

        $preview = app(AuthoritativeProjectSyncService::class)->preview([], [$inactiveSourceProject]);

        $this->assertSame(1, $preview['status_updates']);
        $this->assertSame('Deactivated project', $preview['changes'][0]['type']);
        $this->assertDatabaseHas('centres', [
            'source_project_id' => 34,
            'active' => true,
        ]);
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
            'location' => null,
        ]);
        $sourceProject->setAttribute('id', $id);

        return $sourceProject;
    }
}
