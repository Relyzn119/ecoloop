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
        Schema::create('inventory_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger_type', 24);
            $table->string('status', 24)->index();
            $table->timestamp('started_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->json('summary')->nullable();
            $table->json('details')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['trigger_type', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_sync_runs');
    }
};
