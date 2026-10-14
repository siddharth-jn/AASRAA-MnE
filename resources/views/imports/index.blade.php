<x-layouts.app heading="Imports">
    <div class="grid gap-6 xl:grid-cols-3">
        <section class="card p-6 xl:col-span-2">
            <h2 class="font-bold text-ink">Upload historical data</h2>
            <p class="mt-1 text-sm text-slate-500">CSV and XLSX files are staged for mapping and validation. Nothing is saved as reporting data until you confirm the batch.</p>
            <form method="POST" action="{{ route('imports.upload') }}" enctype="multipart/form-data" class="mt-5 grid gap-4 md:grid-cols-3">
                @csrf
                <div><label for="project_id">Project</label><select id="project_id" name="project_id" class="mt-1 w-full" required><option value="">Select project</option>@foreach($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach</select></div>
                <div><label for="file">Spreadsheet</label><input id="file" name="file" type="file" accept=".csv,.xlsx" class="mt-1 block w-full" required></div>
                <div class="flex items-end"><button class="btn-primary">Upload and preview</button></div>
            </form>
        </section>
        <section class="card p-6"><h2 class="font-bold text-ink">Templates</h2><p class="mt-1 text-sm text-slate-500">Templates include centre and indicator reference codes.</p><div class="mt-4 max-h-56 space-y-2 overflow-y-auto">@foreach($projects as $project)<div class="rounded-lg border border-slate-100 p-3"><p class="text-sm font-semibold text-slate-800">{{ $project->name }}</p><div class="mt-2 flex gap-3 text-xs font-semibold"><a class="text-brand-700" href="{{ route('imports.template', [$project, 'format' => 'xlsx']) }}">XLSX</a><a class="text-brand-700" href="{{ route('imports.template', [$project, 'format' => 'csv']) }}">CSV</a></div></div>@endforeach</div></section>
    </div>

    <section class="mt-6">
        <div class="mb-3"><h2 class="font-bold text-ink">Import history</h2><p class="text-sm text-slate-500">Uploaded files, validation state, and row counts.</p></div>
        <div class="table-wrap"><table class="data-table"><thead><tr><th>Batch</th><th>Project</th><th>File</th><th>Uploaded by</th><th>Rows</th><th>Status</th><th>Date</th></tr></thead><tbody class="divide-y divide-slate-100">
            @forelse($batches as $batch)<tr><td><a class="font-semibold text-brand-700" href="{{ route('imports.show', $batch) }}">#{{ $batch->id }}</a></td><td>{{ $batch->project->name }}</td><td>{{ $batch->filename }}</td><td>{{ $batch->user->name }}</td><td>{{ $batch->rows_count }}</td><td><span class="badge bg-slate-100 text-slate-700">{{ ucfirst($batch->status) }}</span></td><td>{{ $batch->created_at->format('d M Y, H:i') }}</td></tr>@empty<tr><td colspan="7" class="py-10 text-center text-slate-500">No spreadsheet imports have been uploaded.</td></tr>@endforelse
        </tbody></table></div><div class="mt-6">{{ $batches->links() }}</div>
    </section>
</x-layouts.app>
