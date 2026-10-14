<x-layouts.app :heading="$centre->name">
    <form class="no-print card mb-5 grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-6">
        <x-reporting-period-fields :period="$period" />
        <div><label for="project">Programme</label><select id="project" name="project" class="mt-1 block"><option value="">All programmes</option>@foreach($centre->projects as $project)<option value="{{ $project->id }}" @selected(request('project') == $project->id)>{{ $project->name }}</option>@endforeach</select></div>
        <div class="flex items-end"><button class="btn-secondary">Apply</button></div>
    </form>

    @if($period['warning'])<div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $period['warning'] }}</div>@endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach([['Location type', $centre->type], ['Region', $centre->region], ['Assigned programmes', $centre->projects->count()], ['Assigned staff', $centre->users->count()], ['Reporting completion', $completeness['rate'].'%'], ['Missing records', $completeness['missing']]] as [$label, $value])
            <div class="card p-5"><p class="text-xs font-semibold uppercase text-slate-400">{{ $label }}</p><p class="mt-2 font-bold text-ink">{{ $value }}</p></div>
        @endforeach
    </div>

    <div class="mt-6 table-wrap"><table class="data-table"><thead><tr><th>Project</th><th>Indicator</th><th>Category</th><th>Approved value</th><th>Status</th><th></th></tr></thead><tbody class="divide-y divide-slate-100">
        @forelse($entries as $entry)
            <tr><td>{{ $entry->assignment->indicator->project->name }}</td><td class="font-semibold text-slate-800">{{ $entry->assignment->indicator->name }}</td><td>{{ $entry->assignment->indicator->category }}</td><td>{{ $entry->approvedRevision?->displayValue() ?? '—' }} {{ $entry->approvedRevision ? $entry->assignment->indicator->unit : '' }}</td><td><span class="badge {{ $entry->status === 'APPROVED' ? 'bg-emerald-100 text-emerald-700' : ($entry->status === 'SUBMITTED' ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-700') }}">{{ $entry->status }}</span></td><td><a class="text-sm font-semibold text-brand-700" href="{{ route('entries.edit', ['assignment' => $entry->assignment, 'period' => $entry->period->format('Y-m')]) }}">Open</a></td></tr>
        @empty<tr><td colspan="6" class="py-10 text-center text-slate-500">No records are available for this period.</td></tr>@endforelse
    </tbody></table></div><div class="mt-6">{{ $entries->links() }}</div>

    @if($completeness['missing_items']->isNotEmpty())
        <section class="card mt-6 p-5"><h2 class="font-bold text-ink">Missing indicators</h2><div class="mt-3 grid gap-2 md:grid-cols-2">@foreach($completeness['missing_items'] as $item)<p class="rounded-lg bg-slate-50 px-3 py-2 text-sm">{{ $item['assignment']->indicator->name }} <span class="text-slate-400">· {{ $item['period']->format('M Y') }}</span></p>@endforeach</div></section>
    @endif
</x-layouts.app>
