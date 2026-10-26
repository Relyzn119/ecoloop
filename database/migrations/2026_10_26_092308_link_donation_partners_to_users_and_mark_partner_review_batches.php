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
        Schema::table('donation_partners', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
        });

        Schema::table('product_batches', function (Blueprint $table): void {
            $table->timestamp('partner_review_ready_at')->nullable();
            $table->foreignId('partner_review_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['partner_review_ready_at', 'status', 'expiration_date'], 'product_batches_partner_review_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table): void {
            $table->dropIndex('product_batches_partner_review_index');
            $table->dropForeign(['partner_review_requested_by']);
            $table->dropColumn(['partner_review_ready_at', 'partner_review_requested_by']);
        });

        Schema::table('donation_partners', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
