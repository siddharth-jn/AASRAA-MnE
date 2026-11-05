<?php

namespace App\Services;

use App\Models\EntryRevision;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\User;
use App\Notifications\WorkflowNotification;
use App\Support\WorkflowStatus;
use Illuminate\Support\Facades\Auth;

class TargetChangeService
{
    public function handle(IndicatorTarget $target, bool $removed): bool
    {
        $target->loadMissing('assignment.indicator.project', 'assignment.centre');
        $entry = IndicatorEntry::query()
            ->where('indicator_assignment_id', $target->indicator_assignment_id)
            ->whereDate('period', $target->period)
            ->with(['currentRevision.evidence', 'approvedRevision', 'assignment.indicator.project', 'assignment.centre'])
            ->first();

        if (! $entry) {
            return false;
        }

        $previousStatus = $entry->status;
        if ($removed) {
            $entry->update([
                'status' => WorkflowStatus::TargetRemoved,
                'approved_revision_id' => null,
                'target_changed_at' => now(),
                'target_change_from_status' => $previousStatus,
                'target_change_reason' => 'The monthly target was removed and the reporting obligation is no longer applicable.',
            ]);
            $this->notifyAssignedUsers($entry, 'Target removed', 'The target was removed. The previous entry remains in history and is excluded from current reporting.');

            return true;
        }

        $sourceRevision = $entry->currentRevision ?? $entry->approvedRevision;
        if (! $sourceRevision) {
            return false;
        }

        $status = match (true) {
            $previousStatus === WorkflowStatus::Draft => WorkflowStatus::Draft,
            in_array($previousStatus, WorkflowStatus::pending(), true) => WorkflowStatus::ReturnedForCorrection,
            $previousStatus === WorkflowStatus::FinalApproved,
            $previousStatus === WorkflowStatus::TargetRemoved => WorkflowStatus::ReEntryRequired,
            default => WorkflowStatus::ReturnedForCorrection,
        };

        $revision = $entry->revisions()->create([
            'indicator_definition_version_id' => $entry->assignment->indicator->versions()->latest('version')->value('id'),
            'user_id' => $sourceRevision->user_id,
            'version' => ($entry->revisions()->max('version') ?? 0) + 1,
            'status' => $status,
            'value_state' => 'reported',
            'notes' => 'Target changed. Enter the achievement again and resubmit it for approval.',
            'variance_percentage' => null,
            'variance_flagged' => false,
            'variance_explanation' => null,
            'is_demo' => (bool) $entry->is_demo,
        ]);
        $this->copyEvidence($sourceRevision, $revision);

        $entry->update([
            'current_revision_id' => $revision->id,
            'approved_revision_id' => null,
            'status' => $status,
            'target_changed_at' => now(),
            'target_change_from_status' => $previousStatus,
            'target_change_reason' => 'The monthly target changed. Enter the achievement again and restart approval.',
        ]);
        $this->notifyAssignedUsers($entry, 'Target changed — re-entry required', "The target changed while this entry was {$previousStatus}. Enter the achievement again and resubmit it.");

        return true;
    }

    private function copyEvidence(EntryRevision $source, EntryRevision $destination): void
    {
        foreach ($source->evidence as $file) {
            $destination->evidence()->create([
                'user_id' => $source->user_id,
                'disk' => $file->disk,
                'path' => $file->path,
                'original_name' => $file->original_name,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                'is_demo' => (bool) $file->is_demo,
            ]);
        }
    }

    private function notifyAssignedUsers(IndicatorEntry $entry, string $title, string $message): void
    {
        $assignment = $entry->assignment;
        $users = $assignment->centre_id
            ? $assignment->centre->users()
            : $assignment->indicator->project->users();

        $users->where('users.active', true)
            ->when(Auth::id(), fn ($query, int $userId) => $query->whereKeyNot($userId))
            ->get()
            ->each(fn (User $user) => $user->notify(new WorkflowNotification(
                $title,
                $assignment->indicator->name.' · '.$entry->period->format('F Y').'. '.$message,
                route('entries.edit', [
                    'assignment' => $assignment->id,
                    'period' => $entry->period->format('Y-m'),
                    'period_type' => 'monthly',
                ]),
                'target_changed',
                showcase: (bool) $entry->is_demo,
            )));
    }
}
