<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ReportingPeriodService
{
    public function __construct(private readonly IndicatorReportingPeriodService $indicatorPeriods) {}

    /** @return array{type: string, start: CarbonImmutable, end: CarbonImmutable, data_start: CarbonImmutable, data_end: CarbonImmutable, warning: ?string} */
    public function fromRequest(Request $request): array
    {
        $type = (string) $request->input('period_type', 'monthly');
        $anchor = CarbonImmutable::parse($request->input('anchor', '2026-09-01'));
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

        $dataStart = $start->day === 1 ? $start->startOfMonth() : $start->addMonth()->startOfMonth();
        $dataEnd = $end->isSameDay($end->endOfMonth()) ? $end->endOfMonth() : $end->subMonth()->endOfMonth();
        if ($type === 'custom' && (! $dataStart->isSameDay($start) || ! $dataEnd->isSameDay($end))) {
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
        ];
    }

    private function financialYearStart(CarbonImmutable $anchor): CarbonImmutable
    {
        $month = config('impact.financial_year_start_month', 4);
        $year = $anchor->month < $month ? $anchor->year - 1 : $anchor->year;

        return $anchor->setDate($year, $month, 1)->startOfDay();
    }
}
