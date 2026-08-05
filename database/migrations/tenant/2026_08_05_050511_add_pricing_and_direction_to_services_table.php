<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('business_direction_id')->nullable()->after('service_category_id')->constrained('business_directions')->nullOnDelete();
            $table->json('prices')->nullable()->after('price')->comment('Dynamic pricing matrix based on body type or class');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeign(['business_direction_id']);
            $table->dropColumn(['business_direction_id', 'prices']);
        });
    }
};