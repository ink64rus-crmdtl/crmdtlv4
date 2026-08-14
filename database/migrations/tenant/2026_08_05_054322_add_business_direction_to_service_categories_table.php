<?php

use Illuminate\Database\Migrations\Migration;

// Содержимое перенесено в
// 2026_12_31_900017_add_business_direction_to_service_categories_table.php
// — service_categories создаётся ПОЗЖЕ (2026_12_31_900001), чем эта
// миграция по своей дате (2026_08_05), поэтому ALTER здесь падал на
// КАЖДОЙ новой базе тенанта (регистрация нового тенанта была сломана).
// Уже смигрировавшие тенанты этот файл повторно не выполняют.
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
