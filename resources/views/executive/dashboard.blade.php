<x-layouts.app heading="Executive overview">
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-sm text-slate-500">A monthly view of final-approved indicators that need attention or exceeded expectations. On-track indicators are intentionally excluded.</p>
        </div>
        <form class="no-print w-full sm:w-56">
            <input type="hidden" name="performance" value="{{ $selectedPerformance }}">
            <label for="month">Reporting month</label>
            <input id="month" name="month" type="month" value="{{ $month->format('Y-m') }}" onchange="this.form.submit()" class="mt-1 w-full">
        </form>
    </div>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card p-5"><p class="text-sm text-slate-500">Needs attention</p><p class="mt-2 text-3xl font-bold text-amber-700">{{ $stats['needs_attention'] }}</p></div>
        <div class="card p-5"><p class="text-sm text-slate-500">Overachievers</p><p class="mt-2 text-3xl font-bold text-blue-700">{{ $stats['overachievers'] }}</p></div>
        <div class="card p-5"><p class="text-sm text-slate-500">Projects with exceptions</p><p class="mt-2 text-3xl font-bold text-ink">{{ $stats['projects_with_exceptions'] }}</p></div>
        <div class="card p-5"><p class="text-sm text-slate-500">Approved results reviewed</p><p class="mt-2 text-3xl font-bold text-ink">{{ $stats['approved_results'] }}</p></div>
    </div>

    <div class="no-print mb-5 flex flex-wrap gap-2">
        @foreach([
            'exceptions' => ['All exceptions', $stats['needs_attention'] + $stats['overachievers']],
            'needs_attention' => ['Needs attention', $stats['needs_attention']],
            'overachiever' => ['Overachievers', $stats['overachievers']],
        ] as $value => [$label, $count])
            <a href="{{ route('executive.dashboard', ['month' => $month->format('Y-m'), 'performance' => $value]) }}" class="rounded-full border px-4 py-2 text-sm font-semibold {{ $selectedPerformance === $value ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300 hover:text-brand-700' }}">{{ $label }} · {{ $count }}</a>
        @endforeach
        <a href="{{ route('reports.index', ['period_type' => 'monthly', 'anchor' => $month->format('Y-m')]) }}" class="ml-auto px-3 py-2 text-sm font-semibold text-brand-700">Open full report</a>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th class="sticky-col">Programme / project</th><th>Indicator</th><th>Performance</th><th>Target</th><th>Achievement</th><th>Difference</th><th>Variance</th><th>Explanation</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($rows as $row)
                    <tr>
                        <td class="sticky-col"><p class="font-semibold text-slate-800">{{ $row['project']->name }}</p><p class="text-xs text-slate-500">{{ $row['centre']?->name ?? 'Programme level' }}</p></td>
                        <td><p class="font-semibold text-slate-800">{{ $row['indicator']->name }}</p><p class="text-xs text-slate-500">{{ $row['indicator']->category }}</p></td>
                        <td><span class="badge {{ $row['performance_status'] === 'Needs attention' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-700' }}">{{ $row['performance_status'] }}</span></td>
                        <td>{{ $row['target_display'] }} {{ $row['target'] !== null ? $row['indicator']->unit : '' }}</td>
                        <td class="font-semibold">{{ is_array($row['achievement']) ? collect($row['achievement'])->map(fn ($count, $status) => "$status: $count")->join(', ') : (is_numeric($row['achievement']) ? number_format($row['achievement'], $row['indicator']->precision) : ($row['achievement'] ?? 'No approved result')) }} {{ is_numeric($row['achievement']) ? $row['indicator']->unit : '' }}</td>
                        <td>{{ $row['variance'] === null ? '—' : number_format($row['variance'], $row['indicator']->precision) }}</td>
                        <td>{{ $row['achievement_percentage'] === null ? 'N/A' : (($row['achievement_percentage'] > 0 ? '+' : '').number_format($row['achievement_percentage'], 1).'%') }}</td>
                        <td class="min-w-64">{{ $row['variance_explanations']->join(' · ') ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-12 text-center text-slate-500">No {{ $selectedPerformance === 'exceptions' ? 'variance exceptions' : str_replace('_', ' ', $selectedPerformance) }} were recorded for {{ $month->format('F Y') }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-layouts.app>
