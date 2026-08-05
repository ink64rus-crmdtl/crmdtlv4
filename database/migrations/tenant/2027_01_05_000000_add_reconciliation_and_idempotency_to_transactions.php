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
            // Для дедупликации записей, приходящих от вебхуков банков/эквайринга при повторной доставке.
            $table->string('idempotency_key')->nullable()->unique()->after('comment');

            $table->boolean('is_reconciled')->default(false)->after('idempotency_key');
            $table->timestamp('reconciled_at')->nullable()->after('is_reconciled');
            $table->foreignId('reconciled_by')->nullable()->after('reconciled_at')->constrained('users')->nullOnDelete();

            // Явное направление ноги перевода (in/out) — нужно для разбивки оборотов в дневных снэпшотах.
            // Раньше направление можно было понять только по тексту comment, что ненадежно (комментарий редактируемый).
            $table->string('direction')->nullable()->after('type')->comment('in/out — только для type=transfer');
        });

        // Best-effort бэкафилл направления для уже существующих переводов по тексту комментария.
        DB::table('transactions')->where('type', 'transfer')->where('comment', 'LIKE', 'Перевод на счет:%')->update(['direction' => 'out']);
        DB::table('transactions')->where('type', 'transfer')->where('comment', 'LIKE', 'Перевод со счета:%')->update(['direction' => 'in']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropForeign(['reconciled_by']);
            $table->dropColumn(['idempotency_key', 'is_reconciled', 'reconciled_at', 'reconciled_by', 'direction']);
        });
    }
};
