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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('sku', 64)->unique();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->integer('quantity');
            $table->string('unit', 32);
            $table->decimal('original_price', 10, 2);
            $table->decimal('discount_price', 10, 2)->nullable();
            $table->date('expiration_date')->index();
            $table->enum('status', ['active', 'discounted', 'reserved_donation', 'donated', 'disposed'])->default('active')->index();
            $table->timestamps();

            // Composite index for fast expiration detection based on status
            $table->index(['status', 'expiration_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
