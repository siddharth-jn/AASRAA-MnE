<?php

namespace App\Services;

use App\Models\User;
use App\Support\WorkflowStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

class ApprovalSpreadsheetService
{
    private const HEADERS = [
        'Programme',
        'Project',
        'Indicator',
        'Period',
        'Value',
        'Unit',
        'Submitted by',
        'Submitted at',
        'Evidence',
        'Approval stage',
        'Approve',
        'Comment',
        'Entry ID',
        'Revision ID',
        'Approval status',
        'Approval token',
    ];

    public function __construct(
        private readonly EntryService $entries,
        private readonly ScopeService $scope,
    ) {}

    /** @param array<string, mixed> $filters */
    public function write(User $user, array $filters, string $destination): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setCreator('AASRAA MnE')
            ->setTitle('Pending approvals')
            ->setDescription('Approve pending submissions at their current stage by entering Yes in the Approve column.');
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Approvals');
        $sheet->fromArray(self::HEADERS, null, 'A1');

        $row = 2;
        foreach ($this->pendingQuery($user, $filters)->cursor() as $entry) {
            $revision = $entry->currentRevision;
            $evidenceCount = $revision->evidence->count();
            $values = [
                $entry->assignment->indicator->project->name,
                $entry->assignment->centre?->name ?? 'Programme level',
                $entry->assignment->indicator->name,
                $entry->periodLabel(),
                $revision->displayValue(),
                $entry->assignment->indicator->unit,
                $revision->user->name,
                $revision->submitted_at?->format('d M Y, H:i'),
                $entry->assignment->indicator->evidence_required
                    ? "Required - {$evidenceCount} file(s)"
                    : ($evidenceCount > 0 ? "Optional - {$evidenceCount} file(s)" : 'Optional - none'),
                WorkflowStatus::label($entry->status),
                '',
                '',
                $entry->id,
                $revision->id,
                $entry->status,
                $this->token($user, $entry->id, $revision->id, $entry->status),
            ];

            foreach ($values as $columnIndex => $value) {
                $column = chr(65 + $columnIndex);
                if (in_array($column, ['M', 'N'], true)) {
                    $sheet->setCellValue($column.$row, $value);
                } else {
                    $sheet->setCellValueExplicit($column.$row, (string) ($value ?? ''), DataType::TYPE_STRING);
                }
            }
            $row++;
        }

        $lastRow = max(2, $row - 1);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:P{$lastRow}");
        $sheet->getStyle('A1:P1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0F766E']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("A1:P{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D7E3E1');
        $sheet->getStyle("A2:L{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
        $sheet->getStyle("K2:L{$lastRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF7D6');
        $sheet->getColumnDimension('A')->setWidth(24);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(48);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(22);
        $sheet->getColumnDimension('F')->setWidth(14);
        $sheet->getColumnDimension('G')->setWidth(24);
        $sheet->getColumnDimension('H')->setWidth(20);
        $sheet->getColumnDimension('I')->setWidth(22);
        $sheet->getColumnDimension('J')->setWidth(28);
        $sheet->getColumnDimension('K')->setWidth(14);
        $sheet->getColumnDimension('L')->setWidth(34);
        foreach (['M', 'N', 'O', 'P'] as $column) {
            $sheet->getColumnDimension($column)->setVisible(false);
        }
        $sheet->getRowDimension(1)->setRowHeight(26);

        if ($row > 2) {
            $validation = new DataValidation;
            $validation->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_STOP)
                ->setAllowBlank(true)
                ->setShowErrorMessage(true)
                ->setShowInputMessage(true)
                ->setShowDropDown(true)
                ->setErrorTitle('Invalid approval value')
                ->setError('Choose Yes or No from the list.')
                ->setPromptTitle('Stage approval')
                ->setPrompt('Choose Yes to approve this submission at the stage shown in the spreadsheet.')
                ->setFormula1('"Yes,No"');
            $sheet->setDataValidation("K2:K{$lastRow}", $validation);
        }

        $spreadsheet->setActiveSheetIndex(0);
        (new Xlsx($spreadsheet))->save($destination);
        $spreadsheet->disconnectWorksheets();
    }

    /** @return array{approved: int, skipped: int} */
    public function approve(User $user, UploadedFile $file): array
    {
        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $spreadsheet->getSheetByName('Approvals');
            if ($sheet === null) {
                throw ValidationException::withMessages(['file' => 'This is not an AASRAA approvals spreadsheet. Download a fresh file from the Approvals page.']);
            }
            $rows = $sheet->toArray(null, false, true, false);
            $spreadsheet->disconnectWorksheets();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'The spreadsheet could not be read. Download a fresh XLSX file and try again.']);
        }

        if (count($rows) > 5001) {
            throw ValidationException::withMessages(['file' => 'A single approval spreadsheet may contain at most 5,000 submissions.']);
        }

        $headerIndexes = $this->headerIndexes(array_shift($rows) ?? []);
        $selected = [];
        $seenEntryIds = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $decision = Str::lower(trim((string) ($row[$headerIndexes['Approve']] ?? '')));
            if ($decision === '' || $decision === 'no') {
                continue;
            }
            if ($decision !== 'yes') {
                throw ValidationException::withMessages(['file' => "Row {$rowNumber}: Approve must be Yes, No, or blank."]);
            }

            $entryId = filter_var($row[$headerIndexes['Entry ID']] ?? null, FILTER_VALIDATE_INT);
            $revisionId = filter_var($row[$headerIndexes['Revision ID']] ?? null, FILTER_VALIDATE_INT);
            $approvalStatus = trim((string) ($row[$headerIndexes['Approval status']] ?? ''));
            $token = trim((string) ($row[$headerIndexes['Approval token']] ?? ''));
            if (
                ! $entryId
                || ! $revisionId
                || ! in_array($approvalStatus, $this->pendingStatuses($user), true)
                || ! hash_equals($this->token($user, $entryId, $revisionId, $approvalStatus), $token)
            ) {
                throw ValidationException::withMessages(['file' => "Row {$rowNumber}: The protected approval identifiers were changed. Download a fresh file and try again."]);
            }
            if (isset($seenEntryIds[$entryId])) {
                throw ValidationException::withMessages(['file' => "Row {$rowNumber}: The same submission appears more than once."]);
            }
            $seenEntryIds[$entryId] = true;
            $comment = trim((string) ($row[$headerIndexes['Comment']] ?? ''));
            if (Str::length($comment) > 2000) {
                throw ValidationException::withMessages(['file' => "Row {$rowNumber}: Comment may not exceed 2,000 characters."]);
            }
            $selected[] = [
                'entry_id' => $entryId,
                'revision_id' => $revisionId,
                'approval_status' => $approvalStatus,
                'comment' => $comment !== '' ? $comment : null,
            ];
        }

        if ($selected === []) {
            throw ValidationException::withMessages(['file' => 'Enter Yes in the Approve column for at least one submission.']);
        }

        $approved = 0;
        $skipped = 0;
        foreach ($selected as $selection) {
            $entry = $this->scope->entries($user)
                ->targetBacked()
                ->whereKey($selection['entry_id'])
                ->where('status', $selection['approval_status'])
                ->where('current_revision_id', $selection['revision_id'])
                ->with(['assignment.indicator', 'currentRevision.evidence'])
                ->first();
            if (! $entry || ($entry->assignment->indicator->evidence_required && $entry->currentRevision->evidence->isEmpty())) {
                $skipped++;

                continue;
            }

            try {
                $this->entries->review(
                    $user,
                    $entry,
                    'APPROVED',
                    $selection['comment'] ?? sprintf(
                        'Approved at the %s stage through the approvals spreadsheet.',
                        WorkflowStatus::label($selection['approval_status']),
                    ),
                );
                $approved++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return compact('approved', 'skipped');
    }

    /** @param array<string, mixed> $filters */
    private function pendingQuery(User $user, array $filters): Builder
    {
        return $this->scope->entries($user)
            ->targetBacked()
            ->whereIn('status', $this->pendingStatuses($user))
            ->when((int) ($filters['programme'] ?? 0), fn (Builder $query, int $programmeId): Builder => $query->whereHas('assignment.indicator', fn (Builder $indicatorQuery): Builder => $indicatorQuery->where('project_id', $programmeId)))
            ->when((int) ($filters['project'] ?? 0), fn (Builder $query, int $projectId): Builder => $query->whereHas('assignment', fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('centre_id', $projectId)))
            ->when((int) ($filters['indicator'] ?? 0), fn (Builder $query, int $indicatorId): Builder => $query->whereHas('assignment', fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('indicator_definition_id', $indicatorId)))
            ->with(['assignment.indicator.project', 'assignment.centre', 'currentRevision.user', 'currentRevision.evidence'])
            ->orderBy('period')
            ->orderBy('id');
    }

    /** @param array<int, mixed> $headers
     * @return array<string, int>
     */
    private function headerIndexes(array $headers): array
    {
        $indexes = [];
        foreach ($headers as $index => $header) {
            $name = trim((string) $header);
            if ($name !== '') {
                $indexes[$name] = $index;
            }
        }

        foreach (self::HEADERS as $header) {
            if (! array_key_exists($header, $indexes)) {
                throw ValidationException::withMessages(['file' => "The required {$header} column is missing. Download a fresh file and try again."]);
            }
        }

        return $indexes;
    }

    /** @return array<int, string> */
    private function pendingStatuses(User $user): array
    {
        return match ($user->role) {
            'ADMIN' => WorkflowStatus::pending(),
            'SENIOR PROGRAM LEAD' => [WorkflowStatus::PendingSeniorProgrammeLead],
            default => [WorkflowStatus::PendingMneHead],
        };
    }

    private function token(User $user, int $entryId, int $revisionId, string $approvalStatus): string
    {
        return hash_hmac('sha256', "{$user->id}|{$entryId}|{$revisionId}|{$approvalStatus}", (string) config('app.key'));
    }
}
