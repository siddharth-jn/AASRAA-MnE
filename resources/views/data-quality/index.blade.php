<x-layouts.app heading="Data quality">
    <form class="no-print mb-6 flex items-end gap-3"><div><label for="period">Reporting month</label><input id="period" name="period" type="month" value="{{ $period->format('Y-m') }}" class="mt-1 block"></div><button class="btn-secondary">Apply period</button></form>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach([
            ['Evidence compliance', $metrics['evidence_compliance'].'%', 'Submitted records requiring proof'],
            ['Approval turnaround', $metrics['approval_turnaround'] === null ? '—' : $metrics['approval_turnaround'].' hrs', 'Average decision time'],
            ['On-time submission', $metrics['on_time'].'%', 'Submitted by configured deadline'],
            ['Returned', $metrics['returned'].'%', 'Share of review decisions'],
            ['Unapproved records', $metrics['unapproved'], 'Draft, pending, returned, or rejected'],
        ] as [$label, $value, $hint])
            <div class="card p-5"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-2 text-2xl font-bold text-ink">{{ $value }}</p><p class="mt-2 text-xs text-slate-400">{{ $hint }}</p></div>
        @endforeach
    </div>

    <section class="card mt-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-3"><div><h2 class="font-bold text-ink">Completeness by centre</h2><p class="text-sm text-slate-500">Reporting quality is shown separately from programme outcomes.</p></div><p class="text-sm"><strong>{{ $summary['missing'] }}</strong> missing across the selected scope</p></div>
        <div class="mt-5 space-y-3">
            @foreach($centres as $item)
                <a href="{{ route('centres.show', ['centre' => $item['centre'], 'period' => $period->format('Y-m')]) }}" class="block rounded-lg p-2 hover:bg-slate-50"><div class="mb-1 flex justify-between gap-3 text-sm"><span>{{ $item['centre']->name }}</span><span><strong>{{ $item['summary']['rate'] }}%</strong> · {{ $item['summary']['missing'] }} missing</span></div><div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full {{ $item['summary']['rate'] >= 90 ? 'bg-emerald-500' : ($item['summary']['rate'] >= 60 ? 'bg-amber-500' : 'bg-red-400') }}" style="width: {{ min(100, $item['summary']['rate']) }}%"></div></div></a>
            @endforeach
        </div>
    </section>
</x-layouts.app>
