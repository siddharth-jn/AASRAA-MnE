<?php

namespace App\Console\Commands;

use App\Services\AuthoritativeDataTransitionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('impact:transition-authoritative-data {--dry-run : Inspect changes and roll back all database writes}')]
#[Description('Promote authoritative portfolio data and remove synthetic workflow records safely')]
class TransitionAuthoritativeData extends Command
{
    public function handle(AuthoritativeDataTransitionService $transitionService): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            DB::beginTransaction();
        }

        try {
            $statistics = $transitionService->transition();

            if ($isDryRun) {
                DB::rollBack();
            }
        } catch (Throwable $exception) {
            if ($isDryRun && DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }

        if (! $isDryRun) {
            Storage::disk('local')->delete($statistics['evidence_paths']);
        }

        $this->table(['Result', 'Count'], [
            ['Programme records promoted', $statistics['promoted_programmes']],
            ['Demo projects removed', $statistics['removed_demo_projects']],
            ['Demo users removed', $statistics['removed_demo_users']],
            ['Synthetic indicators removed', $statistics['removed_indicators']],
            ['Synthetic targets removed', $statistics['removed_targets']],
            ['Synthetic entries removed', $statistics['removed_entries']],
            ['Synthetic approvals removed', $statistics['removed_approvals']],
            ['Demo notifications removed', $statistics['removed_notifications']],
            ['Evidence files selected', count($statistics['evidence_paths'])],
        ]);

        $message = $isDryRun
            ? 'Dry run complete. Database changes were rolled back and evidence files were untouched.'
            : 'Authoritative portfolio transition complete.';
        $this->components->info($message);

        return self::SUCCESS;
    }
}
