<?php

namespace App\Console\Commands;

use App\Services\DemoDataService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('impact:clear-demo-data {--force : Confirm removal of demo-tagged records}')]
#[Description('Remove only records explicitly tagged as demonstration data')]
class ClearDemoData extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DemoDataService $demoDataService): int
    {
        if (! $this->option('force') && ! $this->confirm('Remove all records tagged as demo data?')) {
            return self::SUCCESS;
        }
        $demoDataService->clear();
        $this->info('Demo-tagged data removed. Operational records were not selected.');

        return self::SUCCESS;
    }
}
