<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('indicator_definitions')
            ->where('is_demo', true)
            ->whereIn('category', ['KPI', 'Milestone', 'Qualitative Indicator'])
            ->update([
                'active' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('indicator_definitions')
            ->where('is_demo', true)
            ->whereIn('category', ['KPI', 'Milestone', 'Qualitative Indicator'])
            ->update([
                'active' => true,
                'updated_at' => now(),
            ]);
    }
};
