<x-layouts.app :heading="$project->name">
    <div class="card mb-6 p-6">
        <div class="flex flex-col justify-between gap-4 md:flex-row">
            <div>
                <div class="mb-3 flex gap-2"><span class="badge bg-brand-50 text-brand-700">{{ $project->active ? 'Active programme' : 'Archived' }}</span>@if($project->is_demo)<span class="badge bg-amber-100 text-amber-700">Demo configuration</span>@endif</div>
                <p class="max-w-3xl leading-7 text-slate-600">{{ $project->description }}</p>
                <p class="mt-3 text-sm text-slate-500">Programme code: <strong class="text-slate-700">{{ $project->code }}</strong> · Programme lead: <strong class="text-slate-700">{{ $project->lead?->name ?? 'Not assigned' }}</strong></p>
            </div>
            <div class="flex flex-wrap gap-3 self-start">
                @can('configure')<a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('edit', ['record' => $project]) }}" class="btn-secondary">Manage programme &amp; indicators</a>@endcan
                <a href="{{ route('entries.index', ['project' => $project->id, 'lock_project' => 1]) }}" class="btn-primary">Enter data</a>
            </div>
        </div>
    </div>

    @php($tabQuery = request()->only(['centre', 'period_type', 'anchor', 'start', 'end']))
    <nav class="no-print mb-6 flex gap-3 overflow-x-auto pb-1 text-sm font-semibold" aria-label="Programme sections">
        <a href="{{ route('programmes.show', ['project' => $project, 'section' => 'overview']) }}" class="rounded-xl px-5 py-3 {{ $section === 'overview' ? 'bg-brand-600 text-white shadow-sm' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Overview</a>
        @foreach($sectionCategories as $slug => $category)
            @if($availableCategories->contains($category))
                <a href="{{ route('programmes.show', ['project' => $project, 'section' => $slug, ...$tabQuery]) }}" class="rounded-xl px-5 py-3 {{ $section === $slug ? 'bg-brand-600 text-white shadow-sm' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">{{ ['Input' => 'Inputs', 'Activity' => 'Activities', 'Output' => 'Outputs', 'Outcome' => 'Outcomes', 'KPI' => 'KPIs'][$category] }}</a>
            @endif
        @endforeach
        @can('submit')<a href="{{ route('programmes.show', ['project' => $project, 'section' => 'evidence', ...$tabQuery]) }}" class="rounded-xl px-5 py-3 {{ $section === 'evidence' ? 'bg-brand-600 text-white shadow-sm' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' }}">Evidence</a>@endcan
    </nav>

    @if($section !== 'overview')
        <form class="no-print card mb-6 grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-4">
            <input type="hidden" name="section" value="{{ $section }}">
            <x-reporting-period-fields :period="$period" :auto-submit="true" />
            <div><label for="centre">Project</label><select id="centre" name="centre" onchange="this.form.submit()" class="mt-1 w-full"><option value="">All programme projects</option>@foreach($project->centres as $centre)<option value="{{ $centre->id }}" @selected(request('centre') == $centre->id)>{{ $centre->name }}</option>@endforeach</select></div>
            @if(array_key_exists($section, $sectionCategories))
                <div><label for="q">Search {{ strtolower($sectionCategories[$section]) }} indicators</label><input id="q" name="q" value="{{ request('q') }}" placeholder="Name or code" class="mt-1 w-full"></div>
                <div class="flex items-end"><button class="btn-secondary">Search</button></div>
            @endif
        </form>
    @endif

    @if($section !== 'overview' && $period['warning'])<div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $period['warning'] }}</div>@endif

    @if($section === 'overview')
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @foreach([
                ['Assigned projects', $project->centres->count()],
                ['Required records · '.$period['start']->format('M Y'), $completeness['expected']],
                ['Reporting completion · '.$period['start']->format('M Y'), $completeness['rate'].'%'],
                ['Pending approval · '.$period['start']->format('M Y'), $completeness['pending']],
            ] as [$label, $value])
                <div class="card p-4"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-1 text-2xl font-bold text-ink">{{ $value }}</p></div>
            @endforeach
        </div>
        <p class="mb-6 text-sm text-slate-500">Reporting completion uses the latest month with programme data: required indicator records entered, including drafts, divided by all required records due for that month. Approval progress is tracked separately.</p>
        <section class="card p-6">
            <div class="flex items-center justify-between gap-4"><div><h2 class="font-bold text-ink">Projects</h2><p class="text-sm text-slate-500">Projects assigned to this programme and available in your access scope.</p></div><span class="badge bg-brand-50 text-brand-700">{{ $project->centres->count() }} projects</span></div>
            <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach($project->centres as $centre)
                    @php($centreUrl = $firstIndicatorSection ? route('programmes.show', ['project' => $project, 'section' => $firstIndicatorSection, 'centre' => $centre->id, 'period_type' => $period['type'], 'anchor' => $period['start']->format('Y-m')]) : route('projects.show', ['centre' => $centre, 'project' => $project->id]))
                    <a href="{{ $centreUrl }}" class="block rounded-lg border border-slate-100 p-3 hover:border-brand-200 hover:bg-brand-50"><p class="text-sm font-semibold text-slate-800">{{ $centre->name }}</p><p class="mt-1 text-xs text-slate-500">{{ $centre->type }} · {{ $centre->region }}</p><p class="mt-2 text-xs font-semibold text-brand-700">{{ $firstIndicatorSection ? 'View indicators for this project' : 'View project' }}</p></a>
                @endforeach
            </div>
        </section>
    @elseif(array_key_exists($section, $sectionCategories))
        <section class="card p-6">
            <h2 class="font-bold text-ink">{{ $sectionCategories[$section] === 'KPI' ? 'Key performance indicators' : $sectionCategories[$section].' indicators' }}</h2>
            @php($selectedCentre = $project->centres->firstWhere('id', (int) request('centre')))
            <p class="mt-1 text-sm text-slate-500">{{ $selectedCentre ? 'Showing indicators assigned to '.$selectedCentre->name.'.' : 'Showing consolidated information across all programme projects.' }}</p>
            <div class="mt-4 divide-y divide-slate-100">
                @forelse($indicators as $indicator)
                    @php($row = $rows->first(fn ($candidate) => $candidate['indicator']->id === $indicator->id))
                    @php($targetSummary = $targetSummaries->get($indicator->id, ['value' => null, 'display' => 'Not set']))
                    <a href="{{ route('kpis.show', [$project, $indicator, 'centre' => request('centre')]) }}" class="flex items-center justify-between gap-3 rounded-lg px-2 py-4 hover:bg-slate-50">
                        <div><p class="text-sm font-semibold text-slate-800">{{ $indicator->name }}</p><p class="text-xs text-slate-500">{{ $indicator->unit }} · {{ $indicator->time_aggregation }} over time · {{ $indicator->assignments_count }} projects</p></div>
                        <div class="text-right">
                            @if($row)
                                <p class="text-sm font-bold text-ink">Actual: {{ is_numeric($row['achievement']) ? number_format($row['achievement'], $indicator->precision).' '.$indicator->unit : 'Not aggregatable' }}</p>
                                <p class="text-xs text-slate-500">Target: {{ $row['target_display'] }}{{ $row['target'] !== null ? ' '.$indicator->unit : '' }} · {{ $row['performance_status'] }}</p>
                            @else
                                <p class="text-sm font-semibold text-slate-500">No approved result</p>
                                <p class="text-xs text-slate-500">Target: {{ $targetSummary['display'] }}{{ $targetSummary['value'] !== null ? ' '.$indicator->unit : '' }}</p>
                            @endif
                        </div>
                    </a>
                @empty<p class="py-8 text-center text-sm text-slate-500">No indicators match the filters.</p>@endforelse
            </div>
        </section>
    @elseif($section === 'evidence')
        <section class="card p-6">
            <h2 class="font-bold text-ink">Evidence files</h2><p class="mt-1 text-sm text-slate-500">Authorized proof attached during the selected reporting period.</p>
            <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @forelse($evidence as $file)
                    <article class="rounded-lg border border-slate-200 p-4"><p class="truncate text-sm font-semibold text-slate-800">{{ $file->original_name }}</p><p class="mt-1 text-xs text-slate-500">{{ $file->revision->entry->assignment->indicator->name }}</p><p class="text-xs text-slate-500">{{ $file->revision->entry->assignment->centre->name }} · {{ $file->revision->entry->period->format('M Y') }}</p><p class="mt-2 text-xs text-slate-400">Uploaded by {{ $file->user->name }} on {{ $file->created_at->format('d M Y') }}</p><div class="mt-3 flex gap-3 text-xs font-semibold">@if(str_starts_with($file->mime_type, 'image/') || $file->mime_type === 'application/pdf')<a class="text-brand-700" href="{{ route('evidence.preview', $file) }}" target="_blank">Preview</a>@endif<a class="text-brand-700" href="{{ route('evidence.download', $file) }}">Download</a></div></article>
                @empty<p class="py-8 text-sm text-slate-500">No evidence files match this programme, project, and reporting period.</p>@endforelse
            </div>
            <div class="mt-6">{{ $evidence->links() }}</div>
        </section>
    @endif
</x-layouts.app>
