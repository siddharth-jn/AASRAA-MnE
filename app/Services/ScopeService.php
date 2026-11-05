<?php

namespace App\Services;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ScopeService
{
    public function projects(User $user): Builder
    {
        $query = Project::query();
        if (! in_array($user->role, ['ADMIN', 'M&E HEAD', 'CEO', 'CFO'], true)) {
            $query->whereHas('users', fn (Builder $q) => $q->where('users.id', $user->id));
        }

        return $query;
    }

    public function centres(User $user): Builder
    {
        $query = Centre::query();
        if (! in_array($user->role, ['ADMIN', 'M&E HEAD', 'CEO', 'CFO'], true)) {
            $query->whereHas('users', fn (Builder $q) => $q->where('users.id', $user->id));
        }

        return $query;
    }

    public function assignments(User $user): Builder
    {
        return IndicatorAssignment::query()
            ->whereHas('indicator.project', fn (Builder $q) => $q->whereIn('projects.id', $this->projects($user)->select('projects.id')))
            ->where(function (Builder $query) use ($user): void {
                $query->whereIn('centre_id', $this->centres($user)->select('centres.id'))
                    ->orWhere(function (Builder $programmeQuery): void {
                        $programmeQuery->whereNull('centre_id')
                            ->whereHas('indicator', fn (Builder $indicatorQuery): Builder => $indicatorQuery->where('reporting_scope', 'programme'));
                    });
            })
            ->when($user->is_demo, fn (Builder $query): Builder => $query->where('indicator_assignments.is_demo', true));
    }

    public function entries(User $user): Builder
    {
        $q = IndicatorEntry::query()->whereIn('indicator_assignment_id', $this->assignments($user)->select('indicator_assignments.id'));
        if (in_array($user->role, ['VIEWER', 'CEO', 'CFO'], true)) {
            $q->whereNotNull('approved_revision_id');
        }

        return $q;
    }

    public function canAccess(User $user, IndicatorEntry $entry): bool
    {
        return $user->active && $this->entries($user)->whereKey($entry->id)->exists();
    }
}
