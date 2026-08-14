<?php

use Illuminate\Database\Migrations\Migration;

// Содержимое перенесено в
// 2027_01_01_500000_add_employee_id_to_work_order_items_table.php —
// work_order_items создаётся ПОЗЖЕ (2026_12_31_999999), чем эта миграция
// по своей дате (2026_08_05), поэтому ALTER здесь падал на КАЖДОЙ новой
// базе тенанта (регистрация нового тенанта была сломана). Уже
// смигрировавшие тенанты этот файл повторно не выполняют.
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
