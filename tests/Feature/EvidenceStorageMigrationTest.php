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

class EvidenceStorageMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_storage_migration_previews_then_copies_without_deleting_the_source(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        [$assignment, $user] = $this->fixture();
        $entry = app(EntryService::class)->save($user, $assignment, '2026-10-01', [
            'value_state' => 'reported',
            'numeric_value' => 12,
        ], [UploadedFile::fake()->image('proof.jpg')], false);
        $evidence = $entry->currentRevision->evidence->firstOrFail();

        $this->artisan('impact:migrate-evidence-storage', ['--to' => 's3'])
            ->assertSuccessful();
        $this->assertSame('local', $evidence->fresh()->disk);
        Storage::disk('s3')->assertMissing($evidence->path);

        $this->artisan('impact:migrate-evidence-storage', ['--to' => 's3', '--execute' => true])
            ->assertSuccessful();
        $this->assertSame('s3', $evidence->fresh()->disk);
        Storage::disk('s3')->assertExists($evidence->path);
        Storage::disk('local')->assertExists($evidence->path);
    }

    /** @return array{IndicatorAssignment, User} */
    private function fixture(): array
    {
        $user = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $programme = Programme::query()->create(['name' => 'Education', 'description' => 'Test']);
        $project = Project::query()->create(['programme_id' => $programme->id, 'code' => 'EDU', 'name' => 'Education', 'description' => 'Test']);
        $centre = Centre::query()->create(['code' => 'ORG-1', 'name' => 'Project One', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        $user->projects()->attach($project);
        $user->centres()->attach($centre);
        $indicator = IndicatorDefinition::query()->create([
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
            'approval_required' => false,
            'active' => true,
        ]);
        $assignment = IndicatorAssignment::query()->create(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id, 'required' => true]);
        IndicatorTarget::query()->create([
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
