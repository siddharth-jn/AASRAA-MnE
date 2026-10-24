<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\EntryRevision;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\EntryService;
use App\Support\WorkflowStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImpactTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_user_cannot_access_a_centre_outside_their_scope(): void
    {
        $user = User::factory()->create(['role' => 'PROGRAM LEAD']);
        $programme = Programme::create(['name' => 'Test programme', 'description' => 'Test', 'is_demo' => false]);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'TEST', 'name' => 'Test project', 'description' => 'Test', 'active' => true]);
        $allowed = Centre::create(['code' => 'C1', 'name' => 'Allowed centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $restricted = Centre::create(['code' => 'C2', 'name' => 'Restricted centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $user->projects()->attach($project);
        $user->centres()->attach($allowed);

        $this->actingAs($user)->get(route('projects.show', $restricted))->assertForbidden();
    }

    public function test_submitter_cannot_approve_their_own_submission(): void
    {
        $user = User::factory()->create(['role' => 'SENIOR PROGRAM LEAD']);
        $programme = Programme::create(['name' => 'Test programme', 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'TEST', 'name' => 'Test project', 'description' => 'Test']);
        $centre = Centre::create(['code' => 'C1', 'name' => 'Test centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $indicator = IndicatorDefinition::create(['project_id' => $project->id, 'code' => 'TEST-I', 'name' => 'Sessions', 'description' => 'Test', 'category' => 'Activity', 'unit' => 'sessions', 'data_type' => 'integer', 'frequency' => 'monthly', 'time_aggregation' => 'SUM', 'centre_aggregation' => 'SUM', 'min_value' => 0, 'active' => true]);
        $version = $indicator->versions()->firstOrCreate(['version' => 1], ['definition' => $indicator->attributesToArray()]);
        $assignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id]);
        $entry = IndicatorEntry::create(['indicator_assignment_id' => $assignment->id, 'period' => '2026-09-01', 'status' => WorkflowStatus::PendingSeniorProgrammeLead]);
        $revision = EntryRevision::create(['indicator_entry_id' => $entry->id, 'indicator_definition_version_id' => $version->id, 'user_id' => $user->id, 'version' => 1, 'status' => WorkflowStatus::PendingSeniorProgrammeLead, 'numeric_value' => 18, 'submitted_at' => now()]);
        $entry->update(['current_revision_id' => $revision->id]);

        $this->expectException(ValidationException::class);
        app(EntryService::class)->review($user, $entry, 'APPROVED', null);
    }

    public function test_demo_cleanup_preserves_operational_records(): void
    {
        Programme::create(['name' => 'Operational programme', 'description' => 'Keep this', 'is_demo' => false]);
        Programme::create(['name' => 'Demo programme', 'description' => 'Remove this', 'is_demo' => true]);

        $this->artisan('impact:clear-demo-data', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('programmes', ['name' => 'Operational programme']);
        $this->assertDatabaseMissing('programmes', ['name' => 'Demo programme']);
    }
}
