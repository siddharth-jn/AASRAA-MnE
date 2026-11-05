<x-layouts.app heading="Projects">
    <form method="GET" action="{{ route('projects.index') }}" class="card mb-6 p-5">
        <div class="grid gap-4 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.4fr)_auto] lg:items-end">
            <div>
                <label for="programme" class="mb-2 block text-sm font-semibold text-slate-700">Programme</label>
                <select id="programme" name="programme" class="form-input" onchange="this.form.submit()">
                    <option value="">All programmes</option>
                    @foreach($programmes as $programme)
                        <option value="{{ $programme->id }}" @selected($programmeId === $programme->id)>{{ $programme->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="project-search" class="mb-2 block text-sm font-semibold text-slate-700">Find a project</label>
                <input
                    id="project-search"
                    name="q"
                    type="search"
                    list="project-options"
                    value="{{ $search }}"
                    placeholder="Type a project name, code, or source project ID"
                    class="form-input"
                    autocomplete="off"
                >
                <datalist id="project-options">
                    @foreach($projectOptions as $projectOption)
                        <option value="{{ $projectOption->name }}">{{ $projectOption->code }}@if($projectOption->source_project_id) · ID {{ $projectOption->source_project_id }}@endif</option>
                    @endforeach
                </datalist>
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn-primary">Search</button>
                @if($programmeId || $search !== '')
                    <a href="{{ route('projects.index') }}" class="btn-secondary">Clear filters</a>
                @endif
            </div>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Code</th>
                    <th>Project</th>
                    <th>Project lead</th>
                    <th>Programme</th>
                    <th>Timing slot</th>
                    <th>Donor</th>
                    <th>Status</th>
                    <th>Imported on</th>
                    <th></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($centres as $centre)
                    <tr>
                        <td>{{ $centre->source_project_id ?: '—' }}</td>
                        <td>{{ $centre->code }}</td>
                        <td>
                            <p class="font-semibold text-slate-800">{{ $centre->name }}</p>
                            @if($centre->source_description)
                                <p class="mt-1 max-w-md text-xs text-slate-500">{{ Str::limit($centre->source_description, 100) }}</p>
                            @endif
                        </td>
                        <td>{{ $centre->lead?->name ?? 'Not assigned' }}</td>
                        <td>{{ $centre->projects->pluck('name')->join(', ') ?: '—' }}</td>
                        <td>{{ $centre->source_timing_slot ?: '—' }}</td>
                        <td>{{ $centre->source_donor_name ?: '—' }}</td>
                        <td>
                            <span class="badge {{ $centre->source_status === 'Active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                {{ $centre->source_status ?: ($centre->active ? 'Active' : 'Inactive') }}
                            </span>
                        </td>
                        <td>{{ $centre->source_synced_at?->format('d M Y, H:i') ?: '—' }}</td>
                        @php($detailProgrammeId = $programmeId ?: ($centre->projects->count() === 1 ? $centre->projects->first()->id : null))
                        <td><a href="{{ route('projects.show', ['centre' => $centre, ...($detailProgrammeId ? ['project' => $detailProgrammeId] : [])]) }}" class="font-semibold text-brand-700">Open</a></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="py-10 text-center text-sm text-slate-500">No projects match these filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $centres->links() }}</div>
</x-layouts.app>
