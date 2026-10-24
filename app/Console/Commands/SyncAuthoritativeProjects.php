<?php

namespace App\Console\Commands;

use App\Models\AuthoritativeProject;
use App\Services\AuthoritativeProjectSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('impact:sync-authoritative-projects {--dry-run : Inspect the changes and roll back all local writes}')]
#[Description('Mirror active authoritative projects into the local monitoring database')]
class SyncAuthoritativeProjects extends Command
{
    public function handle(AuthoritativeProjectSyncService $syncService): int
    {
        $sourceProjects = AuthoritativeProject::query()
            ->operational()
            ->leftJoin('donors', 'donors.id', '=', 'projects.donor_id')
            ->select([
                'projects.id',
                'projects.name',
                'projects.program',
                'projects.description',
                'projects.status',
                'projects.timing_slot',
                'projects.address',
                'projects.location',
                'projects.started_date',
                'projects.poc_name',
                'projects.poc_contact',
                'projects.poc_designation',
                'projects.donor_id',
                'donors.name as donor_name',
                'projects.budget_head',
            ])
            ->orderBy('projects.id')
            ->get();

        if ($sourceProjects->isEmpty()) {
            $this->components->error('The authoritative source returned no active projects. No local data was changed.');

            return self::FAILURE;
        }

        $sourceStatuses = AuthoritativeProject::query()
            ->withoutDemoProgramme()
            ->select(['projects.id', 'projects.status'])
            ->get();

        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            DB::beginTransaction();
        }

        try {
            $statistics = $syncService->sync($sourceProjects);
            $statistics['status_updates'] = $syncService->reconcileStatuses($sourceStatuses);

            if ($isDryRun) {
                DB::rollBack();
            }
        } catch (Throwable $exception) {
            if ($isDryRun && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        $this->table(['Result', 'Count'], [
            ['Active source projects', $statistics['source']],
            ['Local projects created', $statistics['created']],
            ['Local projects updated', $statistics['updated']],
            ['Matched by existing name', $statistics['matched_by_name']],
            ['Soft-deleted projects restored', $statistics['restored']],
            ['Programme links added', $statistics['programme_links_created']],
            ['Existing project statuses changed', $statistics['status_updates']],
        ]);

        if ($statistics['unmapped_source_programmes'] !== []) {
            $this->components->warn('No programme mapping: '.implode(', ', $statistics['unmapped_source_programmes']));
        }

        if ($statistics['missing_local_programmes'] !== []) {
            $this->components->warn('Mapped local programmes not found: '.implode(', ', $statistics['missing_local_programmes']));
        }

        $message = $isDryRun
            ? 'Dry run complete. All local changes were rolled back.'
            : 'Authoritative projects synchronized. The source database was not modified.';
        $this->components->info($message);

        return self::SUCCESS;
    }
}
