<x-layouts.app heading="Project sync">
    <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-900">
        This page reads the organisation's Projects database and compares it with the reporting projects stored in this application. Matching uses the permanent source project ID, so a name change is treated as an update. The source database is read-only and is never changed from this page.
    </div>

    @if($connectionError)
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p class="font-semibold">Project comparison is unavailable.</p>
            <p class="mt-1">{{ $connectionError }}</p>
        </div>
    @elseif($preview)
        <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            @foreach([
                ['Source projects', $preview['source'], 'text-slate-900'],
                ['New', $preview['created'], 'text-emerald-700'],
                ['Changed', $preview['updated'], 'text-blue-700'],
                ['Status changes', $preview['status_updates'], 'text-amber-700'],
                ['Unchanged', $preview['unchanged'], 'text-slate-600'],
            ] as [$label, $count, $colour])
                <div class="card p-5">
                    <p class="text-sm font-medium text-slate-500">{{ $label }}</p>
                    <p class="mt-2 text-3xl font-bold {{ $colour }}">{{ $count }}</p>
                </div>
            @endforeach
        </section>

        @php($blockingGroups = [
            'Unmapped source programmes' => $preview['unmapped_source_programmes'],
            'Missing local programmes' => $preview['missing_local_programmes'],
            'Possible duplicate projects' => $preview['potential_duplicates'],
            'Linked projects missing from source' => $preview['missing_from_source'],
        ])

        @if($preview['blocked'])
            <section class="mt-6 rounded-lg border border-red-200 bg-red-50 p-5 text-sm text-red-900">
                <h2 class="font-bold">Synchronization is blocked</h2>
                <p class="mt-1">Resolve the items below before applying changes. No local project record will be changed while the preview is blocked.</p>
                @foreach($blockingGroups as $label => $items)
                    @if($items !== [])
                        <div class="mt-4">
                            <h3 class="font-semibold">{{ $label }}</h3>
                            <ul class="mt-1 list-disc space-y-1 pl-5">
                                @foreach($items as $item)<li>{{ $item }}</li>@endforeach
                            </ul>
                        </div>
                    @endif
                @endforeach
            </section>
        @endif

        @if($preview['donor_conflicts'] !== [])
            <section class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
                <h2 class="font-bold">Donor differences to review</h2>
                <p class="mt-1">These do not block synchronization because reporting donor assignments are maintained separately.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($preview['donor_conflicts'] as $conflict)<li>{{ $conflict }}</li>@endforeach
                </ul>
            </section>
        @endif

        <section class="mt-6">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold text-ink">Detected project changes</h2>
                    <p class="mt-1 text-sm text-slate-500">Review every proposed addition, edit, activation, or deactivation before applying it.</p>
                </div>
                @unless($preview['blocked'])
                    <form method="POST" action="{{ route('administration.project-sync.store') }}" onsubmit="return confirm('Apply the displayed project changes to the local reporting database? The source Projects database will remain read-only.')">
                        @csrf
                        <button class="btn-primary">Apply displayed changes</button>
                    </form>
                @endunless
            </div>

            @if($preview['changes'] === [])
                <div class="card px-6 py-10 text-center">
                    <p class="font-semibold text-slate-700">The project records are already synchronized.</p>
                    <p class="mt-1 text-sm text-slate-500">There are no additions, field changes, activations, or deactivations to apply.</p>
                </div>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Change</th>
                                <th>Source ID</th>
                                <th>Project</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($preview['changes'] as $change)
                                <tr>
                                    <td><span class="badge bg-slate-100 text-slate-700">{{ $change['type'] }}</span></td>
                                    <td class="font-mono text-xs">{{ $change['source_id'] }}</td>
                                    <td class="font-semibold text-slate-900">{{ $change['project'] }}</td>
                                    <td>
                                        <ul class="space-y-2 text-sm">
                                            @foreach($change['details'] as $detail)
                                                <li>
                                                    <span class="font-semibold text-slate-700">{{ $detail['field'] }}:</span>
                                                    <span class="text-red-700">{{ $detail['before'] }}</span>
                                                    <span aria-hidden="true">→</span>
                                                    <span class="text-emerald-700">{{ $detail['after'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    <section class="mt-8">
        <div class="mb-3">
            <h2 class="text-lg font-bold text-ink">Synchronization history</h2>
            <p class="mt-1 text-sm text-slate-500">The last 20 attempts are retained for handover and audit review.</p>
        </div>
        @if($runs->isEmpty())
            <div class="card px-6 py-8 text-center text-sm text-slate-500">No project synchronization has been applied yet.</div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Started</th><th>User</th><th>Status</th><th>Result</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach($runs as $run)
                            @php($applied = $run->summary['applied'] ?? [])
                            <tr>
                                <td>{{ $run->started_at?->format('d M Y, h:i A') }}</td>
                                <td>{{ $run->user?->name ?? 'Command line' }}</td>
                                <td><span class="badge bg-slate-100 text-slate-700">{{ Str::headline($run->status) }}</span></td>
                                <td>
                                    @if($applied !== [])
                                        {{ $applied['created'] ?? 0 }} added, {{ $applied['updated'] ?? 0 }} updated, {{ $applied['status_updates'] ?? 0 }} status changes
                                    @elseif($run->error)
                                        {{ Str::limit($run->error, 180) }}
                                    @else
                                        Preview saved; no local changes applied
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</x-layouts.app>
