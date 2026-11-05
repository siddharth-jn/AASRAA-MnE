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
    /** @var array<string, string> */
    private const SOURCE_FIELD_LABELS = [
        'source_project_id' => 'Source project ID',
        'name' => 'Name',
        'region' => 'Location',
        'source_program' => 'Programme',
        'source_status' => 'Status',
        'source_timing_slot' => 'Timing slot',
        'source_description' => 'Description',
        'source_address' => 'Address',
        'source_location' => 'Location detail',
        'source_started_date' => 'Started date',
        'source_poc_name' => 'Point of contact',
        'source_poc_contact' => 'Contact number',
        'source_poc_designation' => 'Contact designation',
        'source_donor_id' => 'Donor ID',
        'source_donor_name' => 'Donor',
        'source_budget_head' => 'Budget head',
    ];

    /**
     * @param  iterable<AuthoritativeProject>  $sourceProjects
     * @return array{
     *     source: int,
     *     created: int,
     *     updated: int,
     *     unchanged: int,
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
                'unchanged' => 0,
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
                    $statistics['matched_by_name'] += (int) $matchedByName;
                }

                if ($centre->trashed()) {
                    $centre->restore();
                    $statistics['restored']++;
                }

                $sourceProgram = trim((string) $sourceProject->program);
                $wasExisting = $centre->exists;
                $centre->fill($this->sourceAttributes($sourceProject, $centre));
                $hasMeaningfulChanges = $centre->isDirty();
                $centre->source_synced_at = now();
                $centre->save();

                if ($wasExisting) {
                    $statistics[$hasMeaningfulChanges ? 'updated' : 'unchanged']++;
                }

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

    /** @param iterable<AuthoritativeProject> $sourceProjects
     * @param  iterable<AuthoritativeProject>  $sourceStatuses
     * @return array<string, mixed>
     */
    public function preview(iterable $sourceProjects, iterable $sourceStatuses): array
    {
        $summary = [
            'source' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'matched_by_name' => 0,
            'restored' => 0,
            'programme_links_created' => 0,
            'status_updates' => 0,
            'unmapped_source_programmes' => [],
            'missing_local_programmes' => [],
            'potential_duplicates' => [],
            'donor_conflicts' => [],
            'missing_from_source' => [],
            'changes' => [],
        ];

        $sourceStatusIds = [];

        foreach ($sourceProjects as $sourceProject) {
            $summary['source']++;
            [$centre, $matchedByName] = $this->findCentre($sourceProject);
            if ($centre === null) {
                $summary['created']++;
                $summary['changes'][] = [
                    'source_id' => (int) $sourceProject->getKey(),
                    'type' => 'New project',
                    'project' => (string) $sourceProject->name,
                    'details' => [
                        ['field' => 'Programme', 'before' => 'Not present locally', 'after' => $this->displayValue($sourceProject->program)],
                        ['field' => 'Status', 'before' => 'Not present locally', 'after' => $this->displayValue($sourceProject->status)],
                    ],
                ];
                $possibleDuplicate = Centre::withTrashed()
                    ->whereNull('source_project_id')
                    ->get()
                    ->first(fn (Centre $candidate): bool => $this->normalizedName($candidate->name) === $this->normalizedName((string) $sourceProject->name));
                if ($possibleDuplicate) {
                    $summary['potential_duplicates'][] = "{$sourceProject->name} may match {$possibleDuplicate->name}";
                }
            } else {
                $summary['matched_by_name'] += (int) $matchedByName;
                $comparison = clone $centre;
                $sourceAttributes = $this->sourceAttributes($sourceProject, $comparison);
                $comparison->fill($sourceAttributes);
                if ($comparison->isDirty()) {
                    $summary['updated']++;
                    $summary['changes'][] = [
                        'source_id' => (int) $sourceProject->getKey(),
                        'type' => $centre->active ? 'Updated project' : 'Reactivated project',
                        'project' => (string) $sourceProject->name,
                        'details' => $this->changedSourceFields($centre, $sourceAttributes),
                    ];
                } else {
                    $summary['unchanged']++;
                }
                if ($centre->trashed()) {
                    $summary['restored']++;
                }

                $localDonors = $centre->donorAssignments()
                    ->whereDate('starts_on', '<=', now())
                    ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', now()))
                    ->with('donor')
                    ->get()
                    ->pluck('donor.name')
                    ->filter();
                $sourceDonor = $this->nullableString($sourceProject->donor_name);
                if ($localDonors->isNotEmpty() && $sourceDonor !== null && ! $localDonors->contains(fn (string $name): bool => strcasecmp($name, $sourceDonor) === 0)) {
                    $summary['donor_conflicts'][] = "{$sourceProject->name}: source says {$sourceDonor}; reporting uses {$localDonors->join(', ')}";
                }
            }

            $sourceProgram = trim((string) $sourceProject->program);
            $programmeName = $this->programmeName($sourceProgram);
            if ($programmeName === null) {
                if ($sourceProgram !== '') {
                    $summary['unmapped_source_programmes'][] = $sourceProgram;
                }

                continue;
            }
            $programme = Project::query()->where('name', $programmeName)->first();
            if ($programme === null) {
                $summary['missing_local_programmes'][] = $programmeName;

                continue;
            }
            if ($centre === null || ! $centre->projects()->whereKey($programme)->exists()) {
                $summary['programme_links_created']++;
            }
        }

        foreach ($sourceStatuses as $sourceProject) {
            $sourceStatusIds[] = (int) $sourceProject->getKey();
            $centre = Centre::withTrashed()->where('source_project_id', $sourceProject->getKey())->first();
            $sourceStatus = $this->nullableString($sourceProject->status);
            $sourceIsActive = $this->isActiveStatus($sourceStatus);
            if ($centre && ($centre->source_status !== $sourceStatus || $centre->active !== $sourceIsActive)) {
                $summary['status_updates']++;
                $alreadyReported = collect($summary['changes'])->contains(
                    fn (array $change): bool => $change['source_id'] === (int) $sourceProject->getKey(),
                );
                if (! $alreadyReported) {
                    $summary['changes'][] = [
                        'source_id' => (int) $sourceProject->getKey(),
                        'type' => $sourceIsActive ? 'Reactivated project' : 'Deactivated project',
                        'project' => $centre->name,
                        'details' => [[
                            'field' => 'Status',
                            'before' => $this->displayValue($centre->source_status),
                            'after' => $this->displayValue($sourceStatus),
                        ]],
                    ];
                }
            }
        }

        $summary['missing_from_source'] = Centre::query()
            ->whereNotNull('source_project_id')
            ->whereNotIn('source_project_id', $sourceStatusIds ?: [-1])
            ->orderBy('source_project_id')
            ->get(['source_project_id', 'name'])
            ->map(fn (Centre $centre): string => "ID {$centre->source_project_id}: {$centre->name}")
            ->values()
            ->all();

        foreach (['unmapped_source_programmes', 'missing_local_programmes', 'potential_duplicates', 'donor_conflicts', 'missing_from_source'] as $key) {
            $summary[$key] = array_values(array_unique($summary[$key]));
        }
        $summary['blocked'] = $summary['unmapped_source_programmes'] !== []
            || $summary['missing_local_programmes'] !== []
            || $summary['potential_duplicates'] !== []
            || $summary['missing_from_source'] !== [];

        return $summary;
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
                $isActive = $this->isActiveStatus($sourceStatus);
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

    /** @return array<string, mixed> */
    private function sourceAttributes(AuthoritativeProject $sourceProject, Centre $centre): array
    {
        $sourceProgram = trim((string) $sourceProject->program);

        return [
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
            'active' => true,
            'is_demo' => false,
        ];
    }

    private function normalizedName(string $name): string
    {
        return Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '')->toString();
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $sourceAttributes
     * @return list<array{field: string, before: string, after: string}>
     */
    private function changedSourceFields(Centre $centre, array $sourceAttributes): array
    {
        $changes = [];

        foreach (self::SOURCE_FIELD_LABELS as $attribute => $label) {
            $before = $centre->getAttribute($attribute);
            $after = $sourceAttributes[$attribute] ?? null;
            if ($this->comparableValue($before) === $this->comparableValue($after)) {
                continue;
            }

            $changes[] = [
                'field' => $label,
                'before' => $this->displayValue($before),
                'after' => $this->displayValue($after),
            ];
        }

        if ($centre->active !== (bool) ($sourceAttributes['active'] ?? false)) {
            $changes[] = [
                'field' => 'Active',
                'before' => $centre->active ? 'Yes' : 'No',
                'after' => ($sourceAttributes['active'] ?? false) ? 'Yes' : 'No',
            ];
        }

        return $changes;
    }

    private function comparableValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) ($value ?? ''));
    }

    private function displayValue(mixed $value): string
    {
        $value = $this->comparableValue($value);

        return $value !== '' ? $value : 'Not set';
    }

    private function isActiveStatus(?string $status): bool
    {
        return $status !== null && strcasecmp($status, 'Active') === 0;
    }
}
