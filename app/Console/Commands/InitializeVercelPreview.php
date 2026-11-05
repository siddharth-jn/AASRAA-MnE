<?php

namespace App\Console\Commands;

use Database\Seeders\VercelPreviewSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('impact:initialize-vercel-preview')]
#[Description('Initialize the isolated Vercel preview database exactly once')]
class InitializeVercelPreview extends Command
{
    private const int AdvisoryLockId = 1_368_211_527;

    public function handle(): int
    {
        $usesAdvisoryLock = DB::getDriverName() === 'pgsql';

        if ($usesAdvisoryLock) {
            DB::select('select pg_advisory_lock(?)', [self::AdvisoryLockId]);
        }

        try {
            if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }

            return $this->call('db:seed', [
                '--class' => VercelPreviewSeeder::class,
                '--force' => true,
            ]);
        } finally {
            if ($usesAdvisoryLock) {
                DB::select('select pg_advisory_unlock(?)', [self::AdvisoryLockId]);
            }
        }
    }
}
