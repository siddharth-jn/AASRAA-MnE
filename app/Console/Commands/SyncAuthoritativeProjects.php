<?php

namespace App\Console\Commands;

use App\Models\SourceSyncRun;
use App\Services\AuthoritativeProjectSourceService;
use App\Services\AuthoritativeProjectSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('impact:sync-authoritative-projects {--dry-run : Preview source changes without changing local project records}')]
#[Description('Preview or synchronize the read-only Projects database into local reporting projects')]
class SyncAuthoritativeProjects extends Command
{
    public function handle(
        AuthoritativeProjectSourceService $source,
        AuthoritativeProjectSyncService $sync,
    ): int {
        $run = null;

        if (! config('impact.authoritative_project_sync_enabled')) {
            $this->components->error('Project synchronization is disabled. Set AUTHORITATIVE_PROJECT_SYNC_ENABLED=true to enable it.');

            return self::FAILURE;
        }

        try {
            $sourceProjects = $source->operationalProjects();
            $sourceStatuses = $source->projectStatuses();
            if ($sourceProjects->isEmpty()) {
                $this->components->error('The Projects database returned no active projects. No local data was changed.');

                return self::FAILURE;
            }

            $preview = $sync->preview($sourceProjects, $sourceStatuses);
            $this->displayPreview($preview);

            if ($this->option('dry-run')) {
                $this->components->info('Dry run complete. The source and local project records were not modified.');

                return self::SUCCESS;
            }

            if ($preview['blocked']) {
                $this->components->error('Synchronization is blocked by the highlighted preview items. No local project records were changed.');

                return self::FAILURE;
            }

            $run = SourceSyncRun::create([
                'source' => 'projects database',
                'snapshot_hash' => $source->snapshotHash($sourceProjects, $sourceStatuses),
                'status' => 'running',
                'summary' => $preview,
                'started_at' => now(),
                'is_demo' => false,
            ]);
            $statistics = $sync->sync($sourceProjects);
            $statistics['status_updates'] = $sync->reconcileStatuses($sourceStatuses);
            $run->update([
                'status' => 'completed',
                'summary' => [...$preview, 'applied' => $statistics],
                'completed_at' => now(),
            ]);

            $this->components->info('Projects synchronized locally. The source database was not modified.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($run) {
                $run->update([
                    'status' => 'failed',
                    'error' => str($exception->getMessage())->limit(2000),
                    'completed_at' => now(),
                ]);
            }
            report($exception);
            $this->components->error('Project synchronization failed. No source data was modified. '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $preview */
    private function displayPreview(array $preview): void
    {
        $this->table(['Preview', 'Count'], [
            ['Active source projects', $preview['source']],
            ['New projects', $preview['created']],
            ['Changed projects', $preview['updated']],
            ['Unchanged projects', $preview['unchanged']],
            ['Status changes', $preview['status_updates']],
            ['Programme links to add', $preview['programme_links_created']],
        ]);

        foreach ($preview['changes'] as $change) {
            $this->line("[{$change['type']}] ID {$change['source_id']} {$change['project']}");
            foreach ($change['details'] as $detail) {
                $this->line("  {$detail['field']}: {$detail['before']} -> {$detail['after']}");
            }
        }

        foreach (['unmapped_source_programmes', 'missing_local_programmes', 'potential_duplicates', 'missing_from_source', 'donor_conflicts'] as $warningKey) {
            foreach ($preview[$warningKey] as $warning) {
                $this->components->warn(str($warningKey)->headline().": {$warning}");
            }
        }
    }
}
