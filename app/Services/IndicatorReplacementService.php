<?php

namespace App\Services;

use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class IndicatorReplacementService
{
    public function earliestMonth(IndicatorDefinition $indicator): string
    {
        $assignmentIds = $indicator->assignments()->pluck('id');
        $latestPeriod = collect([
            IndicatorEntry::query()->whereIn('indicator_assignment_id', $assignmentIds)->max('period'),
            IndicatorTarget::query()->whereIn('indicator_assignment_id', $assignmentIds)->max('period'),
        ])->filter()->sortDesc()->first();

        return ($latestPeriod
            ? CarbonImmutable::parse($latestPeriod)->startOfMonth()->addMonth()
            : CarbonImmutable::now()->startOfMonth())
            ->format('Y-m');
    }

    public function replace(IndicatorDefinition $indicator, string $effectiveFrom): IndicatorDefinition
    {
        $effectiveDate = $this->month($effectiveFrom);
        $earliestDate = CarbonImmutable::createFromFormat('!Y-m', $this->earliestMonth($indicator))->startOfMonth();
        if ($effectiveDate->lt($earliestDate)) {
            throw ValidationException::withMessages([
                'effective_from' => 'The replacement must start in '.$earliestDate->format('F Y').' or later, after every existing target and entry month.',
            ]);
        }

        return DB::transaction(function () use ($indicator, $effectiveDate): IndicatorDefinition {
            $original = IndicatorDefinition::query()
                ->lockForUpdate()
                ->with('assignments')
                ->findOrFail($indicator->id);

            if (! $original->hasHistoricalData()) {
                throw ValidationException::withMessages([
                    'indicator' => 'This indicator has no targets or entries yet, so its configuration can be edited directly.',
                ]);
            }

            $assignments = $original->assignments
                ->map(fn ($assignment): array => [
                    'centre_id' => $assignment->centre_id,
                    'required' => (bool) $assignment->required,
                    'is_demo' => (bool) $assignment->is_demo,
                ])
                ->all();

            $original->update(['active' => false]);

            $replacement = $original->replicate();
            $replacement->forceFill([
                'code' => $this->replacementCode($original, $effectiveDate),
                'effective_date' => $effectiveDate->toDateString(),
                'active' => true,
            ]);
            $replacement->save();
            $replacement->assignments()->createMany($assignments);

            return $replacement->fresh(['project', 'centres', 'assignments']);
        });
    }

    private function month(string $value): CarbonImmutable
    {
        try {
            $month = CarbonImmutable::createFromFormat('!Y-m', trim($value));
        } catch (Throwable) {
            $month = null;
        }

        if (! $month || $month->format('Y-m') !== trim($value)) {
            throw ValidationException::withMessages([
                'effective_from' => 'Choose a valid replacement start month.',
            ]);
        }

        return $month->startOfMonth();
    }

    private function replacementCode(IndicatorDefinition $indicator, CarbonImmutable $effectiveDate): string
    {
        $suffix = '-R-'.$effectiveDate->format('Ym');
        $base = rtrim(substr(Str::upper($indicator->code), 0, 100 - strlen($suffix)), '-').$suffix;
        $candidate = $base;
        $sequence = 2;

        while (IndicatorDefinition::query()->whereRaw('LOWER(code) = ?', [mb_strtolower($candidate)])->exists()) {
            $sequenceSuffix = '-'.$sequence;
            $candidate = rtrim(substr($base, 0, 100 - strlen($sequenceSuffix)), '-').$sequenceSuffix;
            $sequence++;
        }

        return $candidate;
    }
}
