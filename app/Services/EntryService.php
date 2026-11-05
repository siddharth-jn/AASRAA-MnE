<?php

namespace App\Services;

use App\Models\ApprovalAction;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorEntry;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Support\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntryService
{
    public function __construct(
        private readonly TargetService $targets,
        private readonly VarianceService $variances,
        private readonly IndicatorReportingPeriodService $periods,
    ) {}

    /** @param array<string, mixed> $data @param array<int, UploadedFile> $files */
    public function save(User $user, IndicatorAssignment $assignment, string $period, array $data, array $files, bool $submit): IndicatorEntry
    {
        $definition = $assignment->loadMissing('indicator')->indicator;
        $isDemo = (bool) $assignment->is_demo;
        $requestedPeriod = CarbonImmutable::parse($period)->startOfMonth();
        [$normalizedPeriod, $periodEnd] = $this->periods->boundaries($requestedPeriod, 'monthly');
        if (! $this->periods->isAvailable($definition, $requestedPeriod)) {
            throw ValidationException::withMessages([
                'period' => 'Reporting for this indicator starts in '.$definition->effective_date->format('F Y').'.',
            ]);
        }

        $target = $this->targets->findForPeriod($assignment->id, $normalizedPeriod);
        if (! $target) {
            throw ValidationException::withMessages([
                'period' => 'No target is set for this month, so no achievement can be entered.',
            ]);
        }
        $variance = $this->variances->evaluate($definition, $target, $data);
        if ($submit && $variance['flagged'] && blank($data['variance_explanation'] ?? null)) {
            throw ValidationException::withMessages([
                'variance_explanation' => 'Explain this variance before submitting because it is more than 10% from target or outside the expected target.',
            ]);
        }

        try {
            $entry = DB::transaction(function () use ($user, $assignment, $normalizedPeriod, $periodEnd, $definition, $data, $files, $submit, $variance, $isDemo): IndicatorEntry {
                $matchingEntries = IndicatorEntry::query()
                    ->where('indicator_assignment_id', $assignment->id)
                    ->whereBetween('period', [$normalizedPeriod, $periodEnd])
                    ->lockForUpdate()
                    ->get();
                if ($matchingEntries->count() > 1) {
                    throw ValidationException::withMessages([
                        'entry' => 'More than one legacy record exists in this reporting period. Ask an administrator to consolidate those records before continuing.',
                    ]);
                }
                $entry = $matchingEntries->first();
                if (! $entry) {
                    $entry = IndicatorEntry::create([
                        'indicator_assignment_id' => $assignment->id,
                        'period' => $normalizedPeriod->toDateString(),
                        'status' => 'DRAFT',
                        'is_demo' => $isDemo,
                    ]);
                } elseif (! $entry->period->isSameDay($normalizedPeriod)) {
                    $entry->update(['period' => $normalizedPeriod->toDateString()]);
                }
                if (in_array($entry->status, WorkflowStatus::pending(), true)) {
                    throw ValidationException::withMessages(['entry' => 'This record is awaiting review and cannot be edited.']);
                }
                if ($entry->status === WorkflowStatus::FinalApproved) {
                    throw ValidationException::withMessages(['entry' => 'This record is final approved. Request an amendment before changing it.']);
                }
                if ($entry->currentRevision && $entry->currentRevision->user_id !== $user->id && $user->role !== 'ADMIN') {
                    throw ValidationException::withMessages(['entry' => 'Only the person who created this entry can correct its draft or returned revision.']);
                }
                $existingEvidenceCount = $entry->currentRevision?->evidence()->count() ?? 0;
                $minimumEvidenceCount = max(1, $definition->min_files);
                if ($submit && $definition->evidence_required && ($existingEvidenceCount + count($files)) < $minimumEvidenceCount) {
                    throw ValidationException::withMessages(['evidence' => "At least {$minimumEvidenceCount} supporting file(s) are required before submission."]);
                }
                $supersedesRevisionId = $entry->currentRevision?->supersedes_revision_id;
                $isAmendment = $supersedesRevisionId !== null;
                $status = $submit
                    ? ($definition->approval_required || $isAmendment ? WorkflowStatus::PendingSeniorProgrammeLead : WorkflowStatus::FinalApproved)
                    : WorkflowStatus::Draft;
                $revision = $entry->revisions()->create([
                    'supersedes_revision_id' => $supersedesRevisionId,
                    'indicator_definition_version_id' => $definition->versions()->latest('version')->value('id'), 'user_id' => $user->id,
                    'version' => ($entry->revisions()->max('version') ?? 0) + 1, 'status' => $status,
                    'value_state' => $data['value_state'] ?? 'reported', 'numeric_value' => $data['numeric_value'] ?? null,
                    'numerator' => $data['numerator'] ?? null, 'denominator' => $data['denominator'] ?? null,
                    'text_value' => $data['text_value'] ?? null, 'date_value' => $data['date_value'] ?? null,
                    'boolean_value' => $data['boolean_value'] ?? null, 'json_value' => $data['json_value'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'amendment_reason' => $entry->currentRevision?->amendment_reason,
                    'variance_percentage' => $variance['percentage'],
                    'variance_flagged' => $variance['flagged'],
                    'variance_explanation' => $data['variance_explanation'] ?? null,
                    'submitted_at' => $submit ? now() : null,
                    'approved_at' => $status === WorkflowStatus::FinalApproved ? now() : null,
                    'is_demo' => (bool) $entry->is_demo,
                ]);
                $evidenceDisk = (string) config('impact.evidence_disk', 'local');
                foreach ($files as $file) {
                    $revision->evidence()->create([
                        'user_id' => $user->id,
                        'disk' => $evidenceDisk,
                        'path' => $file->store("evidence/{$entry->id}", $evidenceDisk),
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                        'size' => $file->getSize(),
                        'is_demo' => (bool) $entry->is_demo,
                    ]);
                }
                $entry->update([
                    'current_revision_id' => $revision->id,
                    'approved_revision_id' => $status === WorkflowStatus::FinalApproved ? $revision->id : $entry->approved_revision_id,
                    'status' => $revision->status,
                    'target_changed_at' => null,
                    'target_change_from_status' => null,
                    'target_change_reason' => null,
                ]);

                return $entry->fresh(['assignment.indicator', 'currentRevision.evidence']);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'entry' => 'Another user created this reporting record at the same time. Reload the page to see the existing entry.',
            ]);
        }

        if ($submit && $entry->status === WorkflowStatus::PendingSeniorProgrammeLead) {
            $scopeName = $entry->assignment->centre?->name ?? $entry->assignment->indicator->project->name.' (programme level)';
            $this->reviewersFor($entry, WorkflowStatus::PendingSeniorProgrammeLead)->each(fn (User $reviewer) => $reviewer->notify(new WorkflowNotification(
                'Submission requires approval',
                "{$entry->assignment->indicator->name} was submitted for {$scopeName}.",
                route('approvals.index'),
                'approval_required',
                showcase: (bool) $entry->is_demo,
            )));
        }

        return $entry;
    }

    public function review(User $reviewer, IndicatorEntry $entry, string $action, ?string $comment): IndicatorEntry
    {
        $entry = DB::transaction(function () use ($reviewer, $entry, $action, $comment): IndicatorEntry {
            $entry = IndicatorEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $revision = $entry->currentRevision;
            if (! in_array($entry->status, WorkflowStatus::pending(), true) || ! $revision) {
                throw ValidationException::withMessages(['entry' => 'This submission is no longer pending.']);
            }
            if (! $this->targets->findForPeriod($entry->indicator_assignment_id, $entry->period)) {
                throw ValidationException::withMessages(['entry' => 'This submission cannot be approved because no active target is set for its month.']);
            }
            $stage = $entry->status === WorkflowStatus::PendingSeniorProgrammeLead ? 'SENIOR PROGRAM LEAD' : 'M&E HEAD';
            $allowedRoles = $stage === 'SENIOR PROGRAM LEAD' ? ['ADMIN', 'SENIOR PROGRAM LEAD'] : ['ADMIN', 'M&E HEAD'];
            if (! in_array($reviewer->role, $allowedRoles, true)) {
                throw ValidationException::withMessages(['entry' => 'This submission is pending review at a different stage.']);
            }
            if ($revision->user_id === $reviewer->id && $reviewer->role !== 'ADMIN') {
                throw ValidationException::withMessages(['entry' => 'You cannot approve your own submission.']);
            }
            if (in_array($action, ['RETURNED', 'REJECTED'], true) && blank($comment)) {
                throw ValidationException::withMessages(['comment' => 'A review comment is required.']);
            }
            $status = match ($action) {
                'APPROVED' => $stage === 'SENIOR PROGRAM LEAD' ? WorkflowStatus::PendingMneHead : WorkflowStatus::FinalApproved,
                'RETURNED' => WorkflowStatus::ReturnedForCorrection,
                'REJECTED' => WorkflowStatus::Rejected,
                default => throw ValidationException::withMessages(['action' => 'Invalid review action.']),
            };
            $revision->update(['status' => $status, 'approved_at' => $status === WorkflowStatus::FinalApproved ? now() : null]);
            ApprovalAction::create(['entry_revision_id' => $revision->id, 'user_id' => $reviewer->id, 'stage' => $stage, 'action' => $status, 'comment' => $comment, 'is_demo' => (bool) $revision->is_demo]);
            $entry->update(['status' => $status, 'approved_revision_id' => $status === WorkflowStatus::FinalApproved ? $revision->id : $entry->approved_revision_id]);

            return $entry->fresh();
        });

        $entry->loadMissing(['assignment.indicator', 'assignment.centre', 'currentRevision.user']);
        $scopeName = $entry->assignment->centre?->name ?? $entry->assignment->indicator->project->name.' (programme level)';
        $submitter = $entry->currentRevision->user;
        $submitter->notify(new WorkflowNotification(
            "Submission {$entry->status}",
            trim("{$entry->assignment->indicator->name} for {$scopeName} is now {$entry->status}. ".($comment ?: '')),
            route('entries.edit', [
                'assignment' => $entry->indicator_assignment_id,
                'period' => $entry->period->format('Y-m'),
                'period_type' => $entry->assignment->indicator->frequency,
            ]),
            strtolower(str_replace([' ', '&'], ['_', 'and'], $entry->status)),
            showcase: (bool) $entry->is_demo,
        ));

        if ($entry->status === WorkflowStatus::PendingMneHead) {
            $this->reviewersFor($entry, WorkflowStatus::PendingMneHead)->each(fn (User $nextReviewer) => $nextReviewer->notify(new WorkflowNotification(
                'Submission requires final M&E approval',
                "{$entry->assignment->indicator->name} passed Senior Programme Lead review for {$scopeName}.",
                route('approvals.index'),
                'mne_approval_required',
                showcase: (bool) $entry->is_demo,
            )));
        }

        if ($entry->status === WorkflowStatus::FinalApproved) {
            User::query()->where('active', true)->whereIn('role', ['CEO', 'CFO'])->each(fn (User $executive) => $executive->notify(new WorkflowNotification(
                'Result final approved',
                "{$entry->assignment->indicator->name} was final approved for {$scopeName}.",
                route('executive.dashboard'),
                'final_approved',
                showcase: (bool) $entry->is_demo,
            )));
        }

        return $entry;
    }

    public function requestAmendment(User $user, IndicatorEntry $entry, string $reason): IndicatorEntry
    {
        return DB::transaction(function () use ($user, $entry, $reason): IndicatorEntry {
            $entry = IndicatorEntry::query()->lockForUpdate()->findOrFail($entry->id);
            if ($entry->status !== WorkflowStatus::FinalApproved || ! $entry->approvedRevision) {
                throw ValidationException::withMessages(['entry' => 'Only a final-approved entry can begin an amendment.']);
            }

            $approved = $entry->approvedRevision;
            $revision = $entry->revisions()->create([
                'supersedes_revision_id' => $approved->id,
                'indicator_definition_version_id' => $approved->indicator_definition_version_id,
                'user_id' => $user->id,
                'version' => ($entry->revisions()->max('version') ?? 0) + 1,
                'status' => WorkflowStatus::Draft,
                'value_state' => $approved->value_state,
                'numeric_value' => $approved->numeric_value,
                'numerator' => $approved->numerator,
                'denominator' => $approved->denominator,
                'weight' => $approved->weight,
                'text_value' => $approved->text_value,
                'date_value' => $approved->date_value,
                'boolean_value' => $approved->boolean_value,
                'json_value' => $approved->json_value,
                'notes' => $approved->notes,
                'amendment_reason' => $reason,
                'variance_percentage' => $approved->variance_percentage,
                'variance_flagged' => $approved->variance_flagged,
                'variance_explanation' => $approved->variance_explanation,
                'is_demo' => (bool) $entry->is_demo,
            ]);
            foreach ($approved->evidence as $file) {
                $revision->evidence()->create([
                    'user_id' => $user->id,
                    'disk' => $file->disk,
                    'path' => $file->path,
                    'original_name' => $file->original_name,
                    'mime_type' => $file->mime_type,
                    'size' => $file->size,
                    'is_demo' => (bool) $entry->is_demo,
                ]);
            }
            $entry->update([
                'current_revision_id' => $revision->id,
                'status' => WorkflowStatus::Draft,
            ]);

            return $entry->fresh(['currentRevision.evidence', 'approvedRevision']);
        });
    }

    /** @return Collection<int, User> */
    private function reviewersFor(IndicatorEntry $entry, string $status): Collection
    {
        $entry->loadMissing('assignment.indicator');
        $projectId = $entry->assignment->indicator->project_id;

        $roles = $status === WorkflowStatus::PendingSeniorProgrammeLead
            ? ['ADMIN', 'SENIOR PROGRAM LEAD']
            : ['ADMIN', 'M&E HEAD'];

        return User::query()
            ->where('active', true)
            ->whereIn('role', $roles)
            ->whereKeyNot($entry->currentRevision->user_id)
            ->when($status === WorkflowStatus::PendingSeniorProgrammeLead, fn ($query) => $query->where(function ($scopeQuery) use ($projectId): void {
                $scopeQuery->where('role', 'ADMIN')
                    ->orWhereHas('projects', fn ($projectQuery) => $projectQuery->whereKey($projectId));
            }))
            ->get();
    }
}
