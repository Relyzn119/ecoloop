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
        Schema::create('donation_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('contact_person', 150);
            $table->string('phone', 50);
            $table->string('email', 150);
            $table->text('address');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donation_partners');
    }
};
