<x-layouts.app heading="Data entry">
    <form class="no-print card mb-5 grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4">
        @if($projectLocked)<input type="hidden" name="project" value="{{ $selectedProjectId }}"><input type="hidden" name="lock_project" value="1">@endif
        <x-reporting-period-fields :period="$reportingPeriod" :types="config('impact.frequencies')" :auto-submit="true" />
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
    </form>

    <p class="mb-5 text-sm text-slate-500">
        Showing {{ strtolower(config('impact.frequencies.'.$reportingPeriod['type'])) }} indicators for <strong>{{ $periodLabel }}</strong>.
        One entry covers this entire reporting period, even when it is entered before the period ends.
    </p>

    <div class="mb-5 grid gap-4 sm:grid-cols-3">
        <div class="card p-4"><p class="text-sm text-slate-500">Reporting completion · {{ $periodLabel }}</p><p class="mt-1 text-2xl font-bold text-ink">{{ $completeness['rate'] }}%</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Records entered</p><p class="mt-1 text-2xl font-bold text-ink">{{ $completeness['reported'] }} <span class="text-sm font-medium text-slate-400">of {{ $completeness['expected'] }} required</span></p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Records still missing</p><p class="mt-1 text-2xl font-bold text-ink">{{ $completeness['missing'] }}</p></div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th class="sticky-col">Programme / Indicator</th><th>Project</th><th>Input</th><th>Evidence</th><th>Status</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($assignments as $assignment)
                    @php($entry = $assignment->entries->first())
                    <tr>
                        <td class="sticky-col"><p class="font-semibold text-slate-800">{{ $assignment->indicator->name }}</p><p class="text-xs text-slate-500">{{ $assignment->indicator->project->name }} · {{ $assignment->indicator->category }}</p></td>
                        <td>{{ $assignment->centre->name }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $assignment->indicator->data_type)) }}</td>
                        <td>{{ $assignment->indicator->evidence_required ? 'Required' : 'Optional' }}</td>
                        <td><span class="badge {{ $entry?->status === \App\Support\WorkflowStatus::FinalApproved ? 'bg-emerald-100 text-emerald-700' : ($entry && in_array($entry->status, \App\Support\WorkflowStatus::pending(), true) ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-700') }}">{{ $entry ? \App\Support\WorkflowStatus::label($entry->status) : 'Not started' }}</span></td>
                        <td>@can('submit')<a class="btn-secondary" href="{{ route('entries.edit', ['assignment' => $assignment, 'period' => $reportingPeriod['start']->format('Y-m'), 'period_type' => $reportingPeriod['type'], 'project' => $selectedProjectId, 'centre' => $selectedCentreId, 'lock_project' => $projectLocked ? 1 : null]) }}">{{ $entry ? (in_array($entry->status, [...\App\Support\WorkflowStatus::pending(), \App\Support\WorkflowStatus::FinalApproved], true) ? 'View entry' : 'Continue entry') : 'Enter value' }}</a>@endcan</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="py-10 text-center text-slate-500">No {{ strtolower(config('impact.frequencies.'.$reportingPeriod['type'])) }} indicators match this reporting period and the selected filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $assignments->links() }}</div>
</x-layouts.app>
