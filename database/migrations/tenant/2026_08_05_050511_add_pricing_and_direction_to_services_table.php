<?php

use Illuminate\Database\Migrations\Migration;

// Содержимое перенесено в
// 2026_12_31_900016_add_pricing_and_direction_to_services_table.php —
// services создаётся ПОЗЖЕ (2026_12_31_900002), чем эта миграция по своей
// дате (2026_08_05), поэтому ALTER здесь падал на КАЖДОЙ новой базе
// тенанта (регистрация нового тенанта была сломана). Уже смигрировавшие
// тенанты этот файл повторно не выполняют.
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
