<x-layouts.app :heading="$indicator->name">
    <div class="mb-5"><a href="{{ route('programmes.show', $project) }}" class="text-sm font-semibold text-brand-700">← {{ $project->name }}</a></div>
    <div class="card mb-6 p-6">
        <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            <div class="xl:col-span-2"><p class="text-xs font-semibold uppercase text-slate-400">Definition</p><p class="mt-2 text-sm leading-6 text-slate-600">{{ $indicator->description }}</p></div>
            <div><p class="text-xs font-semibold uppercase text-slate-400">Configuration</p><p class="mt-2 text-sm font-semibold text-slate-800">{{ $indicator->unit }} · {{ ucfirst(str_replace('_', ' ', $indicator->frequency)) }}</p><p class="text-xs text-slate-500">{{ $indicator->time_aggregation }} over time</p></div>
            <div><p class="text-xs font-semibold uppercase text-slate-400">Reporting projects</p><p class="mt-2 text-2xl font-bold text-ink">{{ $indicator->centres->count() }}</p></div>
        </div>
    </div>

    <form class="no-print card mb-6 flex flex-wrap items-end gap-3 p-5">
        <div><label for="start">From</label><input id="start" type="month" name="start" value="{{ $start->format('Y-m') }}" class="mt-1 block"></div>
        <div><label for="end">To</label><input id="end" type="month" name="end" value="{{ $end->format('Y-m') }}" class="mt-1 block"></div>
        <div><label for="centre">Project</label><select id="centre" name="centre" onchange="this.form.submit()" class="mt-1 block"><option value="">All projects</option>@foreach($indicator->centres as $centre)<option value="{{ $centre->id }}" @selected(request('centre') == $centre->id)>{{ $centre->name }}</option>@endforeach</select></div>
        <button class="btn-secondary">Apply</button>
    </form>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="card p-6">
            <h2 class="font-bold text-ink">Target versus achievement</h2>
            <div class="mt-4 space-y-4">
                @forelse($rows as $row)
                    @php($bar = $row['achievement_percentage'] === null ? 0 : min(100, max(0, $row['achievement_percentage'])))
                    <div>
                        <div class="mb-1 flex justify-between gap-3 text-sm"><span>{{ $row['centre']->name }}</span><strong>{{ is_numeric($row['achievement']) ? number_format($row['achievement'], $indicator->precision) : '—' }} / {{ $row['target_display'] }}</strong></div>
                        <div class="h-3 rounded-full bg-slate-100"><div class="h-3 rounded-full {{ $row['performance_status'] === 'On track' ? 'bg-emerald-500' : 'bg-brand-500' }}" style="width: {{ $bar }}%"></div></div>
                        <p class="mt-1 text-xs text-slate-500">{{ $row['performance_status'] }}{{ $row['achievement_percentage'] !== null ? ' · '.number_format($row['achievement_percentage'], 1).'%' : '' }}</p>
                    </div>
                @empty<p class="py-8 text-center text-sm text-slate-500">No approved results are available.</p>@endforelse
            </div>
        </section>

        <section class="card p-6">
            <h2 class="font-bold text-ink">Historical trend</h2>
            @php($points = $entries->filter(fn ($entry) => $entry->approvedRevision?->numeric_value !== null)->map(fn ($entry) => ['label' => $entry->periodLabel().' · '.($entry->assignment->centre?->code ?? 'Programme'), 'value' => (float) $entry->approvedRevision->numeric_value]))
            @php($maximum = max(1, (float) $points->max('value')))
            <div class="mt-4 space-y-3">
                @forelse($points as $point)
                    <div><div class="mb-1 flex justify-between text-xs"><span>{{ $point['label'] }}</span><strong>{{ number_format($point['value'], $indicator->precision) }}</strong></div><div class="h-2 rounded-full bg-slate-100"><div class="h-2 rounded-full bg-brand-500" style="width: {{ $point['value'] / $maximum * 100 }}%"></div></div></div>
                @empty<p class="py-8 text-center text-sm text-slate-500">This indicator does not have numeric trend data.</p>@endforelse
            </div>
        </section>
    </div>

    <section class="card mt-6 p-6">
        <h2 class="font-bold text-ink">{{ $showWorkflowHistory ? 'Submission and approval history' : 'Approved result history' }}</h2>
        <div class="mt-4 space-y-3">
            @forelse($entries->sortByDesc('period') as $entry)
                <details class="rounded-lg border border-slate-200 p-4">
                    <summary class="cursor-pointer"><span class="font-semibold text-slate-800">{{ $entry->periodLabel() }} · {{ $entry->assignment->centre?->name ?? 'Programme level' }}</span> <span class="ml-2 badge bg-slate-100 text-slate-700">{{ $showWorkflowHistory ? $entry->status : 'FINAL APPROVED' }}</span></summary>
                    <div class="mt-3 space-y-3">
                        @foreach($showWorkflowHistory ? $entry->revisions->sortByDesc('version') : collect([$entry->approvedRevision]) as $revision)
                            <div class="rounded bg-slate-50 p-3 text-sm"><p><strong>Revision {{ $revision->version }}:</strong> {{ $revision->displayValue() }} · {{ $revision->user->name }}</p>@if($revision->notes)<p class="mt-1 text-slate-500">{{ $revision->notes }}</p>@endif
                                @if($revision->evidence->isNotEmpty())<div class="mt-2 flex flex-wrap gap-2">@foreach($revision->evidence as $file)<a class="font-semibold text-brand-700" href="{{ route('evidence.download', $file) }}">{{ $file->original_name }}</a>@endforeach</div>@endif
                                @foreach($revision->actions as $action)<p class="mt-2 text-slate-500">{{ $action->action }} by {{ $action->user->name }}@if($action->comment): {{ $action->comment }}@endif</p>@endforeach
                            </div>
                        @endforeach
                    </div>
                </details>
            @empty<p class="py-8 text-center text-sm text-slate-500">No submission history is available.</p>@endforelse
        </div>
    </section>
</x-layouts.app>
