<?php

namespace App\Http\Controllers;

use App\Models\EvidenceFile;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Services\CompletenessService;
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
        ReportingPeriodService $reportingPeriods,
        IndicatorReportingPeriodService $indicatorPeriods,
    ): View {
        $request->validate([
            'period_type' => ['nullable', Rule::in(array_keys(config('impact.frequencies')))],
            'anchor' => ['nullable', 'date_format:Y-m'],
            'period' => ['nullable', 'date_format:Y-m'],
        ]);
        $request->merge([
            'period_type' => $request->input('period_type', 'monthly'),
            'anchor' => $request->input('anchor', $request->input('period', CarbonImmutable::now()->format('Y-m'))),
        ]);
        $reportingPeriod = $reportingPeriods->fromRequest($request);
        $frequency = $reportingPeriod['type'];
        $periodStart = $reportingPeriod['start'];
        $periodEnd = $reportingPeriod['end'];
        $projectId = $request->integer('project') ?: null;
        $centreId = $request->integer('centre') ?: null;

        if ($projectId) {
            abort_unless($scope->projects($request->user())->whereKey($projectId)->exists(), 403);
        }

        if ($centreId) {
            $centreQuery = $scope->centres($request->user())->whereKey($centreId);
            if ($projectId) {
                $centreQuery->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereKey($projectId));
            }
            abort_unless($centreQuery->exists(), 422, 'The selected project is not assigned to the selected programme.');
        }

        $assignments = $scope->assignments($request->user())
            ->with([
                'indicator.project',
                'centre',
                'entries' => fn ($query) => $query->whereBetween('period', [$periodStart, $periodEnd])->with('currentRevision'),
            ])
            ->whereHas('indicator', function (Builder $query) use ($periodEnd, $frequency, $projectId): void {
                $query->where('active', true)
                    ->where('frequency', $frequency)
                    ->whereDate('effective_date', '<=', $periodEnd);
                if ($projectId) {
                    $query->where('project_id', $projectId);
                }
            })
            ->when($centreId, fn (Builder $query): Builder => $query->where('centre_id', $centreId))
            ->paginate(30)
            ->withQueryString();

        $projects = $scope->projects($request->user())->orderBy('name')->get();

        return view('entries.index', [
            'assignments' => $assignments,
            'reportingPeriod' => $reportingPeriod,
            'periodLabel' => $indicatorPeriods->label($periodStart, $frequency),
            'projects' => $projects,
            'centres' => $scope->centres($request->user())
                ->when($projectId, fn (Builder $query): Builder => $query->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereKey($projectId)))
                ->orderBy('name')
                ->get(),
            'selectedProjectId' => $projectId,
            'selectedCentreId' => $centreId,
            'selectedProject' => $projects->firstWhere('id', $projectId),
            'projectLocked' => $projectId !== null && $request->boolean('lock_project'),
            'completeness' => $completeness->summary($request->user(), $periodStart, $periodEnd, $projectId, $centreId, $frequency),
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
            'period_type' => ['nullable', Rule::in(array_keys(config('impact.frequencies')))],
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
        $periodLabel = $periods->label($period, $assignment->indicator->frequency);

        return view('entries.edit', compact('assignment', 'entry', 'period', 'periodLabel', 'target'));
    }

    public function store(Request $request, IndicatorAssignment $assignment, ScopeService $scope, EntryService $service): RedirectResponse
    {
        abort_unless($scope->assignments($request->user())->whereKey($assignment)->exists() && Gate::allows('submit'), 403);
        $definition = $assignment->load('indicator')->indicator;
        $requiredValue = $definition->value_required ? 'required_if:value_state,reported' : 'nullable';
        $rules = [
            'period' => ['required', 'date_format:Y-m-d'],
            'value_state' => ['required', Rule::in(['reported', 'not_applicable'])],
            'notes' => ['nullable', 'string', 'max:2000'],
            'variance_explanation' => ['nullable', 'string', 'max:3000'],
        ];
        if (in_array($definition->data_type, ['ratio', 'calculated'], true)) {
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
        if (in_array($definition->data_type, ['ratio', 'calculated'], true) && $data['value_state'] === 'reported' && isset($data['numerator'], $data['denominator'])) {
            $data['numeric_value'] = $data['numerator'] / $data['denominator'] * 100;
            if ($definition->min_value !== null && $data['numeric_value'] < $definition->min_value) {
                throw ValidationException::withMessages(['numerator' => "The calculated value must be at least {$definition->min_value}."]);
            }
            if ($definition->max_value !== null && $data['numeric_value'] > $definition->max_value) {
                throw ValidationException::withMessages(['numerator' => "The calculated value may not exceed {$definition->max_value}."]);
            }
        }
        $service->save($request->user(), $assignment, $data['period'], $data, $request->file('evidence', []), $request->input('action') === 'submit');

        $redirectParameters = ['period' => substr($data['period'], 0, 7)];
        if ($definition->frequency !== 'monthly') {
            $redirectParameters['period_type'] = $definition->frequency;
        }
        if ($request->boolean('lock_project')) {
            $redirectParameters += [
                'project' => $definition->project_id,
                'centre' => $assignment->centre_id,
                'lock_project' => 1,
            ];
        }

        return redirect()->route('entries.index', $redirectParameters)->with('success', $request->input('action') === 'submit' ? 'Entry submitted for approval.' : 'Draft saved.');
    }

    public function approvals(Request $request, ScopeService $scope): View
    {
        Gate::authorize('review');

        $request->validate([
            'programme' => ['nullable', 'integer'],
            'project' => ['nullable', 'integer'],
            'indicator' => ['nullable', 'integer'],
            'per_page' => ['nullable', Rule::in([25, 50, 100])],
        ]);
        $query = $this->approvalQuery($request, $scope);

        return view('approvals.index', [
            'entries' => $query->paginate($request->integer('per_page') ?: 25)->withQueryString(),
            'matchingCount' => (clone $query)->reorder()->count(),
            'programmes' => $scope->projects($request->user())->orderBy('name')->get(),
            'projects' => $scope->centres($request->user())->orderBy('name')->get(),
            'indicators' => IndicatorDefinition::query()
                ->whereIn('project_id', $scope->projects($request->user())->select('projects.id'))
                ->when($request->integer('programme'), fn (Builder $query, int $projectId): Builder => $query->where('project_id', $projectId))
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

        $query = $this->approvalQuery($request, $scope)->where('status', WorkflowStatus::PendingMneHead);
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
                    $service->review($request->user(), $entry, 'APPROVED', 'Bulk approved by the M&E Head after confirmation.');
                    $approved++;
                } catch (ValidationException) {
                    $skipped++;
                }
            }
        });

        return back()->with('success', "{$approved} submission(s) final approved. {$skipped} skipped because they changed or did not meet approval requirements.");
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
            ->whereIn('status', $statuses)
            ->when($request->integer('programme'), fn (Builder $query, int $programmeId): Builder => $query->whereHas('assignment.indicator', fn (Builder $indicatorQuery): Builder => $indicatorQuery->where('project_id', $programmeId)))
            ->when($request->integer('project'), fn (Builder $query, int $projectId): Builder => $query->whereHas('assignment', fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('centre_id', $projectId)))
            ->when($request->integer('indicator'), fn (Builder $query, int $indicatorId): Builder => $query->whereHas('assignment', fn (Builder $assignmentQuery): Builder => $assignmentQuery->where('indicator_definition_id', $indicatorId)))
            ->with(['assignment.indicator.project', 'assignment.centre', 'assignment.targets', 'currentRevision.user', 'currentRevision.evidence.user', 'revisions.user', 'revisions.actions.user'])
            ->orderBy('period')
            ->orderBy('id');
    }
}
