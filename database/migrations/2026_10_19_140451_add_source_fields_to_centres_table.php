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
            $table->unsignedInteger('source_project_id')->nullable()->unique()->after('id');
            $table->string('source_program')->nullable()->after('provenance');
            $table->timestamp('source_synced_at')->nullable()->after('source_program');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('centres', function (Blueprint $table) {
            $table->dropUnique(['source_project_id']);
            $table->dropColumn(['source_project_id', 'source_program', 'source_synced_at']);
        });
    }
};
