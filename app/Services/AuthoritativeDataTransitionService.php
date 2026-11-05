<?php

namespace App\Services;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorDefinitionVersion;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use LogicException;

class AuthoritativeDataTransitionService
{
    /**
     * @return array{
     *     promoted_programmes: int,
     *     removed_demo_projects: int,
     *     removed_demo_users: int,
     *     removed_indicators: int,
     *     removed_targets: int,
     *     removed_entries: int,
     *     removed_approvals: int,
     *     removed_notifications: int,
     *     evidence_paths: list<string>
     * }
     */
    public function transition(): array
    {
        return DB::transaction(function (): array {
            $operationalProgrammeIds = DB::table('projects')
                ->join('project_centres', 'project_centres.project_id', '=', 'projects.id')
                ->join('centres', 'centres.id', '=', 'project_centres.centre_id')
                ->whereNotNull('centres.source_project_id')
                ->where('centres.is_demo', false)
                ->distinct()
                ->pluck('projects.id');

            $programmeContainerIds = DB::table('projects')
                ->whereIn('id', $operationalProgrammeIds)
                ->pluck('programme_id');
            $programmeIdsToPromote = DB::table('projects')
                ->whereIn('id', $operationalProgrammeIds)
                ->where('is_demo', true)
                ->pluck('id');

            DB::table('projects')->whereIn('id', $operationalProgrammeIds)->update([
                'is_demo' => false,
                'updated_at' => now(),
            ]);
            DB::table('programmes')->whereIn('id', $programmeContainerIds)->update([
                'is_demo' => false,
                'updated_at' => now(),
            ]);

            $removedCentreIds = DB::table('centres')->where('is_demo', true)->pluck('id');
            $removedProjectIds = DB::table('projects')->where('is_demo', true)->pluck('id');
            $removedUserIds = DB::table('users')->where('is_demo', true)->pluck('id');

            $definitionIds = DB::table('indicator_definitions')
                ->where(function ($query) use ($removedProjectIds): void {
                    $query->where('is_demo', true)
                        ->when($removedProjectIds->isNotEmpty(), fn ($query) => $query->orWhereIn('project_id', $removedProjectIds));
                })
                ->pluck('id');

            $assignmentIds = $definitionIds->isEmpty() && $removedCentreIds->isEmpty()
                ? collect()
                : DB::table('indicator_assignments')
                    ->where(function ($query) use ($definitionIds, $removedCentreIds): void {
                        $query
                            ->when($definitionIds->isNotEmpty(), fn ($query) => $query->whereIn('indicator_definition_id', $definitionIds))
                            ->when($removedCentreIds->isNotEmpty(), function ($query) use ($definitionIds, $removedCentreIds): void {
                                $method = $definitionIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                                $query->{$method}('centre_id', $removedCentreIds);
                            });
                    })
                    ->pluck('id');

            $entryIds = DB::table('indicator_entries')->whereIn('indicator_assignment_id', $assignmentIds)->pluck('id');
            $revisionIds = DB::table('entry_revisions')->whereIn('indicator_entry_id', $entryIds)->pluck('id');
            $targetIds = DB::table('indicator_targets')->whereIn('indicator_assignment_id', $assignmentIds)->pluck('id');
            $definitionVersionIds = DB::table('indicator_definition_versions')->whereIn('indicator_definition_id', $definitionIds)->pluck('id');
            $evidencePaths = DB::table('evidence_files')->whereIn('entry_revision_id', $revisionIds)->pluck('path')->all();

            $removedApprovals = DB::table('approval_actions')->whereIn('entry_revision_id', $revisionIds)->delete();
            DB::table('evidence_files')->whereIn('entry_revision_id', $revisionIds)->delete();
            DB::table('indicator_entries')->whereIn('id', $entryIds)->update([
                'current_revision_id' => null,
                'approved_revision_id' => null,
            ]);
            DB::table('entry_revisions')->whereIn('id', $revisionIds)->delete();
            $removedEntries = DB::table('indicator_entries')->whereIn('id', $entryIds)->delete();
            $removedTargets = DB::table('indicator_targets')->whereIn('id', $targetIds)->delete();
            DB::table('indicator_assignments')->whereIn('id', $assignmentIds)->delete();
            DB::table('indicator_definition_versions')->whereIn('id', $definitionVersionIds)->delete();
            $removedIndicators = DB::table('indicator_definitions')->whereIn('id', $definitionIds)->delete();

            $importBatchIds = DB::table('import_batches')->whereIn('project_id', $removedProjectIds)->pluck('id');
            DB::table('import_rows')->whereIn('import_batch_id', $importBatchIds)->delete();
            DB::table('import_batches')->whereIn('id', $importBatchIds)->delete();

            $indicatorImportBatchIds = DB::table('indicator_import_batches')->whereIn('project_id', $removedProjectIds)->pluck('id');
            DB::table('indicator_import_rows')->whereIn('indicator_import_batch_id', $indicatorImportBatchIds)->delete();
            DB::table('indicator_import_batches')->whereIn('id', $indicatorImportBatchIds)->delete();

            DB::table('project_centres')
                ->whereIn('centre_id', $removedCentreIds)
                ->when($removedProjectIds->isNotEmpty(), fn ($query) => $query->orWhereIn('project_id', $removedProjectIds))
                ->delete();
            DB::table('centre_user_assignments')
                ->whereIn('centre_id', $removedCentreIds)
                ->when($removedUserIds->isNotEmpty(), fn ($query) => $query->orWhereIn('user_id', $removedUserIds))
                ->delete();
            DB::table('project_user_assignments')
                ->whereIn('project_id', $removedProjectIds)
                ->when($removedUserIds->isNotEmpty(), fn ($query) => $query->orWhereIn('user_id', $removedUserIds))
                ->delete();

            $removedNotifications = DB::table('notifications')
                ->where('notifiable_type', 'App\\Models\\User')
                ->whereIn('notifiable_id', $removedUserIds)
                ->delete();

            $this->deleteAuditLogs([
                Centre::class => $removedCentreIds,
                Project::class => $removedProjectIds,
                IndicatorDefinition::class => $definitionIds,
                IndicatorDefinitionVersion::class => $definitionVersionIds,
                IndicatorAssignment::class => $assignmentIds,
                IndicatorTarget::class => $targetIds,
                IndicatorEntry::class => $entryIds,
            ], $removedUserIds);

            $this->ensureDemoUsersHaveNoOperationalHistory($removedUserIds);

            $removedDemoUsers = DB::table('users')->whereIn('id', $removedUserIds)->delete();
            $removedDemoProjects = DB::table('centres')->whereIn('id', $removedCentreIds)->delete();
            DB::table('projects')->whereIn('id', $removedProjectIds)->delete();
            DB::table('programmes')
                ->where('is_demo', true)
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('projects')->whereColumn('projects.programme_id', 'programmes.id'))
                ->delete();

            return [
                'promoted_programmes' => $programmeIdsToPromote->count(),
                'removed_demo_projects' => $removedDemoProjects,
                'removed_demo_users' => $removedDemoUsers,
                'removed_indicators' => $removedIndicators,
                'removed_targets' => $removedTargets,
                'removed_entries' => $removedEntries,
                'removed_approvals' => $removedApprovals,
                'removed_notifications' => $removedNotifications,
                'evidence_paths' => $evidencePaths,
            ];
        });
    }

    /**
     * @param  array<class-string, iterable<int>>  $subjects
     * @param  iterable<int>  $removedUserIds
     */
    private function deleteAuditLogs(array $subjects, iterable $removedUserIds): void
    {
        DB::table('audit_logs')->whereIn('user_id', $removedUserIds)->delete();

        foreach ($subjects as $subjectType => $subjectIds) {
            DB::table('audit_logs')
                ->where('subject_type', $subjectType)
                ->whereIn('subject_id', $subjectIds)
                ->delete();
        }
    }

    /** @param  iterable<int>  $removedUserIds */
    private function ensureDemoUsersHaveNoOperationalHistory(iterable $removedUserIds): void
    {
        $references = [
            'entry revisions' => DB::table('entry_revisions')->whereIn('user_id', $removedUserIds)->exists(),
            'evidence files' => DB::table('evidence_files')->whereIn('user_id', $removedUserIds)->exists(),
            'approval actions' => DB::table('approval_actions')->whereIn('user_id', $removedUserIds)->exists(),
        ];

        $remainingReferences = array_keys(array_filter($references));
        if ($remainingReferences !== []) {
            throw new LogicException('Demo users still own operational '.implode(', ', $remainingReferences).'; the transition was rolled back.');
        }
    }
}
