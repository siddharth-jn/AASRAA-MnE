<x-layouts.app heading="Reports">
    <form class="no-print card mb-6 grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
        <x-reporting-period-fields :period="$period" />
        <div>
            <label for="project">Programme</label>
            <select id="project" name="project" onchange="this.form.elements.centre.value = ''; this.form.elements.indicator.value = ''; this.form.submit()" class="mt-1 w-full">
                <option value="">All programmes</option>
                @foreach($projects as $project)
                    <option value="{{ $project->id }}" @selected($selectedProjectId === $project->id)>{{ $project->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="indicator">Indicator</label>
            <select id="indicator" name="indicator" class="mt-1 w-full">
                <option value="">All indicators</option>
                @foreach($indicators as $indicator)
                    <option value="{{ $indicator->id }}" @selected($selectedIndicatorId === $indicator->id)>{{ $indicator->project->name }} · {{ $indicator->code }} · {{ $indicator->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="category">Indicator category</label>
            <select id="category" name="category" class="mt-1 w-full">
                <option value="">All categories</option>
                @foreach($categories as $category)
                    <option value="{{ $category }}" @selected($selectedCategory === $category)>{{ $category }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="donor">Donor</label>
            <select id="donor" name="donor" class="mt-1 w-full">
                <option value="">All donors</option>
                @foreach($donors as $donor)
                    <option value="{{ $donor }}" @selected($selectedDonor === $donor)>{{ $donor }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="centre">Project</label>
            <select id="centre" name="centre" onchange="this.form.elements.indicator.value = ''; this.form.submit()" class="mt-1 w-full">
                <option value="">{{ $selectedProjectId ? 'All programme projects' : 'All projects' }}</option>
                @foreach($centres as $centre)
                    <option value="{{ $centre->id }}" @selected($selectedCentreId === $centre->id)>{{ $centre->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="mode">Report view</label>
            <select id="mode" name="mode" class="mt-1 w-full">
                <option value="detailed" @selected($selectedMode === 'detailed')>Detailed by project</option>
                <option value="consolidated" @selected($selectedMode === 'consolidated')>Consolidated programme summary</option>
            </select>
        </div>
        <div>
            <label for="performance">Performance</label>
            <select id="performance" name="performance" class="mt-1 w-full">
                <option value="">All performance statuses</option>
                <option value="on_track" @selected($selectedPerformance === 'on_track')>On track</option>
                <option value="needs_attention" @selected($selectedPerformance === 'needs_attention')>Needs attention</option>
                <option value="overachiever" @selected($selectedPerformance === 'overachiever')>Overachiever</option>
                <option value="not_assessed" @selected($selectedPerformance === 'not_assessed')>Not assessed</option>
            </select>
        </div>
        <div class="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-4">
            <button class="btn-primary">Generate</button>
            <a href="{{ route('reports.export', request()->query()) }}" class="btn-secondary">CSV</a>
            <a href="{{ route('reports.excel', request()->query()) }}" class="btn-secondary">Excel</a>
            <a href="{{ route('reports.pdf', request()->query()) }}" class="btn-secondary">PDF</a>
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
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-xl font-bold text-ink">Target and approved performance report</h2>
                @if($period['provisional'])<span class="badge bg-amber-100 text-amber-800">Provisional · period open</span>@endif
            </div>
            <p class="text-sm text-slate-500">{{ $period['start']->format('d M Y') }} to {{ $period['end']->format('d M Y') }} · {{ $projects->firstWhere('id', $selectedProjectId)?->name ?? 'All programmes' }} · {{ $centres->firstWhere('id', $selectedCentreId)?->name ?? 'All projects' }} · generated {{ now()->format('d M Y, H:i') }}</p>
            <p class="mt-1 text-xs text-slate-500">Every month with a target through {{ $period['data_end']->format('F Y') }} is included. Achievement totals use only final-approved entries; coverage shows how many target months are approved.</p>
        </div>
        <button onclick="window.print()" class="no-print btn-secondary">Print report</button>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th class="sticky-col">Programme / project</th><th>Donor</th><th>Indicator</th><th>Target</th><th>Achievement</th><th>Difference</th><th>Variance %</th><th>Variance explanation</th><th>Coverage</th><th>Trend</th><th>Performance</th><th>Approval</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @php($lastGroup = null)
                @php($lastCategory = null)
                @forelse($rows as $row)
                    @php($group = $row['project']->name.'|'.($row['centre']?->name ?? (($row['scope'] ?? 'project') === 'programme' ? 'Programme level' : 'Consolidated')))
                    @if($group !== $lastGroup)
                        <tr class="bg-slate-100"><td colspan="12" class="sticky-col py-3 font-bold text-ink">{{ $row['project']->name }} <span class="font-normal text-slate-500">· {{ $row['centre']?->name ?? (($row['scope'] ?? 'project') === 'programme' ? 'Programme-level indicators' : 'Consolidated programme summary') }}</span></td></tr>
                        @php($lastGroup = $group)
                        @php($lastCategory = null)
                    @endif
                    @if($row['indicator']->category !== $lastCategory)
                        <tr class="bg-brand-50/60"><td colspan="12" class="sticky-col py-2 text-xs font-bold uppercase tracking-wide text-brand-700">{{ $row['indicator']->category }}</td></tr>
                        @php($lastCategory = $row['indicator']->category)
                    @endif
                    <tr>
                        <td class="sticky-col"><p class="font-semibold text-slate-800">{{ $row['project']->name }}</p><p class="text-xs text-slate-500">{{ $row['centre']?->name ?? (($row['scope'] ?? 'project') === 'programme' ? 'Programme level' : 'All projects ('.$row['centre_count'].')') }}</p></td>
                        <td>{{ $row['donor_display'] ?? 'Not specified' }}</td>
                        <td><p class="font-semibold text-slate-800">{{ $row['indicator']->name }}</p><p class="text-xs text-slate-500">{{ $row['indicator']->category }} · {{ $row['indicator']->time_aggregation }} over time{{ $row['centre'] === null && ($row['scope'] ?? 'project') !== 'programme' ? ' · '.($row['across_project_method'] === 'NON-AGGREGATABLE' ? 'Do not combine' : ($row['across_project_method'].' across projects')) : '' }}</p></td>
                        <td>{{ $row['target_display'] }} {{ $row['target'] !== null ? $row['indicator']->unit : '' }}</td>
                        <td class="font-semibold">{{ is_array($row['achievement']) ? (collect($row['achievement'])->map(fn ($count, $status) => "$status: $count")->join(', ') ?: 'No approved result') : ($row['achievement'] === null ? ($row['centre'] === null && ($row['across_project_method'] ?? null) === 'NON-AGGREGATABLE' ? 'Select a project' : 'No combined value') : number_format($row['achievement'], $row['indicator']->precision)) }} {{ is_numeric($row['achievement']) ? $row['indicator']->unit : '' }}</td>
                        <td>{{ $row['variance'] === null ? '—' : number_format($row['variance'], $row['indicator']->precision) }}</td>
                        <td>{{ $row['achievement_percentage'] === null ? 'N/A' : (($row['achievement_percentage'] > 0 ? '+' : '').number_format($row['achievement_percentage'], 1).'%') }}</td>
                        <td class="min-w-64 text-sm">{{ $row['variance_explanations']->join(' · ') ?: '—' }}</td>
                        <td>{{ $row['approved_months'] }} of {{ $row['expected_months'] }} target months approved</td>
                        <td class="text-xs text-slate-500">{{ $row['trend']->pluck('value')->filter(fn ($value) => $value !== null)->map(fn ($value) => number_format($value, $row['indicator']->precision))->join(' → ') ?: '—' }}</td>
                        <td><span class="badge {{ match($row['performance_status']) { 'On track' => 'bg-emerald-100 text-emerald-700', 'Overachiever' => 'bg-blue-100 text-blue-700', 'Needs attention' => 'bg-amber-100 text-amber-800', default => 'bg-slate-100 text-slate-700' } }}">{{ $row['performance_status'] }}</span></td>
                        <td><span class="badge {{ $row['status'] === 'FINAL APPROVED' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800' }}">{{ $row['status'] }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="py-12 text-center text-slate-500">No monthly targets match this reporting period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($completeness['missing_items']->isNotEmpty())
        <details class="no-print card mt-6 p-5">
            <summary class="cursor-pointer font-semibold text-slate-800">Missing reporting obligations (showing up to 100)</summary>
            <div class="mt-4 grid gap-2 md:grid-cols-2">
                @foreach($completeness['missing_items'] as $item)
                    <p class="rounded-lg bg-slate-50 px-3 py-2 text-sm"><strong>{{ $item['assignment']->indicator->name }}</strong><br><span class="text-slate-500">{{ $item['assignment']->centre?->name ?? 'Programme level' }} · {{ $item['period']->format('M Y') }}</span></p>
                @endforeach
            </div>
        </details>
    @endif
</x-layouts.app>
