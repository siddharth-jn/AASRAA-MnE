<?php

namespace App\Services;

use App\Models\Centre;
use App\Models\EntryRevision;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class ImportService
{
    public function __construct(private readonly IndicatorReportingPeriodService $periods) {}

    public function upload(User $user, Project $project, UploadedFile $file): ImportBatch
    {
        $fingerprint = hash_file('sha256', $file->getRealPath());
        $existing = ImportBatch::query()->where('project_id', $project->id)->where('fingerprint', $fingerprint)->first();
        if ($existing) {
            throw ValidationException::withMessages(['file' => "This exact file was already uploaded in import batch #{$existing->id}."]);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('imports', Str::uuid().'.'.$extension, 'local');

        try {
            $spreadsheet = IOFactory::load(Storage::disk('local')->path($path));
            $data = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
            $spreadsheet->disconnectWorksheets();
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => 'The spreadsheet could not be read. Check that it is a valid CSV or XLSX file.']);
        }

        $headerRow = array_shift($data) ?? [];
        $columns = $this->uniqueHeaders($headerRow);
        if ($columns === [] || collect($columns)->every(fn (string $column): bool => str_starts_with($column, 'Column '))) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => 'The first row must contain column headings.']);
        }
        if (count($data) > 5000) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => 'A single import may contain at most 5,000 data rows.']);
        }

        $batch = ImportBatch::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'fingerprint' => $fingerprint,
            'columns' => $columns,
            'status' => 'uploaded',
            'is_demo' => false,
        ]);

        $rows = [];
        foreach ($data as $index => $values) {
            $source = [];
            foreach ($columns as $columnIndex => $column) {
                $source[$column] = $values[$columnIndex] ?? null;
            }
            if (collect($source)->every(fn ($value): bool => blank($value))) {
                continue;
            }
            $rows[] = [
                'import_batch_id' => $batch->id,
                'row_number' => $index + 2,
                'source' => json_encode($source, JSON_THROW_ON_ERROR),
                'normalized' => null,
                'status' => 'review',
                'error' => null,
                'is_demo' => false,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('import_rows')->insert($chunk);
        }

        return $batch->fresh(['rows', 'project']);
    }

    /** @param array<string, string|null> $mapping */
    public function validateRows(ImportBatch $batch, array $mapping): ImportBatch
    {
        foreach (['centre', 'indicator', 'period'] as $required) {
            if (blank($mapping[$required] ?? null)) {
                throw ValidationException::withMessages(["mapping.{$required}" => 'This mapping is required.']);
            }
        }
        if (blank($mapping['value'] ?? null) && (blank($mapping['numerator'] ?? null) || blank($mapping['denominator'] ?? null))) {
            throw ValidationException::withMessages(['mapping.value' => 'Map a value column or both numerator and denominator columns.']);
        }

        $batch->load(['project.centres', 'project.indicators.assignments']);
        $assignmentIds = $batch->project->indicators->flatMap->assignments->pluck('id');
        $frequencyByAssignmentId = $batch->project->indicators
            ->flatMap(fn (IndicatorDefinition $indicator) => $indicator->assignments->mapWithKeys(
                fn (IndicatorAssignment $assignment): array => [$assignment->id => $indicator->frequency],
            ));
        $seen = IndicatorEntry::query()
            ->whereIn('indicator_assignment_id', $assignmentIds)
            ->get(['indicator_assignment_id', 'period'])
            ->mapWithKeys(function (IndicatorEntry $entry) use ($frequencyByAssignmentId): array {
                $frequency = (string) $frequencyByAssignmentId->get($entry->indicator_assignment_id, 'monthly');
                $period = $this->periods->start($entry->period, $frequency);

                return [$entry->indicator_assignment_id.'-'.$period->format('Y-m-d') => true];
            })
            ->all();
        foreach ($batch->rows()->orderBy('row_number')->cursor() as $row) {
            [$status, $normalized, $error] = $this->normalizeRow($batch->project, $row, $mapping, $seen);
            DB::table('import_rows')->where('id', $row->id)->update([
                'status' => $status,
                'normalized' => $normalized ? json_encode($normalized, JSON_THROW_ON_ERROR) : null,
                'error' => $error,
            ]);
        }

        $batch->update(['mapping' => $mapping, 'status' => 'validated']);

        return $batch->fresh('rows');
    }

    public function confirm(User $user, ImportBatch $batch): ImportBatch
    {
        if ($batch->status !== 'validated') {
            throw ValidationException::withMessages(['batch' => 'Validate the import before confirming it.']);
        }

        DB::transaction(function () use ($user, $batch): void {
            $batch = ImportBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== 'validated') {
                throw ValidationException::withMessages(['batch' => 'This import has already been processed.']);
            }

            foreach ($batch->rows()->whereIn('status', ['valid', 'review'])->orderBy('row_number')->cursor() as $row) {
                $normalized = $row->normalized;
                $assignment = IndicatorAssignment::with('indicator.versions')->findOrFail($normalized['assignment_id']);
                $entry = IndicatorEntry::query()->lockForUpdate()->firstOrCreate([
                    'indicator_assignment_id' => $assignment->id,
                    'period' => $normalized['period'],
                ], ['status' => 'DRAFT', 'is_demo' => false]);
                if ($entry->revisions()->exists()) {
                    DB::table('import_rows')->where('id', $row->id)->update(['status' => 'duplicate', 'error' => 'A reporting record already exists.']);

                    continue;
                }

                $revision = EntryRevision::create([
                    'indicator_entry_id' => $entry->id,
                    'indicator_definition_version_id' => $assignment->indicator->versions->sortByDesc('version')->first()->id,
                    'user_id' => $user->id,
                    'version' => 1,
                    'status' => 'DRAFT',
                    'value_state' => 'reported',
                    'numeric_value' => $normalized['numeric_value'] ?? null,
                    'numerator' => $normalized['numerator'] ?? null,
                    'denominator' => $normalized['denominator'] ?? null,
                    'text_value' => $normalized['text_value'] ?? null,
                    'date_value' => $normalized['date_value'] ?? null,
                    'boolean_value' => $normalized['boolean_value'] ?? null,
                    'json_value' => $normalized['json_value'] ?? null,
                    'notes' => trim(($normalized['notes'] ?? '')."\nImported from {$batch->filename}, row {$row->row_number}.".($row->status === 'review' ? ' Evidence required before submission.' : '')),
                    'is_demo' => false,
                ]);
                $entry->update(['current_revision_id' => $revision->id, 'status' => 'DRAFT']);
                $normalized['entry_id'] = $entry->id;
                DB::table('import_rows')->where('id', $row->id)->update(['status' => 'imported', 'normalized' => json_encode($normalized, JSON_THROW_ON_ERROR), 'error' => null]);
            }

            $batch->update(['status' => 'completed']);
        });

        return $batch->fresh('rows');
    }

    /** @param array<int, mixed> $headerRow
     * @return array<int, string>
     */
    private function uniqueHeaders(array $headerRow): array
    {
        $seen = [];

        return collect($headerRow)->map(function ($header, int $index) use (&$seen): string {
            $base = trim((string) $header) ?: 'Column '.($index + 1);
            $seen[$base] = ($seen[$base] ?? 0) + 1;

            return $seen[$base] === 1 ? $base : "{$base} ({$seen[$base]})";
        })->all();
    }

    /** @param array<string, string|null> $mapping
     * @param  array<string, bool>  $seen
     * @return array{string, array<string, mixed>|null, string|null}
     */
    private function normalizeRow(Project $project, ImportRow $row, array $mapping, array &$seen): array
    {
        $source = $row->source;
        $centreReference = trim((string) ($source[$mapping['centre']] ?? ''));
        $indicatorReference = trim((string) ($source[$mapping['indicator']] ?? ''));
        $centre = $project->centres->first(fn (Centre $candidate): bool => strcasecmp($candidate->code, $centreReference) === 0 || strcasecmp($candidate->name, $centreReference) === 0);
        $indicator = $project->indicators->first(fn (IndicatorDefinition $candidate): bool => strcasecmp($candidate->code, $indicatorReference) === 0 || strcasecmp($candidate->name, $indicatorReference) === 0);
        if (! $centre) {
            return ['invalid', null, "Unknown project code or name: {$centreReference}"];
        }
        if (! $indicator) {
            return ['invalid', null, "Unknown indicator code or name: {$indicatorReference}"];
        }
        $assignment = $indicator->assignments->firstWhere('centre_id', $centre->id);
        if (! $assignment) {
            return ['invalid', null, 'The indicator is not assigned to this project.'];
        }

        try {
            $period = CarbonImmutable::parse((string) ($source[$mapping['period']] ?? ''))->startOfMonth();
        } catch (Throwable) {
            return ['invalid', null, 'The reporting period is not a valid date.'];
        }
        if (! $this->periods->isAvailable($indicator, $period)) {
            return ['invalid', null, 'Reporting for this indicator starts in '.$indicator->effective_date->format('F Y').'.'];
        }
        $period = $this->periods->start($period, $indicator->frequency);
        $key = $assignment->id.'-'.$period->toDateString();
        if (isset($seen[$key])) {
            return ['duplicate', ['assignment_id' => $assignment->id, 'period' => $period->toDateString()], 'A reporting record already exists for this indicator, project, and period.'];
        }
        $seen[$key] = true;

        $normalized = ['assignment_id' => $assignment->id, 'period' => $period->toDateString(), 'notes' => $this->mapped($source, $mapping, 'notes')];
        $value = $this->mapped($source, $mapping, 'value');
        $numerator = $this->mapped($source, $mapping, 'numerator');
        $denominator = $this->mapped($source, $mapping, 'denominator');

        if (in_array($indicator->data_type, ['ratio', 'calculated'], true)) {
            if (! is_numeric($numerator) || ! is_numeric($denominator) || (float) $denominator <= 0) {
                return ['invalid', null, 'Ratio rows require a numeric numerator and a denominator greater than zero.'];
            }
            $normalized += ['numerator' => (float) $numerator, 'denominator' => (float) $denominator, 'numeric_value' => (float) $numerator / (float) $denominator * 100];
        } elseif (in_array($indicator->data_type, ['integer', 'decimal', 'percentage'], true)) {
            if (! is_numeric($value)) {
                return ['invalid', null, 'The mapped value must be numeric.'];
            }
            $numericValue = (float) $value;
            if (($indicator->min_value !== null && $numericValue < $indicator->min_value) || ($indicator->max_value !== null && $numericValue > $indicator->max_value)) {
                return ['invalid', null, 'The numeric value is outside the configured range.'];
            }
            if ($indicator->data_type === 'integer' && floor($numericValue) !== $numericValue) {
                return ['invalid', null, 'The configured indicator requires a whole number.'];
            }
            $normalized['numeric_value'] = $numericValue;
        } elseif ($indicator->data_type === 'boolean') {
            $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($boolean === null) {
                return ['invalid', null, 'Use yes/no or true/false for this indicator.'];
            }
            $normalized['boolean_value'] = $boolean;
        } elseif ($indicator->data_type === 'date') {
            try {
                $normalized['date_value'] = CarbonImmutable::parse((string) $value)->toDateString();
            } catch (Throwable) {
                return ['invalid', null, 'The mapped value is not a valid date.'];
            }
        } elseif ($indicator->data_type === 'multi_select') {
            $values = array_values(array_filter(array_map('trim', explode(',', (string) $value))));
            if (array_diff($values, $indicator->options ?: []) !== []) {
                return ['invalid', null, 'One or more values are not accepted by this indicator.'];
            }
            $normalized['json_value'] = $values;
        } else {
            $text = trim((string) $value);
            if (in_array($indicator->data_type, ['single_select', 'status'], true) && ! in_array($text, $indicator->options ?: [], true)) {
                return ['invalid', null, 'The value is not an accepted option for this indicator.'];
            }
            if (mb_strlen($text) > $indicator->max_length) {
                return ['invalid', null, 'The value exceeds the configured text length.'];
            }
            $normalized['text_value'] = $text;
        }

        return [$indicator->evidence_required ? 'review' : 'valid', $normalized, $indicator->evidence_required ? 'Evidence is required before this draft can be submitted.' : null];
    }

    /** @param array<string, mixed> $source
     * @param  array<string, string|null>  $mapping
     */
    private function mapped(array $source, array $mapping, string $field): mixed
    {
        $column = $mapping[$field] ?? null;

        return $column ? ($source[$column] ?? null) : null;
    }
}
