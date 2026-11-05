<x-layouts.app :heading="$centre->name">
    <form class="no-print card mb-5 p-5 sm:max-w-sm">
        @if($selectedProjectId)<input type="hidden" name="project" value="{{ $selectedProjectId }}">@endif
        <div>
            <label for="anchor">Month</label>
            <input id="anchor" name="anchor" type="month" value="{{ $period['start']->format('Y-m') }}" max="{{ now()->format('Y-m') }}" onchange="this.form.submit()" class="mt-1 w-full">
        </div>
    </form>

    @if($period['warning'])<div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $period['warning'] }}</div>@endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([['Project type', $centre->type], ['Project lead', $centre->lead?->name ?? 'Not assigned'], ['Assigned staff', $centre->users->count()], ['Reporting completion · '.$period['start']->format('M Y'), $completion['filled'].' of '.$completion['expected'].' '.\Illuminate\Support\Str::plural('indicator', $completion['expected']).' filled']] as [$label, $value])
            <div class="card p-5"><p class="text-xs font-semibold uppercase text-slate-400">{{ $label }}</p><p class="mt-2 font-bold text-ink">{{ $value }}</p></div>
        @endforeach
    </div>

    <section class="card mt-6 overflow-hidden">
        <div class="border-b border-slate-100 p-5">
            <h2 class="font-bold text-ink">Indicators · {{ $period['start']->format('F Y') }}</h2>
            <p class="mt-1 text-sm text-slate-500">All indicators assigned to this project are shown. Data entry is available only when an active target exists for the selected month.</p>
        </div>
        <div class="table-wrap border-0"><table class="data-table"><thead><tr><th>Indicator</th><th>Category</th><th>Target</th><th>Reported value</th><th>Status</th><th></th></tr></thead><tbody class="divide-y divide-slate-100">
            @forelse($indicatorRows as $row)
                @php($assignment = $row['assignment'])
                @php($entry = $row['entry'])
                @php($target = $row['target'])
                <tr>
                    <td><p class="font-semibold text-slate-800">{{ $assignment->indicator->name }}</p><p class="text-xs text-slate-500">{{ $assignment->indicator->code }}</p></td>
                    <td>{{ $assignment->indicator->category }}</td>
                    <td>{{ $target ? $row['target_display'] : '—' }}@if($target && $assignment->indicator->unit)<span class="text-xs text-slate-500"> {{ $assignment->indicator->unit }}</span>@endif</td>
                    <td>{{ $row['revision']?->displayValue() ?? '—' }}@if($row['revision'] && $assignment->indicator->unit)<span class="text-xs text-slate-500"> {{ $assignment->indicator->unit }}</span>@endif</td>
                    <td>
                        @if(!$target)
                            <span class="badge bg-slate-100 text-slate-600">No target set</span>
                        @elseif(!$entry)
                            <span class="badge bg-amber-100 text-amber-700">Not filled</span>
                        @else
                            <span class="badge {{ $entry->status === \App\Support\WorkflowStatus::FinalApproved ? 'bg-emerald-100 text-emerald-700' : (in_array($entry->status, \App\Support\WorkflowStatus::pending(), true) ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-700') }}">{{ \App\Support\WorkflowStatus::label($entry->status) }}</span>
                        @endif
                    </td>
                    <td>
                        @if($target && $canSubmit)
                            <a class="text-sm font-semibold text-brand-700" href="{{ route('entries.edit', ['assignment' => $assignment, 'period' => $period['start']->format('Y-m'), 'project' => $selectedProjectId, 'centre' => $centre->id]) }}">Open</a>
                        @else
                            <span class="text-slate-300">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-10 text-center text-slate-500">No indicators are assigned to this project.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>

</x-layouts.app>
