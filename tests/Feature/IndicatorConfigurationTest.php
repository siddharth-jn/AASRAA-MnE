<?php

namespace Tests\Feature;

use App\Filament\Resources\IndicatorTargets\IndicatorTargetResource;
use App\Filament\Resources\IndicatorTargets\Pages\EditIndicatorTarget;
use App\Filament\Resources\IndicatorTargets\Pages\PlanIndicatorTargets;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\Pages\ListProjects;
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

    public function test_programme_catalogue_is_read_only_while_monitoring_setup_remains_editable(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $programme = $this->createAssignment()->indicator->project;

        $this->assertFalse(ProjectResource::canCreate());
        $this->assertFalse(ProjectResource::canDelete($programme));
        $this->actingAs($admin)->get('/admin/programmes/create')->assertNotFound();

        Livewire::actingAs($admin)
            ->test(ListProjects::class)
            ->assertActionDoesNotExist('create');

        Livewire::actingAs($admin)
            ->test(EditProject::class, ['record' => $programme->getRouteKey()])
            ->assertFormFieldDisabled('code')
            ->assertFormFieldDisabled('name');
    }

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
        $this->assertFalse($evidence['evidence_review_required']);
        $this->assertSame(config('impact.evidence_extensions'), $evidence['allowed_extensions']);
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

    public function test_across_project_options_only_include_supported_methods_for_each_input_type(): void
    {
        $indicators = app(IndicatorService::class);

        $this->assertSame(['SUM', 'AVERAGE', 'NON-AGGREGATABLE'], array_keys($indicators->acrossProjectOptions('integer')));
        $this->assertSame(['AVERAGE', 'NON-AGGREGATABLE'], array_keys($indicators->acrossProjectOptions('percentage')));
        $this->assertSame(['RATIO'], array_keys($indicators->acrossProjectOptions('calculated')));
        $this->assertSame(['STATUS', 'NON-AGGREGATABLE'], array_keys($indicators->acrossProjectOptions('status')));
        $this->assertSame(['NON-AGGREGATABLE'], array_keys($indicators->acrossProjectOptions('text')));

        $status = $indicators->prepareAndValidate([
            'data_type' => 'status',
            'options' => ['Open', 'Closed'],
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'STATUS',
            'min_files' => 0,
            'max_files' => 5,
        ]);
        $this->assertSame('STATUS', $status['time_aggregation']);

        $this->expectException(ValidationException::class);
        $indicators->prepareAndValidate([
            'data_type' => 'integer',
            'centre_aggregation' => 'LATEST SNAPSHOT',
            'min_files' => 0,
            'max_files' => 5,
        ]);
    }

    public function test_target_service_normalizes_fiscal_quarter_and_finds_target(): void
    {
        $assignment = $this->createAssignment();
        $assignment->indicator->update(['frequency' => 'quarterly']);
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

    public function test_target_period_must_match_the_indicators_reporting_frequency(): void
    {
        $assignment = $this->createAssignment();

        $this->expectException(ValidationException::class);
        app(TargetService::class)->prepareAndValidate([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-18',
            'period_type' => 'quarterly',
            'target_type' => 'numeric',
            'value' => 60,
            'active' => true,
        ]);
    }

    public function test_target_values_follow_the_indicators_numeric_input_rules(): void
    {
        $assignment = $this->createAssignment();

        try {
            app(TargetService::class)->prepareAndValidate([
                'indicator_assignment_id' => $assignment->id,
                'period' => '2026-08-01',
                'period_type' => 'monthly',
                'target_type' => 'status',
                'value' => 12.5,
                'status_value' => 'Complete',
                'active' => true,
            ]);
            $this->fail('A decimal target should not be accepted for an integer indicator.');
        } catch (ValidationException $exception) {
            $this->assertSame('This indicator requires a whole-number target.', $exception->errors()['value'][0]);
        }

        $assignment->indicator->update([
            'data_type' => 'percentage',
            'precision' => 1,
            'min_value' => null,
            'max_value' => null,
        ]);

        try {
            app(TargetService::class)->prepareAndValidate([
                'indicator_assignment_id' => $assignment->id,
                'period' => '2026-08-01',
                'period_type' => 'monthly',
                'target_type' => 'numeric',
                'value' => 101,
                'active' => true,
            ]);
            $this->fail('A percentage target above 100 should not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame('The target may not exceed 100.', $exception->errors()['value'][0]);
        }
    }

    public function test_status_targets_are_limited_to_the_indicators_accepted_options(): void
    {
        $assignment = $this->createAssignment();
        $assignment->indicator->update([
            'data_type' => 'status',
            'direction' => 'status',
            'options' => ['Open', 'Complete'],
        ]);

        $prepared = app(TargetService::class)->prepareAndValidate([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-01',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 50,
            'status_value' => 'Complete',
            'active' => true,
        ]);

        $this->assertSame('status', $prepared['target_type']);
        $this->assertSame('Complete', $prepared['status_value']);
        $this->assertNull($prepared['value']);

        $this->expectException(ValidationException::class);
        app(TargetService::class)->prepareAndValidate([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-09-01',
            'period_type' => 'monthly',
            'status_value' => 'Unknown',
            'active' => true,
        ]);
    }

    public function test_editing_a_target_keeps_its_indicator_project_and_quarter_fixed(): void
    {
        $assignment = $this->createAssignment();
        $assignment->indicator->update(['frequency' => 'quarterly']);
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $target = app(TargetService::class)->save([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-15',
            'period_type' => 'quarterly',
            'target_type' => 'numeric',
            'value' => 30,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->get(IndicatorTargetResource::getUrl('edit', ['record' => $target]))
            ->assertOk()
            ->assertSee('FY 2026–27 · Q2')
            ->assertDontSee('Select an indicator')
            ->assertDontSee('Period containing date');

        Livewire::actingAs($admin)
            ->test(EditIndicatorTarget::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'indicator_assignment_id' => 999999,
                'period' => '2027-01-01',
                'target_type' => 'numeric',
                'value' => 35,
                'active' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($assignment->id, $target->fresh()->indicator_assignment_id);
        $this->assertSame('2026-07-01', $target->fresh()->period->toDateString());
        $this->assertSame(35.0, (float) $target->fresh()->value);
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
        $this->actingAs($admin)
            ->get('/admin/indicator-targets')
            ->assertOk()
            ->assertSee('Set targets');
        $this->actingAs($admin)->get('/admin/indicator-targets/create')->assertNotFound();
        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('AASRAA MnE', false)
            ->assertSee(route('dashboard'), false)
            ->assertDontSee('Back to main dashboard');
    }

    public function test_administrator_can_manage_project_indicators_without_setting_targets(): void
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
                'evidence_required' => false,
                'max_file_kb' => 5120,
                'min_files' => 0,
                'max_files' => 5,
                'approval_required' => true,
                'active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Sessions delivered by centre', $assignment->indicator->fresh()->name);
        $this->assertSame(2, $assignment->indicator->fresh()->centres()->count());
        $this->assertDatabaseCount('indicator_targets', 0);
    }

    public function test_quarterly_targets_can_be_set_for_multiple_quarters_and_all_selected_projects(): void
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
        $assignment->indicator->update(['frequency' => 'quarterly', 'target_tracking' => true]);
        $assignment->indicator->centres()->sync([$assignment->centre_id, $secondCentre->id]);

        $this->actingAs($admin)
            ->get(IndicatorTargetResource::getUrl('set'))
            ->assertOk()
            ->assertSee('Set indicator targets');

        Livewire::actingAs($admin)
            ->test(PlanIndicatorTargets::class)
            ->set('indicatorId', $assignment->indicator->id)
            ->set('financialYearStart', 2026)
            ->assertSee('FY 2026–27 · Q1')
            ->set('selectedPeriodKeys', ['20260401', '20260701', '20261001'])
            ->assertSee('2. Enter target values')
            ->assertSee('3 period(s) selected for 2 project(s).')
            ->set('targets.20260401.shared.value', '10')
            ->set('targets.20260701.shared.value', '20')
            ->set('targets.20261001.shared.value', '30')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('indicator_targets', 6);
        $this->assertSame(
            ['2026-04-01' => [10.0, 10.0], '2026-07-01' => [20.0, 20.0], '2026-10-01' => [30.0, 30.0]],
            IndicatorTarget::query()
                ->orderBy('period')
                ->orderBy('indicator_assignment_id')
                ->get()
                ->groupBy(fn (IndicatorTarget $target): string => $target->period->toDateString())
                ->map(fn ($targets): array => $targets->map(fn (IndicatorTarget $target): float => (float) $target->value)->all())
                ->all(),
        );

        $secondAssignment = $assignment->indicator->assignments()->where('centre_id', $secondCentre->id)->firstOrFail();
        Livewire::actingAs($admin)
            ->test(PlanIndicatorTargets::class)
            ->set('indicatorId', $assignment->indicator->id)
            ->set('financialYearStart', 2026)
            ->set('selectedPeriodKeys', ['20260401'])
            ->set('sameTargetAllProjects', false)
            ->set("targets.20260401.projects.{$assignment->id}.value", '11')
            ->set("targets.20260401.projects.{$secondAssignment->id}.value", '12')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('indicator_targets', 6);
        $this->assertSame(
            [11.0, 12.0],
            IndicatorTarget::query()->whereDate('period', '2026-04-01')->orderBy('indicator_assignment_id')->get()
                ->map(fn (IndicatorTarget $target): float => (float) $target->value)->all(),
        );
    }

    public function test_monthly_planner_saves_only_filled_months(): void
    {
        $assignment = $this->createAssignment();
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);

        Livewire::actingAs($admin)
            ->test(PlanIndicatorTargets::class)
            ->set('indicatorId', $assignment->indicator->id)
            ->set('financialYearStart', 2026)
            ->assertSee('April 2026')
            ->assertSee('March 2027')
            ->set('selectedPeriodKeys', ['20260401'])
            ->set('targets.20260401.shared.value', '5')
            ->set('targets.20260501.shared.value', '999')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('indicator_targets', 1);
        $target = IndicatorTarget::query()->firstOrFail();
        $this->assertSame($assignment->id, $target->indicator_assignment_id);
        $this->assertSame('2026-04-01', $target->period->toDateString());
        $this->assertSame('monthly', $target->period_type);
    }

    public function test_target_period_selector_follows_half_yearly_and_annual_frequencies(): void
    {
        $assignment = $this->createAssignment();
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $assignment->indicator->update(['frequency' => 'half_yearly']);

        Livewire::actingAs($admin)
            ->test(PlanIndicatorTargets::class)
            ->set('indicatorId', $assignment->indicator->id)
            ->set('financialYearStart', 2026)
            ->assertSee('FY 2026–27 · H1')
            ->assertSee('FY 2026–27 · H2');

        $assignment->indicator->update(['frequency' => 'annual']);

        Livewire::actingAs($admin)
            ->test(PlanIndicatorTargets::class)
            ->set('indicatorId', $assignment->indicator->id)
            ->set('financialYearStart', 2026)
            ->assertSee('FY 2026–27 (Apr 2026–Mar 2027)');
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
            'target_tracking' => false,
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

        $this->assertDatabaseHas('indicator_entries', ['indicator_assignment_id' => $assignment->id, 'status' => 'FINAL APPROVED']);
        $this->assertDatabaseHas('entry_revisions', ['text_value' => 'On track', 'status' => 'FINAL APPROVED']);
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
