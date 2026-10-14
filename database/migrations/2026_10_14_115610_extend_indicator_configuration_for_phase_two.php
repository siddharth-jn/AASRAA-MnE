<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('indicator_definitions', 'value_required')) {
            Schema::table('indicator_definitions', function (Blueprint $table) {
                $table->boolean('value_required')->default(true)->after('data_type');
                $table->string('calculation_method')->nullable()->after('value_required');
                $table->string('numerator_label')->nullable()->after('calculation_method');
                $table->string('denominator_label')->nullable()->after('numerator_label');
                $table->date('minimum_date')->nullable()->after('max_value');
                $table->date('maximum_date')->nullable()->after('minimum_date');
                $table->boolean('target_tracking')->default(true)->after('direction');
                $table->unsignedTinyInteger('min_files')->default(0)->after('max_file_kb');
                $table->boolean('evidence_review_required')->default(false)->after('max_files');
                $table->boolean('approval_required')->default(true)->after('evidence_review_required');
            });
        }

        Schema::table('indicator_targets', function (Blueprint $table) {
            $table->index('indicator_assignment_id', 'indicator_targets_assignment_index');
            $table->dropUnique('indicator_targets_indicator_assignment_id_period_unique');
            $table->string('period_type')->default('monthly')->after('period');
            $table->date('period_end')->nullable()->after('period_type');
            $table->string('target_type')->default('numeric')->after('period_end');
            $table->decimal('value', 18, 4)->nullable()->change();
            $table->decimal('minimum_value', 18, 4)->nullable()->after('value');
            $table->decimal('maximum_value', 18, 4)->nullable()->after('minimum_value');
            $table->string('status_value')->nullable()->after('maximum_value');
            $table->text('notes')->nullable()->after('status_value');
            $table->boolean('active')->default(true)->after('notes');
            $table->unique(['indicator_assignment_id', 'period', 'period_type'], 'indicator_target_period_unique');
        });

        DB::table('indicator_targets')
            ->select(['id', 'period'])
            ->orderBy('id')
            ->eachById(function (object $target): void {
                DB::table('indicator_targets')
                    ->where('id', $target->id)
                    ->update(['period_end' => Carbon::parse($target->period)->endOfMonth()->toDateString()]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('indicator_targets')->whereNull('value')->update(['value' => 0]);

        Schema::table('indicator_targets', function (Blueprint $table) {
            $table->dropUnique('indicator_target_period_unique');
            $table->decimal('value', 18, 4)->nullable(false)->change();
            $table->dropColumn(['period_type', 'period_end', 'target_type', 'minimum_value', 'maximum_value', 'status_value', 'notes', 'active']);
            $table->unique(['indicator_assignment_id', 'period']);
            $table->dropIndex('indicator_targets_assignment_index');
        });

        Schema::table('indicator_definitions', function (Blueprint $table) {
            $table->dropColumn([
                'value_required',
                'calculation_method',
                'numerator_label',
                'denominator_label',
                'minimum_date',
                'maximum_date',
                'target_tracking',
                'min_files',
                'evidence_review_required',
                'approval_required',
            ]);
        });
    }
};
