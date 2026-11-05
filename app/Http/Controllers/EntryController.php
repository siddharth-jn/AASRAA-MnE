<?php

namespace App\Http\Controllers;

use App\Models\EvidenceFile;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use App\Services\CompletenessService;
use App\Services\DonorService;
use App\Services\EntryService;
use App\Services\IndicatorReportingPeriodService;
use App\Services\ReportingPeriodService;
use App\Services\ScopeService;
use App\Services\TargetService;
use App\Support\WorkflowStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EntryController extends Controller
{
    public function index(
        Request $request,
        ScopeService $scope,
        CompletenessService $completeness,
        DonorService $donors,
        ReportingPeriodService $reportingPeriods,
        IndicatorReportingPeriodService $indicatorPeriods,
    ): View {
        Gate::authorize('submit');

        $request->validate([
            'period_type' => ['nullable', Rule::in(['monthly'])],
            'anchor' => ['nullable', 'date_format:Y-m'],
            'period' => ['nullable', 'date_format:Y-m'],
            'donor' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'entry_status' => ['nullable', Rule::in(['unfilled', 'entered'])],
        ]);
        $request->merge([
            'period_type' => $request->input('period_type', 'monthly'),
            'anchor' => $request->input('anchor', $request->input('period', CarbonImmutable::now()->format('Y-m'))),
        ]);
        $reportingPeriod = $reportingPeriods->fromRequest($request);
        $frequency = 'monthly';
        $periodStart = $reportingPeriod['start'];
        $periodEnd = $reportingPeriod['end'];
        $projectId = $request->integer('project') ?: null;
        $centreId = $request->integer('centre') ?: null;
        $donor = $request->string('donor')->trim()->value() ?: null;
        $indicatorSearch = $request->string('search')->trim()->value() ?: null;
        $entryStatus = $request->string('entry_status')->value() ?: null;
        $availableDonors = $donors->availableNames($scope->centres($request->user()), $periodStart, $periodEnd);

        if ($donor) {
            abort_unless($availableDonors->contains($donor), 422, 'Choose a donor available during the selected month.');
        }

        if ($projectId) {
            abort_unless($scope->projects($request->user())->whereKey($projectId)->exists(), 403);
        }

        if ($centreId) {
            $centreQuery = $scope->centres($request->user())->whereKey($centreId);
            if ($projectId) {
                $centreQuery->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereKey($projectId));
            }
            if ($donor) {
                $donors->applyToCentres($centreQuery, $donor, $periodStart, $periodEnd);
            }
            abort_unless($centreQuery->exists(), 422, 'The selected project is not assigned to the selected programme.');
        }

        $assignmentQuery = $scope->assignments($request->user())
            ->when($donor, fn (Builder $query, string $donorName): Builder => $donors->applyCentreFilter($query, $donorName, $periodStart, $periodEnd))
            ->when($centreId, fn (Builder $query): Builder => $query->where('centre_id', $centreId))
            ->whereHas('indicator', function (Builder $query) use ($frequency, $projectId, $indicatorSearch): void {
                $query->where('active', true)
                    ->where('frequency', $frequency);
                if ($projectId) {
                    $query->where('project_id', $projectId);
                }
                if ($indicatorSearch) {
                    $query->where(function (Builder $searchQuery) use ($indicatorSearch): void {
                        $searchQuery->where('name', 'like', '%'.$indicatorSearch.'%')
                            ->orWhere('code', 'like', '%'.$indicatorSearch.'%');
                    });
                }
            });

        $assignments = (clone $assignmentQuery)
            ->with([
                'indicator.project',
                'centre',
                'targets' => fn ($query) => $query->where('period_type', 'monthly')->where('active', true)->whereDate('period', $periodStart),
                'entries' => fn ($query) => $query->whereBetween('period', [$periodStart, $periodEnd])->with('currentRevision'),
            ])
            ->withExists(['entries as has_period_entry' => fn (Builder $query): Builder => $query->whereBetween('period', [$periodStart, $periodEnd])])
            ->whereHas('indicator', fn (Builder $query): Builder => $query->whereDate('effective_date', '<=', $periodEnd))
            ->whereHas('targets', fn (Builder $query): Builder => $query->where('period_type', 'monthly')->where('active', true)->whereDate('period', $periodStart))
            ->when($entryStatus === 'unfilled', fn (Builder $query): Builder => $query->whereDoesntHave('entries', fn (Builder $entryQuery): Builder => $entryQuery->whereBetween('period', [$periodStart, $periodEnd])))
            ->when($entryStatus === 'entered', fn (Builder $query): Builder => $query->whereHas('entries', fn (Builder $entryQuery): Builder => $entryQuery->whereBetween('period', [$periodStart, $periodEnd])))
            ->orderBy('has_period_entry')
            ->orderBy('indicator_definition_id')
            ->orderBy('centre_id')
            ->paginate(30)
            ->withQueryString();

        $visibleCentreIds = $scope->centres($request->user())->select('centres.id');
        $projects = $scope->projects($request->user())
            ->when($donor, fn (Builder $query, string $donorName): Builder => $query->whereHas('centres', function (Builder $centreQuery) use ($donors, $donorName, $periodStart, $periodEnd, $visibleCentreIds): void {
                $centreQuery->whereIn('centres.id', $visibleCentreIds);
                $donors->applyToCentres($centreQuery, $donorName, $periodStart, $periodEnd);
            }))
            ->orderBy('name')
            ->get();
        if ($projectId) {
            abort_unless($projects->contains('id', $projectId), 422, 'The selected programme is not available for this donor and month.');
        }
        $otherTargetMonths = collect();
        if ($assignments->isEmpty()) {
            $otherTargetMonths = IndicatorTarget::query()
                ->whereIn('indicator_assignment_id', (clone $assignmentQuery)->select('indicator_assignments.id'))
                ->where('period_type', 'monthly')
                ->where('active', true)
                ->whereDate('period', '!=', $periodStart)
                ->select('period')
                ->distinct()
                ->orderByDesc('period')
                ->limit(12)
                ->get()
                ->map(fn (IndicatorTarget $target): CarbonImmutable => CarbonImmutable::parse($target->period)->startOfMonth())
                ->sort()
                ->values();
        }

        return view('entries.index', [
            'assignments' => $assignments,
            'reportingPeriod' => $reportingPeriod,
            'periodLabel' => $indicatorPeriods->label($periodStart, $frequency),
            'projects' => $projects,
            'centres' => $scope->centres($request->user())
                ->when($projectId, fn (Builder $query): Builder => $query->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereKey($projectId)))
                ->when($donor, fn (Builder $query, string $donorName): Builder => $donors->applyToCentres($query, $donorName, $periodStart, $periodEnd))
                ->orderBy('name')
                ->get(),
            'selectedProjectId' => $projectId,
            'selectedCentreId' => $centreId,
            'selectedProject' => $projects->firstWhere('id', $projectId),
            'projectLocked' => $projectId !== null && $request->boolean('lock_project'),
            'availableDonors' => $availableDonors,
            'selectedDonor' => $donor,
            'indicatorSearch' => $indicatorSearch,
            'selectedEntryStatus' => $entryStatus,
            'otherTargetMonths' => $otherTargetMonths,
            'completeness' => $completeness->summary($request->user(), $periodStart, $periodEnd, $projectId, $centreId, $frequency, $donor, $indicatorSearch),
        ]);
    }

    public function edit(
        Request $request,
        IndicatorAssignment $assignment,
        ScopeService $scope,
        TargetService $targets,
        IndicatorReportingPeriodService $periods,
    ): View {
        abort_unless($scope->assignments($request->user())->whereKey($assignment)->exists() && Gate::allows('submit'), 403);
        $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
            'period_type' => ['nullable', Rule::in(['monthly'])],
            'donor' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'entry_status' => ['nullable', Rule::in(['unfilled', 'entered'])],
        ]);
        $assignment->load(['indicator.project', 'centre']);
        abort_if(
            $request->filled('period_type') && $request->input('period_type') !== $assignment->indicator->frequency,
            422,
            'The selected reporting frequency does not match this indicator.',
        );
        $requestedPeriod = CarbonImmutable::parse($request->input('period', CarbonImmutable::now()->format('Y-m')).'-01')->startOfMonth();
        abort_unless(
            $periods->isAvailable($assignment->indicator, $requestedPeriod),
            422,
            'Reporting for this indicator starts in '.$assignment->indicator->effective_date->format('F Y').'.',
        );
        [$periodStart, $periodEnd] = $periods->boundaries($requestedPeriod, $assignment->indicator->frequency);
        $period = $periodStart->toDateString();
        $entry = IndicatorEntry::with(['currentRevision.evidence', 'revisions.user', 'revisions.evidence', 'revisions.actions.user'])
            ->where('indicator_assignment_id', $assignment->id)
            ->whereBetween('period', [$periodStart, $periodEnd])
            ->first();
        $target = $targets->findForPeriod($assignment->id, CarbonImmutable::parse($period));
        abort_unless($target, 422, 'No target is set for this month, so there is no reporting obligation or data entry.');
        $periodLabel = $periods->label($period, $assignment->indicator->frequency);

        return view('entries.edit', compact('assignment', 'entry', 'period', 'periodLabel', 'target'));
    }

    public function store(Request $request, IndicatorAssignment $assignment, ScopeService $scope, EntryService $service): RedirectResponse
    {
        abort_unless($scope->assignments($request->user())->whereKey($assignment)->exists() && Gate::allows('submit'), 403);
        $definition = $assignment->load('indicator')->indicator;
        $requiredValue = 'required';
        $rules = [
            'period' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'variance_explanation' => ['nullable', 'string', 'max:3000'],
            'project' => ['nullable', 'integer'],
            'centre' => ['nullable', 'integer'],
            'donor' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'entry_status' => ['nullable', Rule::in(['unfilled', 'entered'])],
        ];
        if (in_array($definition->data_type, ['ratio', 'calculated'], true) || ($definition->data_type === 'percentage' && $definition->calculation_method === 'ratio_percentage')) {
            $rules += ['numerator' => [$requiredValue, 'nullable', 'numeric', 'min:0'], 'denominator' => [$requiredValue, 'nullable', 'numeric', 'gt:0']];
        } elseif (in_array($definition->data_type, ['integer', 'decimal', 'percentage'], true)) {
            $rules['numeric_value'] = [$requiredValue, 'nullable', $definition->data_type === 'integer' ? 'integer' : 'numeric'];
            if ($definition->min_value !== null) {
                $rules['numeric_value'][] = 'min:'.$definition->min_value;
            }
            if ($definition->max_value !== null) {
                $rules['numeric_value'][] = 'max:'.$definition->max_value;
            }
        } elseif ($definition->data_type === 'boolean') {
            $rules['boolean_value'] = [$requiredValue, 'nullable', 'boolean'];
        } elseif ($definition->data_type === 'date') {
            $rules['date_value'] = [$requiredValue, 'nullable', 'date'];
            if ($definition->minimum_date) {
                $rules['date_value'][] = 'after_or_equal:'.$definition->minimum_date->toDateString();
            }
            if ($definition->maximum_date) {
                $rules['date_value'][] = 'before_or_equal:'.$definition->maximum_date->toDateString();
            }
        } elseif (in_array($definition->data_type, ['single_select', 'status'], true)) {
            $rules['text_value'] = [$requiredValue, 'nullable', Rule::in($definition->options ?: [])];
        } elseif ($definition->data_type === 'multi_select') {
            $rules['json_value'] = [$requiredValue, 'nullable', 'array'];
            $rules['json_value.*'] = [Rule::in($definition->options ?: [])];
        } else {
            $rules['text_value'] = [$requiredValue, 'nullable', 'string', 'max:'.$definition->max_length];
        }
        $rules['evidence'] = ['nullable', 'array', 'max:'.$definition->max_files];
        $rules['evidence.*'] = ['file', 'mimes:'.implode(',', $definition->allowed_extensions ?: config('impact.evidence_extensions')), 'max:'.$definition->max_file_kb];
        $data = $request->validate($rules);
        $data['value_state'] = 'reported';
        if ((in_array($definition->data_type, ['ratio', 'calculated'], true) || ($definition->data_type === 'percentage' && $definition->calculation_method === 'ratio_percentage')) && isset($data['numerator'], $data['denominator'])) {
            $data['numeric_value'] = $data['numerator'] / $data['denominator'] * 100;
            if ($definition->min_value !== null && $data['numeric_value'] < $definition->min_value) {
                throw ValidationException::withMessages(['numerator' => "The calculated value must be at least {$definition->min_value}."]);
            }
            if ($definition->max_value !== null && $data['numeric_value'] > $definition->max_value) {
                throw ValidationException::withMessages(['numerator' => "The calculated value may not exceed {$definition->max_value}."]);
            }
        }
        $entry = $service->save($request->user(), $assignment, $data['period'], $data, $request->file('evidence', []), $request->input('action') === 'submit');

        $redirectParameters = [
            'period' => substr($data['period'], 0, 7),
            'project' => $data['project'] ?? null,
            'centre' => $data['centre'] ?? null,
            'donor' => $data['donor'] ?? null,
            'search' => $data['search'] ?? null,
            'entry_status' => $data['entry_status'] ?? null,
        ];
        if ($request->boolean('lock_project')) {
            $redirectParameters += [
                'project' => $definition->project_id,
                'centre' => $assignment->centre_id,
                'lock_project' => 1,
            ];
        }

        $message = match (true) {
            $request->input('action') !== 'submit' => 'Draft saved.',
            $entry->status === WorkflowStatus::FinalApproved => 'Result saved and final approved.',
            default => 'Entry submitted for approval.',
        };

        return redirect()->route('entries.index', $redirectParameters)->with('success', $message);
    }

    public function approvals(Request $request, ScopeService $scope): View
    {
        Gate::authorize('review');

        $request->validate([
            'programme' => ['nullable', 'integer'],
            'project' => ['nullable', 'integer'],
            'indicator' => ['nullable', 'integer'],
            'per_page' => ['nullable', Rule::in([25, 50, 100])],
            'tab' => ['nullable', Rule::in(['pending', 'approved'])],
        ]);
        $tab = $request->string('tab', 'pending')->value();
        $query = $tab === 'approved'
            ? $this->approvedQuery($request, $scope)
            : $this->approvalQuery($request, $scope);

        return view('approvals.index', [
            'entries' => $query->paginate($request->integer('per_page') ?: 25)->withQueryString(),
            'matchingCount' => (clone $query)->reorder()->count(),
            'tab' => $tab,
            'programmes' => $scope->projects($request->user())->orderBy('name')->get(),
            'projects' => $scope->centres($request->user())
                ->when($request->integer('programme'), fn (Builder $query, int $programmeId): Builder => $query->whereHas('projects', fn (Builder $programmeQuery): Builder => $programmeQuery->whereKey($programmeId)))
                ->orderBy('name')
                ->get(),
            'indicators' => IndicatorDefinition::query()
                ->whereIn('project_id', $scope->projects($request->user())->select('projects.id'))
                ->when($request->integer('programme'), fn (Builder $query, int $projectId): Builder => $query->where('project_id', $projectId))
                ->when($request->integer('project'), fn (Builder $query, int $centreId): Builder => $query->whereHas('assignments', fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('centre_id', $centreId)))
                ->with('project:id,name')
                ->where('active', true)
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function review(Request $request, IndicatorEntry $entry, EntryService $service): RedirectResponse
    {
        Gate::authorize('review');
        Gate::authorize('view-entry', $entry);
        $entry->loadMissing('assignment.indicator');
        $rules = ['action' => ['required', Rule::in(['APPROVED', 'RETURNED', 'REJECTED'])], 'comment' => ['nullable', 'string', 'max:2000']];
        $data = $request->validate($rules);
        $service->review($request->user(), $entry, $data['action'], $data['comment'] ?? null);

        return back()->with('success', 'Review decision recorded.');
    }

    public function requestAmendment(Request $request, IndicatorEntry $entry, ScopeService $scope, EntryService $service): RedirectResponse
    {
        Gate::authorize('submit');
        abort_unless($scope->canAccess($request->user(), $entry), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $service->requestAmendment($request->user(), $entry, $data['reason']);

        return back()->with('success', 'An amendment draft was created. The previously approved result remains official until the amendment completes both approval stages.');
    }

    public function bulkReview(Request $request, ScopeService $scope, EntryService $service): RedirectResponse
    {
        Gate::authorize('bulk-approve');
        $data = $request->validate([
            'selection' => ['required', Rule::in(['selected', 'all'])],
            'entry_ids' => ['nullable', 'array'],
            'entry_ids.*' => ['integer'],
            'confirmation' => ['accepted'],
        ]);

        $query = $this->approvalQuery($request, $scope);
        if ($data['selection'] === 'selected') {
            $query->whereKey($data['entry_ids'] ?? []);
        }

        $approved = 0;
        $skipped = 0;
        $query->with(['assignment.indicator', 'currentRevision.evidence'])->orderBy('id')->chunkById(100, function ($entries) use ($request, $service, &$approved, &$skipped): void {
            foreach ($entries as $entry) {
                if ($entry->assignment->indicator->evidence_required && $entry->currentRevision->evidence->isEmpty()) {
                    $skipped++;

                    continue;
                }

                try {
                    $service->review($request->user(), $entry, 'APPROVED', 'Bulk approved at the current review stage after confirmation.');
                    $approved++;
                } catch (ValidationException) {
                    $skipped++;
                }
            }
        });

        return back()->with('success', "{$approved} submission(s) approved at your stage. {$skipped} skipped because they changed or did not meet approval requirements.");
    }

    public function evidence(EvidenceFile $evidence): StreamedResponse
    {
        Gate::authorize('view-evidence', $evidence);
        $disk = Storage::disk($evidence->disk ?: config('impact.evidence_disk', 'local'));
        abort_unless($disk->exists($evidence->path), 404, 'This evidence file is unavailable in storage.');

        return $disk->download($evidence->path, $evidence->original_name, ['Content-Type' => $evidence->mime_type]);
    }

    public function previewEvidence(EvidenceFile $evidence): StreamedResponse
    {
        Gate::authorize('view-evidence', $evidence);
        abort_unless(str_starts_with($evidence->mime_type, 'image/') || $evidence->mime_type === 'application/pdf', 415, 'Preview is not available for this file type.');
        $disk = Storage::disk($evidence->disk ?: config('impact.evidence_disk', 'local'));
        abort_unless($disk->exists($evidence->path), 404, 'This evidence file is unavailable in storage.');

        return $disk->response($evidence->path, $evidence->original_name, [
            'Content-Type' => $evidence->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($evidence->original_name).'"',
        ]);
    }

    private function approvalQuery(Request $request, ScopeService $scope): Builder
    {
        $statuses = match ($request->user()->role) {
            'SENIOR PROGRAM LEAD' => [WorkflowStatus::PendingSeniorProgrammeLead],
            'M&E HEAD' => [WorkflowStatus::PendingMneHead],
            default => WorkflowStatus::pending(),
        };

        return $scope->entries($request->user())
            ->targetBacked()
            ->whereIn('status', $statuses)
            ->when($request->integer('programme'), fn (Builder $query, int $programmeId): Builder => $query->whereHas('assignment.indicator', fn (Builder $indicatorQuery): Builder => $indicatorQuery->where('project_id', $programmeId)))
            ->when($request->integer('project'), fn (Builder $query, int $projectId): Builder => $query->whereHas('assignment', fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('centre_id', $projectId)))
            ->when($request->integer('indicator'), fn (Builder $query, int $indicatorId): Builder => $query->whereHas('assignment', fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('indicator_definition_id', $indicatorId)))
            ->with(['assignment.indicator.project', 'assignment.centre', 'assignment.targets', 'currentRevision.user', 'currentRevision.evidence.user', 'approvedRevision', 'revisions.user', 'revisions.actions.user'])
            ->orderBy('period')
            ->orderBy('id');
    }

    private function approvedQuery(Request $request, ScopeService $scope): Builder
    {
        return $scope->entries($request->user())
            ->targetBacked()
            ->whereNotNull('approved_revision_id')
            ->when($request->integer('programme'), fn (Builder $query, int $programmeId): Builder => $query->whereHas('assignment.indicator', fn (Builder $indicatorQuery): Builder => $indicatorQuery->where('project_id', $programmeId)))
            ->when($request->integer('project'), fn (Builder $query, int $projectId): Builder => $query->whereHas('assignment', fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('centre_id', $projectId)))
            ->when($request->integer('indicator'), fn (Builder $query, int $indicatorId): Builder => $query->whereHas('assignment', fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('indicator_definition_id', $indicatorId)))
            ->with(['assignment.indicator.project', 'assignment.centre', 'assignment.targets', 'approvedRevision.user', 'approvedRevision.evidence.user', 'approvedRevision.actions.user', 'currentRevision'])
            ->latest('updated_at');
    }
}
