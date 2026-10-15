<x-layouts.app heading="Approvals">
    <div class="space-y-5">
        @forelse($entries as $entry)
            @php($revision = $entry->currentRevision)
            @php($target = $entry->assignment->targets->first(fn ($candidate) => $candidate->active && $candidate->period->lte($entry->period) && $candidate->period_end?->gte($entry->period)))
            <article class="card p-5">
                <div class="grid gap-5 sm:grid-cols-2 xl:grid-cols-5">
                    <div class="xl:col-span-2">
                        <p class="text-xs font-semibold uppercase text-slate-400">Indicator</p>
                        <p class="mt-1 font-semibold text-ink">{{ $entry->assignment->indicator->name }}</p>
                        <p class="text-xs text-slate-500">{{ $entry->assignment->indicator->project->name }} · {{ $entry->period->format('F Y') }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-400">Project</p>
                        <p class="mt-1 text-sm">{{ $entry->assignment->centre->name }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-400">Achievement / target</p>
                        <p class="mt-1 text-xl font-bold text-ink">{{ $revision->displayValue() }} <small class="text-sm font-normal text-slate-500">{{ $entry->assignment->indicator->unit }}</small></p>
                        <p class="text-xs text-slate-500">Target: {{ $target?->value !== null ? number_format($target->value, $entry->assignment->indicator->precision) : ($target?->status_value ?? 'Not set') }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase text-slate-400">Submitted by</p>
                        <p class="mt-1 text-sm">{{ $revision->user->name }}</p>
                        <p class="text-xs text-slate-500">{{ $revision->submitted_at?->format('d M Y, H:i') }}</p>
                    </div>
                </div>

                @if($revision->notes)
                    <p class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600"><strong>Submitter note:</strong> {{ $revision->notes }}</p>
                @endif

                @if($revision->evidence->isNotEmpty())
                    <div class="mt-4">
                        <p class="text-sm font-semibold text-slate-800">Supporting evidence</p>
                        <div class="mt-2 flex flex-wrap gap-3">
                            @foreach($revision->evidence as $file)
                                <div class="rounded-lg border border-slate-200 bg-slate-50 p-2">
                                    @if(str_starts_with($file->mime_type, 'image/'))
                                        <a href="{{ route('evidence.preview', $file) }}" target="_blank"><img src="{{ route('evidence.preview', $file) }}" alt="{{ $file->original_name }}" loading="lazy" class="mb-2 h-24 w-32 rounded object-cover"></a>
                                    @elseif($file->mime_type === 'application/pdf')
                                        <a href="{{ route('evidence.preview', $file) }}" target="_blank" class="mb-2 grid h-24 w-32 place-items-center rounded bg-red-50 text-xs font-bold text-red-700">PDF preview</a>
                                    @endif
                                    <a class="block max-w-40 truncate text-xs font-semibold text-brand-700" href="{{ route('evidence.download', $file) }}">{{ $file->original_name }}</a>
                                    <p class="mt-1 text-[11px] text-slate-400">{{ number_format($file->size / 1024, 1) }} KB</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @elseif($entry->assignment->indicator->evidence_required)
                    <p class="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">Required evidence is missing from this revision.</p>
                @endif

                @if($entry->revisions->count() > 1)
                    <details class="mt-4 rounded-lg border border-slate-200 p-4">
                        <summary class="cursor-pointer text-sm font-semibold text-slate-700">Previous revisions and review history ({{ $entry->revisions->count() - 1 }})</summary>
                        <div class="mt-3 space-y-3">
                            @foreach($entry->revisions->where('id', '!=', $revision->id)->sortByDesc('version') as $previous)
                                <div class="border-t border-slate-100 pt-3 text-sm">
                                    <p><strong>Revision {{ $previous->version }}:</strong> {{ $previous->displayValue() }} · {{ $previous->status }} · {{ $previous->user->name }}</p>
                                    @foreach($previous->actions as $decision)
                                        <p class="mt-1 text-slate-500">{{ $decision->action }} by {{ $decision->user->name }}@if($decision->comment): {{ $decision->comment }}@endif</p>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif

                <form method="POST" action="{{ route('approvals.review', $entry) }}" class="mt-5 flex flex-col gap-3 border-t border-slate-100 pt-5 md:flex-row md:items-end">
                    @csrf
                    <div class="flex-1">
                        <label for="comment-{{ $entry->id }}">Review comment</label>
                        <input id="comment-{{ $entry->id }}" name="comment" class="mt-1 w-full" placeholder="Required when returning or rejecting">
                        @if($entry->assignment->indicator->evidence_review_required)
                            <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" name="evidence_reviewed" value="1"> I confirm that I reviewed all supporting evidence</label>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button name="action" value="APPROVED" class="btn-primary">Approve</button>
                        <button name="action" value="RETURNED" class="btn-secondary">Return for correction</button>
                        <button name="action" value="REJECTED" class="btn-secondary text-red-700">Reject</button>
                    </div>
                </form>
            </article>
        @empty
            <div class="card p-12 text-center">
                <p class="text-lg font-semibold text-ink">Approval queue is clear</p>
                <p class="mt-2 text-sm text-slate-500">New submissions will appear here.</p>
            </div>
        @endforelse
    </div>
    <div class="mt-6">{{ $entries->links() }}</div>
</x-layouts.app>
