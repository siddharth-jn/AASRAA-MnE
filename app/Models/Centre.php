<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Centre extends ImpactModel
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'source_project_id' => 'integer',
            'source_donor_id' => 'integer',
            'source_started_date' => 'date',
            'source_synced_at' => 'datetime',
        ];
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_centres')->withPivot('is_demo', 'provenance');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'centre_user_assignments');
    }

    public function donorAssignments(): HasMany
    {
        return $this->hasMany(CentreDonor::class);
    }

    public function donors(): BelongsToMany
    {
        return $this->belongsToMany(Donor::class, 'centre_donors')
            ->withPivot(['starts_on', 'ends_on', 'is_primary', 'notes', 'is_demo'])
            ->withTimestamps();
    }

    public function ensureLeadAccess(): void
    {
        if ($this->lead_id === null) {
            return;
        }

        $this->users()->syncWithoutDetaching([
            $this->lead_id => ['is_demo' => (bool) $this->is_demo],
        ]);

        $lead = $this->lead()->first();
        if ($lead === null) {
            return;
        }

        $programmeAssignments = $this->projects()
            ->get()
            ->mapWithKeys(fn (Project $project): array => [
                $project->id => ['is_demo' => (bool) ($this->is_demo || $project->is_demo)],
            ])
            ->all();

        $lead->projects()->syncWithoutDetaching($programmeAssignments);
    }
}
