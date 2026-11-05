<x-layouts.app heading="Preview target import">
    <div class="card mb-5 p-5">
        <p class="font-semibold text-ink">{{ $batch->project?->name ?? 'All programmes' }}{{ $batch->centre ? ' · '.$batch->centre->name : '' }}{{ $donor ? ' · '.$donor : '' }} · FY {{ $batch->financial_year_start }}–{{ substr((string)($batch->financial_year_start + 1), -2) }}</p>
        <p class="mt-1 text-sm text-slate-500">{{ $batch->filename }} · Nothing has changed yet.</p>
    </div>

    <div class="mb-5 grid gap-4 sm:grid-cols-4">
        @foreach(['create' => 'New targets', 'update' => 'Changed targets', 'remove' => 'Removed targets', 'affected' => 'Entries requiring re-entry'] as $key => $label)
            @php($value = $key === 'affected' ? $batch->rows->where('affects_entry', true)->count() : $batch->rows->where('action', $key)->count())
            <div class="card p-4"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-1 text-2xl font-bold text-ink">{{ $value }}</p></div>
        @endforeach
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Programme / indicator</th><th>Project / level</th><th>Month</th><th>Change</th><th>Existing</th><th>Proposed</th><th>Entry impact</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($batch->rows as $row)
                    <tr>
                        <td><p class="font-semibold text-slate-800">{{ $row->assignment->indicator->name }}</p><p class="text-xs text-slate-500">{{ $row->assignment->indicator->project->name }}</p></td>
                        <td>{{ $row->assignment->centre?->name ?? 'Programme level' }}</td>
                        <td>{{ $row->period->format('M Y') }}</td>
                        <td><span class="badge {{ $row->action === 'remove' ? 'bg-rose-100 text-rose-700' : ($row->action === 'update' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-700') }}">{{ ucfirst($row->action) }}</span></td>
                        <td class="text-xs">{{ $row->previous_target ? json_encode(collect($row->previous_target)->except(['notes', 'active'])->filter(fn($value) => $value !== null)->all()) : 'N/A' }}</td>
                        <td class="text-xs">{{ $row->proposed_target ? json_encode(collect($row->proposed_target)->only(['value', 'minimum_value', 'maximum_value', 'target_numerator', 'target_denominator', 'status_value'])->filter(fn($value) => $value !== null)->all()) : 'N/A' }}</td>
                        <td>{{ $row->affects_entry ? 'Achievement will require re-entry' : 'No existing achievement' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="py-10 text-center text-slate-500">No target changes were found. You may safely return without confirming.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6 flex flex-wrap justify-end gap-3">
        <a class="btn-secondary" href="{{ route('targets.index', ['programme' => $batch->project_id ?? 'all', 'project' => $batch->centre_id, 'financial_year' => $batch->financial_year_start, 'donor' => $donor]) }}">Cancel</a>
        @if($batch->status === 'preview' && $batch->rows->isNotEmpty())
            <form method="POST" action="{{ route('targets.imports.confirm', $batch) }}">
                @csrf
                <input type="hidden" name="confirmation" value="1">
                <input type="hidden" name="donor" value="{{ $donor }}">
                <button class="btn-primary">Confirm and apply changes</button>
            </form>
        @endif
    </div>
</x-layouts.app>
