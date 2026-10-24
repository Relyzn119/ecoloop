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
        Schema::table('products', function (Blueprint $table): void {
            $table->string('external_id', 100)->nullable()->unique();
            $table->string('source_system', 50)->nullable()->index();
            $table->enum('status', ['active', 'discounted', 'reserved_donation', 'donated', 'disposed', 'sold_out'])
                ->default('active')
                ->change();
        });

        Schema::table('product_batches', function (Blueprint $table): void {
            $table->string('store_code', 32)->nullable()->index();
            $table->timestamp('source_synced_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('products')->where('status', 'sold_out')->update(['status' => 'active']);

        Schema::table('product_batches', function (Blueprint $table): void {
            $table->dropColumn(['store_code', 'source_synced_at']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropUnique(['external_id']);
            $table->dropIndex(['source_system']);
            $table->dropColumn(['external_id', 'source_system']);
            $table->enum('status', ['active', 'discounted', 'reserved_donation', 'donated', 'disposed'])
                ->default('active')
                ->change();
        });
    }
};
