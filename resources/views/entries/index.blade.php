<x-layouts.app heading="Data entry">
    <form class="no-print card mb-5 grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-7">
        @if($projectLocked)<input type="hidden" name="project" value="{{ $selectedProjectId }}"><input type="hidden" name="lock_project" value="1">@endif
        <div>
            <label for="anchor">Month</label>
            <input id="anchor" name="anchor" type="month" value="{{ $reportingPeriod['start']->format('Y-m') }}" onchange="this.form.submit()" class="mt-1 w-full">
        </div>
        <div>
            <label for="donor">Donor</label>
            <select id="donor" name="donor" onchange="this.form.elements.centre.value=''; this.form.submit()" class="mt-1 w-full">
                <option value="">All donors</option>
                @foreach($availableDonors as $donor)
                    <option value="{{ $donor }}" @selected($selectedDonor === $donor)>{{ $donor }}</option>
                @endforeach
            </select>
        </div>
        @if($projectLocked)
            <div><p class="text-sm font-medium text-slate-700">Programme</p><p class="mt-3 font-semibold text-ink">{{ $selectedProject->name }}</p></div>
        @else
            <div>
                <label for="project">Programme</label>
                <select id="project" name="project" onchange="this.form.elements.centre.value = ''; this.form.submit()" class="mt-1 w-full">
                    <option value="">All programmes</option>
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}" @selected($selectedProjectId === $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <label for="centre">Project</label>
            <select id="centre" name="centre" onchange="this.form.submit()" class="mt-1 w-full">
                <option value="">{{ $selectedProjectId ? 'All programme projects' : 'All projects' }}</option>
                @foreach($centres as $centre)
                    <option value="{{ $centre->id }}" @selected($selectedCentreId === $centre->id)>{{ $centre->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="xl:col-span-2">
            <label for="search">Indicator</label>
            <input id="search" name="search" type="search" value="{{ $indicatorSearch }}" placeholder="Type an indicator name or code" x-on:input.debounce.500ms="$el.form.submit()" x-on:search="$el.form.submit()" class="mt-1 w-full">
        </div>
        <div>
            <label for="entry_status">Records</label>
            <select id="entry_status" name="entry_status" onchange="this.form.submit()" class="mt-1 w-full">
                <option value="">All records</option>
                <option value="unfilled" @selected($selectedEntryStatus === 'unfilled')>Unfilled only</option>
                <option value="entered" @selected($selectedEntryStatus === 'entered')>Entered only</option>
            </select>
        </div>
        @if($selectedDonor || $indicatorSearch || $selectedEntryStatus || $selectedProjectId || $selectedCentreId)
            <div class="flex items-end xl:col-span-7">
                <a class="text-sm font-semibold text-brand-700" href="{{ route('entries.index', ['anchor' => $reportingPeriod['start']->format('Y-m')]) }}">Clear filters</a>
            </div>
        @endif
    </form>

    <p class="mb-5 text-sm text-slate-500">
        Showing indicators with an active target for <strong>{{ $periodLabel }}</strong>. A blank target means N/A and does not create a data-entry row.
    </p>

    <div class="mb-5 grid gap-4 sm:grid-cols-2">
        <a class="card p-4 transition hover:border-brand-300 hover:bg-brand-50/40" href="{{ route('entries.index', array_merge(request()->except(['page', 'entry_status']), ['entry_status' => 'entered'])) }}">
            <p class="text-sm text-slate-500">Records entered · {{ $periodLabel }}</p>
            <p class="mt-1 text-2xl font-bold text-ink">{{ $completeness['reported'] }} <span class="text-sm font-medium text-slate-400">of {{ $completeness['expected'] }}</span></p>
        </a>
        <a class="card p-4 transition hover:border-amber-300 hover:bg-amber-50" href="{{ route('entries.index', array_merge(request()->except(['page', 'entry_status']), ['entry_status' => 'unfilled'])) }}">
            <p class="text-sm text-slate-500">Records still missing · click to filter</p>
            <p class="mt-1 text-2xl font-bold text-amber-700">{{ $completeness['missing'] }}</p>
        </a>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th class="sticky-col">Programme / Indicator</th><th>Project</th><th>Input</th><th>Evidence</th><th>Status</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($assignments as $assignment)
                    @php($entry = $assignment->entries->first())
                    <tr>
                        <td class="sticky-col"><p class="font-semibold text-slate-800">{{ $assignment->indicator->name }}</p><p class="text-xs text-slate-500">{{ $assignment->indicator->project->name }} · {{ $assignment->indicator->category }}</p></td>
                        <td>{{ $assignment->centre?->name ?? 'Programme level' }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $assignment->indicator->data_type)) }}</td>
                        <td>{{ $assignment->indicator->evidence_required ? 'Required' : 'Optional' }}</td>
                        <td><span class="badge {{ $entry?->status === \App\Support\WorkflowStatus::FinalApproved ? 'bg-emerald-100 text-emerald-700' : ($entry && in_array($entry->status, \App\Support\WorkflowStatus::pending(), true) ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-700') }}">{{ $entry ? \App\Support\WorkflowStatus::label($entry->status) : 'Not started' }}</span></td>
                        <td>@can('submit')<a class="btn-secondary" href="{{ route('entries.edit', ['assignment' => $assignment, 'period' => $reportingPeriod['start']->format('Y-m'), 'project' => $selectedProjectId, 'centre' => $selectedCentreId, 'donor' => $selectedDonor, 'search' => $indicatorSearch, 'entry_status' => $selectedEntryStatus, 'lock_project' => $projectLocked ? 1 : null]) }}">Open</a>@endcan</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-10 text-center text-slate-500">
                            <p>No indicators have an active target for this month and the selected filters.</p>
                            @if($otherTargetMonths->isNotEmpty())
                                <p class="mt-4 text-sm font-semibold text-slate-700">Targets are available in:</p>
                                <div class="mt-3 flex flex-wrap justify-center gap-2">
                                    @foreach($otherTargetMonths as $targetMonth)
                                        <a class="rounded-full border border-brand-200 bg-brand-50 px-3 py-1.5 text-sm font-semibold text-brand-700" href="{{ route('entries.index', ['anchor' => $targetMonth->format('Y-m'), 'project' => $selectedProjectId, 'centre' => $selectedCentreId, 'donor' => $selectedDonor, 'search' => $indicatorSearch, 'entry_status' => $selectedEntryStatus, 'lock_project' => $projectLocked ? 1 : null]) }}">{{ $targetMonth->format('M Y') }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $assignments->links() }}</div>
</x-layouts.app>
