<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('entry_revisions', function (Blueprint $table) {
            $table->decimal('variance_percentage', 12, 4)->nullable()->after('notes');
            $table->boolean('variance_flagged')->default(false)->after('variance_percentage');
            $table->text('variance_explanation')->nullable()->after('variance_flagged');
        });

        Schema::table('evidence_files', function (Blueprint $table) {
            $table->string('disk')->default('local')->after('user_id');
        });

        Schema::table('approval_actions', function (Blueprint $table) {
            $table->string('stage')->nullable()->after('user_id');
        });

        DB::table('entry_revisions')->where('status', 'SUBMITTED')->update(['status' => 'PENDING SENIOR PL']);
        DB::table('entry_revisions')->where('status', 'APPROVED')->update(['status' => 'FINAL APPROVED']);
        DB::table('indicator_entries')->where('status', 'SUBMITTED')->update(['status' => 'PENDING SENIOR PL']);
        DB::table('indicator_entries')->where('status', 'APPROVED')->update(['status' => 'FINAL APPROVED']);
        DB::table('approval_actions')->where('action', 'APPROVED')->update([
            'action' => 'FINAL APPROVED',
            'stage' => 'M&E HEAD',
        ]);
        DB::table('approval_actions')->whereNull('stage')->update(['stage' => 'SENIOR PROGRAM LEAD']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('entry_revisions')->where('status', 'PENDING SENIOR PL')->update(['status' => 'SUBMITTED']);
        DB::table('entry_revisions')->where('status', 'PENDING M&E HEAD')->update(['status' => 'SUBMITTED']);
        DB::table('entry_revisions')->where('status', 'FINAL APPROVED')->update(['status' => 'APPROVED']);
        DB::table('indicator_entries')->where('status', 'PENDING SENIOR PL')->update(['status' => 'SUBMITTED']);
        DB::table('indicator_entries')->where('status', 'PENDING M&E HEAD')->update(['status' => 'SUBMITTED']);
        DB::table('indicator_entries')->where('status', 'FINAL APPROVED')->update(['status' => 'APPROVED']);

        Schema::table('approval_actions', function (Blueprint $table) {
            $table->dropColumn('stage');
        });
        Schema::table('evidence_files', function (Blueprint $table) {
            $table->dropColumn('disk');
        });
        Schema::table('entry_revisions', function (Blueprint $table) {
            $table->dropColumn(['variance_percentage', 'variance_flagged', 'variance_explanation']);
        });
    }
};
