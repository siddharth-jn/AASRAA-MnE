<x-layouts.app heading="Programmes">
    <div class="mb-6"><p class="text-sm text-slate-500">Programmes available in your reporting scope.</p></div>
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach($projects as $project)
            <a href="{{ route('programmes.show', $project) }}" class="card p-5 transition hover:-translate-y-0.5 hover:border-brand-500 hover:shadow-md">
                <div class="flex items-start justify-between gap-4"><div class="grid h-10 w-10 place-items-center rounded-lg bg-brand-50 font-bold text-brand-700">{{ str_pad($project->id, 2, '0', STR_PAD_LEFT) }}</div>@if($project->is_demo)<span class="badge bg-amber-100 text-amber-700">DEMO</span>@endif</div>
                <h2 class="mt-5 font-bold text-ink">{{ $project->name }}</h2>
                <p class="mt-2 line-clamp-2 text-sm leading-6 text-slate-500">{{ $project->description }}</p>
                <div class="mt-5 border-t border-slate-100 pt-4 text-xs text-slate-500">
                    <p class="mb-3"><strong class="text-slate-800">{{ $project->centres_count }}</strong> projects</p>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <span><strong class="block text-base text-slate-800">{{ $project->input_indicators_count }}</strong>Input</span>
                        <span><strong class="block text-base text-slate-800">{{ $project->activity_indicators_count }}</strong>Activity</span>
                        <span><strong class="block text-base text-slate-800">{{ $project->output_indicators_count }}</strong>Output</span>
                        <span><strong class="block text-base text-slate-800">{{ $project->outcome_indicators_count }}</strong>Outcome</span>
                    </div>
                </div>
            </a>
        @endforeach
    </div>
    <div class="mt-6">{{ $projects->links() }}</div>
</x-layouts.app>
