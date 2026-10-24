<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('centres', function (Blueprint $table) {
            $table->string('source_status')->nullable()->after('source_program');
            $table->string('source_timing_slot', 50)->nullable()->after('source_status');
            $table->text('source_description')->nullable()->after('source_timing_slot');
            $table->text('source_address')->nullable()->after('source_description');
            $table->string('source_location')->nullable()->after('source_address');
            $table->date('source_started_date')->nullable()->after('source_location');
            $table->string('source_poc_name')->nullable()->after('source_started_date');
            $table->string('source_poc_contact')->nullable()->after('source_poc_name');
            $table->string('source_poc_designation')->nullable()->after('source_poc_contact');
            $table->unsignedInteger('source_donor_id')->nullable()->after('source_poc_designation');
            $table->string('source_donor_name')->nullable()->after('source_donor_id');
            $table->string('source_budget_head')->nullable()->after('source_donor_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('centres', function (Blueprint $table) {
            $table->dropColumn([
                'source_status',
                'source_timing_slot',
                'source_description',
                'source_address',
                'source_location',
                'source_started_date',
                'source_poc_name',
                'source_poc_contact',
                'source_poc_designation',
                'source_donor_id',
                'source_donor_name',
                'source_budget_head',
            ]);
        });
    }
};
