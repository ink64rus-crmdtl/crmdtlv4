<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Перенесено из
 * 2026_08_05_054322_add_business_direction_to_service_categories_table.php
 * — там service_categories ещё не существовала на новой базе тенанта
 * (создаётся 2026_12_31_900001, позже по порядку выполнения). hasColumn-
 * проверка — на уже смигрировавших тенантах обычный addColumn упал бы с
 * "duplicate column" при первом же tenants:migrate после обновления кода.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_categories', 'business_direction_id')) {
            Schema::table('service_categories', function (Blueprint $table) {
                $table->foreignId('business_direction_id')->nullable()->after('id')->constrained('business_directions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropForeign(['business_direction_id']);
            $table->dropColumn('business_direction_id');
        });
    }
};
