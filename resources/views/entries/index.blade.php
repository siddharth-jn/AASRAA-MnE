<x-layouts.app heading="Data entry">
    <form class="no-print card mb-5 grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4">
        <div>
            <label for="period">Reporting month</label>
            <input id="period" name="period" type="month" value="{{ $period }}" class="mt-1 w-full">
        </div>
        <div>
            <label for="project">Project</label>
            <select id="project" name="project" onchange="this.form.elements.centre.value = ''; this.form.submit()" class="mt-1 w-full">
                <option value="">All projects</option>
                @foreach($projects as $project)
                    <option value="{{ $project->id }}" @selected($selectedProjectId === $project->id)>{{ $project->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="centre">Centre</label>
            <select id="centre" name="centre" class="mt-1 w-full">
                <option value="">{{ $selectedProjectId ? 'All project centres' : 'All centres' }}</option>
                @foreach($centres as $centre)
                    <option value="{{ $centre->id }}" @selected($selectedCentreId === $centre->id)>{{ $centre->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end"><button class="btn-secondary">Apply filters</button></div>
    </form>

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
                        <td>@can('submit')<a class="btn-secondary" href="{{ route('entries.edit', ['assignment' => $assignment, 'period' => $period]) }}">Enter value</a>@endcan</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-10 text-center text-slate-500">No reporting assignments match these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $assignments->links() }}</div>
</x-layouts.app>
