<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Перенесено из 2026_08_04_192736_add_preferred_warehouse_to_products.php —
 * там products/warehouses ещё не существовали на новой базе тенанта
 * (создаются 2026_12_31_900004/900005, позже по порядку выполнения).
 * hasColumn-проверка — на уже смигрировавших тенантах обычный addColumn
 * упал бы с "duplicate column" при первом же tenants:migrate после
 * обновления кода.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'preferred_warehouse_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('preferred_warehouse_id')->nullable()->after('accounting_type')->constrained('warehouses')->nullOnDelete()->comment('Для смешанного режима склада');
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['preferred_warehouse_id']);
            $table->dropColumn('preferred_warehouse_id');
        });
    }
};
