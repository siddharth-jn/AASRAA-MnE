<?php

namespace App\Console\Commands;

use App\Models\ApprovalAction;
use App\Models\AuditLog;
use App\Models\Centre;
use App\Models\EntryRevision;
use App\Models\EvidenceFile;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorDefinitionVersion;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Signature('impact:clear-demo-data {--force : Confirm removal of demo-tagged records}')]
#[Description('Remove only records explicitly tagged as demonstration data')]
class ClearDemoData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Remove all records tagged as demo data?')) {
            return self::SUCCESS;
        }
        DB::transaction(function (): void {
            EvidenceFile::where('is_demo', true)->each(function (EvidenceFile $file): void {
                Storage::disk('local')->delete($file->path);
            });
            AuditLog::where('is_demo', true)->delete();
            ImportRow::where('is_demo', true)->delete();
            ImportBatch::where('is_demo', true)->delete();
            ApprovalAction::where('is_demo', true)->delete();
            EvidenceFile::where('is_demo', true)->delete();
            IndicatorEntry::where('is_demo', true)->update(['current_revision_id' => null, 'approved_revision_id' => null]);
            EntryRevision::where('is_demo', true)->delete();
            IndicatorEntry::where('is_demo', true)->delete();
            IndicatorTarget::where('is_demo', true)->delete();
            IndicatorAssignment::where('is_demo', true)->delete();
            IndicatorDefinitionVersion::where('is_demo', true)->delete();
            IndicatorDefinition::where('is_demo', true)->delete();
            DB::table('project_user_assignments')->where('is_demo', true)->delete();
            DB::table('centre_user_assignments')->where('is_demo', true)->delete();
            DB::table('project_centres')->where('is_demo', true)->delete();
            Project::where('is_demo', true)->forceDelete();
            Centre::where('is_demo', true)->forceDelete();
            Programme::where('is_demo', true)->delete();
            User::where('is_demo', true)->delete();
        });
        $this->info('Demo-tagged data removed. Operational records were not selected.');

        return self::SUCCESS;
    }
}
