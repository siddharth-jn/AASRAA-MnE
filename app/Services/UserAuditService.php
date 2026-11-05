<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAuditService
{
    /** @return array<string, mixed> */
    public function snapshot(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'active' => (bool) $user->active,
            'project_ids' => $user->projects()->orderBy('projects.id')->pluck('projects.id')->all(),
            'centre_ids' => $user->centres()->orderBy('centres.id')->pluck('centres.id')->all(),
        ];
    }

    /** @param array<string, mixed>|null $before */
    public function record(User $actor, User $subject, string $action, ?array $before = null): AuditLog
    {
        return AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'subject_type' => User::class,
            'subject_id' => $subject->id,
            'changes' => array_filter([
                'before' => $before,
                'after' => $this->snapshot($subject),
            ], fn (mixed $value): bool => $value !== null),
            'is_demo' => (bool) $subject->is_demo,
        ]);
    }

    public function restore(AuditLog $auditLog, User $actor): User
    {
        $before = $auditLog->changes['before'] ?? null;
        if ($auditLog->subject_type !== User::class || ! is_array($before)) {
            throw ValidationException::withMessages([
                'audit' => 'This audit record does not contain a restorable user configuration.',
            ]);
        }

        $user = User::query()->findOrFail($auditLog->subject_id);
        if ($user->is($actor) && ((! ($before['active'] ?? false)) || ($before['role'] ?? null) !== 'ADMIN')) {
            throw ValidationException::withMessages([
                'audit' => 'You cannot use recovery to deactivate your own administrator account or remove its administrator role.',
            ]);
        }

        return DB::transaction(function () use ($actor, $auditLog, $before, $user): User {
            $current = $this->snapshot($user);
            $user->update([
                'name' => $before['name'],
                'email' => $before['email'],
                'role' => $before['role'],
                'active' => $before['active'],
            ]);
            $user->projects()->sync($before['project_ids'] ?? []);
            $user->centres()->sync($before['centre_ids'] ?? []);
            $this->record($actor, $user->refresh(), 'user_restored', $current)->update([
                'changes' => [
                    'before' => $current,
                    'after' => $this->snapshot($user),
                    'restored_from_audit_log_id' => $auditLog->id,
                ],
            ]);

            return $user;
        });
    }
}
