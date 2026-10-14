<x-layouts.app heading="Organization dashboard">
    <form class="no-print card mb-6 grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-5">
        <x-reporting-period-fields :period="$period" />
        <div class="flex items-end"><button class="btn-secondary">Apply period</button></div>
    </form>

    @if($period['warning'])<div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $period['warning'] }}</div>@endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([
            ['Active projects', $stats['projects'], 'Portfolio'],
                ['Active centres', $stats['centres'], $stats['reporting_centres'].' reported in this period'],
            ['Reporting completion', $stats['completion'].'%', $completeness['missing'].' required records missing'],
            ['Pending approvals', $stats['pending'], $stats['approved'].' approved records'],
        ] as [$label, $value, $hint])
            <div class="card p-5"><p class="text-sm font-medium text-slate-500">{{ $label }}</p><p class="mt-2 text-3xl font-bold text-ink">{{ $value }}</p><p class="mt-2 text-xs text-slate-400">{{ $hint }}</p></div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <section class="card p-6 xl:col-span-2">
            <h2 class="font-bold text-ink">Project reporting completion</h2>
            <p class="mb-5 text-sm text-slate-500">Required records received from {{ $period['start']->format('d M Y') }} to {{ $period['end']->format('d M Y') }}</p>
            <div class="space-y-4">
                @foreach($projectPerformance as $item)
                    <a href="{{ route('projects.show', ['project' => $item['project'], 'period_type' => $period['type'], 'anchor' => request('anchor', '2026-09'), 'start' => request('start'), 'end' => request('end')]) }}" class="block rounded-lg p-2 hover:bg-slate-50">
                        <div class="mb-1 flex justify-between gap-3 text-sm"><span class="font-medium text-slate-700">{{ $item['project']->name }}</span><strong>{{ $item['summary']['rate'] }}%</strong></div>
                        <div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full {{ $item['summary']['rate'] >= 90 ? 'bg-emerald-500' : ($item['summary']['rate'] >= 60 ? 'bg-amber-500' : 'bg-red-400') }}" style="width: {{ min(100, $item['summary']['rate']) }}%"></div></div>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="card p-6">
            <h2 class="font-bold text-ink">Approval status</h2>
            <p class="mb-5 text-sm text-slate-500">Records in the selected month</p>
            <div class="space-y-4">
                @foreach(['APPROVED', 'SUBMITTED', 'RETURNED FOR CORRECTION', 'REJECTED', 'DRAFT'] as $status)
                    <div><div class="mb-1 flex justify-between text-sm"><span>{{ ucfirst(strtolower($status)) }}</span><strong>{{ $statuses[$status] ?? 0 }}</strong></div><div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-brand-500" style="width: {{ min(100, (($statuses[$status] ?? 0) / max(1, $statuses->sum())) * 100) }}%"></div></div></div>
                @endforeach
            </div>
        </section>
    </div>

    @if($keyOutcomes->isNotEmpty())
        <section class="mt-6">
            <div class="mb-3"><h2 class="font-bold text-ink">Key approved outcomes</h2><p class="text-sm text-slate-500">Outcome and KPI results are kept separate by indicator and centre.</p></div>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach($keyOutcomes as $row)
                    <a href="{{ route('kpis.show', [$row['project'], $row['indicator'], 'centre' => $row['centre']->id]) }}" class="card p-5 hover:border-brand-200">
                        <p class="text-xs font-semibold uppercase text-brand-700">{{ $row['project']->name }}</p>
                        <p class="mt-2 font-semibold text-slate-800">{{ $row['indicator']->name }}</p>
                        <p class="mt-3 text-2xl font-bold text-ink">{{ is_numeric($row['achievement']) ? number_format($row['achievement'], $row['indicator']->precision) : '—' }} <small class="text-sm font-medium text-slate-500">{{ $row['indicator']->unit }}</small></p>
                        <p class="mt-1 text-xs text-slate-500">{{ $row['centre']->name }} · {{ $row['performance_status'] }}</p>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="card p-6">
            <div class="mb-5 flex items-center justify-between"><div><h2 class="font-bold text-ink">Recent submissions</h2><p class="text-sm text-slate-500">Latest activity across your assigned scope</p></div><a href="{{ route('entries.index') }}" class="text-sm font-semibold text-brand-700">View all</a></div>
            <div class="space-y-1">
                @forelse($recentEntries as $entry)
                    <a href="{{ route('entries.edit', ['assignment' => $entry->assignment, 'period' => $entry->period->format('Y-m')]) }}" class="flex items-center justify-between gap-4 rounded-lg p-3 hover:bg-slate-50"><div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-800">{{ $entry->assignment->indicator->name }}</p><p class="truncate text-xs text-slate-500">{{ $entry->assignment->indicator->project->name }} · {{ $entry->assignment->centre->name }}</p></div><span class="badge {{ $entry->status === 'APPROVED' ? 'bg-emerald-100 text-emerald-700' : ($entry->status === 'SUBMITTED' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700') }}">{{ $entry->status }}</span></a>
                @empty<p class="py-8 text-center text-sm text-slate-500">No submissions are available.</p>@endforelse
            </div>
        </section>
        <section class="card p-6">
            <h2 class="font-bold text-ink">Recently approved</h2><p class="mb-5 text-sm text-slate-500">Results most recently added to official reporting</p>
            <div class="space-y-3">
                @forelse($recentApproved as $entry)
                    <div class="rounded-lg border border-slate-100 p-3"><p class="text-sm font-semibold text-slate-800">{{ $entry->assignment->indicator->name }} · {{ $entry->approvedRevision->displayValue() }}</p><p class="mt-1 text-xs text-slate-500">{{ $entry->assignment->centre->name }} · {{ $entry->period->format('M Y') }}</p></div>
                @empty<p class="py-8 text-center text-sm text-slate-500">No approved results are available.</p>@endforelse
            </div>
        </section>
    </div>
</x-layouts.app>
