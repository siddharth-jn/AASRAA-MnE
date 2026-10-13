<?php

namespace App\Http\Controllers;

use App\Models\EvidenceFile;
use App\Models\IndicatorAssignment;
use App\Models\IndicatorEntry;
use App\Services\EntryService;
use App\Services\ScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EntryController extends Controller
{
    public function index(Request $request, ScopeService $scope): View
    {
        return view('entries.index', ['assignments' => $scope->assignments($request->user())->with(['indicator.project', 'centre'])->whereHas('indicator', fn ($query) => $query->where('active', true))->paginate(30), 'period' => $request->input('period', '2026-09')]);
    }

    public function edit(Request $request, IndicatorAssignment $assignment, ScopeService $scope): View
    {
        abort_unless($scope->assignments($request->user())->whereKey($assignment)->exists() && Gate::allows('submit'), 403);
        $period = $request->input('period', '2026-09').'-01';
        $assignment->load(['indicator.project', 'centre']);
        $entry = IndicatorEntry::with('currentRevision.evidence')->where('indicator_assignment_id', $assignment->id)->whereDate('period', $period)->first();

        return view('entries.edit', compact('assignment', 'entry', 'period'));
    }

    public function store(Request $request, IndicatorAssignment $assignment, ScopeService $scope, EntryService $service): RedirectResponse
    {
        abort_unless($scope->assignments($request->user())->whereKey($assignment)->exists() && Gate::allows('submit'), 403);
        $definition = $assignment->load('indicator')->indicator;
        $rules = ['period' => ['required', 'date_format:Y-m-d'], 'value_state' => ['required', Rule::in(['reported', 'not_applicable'])], 'notes' => ['nullable', 'string', 'max:2000']];
        if ($definition->data_type === 'ratio') {
            $rules += ['numerator' => ['required_if:value_state,reported', 'nullable', 'numeric', 'min:0'], 'denominator' => ['required_if:value_state,reported', 'nullable', 'numeric', 'gt:0']];
        } elseif (in_array($definition->data_type, ['integer', 'decimal', 'percentage'], true)) {
            $rules['numeric_value'] = ['required_if:value_state,reported', 'nullable', 'numeric', $definition->min_value !== null ? 'min:'.$definition->min_value : 'min:0', $definition->max_value !== null ? 'max:'.$definition->max_value : 'max:999999999'];
        } else {
            $rules['text_value'] = ['required_if:value_state,reported', 'nullable', 'string', 'max:'.$definition->max_length];
        }
        $rules['evidence.*'] = ['file', 'mimes:'.implode(',', $definition->allowed_extensions ?: ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'xlsx', 'csv']), 'max:'.$definition->max_file_kb];
        $data = $request->validate($rules);
        if ($definition->data_type === 'ratio' && $data['value_state'] === 'reported') {
            $data['numeric_value'] = $data['numerator'] / $data['denominator'] * 100;
        }
        $service->save($request->user(), $assignment, $data['period'], $data, $request->file('evidence', []), $request->input('action') === 'submit');

        return redirect()->route('entries.index', ['period' => substr($data['period'], 0, 7)])->with('success', $request->input('action') === 'submit' ? 'Entry submitted for approval.' : 'Draft saved.');
    }

    public function approvals(Request $request, ScopeService $scope): View
    {
        Gate::authorize('review');

        return view('approvals.index', ['entries' => $scope->entries($request->user())->where('status', 'SUBMITTED')->with(['assignment.indicator.project', 'assignment.centre', 'currentRevision.user', 'currentRevision.evidence'])->paginate(20)]);
    }

    public function review(Request $request, IndicatorEntry $entry, EntryService $service): RedirectResponse
    {
        Gate::authorize('review');
        Gate::authorize('view-entry', $entry);
        $data = $request->validate(['action' => ['required', Rule::in(['APPROVED', 'RETURNED', 'REJECTED'])], 'comment' => ['nullable', 'string', 'max:2000']]);
        $service->review($request->user(), $entry, $data['action'], $data['comment'] ?? null);

        return back()->with('success', 'Review decision recorded.');
    }

    public function evidence(EvidenceFile $evidence): StreamedResponse
    {
        Gate::authorize('view-evidence', $evidence);

        return Storage::disk('local')->download($evidence->path, $evidence->original_name, ['Content-Type' => $evidence->mime_type]);
    }
}
