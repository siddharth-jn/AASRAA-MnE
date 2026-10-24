<?php

namespace App\Services;

use App\Models\IndicatorDefinition;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IndicatorReportingPeriodService
{
    /** @return array{CarbonImmutable, CarbonImmutable} */
    public function boundaries(CarbonInterface|string $period, string $frequency): array
    {
        $anchor = $period instanceof CarbonInterface
            ? CarbonImmutable::instance($period)
            : CarbonImmutable::parse($period);

        return match ($frequency) {
            'monthly' => [$anchor->startOfMonth(), $anchor->endOfMonth()],
            'quarterly' => $this->fiscalBoundaries($anchor, 3),
            'half_yearly' => $this->fiscalBoundaries($anchor, 6),
            'annual' => $this->fiscalBoundaries($anchor, 12),
            default => throw ValidationException::withMessages([
                'period_type' => 'Choose a valid reporting frequency.',
            ]),
        };
    }

    public function start(CarbonInterface|string $period, string $frequency): CarbonImmutable
    {
        return $this->boundaries($period, $frequency)[0];
    }

    public function isAvailable(IndicatorDefinition $indicator, CarbonInterface|string $period): bool
    {
        [, $periodEnd] = $this->boundaries($period, $indicator->frequency);

        return $periodEnd->gte(CarbonImmutable::parse($indicator->effective_date)->startOfMonth());
    }

    public function label(CarbonInterface|string $period, string $frequency): string
    {
        [$start, $end] = $this->boundaries($period, $frequency);
        if ($frequency === 'monthly') {
            return $start->format('F Y');
        }

        $financialYearStart = $this->financialYearStart($start);
        $financialYear = 'FY '.$financialYearStart->format('Y').'–'.$financialYearStart->addYear()->format('y');

        return match ($frequency) {
            'quarterly' => sprintf(
                '%s · Q%d (%s–%s)',
                $financialYear,
                intdiv((int) $financialYearStart->diffInMonths($start), 3) + 1,
                $start->format('M'),
                $end->format('M Y'),
            ),
            'half_yearly' => sprintf(
                '%s · H%d (%s–%s)',
                $financialYear,
                intdiv((int) $financialYearStart->diffInMonths($start), 6) + 1,
                $start->format('M'),
                $end->format('M Y'),
            ),
            'annual' => $financialYear.' ('.$start->format('M Y').'–'.$end->format('M Y').')',
            default => Str::headline($frequency),
        };
    }

    public function frequencyLabel(string $frequency): string
    {
        return (string) (config("impact.frequencies.{$frequency}") ?? Str::headline($frequency));
    }

    public function months(string $frequency): int
    {
        return match ($frequency) {
            'monthly' => 1,
            'quarterly' => 3,
            'half_yearly' => 6,
            'annual' => 12,
            default => throw ValidationException::withMessages([
                'period_type' => 'Choose a valid reporting frequency.',
            ]),
        };
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function fiscalBoundaries(CarbonImmutable $anchor, int $months): array
    {
        $financialYearStartMonth = (int) config('impact.financial_year_start_month', 4);
        $offsetFromFinancialYear = ($anchor->month - $financialYearStartMonth + 12) % 12;
        $periodOffset = $offsetFromFinancialYear % $months;
        $start = $anchor->startOfMonth()->subMonths($periodOffset);

        return [$start, $start->addMonths($months)->subDay()->endOfDay()];
    }

    private function financialYearStart(CarbonImmutable $anchor): CarbonImmutable
    {
        $month = (int) config('impact.financial_year_start_month', 4);
        $year = $anchor->month < $month ? $anchor->year - 1 : $anchor->year;

        return $anchor->setDate($year, $month, 1)->startOfDay();
    }
}
