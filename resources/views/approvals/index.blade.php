<x-layouts.app heading="Approvals">
    <form class="no-print card mb-5 grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
        <div>
            <label for="programme">Programme</label>
            <select id="programme" name="programme" class="mt-1 w-full">
                <option value="">All programmes</option>
                @foreach($programmes as $programme)
                    <option value="{{ $programme->id }}" @selected(request()->integer('programme') === $programme->id)>{{ $programme->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="project">Project</label>
            <select id="project" name="project" class="mt-1 w-full">
                <option value="">All projects</option>
                @foreach($projects as $project)
                    <option value="{{ $project->id }}" @selected(request()->integer('project') === $project->id)>{{ $project->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="per_page">Rows per page</label>
            <select id="per_page" name="per_page" class="mt-1 w-full">
                @foreach([25, 50, 100] as $pageSize)
                    <option value="{{ $pageSize }}" @selected((request()->integer('per_page') ?: 25) === $pageSize)>{{ $pageSize }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="indicator">Indicator</label>
            <select id="indicator" name="indicator" class="mt-1 w-full">
                <option value="">All indicators</option>
                @foreach($indicators as $indicator)
                    <option value="{{ $indicator->id }}" @selected(request()->integer('indicator') === $indicator->id)>{{ $indicator->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2"><button class="btn-primary">Apply filters</button><a href="{{ route('approvals.index') }}" class="btn-secondary">Clear</a></div>
    </form>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div><p class="font-semibold text-ink">{{ number_format($matchingCount) }} pending at your approval stage</p><p class="text-sm text-slate-500">Submitted values are read-only for reviewers. Return an entry when the submitter must correct it.</p></div>
        @can('bulk-approve')
            <button type="submit" form="bulk-approval-form" name="selection" value="all" class="btn-primary" onclick="return confirm('Caution: this will final approve every submission matching the current filters, including records on other pages. Continue?')">Approve all {{ number_format($matchingCount) }} matching</button>
        @endcan
    </div>

    <form id="bulk-approval-form" method="POST" action="{{ route('approvals.bulk', request()->query()) }}">
        @csrf
        <input type="hidden" name="confirmation" value="1">
        @foreach(request()->except('page') as $filter => $value)
            <input type="hidden" name="{{ $filter }}" value="{{ $value }}">
        @endforeach

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        @can('bulk-approve')<th><input type="checkbox" aria-label="Select this page" onchange="document.querySelectorAll('[name=\'entry_ids[]\']').forEach(checkbox => checkbox.checked = this.checked)"></th>@endcan
                        <th class="sticky-col">Indicator / project</th><th>Period</th><th>Value / target</th><th>Variance</th><th>Submitted by</th><th>Stage</th><th>Evidence</th><th>Decision</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($entries as $entry)
                        @php($revision = $entry->currentRevision)
                        @php($target = $entry->assignment->targets->first(fn ($candidate) => $candidate->active && $candidate->period->lte($entry->period) && $candidate->period_end?->gte($entry->period)))
                        <tr>
                            @can('bulk-approve')<td><input type="checkbox" name="entry_ids[]" value="{{ $entry->id }}" aria-label="Select {{ $entry->assignment->indicator->name }}"></td>@endcan
                            <td class="sticky-col min-w-64"><p class="font-semibold text-slate-800">{{ $entry->assignment->indicator->name }}</p><p class="text-xs text-slate-500">{{ $entry->assignment->indicator->project->name }} · {{ $entry->assignment->centre->name }}</p></td>
                            <td>{{ $entry->periodLabel() }}</td>
                            <td><p class="font-semibold">{{ $revision->displayValue() }} {{ $entry->assignment->indicator->unit }}</p><p class="text-xs text-slate-500">Target: {{ $target?->value !== null ? number_format($target->value, $entry->assignment->indicator->precision) : ($target?->status_value ?? 'Not set') }}</p></td>
                            <td class="min-w-56"><p class="{{ $revision->variance_flagged ? 'font-semibold text-amber-700' : '' }}">{{ $revision->variance_percentage === null ? 'N/A' : (($revision->variance_percentage > 0 ? '+' : '').number_format($revision->variance_percentage, 1).'%') }}</p><p class="mt-1 text-xs text-slate-500">{{ $revision->variance_explanation ?: ($revision->variance_flagged ? 'Explanation missing' : 'Within threshold') }}</p></td>
                            <td><p>{{ $revision->user->name }}</p><p class="text-xs text-slate-500">{{ $revision->submitted_at?->format('d M Y, H:i') }}</p></td>
                            <td class="min-w-40"><span class="stage-label">{{ $entry->status === \App\Support\WorkflowStatus::PendingSeniorProgrammeLead ? 'Senior PL review' : 'M&E Head review' }}</span></td>
                            <td>
                                @forelse($revision->evidence as $file)
                                    <a class="block max-w-40 truncate text-xs font-semibold text-brand-700" href="{{ route('evidence.download', $file) }}">{{ $file->original_name }}</a>
                                @empty
                                    <span class="text-xs {{ $entry->assignment->indicator->evidence_required ? 'text-amber-700' : 'text-slate-400' }}">{{ $entry->assignment->indicator->evidence_required ? 'Required evidence missing' : 'None' }}</span>
                                @endforelse
                            </td>
                            <td class="min-w-72">
                                <input form="review-{{ $entry->id }}" name="comment" class="mb-2 w-full" placeholder="Comment required to return/reject">
                                <div class="flex flex-wrap gap-2">
                                    <button form="review-{{ $entry->id }}" name="action" value="APPROVED" class="btn-primary">Approve</button>
                                    <button form="review-{{ $entry->id }}" name="action" value="RETURNED" class="btn-secondary">Return</button>
                                    <button form="review-{{ $entry->id }}" name="action" value="REJECTED" class="btn-secondary text-red-700">Reject</button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-12 text-center text-slate-500">No submissions are pending at your approval stage.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @can('bulk-approve')
            @if($entries->isNotEmpty())
                <div class="mt-4 flex justify-end"><button name="selection" value="selected" class="btn-primary" onclick="return confirm('Final approve every selected submission on this page?')">Approve selected</button></div>
            @endif
        @endcan
    </form>

    @foreach($entries as $entry)
        <form id="review-{{ $entry->id }}" method="POST" action="{{ route('approvals.review', $entry) }}">@csrf</form>
    @endforeach

    <div class="mt-6">{{ $entries->links() }}</div>
</x-layouts.app>
