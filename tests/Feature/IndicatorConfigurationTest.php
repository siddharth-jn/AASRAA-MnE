<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\RelationManagers\IndicatorsRelationManager;
use App\Models\AuditLog;
use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\IndicatorService;
use App\Services\TargetService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class IndicatorConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_indicator_changes_create_an_immutable_definition_version(): void
    {
        $indicator = $this->createAssignment()->indicator;

        $this->assertCount(1, $indicator->versions);

        $indicator->update(['name' => 'Updated sessions delivered']);

        $this->assertSame([1, 2], $indicator->versions()->orderBy('version')->pluck('version')->all());
        $this->assertSame('Sessions delivered', $indicator->versions()->first()->definition['name']);
        $this->assertSame('Updated sessions delivered', $indicator->versions()->latest('version')->first()->definition['name']);
    }

    public function test_indicator_service_applies_percentage_and_ratio_rules(): void
    {
        $percentage = app(IndicatorService::class)->prepareAndValidate([
            'data_type' => 'percentage',
            'precision' => 1,
            'min_files' => 0,
            'max_files' => 5,
        ]);
        $ratio = app(IndicatorService::class)->prepareAndValidate([
            'data_type' => 'calculated',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'AVERAGE',
            'min_files' => 0,
            'max_files' => 5,
        ]);
        $evidence = app(IndicatorService::class)->prepareAndValidate([
            'data_type' => 'integer',
            'evidence_required' => true,
            'evidence_review_required' => true,
            'min_files' => 0,
            'max_files' => 5,
        ]);

        $this->assertSame(0, $percentage['min_value']);
        $this->assertSame(100, $percentage['max_value']);
        $this->assertSame('RATIO', $ratio['time_aggregation']);
        $this->assertSame('RATIO', $ratio['centre_aggregation']);
        $this->assertSame('Numerator', $ratio['numerator_label']);
        $this->assertSame(1, $evidence['min_files']);
        $this->assertTrue($evidence['approval_required']);
    }

    public function test_select_indicator_requires_accepted_options(): void
    {
        $this->expectException(ValidationException::class);

        app(IndicatorService::class)->prepareAndValidate([
            'data_type' => 'single_select',
            'options' => [],
            'min_files' => 0,
            'max_files' => 5,
        ]);
    }

    public function test_target_service_normalizes_fiscal_quarter_and_finds_target(): void
    {
        $assignment = $this->createAssignment();
        $data = app(TargetService::class)->prepareAndValidate([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-18',
            'period_type' => 'quarterly',
            'target_type' => 'numeric',
            'value' => 60,
            'active' => true,
        ]);
        $target = IndicatorTarget::create($data);

        $this->assertSame('2026-07-01', $target->period->toDateString());
        $this->assertSame('2026-09-30', $target->period_end->toDateString());
        $this->assertTrue($target->is(app(TargetService::class)->findForPeriod($assignment->id, CarbonImmutable::parse('2026-09-01'))));
    }

    public function test_duplicate_target_period_is_rejected_before_database_write(): void
    {
        $assignment = $this->createAssignment();
        IndicatorTarget::create([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-04-01',
            'period_end' => '2026-04-30',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 20,
            'active' => true,
        ]);

        $this->expectException(ValidationException::class);
        app(TargetService::class)->prepareAndValidate([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-04-18',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 25,
            'active' => true,
        ]);
    }

    public function test_administrator_can_open_indicator_and_target_management(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);

        $this->actingAs($admin)->get('/admin/indicator-definitions')->assertOk();
        $this->actingAs($admin)
            ->get('/admin/indicator-definitions/create')
            ->assertOk()
            ->assertSee('Reporting starts from')
            ->assertSee('type="month"', false)
            ->assertDontSee('Effective date')
            ->assertSee('Require a value when reported')
            ->assertDontSee('Reported value is required');
        $this->actingAs($admin)->get('/admin/indicator-targets')->assertOk();
        $this->actingAs($admin)->get('/admin/indicator-targets/create')->assertOk();
        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('AASRAA Monitoring &amp; Evaluation', false)
            ->assertSee(route('dashboard'), false)
            ->assertDontSee('Back to main dashboard');
    }

    public function test_administrator_can_manage_project_indicators_from_the_project_edit_page(): void
    {
        $assignment = $this->createAssignment();
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $secondCentre = Centre::create([
            'code' => 'C2',
            'name' => 'Second centre',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
        ]);
        $assignment->indicator->project->centres()->attach($secondCentre);

        $this->actingAs($admin)
            ->get(ProjectResource::getUrl('edit', ['record' => $assignment->indicator->project]))
            ->assertOk();

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('Indicator definitions');

        $this->actingAs($admin)
            ->get(route('projects.show', $assignment->indicator->project))
            ->assertOk()
            ->assertSee('Edit project &amp; indicators', false);

        Livewire::actingAs($admin)
            ->test(IndicatorsRelationManager::class, [
                'ownerRecord' => $assignment->indicator->project,
                'pageClass' => EditProject::class,
            ])
            ->assertCanSeeTableRecords([$assignment->indicator])
            ->assertSee('Add indicator')
            ->callTableAction('edit', $assignment->indicator, [
                'code' => $assignment->indicator->code,
                'name' => 'Sessions delivered by centre',
                'description' => $assignment->indicator->description,
                'category' => $assignment->indicator->category,
                'unit' => $assignment->indicator->unit,
                'frequency' => 'monthly',
                'effective_date' => '2026-04',
                'data_type' => 'integer',
                'value_required' => true,
                'min_value' => 0,
                'centres' => [$assignment->centre_id, $secondCentre->id],
                'target_centre_ids' => [$assignment->centre_id, $secondCentre->id],
                'time_aggregation' => 'SUM',
                'centre_aggregation' => 'SUM',
                'target_tracking' => true,
                'direction' => 'higher',
                'set_targets_now' => true,
                'target_period_type' => 'monthly',
                'target_period' => '2026-09-15',
                'same_target_all_centres' => true,
                'shared_target' => ['value' => 25],
                'evidence_required' => false,
                'evidence_review_required' => false,
                'max_file_kb' => 5120,
                'min_files' => 0,
                'max_files' => 5,
                'approval_required' => true,
                'active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('indicator_definitions', [
            'id' => $assignment->indicator_definition_id,
            'project_id' => $assignment->indicator->project_id,
            'name' => 'Sessions delivered by centre',
        ]);

        $this->assertSame(2, $assignment->indicator->fresh()->centres()->count());
        $this->assertDatabaseCount('indicator_targets', 2);
        $this->assertSame(
            [25.0, 25.0],
            IndicatorTarget::query()->orderBy('indicator_assignment_id')->get()->map(fn (IndicatorTarget $target): float => (float) $target->value)->all(),
        );

        Livewire::actingAs($admin)
            ->test(IndicatorsRelationManager::class, [
                'ownerRecord' => $assignment->indicator->project,
                'pageClass' => EditProject::class,
            ])
            ->callTableAction('edit', $assignment->indicator, [
                'code' => $assignment->indicator->code,
                'name' => 'Sessions delivered by centre',
                'description' => $assignment->indicator->description,
                'category' => $assignment->indicator->category,
                'unit' => $assignment->indicator->unit,
                'frequency' => 'monthly',
                'effective_date' => '2026-04',
                'data_type' => 'integer',
                'value_required' => true,
                'min_value' => 0,
                'centres' => [$assignment->centre_id, $secondCentre->id],
                'target_centre_ids' => [$assignment->centre_id, $secondCentre->id],
                'time_aggregation' => 'SUM',
                'centre_aggregation' => 'SUM',
                'target_tracking' => true,
                'direction' => 'higher',
                'set_targets_now' => true,
                'target_period_type' => 'monthly',
                'target_period' => '2026-11-15',
                'same_target_all_centres' => false,
                'centre_targets' => [
                    $assignment->centre_id => ['value' => 10],
                    $secondCentre->id => ['value' => 20],
                ],
                'evidence_required' => false,
                'evidence_review_required' => false,
                'max_file_kb' => 5120,
                'min_files' => 0,
                'max_files' => 5,
                'approval_required' => true,
                'active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(
            [10.0, 20.0],
            IndicatorTarget::query()
                ->whereDate('period', '2026-11-01')
                ->orderBy('indicator_assignment_id')
                ->get()
                ->map(fn (IndicatorTarget $target): float => (float) $target->value)
                ->all(),
        );

        $this->actingAs($admin)
            ->get(route('projects.show', [
                $assignment->indicator->project,
                'section' => 'activities',
                'centre' => $assignment->centre_id,
                'period_type' => 'monthly',
                'anchor' => '2026-09',
            ]))
            ->assertOk()
            ->assertSee('No approved result')
            ->assertSee('Target: 25 sessions');
    }

    public function test_target_changes_are_written_to_the_audit_log(): void
    {
        $assignment = $this->createAssignment();
        $target = IndicatorTarget::create([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-04-01',
            'period_end' => '2026-04-30',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 20,
            'active' => true,
        ]);
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);

        $this->actingAs($admin);
        $target->update(['value' => 25]);

        $this->assertTrue(AuditLog::query()
            ->where('user_id', $admin->id)
            ->where('subject_type', IndicatorTarget::class)
            ->where('subject_id', $target->id)
            ->where('action', 'updated')
            ->exists());
    }

    public function test_configured_select_options_and_optional_approval_drive_data_entry(): void
    {
        $assignment = $this->createAssignment();
        $assignment->indicator->update([
            'data_type' => 'single_select',
            'options' => ['On track', 'Delayed'],
            'approval_required' => false,
        ]);
        $user = User::factory()->create(['role' => 'PROGRAM LEAD', 'active' => true]);
        $user->projects()->attach($assignment->indicator->project_id);
        $user->centres()->attach($assignment->centre_id);

        $this->actingAs($user)
            ->post(route('entries.store', $assignment), [
                'period' => '2026-10-01',
                'value_state' => 'reported',
                'text_value' => 'Unknown',
                'action' => 'submit',
            ])
            ->assertSessionHasErrors('text_value');

        $response = $this->actingAs($user)
            ->post(route('entries.store', $assignment), [
                'period' => '2026-10-01',
                'value_state' => 'reported',
                'text_value' => 'On track',
                'action' => 'submit',
            ]);

        $response->assertRedirect(route('entries.index', ['period' => '2026-10']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('indicator_entries', ['indicator_assignment_id' => $assignment->id, 'status' => 'APPROVED']);
        $this->assertDatabaseHas('entry_revisions', ['text_value' => 'On track', 'status' => 'APPROVED']);
    }

    private function createAssignment(): IndicatorAssignment
    {
        $programme = Programme::create(['name' => 'Test programme', 'description' => 'Test']);
        $project = Project::create(['programme_id' => $programme->id, 'code' => 'TEST', 'name' => 'Test project', 'description' => 'Test']);
        $centre = Centre::create(['code' => 'C1', 'name' => 'Test centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        $indicator = IndicatorDefinition::create([
            'project_id' => $project->id,
            'code' => 'TEST-I',
            'name' => 'Sessions delivered',
            'description' => 'Configured by an administrator.',
            'category' => 'Activity',
            'unit' => 'sessions',
            'data_type' => 'integer',
            'frequency' => 'monthly',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'SUM',
            'min_value' => 0,
            'active' => true,
        ]);

        return IndicatorAssignment::create([
            'indicator_definition_id' => $indicator->id,
            'centre_id' => $centre->id,
            'required' => true,
        ])->load(['indicator', 'centre']);
    }
}
