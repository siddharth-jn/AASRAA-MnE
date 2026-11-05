<?php

namespace App\Http\Controllers;

use App\Models\Centre;
use App\Models\IndicatorAssignment;
use App\Models\Project;
use App\Models\TargetImportBatch;
use App\Models\User;
use App\Services\DonorService;
use App\Services\IndicatorReportingPeriodService;
use App\Services\ReportingService;
use App\Services\ScopeService;
use App\Services\TargetSpreadsheetService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TargetController extends Controller
{
    public function index(
        Request $request,
        ScopeService $scope,
        DonorService $donors,
        ReportingService $reporting,
        IndicatorReportingPeriodService $reportingPeriods,
    ): View {
        Gate::authorize('manage-targets');
        $request->validate([
            'target_period_type' => ['nullable', Rule::in(array_keys(config('impact.target_periods')))],
            'target_anchor' => ['nullable', 'date_format:Y-m'],
            'indicator_search' => ['nullable', 'string', 'max:255'],
        ]);
        [$currentFinancialYear, $allowedYears] = $this->financialYears();
        $financialYearStart = $request->integer('financial_year') ?: $currentFinancialYear;
        abort_unless(in_array($financialYearStart, $allowedYears, true), 422, 'Choose an available financial year.');
        [$donor, $availableDonors, $periodStart, $periodEnd] = $this->donorContext($request, $scope, $donors, $financialYearStart);
        $visibleCentreIds = $scope->centres($request->user())->select('centres.id');
        $projects = $scope->projects($request->user())
            ->when($donor, fn (Builder $query, string $donorName): Builder => $query->whereHas('centres', function (Builder $centreQuery) use ($donors, $donorName, $periodStart, $periodEnd, $visibleCentreIds): void {
                $centreQuery->whereIn('centres.id', $visibleCentreIds);
                $donors->applyToCentres($centreQuery, $donorName, $periodStart, $periodEnd);
            }))
            ->orderBy('name')
            ->get();
        $programmeSelection = $request->string('programme')->value();
        $allProgrammes = $programmeSelection === 'all';
        if ($programmeSelection !== '' && ! $allProgrammes && filter_var($programmeSelection, FILTER_VALIDATE_INT) === false) {
            abort(422, 'Choose an available programme.');
        }
        $projectId = $allProgrammes ? null : ($request->integer('programme') ?: $projects->first()?->id);
        $centreId = $request->integer('project') ?: null;

        $project = $projectId ? $projects->firstWhere('id', $projectId) : null;
        if ($projectId) {
            abort_unless($project, 422, 'Choose a programme available for the selected donor.');
        }
        $centres = $project
            ? $scope->centres($request->user())
                ->whereHas('projects', fn (Builder $query): Builder => $query->whereKey($project->id))
                ->when($donor, fn (Builder $query, string $donorName): Builder => $donors->applyToCentres($query, $donorName, $periodStart, $periodEnd))
                ->orderBy('name')
                ->get()
            : collect();
        if ($centreId) {
            abort_unless($centres->contains('id', $centreId), 422, 'Choose a project assigned to the selected programme and donor.');
        }

        $targetPeriodType = $request->string('target_period_type')->value() ?: 'monthly';
        $financialYearDate = CarbonImmutable::create($financialYearStart, 4, 1)->startOfDay();
        $financialYearEnd = $financialYearDate->addYear()->subDay()->endOfDay();
        $defaultAnchor = CarbonImmutable::now()->startOfMonth();
        if ($defaultAnchor->lt($financialYearDate) || $defaultAnchor->gt($financialYearEnd)) {
            $defaultAnchor = $financialYearDate;
        }
        $targetAnchor = $request->filled('target_anchor')
            ? CarbonImmutable::createFromFormat('!Y-m', $request->string('target_anchor')->value())
            : $defaultAnchor;
        abort_unless($targetAnchor->betweenIncluded($financialYearDate, $financialYearEnd), 422, 'Choose a target period within the selected financial year.');
        [$targetPeriodStart, $targetPeriodEnd] = $reportingPeriods->boundaries($targetAnchor, $targetPeriodType);
        $targetAnchor = $targetPeriodStart;
        $targetPeriodLabel = $reportingPeriods->label($targetAnchor, $targetPeriodType);
        $targetPeriodOptions = $this->targetPeriodOptions($financialYearStart, $targetPeriodType, $reportingPeriods);
        $indicatorSearch = $request->string('indicator_search')->trim()->value();

        $targetAssignmentsQuery = $allProgrammes
            ? $this->assignmentsForProjects($projects, $donor, $periodStart, $periodEnd, $donors, $request->user(), $scope)
            : ($project
                ? $this->assignments($project, $centreId, $donor, $periodStart, $periodEnd, $donors, $request->user(), $scope)
                : IndicatorAssignment::query()->whereRaw('1 = 0')->with(['indicator.project', 'centre', 'targets']));
        $targetAssignments = $targetAssignmentsQuery
            ->when($indicatorSearch, function (Builder $query, string $search): void {
                $query->whereHas('indicator', function (Builder $indicatorQuery) use ($search): void {
                    $indicatorQuery->where(function (Builder $searchQuery) use ($search): void {
                        $searchQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%");
                    });
                });
            })
            ->whereHas('targets', fn (Builder $query): Builder => $query
                ->where('period_type', 'monthly')
                ->where('active', true)
                ->whereBetween('period', [$targetPeriodStart, $targetPeriodEnd]))
            ->paginate(25)
            ->withQueryString()
            ->through(function (IndicatorAssignment $assignment) use ($reporting, $targetPeriodStart, $targetPeriodEnd): array {
                $targets = $assignment->targets
                    ->where('period_type', 'monthly')
                    ->where('active', true)
                    ->filter(fn ($target): bool => $target->period->betweenIncluded($targetPeriodStart, $targetPeriodEnd))
                    ->sortBy('period')
                    ->values();
                $monthlyTargets = collect();
                for ($month = $targetPeriodStart->startOfMonth(); $month->lte($targetPeriodEnd); $month = $month->addMonth()) {
                    $monthlyTarget = $targets->first(fn ($target): bool => $target->period->isSameMonth($month));
                    $monthlyTargets->push([
                        'label' => $month->format('M'),
                        'display' => $monthlyTarget
                            ? $reporting->summarizeTargets(collect([$monthlyTarget]), $assignment->indicator)['display']
                            : '—',
                        'set' => (bool) $monthlyTarget,
                    ]);
                }

                return [
                    'assignment' => $assignment,
                    'summary' => $reporting->summarizeTargets($targets, $assignment->indicator),
                    'monthly_targets' => $monthlyTargets,
                    'target_month_count' => $targets->count(),
                    'expected_month_count' => $monthlyTargets->count(),
                ];
            });

        return view('targets.index', compact(
            'projects', 'project', 'allProgrammes', 'centres', 'centreId', 'financialYearStart', 'allowedYears', 'donor', 'availableDonors',
            'targetPeriodType', 'targetAnchor', 'targetPeriodLabel', 'targetPeriodOptions', 'indicatorSearch', 'targetAssignments',
        ));
    }

    public function template(Request $request, Project $project, ScopeService $scope, TargetSpreadsheetService $spreadsheets, DonorService $donors): StreamedResponse
    {
        Gate::authorize('manage-targets');
        abort_unless($scope->projects($request->user())->whereKey($project)->exists(), 403);
        [, $allowedYears] = $this->financialYears();
        $data = $request->validate([
            'financial_year' => ['required', 'integer', Rule::in($allowedYears)],
            'centre' => ['nullable', 'integer'],
            'donor' => ['nullable', 'string', 'max:255'],
        ]);
        [$donor, , $periodStart, $periodEnd] = $this->donorContext($request, $scope, $donors, (int) $data['financial_year']);
        $centre = $this->selectedCentre(
            $request,
            $scope,
            $project,
            isset($data['centre']) ? (int) $data['centre'] : null,
            $donor,
            $periodStart,
            $periodEnd,
            $donors,
        );
        $assignments = $this->assignments($project, $centre?->id, $donor, $periodStart, $periodEnd, $donors, $request->user(), $scope)->get();
        $scopeSlug = $centre ? '-'.str($centre->name)->slug() : '';

        return response()->streamDownload(
            fn () => $spreadsheets->write($project, $centre, (int) $data['financial_year'], $assignments, 'php://output', $donor),
            'aasraa-targets-'.str($project->name)->slug().$scopeSlug.'-'.$data['financial_year'].'-'.($data['financial_year'] + 1).'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function templateAll(Request $request, ScopeService $scope, TargetSpreadsheetService $spreadsheets, DonorService $donors): StreamedResponse
    {
        Gate::authorize('manage-targets');
        [, $allowedYears] = $this->financialYears();
        $data = $request->validate([
            'financial_year' => ['required', 'integer', Rule::in($allowedYears)],
            'donor' => ['nullable', 'string', 'max:255'],
        ]);
        [$donor, , $periodStart, $periodEnd] = $this->donorContext($request, $scope, $donors, (int) $data['financial_year']);
        $projects = $scope->projects($request->user())->orderBy('name')->get();
        abort_if($projects->isEmpty(), 422, 'No programmes are available for target planning.');
        $assignments = $this->assignmentsForProjects($projects, $donor, $periodStart, $periodEnd, $donors, $request->user(), $scope)->get();

        return response()->streamDownload(
            fn () => $spreadsheets->write(null, null, (int) $data['financial_year'], $assignments, 'php://output', $donor),
            'aasraa-targets-all-programmes-'.$data['financial_year'].'-'.($data['financial_year'] + 1).'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function upload(Request $request, ScopeService $scope, TargetSpreadsheetService $spreadsheets, DonorService $donors): RedirectResponse
    {
        Gate::authorize('manage-targets');
        [, $allowedYears] = $this->financialYears();
        $data = $request->validate([
            'programme' => ['required'],
            'financial_year' => ['required', 'integer', Rule::in($allowedYears)],
            'centre' => ['nullable', 'integer'],
            'donor' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
        ]);
        [$donor, , $periodStart, $periodEnd] = $this->donorContext($request, $scope, $donors, (int) $data['financial_year']);
        $allProgrammes = $data['programme'] === 'all';
        $centre = null;
        if ($allProgrammes) {
            abort_if(filled($data['centre'] ?? null), 422, 'A project cannot be selected when importing targets for all programmes.');
            $projects = $scope->projects($request->user())->orderBy('name')->get();
            abort_if($projects->isEmpty(), 422, 'No programmes are available for target planning.');
        } else {
            $projectId = filter_var($data['programme'], FILTER_VALIDATE_INT);
            abort_if($projectId === false, 422, 'Choose an available programme.');
            $project = $scope->projects($request->user())->whereKey($projectId)->firstOrFail();
            $projects = collect([$project]);
            $centre = $this->selectedCentre(
                $request,
                $scope,
                $project,
                isset($data['centre']) ? (int) $data['centre'] : null,
                $donor,
                $periodStart,
                $periodEnd,
                $donors,
            );
        }
        $batch = $spreadsheets->stage($request->user(), $projects, $allProgrammes, $centre, (int) $data['financial_year'], $data['file'], $donor);

        return redirect()->route('targets.imports.show', ['batch' => $batch, 'donor' => $donor]);
    }

    public function showImport(Request $request, TargetImportBatch $batch, ScopeService $scope): View
    {
        Gate::authorize('manage-targets');
        $this->authorizeBatchAccess($request, $batch, $scope);
        $request->validate(['donor' => ['nullable', 'string', 'max:255']]);
        $donor = $request->string('donor')->trim()->value() ?: null;
        $batch->load(['project', 'centre', 'rows.assignment.indicator.project', 'rows.assignment.centre']);

        return view('targets.import-preview', compact('batch', 'donor'));
    }

    public function confirmImport(Request $request, TargetImportBatch $batch, TargetSpreadsheetService $spreadsheets, ScopeService $scope): RedirectResponse
    {
        Gate::authorize('manage-targets');
        $this->authorizeBatchAccess($request, $batch, $scope);
        $data = $request->validate([
            'confirmation' => ['accepted'],
            'donor' => ['nullable', 'string', 'max:255'],
        ]);
        $spreadsheets->confirm($batch);

        return redirect()->route('targets.index', [
            'programme' => $batch->project_id ?? 'all',
            'project' => $batch->centre_id,
            'financial_year' => $batch->financial_year_start,
            'donor' => $data['donor'] ?? null,
        ])->with('success', 'The reviewed monthly target changes were applied.');
    }

    private function assignments(
        Project $project,
        ?int $centreId,
        ?string $donor,
        CarbonImmutable $start,
        CarbonImmutable $end,
        DonorService $donors,
        User $user,
        ScopeService $scope,
    ): Builder {
        return $this->assignmentsForProjects(collect([$project]), $donor, $start, $end, $donors, $user, $scope)
            ->when($centreId, fn (Builder $query): Builder => $query->where('centre_id', $centreId));
    }

    /** @param Collection<int, Project> $projects */
    private function assignmentsForProjects(
        Collection $projects,
        ?string $donor,
        CarbonImmutable $start,
        CarbonImmutable $end,
        DonorService $donors,
        User $user,
        ScopeService $scope,
    ): Builder {
        return $scope->assignments($user)
            ->whereHas('indicator', fn (Builder $query): Builder => $query->whereIn('project_id', $projects->pluck('id')->all())->where('active', true)->where('target_tracking', true))
            ->when($donor, fn (Builder $query, string $donorName): Builder => $donors->applyCentreFilter($query, $donorName, $start, $end))
            ->with(['indicator.project', 'centre', 'targets'])
            ->orderByRaw('centre_id is null desc')
            ->orderBy('indicator_definition_id');
    }

    private function authorizeBatchAccess(Request $request, TargetImportBatch $batch, ScopeService $scope): void
    {
        $user = $request->user();
        if (in_array($user->role, ['ADMIN', 'M&E HEAD'], true)) {
            return;
        }

        abort_unless($batch->user_id === $user->id, 403);
        if ($batch->project_id) {
            abort_unless($scope->projects($user)->whereKey($batch->project_id)->exists(), 403);
        }
        if ($batch->centre_id) {
            abort_unless($scope->centres($user)->whereKey($batch->centre_id)->exists(), 403);
        }
        abort_if($batch->rows()
            ->whereNotIn('indicator_assignment_id', $scope->assignments($user)->select('indicator_assignments.id'))
            ->exists(), 403);
    }

    private function selectedCentre(
        Request $request,
        ScopeService $scope,
        Project $project,
        ?int $centreId,
        ?string $donor,
        CarbonImmutable $start,
        CarbonImmutable $end,
        DonorService $donors,
    ): ?Centre {
        if (! $centreId) {
            return null;
        }

        $centre = $scope->centres($request->user())
            ->whereKey($centreId)
            ->whereHas('projects', fn (Builder $query) => $query->whereKey($project->id))
            ->when($donor, fn (Builder $query, string $donorName): Builder => $donors->applyToCentres($query, $donorName, $start, $end))
            ->first();
        abort_unless($centre, 422, 'Choose a project assigned to the selected programme and donor.');

        return $centre;
    }

    /** @return array{int, array<int, int>} */
    private function financialYears(): array
    {
        $now = CarbonImmutable::now();
        $current = $now->month >= 4 ? $now->year : $now->year - 1;

        return [$current, range($current - 5, $current + 5)];
    }

    /** @return Collection<int, array{value: string, label: string}> */
    private function targetPeriodOptions(
        int $financialYearStart,
        string $periodType,
        IndicatorReportingPeriodService $reportingPeriods,
    ): Collection {
        $firstMonth = CarbonImmutable::create($financialYearStart, 4, 1);
        $monthsPerPeriod = $reportingPeriods->months($periodType);
        $periods = collect();

        for ($monthOffset = 0; $monthOffset < 12; $monthOffset += $monthsPerPeriod) {
            $anchor = $firstMonth->addMonths($monthOffset);
            $periods->push([
                'value' => $anchor->format('Y-m'),
                'label' => $reportingPeriods->label($anchor, $periodType),
            ]);
        }

        return $periods;
    }

    /** @return array{?string, Collection<int, string>, CarbonImmutable, CarbonImmutable} */
    private function donorContext(Request $request, ScopeService $scope, DonorService $donors, int $financialYearStart): array
    {
        $request->validate(['donor' => ['nullable', 'string', 'max:255']]);
        $start = CarbonImmutable::create($financialYearStart, 4, 1);
        $end = $start->addYear()->subDay();
        $availableDonors = $donors->availableNames($scope->centres($request->user()), $start, $end);
        $donor = $request->string('donor')->trim()->value() ?: null;

        if ($donor) {
            abort_unless($availableDonors->contains($donor), 422, 'Choose a donor available during the selected financial year.');
        }

        return [$donor, $availableDonors, $start, $end];
    }
}
