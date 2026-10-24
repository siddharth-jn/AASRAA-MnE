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
use App\Support\WorkflowStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_variance_explanation_and_both_approval_stages_are_required(): void
    {
        [$assignment, $submitter, $seniorLead, $mneHead] = $this->fixture();

        try {
            app(EntryService::class)->save($submitter, $assignment, '2026-10-01', [
                'value_state' => 'reported',
                'numeric_value' => 115,
            ], [], true);
            $this->fail('A material variance should require an explanation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('variance_explanation', $exception->errors());
        }

        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-10-01', [
            'value_state' => 'reported',
            'numeric_value' => 115,
            'variance_explanation' => 'Additional outreach increased participation.',
        ], [], true);

        $this->assertSame(WorkflowStatus::PendingSeniorProgrammeLead, $entry->status);
        $this->assertEquals(15.0, $entry->currentRevision->variance_percentage);

        app(EntryService::class)->review($seniorLead, $entry, 'APPROVED', 'Checked');
        $this->assertSame(WorkflowStatus::PendingMneHead, $entry->fresh()->status);

        app(EntryService::class)->review($mneHead, $entry, 'APPROVED', 'Final review');
        $this->assertSame(WorkflowStatus::FinalApproved, $entry->fresh()->status);
        $this->assertDatabaseCount('approval_actions', 2);
    }

    public function test_only_mne_head_can_bulk_final_approve(): void
    {
        [$assignment, $submitter, $seniorLead, $mneHead] = $this->fixture();
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-10-01', [
            'value_state' => 'reported',
            'numeric_value' => 100,
        ], [], true);
        app(EntryService::class)->review($seniorLead, $entry, 'APPROVED', null);

        $this->actingAs($seniorLead)->post(route('approvals.bulk'), [
            'selection' => 'selected',
            'entry_ids' => [$entry->id],
            'confirmation' => '1',
        ])->assertForbidden();

        $this->actingAs($mneHead)->post(route('approvals.bulk'), [
            'selection' => 'selected',
            'entry_ids' => [$entry->id],
            'confirmation' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(WorkflowStatus::FinalApproved, $entry->fresh()->status);
    }

    public function test_an_admin_submission_only_skips_review_when_indicator_approval_is_disabled(): void
    {
        [$assignment] = $this->fixture();
        $administrator = User::factory()->create(['role' => 'ADMIN', 'active' => true]);

        $pendingEntry = app(EntryService::class)->save($administrator, $assignment, '2026-11-01', [
            'value_state' => 'reported',
            'numeric_value' => 100,
        ], [], true);

        $this->assertSame(WorkflowStatus::PendingSeniorProgrammeLead, $pendingEntry->status);

        $assignment->indicator->update(['approval_required' => false]);
        $approvedEntry = app(EntryService::class)->save($administrator, $assignment, '2026-12-01', [
            'value_state' => 'reported',
            'numeric_value' => 100,
        ], [], true);

        $this->assertSame(WorkflowStatus::FinalApproved, $approvedEntry->status);
    }

    /** @return array{IndicatorAssignment, User, User, User} */
    private function fixture(): array
    {
        $submitter = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $seniorLead = User::factory()->create(['role' => 'SENIOR PROGRAM LEAD', 'active' => true]);
        $mneHead = User::factory()->create(['role' => 'M&E HEAD', 'active' => true]);
        $programme = Programme::create(['name' => 'Education', 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'EDU', 'name' => 'Education', 'description' => 'Test']);
        $centre = Centre::create(['code' => 'ORG-1', 'name' => 'Project One', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        foreach ([$submitter, $seniorLead] as $user) {
            $user->projects()->attach($project);
            $user->centres()->attach($centre);
        }
        $indicator = IndicatorDefinition::create([
            'project_id' => $project->id,
            'code' => 'PARTICIPATION',
            'name' => 'Participation',
            'description' => 'Test',
            'category' => 'Output',
            'unit' => 'people',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'approval_required' => true,
            'active' => true,
        ]);
        $assignment = IndicatorAssignment::create(['indicator_definition_id' => $indicator->id, 'centre_id' => $centre->id, 'required' => true]);
        IndicatorTarget::create([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-10-01',
            'period_end' => '2026-10-31',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 100,
            'active' => true,
        ]);

        return [$assignment->load(['indicator.project', 'centre']), $submitter, $seniorLead, $mneHead];
    }
}
