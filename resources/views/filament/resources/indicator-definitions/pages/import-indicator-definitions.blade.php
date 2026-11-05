<x-filament-panels::page>
    <style>
        .indicator-selection-grid,
        .indicator-workflow-grid,
        .indicator-summary-grid {
            display: grid;
            gap: 1.25rem;
        }

        .indicator-download-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: 1rem;
            border-radius: 0.5rem;
            background: #0f766e;
            padding: 0.625rem 0.875rem;
            color: #fff;
            font-size: 0.875rem;
            font-weight: 600;
            line-height: 1.25rem;
            text-decoration: none;
            transition: background-color 150ms ease;
        }

        .indicator-download-button:hover {
            background: #115e59;
        }

        .indicator-preview-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .indicator-preview-metric {
            min-width: 0;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            background: #f8fafc;
            padding: 0.875rem 1rem;
        }

        .indicator-preview-metric strong {
            display: block;
            margin-top: 0.2rem;
            color: #0f172a;
            font-size: 1.5rem;
            line-height: 1.2;
        }

        .indicator-preview-metric span {
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .indicator-preview-metric.is-ready {
            border-color: #a7f3d0;
            background: #ecfdf5;
        }

        .indicator-preview-metric.is-invalid {
            border-color: #fecaca;
            background: #fef2f2;
        }

        .indicator-preview-notice {
            margin-bottom: 1rem;
            border: 1px solid #fcd34d;
            border-radius: 0.75rem;
            background: #fffbeb;
            padding: 0.875rem 1rem;
            color: #78350f;
            font-size: 0.875rem;
            line-height: 1.45;
        }

        .indicator-preview-list {
            display: grid;
            gap: 0.875rem;
        }

        .indicator-preview-row {
            border: 1px solid #e2e8f0;
            border-radius: 0.875rem;
            background: #fff;
            padding: 1rem;
        }

        .indicator-preview-row.is-invalid {
            border-color: #fecaca;
        }

        .indicator-preview-row-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
        }

        .indicator-preview-row-number {
            margin: 0 0 0.25rem;
            color: #64748b;
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .indicator-preview-row h3 {
            margin: 0;
            color: #0f172a;
            font-size: 1rem;
            font-weight: 700;
            line-height: 1.4;
        }

        .indicator-preview-status {
            flex: 0 0 auto;
            border-radius: 999px;
            padding: 0.3rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .indicator-preview-status.is-ready {
            background: #d1fae5;
            color: #065f46;
        }

        .indicator-preview-status.is-invalid {
            background: #fee2e2;
            color: #991b1b;
        }

        .indicator-preview-details {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.75rem;
            margin: 0.875rem 0 0;
        }

        .indicator-preview-details div {
            min-width: 0;
        }

        .indicator-preview-details dt {
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .indicator-preview-details dd {
            margin: 0.2rem 0 0;
            color: #334155;
            font-size: 0.875rem;
            overflow-wrap: anywhere;
        }

        .indicator-preview-error {
            margin-top: 0.875rem;
            border-left: 4px solid #dc2626;
            border-radius: 0.5rem;
            background: #fef2f2;
            padding: 0.75rem 0.875rem;
            color: #7f1d1d;
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .indicator-preview-error strong {
            display: block;
            margin-bottom: 0.15rem;
        }

        .indicator-preview-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .indicator-preview-actions p {
            margin: 0;
            color: #991b1b;
            font-size: 0.875rem;
        }

        .indicator-history-table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        .indicator-history-table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
            table-layout: fixed;
            text-align: left;
        }

        .indicator-history-table th,
        .indicator-history-table td {
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 0.65rem;
            vertical-align: top;
        }

        .indicator-history-table th {
            color: #475569;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .indicator-history-table td {
            color: #334155;
            font-size: 0.875rem;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .indicator-history-table th:nth-child(1),
        .indicator-history-table td:nth-child(1) { width: 8%; }

        .indicator-history-table th:nth-child(2),
        .indicator-history-table td:nth-child(2) { width: 20%; }

        .indicator-history-table th:nth-child(3),
        .indicator-history-table td:nth-child(3) { width: 29%; }

        .indicator-history-table th:nth-child(4),
        .indicator-history-table td:nth-child(4) { width: 8%; }

        .indicator-history-table th:nth-child(5),
        .indicator-history-table td:nth-child(5) { width: 12%; }

        .indicator-history-table th:nth-child(6),
        .indicator-history-table td:nth-child(6) { width: 23%; }

        .indicator-history-link {
            color: #0f766e;
            font-weight: 700;
            text-decoration: none;
        }

        .indicator-history-link:hover {
            text-decoration: underline;
        }

        .dark .indicator-preview-metric,
        .dark .indicator-preview-row {
            border-color: rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.04);
        }

        .dark .indicator-preview-metric strong,
        .dark .indicator-preview-row h3 {
            color: #f8fafc;
        }

        .dark .indicator-preview-details dd {
            color: #cbd5e1;
        }

        .dark .indicator-history-table th,
        .dark .indicator-history-table td {
            border-bottom-color: rgba(255, 255, 255, 0.1);
        }

        .dark .indicator-history-table th {
            color: #94a3b8;
        }

        .dark .indicator-history-table td {
            color: #cbd5e1;
        }

        @media (min-width: 768px) {
            .indicator-selection-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .indicator-workflow-grid,
            .indicator-summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .indicator-preview-summary,
            .indicator-preview-details {
                grid-template-columns: 1fr;
            }
        }
    </style>

    @if (session('success'))
        <div class="rounded-xl border border-success-200 bg-success-50 px-4 py-3 text-sm font-medium text-success-800">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-xl border border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-800">
            <p class="font-semibold">The import could not continue.</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $selectedProgramme = $this->selectedProgramme();
        $selectedCentre = $this->selectedCentre();
        $defaultEffectiveFrom = old('effective_from', now()->format('Y-m'));
        $importUrl = \App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource::getUrl('import');
    @endphp

    <x-filament::section>
        <x-slot name="heading">Choose where the indicators belong</x-slot>
        <x-slot name="description">Select one programme and one reporting level. Only the relevant import workspace will open.</x-slot>

        <form method="GET" action="{{ $importUrl }}" class="indicator-selection-grid">
            <div>
                <label for="programme" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Programme</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="programme" name="programme" required onchange="this.form.elements.centre && (this.form.elements.centre.value = ''); this.form.submit()">
                        <option value="">Select programme</option>
                        @foreach ($this->programmes() as $programme)
                            <option value="{{ $programme->id }}" @selected((string) $this->selectedProgrammeId === (string) $programme->id)>{{ $programme->name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>

            @if ($selectedProgramme)
                <div>
                    <label for="scope" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Reporting level</label>
                    <x-filament::input.wrapper>
                        <x-filament::input.select id="scope" name="scope" onchange="this.form.submit()">
                            <option value="project" @selected($this->selectedScope === 'project')>Project level</option>
                            <option value="programme" @selected($this->selectedScope === 'programme')>Programme level</option>
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>

                @if ($this->selectedScope === 'project')
                    <div>
                        <label for="centre" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Project</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="centre" name="centre" required onchange="this.form.submit()">
                                <option value="">Select project</option>
                                @foreach ($selectedProgramme->centres as $centre)
                                    <option value="{{ $centre->id }}" @selected((string) $this->selectedCentreId === (string) $centre->id)>{{ $centre->name }} ({{ $centre->code }})</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>
                @endif
            @endif
        </form>

        @if ($selectedProgramme && $this->selectedScope === 'project' && $selectedProgramme->centres->isEmpty())
            <p class="mt-4 rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800">This programme has no active projects available for indicator assignment.</p>
        @endif
    </x-filament::section>

    @if ($selectedProgramme && ($this->selectedScope === 'programme' || $selectedCentre))
        @php
            $isProjectScope = $this->selectedScope === 'project';
            $scopeLabel = $isProjectScope ? $selectedCentre->name : $selectedProgramme->name.' (programme level)';
            $templateParameters = array_filter([
                'project_id' => $selectedProgramme->id,
                'scope' => $this->selectedScope,
                'centre_id' => $selectedCentre?->id,
            ], fn (mixed $value): bool => $value !== null);
        @endphp
        <x-filament::section>
            <x-slot name="heading">Import for {{ $scopeLabel }}</x-slot>
            <x-slot name="description">Download this location-specific template, complete it, then upload the same file for validation.</x-slot>

            <div class="indicator-workflow-grid">
                <div class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
                    <p class="text-xs font-semibold uppercase tracking-wide text-primary-600">Step 1</p>
                    <h3 class="mt-1 font-semibold text-gray-950 dark:text-white">Download the Excel template</h3>
                    <p class="mt-2 text-sm text-gray-500">The file is locked to {{ $scopeLabel }}. Keep its columns and hidden sheets unchanged.</p>
                    <a
                        href="{{ route('indicator-imports.template.select', $templateParameters) }}"
                        download
                        data-no-wire-navigate
                        class="indicator-download-button"
                    >Download XLSX template</a>
                </div>

                <form method="POST" action="{{ route('indicator-imports.upload') }}" enctype="multipart/form-data" class="rounded-xl border border-gray-200 p-5 dark:border-white/10">
                    @csrf
                    <input type="hidden" name="project_id" value="{{ $selectedProgramme->id }}">
                    <input type="hidden" name="scope" value="{{ $this->selectedScope }}">
                    @if ($selectedCentre)
                        <input type="hidden" name="centre_id" value="{{ $selectedCentre->id }}">
                    @endif
                    <p class="text-xs font-semibold uppercase tracking-wide text-primary-600">Step 2</p>
                    <h3 class="mt-1 font-semibold text-gray-950 dark:text-white">Upload and review</h3>
                    <div class="mt-4 space-y-4">
                        <div>
                            <label for="effective-from" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Reporting starts from</label>
                            <x-filament::input.wrapper>
                                <x-filament::input id="effective-from" name="effective_from" type="month" value="{{ $defaultEffectiveFrom }}" required />
                            </x-filament::input.wrapper>
                            <p class="mt-1 text-xs text-gray-500">The indicators will be active from this month onward.</p>
                        </div>
                        <div>
                            <label for="indicator-file" class="mb-1 block text-sm font-medium text-gray-950 dark:text-white">Completed XLSX template</label>
                            <x-filament::input.wrapper>
                                <x-filament::input id="indicator-file" name="file" type="file" accept=".xlsx" required />
                            </x-filament::input.wrapper>
                        </div>
                        <x-filament::button type="submit" icon="heroicon-o-arrow-up-tray">Validate and preview</x-filament::button>
                    </div>
                </form>
            </div>
        </x-filament::section>
    @elseif ($selectedProgramme && $this->selectedScope === 'project' && $selectedProgramme->centres->isNotEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 px-5 py-6 text-sm text-gray-500 dark:border-white/10">Select one project above to open its download and upload workspace.</div>
    @endif

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">What will be applied to every indicator</x-slot>
        <x-slot name="description">This is the final import behaviour. You will review every row before anything is created.</x-slot>

        <div class="indicator-summary-grid">
            <div>
                <h3 class="font-semibold text-gray-950 dark:text-white">Taken from Excel</h3>
                <ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <li><strong>Name and classification:</strong> indicator name, category, unit, and input type.</li>
                    <li><strong>Optional setup:</strong> data sources, accepted options, percentage method, ratio labels, across-month method, and performance direction.</li>
                    <li><strong>Conditional fields:</strong> percentage setup is only for percentage/ratio indicators and must stay blank for Integer; accepted options are only for select/status indicators; numerator and denominator labels are only for ratio-based indicators.</li>
                </ul>
            </div>
            <div>
                <h3 class="font-semibold text-gray-950 dark:text-white">Set automatically</h3>
                <ul class="mt-3 space-y-2 text-sm text-gray-600 dark:text-gray-300">
                    <li><strong>Assignment:</strong> the selected programme, project/reporting level, and reporting start month.</li>
                    <li><strong>Defaults:</strong> generated unique code, monthly frequency, active status, and no cross-project aggregation.</li>
                    <li><strong>Controls:</strong> evidence is optional by default and approval is required. If evidence is enabled later, the configured file limits and types apply.</li>
                    <li><strong>Compatibility:</strong> description is copied from the indicator name; numeric precision is 0 for integers and 2 otherwise; percentages are limited to 0–100.</li>
                    <li><strong>Targets:</strong> the import does not create targets. A target must be set separately before target-based data entry opens.</li>
                </ul>
            </div>
        </div>

        <div class="mt-6 rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm text-primary-900 dark:border-primary-800 dark:bg-primary-950/30 dark:text-primary-100">
            <p class="font-semibold">Validation before saving</p>
            <p class="mt-1">The preview identifies the Excel row, the exact column, the rejected value, and how to correct it. It checks the template identity, required cells, dropdown values, input-specific rules, duplicate names, supported aggregation methods, formulas, text limits, and project assignment. If any row is invalid, no indicators can be created.</p>
        </div>
    </x-filament::section>

    @php
        $batch = $this->batch();
    @endphp
    @if ($batch)
        @php
            $validCount = $batch->rows->where('status', 'valid')->count();
            $invalidCount = $batch->rows->where('status', 'invalid')->count();
            $projectNames = $batch->project->centres->pluck('name', 'id');
        @endphp
        <x-filament::section>
            <x-slot name="heading">Import preview #{{ $batch->id }}</x-slot>
            <x-slot name="description">{{ $batch->project->name }} · {{ $batch->filename }} · No indicators have been created yet.</x-slot>

            <div class="indicator-preview-summary">
                <div class="indicator-preview-metric"><span>Rows checked</span><strong>{{ $batch->rows->count() }}</strong></div>
                <div class="indicator-preview-metric is-ready"><span>Ready to import</span><strong>{{ $validCount }}</strong></div>
                <div class="indicator-preview-metric is-invalid"><span>Need changes</span><strong>{{ $invalidCount }}</strong></div>
            </div>

            @if ($invalidCount > 0)
                <div class="indicator-preview-notice"><strong>Nothing has been saved.</strong> Fix the Excel cells named below, then upload the corrected file again.</div>
            @endif

            <div class="indicator-preview-list">
                @foreach ($batch->rows->sortBy('row_number') as $row)
                    @php
                        $indicatorName = trim((string) ($row->source['Indicator name'] ?? ''));
                        $category = $row->proposed_indicator['category'] ?? ($row->source['Category'] ?? null);
                        $inputType = $row->proposed_indicator['data_type'] ?? ($row->source['Input type'] ?? null);
                        $reportingLevel = $row->proposed_indicator['reporting_scope'] ?? (collect($row->centre_ids)->isNotEmpty() ? 'project' : 'programme');
                        $assignedProjects = collect($row->centre_ids)->map(fn ($id) => $projectNames->get($id))->filter()->join(', ') ?: 'Programme level';
                    @endphp
                    <article class="indicator-preview-row {{ $row->status === 'valid' ? '' : 'is-invalid' }}">
                        <div class="indicator-preview-row-header">
                            <div>
                                <p class="indicator-preview-row-number">Excel row {{ $row->row_number }}</p>
                                <h3>{{ $indicatorName !== '' ? $indicatorName : 'Incomplete row' }}</h3>
                            </div>
                            <span class="indicator-preview-status {{ $row->status === 'valid' ? 'is-ready' : 'is-invalid' }}">{{ $row->status === 'valid' ? 'Ready' : 'Needs changes' }}</span>
                        </div>

                        <dl class="indicator-preview-details">
                            <div><dt>Category and input</dt><dd>{{ $category ?: 'Not set' }}{{ $inputType ? ' · '.str($inputType)->headline() : '' }}</dd></div>
                            <div><dt>Reporting level</dt><dd>{{ str($reportingLevel)->headline() }}</dd></div>
                            <div><dt>Assigned project</dt><dd>{{ $assignedProjects }}</dd></div>
                        </dl>

                        @if ($row->status !== 'valid')
                            <div class="indicator-preview-error">
                                <strong>What to fix</strong>
                                {{ $this->validationMessage($row) }}
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>

            <div class="indicator-preview-actions">
                <x-filament::button color="gray" tag="a" href="{{ \App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource::getUrl('index') }}">Cancel</x-filament::button>
                @if ($batch->status === 'preview' && $validCount > 0 && $invalidCount === 0)
                    <form method="POST" action="{{ route('indicator-imports.confirm', $batch) }}">
                        @csrf
                        <input type="hidden" name="confirmation" value="1">
                        <x-filament::button type="submit" icon="heroicon-o-check">Confirm and create {{ $validCount }} indicators</x-filament::button>
                    </form>
                @elseif ($invalidCount > 0)
                    <p>Correct all {{ $invalidCount }} rows before importing. Valid rows will not be saved separately.</p>
                @endif
            </div>
        </x-filament::section>
    @endif

    <x-filament::section collapsible collapsed>
        <x-slot name="heading">Recent indicator imports</x-slot>
        <div class="indicator-history-table-wrap">
            <table class="indicator-history-table">
                <thead><tr><th>Batch</th><th>Programme</th><th>File</th><th>Rows</th><th>Status</th><th>Uploaded by</th></tr></thead>
                <tbody>
                    @forelse ($this->recentBatches() as $recentBatch)
                        <tr><td><a class="indicator-history-link" href="{{ \App\Filament\Resources\IndicatorDefinitions\IndicatorDefinitionResource::getUrl('import', ['batch' => $recentBatch->id]) }}">#{{ $recentBatch->id }}</a></td><td>{{ $recentBatch->project->name }}</td><td>{{ $recentBatch->filename }}</td><td>{{ $recentBatch->rows_count }}</td><td>{{ str($recentBatch->status)->headline() }}</td><td>{{ $recentBatch->user->name }}</td></tr>
                    @empty
                        <tr><td colspan="6">No indicator spreadsheets have been uploaded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
