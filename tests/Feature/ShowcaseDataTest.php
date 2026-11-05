<?php

namespace Tests\Feature;

use App\Models\ApprovalAction;
use App\Models\AuditLog;
use App\Models\Centre;
use App\Models\EvidenceFile;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use App\Services\EntryService;
use App\Services\TargetService;
use App\Support\WorkflowStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShowcaseDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_showcase_is_minimal_complete_repeatable_and_removable(): void
    {
        Storage::fake('local');
        $this->createAuthoritativeCatalogue();
        $administrator = User::factory()->create([
            'name' => 'Siddharth Jain',
            'email' => 'siddharth@aasraatrust.org',
            'role' => 'ADMIN',
            'active' => true,
            'is_demo' => false,
        ]);

        $this->artisan('impact:seed-showcase-data', ['--year' => 2026])->assertSuccessful();

        $this->assertDatabaseCount('users', 7);
        $this->assertDatabaseCount('programmes', 7);
        $this->assertDatabaseCount('projects', 7);
        $this->assertDatabaseCount('centres', 14);
        $this->assertSame(8, IndicatorDefinition::query()->where('is_demo', true)->count());
        $this->assertSame(5 * (now()->month + 1), IndicatorEntry::query()->where('is_demo', true)->count());
        $this->assertSame(2, IndicatorEntry::query()->where('is_demo', true)->where('status', WorkflowStatus::PendingMneHead)->count());
        $this->assertDatabaseCount('indicator_targets', 5 * (now()->month + 1));
        $this->assertDatabaseCount('evidence_files', 0);
        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseCount('import_batches', 0);

        $this->assertEqualsCanonicalizing(
            ['integer', 'decimal', 'ratio', 'date', 'single_select', 'multi_select'],
            IndicatorDefinition::query()->distinct()->pluck('data_type')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['FINAL APPROVED', 'PENDING M&E HEAD', 'PENDING SENIOR PL'],
            IndicatorEntry::query()->distinct()->pluck('status')->all(),
        );
        $this->assertEqualsCanonicalizing(
            config('impact.categories'),
            IndicatorDefinition::query()->distinct()->pluck('category')->all(),
        );
        $this->assertFalse(IndicatorDefinition::query()->whereIn('category', ['KPI', 'Milestone', 'Qualitative Indicator'])->exists());
        $this->assertFalse(IndicatorDefinition::query()->where('name', 'Monthly programme participation')->exists());
        $showcaseNames = IndicatorDefinition::query()->where('is_demo', true)->pluck('name');
        $this->assertSame($showcaseNames->count(), $showcaseNames->map(fn (string $name): string => mb_strtolower(trim($name)))->unique()->count());
        $this->assertTrue(IndicatorEntry::query()->whereDate('period', '2026-01-01')->where('is_demo', true)->exists());
        $this->assertTrue(IndicatorEntry::query()->whereDate('period', '2026-10-01')->where('is_demo', true)->exists());
        $this->assertDatabaseHas('indicator_targets', ['target_type' => 'numeric', 'is_demo' => true]);
        $this->assertDatabaseHas('indicator_targets', ['target_type' => 'ratio', 'is_demo' => true]);
        IndicatorEntry::query()
            ->where('is_demo', true)
            ->whereIn('status', WorkflowStatus::pending())
            ->each(function (IndicatorEntry $entry): void {
                $this->assertTrue(
                    $entry->assignment->targets()
                        ->where('period_type', 'monthly')
                        ->where('active', true)
                        ->whereDate('period', $entry->period)
                        ->exists(),
                );
            });

        $pendingEntry = IndicatorEntry::query()
            ->where('status', WorkflowStatus::PendingSeniorProgrammeLead)
            ->firstOrFail();
        app(EntryService::class)->review($administrator, $pendingEntry, 'APPROVED', null);
        app(EntryService::class)->review($administrator, $pendingEntry->fresh(), 'APPROVED', null);

        $this->assertSame(
            2,
            ApprovalAction::query()
                ->where('entry_revision_id', $pendingEntry->current_revision_id)
                ->where('is_demo', true)
                ->count(),
        );

        $targetAssignmentId = IndicatorDefinition::query()
            ->where('is_demo', true)
            ->where('data_type', 'integer')
            ->firstOrFail()
            ->assignments()
            ->value('id');
        $futureTarget = app(TargetService::class)->save([
            'indicator_assignment_id' => $targetAssignmentId,
            'period' => '2027-04-01',
            'value' => 42,
            'notes' => null,
            'active' => true,
        ]);
        $this->assertTrue($futureTarget->is_demo);

        $mismatchedAction = ApprovalAction::query()
            ->where('entry_revision_id', $pendingEntry->current_revision_id)
            ->firstOrFail();
        $mismatchedAction->update(['is_demo' => false]);
        $futureTarget->update(['is_demo' => false]);
        foreach ([$mismatchedAction, $futureTarget] as $mismatchedRecord) {
            AuditLog::query()->create([
                'user_id' => $administrator->id,
                'action' => 'updated',
                'subject_type' => $mismatchedRecord::class,
                'subject_id' => $mismatchedRecord->id,
                'changes' => ['is_demo' => false],
                'is_demo' => false,
            ]);
        }

        $firstCounts = [
            IndicatorDefinition::query()->count(),
            IndicatorEntry::query()->count(),
            EvidenceFile::query()->count(),
        ];
        $this->artisan('impact:seed-showcase-data', ['--year' => 2026])->assertSuccessful();
        $this->assertSame($firstCounts, [
            IndicatorDefinition::query()->count(),
            IndicatorEntry::query()->count(),
            EvidenceFile::query()->count(),
        ]);
        $this->assertDatabaseCount('notifications', 3);
        $this->assertDatabaseMissing('audit_logs', [
            'subject_type' => $mismatchedAction::class,
            'subject_id' => $mismatchedAction->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'subject_type' => $futureTarget::class,
            'subject_id' => $futureTarget->id,
        ]);

        $this->artisan('impact:clear-demo-data', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('programmes', 7);
        $this->assertDatabaseCount('projects', 7);
        $this->assertDatabaseCount('centres', 14);
        $this->assertDatabaseCount('indicator_definitions', 0);
        $this->assertDatabaseCount('indicator_entries', 0);
        $this->assertDatabaseCount('evidence_files', 0);
        $this->assertDatabaseCount('notifications', 0);
        Storage::disk('local')->assertMissing('evidence/showcase/2026/summary.pdf');
    }

    public function test_showcase_command_refuses_to_run_outside_local_or_testing(): void
    {
        $this->createAuthoritativeCatalogue();
        User::factory()->create(['role' => 'ADMIN', 'active' => true, 'is_demo' => false]);
        $this->app['env'] = 'production';

        $this->artisan('impact:seed-showcase-data', ['--year' => 2026])->assertFailed();

        $this->assertDatabaseCount('indicator_definitions', 0);
    }

    private function createAuthoritativeCatalogue(): void
    {
        foreach (range(1, 7) as $programmeNumber) {
            $programme = Programme::query()->create([
                'name' => "Programme {$programmeNumber}",
                'description' => 'Authoritative programme.',
                'is_demo' => false,
            ]);
            $project = Project::query()->create([
                'programme_id' => $programme->id,
                'code' => sprintf('PRG-%02d', $programmeNumber),
                'name' => "Programme {$programmeNumber}",
                'description' => 'Authoritative reporting container.',
                'active' => true,
                'is_demo' => false,
            ]);

            foreach (range(1, 2) as $projectNumber) {
                $sourceProjectNumber = (($programmeNumber - 1) * 2) + $projectNumber;
                $centre = Centre::query()->create([
                    'source_project_id' => $sourceProjectNumber,
                    'code' => "ORG-{$sourceProjectNumber}",
                    'name' => "Source project {$sourceProjectNumber}",
                    'type' => 'Education Centre',
                    'region' => 'Not specified',
                    'source_program' => $programme->name,
                    'source_status' => 'Active',
                    'active' => true,
                    'is_demo' => false,
                ]);
                $project->centres()->attach($centre, [
                    'is_demo' => false,
                    'provenance' => 'authoritative project catalogue',
                ]);
            }
        }
    }
}
