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
        Schema::create('agent_executions', function (Blueprint $table) {
            $table->id();
            $table->string('execution_id')->nullable()->index();
            $table->string('event_type', 32)->index();
            $table->timestamp('execution_time')->nullable();
            $table->unsignedInteger('total_processed')->default(0);
            $table->unsignedInteger('total_discounted')->default(0);
            $table->unsignedInteger('total_donated')->default(0);
            $table->unsignedInteger('total_skipped')->default(0);
            $table->unsignedInteger('total_failed')->default(0);
            $table->string('status', 20)->default('success')->index();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_executions');
    }
};
