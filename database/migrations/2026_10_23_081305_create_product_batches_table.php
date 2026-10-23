<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('batch_code', 100)->nullable()->index();
            $table->unsignedInteger('quantity_received');
            $table->unsignedInteger('quantity_available');
            $table->unsignedInteger('quantity_donated')->default(0);
            $table->unsignedInteger('quantity_sold')->default(0);
            $table->date('expiration_date')->index();
            $table->decimal('original_price', 10, 2);
            $table->decimal('discount_price', 10, 2)->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->string('source', 24)->default('manual')->index();
            $table->string('source_reference', 150)->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_reference']);
            $table->index(['product_id', 'status', 'expiration_date']);
        });

        DB::table('products')->orderBy('id')->each(function (object $product): void {
            DB::table('product_batches')->insert([
                'product_id' => $product->id,
                'batch_code' => 'LEGACY-'.$product->id,
                'quantity_received' => max(0, (int) $product->quantity),
                'quantity_available' => max(0, (int) $product->quantity),
                'expiration_date' => $product->expiration_date,
                'original_price' => $product->original_price,
                'discount_price' => $product->discount_price,
                'status' => $product->status,
                'source' => 'legacy',
                'source_reference' => (string) $product->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
