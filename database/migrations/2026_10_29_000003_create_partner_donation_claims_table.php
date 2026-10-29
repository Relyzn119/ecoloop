<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_donation_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('product_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('donation_partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('unit', 24);
            $table->text('reasoning');
            $table->string('status', 32)->default('pending_stock_decision')->index();
            $table->boolean('stock_reduction_applied')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['product_batch_id', 'status'], 'partner_donation_claim_batch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_donation_claims');
    }
};
