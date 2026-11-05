<?php

namespace App\Services;

use App\Models\Centre;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorImportBatch;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class IndicatorSpreadsheetService
{
    private const HEADER_ROW = 5;

    private const FIRST_DATA_ROW = 6;

    private const MAX_INDICATORS = 250;

    /** @var array<int, string> */
    private const HEADERS = [
        'Indicator name',
        'Category',
        'Unit',
        'Input type',
        'Data sources',
        'Accepted options (select/status only)',
        'Percentage setup (%/ratio only)',
        'Numerator label (ratio only)',
        'Denominator label (ratio only)',
        'Across months',
        'Performance direction',
    ];

    /** @var array<int, string> */
    private const LEGACY_HEADERS = [
        'Indicator name',
        'Category',
        'Unit',
        'Input type',
        'Data sources',
        'Accepted options (use |)',
        'Percentage entry',
        'Numerator label',
        'Denominator label',
        'Across months',
        'Performance direction',
    ];

    /** @var array<string, string> */
    private const FIELD_BY_HEADER = [
        'Indicator name' => 'name',
        'Category' => 'category',
        'Unit' => 'unit',
        'Input type' => 'data_type',
        'Data sources' => 'data_sources',
        'Accepted options (select/status only)' => 'options',
        'Percentage setup (%/ratio only)' => 'calculation_method',
        'Numerator label (ratio only)' => 'numerator_label',
        'Denominator label (ratio only)' => 'denominator_label',
        'Across months' => 'time_aggregation',
        'Performance direction' => 'direction',
    ];

    /** @var array<string, string> */
    private const COLUMN_BY_LABEL = [
        'Indicator name' => 'A',
        'Category' => 'B',
        'Unit' => 'C',
        'Input type' => 'D',
        'Data sources' => 'E',
        'Accepted options (select/status only)' => 'F',
        'Accepted options (use |)' => 'F',
        'Percentage setup (%/ratio only)' => 'G',
        'Percentage entry' => 'G',
        'Numerator label (ratio only)' => 'H',
        'Numerator label' => 'H',
        'Denominator label (ratio only)' => 'I',
        'Denominator label' => 'I',
        'Across months' => 'J',
        'Performance direction' => 'K',
    ];

    /** @var array<string, string> */
    private const PERCENTAGE_METHODS = [
        'direct_percentage' => 'Direct percentage',
        'ratio_percentage' => 'Ratio percentage',
    ];

    /** @var array<string, string> */
    private const TIME_AGGREGATIONS = [
        'SUM' => 'Total across months',
        'LATEST SNAPSHOT' => 'Latest monthly value',
        'AVERAGE' => 'Average across months',
        'RATIO' => 'Ratio from monthly totals',
        'UNIQUE COUNT' => 'Cumulative unique count',
        'STATUS' => 'Count each status',
        'NON-AGGREGATABLE' => 'Do not combine across months',
    ];

    /** @var array<string, string> */
    private const DIRECTIONS = [
        'higher' => 'Higher is better',
        'lower' => 'Lower is better',
        'range' => 'Stay within a target range',
        'status' => 'Match the expected value',
    ];

    public function __construct(private readonly IndicatorService $indicators) {}

    public function write(Project $project, ?Centre $centre, string $reportingScope, string $destination): void
    {
        $this->ensureSelection($project, $centre, $reportingScope);
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('AASRAA MnE')
            ->setTitle('Indicator import template');

        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Indicators');
        $sheet->setCellValue('A1', 'AASRAA indicator setup');
        $sheet->mergeCells('A1:K1');
        $sheet->setCellValue('A2', 'Programme');
        $sheet->setCellValue('B2', $project->name);
        $sheet->setCellValue('D2', $reportingScope === 'project' ? 'Project' : 'Reporting level');
        $sheet->setCellValue('E2', $reportingScope === 'project' ? $centre?->name : 'Programme level');
        $sheet->setCellValue('A3', 'Enter one indicator per row. The website assigns the programme, project, reporting level, monthly frequency, optional evidence, required approval, and indicator code automatically.');
        $sheet->mergeCells('A3:K3');
        $sheet->setCellValue('A4', 'You may start on any row and leave blank rows between indicators. Columns F-I apply only to the input types named in their headings; leave them blank otherwise.');
        $sheet->mergeCells('A4:K4');
        $sheet->fromArray(self::HEADERS, null, 'A'.self::HEADER_ROW);

        $lastDataRow = self::FIRST_DATA_ROW + self::MAX_INDICATORS - 1;
        $sheet->getStyle('A'.self::FIRST_DATA_ROW.':K'.$lastDataRow)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF9E8');
        $sheet->getStyle('A'.self::FIRST_DATA_ROW.':K'.$lastDataRow)
            ->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->getColor()->setRGB('E2E8F0');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('0F766E');
        $sheet->getStyle('A2:E2')->getFont()->setBold(true);
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(10)->getColor()->setRGB('475569');
        $sheet->getStyle('A4')->getFont()->setBold(true)->setSize(10)->getColor()->setRGB('9A3412');
        $sheet->getStyle('A'.self::HEADER_ROW.':K'.self::HEADER_ROW)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D7E3E1']]],
        ]);
        $sheet->getStyle('F'.self::HEADER_ROW.':I'.self::HEADER_ROW)
            ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('B45309');
        $sheet->getStyle('A'.self::FIRST_DATA_ROW.':K'.$lastDataRow)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->freezePane('A'.self::FIRST_DATA_ROW);
        $sheet->setAutoFilter('A'.self::HEADER_ROW.':K'.self::HEADER_ROW);
        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(44);

        $widths = [
            'A' => 38, 'B' => 18, 'C' => 18, 'D' => 20, 'E' => 34, 'F' => 32,
            'G' => 30, 'H' => 28, 'I' => 28, 'J' => 28, 'K' => 26,
        ];
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $lists = $this->listsSheet($spreadsheet);
        $this->applyValidations($sheet, $lists, $lastDataRow);
        $this->metadataSheet($spreadsheet, $project, $centre, $reportingScope);
        $spreadsheet->setActiveSheetIndexByName('Indicators');
        $sheet->setSelectedCell('A'.self::FIRST_DATA_ROW);

        (new Xlsx($spreadsheet))->save($destination);
        $spreadsheet->disconnectWorksheets();
    }

    public function stage(
        User $user,
        Project $project,
        ?Centre $centre,
        string $reportingScope,
        string $effectiveFrom,
        UploadedFile $file,
    ): IndicatorImportBatch {
        $this->ensureSelection($project, $centre, $reportingScope);
        $effectiveDate = $this->month($effectiveFrom);
        $fingerprint = hash('sha256', implode('|', [
            hash_file('sha256', $file->getRealPath()),
            $reportingScope,
            (string) $centre?->id,
            $effectiveDate,
        ]));
        $existing = IndicatorImportBatch::query()
            ->where('project_id', $project->id)
            ->where('fingerprint', $fingerprint)
            ->first();
        if ($existing) {
            throw ValidationException::withMessages([
                'file' => "This exact file was already uploaded in indicator import batch #{$existing->id}.",
            ]);
        }

        $path = $file->storeAs('indicator-imports', Str::uuid().'.xlsx', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        try {
            $spreadsheet = IOFactory::load($absolutePath);
            $sheet = $spreadsheet->getSheetByName('Indicators');
            $metadata = $spreadsheet->getSheetByName('Metadata');
            if (! $sheet || ! $metadata) {
                throw ValidationException::withMessages(['file' => 'This is not an AASRAA indicator template. Download a fresh template and try again.']);
            }
            $this->validateMetadata($metadata, $project, $centre, $reportingScope);
            $rows = $sheet->toArray(null, false, true, false);
            $spreadsheet->disconnectWorksheets();
        } catch (ValidationException $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        } catch (Throwable) {
            Storage::disk('local')->delete($path);
            throw ValidationException::withMessages(['file' => 'The spreadsheet could not be read. Upload an unmodified AASRAA XLSX indicator template.']);
        }

        try {
            $isDemo = (bool) ($user->is_demo || $project->is_demo);
            $headers = array_slice($rows[self::HEADER_ROW - 1] ?? [], 0, count(self::HEADERS));
            if ($headers !== self::HEADERS && $headers !== self::LEGACY_HEADERS) {
                throw ValidationException::withMessages(['file' => 'The indicator template columns were changed. Download a fresh template and copy only indicator values into it.']);
            }

            $existingCodes = IndicatorDefinition::query()->pluck('code')->mapWithKeys(fn (string $code): array => [mb_strtolower(trim($code)) => true])->all();
            $existingProgrammeNames = IndicatorDefinition::query()
                ->where('project_id', $project->id)
                ->where('active', true)
                ->pluck('name')
                ->mapWithKeys(fn (string $name): array => [$this->normaliseName($name) => true])
                ->all();
            $existingCategoriesByName = IndicatorDefinition::query()
                ->where('active', true)
                ->get(['name', 'category'])
                ->groupBy(fn (IndicatorDefinition $indicator): string => $this->normaliseName($indicator->name))
                ->map(fn (Collection $definitions): array => $definitions->pluck('category')->unique()->all())
                ->all();
            $seenCodes = [];
            $seenNames = [];
            $stagedRows = [];

            foreach (array_slice($rows, self::HEADER_ROW) as $offset => $row) {
                $rowNumber = self::FIRST_DATA_ROW + $offset;
                $values = array_slice(array_pad($row, count(self::HEADERS), null), 0, count(self::HEADERS));
                if ($this->rowIsBlank($values)) {
                    continue;
                }
                if ($rowNumber >= self::FIRST_DATA_ROW + self::MAX_INDICATORS) {
                    throw ValidationException::withMessages(['file' => 'Indicator rows must remain within the '.self::MAX_INDICATORS.' prepared template rows.']);
                }
                $source = array_combine(self::HEADERS, $values);
                [$status, $proposed, $centreIds, $error] = $this->normaliseRow(
                    $project,
                    $centre,
                    $reportingScope,
                    $effectiveDate,
                    $source,
                    $existingCodes,
                    $existingProgrammeNames,
                    $existingCategoriesByName,
                    $seenCodes,
                    $seenNames,
                );
                if ($proposed !== null) {
                    $proposed['is_demo'] = $isDemo;
                }
                $stagedRows[] = [
                    'row_number' => $rowNumber,
                    'source' => $source,
                    'proposed_indicator' => $proposed,
                    'centre_ids' => $centreIds,
                    'status' => $status,
                    'error' => $error,
                    'is_demo' => $isDemo,
                ];
            }

            if (count($stagedRows) > self::MAX_INDICATORS) {
                throw ValidationException::withMessages(['file' => 'An indicator spreadsheet may contain at most '.self::MAX_INDICATORS.' indicator rows.']);
            }
            if ($stagedRows === []) {
                throw ValidationException::withMessages(['file' => 'Add at least one indicator row before uploading the template.']);
            }

            return DB::transaction(function () use ($user, $project, $file, $path, $fingerprint, $stagedRows, $isDemo): IndicatorImportBatch {
                $batch = IndicatorImportBatch::create([
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'filename' => $file->getClientOriginalName(),
                    'path' => $path,
                    'fingerprint' => $fingerprint,
                    'status' => 'preview',
                    'summary' => collect($stagedRows)->countBy('status')->all(),
                    'is_demo' => $isDemo,
                ]);
                $batch->rows()->createMany($stagedRows);

                return $batch->fresh(['project', 'user', 'rows']);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
    }

    public function confirm(IndicatorImportBatch $batch): IndicatorImportBatch
    {
        if ($batch->status !== 'preview') {
            throw ValidationException::withMessages(['batch' => 'This indicator import has already been processed.']);
        }
        if ($batch->rows()->where('status', 'invalid')->exists()) {
            throw ValidationException::withMessages(['batch' => 'Correct every invalid row and upload a new template before confirming.']);
        }

        return DB::transaction(function () use ($batch): IndicatorImportBatch {
            $batch = IndicatorImportBatch::query()->lockForUpdate()->with(['project.centres', 'rows'])->findOrFail($batch->id);
            if ($batch->status !== 'preview') {
                throw ValidationException::withMessages(['batch' => 'This indicator import has already been processed.']);
            }
            if ($batch->rows->isEmpty() || $batch->rows->contains(fn ($row): bool => $row->status !== 'valid')) {
                throw ValidationException::withMessages(['batch' => 'Only a complete, valid preview can be confirmed.']);
            }

            foreach ($batch->rows->sortBy('row_number') as $row) {
                $data = $this->indicators->prepareAndValidate($row->proposed_indicator);
                if (IndicatorDefinition::query()->whereRaw('LOWER(code) = ?', [mb_strtolower($data['code'])])->exists()) {
                    throw ValidationException::withMessages(['batch' => "Indicator code {$data['code']} now exists. Upload a fresh template to review the current data."]);
                }
                if (IndicatorDefinition::query()
                    ->where('project_id', $batch->project_id)
                    ->where('active', true)
                    ->whereRaw('LOWER(TRIM(name)) = ?', [$this->normaliseName($data['name'])])
                    ->exists()) {
                    throw ValidationException::withMessages(['batch' => "An active indicator named {$data['name']} now exists in this programme. Upload a fresh template to review the current data."]);
                }

                $centreIds = collect($row->centre_ids)->map(fn (mixed $centreId): int => (int) $centreId)->filter()->values();
                if ($data['reporting_scope'] === 'project') {
                    $availableCentreIds = $batch->project->centres->pluck('id');
                    if ($centreIds->isEmpty() || $centreIds->diff($availableCentreIds)->isNotEmpty()) {
                        throw ValidationException::withMessages(['batch' => "The project assignments on row {$row->row_number} are no longer available. Upload a fresh template."]);
                    }
                }

                $indicator = IndicatorDefinition::create($data);
                if ($indicator->isProgrammeScoped()) {
                    $indicator->assignments()->create([
                        'centre_id' => null,
                        'required' => true,
                        'is_demo' => (bool) $indicator->is_demo,
                    ]);
                } else {
                    $assignments = $centreIds->mapWithKeys(fn (int $centreId): array => [
                        $centreId => ['required' => true, 'is_demo' => (bool) $indicator->is_demo],
                    ])->all();
                    $indicator->centres()->sync($assignments);
                }
                $row->update(['status' => 'imported']);
            }

            $batch->update([
                'status' => 'completed',
                'summary' => ['imported' => $batch->rows->count()],
            ]);

            return $batch->fresh(['project', 'user', 'rows']);
        });
    }

    private function listsSheet(Spreadsheet $spreadsheet): Worksheet
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Lists');
        $lists = [
            config('impact.categories'),
            collect(config('impact.data_types'))->map(fn (string $type): string => Str::headline($type))->all(),
            array_values(self::PERCENTAGE_METHODS),
            array_values(self::TIME_AGGREGATIONS),
            array_values(self::DIRECTIONS),
        ];
        foreach ($lists as $columnIndex => $values) {
            foreach (array_values($values) as $rowIndex => $value) {
                $sheet->setCellValue([$columnIndex + 1, $rowIndex + 1], $value);
            }
        }
        $sheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

        return $sheet;
    }

    private function metadataSheet(Spreadsheet $spreadsheet, Project $project, ?Centre $centre, string $reportingScope): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Metadata');
        $sheet->fromArray([
            ['Template', 'AASRAA indicator import'],
            ['Version', '2'],
            ['Programme ID', (string) $project->id],
            ['Reporting scope', $reportingScope],
            ['Reporting project ID', (string) $centre?->id],
            ['Template token', $this->token($project, $centre, $reportingScope)],
        ], null, 'A1');
        $sheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);
    }

    private function applyValidations(Worksheet $sheet, Worksheet $lists, int $lastDataRow): void
    {
        $validations = [
            'B' => [1, count(config('impact.categories'))],
            'D' => [2, count(config('impact.data_types'))],
            'G' => [3, count(self::PERCENTAGE_METHODS)],
            'J' => [4, count(self::TIME_AGGREGATIONS)],
            'K' => [5, count(self::DIRECTIONS)],
        ];

        $prompts = [
            'B' => ['Category', 'Choose a category from the list.'],
            'D' => ['Input type', 'Choose how this indicator will be entered.'],
            'G' => ['Percentage or ratio only', 'Leave this blank for Integer and all other non-percentage input types.'],
            'J' => ['Across months', 'Choose how monthly values should be combined. Clear this cell if you want the whole row to be ignored.'],
            'K' => ['Performance direction', 'Choose how performance is judged. Clear this cell if you want the whole row to be ignored.'],
        ];

        foreach ($validations as $column => [$listColumn, $count]) {
            $listColumnName = $this->column($listColumn);
            for ($row = self::FIRST_DATA_ROW; $row <= $lastDataRow; $row++) {
                $validation = $sheet->getCell($column.$row)->getDataValidation();
                $validation->setType(DataValidation::TYPE_LIST)
                    ->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setAllowBlank(true)
                    ->setShowDropDown(true)
                    ->setShowInputMessage(true)
                    ->setShowErrorMessage(true)
                    ->setErrorTitle('Invalid option')
                    ->setError('Choose a value from the dropdown list.')
                    ->setFormula1("'{$lists->getTitle()}'!\$".$listColumnName.'$1:$'.$listColumnName.'$'.$count);
                if (isset($prompts[$column])) {
                    $validation
                        ->setPromptTitle($prompts[$column][0])
                        ->setPrompt($prompts[$column][1]);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $source
     */
    public function validationMessage(?string $error, int $rowNumber, array $source): string
    {
        $message = trim((string) $error);
        if ($message === '') {
            return "Excel row {$rowNumber} needs to be corrected.";
        }

        if ($message === 'Indicator name: this cell is required.') {
            $remainingCells = collect($source)
                ->reject(fn (mixed $value): bool => $this->isBlankCell($value))
                ->map(fn (mixed $value, string $label): ?string => isset(self::COLUMN_BY_LABEL[$label])
                    ? self::COLUMN_BY_LABEL[$label].$rowNumber.' ('.$label.')'
                    : null)
                ->filter()
                ->values();

            if ($remainingCells->isNotEmpty()) {
                return "Excel row {$rowNumber} is not blank. {$remainingCells->join(', ', ' and ')} still contain values. Either enter an indicator name in A{$rowNumber}, or clear those cells so the row is ignored.";
            }

            return "Enter an indicator name in Excel cell A{$rowNumber}.";
        }

        $requiredMessages = [
            'Category: this cell is required.' => "Choose a category in Excel cell B{$rowNumber}.",
            'Unit: this cell is required.' => "Excel cell C{$rowNumber} (Unit) is empty. Enter what the value counts, such as people, sessions, %, or status.",
            'Input type: this cell is required.' => "Choose an input type in Excel cell D{$rowNumber}.",
        ];
        if (isset($requiredMessages[$message])) {
            return $requiredMessages[$message];
        }

        if (str_starts_with($message, 'Numerator label and Denominator label:')) {
            return "Excel cells H{$rowNumber} and I{$rowNumber} are required for a ratio-based indicator. Enter a clear numerator and denominator label in both cells.";
        }

        foreach (self::COLUMN_BY_LABEL as $label => $column) {
            if (str_starts_with($message, $label.':')) {
                $detail = trim(Str::after($message, ':'));

                return "Excel cell {$column}{$rowNumber} ({$label}): {$detail}";
            }
        }

        return "Excel row {$rowNumber}: {$message}";
    }

    private function validateMetadata(Worksheet $metadata, Project $project, ?Centre $centre, string $reportingScope): void
    {
        if ((string) $metadata->getCell('B1')->getValue() !== 'AASRAA indicator import'
            || (string) $metadata->getCell('B2')->getValue() !== '2'
            || (int) $metadata->getCell('B3')->getValue() !== $project->id
            || (string) $metadata->getCell('B4')->getValue() !== $reportingScope
            || (int) $metadata->getCell('B5')->getValue() !== (int) $centre?->id
            || ! hash_equals($this->token($project, $centre, $reportingScope), (string) $metadata->getCell('B6')->getValue())) {
            throw ValidationException::withMessages(['file' => 'This template does not belong to the selected programme and project, or its protected metadata was changed. Download a fresh template.']);
        }
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, bool>  $existingCodes
     * @param  array<string, bool>  $existingProgrammeNames
     * @param  array<string, array<int, string>>  $existingCategoriesByName
     * @param  array<string, bool>  $seenCodes
     * @param  array<string, string>  $seenNames
     * @return array{string, array<string, mixed>|null, array<int, int>, string|null}
     */
    private function normaliseRow(
        Project $project,
        ?Centre $centre,
        string $reportingScope,
        string $effectiveDate,
        array $source,
        array $existingCodes,
        array $existingProgrammeNames,
        array $existingCategoriesByName,
        array &$seenCodes,
        array &$seenNames,
    ): array {
        try {
            foreach ($source as $header => $value) {
                if (is_string($value) && str_starts_with(ltrim($value), '=')) {
                    throw ValidationException::withMessages(['row' => "{$header}: formulas are not allowed. Enter a fixed value instead."]);
                }
            }

            $raw = collect(self::FIELD_BY_HEADER)->mapWithKeys(fn (string $field, string $header): array => [
                $field => $this->clean($source[$header] ?? null),
            ])->all();
            $requiredHeaders = [
                'name' => 'Indicator name',
                'category' => 'Category',
                'unit' => 'Unit',
                'data_type' => 'Input type',
            ];
            foreach ($requiredHeaders as $required => $header) {
                if (blank($raw[$required])) {
                    throw ValidationException::withMessages(['row' => "{$header}: this cell is required."]);
                }
            }

            $name = (string) $raw['name'];
            if (mb_strlen($name) > 255) {
                throw ValidationException::withMessages(['row' => 'Indicator name: use 255 characters or fewer.']);
            }
            if (mb_strlen((string) $raw['unit']) > 255) {
                throw ValidationException::withMessages(['row' => 'Unit: use 255 characters or fewer.']);
            }
            foreach (['numerator_label' => 'Numerator label', 'denominator_label' => 'Denominator label'] as $field => $header) {
                if (mb_strlen((string) $raw[$field]) > 255) {
                    throw ValidationException::withMessages(['row' => "{$header}: use 255 characters or fewer."]);
                }
            }

            $category = $this->matchOption((string) $raw['category'], config('impact.categories'), 'Category');
            $dataType = $this->matchOption(Str::snake((string) $raw['data_type']), config('impact.data_types'), 'Input type');
            $normalisedName = $this->normaliseName($name);
            if (isset($existingProgrammeNames[$normalisedName])) {
                throw ValidationException::withMessages(['row' => "Indicator name: “{$name}” already exists in the selected programme. Use a different name or edit the existing indicator."]);
            }
            $conflictingCategories = collect($existingCategoriesByName[$normalisedName] ?? [])->reject(fn (string $existingCategory): bool => $existingCategory === $category);
            if ($conflictingCategories->isNotEmpty()) {
                throw ValidationException::withMessages(['row' => "Category: “{$name}” already uses {$conflictingCategories->join(', ')} elsewhere. Use the same category for the same indicator concept."]);
            }
            if (isset($seenNames[$normalisedName])) {
                throw ValidationException::withMessages(['row' => "Indicator name: “{$name}” also appears in an earlier spreadsheet row. Keep only one copy."]);
            }

            $code = $this->generatedCode($project, $centre, $name, $existingCodes, $seenCodes);
            $normalisedCode = mb_strtolower($code);
            $calculationMethod = blank($raw['calculation_method'])
                ? null
                : $this->matchOption((string) $raw['calculation_method'], self::PERCENTAGE_METHODS, 'Percentage entry');
            if (! in_array($dataType, ['percentage', 'ratio', 'calculated'], true) && $calculationMethod !== null) {
                throw ValidationException::withMessages(['row' => "Percentage entry: leave this blank when Input type is {$raw['data_type']}."]);
            }
            if (in_array($dataType, ['ratio', 'calculated'], true) && $calculationMethod === 'direct_percentage') {
                throw ValidationException::withMessages(['row' => 'Percentage entry: choose “Ratio percentage” for Ratio or Calculated input types.']);
            }
            if ($dataType === 'percentage' && $calculationMethod === null) {
                $calculationMethod = 'direct_percentage';
            }
            if (in_array($dataType, ['ratio', 'calculated'], true)) {
                $calculationMethod = 'ratio_percentage';
            }

            if (blank($raw['time_aggregation'])) {
                if ($calculationMethod === 'ratio_percentage') {
                    $timeAggregation = 'RATIO';
                } else {
                    throw ValidationException::withMessages(['row' => 'Across months: this cell is required. Choose a value from the dropdown.']);
                }
            } else {
                $timeAggregation = $this->matchOption((string) $raw['time_aggregation'], self::TIME_AGGREGATIONS, 'Across months');
            }

            $supportedTimeAggregations = $this->indicators->timeAggregationOptions($dataType, $calculationMethod);
            if (! array_key_exists($timeAggregation, $supportedTimeAggregations)) {
                $allowedAggregations = collect(array_keys($supportedTimeAggregations))
                    ->map(fn (string $aggregation): string => self::TIME_AGGREGATIONS[$aggregation] ?? Str::headline($aggregation))
                    ->join(', ');
                throw ValidationException::withMessages(['row' => "Across months: “{$raw['time_aggregation']}” is not supported for {$raw['data_type']}. Choose: {$allowedAggregations}."]);
            }

            $direction = blank($raw['direction']) ? null : $this->matchOption((string) $raw['direction'], self::DIRECTIONS, 'Performance direction');
            $options = blank($raw['options']) ? [] : collect(explode('|', (string) $raw['options']))->map(fn (string $option): string => trim($option))->filter()->unique()->values()->all();
            if (! in_array($dataType, ['single_select', 'multi_select', 'status'], true) && $options !== []) {
                throw ValidationException::withMessages(['row' => "Accepted options (use |): leave this blank when Input type is {$raw['data_type']}."]);
            }
            if (in_array($dataType, ['single_select', 'multi_select', 'status'], true) && $options === []) {
                throw ValidationException::withMessages(['row' => "Accepted options (use |): add at least one option for {$raw['data_type']}, separating options with |."]);
            }
            if (count($options) > 100) {
                throw ValidationException::withMessages(['row' => 'Accepted options (use |): add no more than 100 options.']);
            }
            if (collect($options)->contains(fn (string $option): bool => mb_strlen($option) > 255)) {
                throw ValidationException::withMessages(['row' => 'Accepted options (use |): each option must be 255 characters or fewer.']);
            }
            if ((in_array($dataType, ['ratio', 'calculated'], true) || ($dataType === 'percentage' && $calculationMethod === 'ratio_percentage'))
                && (blank($raw['numerator_label']) || blank($raw['denominator_label']))) {
                throw ValidationException::withMessages(['row' => 'Numerator label and Denominator label: both cells are required for a ratio-based indicator.']);
            }

            if (in_array($dataType, ['integer', 'decimal', 'percentage', 'ratio', 'calculated'], true)
                && $direction !== null && ! in_array($direction, ['higher', 'lower', 'range'], true)) {
                throw ValidationException::withMessages(['row' => 'Performance direction: numeric indicators must use Higher is better, Lower is better, or Stay within a target range.']);
            }
            if (in_array($dataType, ['boolean', 'single_select', 'status'], true)
                && $direction !== null && $direction !== 'status') {
                throw ValidationException::withMessages(['row' => "Performance direction: choose “Match the expected value” when Input type is {$raw['data_type']}."]);
            }
            if (in_array($dataType, ['date', 'text', 'long_text', 'multi_select'], true) && $direction !== null) {
                throw ValidationException::withMessages(['row' => "Performance direction: leave this blank when Input type is {$raw['data_type']} because target comparison is not supported."]);
            }

            $data = array_filter([
                'project_id' => $project->id,
                'code' => $code,
                'name' => $name,
                'description' => $name,
                'data_sources' => $raw['data_sources'],
                'category' => $category,
                'unit' => (string) $raw['unit'],
                'data_type' => $dataType,
                'reporting_scope' => $reportingScope,
                'effective_date' => $effectiveDate,
                'precision' => $dataType === 'integer' ? 0 : 2,
                'max_length' => 2000,
                'options' => $options,
                'calculation_method' => $calculationMethod,
                'numerator_label' => $raw['numerator_label'],
                'denominator_label' => $raw['denominator_label'],
                'time_aggregation' => $timeAggregation,
                'centre_aggregation' => 'NON-AGGREGATABLE',
                'direction' => $direction ?? (in_array($dataType, ['boolean', 'single_select', 'status'], true) ? 'status' : 'higher'),
                'evidence_required' => false,
                'approval_required' => true,
                'allowed_extensions' => config('impact.evidence_extensions'),
                'max_file_kb' => 5120,
                'min_files' => 0,
                'max_files' => 5,
                'active' => true,
                'is_demo' => false,
            ], fn (mixed $value): bool => $value !== null);
            $data = $this->indicators->prepareAndValidate($data);

            $seenCodes[$normalisedCode] = true;
            $seenNames[$normalisedName] = $category;

            return ['valid', $data, $reportingScope === 'project' ? [(int) $centre?->id] : [], null];
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $field = array_key_first($errors);
            $message = collect($errors)->flatten()->first() ?? 'The indicator row is invalid.';
            $fieldLabels = [
                'direction' => 'Performance direction',
                'options' => 'Accepted options (use |)',
                'time_aggregation' => 'Across months',
                'centre_aggregation' => 'Across projects',
                'numerator_label' => 'Numerator label',
                'denominator_label' => 'Denominator label',
            ];
            if ($field !== 'row' && isset($fieldLabels[$field])) {
                $message = $fieldLabels[$field].': '.$message;
            }

            return ['invalid', null, $reportingScope === 'project' ? [(int) $centre?->id] : [], $message];
        }
    }

    /** @param array<int|string, string> $options */
    private function matchOption(string $value, array $options, string $label): string
    {
        $needle = mb_strtolower(trim($value));
        foreach ($options as $key => $option) {
            $internal = is_int($key) ? $option : (string) $key;
            if ($needle === mb_strtolower(trim((string) $option)) || $needle === mb_strtolower(trim($internal))) {
                return $internal;
            }
        }

        $allowed = collect($options)->values()->join(', ');

        throw ValidationException::withMessages(['row' => "{$label}: “{$value}” is not accepted. Choose one of: {$allowed}."]);
    }

    private function month(string $value): string
    {
        try {
            $month = CarbonImmutable::createFromFormat('!Y-m', trim($value));
        } catch (Throwable) {
            $month = null;
        }
        if (! $month || $month->format('Y-m') !== trim($value)) {
            throw ValidationException::withMessages(['row' => 'Reporting starts must use YYYY-MM, for example 2026-04.']);
        }

        return $month->startOfMonth()->toDateString();
    }

    private function clean(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->format('Y-m');
        }
        if (! is_string($value)) {
            return $value;
        }

        $value = str_replace("\u{00A0}", ' ', $value);
        $value = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $value) ?? $value;

        return trim($value);
    }

    /** @param array<int, mixed> $values */
    private function rowIsBlank(array $values): bool
    {
        return collect($values)->every(fn (mixed $value): bool => $this->isBlankCell($value));
    }

    private function isBlankCell(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (! is_string($value)) {
            return false;
        }

        return $this->clean($value) === '';
    }

    private function ensureSelection(Project $project, ?Centre $centre, string $reportingScope): void
    {
        if (! in_array($reportingScope, ['project', 'programme'], true)) {
            throw ValidationException::withMessages(['scope' => 'Choose either a project-level or programme-level indicator upload.']);
        }
        if ($reportingScope === 'programme' && $centre !== null) {
            throw ValidationException::withMessages(['centre_id' => 'Programme-level indicators must not be assigned to a project.']);
        }
        if ($reportingScope === 'project' && ($centre === null || ! $centre->active || ! $project->centres()->whereKey($centre->id)->exists())) {
            throw ValidationException::withMessages(['centre_id' => 'Choose an active project assigned to the selected programme.']);
        }
    }

    /**
     * @param  array<string, bool>  $existingCodes
     * @param  array<string, bool>  $seenCodes
     */
    private function generatedCode(Project $project, ?Centre $centre, string $name, array $existingCodes, array $seenCodes): string
    {
        $base = collect(['IND', $project->code, $centre?->code ?? 'PROGRAMME', $name])
            ->map(fn (string $part): string => $this->codeComponent($part))
            ->filter()
            ->implode('-');
        $base = rtrim(substr($base, 0, 100), '-');
        $candidate = $base;
        $suffix = 2;

        while (isset($existingCodes[mb_strtolower($candidate)]) || isset($seenCodes[mb_strtolower($candidate)])) {
            $suffixText = '-'.$suffix;
            $candidate = rtrim(substr($base, 0, 100 - strlen($suffixText)), '-').$suffixText;
            $suffix++;
        }

        return $candidate;
    }

    private function codeComponent(string $value): string
    {
        $component = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($value)) ?? '', '-'));

        return $component !== '' ? $component : 'INDICATOR';
    }

    private function token(Project $project, ?Centre $centre, string $reportingScope): string
    {
        return hash_hmac(
            'sha256',
            "indicator-template|2|{$project->id}|{$project->code}|{$reportingScope}|{$centre?->id}|{$centre?->code}",
            (string) config('app.key'),
        );
    }

    private function normaliseName(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
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
}
