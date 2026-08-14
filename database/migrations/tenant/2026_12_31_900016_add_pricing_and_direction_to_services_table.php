<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Перенесено из
 * 2026_08_05_050511_add_pricing_and_direction_to_services_table.php —
 * там services ещё не существовала на новой базе тенанта (создаётся
 * 2026_12_31_900002, позже по порядку выполнения). hasColumn-проверки —
 * на уже смигрировавших тенантах обычный addColumn упал бы с "duplicate
 * column" при первом же tenants:migrate после обновления кода.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'business_direction_id')) {
                $table->foreignId('business_direction_id')->nullable()->after('service_category_id')->constrained('business_directions')->nullOnDelete();
            }
            if (! Schema::hasColumn('services', 'prices')) {
                $table->json('prices')->nullable()->after('price')->comment('Dynamic pricing matrix based on body type or class');
            }
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
