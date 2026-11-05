<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ReportingPeriodService
{
    public function __construct(private readonly IndicatorReportingPeriodService $indicatorPeriods) {}

    /** @return array{type: string, start: CarbonImmutable, end: CarbonImmutable, data_start: CarbonImmutable, data_end: CarbonImmutable, warning: ?string, provisional: bool} */
    public function fromRequest(Request $request): array
    {
        $type = (string) $request->input('period_type', 'monthly');
        $anchor = CarbonImmutable::parse($request->input('anchor', CarbonImmutable::now()->startOfMonth()->toDateString()));
        $warning = null;

        [$start, $end] = match ($type) {
            'monthly', 'quarterly', 'half_yearly', 'annual' => $this->indicatorPeriods->boundaries($anchor, $type),
            'ytd' => [$this->financialYearStart($anchor), $anchor->endOfDay()],
            'custom' => [
                CarbonImmutable::parse($request->input('start', $anchor->startOfMonth()->toDateString()))->startOfDay(),
                CarbonImmutable::parse($request->input('end', $anchor->endOfMonth()->toDateString()))->endOfDay(),
            ],
            default => abort(422, 'Invalid reporting period type.'),
        };

        abort_if($end->lt($start), 422, 'End date must be after start date.');

        $includesPartialStartMonth = $start->day !== 1;
        $includesPartialEndMonth = ! $end->isSameDay($end->endOfMonth());
        $dataStart = $includesPartialStartMonth ? $start->addMonth()->startOfMonth() : $start->startOfMonth();
        $dataEnd = $includesPartialEndMonth ? $end->subMonth()->endOfMonth() : $end->endOfMonth();
        $currentMonthEnd = CarbonImmutable::now()->endOfMonth();
        $provisional = $end->isAfter(CarbonImmutable::now());
        if ($dataEnd->gt($currentMonthEnd)) {
            $dataEnd = $currentMonthEnd;
            $warning = 'This reporting period is still open. Targets and approved achievements are calculated only through '.$currentMonthEnd->format('F Y').'.';
        } elseif ($provisional) {
            $warning = 'This reporting period is still open. Performance is provisional until the period closes.';
        }
        if ($type === 'custom' && ($includesPartialStartMonth || $includesPartialEndMonth)) {
            $warning = 'Monthly records are not prorated. Results include only complete months fully contained in the custom date range.';
        }

        if ($dataEnd->lt($dataStart)) {
            $warning = 'This range does not contain a complete reporting month, so no monthly achievements can be calculated.';
        }

        return [
            'type' => $type,
            'start' => $start,
            'end' => $end,
            'data_start' => $dataStart,
            'data_end' => $dataEnd,
            'warning' => $warning,
            'provisional' => $provisional,
        ];
    }

    private function financialYearStart(CarbonImmutable $anchor): CarbonImmutable
    {
        $month = config('impact.financial_year_start_month', 4);
        $year = $anchor->month < $month ? $anchor->year - 1 : $anchor->year;

        return $anchor->setDate($year, $month, 1)->startOfDay();
    }
}
