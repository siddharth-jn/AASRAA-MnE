<?php

namespace App\Console\Commands;

use App\Services\TataIndicatorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('impact:restore-tata-indicators {file : Path to the Tata KPI workbook} {--apply : Create the missing indicators and assignments}')]
#[Description('Preview or restore the Tata KPI indicator catalogue without importing targets or achievements')]
class RestoreTataIndicators extends Command
{
    public function handle(TataIndicatorService $service): int
    {
        $names = $service->readWorkbook((string) $this->argument('file'));
        $rows = collect($service->specifications())
            ->map(fn (array $specification, int $row): array => [
                $specification['code'],
                $names[$row],
                $specification['project_code'] === 'PRG-01' ? 'Street Smart' : 'Mainstreaming / SQEP',
                ucfirst($specification['reporting_scope']),
                $specification['reporting_scope'] === 'programme'
                    ? 1
                    : count(TataIndicatorService::CENTRE_CODES[$specification['group']]),
            ])
            ->values()
            ->all();

        $this->table(['Code', 'Indicator', 'Programme', 'Level', 'Assignments'], $rows);

        if (! $this->option('apply')) {
            $this->components->info('Preview complete. No data was changed. Run again with --apply to restore the catalogue.');

            return self::SUCCESS;
        }

        $statistics = $service->restore($names);
        $this->table(['Result', 'Count'], [
            ['Indicators created', $statistics['created_indicators']],
            ['Indicators already present', $statistics['existing_indicators']],
            ['Assignments created', $statistics['created_assignments']],
            ['Assignments already present', $statistics['existing_assignments']],
            ['Targets created', 0],
            ['Achievements created', 0],
        ]);
        $this->components->info('Tata indicators restored. Existing targets, achievements, projects, centres, and synthetic data were not changed.');

        return self::SUCCESS;
    }
}
