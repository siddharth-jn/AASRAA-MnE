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
use App\Models\IndicatorImportBatch;
use App\Models\IndicatorImportRow;
use App\Models\IndicatorTarget;
use App\Models\IndicatorTargetRevision;
use App\Models\Programme;
use App\Models\Project;
use App\Models\TargetImportBatch;
use App\Models\TargetImportRow;
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
            $this->deleteAuditLogsForDemoRecords();
            EvidenceFile::query()
                ->where('is_demo', true)
                ->orWhereIn('entry_revision_id', EntryRevision::query()->where('is_demo', true)->select('id'))
                ->each(function (EvidenceFile $file): void {
                    Storage::disk($file->disk ?: config('impact.evidence_disk', 'local'))->delete($file->path);
                });
            DB::table('notifications')->where('data', 'like', '%"showcase":true%')->delete();
            AuditLog::query()->where('is_demo', true)->delete();
            ImportRow::query()->where('is_demo', true)->delete();
            ImportBatch::query()->where('is_demo', true)->delete();
            IndicatorImportRow::query()->where('is_demo', true)->delete();
            IndicatorImportBatch::query()->where('is_demo', true)->delete();
            TargetImportRow::query()->where('is_demo', true)->delete();
            TargetImportBatch::query()->where('is_demo', true)->delete();
            ApprovalAction::query()
                ->where('is_demo', true)
                ->orWhereIn('entry_revision_id', EntryRevision::query()->where('is_demo', true)->select('id'))
                ->delete();
            EvidenceFile::query()
                ->where('is_demo', true)
                ->orWhereIn('entry_revision_id', EntryRevision::query()->where('is_demo', true)->select('id'))
                ->delete();
            IndicatorEntry::query()->where('is_demo', true)->update([
                'current_revision_id' => null,
                'approved_revision_id' => null,
            ]);
            EntryRevision::query()->where('is_demo', true)->delete();
            IndicatorEntry::query()->where('is_demo', true)->delete();
            IndicatorTargetRevision::query()
                ->where('is_demo', true)
                ->orWhereIn('indicator_target_id', IndicatorTarget::query()
                    ->where('is_demo', true)
                    ->orWhereIn('indicator_assignment_id', IndicatorAssignment::query()->where('is_demo', true)->select('id'))
                    ->select('id'))
                ->delete();
            IndicatorTarget::query()
                ->where('is_demo', true)
                ->orWhereIn('indicator_assignment_id', IndicatorAssignment::query()->where('is_demo', true)->select('id'))
                ->delete();
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

    private function deleteAuditLogsForDemoRecords(): void
    {
        $subjects = [
            User::class => User::query()->where('is_demo', true)->select('id'),
            Programme::class => Programme::query()->where('is_demo', true)->select('id'),
            Project::class => Project::query()->where('is_demo', true)->select('id'),
            Centre::class => Centre::query()->where('is_demo', true)->select('id'),
            IndicatorDefinition::class => IndicatorDefinition::query()->where('is_demo', true)->select('id'),
            IndicatorDefinitionVersion::class => IndicatorDefinitionVersion::query()->where('is_demo', true)->select('id'),
            IndicatorAssignment::class => IndicatorAssignment::query()->where('is_demo', true)->select('id'),
            IndicatorTarget::class => IndicatorTarget::query()
                ->where('is_demo', true)
                ->orWhereIn('indicator_assignment_id', IndicatorAssignment::query()->where('is_demo', true)->select('id'))
                ->select('id'),
            IndicatorTargetRevision::class => IndicatorTargetRevision::query()
                ->where('is_demo', true)
                ->orWhereIn('indicator_target_id', IndicatorTarget::query()
                    ->where('is_demo', true)
                    ->orWhereIn('indicator_assignment_id', IndicatorAssignment::query()->where('is_demo', true)->select('id'))
                    ->select('id'))
                ->select('id'),
            IndicatorEntry::class => IndicatorEntry::query()->where('is_demo', true)->select('id'),
            EntryRevision::class => EntryRevision::query()->where('is_demo', true)->select('id'),
            EvidenceFile::class => EvidenceFile::query()
                ->where('is_demo', true)
                ->orWhereIn('entry_revision_id', EntryRevision::query()->where('is_demo', true)->select('id'))
                ->select('id'),
            ApprovalAction::class => ApprovalAction::query()
                ->where('is_demo', true)
                ->orWhereIn('entry_revision_id', EntryRevision::query()->where('is_demo', true)->select('id'))
                ->select('id'),
            ImportBatch::class => ImportBatch::query()->where('is_demo', true)->select('id'),
            ImportRow::class => ImportRow::query()->where('is_demo', true)->select('id'),
            IndicatorImportBatch::class => IndicatorImportBatch::query()->where('is_demo', true)->select('id'),
            IndicatorImportRow::class => IndicatorImportRow::query()->where('is_demo', true)->select('id'),
            TargetImportBatch::class => TargetImportBatch::query()->where('is_demo', true)->select('id'),
            TargetImportRow::class => TargetImportRow::query()->where('is_demo', true)->select('id'),
        ];

        foreach ($subjects as $subjectType => $subjectIds) {
            AuditLog::query()
                ->where('subject_type', $subjectType)
                ->whereIn('subject_id', $subjectIds)
                ->delete();
        }
    }
}
