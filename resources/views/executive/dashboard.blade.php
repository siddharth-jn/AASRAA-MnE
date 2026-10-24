<x-layouts.app heading="Executive overview">
    <p class="mb-6 text-sm text-slate-500">A read-only summary of final-approved monitoring results and material variance exceptions.</p>

    <div class="mb-6 grid gap-4 sm:grid-cols-3">
        <div class="card p-5"><p class="text-sm text-slate-500">Approved this month</p><p class="mt-2 text-3xl font-bold text-ink">{{ $stats['approved_this_month'] }}</p></div>
        <div class="card p-5"><p class="text-sm text-slate-500">Variance exceptions</p><p class="mt-2 text-3xl font-bold text-amber-700">{{ $stats['variance_exceptions'] }}</p></div>
        <div class="card p-5"><p class="text-sm text-slate-500">Reporting assignments represented</p><p class="mt-2 text-3xl font-bold text-ink">{{ $stats['programmes_reporting'] }}</p></div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th class="sticky-col">Programme / project</th><th>Indicator</th><th>Period</th><th>Approved value</th><th>Variance</th><th>Explanation</th><th>Approved</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($recentApproved as $entry)
                    <tr>
                        <td class="sticky-col"><p class="font-semibold text-slate-800">{{ $entry->assignment->indicator->project->name }}</p><p class="text-xs text-slate-500">{{ $entry->assignment->centre->name }}</p></td>
                        <td>{{ $entry->assignment->indicator->name }}</td>
                        <td>{{ $entry->periodLabel() }}</td>
                        <td class="font-semibold">{{ $entry->approvedRevision->displayValue() }} {{ $entry->assignment->indicator->unit }}</td>
                        <td>{{ $entry->approvedRevision->variance_percentage === null ? 'N/A' : (($entry->approvedRevision->variance_percentage > 0 ? '+' : '').number_format($entry->approvedRevision->variance_percentage, 1).'%') }}</td>
                        <td class="min-w-64">{{ $entry->approvedRevision->variance_explanation ?: '—' }}</td>
                        <td>{{ $entry->updated_at->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-12 text-center text-slate-500">No final-approved results are available.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-6">{{ $recentApproved->links() }}</div>
</x-layouts.app>
