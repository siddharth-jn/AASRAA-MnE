<?php

namespace App\Services;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Models\Project;
use App\Models\TargetImportBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class TargetSpreadsheetService
{
    private const HEADER_ROW = 4;

    private const MONTH_START_COLUMN = 2;

    private const ASSIGNMENT_ID_COLUMN = 14;

    private const TOKEN_COLUMN = 15;

    /** @var array<int, string> */
    private const HEADERS = [
        'Indicator', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar',
        'Assignment ID', 'Template token',
    ];

    public function __construct(
        private readonly TargetService $targets,
        private readonly DonorService $donors,
        private readonly ScopeService $scope,
    ) {}

    /** @param iterable<int, IndicatorAssignment> $assignments */
    public function write(?Project $project, ?Centre $centre, int $financialYearStart, iterable $assignments, string $destination, ?string $donor = null): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()->setCreator('AASRAA MnE')->setTitle('Monthly indicator targets');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Targets');
        $sheet->setCellValue('A1', 'AASRAA monthly target plan');
        $sheet->setCellValue('A2', 'Scope');
        $scope = $centre?->name ?? $project?->name ?? 'All programmes';
        $sheet->setCellValue('B2', $donor ? $scope.' · Donor: '.$donor : $scope);
        $sheet->setCellValue('D2', $this->financialYearLabel($financialYearStart));
        $sheet->setCellValue('F2', 'Blank month = N/A / no data entry');
        $sheet->fromArray(self::HEADERS, null, 'A'.self::HEADER_ROW);

        $row = self::HEADER_ROW + 1;
        $sortedAssignments = collect($assignments)->each->loadMissing(['indicator.project', 'centre', 'targets'])->sortBy(fn (IndicatorAssignment $assignment): string => implode('|', [
            $assignment->indicator->project->name,
            $assignment->centre?->name ?? ' Programme level',
            str_pad((string) $this->categoryOrder($assignment->indicator->category), 2, '0', STR_PAD_LEFT),
            $assignment->indicator->name,
        ]));
        $currentProgramme = null;
        $currentProject = null;
        $currentCategory = null;
        $optionsSheet = null;
        $optionsColumn = 0;

        foreach ($sortedAssignments as $assignment) {
            $programmeName = $assignment->indicator->project->name;
            $projectName = $assignment->centre?->name ?? 'Programme level';
            $category = $assignment->indicator->category;

            if (! $project && $currentProgramme !== $programmeName) {
                $this->groupRow($sheet, $row++, $programmeName, '0F766E', 'FFFFFF', 12);
                $currentProgramme = $programmeName;
                $currentProject = null;
                $currentCategory = null;
            }
            if (! $centre && $currentProject !== $programmeName.'|'.$projectName) {
                $this->groupRow($sheet, $row++, $projectName, 'DDF5F0', '0F4C45', 11);
                $currentProject = $programmeName.'|'.$projectName;
                $currentCategory = null;
            }
            if ($currentCategory !== $currentProject.'|'.$category) {
                $this->groupRow($sheet, $row++, $category, 'F1F5F9', '475569', 10);
                $currentCategory = $currentProject.'|'.$category;
            }

            $values = $this->protectedValues($assignment);
            foreach ($this->months($financialYearStart) as $month) {
                $target = $assignment->targets->first(fn (IndicatorTarget $candidate): bool => $candidate->active && $candidate->period->isSameMonth($month));
                $values[] = $this->editableValue($target);
            }
            $values[] = $assignment->id;
            $values[] = $this->token($assignment, $financialYearStart);

            foreach ($values as $index => $value) {
                $cell = $this->column($index + 1).$row;
                $sheet->setCellValueExplicit($cell, (string) ($value ?? ''), DataType::TYPE_STRING);
            }

            $sheet->getComment('A'.$row)->getText()->createTextRun(
                'Target format: '.$this->targetFormat($assignment->indicator)."\nBlank month = N/A / no data entry.",
            );
            $sheet->getStyle('B'.$row.':M'.$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF7D6');
            if ($this->targets->typeForIndicator($assignment->indicator) === 'status') {
                $optionsSheet ??= $this->optionsSheet($spreadsheet);
                $optionsColumn++;
                $options = array_keys($this->targets->statusOptionsForIndicator($assignment->indicator));
                foreach ($options as $optionIndex => $option) {
                    $optionsSheet->setCellValueExplicit([$optionsColumn, $optionIndex + 1], $option, DataType::TYPE_STRING);
                }
                if ($options !== []) {
                    $optionsColumnName = $this->column($optionsColumn);
                    foreach (range('B', 'M') as $monthColumn) {
                        $validation = $sheet->getCell($monthColumn.$row)->getDataValidation();
                        $validation->setType(DataValidation::TYPE_LIST)
                            ->setErrorStyle(DataValidation::STYLE_STOP)
                            ->setAllowBlank(true)
                            ->setShowDropDown(true)
                            ->setShowErrorMessage(true)
                            ->setErrorTitle('Invalid target')
                            ->setError('Select one of the accepted values for this indicator.')
                            ->setFormula1("'Lists'!\$".$optionsColumnName.'$1:$'.$optionsColumnName.'$'.count($options));
                    }
                }
            }
            $row++;
        }

        $lastRow = max(self::HEADER_ROW + 1, $row - 1);
        $sheet->mergeCells('A1:M1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0F766E');
        $sheet->getStyle('A'.self::HEADER_ROW.':O'.self::HEADER_ROW)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getStyle('A'.self::HEADER_ROW.':O'.$lastRow)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D7E3E1');
        $sheet->freezePane('B'.(self::HEADER_ROW + 1));
        foreach (range('B', 'M') as $column) {
            $sheet->getColumnDimension($column)->setWidth(13);
        }
        $sheet->getColumnDimension('A')->setWidth(58);
        foreach (['N', 'O'] as $column) {
            $sheet->getColumnDimension($column)->setVisible(false);
        }
        $optionsSheet?->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        (new Xlsx($spreadsheet))->save($destination);
        $spreadsheet->disconnectWorksheets();
    }

    /** @param Collection<int, Project> $projects */
    public function stage(User $user, Collection $projects, bool $allProgrammes, ?Centre $centre, int $financialYearStart, UploadedFile $file, ?string $donor = null): TargetImportBatch
    {
        $path = $file->storeAs('target-imports', Str::uuid().'.xlsx', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        try {
            $spreadsheet = IOFactory::load($absolutePath);
            $sheet = $spreadsheet->getSheetByName('Targets');
            if (! $sheet) {
                throw ValidationException::withMessages(['file' => 'This is not an AASRAA target template. Download a fresh template and try again.']);
            }
            $rows = $sheet->toArray(null, false, true, false);
            $spreadsheet->disconnectWorksheets();
        } catch (ValidationException $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        } catch (Throwable) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => 'The spreadsheet could not be read. Upload an unmodified AASRAA XLSX target template.']);
        }

        try {
            if (count($rows) > 8005) {
                throw ValidationException::withMessages(['file' => 'A target spreadsheet may contain at most 2,000 indicator rows.']);
            }
            $headers = $rows[self::HEADER_ROW - 1] ?? [];
            if (array_slice($headers, 0, count(self::HEADERS)) !== self::HEADERS) {
                throw ValidationException::withMessages(['file' => 'The template columns were changed. Download a fresh template and copy only target values into it.']);
            }

            $financialYearStartDate = CarbonImmutable::create($financialYearStart, 4, 1);
            $financialYearEndDate = $financialYearStartDate->addYear()->subDay();
            $expectedAssignments = IndicatorAssignment::query()
                ->whereIn('indicator_assignments.id', $this->scope->assignments($user)->select('indicator_assignments.id'))
                ->whereHas('indicator', fn ($query) => $query->whereIn('project_id', $projects->pluck('id')->all())->where('active', true)->where('target_tracking', true))
                ->when($centre, fn ($query) => $query->where('centre_id', $centre->id))
                ->when($donor, fn (Builder $query, string $donorName): Builder => $this->donors->applyCentreFilter($query, $donorName, $financialYearStartDate, $financialYearEndDate))
                ->with(['indicator.project', 'centre', 'targets'])
                ->get()
                ->keyBy('id');
            if ($expectedAssignments->count() > 2000) {
                throw ValidationException::withMessages(['file' => 'A target spreadsheet may contain at most 2,000 indicator rows.']);
            }
            $seen = [];
            $stagedRows = [];
            foreach (array_slice($rows, self::HEADER_ROW) as $offset => $row) {
                $rowNumber = self::HEADER_ROW + 1 + $offset;
                if (collect($row)->filter(fn ($value): bool => filled($value))->isEmpty()) {
                    continue;
                }
                if (blank($row[self::ASSIGNMENT_ID_COLUMN - 1] ?? null)) {
                    $monthlyValues = array_slice($row, self::MONTH_START_COLUMN - 1, 12);
                    if (collect($monthlyValues)->contains(fn ($value): bool => filled($value))) {
                        throw ValidationException::withMessages(['file' => "Row {$rowNumber}: targets may be entered only on indicator rows."]);
                    }

                    continue;
                }
                $assignmentId = filter_var($row[self::ASSIGNMENT_ID_COLUMN - 1] ?? null, FILTER_VALIDATE_INT);
                $assignment = $assignmentId ? $expectedAssignments->get($assignmentId) : null;
                if (! $assignment || ! hash_equals($this->token($assignment, $financialYearStart), trim((string) ($row[self::TOKEN_COLUMN - 1] ?? '')))) {
                    throw ValidationException::withMessages(['file' => "Row {$rowNumber}: protected indicator identifiers were changed or do not belong to the selected programme scope and year."]);
                }
                $protectedValues = array_map(fn (mixed $value): string => (string) ($value ?? ''), array_slice($row, 0, self::MONTH_START_COLUMN - 1));
                if ($protectedValues !== $this->protectedValues($assignment)) {
                    throw ValidationException::withMessages(['file' => "Row {$rowNumber}: the indicator name was changed. Edit only the monthly target cells."]);
                }
                if (isset($seen[$assignmentId])) {
                    throw ValidationException::withMessages(['file' => "Row {$rowNumber}: this indicator assignment appears more than once."]);
                }
                $seen[$assignmentId] = true;

                foreach ($this->months($financialYearStart) as $monthIndex => $month) {
                    $raw = trim((string) ($row[self::MONTH_START_COLUMN - 1 + $monthIndex] ?? ''));
                    if (str_starts_with($raw, '=')) {
                        throw ValidationException::withMessages(['file' => "Row {$rowNumber}: formulas are not allowed in target cells."]);
                    }
                    $existing = $assignment->targets->first(fn (IndicatorTarget $target): bool => $target->period_type === 'monthly' && $target->period->isSameMonth($month));
                    $previous = $existing ? $this->targets->snapshot($existing) : null;
                    $proposed = $raw === '' ? null : $this->targetDataForRaw($assignment, $month, $raw, "Row {$rowNumber}");
                    $action = $this->action($previous, $proposed);
                    if ($action === 'unchanged') {
                        continue;
                    }
                    $stagedRows[] = [
                        'indicator_assignment_id' => $assignment->id,
                        'period' => $month->toDateString(),
                        'row_number' => $rowNumber,
                        'action' => $action,
                        'previous_target' => $previous,
                        'proposed_target' => $proposed,
                        'affects_entry' => IndicatorEntry::query()->where('indicator_assignment_id', $assignment->id)->whereDate('period', $month)->exists(),
                        'is_demo' => (bool) $assignment->is_demo,
                    ];
                }
            }

            if (count($seen) !== $expectedAssignments->count()) {
                throw ValidationException::withMessages(['file' => 'One or more indicator rows were deleted. Download a fresh template and leave unused monthly targets blank.']);
            }

            return DB::transaction(function () use ($user, $projects, $allProgrammes, $centre, $financialYearStart, $file, $path, $absolutePath, $stagedRows): TargetImportBatch {
                $batch = TargetImportBatch::create([
                    'user_id' => $user->id,
                    'project_id' => $allProgrammes ? null : $projects->first()->id,
                    'centre_id' => $centre?->id,
                    'financial_year_start' => $financialYearStart,
                    'filename' => $file->getClientOriginalName(),
                    'path' => $path,
                    'fingerprint' => hash_file('sha256', $absolutePath),
                    'status' => 'preview',
                    'summary' => collect($stagedRows)->countBy('action')->all(),
                    'is_demo' => (bool) $user->is_demo,
                ]);
                $batch->rows()->createMany($stagedRows);

                return $batch->fresh(['rows.assignment.indicator', 'rows.assignment.centre', 'project', 'centre']);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    public function confirm(TargetImportBatch $batch): TargetImportBatch
    {
        if ($batch->status !== 'preview') {
            throw ValidationException::withMessages(['batch' => 'This target import has already been processed.']);
        }

        return DB::transaction(function () use ($batch): TargetImportBatch {
            $batch = TargetImportBatch::query()->lockForUpdate()->with('rows')->findOrFail($batch->id);
            foreach ($batch->rows as $row) {
                $current = IndicatorTarget::query()
                    ->where('indicator_assignment_id', $row->indicator_assignment_id)
                    ->where('period_type', 'monthly')
                    ->whereDate('period', $row->period)
                    ->first();
                $snapshot = $current ? $this->targets->snapshot($current) : null;
                if ($this->comparableTarget($snapshot) !== $this->comparableTarget($row->previous_target)) {
                    throw ValidationException::withMessages(['batch' => 'Targets changed after this preview was created. Download a fresh template and try again.']);
                }

                if ($row->action === 'remove') {
                    $this->targets->clear($row->indicator_assignment_id, $row->period, 'excel');
                } else {
                    $this->targets->save($row->proposed_target, 'excel');
                }
            }
            $batch->update(['status' => 'completed']);

            return $batch->fresh(['rows.assignment.indicator', 'rows.assignment.centre', 'project', 'centre']);
        });
    }

    /** @return array<int, CarbonImmutable> */
    public function months(int $financialYearStart): array
    {
        return collect(range(0, 11))->map(fn (int $offset): CarbonImmutable => CarbonImmutable::create($financialYearStart, 4, 1)->addMonths($offset))->all();
    }

    public function financialYearLabel(int $financialYearStart): string
    {
        return 'FY '.$financialYearStart.'–'.substr((string) ($financialYearStart + 1), -2);
    }

    private function token(IndicatorAssignment $assignment, int $financialYearStart): string
    {
        return hash_hmac('sha256', "{$assignment->id}|{$assignment->indicator_definition_id}|{$financialYearStart}", (string) config('app.key'));
    }

    private function targetFormat(IndicatorDefinition $indicator): string
    {
        return match ($this->targets->typeForIndicator($indicator)) {
            'ratio' => ($indicator->numerator_label ?: 'Numerator').'/'.($indicator->denominator_label ?: 'Denominator').' (example 25/40)',
            'range' => 'Minimum to maximum (example 10 to 20)',
            'status' => 'One of: '.implode(', ', array_keys($this->targets->statusOptionsForIndicator($indicator))),
            default => $indicator->data_type === 'integer' ? 'Whole number' : 'Number',
        };
    }

    /** @return array<int, string> */
    private function protectedValues(IndicatorAssignment $assignment): array
    {
        return [$assignment->indicator->name];
    }

    private function editableValue(?IndicatorTarget $target): string
    {
        if (! $target?->active) {
            return '';
        }

        return match ($target->target_type) {
            'ratio' => $this->plainNumber($target->target_numerator).'/'.$this->plainNumber($target->target_denominator),
            'range' => $this->plainNumber($target->minimum_value).' to '.$this->plainNumber($target->maximum_value),
            'status' => (string) $target->status_value,
            default => $this->plainNumber($target->value),
        };
    }

    private function groupRow(Worksheet $sheet, int $row, string $label, string $fill, string $font, int $fontSize): void
    {
        $sheet->setCellValue('A'.$row, $label);
        $sheet->mergeCells('A'.$row.':M'.$row);
        $sheet->getStyle('A'.$row.':M'.$row)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => $font], 'size' => $fontSize],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
    }

    private function optionsSheet(Spreadsheet $spreadsheet): Worksheet
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Lists');

        return $sheet;
    }

    /** @return array<string, mixed> */
    public function targetDataForRaw(IndicatorAssignment $assignment, CarbonImmutable $month, string $raw, string $location = 'Target'): array
    {
        $type = $this->targets->typeForIndicator($assignment->indicator);
        $data = ['indicator_assignment_id' => $assignment->id, 'period' => $month->toDateString()];
        if ($type === 'ratio') {
            $parts = preg_split('/\s*\/\s*/', $raw);
            if (count($parts) !== 2) {
                throw ValidationException::withMessages(['file' => "{$location}, {$month->format('M Y')}: enter a ratio as numerator/denominator."]);
            }
            $data['target_numerator'] = $parts[0];
            $data['target_denominator'] = $parts[1];
        } elseif ($type === 'range') {
            $parts = preg_split('/\s+(?:to|–|-)\s+/i', $raw);
            if (count($parts) !== 2) {
                throw ValidationException::withMessages(['file' => "{$location}, {$month->format('M Y')}: enter a range as minimum to maximum."]);
            }
            $data['minimum_value'] = $parts[0];
            $data['maximum_value'] = $parts[1];
        } elseif ($type === 'status') {
            $data['status_value'] = $raw;
        } else {
            $data['value'] = $raw;
        }

        try {
            $existingTarget = IndicatorTarget::query()
                ->where('indicator_assignment_id', $assignment->id)
                ->where('period_type', 'monthly')
                ->whereDate('period', $month->startOfMonth())
                ->first();

            return $this->targets->prepareAndValidate($data, $existingTarget);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? 'Invalid target.';
            throw ValidationException::withMessages(['file' => "{$location}, {$month->format('M Y')}: {$message}"]);
        }
    }

    private function action(?array $previous, ?array $proposed): string
    {
        if ($proposed === null) {
            return $previous !== null && ($previous['active'] ?? false) ? 'remove' : 'unchanged';
        }

        return $this->comparableTarget($previous) === $this->comparableTarget($proposed)
            ? 'unchanged'
            : ($previous === null || ! ($previous['active'] ?? false) ? 'create' : 'update');
    }

    /** @param array<string, mixed>|null $target
     * @return array<string, bool|float|string|null>|null
     */
    private function comparableTarget(?array $target): ?array
    {
        if ($target === null) {
            return null;
        }

        $comparable = [];
        foreach (['target_type', 'value', 'minimum_value', 'maximum_value', 'target_numerator', 'target_denominator', 'status_value', 'active'] as $field) {
            $comparable[$field] = $target[$field] ?? null;
        }
        $comparable['active'] = (bool) ($target['active'] ?? false);
        foreach (['value', 'minimum_value', 'maximum_value', 'target_numerator', 'target_denominator'] as $field) {
            if (array_key_exists($field, $comparable) && $comparable[$field] !== null) {
                $comparable[$field] = (float) $comparable[$field];
            }
        }

        return $comparable;
    }

    private function plainNumber(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    }

    private function column(int $number): string
    {
        $column = '';
        while ($number > 0) {
            $number--;
            $column = chr(65 + ($number % 26)).$column;
            $number = intdiv($number, 26);
        }

        return $column;
    }

    private function categoryOrder(string $category): int
    {
        $order = array_search($category, config('impact.categories'), true);

        return $order === false ? 99 : $order;
    }
}
