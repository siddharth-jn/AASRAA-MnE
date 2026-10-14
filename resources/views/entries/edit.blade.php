<x-layouts.app heading="Report an achievement">
    @php($definition = $assignment->indicator)
    @php($revision = $entry?->currentRevision)
    <div class="mx-auto max-w-3xl">
        <div class="card mb-5 p-6">
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold uppercase text-slate-400">Project</p>
                    <p class="mt-1 font-semibold text-ink">{{ $definition->project->name }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase text-slate-400">Centre</p>
                    <p class="mt-1 font-semibold text-ink">{{ $assignment->centre->name }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase text-slate-400">Indicator</p>
                    <p class="mt-1 font-semibold text-ink">{{ $definition->name }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $definition->description }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase text-slate-400">Reporting period</p>
                    <p class="mt-1 font-semibold text-ink">{{ \Carbon\Carbon::parse($period)->format('F Y') }}</p>
                    @if($target)
                        <p class="mt-1 text-sm text-slate-500">
                            Target:
                            @if($target->target_type === 'range')
                                {{ number_format($target->minimum_value, 2) }}–{{ number_format($target->maximum_value, 2) }}
                            @elseif($target->target_type === 'status')
                                {{ $target->status_value }}
                            @else
                                {{ number_format($target->value, $definition->precision) }} {{ $definition->unit }}
                            @endif
                        </p>
                    @endif
                </div>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" action="{{ route('entries.store', $assignment) }}" class="card p-6">
            @csrf
            <input type="hidden" name="period" value="{{ $period }}">

            <div class="mb-6">
                <label for="value_state">Reporting state</label>
                <select id="value_state" name="value_state" class="mt-1 w-full">
                    <option value="reported" @selected(old('value_state', $revision?->value_state) === 'reported')>Reported value</option>
                    <option value="not_applicable" @selected(old('value_state', $revision?->value_state) === 'not_applicable')>Not applicable</option>
                </select>
            </div>

            @if(in_array($definition->data_type, ['ratio', 'calculated'], true))
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="numerator">{{ $definition->numerator_label ?: 'Numerator' }}</label>
                        <input id="numerator" name="numerator" type="number" min="0" step="any" value="{{ old('numerator', $revision?->numerator) }}" class="mt-1 w-full">
                    </div>
                    <div>
                        <label for="denominator">{{ $definition->denominator_label ?: 'Denominator' }}</label>
                        <input id="denominator" name="denominator" type="number" min="0.0001" step="any" value="{{ old('denominator', $revision?->denominator) }}" class="mt-1 w-full">
                    </div>
                </div>
                <p class="mt-2 text-sm text-slate-500">The percentage is calculated securely on submission.</p>
            @elseif(in_array($definition->data_type, ['integer', 'decimal', 'percentage'], true))
                <div>
                    <label for="numeric_value">Achievement ({{ $definition->unit }})</label>
                    <input id="numeric_value" name="numeric_value" type="number"
                           @if($definition->min_value !== null) min="{{ $definition->min_value }}" @endif
                           @if($definition->max_value !== null) max="{{ $definition->max_value }}" @endif
                           step="{{ $definition->data_type === 'integer' ? '1' : 'any' }}"
                           value="{{ old('numeric_value', $revision?->numeric_value) }}" class="mt-1 w-full">
                </div>
            @elseif($definition->data_type === 'boolean')
                <div>
                    <label for="boolean_value">Reported value</label>
                    <select id="boolean_value" name="boolean_value" class="mt-1 w-full">
                        <option value="">Select a value</option>
                        <option value="1" @selected((string) old('boolean_value', $revision?->boolean_value) === '1')>Yes</option>
                        <option value="0" @selected((string) old('boolean_value', $revision?->boolean_value) === '0')>No</option>
                    </select>
                </div>
            @elseif($definition->data_type === 'date')
                <div>
                    <label for="date_value">Reported date</label>
                    <input id="date_value" name="date_value" type="date"
                           @if($definition->minimum_date) min="{{ $definition->minimum_date->toDateString() }}" @endif
                           @if($definition->maximum_date) max="{{ $definition->maximum_date->toDateString() }}" @endif
                           value="{{ old('date_value', $revision?->date_value?->format('Y-m-d')) }}" class="mt-1 w-full">
                </div>
            @elseif(in_array($definition->data_type, ['single_select', 'status'], true))
                <div>
                    <label for="text_value">Reported value</label>
                    <select id="text_value" name="text_value" class="mt-1 w-full">
                        <option value="">Select a value</option>
                        @foreach($definition->options ?: [] as $option)
                            <option value="{{ $option }}" @selected(old('text_value', $revision?->text_value) === $option)>{{ $option }}</option>
                        @endforeach
                    </select>
                </div>
            @elseif($definition->data_type === 'multi_select')
                <fieldset>
                    <legend class="font-medium">Reported values</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach($definition->options ?: [] as $option)
                            <label class="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2">
                                <input type="checkbox" name="json_value[]" value="{{ $option }}" @checked(in_array($option, old('json_value', $revision?->json_value ?: []), true))>
                                <span>{{ $option }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
            @else
                <div>
                    <label for="text_value">Reported value</label>
                    <textarea id="text_value" name="text_value" rows="{{ $definition->data_type === 'long_text' ? 6 : 3 }}" maxlength="{{ $definition->max_length }}" class="mt-1 w-full">{{ old('text_value', $revision?->text_value) }}</textarea>
                </div>
            @endif

            <div class="mt-5">
                <label for="evidence">Supporting evidence {{ $definition->evidence_required ? '(required to submit)' : '(optional)' }}</label>
                <input id="evidence" name="evidence[]" type="file" multiple accept="{{ collect($definition->allowed_extensions ?: [])->map(fn ($extension) => '.'.$extension)->implode(',') }}" class="mt-1 block w-full">
                <p class="mt-1 text-xs text-slate-500">
                    {{ strtoupper(implode(', ', $definition->allowed_extensions ?: [])) }} · Up to {{ $definition->max_files }} files · {{ number_format($definition->max_file_kb / 1024, 1) }} MB each.
                </p>
            </div>

            @if($revision?->evidence->isNotEmpty())
                <div class="mt-4 rounded-lg border border-slate-200 bg-slate-50 p-4">
                    <p class="text-sm font-semibold text-slate-800">Evidence on the current revision</p>
                    <div class="mt-3 flex flex-wrap gap-3">
                        @foreach($revision->evidence as $file)
                            <div class="rounded-lg border border-slate-200 bg-white p-2">
                                @if(str_starts_with($file->mime_type, 'image/'))
                                    <a href="{{ route('evidence.preview', $file) }}" target="_blank"><img src="{{ route('evidence.preview', $file) }}" alt="{{ $file->original_name }}" loading="lazy" class="mb-2 h-20 w-28 rounded object-cover"></a>
                                @endif
                                <a class="block max-w-40 truncate text-xs font-semibold text-brand-700" href="{{ route('evidence.download', $file) }}">{{ $file->original_name }}</a>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="mt-5">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" rows="3" class="mt-1 w-full">{{ old('notes', $revision?->notes) }}</textarea>
            </div>

            @if($entry)
                <p class="mt-4 text-sm text-slate-500">Current status: <strong>{{ $entry->status }}</strong> · Revision {{ $revision?->version }}</p>
            @endif

            <div class="mt-7 flex flex-wrap justify-end gap-3">
                <a href="{{ route('entries.index', ['period' => substr($period, 0, 7)]) }}" class="btn-secondary">Cancel</a>
                <button name="action" value="draft" class="btn-secondary">Save draft</button>
                <button name="action" value="submit" class="btn-primary">{{ $definition->approval_required ? 'Submit for approval' : 'Submit result' }}</button>
            </div>
        </form>

        @if($entry?->revisions->isNotEmpty())
            <section class="card mt-6 p-6">
                <h2 class="font-bold text-ink">Submission history</h2>
                <div class="mt-4 space-y-4">
                    @foreach($entry->revisions->sortByDesc('version') as $history)
                        <div class="rounded-lg border border-slate-200 p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="font-semibold text-slate-800">Revision {{ $history->version }} · {{ $history->displayValue() }} {{ $definition->unit }}</p>
                                <span class="badge bg-slate-100 text-slate-700">{{ $history->status }}</span>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">Saved by {{ $history->user->name }} on {{ $history->created_at->format('d M Y, H:i') }}</p>
                            @if($history->notes)<p class="mt-2 text-sm text-slate-600">{{ $history->notes }}</p>@endif
                            @foreach($history->actions as $review)
                                <p class="mt-2 rounded bg-slate-50 px-3 py-2 text-sm"><strong>{{ $review->action }}</strong> by {{ $review->user->name }}@if($review->comment): {{ $review->comment }}@endif</p>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.app>
