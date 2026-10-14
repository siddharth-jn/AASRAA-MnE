<x-layouts.app :heading="$project->name">
    <div class="card mb-6 p-6"><div class="flex flex-col justify-between gap-4 md:flex-row"><div><div class="mb-3 flex gap-2"><span class="badge bg-brand-50 text-brand-700">{{ $project->active ? 'Active programme' : 'Archived' }}</span>@if($project->is_demo)<span class="badge bg-amber-100 text-amber-700">Demo configuration</span>@endif</div><p class="max-w-3xl leading-7 text-slate-600">{{ $project->description }}</p><p class="mt-3 text-sm text-slate-500">Project lead: <strong class="text-slate-700">{{ $project->lead?->name ?? 'Not assigned' }}</strong></p></div><a href="{{ route('entries.index') }}" class="btn-primary self-start">Enter data</a></div></div>

    <nav class="no-print mb-6 flex gap-2 overflow-x-auto pb-1 text-sm font-semibold"><a href="#overview" class="badge bg-brand-100 text-brand-700">Overview</a>@foreach(['Input', 'Activity', 'Output', 'Outcome', 'KPI'] as $tab)<a href="#{{ strtolower($tab) }}" class="badge bg-slate-100 text-slate-700">{{ $tab === 'KPI' ? 'KPIs' : $tab.'s' }}</a>@endforeach<a href="#evidence" class="badge bg-slate-100 text-slate-700">Evidence</a><a href="{{ route('reports.index', ['project' => $project->id]) }}" class="badge bg-slate-100 text-slate-700">Reports</a></nav>

    <form id="overview" class="no-print card mb-6 grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4">
        <x-reporting-period-fields :period="$period" />
        <div><label for="centre">Centre</label><select id="centre" name="centre" class="mt-1 w-full"><option value="">All centres</option>@foreach($project->centres as $centre)<option value="{{ $centre->id }}" @selected(request('centre') == $centre->id)>{{ $centre->name }}</option>@endforeach</select></div>
        <div><label for="category">Category</label><select id="category" name="category" class="mt-1 w-full"><option value="">All categories</option>@foreach(config('impact.categories') as $category)<option @selected(request('category') === $category)>{{ $category }}</option>@endforeach</select></div>
        <div><label for="q">Search indicators</label><input id="q" name="q" value="{{ request('q') }}" placeholder="Name or code" class="mt-1 w-full"></div>
        <div class="flex items-end"><button class="btn-secondary">Apply filters</button></div>
    </form>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([['Assigned centres', $project->centres->count()], ['Required records', $completeness['expected']], ['Reporting completion', $completeness['rate'].'%'], ['Pending approval', $completeness['pending']]] as [$label, $value])
            <div class="card p-4"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-1 text-2xl font-bold text-ink">{{ $value }}</p></div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <section class="card p-6 xl:col-span-2">
            <h2 class="font-bold text-ink">Configured indicators</h2>
            <div class="mt-4 divide-y divide-slate-100">
                @forelse($indicators->groupBy('category') as $category => $categoryIndicators)
                    <div id="{{ strtolower($category) }}" class="scroll-mt-20 py-4"><p class="mb-2 text-xs font-bold uppercase tracking-wider text-brand-700">{{ $category }}</p>
                        @foreach($categoryIndicators as $indicator)
                            @php($row = $rows->first(fn ($candidate) => $candidate['indicator']->id === $indicator->id))
                            <a href="{{ route('kpis.show', [$project, $indicator, 'centre' => request('centre')]) }}" class="flex items-center justify-between gap-3 rounded-lg px-2 py-3 hover:bg-slate-50"><div><p class="text-sm font-semibold text-slate-800">{{ $indicator->name }}</p><p class="text-xs text-slate-500">{{ $indicator->unit }} · {{ $indicator->time_aggregation }} over time · {{ $indicator->assignments_count }} centres</p></div><div class="text-right"><p class="text-sm font-bold text-ink">{{ $row && is_numeric($row['achievement']) ? number_format($row['achievement'], $indicator->precision).' '.$indicator->unit : 'View details' }}</p><p class="text-xs text-slate-500">{{ $row['performance_status'] ?? 'No approved result' }}</p></div></a>
                        @endforeach
                    </div>
                @empty<p class="py-8 text-center text-sm text-slate-500">No indicators match the filters.</p>@endforelse
            </div>
        </section>
        <section id="evidence" class="card scroll-mt-20 p-6"><h2 class="font-bold text-ink">Reporting locations</h2><div class="mt-4 space-y-3">@foreach($project->centres as $centre)<a href="{{ route('centres.show', ['centre' => $centre, 'project' => $project->id, 'period_type' => $period['type'], 'anchor' => request('anchor', '2026-09'), 'start' => request('start'), 'end' => request('end')]) }}" class="block rounded-lg border border-slate-100 p-3 hover:border-brand-200 hover:bg-brand-50"><p class="text-sm font-semibold text-slate-800">{{ $centre->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $centre->type }} · {{ $centre->region }}</p></a>@endforeach</div></section>
    </div>
</x-layouts.app>
