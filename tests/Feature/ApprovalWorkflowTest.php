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
use Carbon\CarbonImmutable;
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

    public function test_each_reviewer_can_bulk_approve_their_own_stage(): void
    {
        [$assignment, $submitter, $seniorLead, $mneHead] = $this->fixture();
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-10-01', [
            'value_state' => 'reported',
            'numeric_value' => 100,
        ], [], true);

        $this->actingAs($seniorLead)->post(route('approvals.bulk'), [
            'selection' => 'selected',
            'entry_ids' => [$entry->id],
            'confirmation' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(WorkflowStatus::PendingMneHead, $entry->fresh()->status);

        $this->actingAs($mneHead)->post(route('approvals.bulk'), [
            'selection' => 'selected',
            'entry_ids' => [$entry->id],
            'confirmation' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame(WorkflowStatus::FinalApproved, $entry->fresh()->status);
    }

    public function test_administrator_can_bulk_approve_each_stage_without_skipping_a_stage(): void
    {
        [$assignment, $submitter] = $this->fixture();
        $administrator = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-10-01', [
            'value_state' => 'reported',
            'numeric_value' => 100,
        ], [], true);
        $payload = [
            'selection' => 'selected',
            'entry_ids' => [$entry->id],
            'confirmation' => '1',
        ];

        $this->actingAs($administrator)->post(route('approvals.bulk'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(WorkflowStatus::PendingMneHead, $entry->fresh()->status);

        $this->actingAs($administrator)->post(route('approvals.bulk'), $payload)->assertSessionHasNoErrors();
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

        $noApprovalIndicator = $assignment->indicator->replicate();
        $noApprovalIndicator->forceFill([
            'code' => 'PARTICIPATION-NO-APPROVAL',
            'approval_required' => false,
        ])->save();
        $noApprovalAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $noApprovalIndicator->id,
            'centre_id' => $assignment->centre_id,
            'required' => true,
        ]);
        IndicatorTarget::create([
            'indicator_assignment_id' => $noApprovalAssignment->id,
            'period' => '2026-12-01',
            'period_end' => '2026-12-31',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 100,
            'active' => true,
        ]);
        $approvedEntry = app(EntryService::class)->save($administrator, $noApprovalAssignment, '2026-12-01', [
            'value_state' => 'reported',
            'numeric_value' => 100,
        ], [], true);

        $this->assertSame(WorkflowStatus::FinalApproved, $approvedEntry->status);
    }

    public function test_a_legacy_targetless_submission_is_not_actionable_or_visible_in_approvals(): void
    {
        [$assignment, $submitter, $seniorLead] = $this->fixture();
        $entry = app(EntryService::class)->save($submitter, $assignment, '2026-10-01', [
            'value_state' => 'reported',
            'numeric_value' => 100,
        ], [], true);
        $assignment->targets()->whereDate('period', '2026-10-01')->update(['active' => false]);

        $this->actingAs($seniorLead)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('0 pending at your approval stage');

        $this->actingAs($seniorLead)
            ->post(route('approvals.review', $entry), ['action' => 'APPROVED'])
            ->assertSessionHasErrors('entry');

        $this->assertSame(WorkflowStatus::PendingSeniorProgrammeLead, $entry->fresh()->status);
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
        foreach (['2026-11-01', '2026-12-01'] as $period) {
            IndicatorTarget::create([
                'indicator_assignment_id' => $assignment->id,
                'period' => $period,
                'period_end' => CarbonImmutable::parse($period)->endOfMonth(),
                'period_type' => 'monthly',
                'target_type' => 'numeric',
                'value' => 100,
                'active' => true,
            ]);
        }

        return [$assignment->load(['indicator.project', 'centre']), $submitter, $seniorLead, $mneHead];
    }
}
