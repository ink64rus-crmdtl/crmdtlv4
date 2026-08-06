<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('calendar_color', 20)->nullable()->after('prevent_overlapping_appointments');
            $table->string('icon', 50)->nullable()->after('calendar_color');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['calendar_color', 'icon']);
        });
    }
};
