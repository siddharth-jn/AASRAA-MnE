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
        Schema::create('centre_donors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centre_id')->constrained()->restrictOnDelete();
            $table->foreignId('donor_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->text('notes')->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestamps();
            $table->unique(['centre_id', 'donor_id', 'starts_on'], 'centre_donor_period_unique');
            $table->index(['centre_id', 'starts_on', 'ends_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('centre_donors');
    }
};
