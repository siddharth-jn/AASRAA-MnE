<?php

namespace Tests\Unit;

use App\Services\AggregationService;
use PHPUnit\Framework\TestCase;

class AggregationTest extends TestCase
{
    public function test_sum_uses_additive_monthly_values(): void
    {
        $result = (new AggregationService)->aggregate([['value' => 12], ['value' => 15], ['value' => 9]], 'SUM');

        $this->assertEquals(36, $result);
    }

    public function test_latest_snapshot_uses_the_period_end_value(): void
    {
        $result = (new AggregationService)->aggregate([['value' => 33], ['value' => 34], ['value' => 35]], 'LATEST SNAPSHOT');

        $this->assertEquals(35, $result);
    }

    public function test_snapshot_values_can_be_summed_across_distinct_centres(): void
    {
        $result = (new AggregationService)->aggregate([['value' => 35], ['value' => 20]], 'SUM');

        $this->assertEquals(55, $result);
    }

    public function test_ratio_uses_combined_numerators_and_denominators(): void
    {
        $result = (new AggregationService)->aggregate([['numerator' => 85, 'denominator' => 100], ['numerator' => 8, 'denominator' => 10]], 'RATIO');

        $this->assertEqualsWithDelta(84.5454, $result, 0.0001);
    }

    public function test_missing_records_do_not_become_zero(): void
    {
        $this->assertNull((new AggregationService)->aggregate([], 'SUM'));
        $this->assertNull((new AggregationService)->aggregate([['value' => null]], 'SUM'));
        $this->assertEquals(12, (new AggregationService)->aggregate([['value' => null], ['value' => 12]], 'AVERAGE'));
    }
}
