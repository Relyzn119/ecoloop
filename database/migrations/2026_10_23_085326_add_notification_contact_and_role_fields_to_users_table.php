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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('role', 24)->default('cashier')->index()->after('phone');
            $table->string('telegram_chat_id', 64)->nullable()->unique()->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['telegram_chat_id']);
            $table->dropIndex(['role']);
            $table->dropColumn(['phone', 'role', 'telegram_chat_id']);
        });
    }
};
