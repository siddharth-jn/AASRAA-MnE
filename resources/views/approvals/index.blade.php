<x-layouts.app heading="Approvals">
    <div class="mb-5 flex gap-2 border-b border-slate-200">
        <a href="{{ route('approvals.index', array_merge(request()->except(['page', 'tab']), ['tab' => 'pending'])) }}" class="border-b-2 px-4 py-3 text-sm font-semibold {{ $tab === 'pending' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500' }}">Pending</a>
        <a href="{{ route('approvals.index', array_merge(request()->except(['page', 'tab']), ['tab' => 'approved'])) }}" class="border-b-2 px-4 py-3 text-sm font-semibold {{ $tab === 'approved' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500' }}">Approved history</a>
    </div>

    <form class="no-print card mb-5 grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <div>
            <label for="programme">Programme</label>
            <select id="programme" name="programme" onchange="this.form.elements.project.value = ''; this.form.elements.indicator.value = ''; this.form.submit()" class="mt-1 w-full">
                <option value="">All programmes</option>
                @foreach($programmes as $programme)
                    <option value="{{ $programme->id }}" @selected(request()->integer('programme') === $programme->id)>{{ $programme->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="project">Project</label>
            <select id="project" name="project" onchange="this.form.elements.indicator.value = ''; this.form.submit()" class="mt-1 w-full">
                <option value="">{{ request()->filled('programme') ? 'All projects in programme' : 'All projects' }}</option>
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
                    <option value="{{ $indicator->id }}" @selected(request()->integer('indicator') === $indicator->id)>{{ $indicator->project->name }} · {{ $indicator->code }} · {{ $indicator->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2"><button class="btn-primary">Apply filters</button><a href="{{ route('approvals.index') }}" class="btn-secondary">Clear</a></div>
    </form>

    @if($tab === 'pending')
    @can('bulk-approve-excel')
        <section class="card mb-5 p-5">
            <div class="grid gap-5 lg:grid-cols-[1fr_auto] lg:items-end">
                <div>
                    <h2 class="font-semibold text-ink">Approval with Excel</h2>
                    <p class="mt-1 max-w-3xl text-sm leading-6 text-slate-500">Download the pending approvals at your stage shown by these filters. Senior Programme Leads receive first-stage reviews, M&E Heads receive final reviews, and administrators receive both stages. In Excel, choose <span class="font-semibold text-slate-700">Yes</span> in the Approve column, save the file, and upload it here. Each selected row advances through the stage shown in the spreadsheet.</p>
                </div>
                <a href="{{ route('approvals.excel.download', request()->query()) }}" class="btn-secondary">Download approvals</a>
            </div>
            <form method="POST" action="{{ route('approvals.excel.upload') }}" enctype="multipart/form-data" class="mt-4 flex flex-col gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-end">
                @csrf
                <div class="w-full max-w-xl">
                    <label for="approval_file">Completed approvals file</label>
                    <input id="approval_file" name="file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="mt-1 w-full" required>
                </div>
                <button class="btn-primary" onclick="return confirm('Approve every spreadsheet row marked Yes at the stage shown in the file?')">Upload and approve</button>
            </form>
        </section>
    @endcan
    @endif

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div><p class="font-semibold text-ink">{{ number_format($matchingCount) }} {{ $tab === 'pending' ? 'pending at your approval stage' : 'final-approved results' }}</p><p class="text-sm text-slate-500">{{ $tab === 'pending' ? 'Submitted values are read-only for reviewers. Return an entry when the submitter must correct it.' : 'This history shows the revision currently used in official reporting, including while a newer amendment is pending.' }}</p></div>
        @if($tab === 'pending')
        @can('bulk-approve')
            <button type="submit" form="bulk-approval-form" name="selection" value="all" class="btn-primary" onclick="return confirm('Approve every submission matching the current filters at your current review stage, including records on other pages?')">Approve all {{ number_format($matchingCount) }} matching</button>
        @endcan
        @endif
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
                        @if($tab === 'pending')@can('bulk-approve')<th><input type="checkbox" aria-label="Select this page" onchange="document.querySelectorAll('[name=\'entry_ids[]\']').forEach(checkbox => checkbox.checked = this.checked)"></th>@endcan @endif
                        <th class="sticky-col">Indicator / project</th><th>Period</th><th>Value / target</th><th>Variance</th><th>{{ $tab === 'pending' ? 'Submitted by' : 'Approved revision' }}</th><th>{{ $tab === 'pending' ? 'Stage' : 'Approved by' }}</th><th>Evidence</th><th>{{ $tab === 'pending' ? 'Decision' : 'Official status' }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($entries as $entry)
                        @php($revision = $tab === 'pending' ? $entry->currentRevision : $entry->approvedRevision)
                        @php($isAmendment = $tab === 'pending' && $revision?->supersedes_revision_id !== null)
                        @php($target = $entry->assignment->targets->first(fn ($candidate) => $candidate->active && $candidate->period->lte($entry->period) && $candidate->period_end?->gte($entry->period)))
                        <tr>
                            @if($tab === 'pending')@can('bulk-approve')<td><input type="checkbox" name="entry_ids[]" value="{{ $entry->id }}" aria-label="Select {{ $entry->assignment->indicator->name }}"></td>@endcan @endif
                            <td class="sticky-col min-w-64"><p class="font-semibold text-slate-800">{{ $entry->assignment->indicator->name }}</p><p class="text-xs text-slate-500">{{ $entry->assignment->indicator->project->name }} · {{ $entry->assignment->centre?->name ?? 'Programme level' }}</p>@if($isAmendment)<span class="mt-2 inline-flex rounded-full bg-purple-100 px-2 py-1 text-xs font-semibold text-purple-700">Amendment</span><p class="mt-1 text-xs text-purple-700">Previous approved: {{ $entry->approvedRevision?->displayValue() }} · {{ $revision->amendment_reason }}</p>@endif</td>
                            <td>{{ $entry->periodLabel() }}</td>
                            <td><p class="font-semibold">{{ $revision->displayValue() }} {{ $entry->assignment->indicator->unit }}</p><p class="text-xs text-slate-500">Target: {{ $target?->value !== null ? number_format($target->value, $entry->assignment->indicator->precision) : ($target?->status_value ?? 'Not set') }}</p></td>
                            <td class="min-w-56"><p class="{{ $revision->variance_flagged ? 'font-semibold text-amber-700' : '' }}">{{ $revision->variance_percentage === null ? 'N/A' : (($revision->variance_percentage > 0 ? '+' : '').number_format($revision->variance_percentage, 1).'%') }}</p><p class="mt-1 text-xs text-slate-500">{{ $revision->variance_explanation ?: ($revision->variance_flagged ? 'Explanation missing' : 'Within threshold') }}</p></td>
                            <td><p>{{ $revision->user->name }}</p><p class="text-xs text-slate-500">{{ ($tab === 'pending' ? $revision->submitted_at : $revision->approved_at)?->format('d M Y, H:i') }}</p></td>
                            <td class="min-w-40">@if($tab === 'pending')<span class="stage-label">{{ $entry->status === \App\Support\WorkflowStatus::PendingSeniorProgrammeLead ? 'Senior PL review' : 'M&E Head review' }}</span>@else @php($finalAction = $revision->actions->where('action', \App\Support\WorkflowStatus::FinalApproved)->last()) <p>{{ $finalAction?->user?->name ?? 'System' }}</p><p class="text-xs text-slate-500">M&E final approval</p>@endif</td>
                            <td>
                                @forelse($revision->evidence as $file)
                                    <a class="block max-w-40 truncate text-xs font-semibold text-brand-700" href="{{ route('evidence.download', $file) }}">{{ $file->original_name }}</a>
                                @empty
                                    <span class="text-xs {{ $entry->assignment->indicator->evidence_required ? 'text-amber-700' : 'text-slate-400' }}">{{ $entry->assignment->indicator->evidence_required ? 'Required evidence missing' : 'None' }}</span>
                                @endforelse
                            </td>
                            <td class="min-w-72">
                                @if($tab === 'pending')
                                <input form="review-{{ $entry->id }}" name="comment" class="mb-2 w-full" placeholder="Comment required to return/reject">
                                <div class="flex flex-wrap gap-2">
                                    <button form="review-{{ $entry->id }}" name="action" value="APPROVED" class="btn-primary">Approve</button>
                                    <button form="review-{{ $entry->id }}" name="action" value="RETURNED" class="btn-secondary">Return</button>
                                    <button form="review-{{ $entry->id }}" name="action" value="REJECTED" class="btn-secondary text-red-700">Reject</button>
                                </div>
                                @else
                                    <span class="badge bg-emerald-100 text-emerald-700">Final approved</span>
                                    @if($entry->current_revision_id !== $entry->approved_revision_id)<p class="mt-2 text-xs text-amber-700">A newer {{ \App\Support\WorkflowStatus::label($entry->status) }} amendment exists; this value remains official.</p>@endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="py-12 text-center text-slate-500">{{ $tab === 'pending' ? 'No submissions are pending at your approval stage.' : 'No final-approved results match these filters.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($tab === 'pending')
        @can('bulk-approve')
            @if($entries->isNotEmpty())
                <div class="mt-4 flex justify-end"><button name="selection" value="selected" class="btn-primary" onclick="return confirm('Approve every selected submission at your current review stage?')">Approve selected</button></div>
            @endif
        @endcan
        @endif
    </form>

    @if($tab === 'pending')
    @foreach($entries as $entry)
        <form id="review-{{ $entry->id }}" method="POST" action="{{ route('approvals.review', $entry) }}">@csrf</form>
    @endforeach
    @endif

    <div class="mt-6">{{ $entries->links() }}</div>
</x-layouts.app>
