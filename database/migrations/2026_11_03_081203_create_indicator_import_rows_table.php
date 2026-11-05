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
        Schema::create('indicator_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('indicator_import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('source');
            $table->json('proposed_indicator')->nullable();
            $table->json('centre_ids')->nullable();
            $table->string('status');
            $table->text('error')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->unique(['indicator_import_batch_id', 'row_number'], 'indicator_import_batch_row_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('indicator_import_rows');
    }
};
