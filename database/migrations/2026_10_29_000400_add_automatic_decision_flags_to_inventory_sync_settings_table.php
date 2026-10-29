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
        Schema::table('inventory_sync_settings', function (Blueprint $table): void {
            $table->boolean('auto_donation_enabled')->default(false);
            $table->boolean('auto_discount_enabled')->default(false);
            $table->boolean('auto_donation_prealert_enabled')->default(false);
            $table->foreignId('auto_decision_activated_by')->nullable()->after('auto_donation_prealert_enabled')->constrained('users')->nullOnDelete();
            $table->timestamp('auto_decision_activated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventory_sync_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('auto_decision_activated_by');
            $table->dropColumn([
                'auto_donation_enabled',
                'auto_discount_enabled',
                'auto_donation_prealert_enabled',
                'auto_decision_activated_at',
            ]);
        });
    }
};
