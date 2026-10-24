<?php

namespace App\Services;

use App\Models\AuthoritativeProject;
use App\Models\Centre;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class AuthoritativeProjectSyncService
{
    /**
     * @param  iterable<AuthoritativeProject>  $sourceProjects
     * @return array{
     *     source: int,
     *     created: int,
     *     updated: int,
     *     matched_by_name: int,
     *     restored: int,
     *     programme_links_created: int,
     *     unmapped_source_programmes: list<string>,
     *     missing_local_programmes: list<string>
     * }
     */
    public function sync(iterable $sourceProjects): array
    {
        return DB::transaction(function () use ($sourceProjects): array {
            $statistics = [
                'source' => 0,
                'created' => 0,
                'updated' => 0,
                'matched_by_name' => 0,
                'restored' => 0,
                'programme_links_created' => 0,
                'unmapped_source_programmes' => [],
                'missing_local_programmes' => [],
            ];

            foreach ($sourceProjects as $sourceProject) {
                $statistics['source']++;
                [$centre, $matchedByName] = $this->findCentre($sourceProject);

                if ($centre === null) {
                    $centre = new Centre;
                    $statistics['created']++;
                } else {
                    $statistics['updated']++;
                    $statistics['matched_by_name'] += (int) $matchedByName;
                }

                if ($centre->trashed()) {
                    $centre->restore();
                    $statistics['restored']++;
                }

                $sourceProgram = trim((string) $sourceProject->program);
                $centre->fill([
                    'source_project_id' => $sourceProject->getKey(),
                    'code' => $centre->code ?: $this->availableCode($sourceProject->getKey()),
                    'name' => trim((string) $sourceProject->name),
                    'type' => $centre->type ?: $this->projectType($sourceProgram),
                    'region' => $this->region($sourceProject, $centre),
                    'provenance' => $centre->provenance ?: 'organisation database',
                    'source_program' => $sourceProgram ?: null,
                    'source_status' => $this->nullableString($sourceProject->status),
                    'source_timing_slot' => $this->nullableString($sourceProject->timing_slot),
                    'source_description' => $this->nullableString($sourceProject->description),
                    'source_address' => $this->nullableString($sourceProject->address),
                    'source_location' => $this->nullableString($sourceProject->location),
                    'source_started_date' => $sourceProject->started_date,
                    'source_poc_name' => $this->nullableString($sourceProject->poc_name),
                    'source_poc_contact' => $this->nullableString($sourceProject->poc_contact),
                    'source_poc_designation' => $this->nullableString($sourceProject->poc_designation),
                    'source_donor_id' => $sourceProject->donor_id,
                    'source_donor_name' => $this->nullableString($sourceProject->donor_name),
                    'source_budget_head' => $this->nullableString($sourceProject->budget_head),
                    'source_synced_at' => now(),
                    'active' => true,
                    'is_demo' => false,
                ])->save();

                $programmeName = $this->programmeName($sourceProgram);
                if ($programmeName === null) {
                    if ($sourceProgram !== '') {
                        $statistics['unmapped_source_programmes'][] = $sourceProgram;
                    }

                    continue;
                }

                $programme = Project::query()->where('name', $programmeName)->first();
                if ($programme === null) {
                    $statistics['missing_local_programmes'][] = $programmeName;

                    continue;
                }

                if (! $centre->projects()->whereKey($programme->getKey())->exists()) {
                    $centre->projects()->attach($programme, [
                        'is_demo' => false,
                        'provenance' => 'organisation database programme mapping',
                    ]);
                    $statistics['programme_links_created']++;
                }
            }

            $statistics['unmapped_source_programmes'] = array_values(array_unique($statistics['unmapped_source_programmes']));
            $statistics['missing_local_programmes'] = array_values(array_unique($statistics['missing_local_programmes']));

            return $statistics;
        });
    }

    /** @param  iterable<AuthoritativeProject>  $sourceStatuses */
    public function reconcileStatuses(iterable $sourceStatuses): int
    {
        return DB::transaction(function () use ($sourceStatuses): int {
            $updated = 0;

            foreach ($sourceStatuses as $sourceProject) {
                $centre = Centre::query()
                    ->where('source_project_id', $sourceProject->getKey())
                    ->first();

                if ($centre === null) {
                    continue;
                }

                $sourceStatus = $this->nullableString($sourceProject->status);
                $isActive = $sourceStatus === 'Active';
                if ($centre->source_status === $sourceStatus && $centre->active === $isActive) {
                    continue;
                }

                $centre->update([
                    'source_status' => $sourceStatus,
                    'source_synced_at' => now(),
                    'active' => $isActive,
                ]);
                $updated++;
            }

            return $updated;
        });
    }

    /** @return array{?Centre, bool} */
    private function findCentre(AuthoritativeProject $sourceProject): array
    {
        $centre = Centre::withTrashed()
            ->where('source_project_id', $sourceProject->getKey())
            ->first();

        if ($centre !== null) {
            return [$centre, false];
        }

        $matchingCentres = Centre::withTrashed()
            ->whereNull('source_project_id')
            ->where('name', trim((string) $sourceProject->name))
            ->get();

        if ($matchingCentres->count() > 1) {
            throw new LogicException("More than one local project matches authoritative project [{$sourceProject->getKey()}] by name.");
        }

        return [$matchingCentres->first(), $matchingCentres->isNotEmpty()];
    }

    private function availableCode(int|string $sourceProjectId): string
    {
        $baseCode = 'ORG-'.$sourceProjectId;
        $code = $baseCode;
        $suffix = 2;

        while (Centre::withTrashed()->where('code', $code)->exists()) {
            $code = $baseCode.'-'.$suffix;
            $suffix++;
        }

        return $code;
    }

    private function programmeName(string $sourceProgram): ?string
    {
        $programmeName = config('impact.authoritative_project_programmes.'.Str::lower($sourceProgram));

        return is_string($programmeName) && $programmeName !== '' ? $programmeName : null;
    }

    private function projectType(string $sourceProgram): string
    {
        $projectType = config('impact.authoritative_project_types.'.Str::lower($sourceProgram));

        return is_string($projectType) && $projectType !== '' ? $projectType : 'Education Centre';
    }

    private function region(AuthoritativeProject $sourceProject, Centre $centre): string
    {
        $sourceLocation = trim((string) $sourceProject->location);

        return $sourceLocation ?: ($centre->region ?: 'Not specified');
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
