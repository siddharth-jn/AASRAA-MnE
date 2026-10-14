<x-layouts.app :heading="'Import batch #'.$batch->id">
    <div class="mb-5"><a href="{{ route('imports.index') }}" class="text-sm font-semibold text-brand-700">← Import history</a></div>
    <div class="card mb-6 p-6"><div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><div class="xl:col-span-2"><p class="text-xs font-semibold uppercase text-slate-400">File</p><p class="mt-1 font-semibold text-ink">{{ $batch->filename }}</p><p class="text-xs text-slate-500">{{ $batch->project->name }}</p></div><div><p class="text-xs font-semibold uppercase text-slate-400">Uploaded by</p><p class="mt-1 text-sm">{{ $batch->user->name }}</p></div><div><p class="text-xs font-semibold uppercase text-slate-400">Rows</p><p class="mt-1 text-2xl font-bold text-ink">{{ $batch->rows_count }}</p></div><div><p class="text-xs font-semibold uppercase text-slate-400">Status</p><span class="mt-1 badge bg-slate-100 text-slate-700">{{ ucfirst($batch->status) }}</span></div></div></div>

    @if($batch->status !== 'completed')
        <form method="POST" action="{{ route('imports.validate', $batch) }}" class="card mb-6 p-6">
            @csrf
            <h2 class="font-bold text-ink">Map source columns</h2><p class="mt-1 text-sm text-slate-500">Centre and indicator values may use an internal code or exact configured name.</p>
            <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach(['centre' => 'Centre code or name', 'indicator' => 'Indicator code or name', 'period' => 'Reporting period', 'value' => 'Value', 'numerator' => 'Numerator', 'denominator' => 'Denominator', 'notes' => 'Notes'] as $field => $label)
                    <div><label for="mapping-{{ $field }}">{{ $label }} {{ in_array($field, ['centre', 'indicator', 'period'], true) ? '*' : '' }}</label><select id="mapping-{{ $field }}" name="mapping[{{ $field }}]" class="mt-1 w-full"><option value="">Not mapped</option>@foreach($batch->columns as $column)<option value="{{ $column }}" @selected(($batch->mapping[$field] ?? null) === $column || strcasecmp(str_replace('_', ' ', $column), str_replace('_', ' ', $field)) === 0)>{{ $column }}</option>@endforeach</select></div>
                @endforeach
            </div>
            <div class="mt-5 flex justify-end"><button class="btn-primary">Validate rows</button></div>
        </form>
    @endif

    @if(in_array($batch->status, ['validated', 'completed'], true))
        <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            @foreach([['Valid', $batch->valid_rows_count, 'emerald'], ['Needs evidence', $batch->review_rows_count, 'amber'], ['Invalid', $batch->invalid_rows_count, 'red'], ['Duplicates', $batch->duplicate_rows_count, 'slate'], ['Imported', $batch->imported_rows_count, 'blue']] as [$label, $value, $colour])
                <div class="card p-4"><p class="text-sm text-slate-500">{{ $label }}</p><p class="mt-1 text-2xl font-bold text-ink">{{ $value }}</p></div>
            @endforeach
        </div>
        @if($batch->status === 'validated' && ($batch->valid_rows_count + $batch->review_rows_count) > 0)
            <form method="POST" action="{{ route('imports.confirm', $batch) }}" class="no-print mb-6 flex justify-end">@csrf<button class="btn-primary">Confirm import as drafts</button></form>
        @endif
    @endif

    <div class="table-wrap"><table class="data-table"><thead><tr><th>Row</th>@foreach($batch->columns as $column)<th>{{ $column }}</th>@endforeach<th>Status</th><th>Validation</th></tr></thead><tbody class="divide-y divide-slate-100">
        @foreach($rows as $row)<tr><td>{{ $row->row_number }}</td>@foreach($batch->columns as $column)<td>{{ is_array($row->source[$column] ?? null) ? json_encode($row->source[$column]) : ($row->source[$column] ?? '') }}</td>@endforeach<td><span class="badge {{ $row->status === 'valid' || $row->status === 'imported' ? 'bg-emerald-100 text-emerald-700' : ($row->status === 'invalid' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ ucfirst($row->status) }}</span></td><td class="max-w-xs text-xs text-slate-500">{{ $row->error ?: 'Ready' }}</td></tr>@endforeach
    </tbody></table></div><div class="mt-6">{{ $rows->links() }}</div>
</x-layouts.app>
