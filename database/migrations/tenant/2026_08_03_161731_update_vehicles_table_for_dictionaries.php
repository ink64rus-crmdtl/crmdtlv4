<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['make', 'model']);
            $table->foreignId('vehicle_make_id')->nullable()->after('client_id')->constrained('vehicle_makes')->nullOnDelete();
            $table->foreignId('vehicle_model_id')->nullable()->after('vehicle_make_id')->constrained('vehicle_models')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['vehicle_model_id']);
            $table->dropForeign(['vehicle_make_id']);
            $table->dropColumn(['vehicle_model_id', 'vehicle_make_id']);
            $table->string('make')->after('client_id');
            $table->string('model')->after('make');
        });
    }
};