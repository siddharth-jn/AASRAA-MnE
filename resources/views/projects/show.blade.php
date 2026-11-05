<x-layouts.app :heading="$project->name">
    <div class="card mb-6 p-6">
        <div class="flex flex-col justify-between gap-4 md:flex-row">
            <div>
                <div class="mb-3 flex flex-wrap items-center gap-2">
                    <span class="badge bg-brand-50 text-brand-700">{{ $project->active ? 'Active programme' : 'Archived' }}</span>
                    <span class="badge bg-slate-100 text-slate-700">{{ $project->centres->count() }} assigned {{ \Illuminate\Support\Str::plural('project', $project->centres->count()) }}</span>
                    @if($project->is_demo)<span class="badge bg-amber-100 text-amber-700">Demo configuration</span>@endif
                </div>
                <p class="max-w-3xl leading-7 text-slate-600">{{ $project->description }}</p>
                <p class="mt-3 text-sm text-slate-500">Programme code: <strong class="text-slate-700">{{ $project->code }}</strong> · Programme lead: <strong class="text-slate-700">{{ $project->lead?->name ?? 'Not assigned' }}</strong></p>
            </div>
        </div>
    </div>

    <section class="card mb-6 p-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="font-bold text-ink">Assigned projects</h2>
                <p class="mt-1 text-sm text-slate-500">Projects within this programme that are available in your access scope.</p>
            </div>
            <span class="badge bg-brand-50 text-brand-700">{{ $project->centres->count() }}</span>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            @forelse($project->centres as $centre)
                <a href="{{ route('projects.show', ['centre' => $centre, 'project' => $project->id]) }}" class="block rounded-lg border border-slate-100 p-3 hover:border-brand-200 hover:bg-brand-50">
                    <p class="text-sm font-semibold text-slate-800">{{ $centre->name }}</p>
                    <p class="mt-1 text-xs text-slate-500">{{ $centre->type }} · Lead: {{ $centre->lead?->name ?? 'Not assigned' }}</p>
                </a>
            @empty
                <p class="text-sm text-slate-500">No assigned projects are available in your access scope.</p>
            @endforelse
        </div>
    </section>

    <section class="card p-6">
        <div>
            <h2 class="font-bold text-ink">Indicators</h2>
            <p class="mt-1 text-sm text-slate-500">The active indicators configured for this programme. Results and performance calculations are available in Reports.</p>
        </div>

        <div class="mt-6 space-y-7">
            @foreach($categoryLabels as $category => $label)
                @php($categoryIndicators = $indicators->get($category, collect()))
                @if($categoryIndicators->isNotEmpty())
                    <div>
                        <div class="mb-2 flex items-center gap-3">
                            <h3 class="text-sm font-bold uppercase tracking-wide text-brand-700">{{ $label }}</h3>
                            <span class="badge bg-slate-100 text-slate-600">{{ $categoryIndicators->count() }}</span>
                        </div>
                        <div class="divide-y divide-slate-100 rounded-lg border border-slate-100">
                            @foreach($categoryIndicators as $indicator)
                                <div class="flex flex-col justify-between gap-3 px-4 py-4 sm:flex-row sm:items-center">
                                    <div>
                                        <p class="text-sm font-semibold text-slate-800">{{ $indicator->name }}</p>
                                        <p class="mt-1 text-xs text-slate-500">Code: {{ $indicator->code }} · Unit: {{ $indicator->unit }}</p>
                                    </div>
                                    <div class="flex flex-wrap gap-2 text-xs">
                                        <span class="badge bg-brand-50 text-brand-700">{{ $indicator->isProgrammeScoped() ? 'Programme level' : 'Project level' }}</span>
                                        <span class="badge bg-slate-100 text-slate-700">{{ $indicator->assigned_projects_count }} assigned {{ \Illuminate\Support\Str::plural('project', $indicator->assigned_projects_count) }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            @endforeach

            @if($indicators->isEmpty())
                <p class="py-8 text-center text-sm text-slate-500">No active indicators are assigned within your access scope.</p>
            @endif
        </div>
    </section>
</x-layouts.app>
