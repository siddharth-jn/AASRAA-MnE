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
        Schema::table('indicator_definitions', function (Blueprint $table) {
            $table->text('objective')->nullable()->after('description');
            $table->text('data_collection_method')->nullable()->after('objective');
            $table->string('data_collection_frequency')->nullable()->after('data_collection_method');
            $table->text('responsible_persons')->nullable()->after('data_collection_frequency');
            $table->text('data_sources')->nullable()->after('responsible_persons');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('indicator_definitions', function (Blueprint $table) {
            $table->dropColumn([
                'objective',
                'data_collection_method',
                'data_collection_frequency',
                'responsible_persons',
                'data_sources',
            ]);
        });
    }
};
