<?php

namespace App\Services;

use App\Models\ApprovalAction;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorEntry;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EntryService
{
    /** @param array<string, mixed> $data @param array<int, UploadedFile> $files */
    public function save(User $user, IndicatorAssignment $assignment, string $period, array $data, array $files, bool $submit): IndicatorEntry
    {
        return DB::transaction(function () use ($user, $assignment, $period, $data, $files, $submit): IndicatorEntry {
            $entry = IndicatorEntry::query()->lockForUpdate()->firstOrCreate(['indicator_assignment_id' => $assignment->id, 'period' => $period], ['status' => 'DRAFT', 'is_demo' => false]);
            if ($entry->status === 'SUBMITTED') {
                throw ValidationException::withMessages(['entry' => 'This record is awaiting review.']);
            }
            $definition = $assignment->indicator;
            if ($submit && $definition->evidence_required && $files === [] && ! $entry->currentRevision?->evidence()->exists()) {
                throw ValidationException::withMessages(['evidence' => 'Supporting evidence is required before submission.']);
            }
            $revision = $entry->revisions()->create([
                'indicator_definition_version_id' => $definition->versions()->latest('version')->value('id'), 'user_id' => $user->id,
                'version' => ($entry->revisions()->max('version') ?? 0) + 1, 'status' => $submit ? 'SUBMITTED' : 'DRAFT',
                'value_state' => $data['value_state'] ?? 'reported', 'numeric_value' => $data['numeric_value'] ?? null,
                'numerator' => $data['numerator'] ?? null, 'denominator' => $data['denominator'] ?? null,
                'text_value' => $data['text_value'] ?? null, 'date_value' => $data['date_value'] ?? null,
                'boolean_value' => $data['boolean_value'] ?? null, 'notes' => $data['notes'] ?? null,
                'submitted_at' => $submit ? now() : null, 'is_demo' => false,
            ]);
            foreach ($files as $file) {
                $revision->evidence()->create(['user_id' => $user->id, 'path' => $file->store("evidence/{$entry->id}", 'local'), 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(), 'is_demo' => false]);
            }
            $entry->update(['current_revision_id' => $revision->id, 'status' => $revision->status]);

            return $entry->fresh(['assignment.indicator', 'currentRevision.evidence']);
        });
    }

    public function review(User $reviewer, IndicatorEntry $entry, string $action, ?string $comment): IndicatorEntry
    {
        return DB::transaction(function () use ($reviewer, $entry, $action, $comment): IndicatorEntry {
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
    }
}
