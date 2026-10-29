<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_automation_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            // NULL inherits the global InventorySyncSetting value; true/false overrides it.
            $table->boolean('auto_donation_enabled')->nullable();
            $table->boolean('auto_discount_enabled')->nullable();
            $table->boolean('auto_donation_prealert_enabled')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_automation_policies');
    }
};
