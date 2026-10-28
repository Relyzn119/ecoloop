<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('donation_partners', function (Blueprint $table): void {
            $table->string('partner_type', 24)->default('foundation')->index()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('donation_partners', function (Blueprint $table): void {
            $table->dropIndex(['partner_type']);
            $table->dropColumn('partner_type');
        });
    }
};
