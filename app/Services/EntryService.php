<?php

namespace App\Services;

use App\Models\ApprovalAction;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorEntry;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntryService
{
    /** @param array<string, mixed> $data @param array<int, UploadedFile> $files */
    public function save(User $user, IndicatorAssignment $assignment, string $period, array $data, array $files, bool $submit): IndicatorEntry
    {
        $definition = $assignment->loadMissing('indicator')->indicator;
        $normalizedPeriod = CarbonImmutable::parse($period)->startOfMonth();
        if (! $definition->isEffectiveForPeriod($normalizedPeriod)) {
            throw ValidationException::withMessages([
                'period' => 'Reporting for this indicator starts in '.$definition->effective_date->format('F Y').'.',
            ]);
        }

        $entry = DB::transaction(function () use ($user, $assignment, $normalizedPeriod, $definition, $data, $files, $submit): IndicatorEntry {
            $entry = IndicatorEntry::query()
                ->where('indicator_assignment_id', $assignment->id)
                ->whereDate('period', $normalizedPeriod->toDateString())
                ->lockForUpdate()
                ->first();
            if (! $entry) {
                $entry = IndicatorEntry::create([
                    'indicator_assignment_id' => $assignment->id,
                    'period' => $normalizedPeriod->toDateString(),
                    'status' => 'DRAFT',
                    'is_demo' => false,
                ]);
            }
            if ($entry->status === 'SUBMITTED') {
                throw ValidationException::withMessages(['entry' => 'This record is awaiting review.']);
            }
            $existingEvidenceCount = $entry->currentRevision?->evidence()->count() ?? 0;
            $minimumEvidenceCount = max(1, $definition->min_files);
            if ($submit && $definition->evidence_required && ($existingEvidenceCount + count($files)) < $minimumEvidenceCount) {
                throw ValidationException::withMessages(['evidence' => "At least {$minimumEvidenceCount} supporting file(s) are required before submission."]);
            }
            $status = $submit ? ($definition->approval_required ? 'SUBMITTED' : 'APPROVED') : 'DRAFT';
            $revision = $entry->revisions()->create([
                'indicator_definition_version_id' => $definition->versions()->latest('version')->value('id'), 'user_id' => $user->id,
                'version' => ($entry->revisions()->max('version') ?? 0) + 1, 'status' => $status,
                'value_state' => $data['value_state'] ?? 'reported', 'numeric_value' => $data['numeric_value'] ?? null,
                'numerator' => $data['numerator'] ?? null, 'denominator' => $data['denominator'] ?? null,
                'text_value' => $data['text_value'] ?? null, 'date_value' => $data['date_value'] ?? null,
                'boolean_value' => $data['boolean_value'] ?? null, 'json_value' => $data['json_value'] ?? null,
                'notes' => $data['notes'] ?? null, 'submitted_at' => $submit ? now() : null,
                'approved_at' => $status === 'APPROVED' ? now() : null, 'is_demo' => false,
            ]);
            foreach ($files as $file) {
                $revision->evidence()->create(['user_id' => $user->id, 'path' => $file->store("evidence/{$entry->id}", 'local'), 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(), 'is_demo' => false]);
            }
            $entry->update([
                'current_revision_id' => $revision->id,
                'approved_revision_id' => $status === 'APPROVED' ? $revision->id : $entry->approved_revision_id,
                'status' => $revision->status,
            ]);

            return $entry->fresh(['assignment.indicator', 'currentRevision.evidence']);
        });

        if ($submit && $entry->status === 'SUBMITTED') {
            $this->reviewersFor($entry)->each(fn (User $reviewer) => $reviewer->notify(new WorkflowNotification(
                'Submission requires approval',
                "{$entry->assignment->indicator->name} was submitted for {$entry->assignment->centre->name}.",
                route('approvals.index'),
                'approval_required',
            )));
        }

        return $entry;
    }

    public function review(User $reviewer, IndicatorEntry $entry, string $action, ?string $comment): IndicatorEntry
    {
        $entry = DB::transaction(function () use ($reviewer, $entry, $action, $comment): IndicatorEntry {
            $entry = IndicatorEntry::query()->lockForUpdate()->findOrFail($entry->id);
            $revision = $entry->currentRevision;
            if ($entry->status !== 'SUBMITTED' || ! $revision) {
                throw ValidationException::withMessages(['entry' => 'This submission is no longer pending.']);
            }
            if ($revision->user_id === $reviewer->id) {
                throw ValidationException::withMessages(['entry' => 'You cannot approve your own submission.']);
            }
            if (in_array($action, ['RETURNED', 'REJECTED'], true) && blank($comment)) {
                throw ValidationException::withMessages(['comment' => 'A review comment is required.']);
            }
            $status = match ($action) {
                'APPROVED' => 'APPROVED', 'RETURNED' => 'RETURNED FOR CORRECTION', 'REJECTED' => 'REJECTED', default => throw ValidationException::withMessages(['action' => 'Invalid review action.'])
            };
            $revision->update(['status' => $status, 'approved_at' => $status === 'APPROVED' ? now() : null]);
            ApprovalAction::create(['entry_revision_id' => $revision->id, 'user_id' => $reviewer->id, 'action' => $status, 'comment' => $comment, 'is_demo' => false]);
            $entry->update(['status' => $status, 'approved_revision_id' => $status === 'APPROVED' ? $revision->id : $entry->approved_revision_id]);

            return $entry->fresh();
        });

        $entry->loadMissing(['assignment.indicator', 'assignment.centre', 'currentRevision.user']);
        $submitter = $entry->currentRevision->user;
        $submitter->notify(new WorkflowNotification(
            "Submission {$entry->status}",
            trim("{$entry->assignment->indicator->name} for {$entry->assignment->centre->name} is now {$entry->status}. ".($comment ?: '')),
            route('entries.edit', ['assignment' => $entry->indicator_assignment_id, 'period' => $entry->period->format('Y-m')]),
            strtolower(str_replace(' ', '_', $entry->status)),
        ));

        return $entry;
    }

    /** @return Collection<int, User> */
    private function reviewersFor(IndicatorEntry $entry): Collection
    {
        $entry->loadMissing('assignment.indicator');
        $projectId = $entry->assignment->indicator->project_id;

        return User::query()
            ->where('active', true)
            ->whereIn('role', ['ADMIN', 'SENIOR PROGRAM LEAD'])
            ->whereKeyNot($entry->currentRevision->user_id)
            ->where(function ($query) use ($projectId): void {
                $query->where('role', 'ADMIN')
                    ->orWhereHas('projects', fn ($projectQuery) => $projectQuery->whereKey($projectId));
            })
            ->get();
    }
}
