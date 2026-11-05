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
use App\Services\ReportPdfService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PdfReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_export_uses_the_fixed_report_template_and_approved_results(): void
    {
        [$user, $assignment] = $this->fixture();
        app(EntryService::class)->save($user, $assignment, '2026-10-01', [
            'numeric_value' => 10,
            'value_state' => 'reported',
        ], [], true);

        $response = $this->actingAs($user)->get(route('reports.pdf', [
            'period_type' => 'monthly',
            'anchor' => '2026-10',
            'mode' => 'detailed',
        ]));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_large_annual_pdf_renders_within_the_standard_memory_limit(): void
    {
        $rows = collect();
        for ($projectIndex = 1; $projectIndex <= 100; $projectIndex++) {
            $project = new Project(['name' => "Programme {$projectIndex}"]);
            $centre = new Centre(['name' => "Project {$projectIndex}"]);

            for ($indicatorIndex = 1; $indicatorIndex <= 20; $indicatorIndex++) {
                $indicator = new IndicatorDefinition([
                    'name' => "Indicator {$indicatorIndex}",
                    'code' => "I-{$projectIndex}-{$indicatorIndex}",
                    'category' => ['Input', 'Activity', 'Output', 'Outcome'][$indicatorIndex % 4],
                    'unit' => 'people',
                    'precision' => 0,
                ]);
                $rows->push([
                    'project' => $project,
                    'centre' => $centre,
                    'scope' => 'project',
                    'indicator' => $indicator,
                    'donor_display' => 'General',
                    'target_display' => '100',
                    'achievement' => 95.0,
                    'variance' => -5.0,
                    'achievement_percentage' => -5.0,
                    'performance_status' => 'On track',
                    'approved_months' => 12,
                    'expected_months' => 12,
                    'variance_explanations' => new Collection,
                ]);
            }
        }

        $contents = app(ReportPdfService::class)->render($rows, [
            'start' => CarbonImmutable::parse('2026-04-01'),
            'end' => CarbonImmutable::parse('2027-03-31'),
            'data_end' => CarbonImmutable::parse('2027-03-31'),
            'provisional' => false,
        ], [
            'mode' => 'Detailed project report',
            'programme' => 'All programmes',
            'project' => 'All projects',
            'category' => 'All indicator types',
            'donor' => 'All donors',
            'performance' => 'All performance statuses',
            'generated_at' => '31 Mar 2027, 18:00',
        ], [
            'expected' => 2000,
            'reported' => 2000,
            'approved' => 2000,
            'missing' => 0,
        ]);

        $this->assertStringStartsWith('%PDF-', $contents);
        $this->assertGreaterThan(100000, strlen($contents));
        $this->assertMatchesRegularExpression('/\\/MediaBox\\s*\\[\\s*0(?:\\.0+)?\\s+0(?:\\.0+)?\\s+841\\.890\\s+595\\.280\\s*\\]/', $contents);
    }

    /** @return array{User, IndicatorAssignment} */
    private function fixture(): array
    {
        $user = User::factory()->create(['role' => 'M&E HEAD', 'active' => true]);
        $programme = Programme::query()->create(['name' => 'Education', 'description' => 'Test']);
        $project = Project::query()->create(['programme_id' => $programme->id, 'code' => 'EDU', 'name' => 'Education', 'description' => 'Test']);
        $centre = Centre::query()->create(['code' => 'C1', 'name' => 'Project One', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $project->centres()->attach($centre);
        $indicator = IndicatorDefinition::query()->create([
            'project_id' => $project->id,
            'code' => 'ACT-1',
            'name' => 'Sessions delivered',
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
