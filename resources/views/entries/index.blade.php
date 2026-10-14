<x-layouts.app heading="Data entry">
    <form class="no-print card mb-5 grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
        @if($projectLocked)<input type="hidden" name="project" value="{{ $selectedProjectId }}"><input type="hidden" name="lock_project" value="1">@endif
        <div>
            <label for="period">Reporting month</label>
            <input id="period" name="period" type="month" value="{{ $period }}" onchange="this.form.submit()" class="mt-1 w-full">
        </div>
        @if($projectLocked)
            <div><p class="text-sm font-medium text-slate-700">Project</p><p class="mt-3 font-semibold text-ink">{{ $selectedProject->name }}</p></div>
        @else
            <div>
                <label for="project">Project</label>
                <select id="project" name="project" onchange="this.form.elements.centre.value = ''; this.form.submit()" class="mt-1 w-full">
                    <option value="">All projects</option>
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}" @selected($selectedProjectId === $project->id)>{{ $project->name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div>
            <label for="centre">Centre</label>
            <select id="centre" name="centre" onchange="this.form.submit()" class="mt-1 w-full">
                <option value="">{{ $selectedProjectId ? 'All project centres' : 'All centres' }}</option>
                @foreach($centres as $centre)
                    <option value="{{ $centre->id }}" @selected($selectedCentreId === $centre->id)>{{ $centre->name }}</option>
                @endforeach
            </select>
        </div>
    </form>

    <div class="mb-5 grid gap-4 sm:grid-cols-3">
        <div class="card p-4"><p class="text-sm text-slate-500">Reporting completion · {{ \Carbon\Carbon::parse($period.'-01')->format('M Y') }}</p><p class="mt-1 text-2xl font-bold text-ink">{{ $completeness['rate'] }}%</p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Records reported</p><p class="mt-1 text-2xl font-bold text-ink">{{ $completeness['reported'] }} <span class="text-sm font-medium text-slate-400">of {{ $completeness['expected'] }} required</span></p></div>
        <div class="card p-4"><p class="text-sm text-slate-500">Records still missing</p><p class="mt-1 text-2xl font-bold text-ink">{{ $completeness['missing'] }}</p></div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Project / Indicator</th><th>Centre</th><th>Input</th><th>Evidence</th><th></th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($assignments as $assignment)
                    <tr>
                        <td><p class="font-semibold text-slate-800">{{ $assignment->indicator->name }}</p><p class="text-xs text-slate-500">{{ $assignment->indicator->project->name }} · {{ $assignment->indicator->category }}</p></td>
                        <td>{{ $assignment->centre->name }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $assignment->indicator->data_type)) }}</td>
                        <td>{{ $assignment->indicator->evidence_required ? 'Required' : 'Optional' }}</td>
                        <td>@can('submit')<a class="btn-secondary" href="{{ route('entries.edit', ['assignment' => $assignment, 'period' => $period, 'project' => $selectedProjectId, 'centre' => $selectedCentreId, 'lock_project' => $projectLocked ? 1 : null]) }}">Enter value</a>@endcan</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-10 text-center text-slate-500">No reporting assignments match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $assignments->links() }}</div>
</x-layouts.app>
