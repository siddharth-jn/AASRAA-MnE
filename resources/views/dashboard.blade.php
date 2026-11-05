<x-layouts.app heading="Organization dashboard">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <a href="{{ route('programmes.index') }}" class="card group p-5 hover:border-brand-200 hover:shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div><p class="text-sm font-medium text-slate-500">Active programmes</p><p class="mt-2 text-3xl font-bold text-ink">{{ $stats['projects'] }}</p><p class="mt-2 text-xs text-slate-400">View the programme portfolio</p></div>
                <span class="text-xl text-slate-300 transition group-hover:translate-x-1 group-hover:text-brand-600">→</span>
            </div>
        </a>
        <a href="{{ route('projects.index') }}" class="card group p-5 hover:border-brand-200 hover:shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div><p class="text-sm font-medium text-slate-500">Active projects</p><p class="mt-2 text-3xl font-bold text-ink">{{ $stats['centres'] }}</p><p class="mt-2 text-xs text-slate-400">View all reporting projects</p></div>
                <span class="text-xl text-slate-300 transition group-hover:translate-x-1 group-hover:text-brand-600">→</span>
            </div>
        </a>
        @can('review')
            <a href="{{ route('approvals.index') }}" class="card group p-5 hover:border-brand-200 hover:shadow-sm">
                <div class="flex items-start justify-between gap-4">
                    <div><p class="text-sm font-medium text-slate-500">Pending approvals</p><p class="mt-2 text-3xl font-bold text-ink">{{ $stats['pending'] }}</p><p class="mt-2 text-xs text-slate-400">Review submitted records</p></div>
                    <span class="text-xl text-slate-300 transition group-hover:translate-x-1 group-hover:text-brand-600">→</span>
                </div>
            </a>
        @elsecan('submit')
            <a href="{{ route('entries.index', ['entry_status' => 'unfilled']) }}" class="card group p-5 hover:border-brand-200 hover:shadow-sm">
                <div class="flex items-start justify-between gap-4"><div><p class="text-sm font-medium text-slate-500">Missing this month</p><p class="mt-2 text-3xl font-bold text-ink">{{ $responsibilities['missing'] }}</p><p class="mt-2 text-xs text-slate-400">{{ $responsibilities['returned'] }} returned or require re-entry</p></div><span class="text-xl text-slate-300 transition group-hover:translate-x-1 group-hover:text-brand-600">→</span></div>
            </a>
        @else
            <a href="{{ route('reports.index') }}" class="card group p-5 hover:border-brand-200 hover:shadow-sm">
                <div class="flex items-start justify-between gap-4"><div><p class="text-sm font-medium text-slate-500">Official results</p><p class="mt-2 text-3xl font-bold text-ink">{{ $stats['approved'] }}</p><p class="mt-2 text-xs text-slate-400">Final-approved records in your scope</p></div><span class="text-xl text-slate-300 transition group-hover:translate-x-1 group-hover:text-brand-600">→</span></div>
            </a>
        @endcan
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="card p-6">
            @can('submit')
                <div class="mb-5 flex items-center justify-between"><div><h2 class="font-bold text-ink">Recent submissions</h2><p class="text-sm text-slate-500">Latest activity across your assigned scope</p></div><a href="{{ route('entries.index') }}" class="text-sm font-semibold text-brand-700">View all</a></div>
            @else
                <div class="mb-5 flex items-center justify-between"><div><h2 class="font-bold text-ink">Recent official results</h2><p class="text-sm text-slate-500">Final-approved results in your scope</p></div><a href="{{ route('reports.index') }}" class="text-sm font-semibold text-brand-700">View reports</a></div>
            @endcan
            <div class="space-y-1">
                @forelse(Gate::allows('submit') ? $recentEntries : $recentApproved as $entry)
                    @can('submit')
                        <a href="{{ route('entries.edit', ['assignment' => $entry->assignment, 'period' => $entry->period->format('Y-m'), 'period_type' => 'monthly']) }}" class="flex items-center justify-between gap-4 rounded-lg p-3 hover:bg-slate-50"><div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-800">{{ $entry->assignment->indicator->name }}</p><p class="truncate text-xs text-slate-500">{{ $entry->assignment->indicator->project->name }} · {{ $entry->assignment->centre?->name ?? 'Programme level' }} · {{ $entry->periodLabel() }}</p></div><span class="badge {{ $entry->status === \App\Support\WorkflowStatus::FinalApproved ? 'bg-emerald-100 text-emerald-700' : (in_array($entry->status, \App\Support\WorkflowStatus::pending(), true) ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700') }}">{{ \App\Support\WorkflowStatus::label($entry->status) }}</span></a>
                    @else
                        <div class="flex items-center justify-between gap-4 rounded-lg p-3"><div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-800">{{ $entry->assignment->indicator->name }}</p><p class="truncate text-xs text-slate-500">{{ $entry->assignment->indicator->project->name }} · {{ $entry->assignment->centre?->name ?? 'Programme level' }} · {{ $entry->periodLabel() }}</p></div><span class="badge bg-emerald-100 text-emerald-700">Final approved</span></div>
                    @endcan
                @empty<p class="py-8 text-center text-sm text-slate-500">No submissions are available.</p>@endforelse
            </div>
        </section>
        <section class="card p-6">
            <div class="mb-5 flex items-center justify-between gap-4">
                <div><h2 class="font-bold text-ink">Recently approved</h2><p class="text-sm text-slate-500">Results most recently added to official reporting</p></div>
                @can('review')<a href="{{ route('approvals.index', ['tab' => 'approved']) }}" class="text-sm font-semibold text-brand-700">View all</a>@endcan
            </div>
            <div class="space-y-3">
                @forelse($recentApproved as $entry)
                    @can('review')
                        <a href="{{ route('approvals.index', ['tab' => 'approved', 'programme' => $entry->assignment->indicator->project_id, 'project' => $entry->assignment->centre_id, 'indicator' => $entry->assignment->indicator_definition_id]) }}" class="block rounded-lg border border-slate-100 p-3 hover:border-brand-200 hover:bg-slate-50"><p class="text-sm font-semibold text-slate-800">{{ $entry->assignment->indicator->name }} · {{ $entry->approvedRevision->displayValue() }}</p><p class="mt-1 text-xs text-slate-500">{{ $entry->assignment->indicator->project->name }} · {{ $entry->assignment->centre?->name ?? 'Programme level' }} · {{ $entry->periodLabel() }}</p></a>
                    @else
                        <div class="rounded-lg border border-slate-100 p-3"><p class="text-sm font-semibold text-slate-800">{{ $entry->assignment->indicator->name }} · {{ $entry->approvedRevision->displayValue() }}</p><p class="mt-1 text-xs text-slate-500">{{ $entry->assignment->indicator->project->name }} · {{ $entry->assignment->centre?->name ?? 'Programme level' }} · {{ $entry->periodLabel() }}</p></div>
                    @endcan
                @empty<p class="py-8 text-center text-sm text-slate-500">No approved results are available.</p>@endforelse
            </div>
        </section>
    </div>
</x-layouts.app>
