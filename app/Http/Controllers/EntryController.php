<?php

namespace App\Http\Controllers;

use App\Models\EvidenceFile;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorEntry;
use App\Services\CompletenessService;
use App\Services\EntryService;
use App\Services\ScopeService;
use App\Services\TargetService;
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
    public function index(Request $request, ScopeService $scope, CompletenessService $completeness): View
    {
        $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $projectId = $request->integer('project') ?: null;
        $centreId = $request->integer('centre') ?: null;
        $period = $request->input('period', '2026-09');
        $periodMonth = CarbonImmutable::parse($period.'-01');

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
            ->with(['indicator.project', 'centre'])
            ->whereHas('indicator', function (Builder $query) use ($periodMonth, $projectId): void {
                $query->where('active', true)
                    ->whereDate('effective_date', '<=', $periodMonth->endOfMonth());
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
            'period' => $period,
            'projects' => $projects,
            'centres' => $scope->centres($request->user())
                ->when($projectId, fn (Builder $query): Builder => $query->whereHas('projects', fn (Builder $projectQuery): Builder => $projectQuery->whereKey($projectId)))
                ->orderBy('name')
                ->get(),
            'selectedProjectId' => $projectId,
            'selectedCentreId' => $centreId,
            'selectedProject' => $projects->firstWhere('id', $projectId),
            'projectLocked' => $projectId !== null && $request->boolean('lock_project'),
            'completeness' => $completeness->summary($request->user(), $periodMonth->startOfMonth(), $periodMonth->endOfMonth(), $projectId, $centreId),
        ]);
    }

    public function edit(Request $request, IndicatorAssignment $assignment, ScopeService $scope, TargetService $targets): View
    {
        abort_unless($scope->assignments($request->user())->whereKey($assignment)->exists() && Gate::allows('submit'), 403);
        $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $periodMonth = CarbonImmutable::parse($request->input('period', '2026-09').'-01')->startOfMonth();
        $assignment->load(['indicator.project', 'centre']);
        abort_unless(
            $assignment->indicator->isEffectiveForPeriod($periodMonth),
            422,
            'Reporting for this indicator starts in '.$assignment->indicator->effective_date->format('F Y').'.',
        );
        $period = $periodMonth->toDateString();
        $entry = IndicatorEntry::with(['currentRevision.evidence', 'revisions.user', 'revisions.evidence', 'revisions.actions.user'])->where('indicator_assignment_id', $assignment->id)->whereDate('period', $period)->first();
        $target = $targets->findForPeriod($assignment->id, CarbonImmutable::parse($period));

        return view('entries.edit', compact('assignment', 'entry', 'period', 'target'));
    }

    public function store(Request $request, IndicatorAssignment $assignment, ScopeService $scope, EntryService $service): RedirectResponse
    {
        abort_unless($scope->assignments($request->user())->whereKey($assignment)->exists() && Gate::allows('submit'), 403);
        $definition = $assignment->load('indicator')->indicator;
        $requiredValue = $definition->value_required ? 'required_if:value_state,reported' : 'nullable';
        $rules = ['period' => ['required', 'date_format:Y-m-d'], 'value_state' => ['required', Rule::in(['reported', 'not_applicable'])], 'notes' => ['nullable', 'string', 'max:2000']];
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
        $rules['evidence.*'] = ['file', 'mimes:'.implode(',', $definition->allowed_extensions ?: ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'xlsx', 'csv']), 'max:'.$definition->max_file_kb];
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

        return view('approvals.index', ['entries' => $scope->entries($request->user())->where('status', 'SUBMITTED')->with(['assignment.indicator.project', 'assignment.centre', 'assignment.targets', 'currentRevision.user', 'currentRevision.evidence.user', 'revisions.user', 'revisions.actions.user'])->paginate(20)]);
    }

    public function review(Request $request, IndicatorEntry $entry, EntryService $service): RedirectResponse
    {
        Gate::authorize('review');
        Gate::authorize('view-entry', $entry);
        $entry->loadMissing('assignment.indicator');
        $rules = ['action' => ['required', Rule::in(['APPROVED', 'RETURNED', 'REJECTED'])], 'comment' => ['nullable', 'string', 'max:2000']];
        if ($request->input('action') === 'APPROVED' && $entry->assignment->indicator->evidence_review_required) {
            $rules['evidence_reviewed'] = ['accepted'];
        }
        $data = $request->validate($rules);
        $service->review($request->user(), $entry, $data['action'], $data['comment'] ?? null);

        return back()->with('success', 'Review decision recorded.');
    }

    public function evidence(EvidenceFile $evidence): StreamedResponse
    {
        Gate::authorize('view-evidence', $evidence);

        return Storage::disk('local')->download($evidence->path, $evidence->original_name, ['Content-Type' => $evidence->mime_type]);
    }

    public function previewEvidence(EvidenceFile $evidence): StreamedResponse
    {
        Gate::authorize('view-evidence', $evidence);
        abort_unless(str_starts_with($evidence->mime_type, 'image/') || $evidence->mime_type === 'application/pdf', 415, 'Preview is not available for this file type.');

        return Storage::disk('local')->response($evidence->path, $evidence->original_name, [
            'Content-Type' => $evidence->mime_type,
            'Content-Disposition' => 'inline; filename="'.addslashes($evidence->original_name).'"',
        ]);
    }
}
