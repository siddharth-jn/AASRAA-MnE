<?php

namespace Tests\Feature;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\EntryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvidenceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_uses_its_recorded_disk_and_missing_files_return_404(): void
    {
        Storage::fake('local');
        [$assignment, $user] = $this->fixture();
        $entry = app(EntryService::class)->save($user, $assignment, '2026-10-01', [
            'value_state' => 'reported',
            'numeric_value' => 12,
        ], [UploadedFile::fake()->image('proof.jpg')], false);
        $evidence = $entry->currentRevision->evidence->firstOrFail();

        $this->assertSame('local', $evidence->disk);
        $this->actingAs($user)->get(route('evidence.preview', $evidence))->assertOk();

        Storage::disk('local')->delete($evidence->path);
        $this->actingAs($user)->get(route('evidence.download', $evidence))->assertNotFound();
    }

    /** @return array{IndicatorAssignment, User} */
    private function fixture(): array
    {
        $user = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $programme = Programme::create(['name' => 'Education', 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'EDU', 'name' => 'Education', 'description' => 'Test']);
        $centre = Centre::create(['code' => 'ORG-1', 'name' => 'Project One', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        $user->projects()->attach($project);
        $user->centres()->attach($centre);
        $indicator = IndicatorDefinition::create([
            'project_id' => $project->id,
            'code' => 'SESSIONS',
            'name' => 'Sessions',
            'description' => 'Test',
            'category' => 'Activity',
            'unit' => 'sessions',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'active' => true,
        ]);
        $assignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id, 'required' => true]);
        IndicatorTarget::create([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-10-01',
            'period_end' => '2026-10-31',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 12,
            'active' => true,
        ]);

        return [$assignment->load(['indicator.project', 'centre']), $user];
    }
}
