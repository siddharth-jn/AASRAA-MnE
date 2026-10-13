<?php

namespace App\Services;

use InvalidArgumentException;

class AggregationService
{
    /**
     * @param  list<array{value?: float|int|null, numerator?: float|int|null, denominator?: float|int|null, weight?: float|int|null, status?: string|null}>  $records
     */
    public function aggregate(array $records, string $method): float|array|string|null
    {
        if ($records === []) {
            return null;
        }

        return match ($method) {
            'SUM' => array_sum(array_column($records, 'value')),
            'LATEST SNAPSHOT' => end($records)['value'] ?? null,
            'AVERAGE' => array_sum(array_column($records, 'value')) / count($records),
            'WEIGHTED AVERAGE' => $this->weightedAverage($records),
            'RATIO' => $this->ratio($records),
            'STATUS' => array_count_values(array_values(array_filter(array_column($records, 'status')))),
            'NON-AGGREGATABLE', 'UNIQUE COUNT' => null,
            default => throw new InvalidArgumentException("Unsupported aggregation method: {$method}"),
        };
    }

    /** @param list<array<string, float|int|string|null>> $records */
    private function weightedAverage(array $records): ?float
    {
        $weight = array_sum(array_column($records, 'weight'));

        return $weight > 0 ? array_sum(array_map(fn (array $record): float => (float) ($record['value'] ?? 0) * (float) ($record['weight'] ?? 0), $records)) / $weight : null;
    }

    /** @param list<array<string, float|int|string|null>> $records */
    private function ratio(array $records): ?float
    {
        $denominator = array_sum(array_column($records, 'denominator'));

        return $denominator > 0 ? array_sum(array_column($records, 'numerator')) / $denominator * 100 : null;
    }
}
