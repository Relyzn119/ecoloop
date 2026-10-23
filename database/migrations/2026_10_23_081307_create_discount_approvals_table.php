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
        Schema::create('discount_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('recommended_percentage');
            $table->decimal('recommended_price', 10, 2);
            $table->unsignedTinyInteger('approved_percentage')->nullable();
            $table->decimal('approved_price', 10, 2)->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->text('reasoning');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['product_batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discount_approvals');
    }
};
