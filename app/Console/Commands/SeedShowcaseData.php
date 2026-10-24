<?php

namespace App\Console\Commands;

use App\Models\EvidenceFile;
use App\Models\IndicatorDefinition;
use App\Models\IndicatorEntry;
use App\Models\IndicatorTarget;
use Database\Seeders\ShowcaseSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('impact:seed-showcase-data {--year= : Calendar year to represent; defaults to the current year}')]
#[Description('Create a minimal, reversible showcase over the local authoritative project catalogue')]
class SeedShowcaseData extends Command
{
    public function handle(ShowcaseSeeder $showcaseSeeder): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->components->error('Showcase data may only be seeded in a local or testing environment.');

            return self::FAILURE;
        }

        $yearOption = $this->option('year');
        if ($yearOption !== null && (! ctype_digit((string) $yearOption) || (int) $yearOption < 2000)) {
            $this->components->error('The year must be a four-digit calendar year.');

            return self::INVALID;
        }

        $year = $yearOption === null ? now()->year : (int) $yearOption;
        app()->call([$showcaseSeeder, 'run'], ['year' => $year]);

        $this->table(['Showcase result', 'Count'], [
            ['Indicator definitions', IndicatorDefinition::query()->where('is_demo', true)->count()],
            ['Reporting entries', IndicatorEntry::query()->where('is_demo', true)->count()],
            ['Targets', IndicatorTarget::query()->where('is_demo', true)->count()],
            ['Evidence files', EvidenceFile::query()->where('is_demo', true)->count()],
        ]);
        $this->components->info("Local {$year} showcase data created. Authoritative projects and import history were not changed.");

        return self::SUCCESS;
    }
}
