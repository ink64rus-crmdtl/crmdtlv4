<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Перенесено из 2026_07_30_160249_add_details_to_employees_table.php.php —
 * там employees ещё не существовала на новой базе тенанта (создаётся
 * 2026_12_31_900022, позже по порядку выполнения). hasColumn-проверка —
 * на уже смигрировавших тенантах (получивших эти колонки через старую
 * версию той миграции ДО фикса) обычный addColumn упал бы с "duplicate
 * column" при первом же tenants:migrate после обновления кода.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (! Schema::hasColumn('employees', 'middle_name')) {
                $table->string('middle_name')->nullable()->after('last_name');
            }
            if (! Schema::hasColumn('employees', 'personal_email')) {
                $table->string('personal_email')->nullable()->after('phone');
            }
            if (! Schema::hasColumn('employees', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('personal_email');
            }
            if (! Schema::hasColumn('employees', 'hire_date')) {
                $table->date('hire_date')->nullable()->after('birth_date');
            }
            if (! Schema::hasColumn('employees', 'termination_date')) {
                $table->date('termination_date')->nullable()->after('hire_date');
            }
            if (! Schema::hasColumn('employees', 'passport_data')) {
                $table->json('passport_data')->nullable()->after('termination_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn([
                'middle_name',
                'personal_email',
                'birth_date',
                'hire_date',
                'termination_date',
                'passport_data',
            ]);
        });
    }
};
