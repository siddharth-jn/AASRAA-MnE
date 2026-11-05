<x-layouts.app heading="Targets">
    <div class="mb-5 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        Targets are changed through the Excel template and can be reviewed below. A value, including <strong>0</strong>, opens data entry for that month. A blank month means N/A and creates no reporting obligation.
    </div>

    <form method="GET" class="card mb-6 grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
        <div>
            <label for="financial_year">Financial year</label>
            <select id="financial_year" name="financial_year" onchange="this.form.elements.donor.value=''; this.form.elements.project.value=''; this.form.submit()" class="mt-1 w-full">
                @foreach($allowedYears as $year)
                    <option value="{{ $year }}" @selected($financialYearStart === $year)>FY {{ $year }}–{{ substr((string)($year + 1), -2) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="donor">Donor</label>
            <select id="donor" name="donor" onchange="this.form.elements.programme.value='all'; this.form.elements.project.value=''; this.form.submit()" class="mt-1 w-full">
                <option value="">All donors</option>
                @foreach($availableDonors as $donorOption)
                    <option value="{{ $donorOption }}" @selected($donor === $donorOption)>{{ $donorOption }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="programme">Programme</label>
            <select id="programme" name="programme" onchange="this.form.elements.project.value=''; this.form.submit()" class="mt-1 w-full">
                <option value="all" @selected($allProgrammes)>All programmes</option>
                @foreach($projects as $item)
                    <option value="{{ $item->id }}" @selected($project?->id === $item->id)>{{ $item->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="project">Project</label>
            <select id="project" name="project" onchange="this.form.submit()" class="mt-1 w-full" @disabled($allProgrammes)>
                <option value="">{{ $allProgrammes ? 'Included automatically for all programmes' : 'All projects and programme-level indicators' }}</option>
                @foreach($centres as $centre)
                    <option value="{{ $centre->id }}" @selected($centreId === $centre->id)>{{ $centre->name }}</option>
                @endforeach
            </select>
        </div>
    </form>

    @if($project || $allProgrammes)
        <section class="card p-6">
            <div class="grid gap-8 lg:grid-cols-2">
                <div>
                    <div class="flex items-start gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">1</span>
                        <div>
                            <h2 class="font-bold text-ink">Download the template</h2>
                            <p class="mt-1 text-sm leading-6 text-slate-500">
                                @if($allProgrammes)
                                    Indicators are grouped by programme and project.
                                @elseif($centreId)
                                    The workbook contains only indicators assigned to {{ $centres->firstWhere('id', $centreId)?->name }}.
                                @else
                                    Indicators are grouped by project, with programme-level indicators shown separately.
                                @endif
                                @if($donor)
                                    Only projects funded by {{ $donor }} during this financial year are included.
                                @endif
                                Existing monthly targets are already filled in.
                            </p>
                            <a class="btn-secondary mt-4 inline-flex" href="{{ $allProgrammes ? route('targets.template.all', ['financial_year' => $financialYearStart, 'donor' => $donor]) : route('targets.template', ['project' => $project, 'financial_year' => $financialYearStart, 'centre' => $centreId, 'donor' => $donor]) }}">Download Excel template</a>
                        </div>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" action="{{ route('targets.imports.upload') }}">
                    @csrf
                    <input type="hidden" name="programme" value="{{ $allProgrammes ? 'all' : $project->id }}">
                    <input type="hidden" name="financial_year" value="{{ $financialYearStart }}">
                    <input type="hidden" name="donor" value="{{ $donor }}">
                    @if($centreId)<input type="hidden" name="centre" value="{{ $centreId }}">@endif
                    <div class="flex items-start gap-3">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white">2</span>
                        <div class="w-full">
                            <label for="file" class="font-bold text-ink">Upload the completed template</label>
                            <p class="mt-1 text-sm leading-6 text-slate-500">You will review every new, changed, or removed target before anything is saved.</p>
                            <input id="file" name="file" type="file" accept=".xlsx" required class="mt-4 block w-full">
                            <button class="btn-primary mt-4">Preview target changes</button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="mt-6">
            <div class="mb-4">
                <h2 class="text-lg font-bold text-ink">Saved targets</h2>
                <p class="mt-1 text-sm text-slate-500">Quarterly, half-yearly, and annual views summarize the saved monthly targets using each indicator's configured aggregation method.</p>
            </div>

            <form method="GET" class="card mb-4 grid gap-4 p-5 md:grid-cols-2 xl:grid-cols-4">
                <input type="hidden" name="financial_year" value="{{ $financialYearStart }}">
                <input type="hidden" name="donor" value="{{ $donor }}">
                <input type="hidden" name="programme" value="{{ $allProgrammes ? 'all' : $project->id }}">
                @if($centreId)<input type="hidden" name="project" value="{{ $centreId }}">@endif
                <div>
                    <label for="target_period_type">View targets by</label>
                    <select id="target_period_type" name="target_period_type" class="mt-1 w-full" onchange="this.form.elements.target_anchor.value=''; this.form.submit()">
                        @foreach(config('impact.target_periods') as $value => $label)
                            <option value="{{ $value }}" @selected($targetPeriodType === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="target_anchor">Period</label>
                    <select id="target_anchor" name="target_anchor" class="mt-1 w-full">
                        @foreach($targetPeriodOptions as $periodOption)
                            <option value="{{ $periodOption['value'] }}" @selected($targetAnchor->format('Y-m') === $periodOption['value'])>{{ $periodOption['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="indicator_search">Search indicator</label>
                    <input id="indicator_search" name="indicator_search" type="search" value="{{ $indicatorSearch }}" placeholder="Name or code" class="mt-1 w-full">
                </div>
                <div class="flex items-end gap-2">
                    <button class="btn-primary">Show targets</button>
                    <a class="btn-secondary" href="{{ route('targets.index', array_filter(['financial_year' => $financialYearStart, 'donor' => $donor, 'programme' => $allProgrammes ? 'all' : $project->id, 'project' => $centreId])) }}">Clear</a>
                </div>
            </form>

            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-slate-700">{{ $targetPeriodLabel }}</p>
                <p class="text-sm text-slate-500">{{ $targetAssignments->total() }} target {{ Str::plural('assignment', $targetAssignments->total()) }}</p>
            </div>

            @if($targetAssignments->isEmpty())
                <div class="card px-6 py-10 text-center">
                    <p class="font-semibold text-slate-700">No saved targets match these filters.</p>
                    <p class="mt-1 text-sm text-slate-500">Try another period or indicator, or use the Excel template to set monthly targets.</p>
                </div>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Programme</th>
                                <th>Project</th>
                                <th>Indicator</th>
                                <th>Category</th>
                                <th>Aggregation</th>
                                <th>{{ config("impact.target_periods.{$targetPeriodType}") }} target</th>
                                <th>Monthly targets</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($targetAssignments as $row)
                                @php($assignment = $row['assignment'])
                                <tr>
                                    <td class="font-medium text-slate-900">{{ $assignment->indicator->project->name }}</td>
                                    <td>{{ $assignment->centre?->name ?? 'Programme level' }}</td>
                                    <td>
                                        <p class="font-semibold text-slate-900">{{ $assignment->indicator->name }}</p>
                                        <p class="mt-1 text-xs text-slate-500">{{ $assignment->indicator->code }}</p>
                                    </td>
                                    <td>{{ $assignment->indicator->category }}</td>
                                    <td>{{ Str::headline(strtolower($assignment->indicator->time_aggregation)) }}</td>
                                    <td>
                                        <p class="font-semibold text-slate-900">{{ $row['summary']['display'] }}</p>
                                        @if($row['target_month_count'] < $row['expected_month_count'])
                                            <p class="mt-1 text-xs font-medium text-amber-700">{{ $row['target_month_count'] }}/{{ $row['expected_month_count'] }} months set</p>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="flex min-w-52 flex-wrap gap-1.5">
                                            @foreach($row['monthly_targets'] as $monthlyTarget)
                                                <span class="rounded-md border px-2 py-1 text-xs {{ $monthlyTarget['set'] ? 'border-slate-200 bg-slate-50 text-slate-700' : 'border-dashed border-slate-200 text-slate-400' }}">
                                                    <strong>{{ $monthlyTarget['label'] }}</strong> {{ $monthlyTarget['display'] }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $targetAssignments->links() }}</div>
            @endif
        </section>
    @endif
</x-layouts.app>
