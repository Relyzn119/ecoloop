<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_ai_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('telegram_chat_id', 64);
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action_type', 32);
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->string('status', 24)->default('pending')->index();
            $table->timestamp('expires_at')->index();
            $table->timestamp('completed_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['requested_by', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_ai_actions');
    }
};
