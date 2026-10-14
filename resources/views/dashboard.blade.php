<x-layouts.app heading="Organization dashboard">
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <a href="{{ route('projects.index') }}" class="card group p-5 hover:border-brand-200 hover:shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div><p class="text-sm font-medium text-slate-500">Active projects</p><p class="mt-2 text-3xl font-bold text-ink">{{ $stats['projects'] }}</p><p class="mt-2 text-xs text-slate-400">View the project portfolio</p></div>
                <span class="text-xl text-slate-300 transition group-hover:translate-x-1 group-hover:text-brand-600">→</span>
            </div>
        </a>
        <a href="{{ route('centres.index') }}" class="card group p-5 hover:border-brand-200 hover:shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div><p class="text-sm font-medium text-slate-500">Active centres</p><p class="mt-2 text-3xl font-bold text-ink">{{ $stats['centres'] }}</p><p class="mt-2 text-xs text-slate-400">View all operating centres</p></div>
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
        @else
            <div class="card p-5"><p class="text-sm font-medium text-slate-500">Pending approvals</p><p class="mt-2 text-3xl font-bold text-ink">{{ $stats['pending'] }}</p><p class="mt-2 text-xs text-slate-400">Records awaiting reviewer action</p></div>
        @endcan
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <section class="card p-6">
            <div class="mb-5 flex items-center justify-between"><div><h2 class="font-bold text-ink">Recent submissions</h2><p class="text-sm text-slate-500">Latest activity across your assigned scope</p></div><a href="{{ route('entries.index') }}" class="text-sm font-semibold text-brand-700">View all</a></div>
            <div class="space-y-1">
                @forelse($recentEntries as $entry)
                    <a href="{{ route('entries.edit', ['assignment' => $entry->assignment, 'period' => $entry->period->format('Y-m')]) }}" class="flex items-center justify-between gap-4 rounded-lg p-3 hover:bg-slate-50"><div class="min-w-0"><p class="truncate text-sm font-semibold text-slate-800">{{ $entry->assignment->indicator->name }}</p><p class="truncate text-xs text-slate-500">{{ $entry->assignment->indicator->project->name }} · {{ $entry->assignment->centre->name }} · {{ $entry->period->format('M Y') }}</p></div><span class="badge {{ $entry->status === 'APPROVED' ? 'bg-emerald-100 text-emerald-700' : ($entry->status === 'SUBMITTED' ? 'bg-blue-100 text-blue-700' : 'bg-amber-100 text-amber-700') }}">{{ $entry->status }}</span></a>
                @empty<p class="py-8 text-center text-sm text-slate-500">No submissions are available.</p>@endforelse
            </div>
        </section>
        <section class="card p-6">
            <h2 class="font-bold text-ink">Recently approved</h2><p class="mb-5 text-sm text-slate-500">Results most recently added to official reporting</p>
            <div class="space-y-3">
                @forelse($recentApproved as $entry)
                    <div class="rounded-lg border border-slate-100 p-3"><p class="text-sm font-semibold text-slate-800">{{ $entry->assignment->indicator->name }} · {{ $entry->approvedRevision->displayValue() }}</p><p class="mt-1 text-xs text-slate-500">{{ $entry->assignment->indicator->project->name }} · {{ $entry->assignment->centre->name }} · {{ $entry->period->format('M Y') }}</p></div>
                @empty<p class="py-8 text-center text-sm text-slate-500">No approved results are available.</p>@endforelse
            </div>
        </section>
    </div>
</x-layouts.app>
