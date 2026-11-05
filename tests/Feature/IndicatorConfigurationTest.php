<?php

namespace Tests\Feature;

use App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource;
use App\Filament\Resources\IndicatorDefinitions\Pages\CreateIndicatorDefinition;
use App\Filament\Resources\IndicatorDefinitions\Pages\EditIndicatorDefinition;
use App\Filament\Resources\IndicatorDefinitions\Pages\ListIndicatorDefinitions;
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
use App\Services\IndicatorReplacementService;
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

    public function test_indicator_categories_are_limited_to_the_approved_monitoring_levels(): void
    {
        $this->assertSame(['Input', 'Activity', 'Output', 'Outcome'], config('impact.categories'));
    }

    public function test_same_active_indicator_concept_cannot_use_different_categories(): void
    {
        $existing = $this->createAssignment()->indicator;

        try {
            IndicatorDefinition::create([
                'project_id' => $existing->project_id,
                'code' => 'TEST-DUPLICATE-CONCEPT',
                'name' => '  sessions DELIVERED ',
                'description' => 'A conflicting classification of the same indicator concept.',
                'category' => 'Input',
                'unit' => 'sessions',
                'data_type' => 'integer',
                'frequency' => 'monthly',
                'time_aggregation' => 'SUM',
                'centre_aggregation' => 'SUM',
                'active' => true,
            ]);
            $this->fail('The same active indicator concept should not be classified under two categories.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('category', $exception->errors());
        }

        $this->assertDatabaseMissing('indicator_definitions', ['code' => 'TEST-DUPLICATE-CONCEPT']);
    }

    public function test_programme_catalogue_is_read_only_while_monitoring_setup_remains_editable(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $programme = $this->createAssignment()->indicator->project;

        $this->assertFalse(ProjectResource::canCreate());
        $this->assertFalse(ProjectResource::canDelete($programme));
        $this->assertSame('Programmes', ProjectResource::getNavigationLabel());
        $this->actingAs($admin)->get('/admin/programmes/create')->assertNotFound();
        $this->actingAs($admin)
            ->get(route('programmes.show', $programme))
            ->assertOk()
            ->assertDontSee('Manage programme &amp; indicators', false)
            ->assertDontSee('Enter data');

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

    public function test_indicator_code_is_permanent_even_before_reporting_starts(): void
    {
        $indicator = $this->createAssignment()->indicator;

        try {
            $indicator->update(['code' => 'CHANGED-CODE']);
            $this->fail('The permanent indicator code should not be editable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }

        $this->assertSame('TEST-I', $indicator->fresh()->code);
    }

    public function test_indicator_with_history_only_allows_descriptive_corrections_and_archiving(): void
    {
        $assignment = $this->createAssignment();
        IndicatorTarget::create([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-01',
            'period_end' => '2026-08-31',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 20,
            'active' => true,
        ]);

        $assignment->indicator->update([
            'name' => 'Corrected sessions delivered',
            'description' => 'Corrected wording only.',
            'data_sources' => 'Attendance register',
            'active' => false,
        ]);

        $this->assertSame('Corrected sessions delivered', $assignment->indicator->fresh()->name);
        $this->assertFalse($assignment->indicator->fresh()->active);

        try {
            $assignment->indicator->update(['unit' => 'children']);
            $this->fail('A structural setting should not change after targets exist.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('unit', $exception->errors());
        }

        $this->assertSame('sessions', $assignment->indicator->fresh()->unit);
        $this->assertDatabaseHas('indicator_targets', ['indicator_assignment_id' => $assignment->id, 'value' => 20]);
    }

    public function test_historical_indicator_form_locks_configuration_and_offers_replacement(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $assignment = $this->createAssignment();
        $assignment->indicator->update([
            'effective_date' => '2026-04-01',
            'min_value' => null,
            'max_value' => null,
        ]);
        IndicatorTarget::create([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-01',
            'period_end' => '2026-08-31',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 20,
            'active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(EditIndicatorDefinition::class, ['record' => $assignment->indicator->getRouteKey()])
            ->assertActionExists('createReplacement')
            ->assertFormFieldEnabled('name')
            ->assertFormFieldEnabled('description')
            ->assertFormFieldEnabled('active')
            ->assertFormFieldHidden('project_id')
            ->assertSchemaComponentVisible('programme_readonly')
            ->assertFormFieldHidden('code')
            ->assertSchemaComponentVisible('code_readonly')
            ->assertFormFieldHidden('category')
            ->assertFormFieldHidden('unit')
            ->assertFormFieldHidden('data_type')
            ->assertFormFieldHidden('centres')
            ->assertFormFieldHidden('time_aggregation')
            ->assertFormFieldHidden('evidence_required')
            ->assertSchemaComponentVisible('historical_configuration_notice')
            ->assertSchemaComponentVisible('locked_basic_configuration')
            ->assertSchemaComponentVisible('locked_input_configuration')
            ->assertSchemaComponentVisible('locked_project_assignments')
            ->assertSchemaComponentVisible('locked_aggregation_configuration')
            ->assertSchemaComponentVisible('locked_evidence_configuration')
            ->assertSee('Review mode')
            ->assertSee('Starts: Apr 2026')
            ->assertSee('No minimum or maximum limit')
            ->assertSee($assignment->centre->name)
            ->fillForm([
                'name' => 'Corrected historical indicator name',
                'description' => 'Only descriptive information was corrected.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Corrected historical indicator name', $assignment->indicator->fresh()->name);

        Livewire::actingAs($admin)
            ->test(IndicatorsRelationManager::class, [
                'ownerRecord' => $assignment->indicator->project,
                'pageClass' => EditProject::class,
            ])
            ->callTableAction('edit', $assignment->indicator, [
                'name' => 'Corrected from programme indicators',
                'description' => 'The reporting setup must remain unchanged.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('Corrected from programme indicators', $assignment->indicator->fresh()->name);
    }

    public function test_administrator_can_delete_an_unused_indicator_but_not_reporting_history(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $unusedAssignment = $this->createAssignment();
        $unusedIndicator = $unusedAssignment->indicator;
        $historicalIndicator = $unusedIndicator->replicate();
        $historicalIndicator->code = 'TEST-HISTORICAL';
        $historicalIndicator->name = 'Historical sessions delivered';
        $historicalIndicator->save();
        $historicalAssignment = IndicatorAssignment::create([
            'indicator_definition_id' => $historicalIndicator->id,
            'centre_id' => $unusedAssignment->centre_id,
            'required' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ListIndicatorDefinitions::class)
            ->assertTableActionEnabled('delete', $unusedIndicator)
            ->callTableAction('delete', $unusedIndicator)
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('indicator_definitions', ['id' => $unusedIndicator->id]);
        $this->assertDatabaseMissing('indicator_assignments', ['indicator_definition_id' => $unusedIndicator->id]);
        $this->assertDatabaseMissing('indicator_definition_versions', ['indicator_definition_id' => $unusedIndicator->id]);

        IndicatorTarget::create([
            'indicator_assignment_id' => $historicalAssignment->id,
            'period' => '2026-08-01',
            'period_end' => '2026-08-31',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 20,
            'active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ListIndicatorDefinitions::class)
            ->assertTableActionDisabled('delete', $historicalAssignment->indicator);

        $this->assertDatabaseHas('indicator_definitions', ['id' => $historicalAssignment->indicator->id]);
        $this->assertDatabaseHas('indicator_targets', ['indicator_assignment_id' => $historicalAssignment->id]);
    }

    public function test_replacement_archives_the_original_and_copies_no_history(): void
    {
        $assignment = $this->createAssignment();
        $target = IndicatorTarget::create([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-01',
            'period_end' => '2026-08-31',
            'period_type' => 'monthly',
            'target_type' => 'numeric',
            'value' => 20,
            'active' => true,
        ]);
        $replacements = app(IndicatorReplacementService::class);

        $this->assertSame('2026-09', $replacements->earliestMonth($assignment->indicator));
        try {
            $replacements->replace($assignment->indicator, '2026-08');
            $this->fail('A replacement should not overlap an existing target or entry month.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_from', $exception->errors());
        }

        $replacement = $replacements->replace($assignment->indicator, '2026-09');

        $this->assertFalse($assignment->indicator->fresh()->active);
        $this->assertTrue($replacement->active);
        $this->assertSame('2026-09-01', $replacement->effective_date->toDateString());
        $this->assertSame($assignment->indicator->name, $replacement->name);
        $this->assertSame($assignment->indicator->data_type, $replacement->data_type);
        $this->assertNotSame($assignment->indicator->code, $replacement->code);
        $this->assertStringContainsString('-R-202609', $replacement->code);
        $this->assertEqualsCanonicalizing([$assignment->centre_id], $replacement->assignments()->pluck('centre_id')->all());
        $this->assertDatabaseCount('indicator_targets', 1);
        $this->assertDatabaseHas('indicator_targets', ['id' => $target->id, 'indicator_assignment_id' => $assignment->id]);
        $this->assertFalse($replacement->targets()->exists());
        $this->assertFalse($replacement->assignments()->whereHas('entries')->exists());
    }

    public function test_indicator_service_applies_percentage_and_ratio_rules(): void
    {
        $percentage = app(IndicatorService::class)->prepareAndValidate([
            'data_type' => 'percentage',
            'precision' => 1,
            'direction' => 'higher',
            'min_files' => 0,
            'max_files' => 5,
        ]);
        $ratio = app(IndicatorService::class)->prepareAndValidate([
            'data_type' => 'calculated',
            'time_aggregation' => 'SUM',
            'centre_aggregation' => 'AVERAGE',
            'direction' => 'higher',
            'min_files' => 0,
            'max_files' => 5,
        ]);
        $evidence = app(IndicatorService::class)->prepareAndValidate([
            'data_type' => 'integer',
            'evidence_required' => true,
            'evidence_review_required' => true,
            'direction' => 'higher',
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
        $this->assertSame(['RATIO', 'NON-AGGREGATABLE'], array_keys($indicators->acrossProjectOptions('calculated')));
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

    public function test_target_service_normalizes_to_the_month_and_finds_target(): void
    {
        $assignment = $this->createAssignment();
        $data = app(TargetService::class)->prepareAndValidate([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-18',
            'value' => 60,
        ]);
        $target = IndicatorTarget::create($data);

        $this->assertSame('2026-08-01', $target->period->toDateString());
        $this->assertSame('2026-08-31', $target->period_end->toDateString());
        $this->assertTrue($target->is(app(TargetService::class)->findForPeriod($assignment->id, CarbonImmutable::parse('2026-08-20'))));
    }

    public function test_target_period_is_always_monthly(): void
    {
        $assignment = $this->createAssignment();

        $prepared = app(TargetService::class)->prepareAndValidate([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-18',
            'period_type' => 'quarterly',
            'value' => 60,
        ]);

        $this->assertSame('monthly', $prepared['period_type']);
        $this->assertSame('2026-08-01', $prepared['period']);
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

    public function test_updating_a_target_keeps_its_indicator_project_and_month_fixed(): void
    {
        $assignment = $this->createAssignment();
        $target = app(TargetService::class)->save([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-15',
            'value' => 30,
        ]);
        $updated = app(TargetService::class)->save([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-08-22',
            'value' => 35,
        ]);

        $this->assertTrue($target->is($updated));
        $this->assertSame($assignment->id, $updated->indicator_assignment_id);
        $this->assertSame('2026-08-01', $updated->period->toDateString());
        $this->assertSame(35.0, (float) $updated->value);
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
            ->assertSee('Reporting frequency')
            ->assertDontSee('Reported value is required');
        $this->actingAs($admin)
            ->get('/admin/indicator-targets')
            ->assertOk()
            ->assertSee('Open monthly target planner');
        $this->actingAs($admin)->get('/admin/indicator-targets/create')->assertNotFound();
        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('AASRAA MnE', false)
            ->assertSeeInOrder(['Programmes', 'Projects', 'Indicators'])
            ->assertSee(route('dashboard'), false)
            ->assertDontSee('Back to main dashboard');
    }

    public function test_indicator_management_has_its_own_navigation_and_selection_filters(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $assignment = $this->createAssignment();

        $this->assertSame('Indicators', IndicatorDefinitionResource::getNavigationLabel());
        $this->assertTrue(IndicatorDefinitionResource::shouldRegisterNavigation());

        Livewire::actingAs($admin)
            ->test(ListIndicatorDefinitions::class)
            ->assertTableFilterExists('project')
            ->assertTableFilterExists('centre')
            ->assertTableFilterExists('category')
            ->assertTableFilterExists('reporting_scope')
            ->assertTableFilterExists('data_type')
            ->assertTableFilterExists('target_tracking')
            ->assertTableFilterExists('active')
            ->filterTable('project', $assignment->indicator->project_id)
            ->filterTable('centre', $assignment->centre_id)
            ->filterTable('category', 'Activity')
            ->filterTable('reporting_scope', 'project')
            ->filterTable('target_tracking', true)
            ->filterTable('active', true)
            ->assertCanSeeTableRecords([$assignment->indicator]);
    }

    public function test_administrator_can_create_a_project_indicator_from_the_separate_indicator_section(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $assignment = $this->createAssignment();

        Livewire::actingAs($admin)
            ->test(CreateIndicatorDefinition::class)
            ->assertSchemaStateSet(['evidence_required' => true]);

        Livewire::actingAs($admin)
            ->test(CreateIndicatorDefinition::class)
            ->fillForm([
                'project_id' => $assignment->indicator->project_id,
                'code' => 'SEPARATE-INDICATOR',
                'name' => 'Participants completing support',
                'description' => 'Created from the standalone indicator management section.',
                'category' => 'Output',
                'unit' => 'people',
                'reporting_scope' => 'project',
                'effective_date' => '2026-04',
                'data_type' => 'integer',
                'min_value' => 0,
                'centres' => [$assignment->centre_id],
                'target_centre_ids' => [$assignment->centre_id],
                'time_aggregation' => 'SUM',
                'centre_aggregation' => 'SUM',
                'direction' => 'higher',
                'evidence_required' => false,
                'max_file_kb' => 5120,
                'min_files' => 0,
                'max_files' => 5,
                'approval_required' => true,
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $indicator = IndicatorDefinition::query()->where('code', 'SEPARATE-INDICATOR')->firstOrFail();
        $this->assertSame($assignment->indicator->project_id, $indicator->project_id);
        $this->assertTrue($indicator->centres()->whereKey($assignment->centre_id)->exists());

        $secondCentre = Centre::create([
            'code' => 'C-SEPARATE-2',
            'name' => 'Second separate project',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
        ]);
        $indicator->project->centres()->attach($secondCentre);

        Livewire::actingAs($admin)
            ->test(EditIndicatorDefinition::class, ['record' => $indicator->getRouteKey()])
            ->assertSchemaStateSet(['centres' => [$assignment->centre_id]])
            ->assertSchemaComponentVisible('centres')
            ->assertSchemaComponentHidden('programme_reporting_assignment')
            ->fillForm([
                'name' => 'Participants completing monthly support',
                'centres' => [$assignment->centre_id, $secondCentre->id],
                'target_centre_ids' => [$assignment->centre_id, $secondCentre->id],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Participants completing monthly support', $indicator->fresh()->name);
        $this->assertSame(2, $indicator->centres()->count());
        $this->assertDatabaseCount('indicator_targets', 0);
    }

    public function test_programme_indicator_explains_why_project_assignments_are_not_shown(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $assignment = $this->createAssignment();
        $indicator = $assignment->indicator;
        $assignment->delete();
        $indicator->update([
            'reporting_scope' => 'programme',
            'centre_aggregation' => 'NON-AGGREGATABLE',
        ]);
        $indicator->assignments()->create([
            'centre_id' => null,
            'required' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(EditIndicatorDefinition::class, ['record' => $indicator->getRouteKey()])
            ->assertSchemaComponentHidden('centres')
            ->assertSchemaComponentVisible('programme_reporting_assignment')
            ->assertSee('This indicator is reported once for the whole programme');
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

    public function test_target_management_page_is_excel_only(): void
    {
        $assignment = $this->createAssignment();
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $assignment->indicator->update(['frequency' => 'monthly', 'target_tracking' => true]);

        $this->actingAs($admin)
            ->get(route('targets.index', [
                'programme' => $assignment->indicator->project_id,
                'financial_year' => 2026,
            ]))
            ->assertOk()
            ->assertSee('Download Excel template')
            ->assertSee('Upload the completed template')
            ->assertDontSee('Save monthly targets')
            ->assertDontSee('name="targets[', false);
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
            'target_tracking' => true,
            'direction' => 'status',
        ]);
        app(TargetService::class)->save([
            'indicator_assignment_id' => $assignment->id,
            'period' => '2026-10-01',
            'status_value' => 'On track',
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
