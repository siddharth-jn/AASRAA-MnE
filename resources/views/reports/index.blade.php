<x-layouts.app heading="Reports">
    <form class="no-print card mb-6 grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
        <x-reporting-period-fields :period="$period" />
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
        <div class="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-4">
            <button class="btn-primary">Generate</button>
            <a href="{{ route('reports.export', request()->query()) }}" class="btn-secondary">CSV</a>
            <a href="{{ route('reports.excel', request()->query()) }}" class="btn-secondary">Excel</a>
        </div>
    </form>

    @if($period['warning'])
        <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $period['warning'] }}</div>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([['Expected', $completeness['expected']], ['Reported', $completeness['reported']], ['Approved', $completeness['approved']], ['Missing', $completeness['missing']]] as [$label, $value])
            <div class="card p-4"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-1 text-2xl font-bold text-ink">{{ $value }}</p></div>
        @endforeach
    </div>

    <div class="mb-5 flex items-end justify-between gap-4">
        <div>
            <h2 class="text-xl font-bold text-ink">Approved performance report</h2>
            <p class="text-sm text-slate-500">{{ $period['start']->format('d M Y') }} to {{ $period['end']->format('d M Y') }} · {{ $projects->firstWhere('id', $selectedProjectId)?->name ?? 'All projects' }} · {{ $centres->firstWhere('id', $selectedCentreId)?->name ?? 'All centres' }} · generated {{ now()->format('d M Y, H:i') }}</p>
        </div>
        <button onclick="window.print()" class="no-print btn-secondary">Print report</button>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Project / centre</th><th>Indicator</th><th>Target</th><th>Achievement</th><th>Variance</th><th>Achievement</th><th>Trend</th><th>Performance</th><th>Approval</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($rows as $row)
                    <tr>
                        <td><p class="font-semibold text-slate-800">{{ $row['project']->name }}</p><p class="text-xs text-slate-500">{{ $row['centre']->name }}</p></td>
                        <td><p class="font-semibold text-slate-800">{{ $row['indicator']->name }}</p><p class="text-xs text-slate-500">{{ $row['indicator']->category }}</p></td>
                        <td>{{ $row['target_display'] }} {{ $row['target'] !== null ? $row['indicator']->unit : '' }}</td>
                        <td class="font-semibold">{{ is_array($row['achievement']) ? collect($row['achievement'])->map(fn ($count, $status) => "$status: $count")->join(', ') : ($row['achievement'] === null ? 'Not aggregatable' : number_format($row['achievement'], $row['indicator']->precision)) }} {{ is_numeric($row['achievement']) ? $row['indicator']->unit : '' }}</td>
                        <td>{{ $row['variance'] === null ? '—' : number_format($row['variance'], $row['indicator']->precision) }}</td>
                        <td>{{ $row['achievement_percentage'] === null ? '—' : number_format($row['achievement_percentage'], 1).'%' }}</td>
                        <td class="text-xs text-slate-500">{{ $row['trend']->pluck('value')->filter(fn ($value) => $value !== null)->map(fn ($value) => number_format($value, $row['indicator']->precision))->join(' → ') ?: '—' }}</td>
                        <td><span class="badge {{ $row['performance_status'] === 'Met' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700' }}">{{ $row['performance_status'] }}</span></td>
                        <td><span class="badge bg-emerald-100 text-emerald-700">{{ $row['status'] }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-12 text-center text-slate-500">No approved records match this reporting period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($completeness['missing_items']->isNotEmpty())
        <details class="no-print card mt-6 p-5">
            <summary class="cursor-pointer font-semibold text-slate-800">Missing reporting obligations (showing up to 100)</summary>
            <div class="mt-4 grid gap-2 md:grid-cols-2">
                @foreach($completeness['missing_items'] as $item)
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm"><strong>{{ $item['assignment']->indicator->name }}</strong><br><span class="text-slate-500">{{ $item['assignment']->centre->name }} · {{ $item['period']->format('M Y') }}</span></p>
                @endforeach
            </div>
        </details>
    @endif
</x-layouts.app>
