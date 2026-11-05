<?php

namespace Tests\Feature;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AuditLog;
use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\AggregationService;
use App\Services\IndicatorService;
use App\Services\UserAuditService;
use App\Support\WorkflowStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class RoleWorkflowAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_session_is_logged_out_before_it_can_read_the_portal(): void
    {
        [$user] = $this->fixture('PROGRAM LEAD');
        $this->actingAs($user);
        $user->update(['active' => false]);

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->get(route('reports.index', ['anchor' => '2026-10']))
            ->assertRedirect(route('login'));
    }

    public function test_viewer_cannot_open_the_data_entry_obligation_page_directly(): void
    {
        [$viewer, $assignment] = $this->fixture('VIEWER');

        $this->actingAs($viewer)
            ->get(route('entries.index', ['anchor' => '2026-10']))
            ->assertForbidden();
    }

    public function test_unique_count_uses_the_latest_cumulative_monthly_snapshot(): void
    {
        $result = app(AggregationService::class)->aggregate([
            ['value' => 10],
            ['value' => 10],
            ['value' => 20],
        ], 'UNIQUE COUNT');

        $this->assertSame(20.0, $result);
    }

    public function test_weighted_average_is_not_an_available_configuration(): void
    {
        $this->assertNotContains('WEIGHTED AVERAGE', config('impact.aggregations'));
        $this->assertArrayNotHasKey('WEIGHTED AVERAGE', app(IndicatorService::class)->timeAggregationOptions('decimal'));
    }

    public function test_audit_confirms_unsupported_and_oversized_evidence_are_rejected(): void
    {
        [$programmeLead, $assignment] = $this->fixture('PROGRAM LEAD', 'SUM', [
            'allowed_extensions' => ['pdf'],
            'max_file_kb' => 10,
        ]);

        $this->actingAs($programmeLead)
            ->post(route('entries.store', $assignment), [
                'period' => '2026-10-01',
                'numeric_value' => 80,
                'action' => 'draft',
                'evidence' => [UploadedFile::fake()->create('unsupported.exe', 1, 'application/octet-stream')],
            ])
            ->assertSessionHasErrors('evidence.0');

        $this->actingAs($programmeLead)
            ->post(route('entries.store', $assignment), [
                'period' => '2026-10-01',
                'numeric_value' => 80,
                'action' => 'draft',
                'evidence' => [UploadedFile::fake()->create('oversized.pdf', 11, 'application/pdf')],
            ])
            ->assertSessionHasErrors('evidence.0');

        $this->assertDatabaseCount('indicator_entries', 0);
    }

    public function test_audit_confirms_double_submission_does_not_create_a_second_revision(): void
    {
        [$programmeLead, $assignment] = $this->fixture('PROGRAM LEAD');
        $payload = [
            'period' => '2026-10-01',
            'numeric_value' => 100,
            'action' => 'submit',
        ];

        $this->actingAs($programmeLead)
            ->post(route('entries.store', $assignment), $payload)
            ->assertSessionHasNoErrors();
        $this->actingAs($programmeLead)
            ->post(route('entries.store', $assignment), $payload)
            ->assertSessionHasErrors('entry');

        $entry = $assignment->entries()->firstOrFail();
        $this->assertSame(WorkflowStatus::PendingSeniorProgrammeLead, $entry->status);
        $this->assertCount(1, $entry->revisions);
    }

    public function test_audit_confirms_administrator_can_change_scope_role_and_active_status(): void
    {
        $administrator = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        [$user, $assignment] = $this->fixture('VIEWER');

        Livewire::actingAs($administrator)
            ->test(EditUser::class, ['record' => $user->getRouteKey()])
            ->fillForm([
                'name' => $user->name,
                'email' => $user->email,
                'role' => 'PROGRAM LEAD',
                'projects' => [$assignment->indicator->project_id],
                'centres' => [$assignment->centre_id],
                'active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();
        $this->assertSame('PROGRAM LEAD', $user->role);
        $this->assertFalse($user->active);
        $this->assertTrue($user->projects()->whereKey($assignment->indicator->project_id)->exists());
        $this->assertTrue($user->centres()->whereKey($assignment->centre_id)->exists());

        $auditLog = AuditLog::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $user->id)
            ->where('action', 'user_updated')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('VIEWER', $auditLog->changes['before']['role']);
        $this->assertSame('PROGRAM LEAD', $auditLog->changes['after']['role']);
        $this->assertSame([$assignment->indicator->project_id], $auditLog->changes['after']['project_ids']);
        $this->assertSame([$assignment->centre_id], $auditLog->changes['after']['centre_ids']);

        app(UserAuditService::class)->restore($auditLog, $administrator);
        $user->refresh();
        $this->assertSame('VIEWER', $user->role);
        $this->assertTrue($user->active);
        $this->assertTrue($user->projects()->whereKey($assignment->indicator->project_id)->exists());
        $this->assertTrue($user->centres()->whereKey($assignment->centre_id)->exists());
        $this->assertDatabaseHas('audit_logs', [
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'action' => 'user_restored',
        ]);

        Livewire::actingAs($administrator)
            ->test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$auditLog]);
    }

    /** @return array{User, IndicatorAssignment} */
    /** @param array<string, mixed> $indicatorOverrides */
    private function fixture(string $role, string $timeAggregation = 'SUM', array $indicatorOverrides = []): array
    {
        $user = User::factory()->create([
            'role' => $role,
            'active' => true,
        ]);
        $programme = Programme::query()->create([
            'name' => 'Audit Education',
            'description' => 'Role simulation programme.',
        ]);
        $project = Project::query()->create([
            'programme_id' => $programme->id,
            'code' => 'AUDIT-EDU',
            'name' => 'Audit Education Programme',
            'description' => 'Role simulation programme.',
            'active' => true,
        ]);
        $centre = Centre::query()->create([
            'code' => 'AUDIT-PROJECT',
            'name' => 'Audit Learning Project',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
            'active' => true,
        ]);
        $project->centres()->attach($centre);
        $user->projects()->attach($project);
        $user->centres()->attach($centre);
        $indicator = IndicatorDefinition::query()->create(array_merge([
            'project_id' => $project->id,
            'code' => 'AUDIT-OUTPUT',
            'name' => 'Learners completing support',
            'description' => 'Audit role workflow indicator.',
            'category' => 'Output',
            'unit' => 'people',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => $timeAggregation,
            'centre_aggregation' => 'SUM',
            'direction' => 'higher',
            'target_tracking' => true,
            'approval_required' => true,
            'effective_date' => '2026-04-01',
            'active' => true,
        ], $indicatorOverrides));
        $assignment = IndicatorAssignment::query()->create([
            'indicator_definition_id' => $indicator->id,
            'centre_id' => $centre->id,
            'required' => true,
        ]);
        IndicatorTarget::query()->create([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-10-01',
            'period_end' => '2026-10-31',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 100,
            'active' => true,
        ]);

        return [$user, $assignment->load(['indicator.project', 'centre'])];
    }
}
