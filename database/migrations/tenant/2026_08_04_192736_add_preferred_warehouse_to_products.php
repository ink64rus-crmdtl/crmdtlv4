<?php

use Illuminate\Database\Migrations\Migration;

// Содержимое перенесено в
// 2026_12_31_900015_add_preferred_warehouse_to_products_table.php —
// products/warehouses создаются ПОЗЖЕ (2026_12_31_900004/900005), чем эта
// миграция по своей дате (2026_08_04), поэтому ALTER здесь падал на
// КАЖДОЙ новой базе тенанта (регистрация нового тенанта была сломана).
// Уже смигрировавшие тенанты этот файл повторно не выполняют.
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
