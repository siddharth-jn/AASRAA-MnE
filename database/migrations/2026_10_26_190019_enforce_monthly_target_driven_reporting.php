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
        Schema::table('indicator_definitions', function (Blueprint $table) {
            $table->string('reporting_scope')->default('project')->after('frequency');
        });

        Schema::table('indicator_assignments', function (Blueprint $table) {
            $table->foreignId('centre_id')->nullable()->change();
        });

        Schema::table('indicator_targets', function (Blueprint $table) {
            $table->decimal('target_numerator', 18, 4)->nullable()->after('maximum_value');
            $table->decimal('target_denominator', 18, 4)->nullable()->after('target_numerator');
        });

        Schema::table('indicator_entries', function (Blueprint $table) {
            $table->timestamp('target_changed_at')->nullable()->after('status');
            $table->string('target_change_from_status')->nullable()->after('target_changed_at');
            $table->text('target_change_reason')->nullable()->after('target_change_from_status');
        });

        Schema::create('indicator_target_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_target_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('version');
            $table->string('action');
            $table->string('source')->default('manual');
            $table->string('target_type');
            $table->decimal('value', 18, 4)->nullable();
            $table->decimal('minimum_value', 18, 4)->nullable();
            $table->decimal('maximum_value', 18, 4)->nullable();
            $table->decimal('target_numerator', 18, 4)->nullable();
            $table->decimal('target_denominator', 18, 4)->nullable();
            $table->string('status_value')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->unique(['indicator_target_id', 'version'], 'indicator_target_revision_unique');
        });

        Schema::create('target_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('centre_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('financial_year_start');
            $table->string('filename');
            $table->string('path');
            $table->string('fingerprint');
            $table->string('status')->default('preview');
            $table->json('summary')->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('target_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('target_import_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('indicator_assignment_id')->constrained()->restrictOnDelete();
            $table->date('period');
            $table->unsignedInteger('row_number');
            $table->string('action');
            $table->json('previous_target')->nullable();
            $table->json('proposed_target')->nullable();
            $table->text('error')->nullable();
            $table->boolean('affects_entry')->default(false);
            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->unique(
                ['target_import_batch_id', 'indicator_assignment_id', 'period'],
                'target_import_assignment_period_unique',
            );
        });

        DB::table('indicator_definitions')->update(['frequency' => 'monthly']);
        DB::table('indicator_targets')
            ->select(['id', 'period', 'period_type', 'notes'])
            ->orderBy('id')
            ->eachById(function (object $target): void {
                $updates = ['period_end' => Carbon::parse($target->period)->endOfMonth()->toDateString()];
                if ($target->period_type !== 'monthly') {
                    $transitionNote = 'Preserved as inactive during the monthly reporting transition.';
                    $updates['active'] = false;
                    $updates['notes'] = filled($target->notes)
                        ? trim((string) $target->notes)."\n{$transitionNote}"
                        : $transitionNote;
                }

                DB::table('indicator_targets')->where('id', $target->id)->update($updates);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('target_import_rows');
        Schema::dropIfExists('target_import_batches');
        Schema::dropIfExists('indicator_target_revisions');

        Schema::table('indicator_entries', function (Blueprint $table) {
            $table->dropColumn(['target_changed_at', 'target_change_from_status', 'target_change_reason']);
        });

        Schema::table('indicator_targets', function (Blueprint $table) {
            $table->dropColumn(['target_numerator', 'target_denominator']);
        });

        Schema::table('indicator_assignments', function (Blueprint $table) {
            $table->foreignId('centre_id')->nullable(false)->change();
        });

        Schema::table('indicator_definitions', function (Blueprint $table) {
            $table->dropColumn('reporting_scope');
        });
    }
};
