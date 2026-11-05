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
        Schema::table('entry_revisions', function (Blueprint $table) {
            $table->foreignId('supersedes_revision_id')->nullable()->after('indicator_entry_id')->constrained('entry_revisions')->restrictOnDelete();
            $table->text('amendment_reason')->nullable()->after('notes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('entry_revisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supersedes_revision_id');
            $table->dropColumn('amendment_reason');
        });
    }
};
