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
        Schema::create('agent_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->enum('action_type', ['discount_applied', 'donation_requested', 'alert_sent', 'manual_override'])->index();
            $table->text('reasoning');
            $table->json('payload');
            $table->enum('status', ['success', 'failed', 'pending'])->default('success')->index();
            $table->timestamps();

            // Index for chronological agent activity feeds
            $table->index(['created_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_logs');
    }
};
