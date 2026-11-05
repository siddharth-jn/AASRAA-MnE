<?php

namespace App\Services;

use App\Models\AuthoritativeProject;
use Illuminate\Support\Collection;

class AuthoritativeProjectSourceService
{
    /** @return Collection<int, AuthoritativeProject> */
    public function operationalProjects(): Collection
    {
        return AuthoritativeProject::query()
            ->operational()
            ->leftJoin('donors', 'donors.id', '=', 'projects.donor_id')
            ->select($this->projectColumns())
            ->orderBy('projects.id')
            ->get();
    }

    /** @return Collection<int, AuthoritativeProject> */
    public function projectStatuses(): Collection
    {
        return AuthoritativeProject::query()
            ->withoutDemoProgramme()
            ->select(['projects.id', 'projects.status'])
            ->orderBy('projects.id')
            ->get();
    }

    /**
     * @param  Collection<int, AuthoritativeProject>  $projects
     * @param  Collection<int, AuthoritativeProject>|null  $statuses
     */
    public function snapshotHash(Collection $projects, ?Collection $statuses = null): string
    {
        return hash('sha256', json_encode([
            'projects' => $projects->map(fn (AuthoritativeProject $project): array => $project->getAttributes())->values(),
            'statuses' => $statuses?->map(fn (AuthoritativeProject $project): array => $project->getAttributes())->values(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    private function projectColumns(): array
    {
        return [
            'projects.id',
            'projects.name',
            'projects.program',
            'projects.description',
            'projects.status',
            'projects.timing_slot',
            'projects.address',
            'projects.location',
            'projects.started_date',
            'projects.poc_name',
            'projects.poc_contact',
            'projects.poc_designation',
            'projects.donor_id',
            'donors.name as donor_name',
            'projects.budget_head',
        ];
    }
}
