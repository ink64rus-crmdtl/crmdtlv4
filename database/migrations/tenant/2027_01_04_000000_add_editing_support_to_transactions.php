<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Дата операции (можно редактировать/вносить задним числом) — отдельно от created_at,
            // который остается неизменным техническим временем создания записи в системе.
            $table->date('transaction_date')->nullable()->after('amount');
            $table->timestamp('edited_at')->nullable()->after('created_by');
            $table->foreignId('edited_by')->nullable()->after('edited_at')->constrained('users')->nullOnDelete();
        });

        // Бэкафилл: для уже существующих записей дата операции = дата создания.
        // Колонка остается nullable на уровне БД (нет doctrine/dbal для ->change()),
        // но приложение всегда проставляет значение при создании/редактировании.
        DB::statement('UPDATE transactions SET transaction_date = DATE(created_at) WHERE transaction_date IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['edited_by']);
            $table->dropColumn(['transaction_date', 'edited_at', 'edited_by']);
        });
    }
};
