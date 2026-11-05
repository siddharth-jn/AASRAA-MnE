<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('indicator_definitions')
            ->whereIn('data_type', [
                'integer',
                'decimal',
                'percentage',
                'ratio',
                'calculated',
                'boolean',
                'single_select',
                'status',
            ])
            ->update([
                'frequency' => 'monthly',
                'value_required' => true,
                'target_tracking' => true,
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
