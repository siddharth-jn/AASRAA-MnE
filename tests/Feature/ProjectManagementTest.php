<?php

namespace Tests\Feature;

use App\Filament\Resources\Centres\Pages\EditCentre;
use App\Models\Centre;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_assign_a_project_lead_without_changing_source_owned_fields(): void
    {
        $administrator = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $lead = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true, 'name' => 'Project Lead']);
        $programme = Programme::query()->create(['name' => 'Street Smart', 'description' => 'Test programme']);
        $reportingProgramme = Project::query()->create([
            'programme_id' => $programme->id,
            'code' => 'SS',
            'name' => 'Street Smart',
            'description' => 'Test reporting programme',
        ]);
        $project = Centre::query()->create([
            'source_project_id' => 501,
            'code' => 'ORG-501',
            'name' => 'Authoritative Project Name',
            'type' => 'Education Centre',
            'region' => 'Not specified',
            'provenance' => 'organisation database',
            'source_program' => 'Street Smart',
            'source_status' => 'Active',
            'source_timing_slot' => 'Morning',
            'source_donor_id' => 88,
            'source_donor_name' => 'Source Donor',
            'source_description' => null,
            'active' => true,
        ]);
        $project->projects()->attach($reportingProgramme, [
            'is_demo' => false,
            'provenance' => 'organisation database programme mapping',
        ]);

        Livewire::actingAs($administrator)
            ->test(EditCentre::class, ['record' => $project->getRouteKey()])
            ->assertSee('Imported project information')
            ->assertSee('Authoritative Project Name')
            ->assertSee('Street Smart')
            ->assertSee('Source donor ID')
            ->assertSee('88')
            ->assertSee('Source Donor')
            ->assertSee('Not available')
            ->assertSee('no live synchronization is performed')
            ->assertDontSee('Capacity')
            ->assertDontSee('Source URL')
            ->assertDontSee('Provenance')
            ->fillForm([
                'lead_id' => $lead->id,
                'type' => 'Partner School',
                'projects' => [$reportingProgramme->id],
                'active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $project->refresh();
        $this->assertTrue($project->lead->is($lead));
        $this->assertSame('Authoritative Project Name', $project->name);
        $this->assertSame('ORG-501', $project->code);
        $this->assertSame('Street Smart', $project->source_program);
        $this->assertSame('Active', $project->source_status);
        $this->assertTrue($project->active);
        $this->assertSame('Partner School', $project->type);
        $this->assertTrue($project->projects()->whereKey($reportingProgramme)->exists());
        $this->assertTrue($lead->centres()->whereKey($project)->exists());
        $this->assertTrue($lead->projects()->whereKey($reportingProgramme)->exists());
    }

    public function test_local_project_creation_form_keeps_local_identity_fields_available(): void
    {
        $administrator = User::factory()->create(['role' => 'ADMIN', 'active' => true]);

        $this->actingAs($administrator)
            ->get('/admin/projects/create')
            ->assertOk()
            ->assertSee('Local project identity')
            ->assertSee('Project code')
            ->assertSee('Project name')
            ->assertSee('Region');
    }

    public function test_project_lead_is_shown_in_admin_and_public_project_pages(): void
    {
        $administrator = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $lead = User::factory()->create(['role' => 'SENIOR PROGRAM LEAD', 'active' => true, 'name' => 'Visible Project Lead']);
        $programme = Programme::query()->create(['name' => 'Street Smart', 'description' => 'Test programme']);
        $reportingProgramme = Project::query()->create([
            'programme_id' => $programme->id,
            'code' => 'SS',
            'name' => 'Street Smart',
            'description' => 'Test reporting programme',
        ]);
        $project = Centre::query()->create([
            'source_project_id' => 501,
            'code' => 'ORG-501',
            'name' => 'Community Learning Project',
            'lead_id' => $lead->id,
            'type' => 'Education Centre',
            'region' => 'Not specified',
            'provenance' => 'organisation database',
            'source_program' => 'Street Smart',
            'source_status' => 'Active',
            'active' => true,
        ]);
        $project->projects()->attach($reportingProgramme, [
            'is_demo' => false,
            'provenance' => 'organisation database programme mapping',
        ]);

        $this->actingAs($administrator)
            ->get('/admin/projects')
            ->assertOk()
            ->assertSee($lead->name);

        $this->actingAs($administrator)
            ->get(route('projects.index', ['programme' => $reportingProgramme->id]))
            ->assertOk()
            ->assertSee('Project lead')
            ->assertSee($lead->name);

        $this->actingAs($administrator)
            ->get(route('projects.show', ['centre' => $project, 'project' => $reportingProgramme->id]))
            ->assertOk()
            ->assertSee('Project lead')
            ->assertSee($lead->name);

        $this->actingAs($administrator)
            ->get(route('programmes.show', $reportingProgramme))
            ->assertOk()
            ->assertSee('Lead: '.$lead->name);
    }
}
