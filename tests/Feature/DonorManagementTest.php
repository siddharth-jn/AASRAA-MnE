<?php

namespace Tests\Feature;

use App\Filament\Resources\CentreDonors\CentreDonorResource;
use App\Filament\Resources\Centres\Pages\EditCentre;
use App\Filament\Resources\Centres\RelationManagers\DonorAssignmentsRelationManager;
use App\Filament\Resources\Donors\DonorResource;
use App\Models\Centre;
use App\Models\CentreDonor;
use App\Models\Donor;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\DonorService;
use App\Services\EntryService;
use App\Services\ReportingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DonorManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_effective_local_donor_is_used_for_reporting_without_overwriting_the_source_donor(): void
    {
        [$user, $assignment] = $this->fixture();
        $donor = Donor::factory()->create(['code' => 'LOCAL', 'name' => 'Local Reporting Donor']);
        CentreDonor::query()->create([
            'centre_id' => $assignment->centre_id,
            'donor_id' => $donor->id,
            'starts_on' => '2026-04-01',
            'ends_on' => null,
            'is_primary' => true,
        ]);

        $entry = app(EntryService::class)->save($user, $assignment, '2026-10-01', [
            'numeric_value' => 10,
            'value_state' => 'reported',
        ], [], true);
        $this->assertNotNull($entry->approved_revision_id);

        $reports = app(ReportingService::class);
        $localRows = $reports->report($user, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), donor: 'Local Reporting Donor');
        $sourceRows = $reports->report($user, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), donor: 'Old Source Donor');

        $this->assertCount(1, $localRows);
        $this->assertSame('Local Reporting Donor', $localRows->first()['donor_display']);
        $this->assertCount(0, $sourceRows);
        $this->assertSame('Old Source Donor', $assignment->centre->fresh()->source_donor_name);

        $availableNames = app(DonorService::class)->availableNames(
            Centre::query()->whereKey($assignment->centre_id),
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-31'),
        );
        $this->assertSame(['Local Reporting Donor'], $availableNames->all());

        $donor->update(['active' => false]);
        $fallbackRows = $reports->report($user, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), donor: 'Old Source Donor');
        $this->assertCount(1, $fallbackRows);
    }

    public function test_donor_assignments_are_managed_inside_projects_without_separate_navigation(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $donor = Donor::factory()->create(['active' => true]);
        $centre = Centre::query()->create([
            'code' => 'PROJECT-1',
            'name' => 'Project One',
            'type' => 'Education Centre',
            'region' => 'Dehradun',
        ]);

        $this->assertFalse(CentreDonorResource::shouldRegisterNavigation());
        $this->assertTrue(DonorResource::shouldRegisterNavigation());
        $this->assertNull(DonorResource::getNavigationGroup());

        Livewire::actingAs($admin)
            ->test(DonorAssignmentsRelationManager::class, [
                'ownerRecord' => $centre,
                'pageClass' => EditCentre::class,
            ])
            ->assertSee('Assign donor')
            ->callTableAction('create', data: [
                'donor_id' => $donor->id,
                'starts_on' => '2026-04-01',
                'ends_on' => null,
                'is_primary' => true,
                'notes' => 'Primary project donor',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('centre_donors', [
            'centre_id' => $centre->id,
            'donor_id' => $donor->id,
            'starts_on' => '2026-04-01 00:00:00',
            'ends_on' => null,
            'is_primary' => true,
            'notes' => 'Primary project donor',
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Donors')
            ->assertDontSee('Project donors')
            ->assertDontSee('Administration');
    }

    /** @return array{User, IndicatorAssignment} */
    private function fixture(): array
    {
        $user = User::factory()->create(['role' => 'M&E HEAD', 'active' => true]);
        $programme = Programme::query()->create(['name' => 'Education', 'description' => 'Test']);
        $project = Project::query()->create(['programme_id' => $programme->id, 'code' => 'EDU', 'name' => 'Education', 'description' => 'Test']);
        $centre = Centre::query()->create(['code' => 'C1', 'name' => 'Project One', 'type' => 'Education Centre', 'region' => 'Dehradun', 'source_donor_name' => 'Old Source Donor']);
        $project->centres()->attach($centre);
        $indicator = IndicatorDefinition::query()->create([
            'project_id' => $project->id,
            'code' => 'ACT-1',
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
        IndicatorTarget::query()->create(['indicator_assignment_id' => $assignment->id, 'period' => '2026-10-01', 'period_end' => '2026-10-31', 'period_type' => 'monthly', 'target_type' => 'numeric', 'value' => 10, 'active' => true]);

        return [$user, $assignment->load(['indicator.project', 'centre'])];
    }
}
