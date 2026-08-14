<?php

use Illuminate\Database\Migrations\Migration;

// Содержимое перенесено в 2026_12_31_900025_add_details_to_employees_table.php
// — таблица employees создаётся ПОЗЖЕ (2026_12_31_900022), чем эта миграция
// по своей дате (2026_07_30), поэтому ALTER здесь падал с "table employees
// doesn't exist" на КАЖДОЙ новой базе тенанта (регистрация нового тенанта
// была сломана). Уже смигрировавшие тенанты этот файл повторно не
// выполняют — на них не влияет, колонки у них уже есть.
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
