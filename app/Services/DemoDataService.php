<?php

namespace App\Services;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DemoDataService
{
    /** @return array<string, int> */
    public function clear(): array
    {
        $statistics = [
            'indicators' => IndicatorDefinition::query()->where('is_demo', true)->count(),
            'entries' => IndicatorEntry::query()->where('is_demo', true)->count(),
            'evidence' => EvidenceFile::query()->where('is_demo', true)->count(),
            'users' => User::query()->where('is_demo', true)->count(),
        ];

        DB::transaction(function (): void {
            EvidenceFile::query()->where('is_demo', true)->each(function (EvidenceFile $file): void {
                Storage::disk($file->disk ?: config('impact.evidence_disk', 'local'))->delete($file->path);
            });
            DB::table('notifications')->where('data', 'like', '%"showcase":true%')->delete();
            AuditLog::query()->where('is_demo', true)->delete();
            ImportRow::query()->where('is_demo', true)->delete();
            ImportBatch::query()->where('is_demo', true)->delete();
            ApprovalAction::query()->where('is_demo', true)->delete();
            EvidenceFile::query()->where('is_demo', true)->delete();
            IndicatorEntry::query()->where('is_demo', true)->update([
                'current_revision_id' => null,
                'approved_revision_id' => null,
            ]);
            EntryRevision::query()->where('is_demo', true)->delete();
            IndicatorEntry::query()->where('is_demo', true)->delete();
            IndicatorTarget::query()->where('is_demo', true)->delete();
            IndicatorAssignment::query()->where('is_demo', true)->delete();
            IndicatorDefinitionVersion::query()->where('is_demo', true)->delete();
            IndicatorDefinition::query()->where('is_demo', true)->delete();
            DB::table('project_user_assignments')->where('is_demo', true)->delete();
            DB::table('centre_user_assignments')->where('is_demo', true)->delete();
            DB::table('project_centres')->where('is_demo', true)->delete();
            Project::query()->where('is_demo', true)->forceDelete();
            Centre::query()->where('is_demo', true)->forceDelete();
            Programme::query()->where('is_demo', true)->delete();
            User::query()->where('is_demo', true)->delete();
        });

        Storage::disk(config('impact.evidence_disk', 'local'))->deleteDirectory('evidence/showcase');

        return $statistics;
    }
}
