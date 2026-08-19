<?php

use App\Models\TransactionCategory;
use App\Services\FinanceCounterpartyBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Системные статьи доходов/расходов (Фаза А финансовой доработки).
     * value — стабильный слаг (по образцу client_roles), по нему же идёт сид;
     * label живёт в translatable name. Системные статьи защищены от правки/удаления
     * на уровне TransactionCategoryController (тот же паттерн, что Lookup.is_system).
     */
    private const SYSTEM_CATEGORIES = [
        'order_payment' => ['label' => 'Оплата заказа', 'type' => 'income'],
        'client_deposit' => ['label' => 'Пополнение депозита', 'type' => 'income'],
        'other_income' => ['label' => 'Прочие доходы', 'type' => 'income'],
        'purchase_payment' => ['label' => 'Оплата поставщику', 'type' => 'expense'],
        'payroll_payment' => ['label' => 'Выплата зарплаты', 'type' => 'expense'],
        'refund' => ['label' => 'Возврат клиенту', 'type' => 'expense'],
        'commission' => ['label' => 'Комиссия эквайринга', 'type' => 'expense'],
        'other_expense' => ['label' => 'Прочие расходы', 'type' => 'expense'],
    ];

    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Контрагент операции — полиморф (Client | Employee). Заполнен всегда для
            // ручных доходов/расходов; пуст для переводов и комиссии эквайринга.
            $table->nullableMorphs('counterparty');
        });

        Schema::table('transaction_categories', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('type');
            $table->string('value')->nullable()->after('is_system');
            $table->unique('value');
        });

        // Сид системных статей (идемпотентно, с восстановлением из soft-deleted)
        foreach (self::SYSTEM_CATEGORIES as $slug => $config) {
            $category = TransactionCategory::withTrashed()->updateOrCreate(
                ['value' => $slug],
                [
                    'name' => ['ru' => $config['label']],
                    'type' => $config['type'],
                    'is_active' => true,
                    'is_system' => true,
                ]
            );
            if ($category->trashed()) {
                $category->restore();
            }
        }

        // Контрагент для уже проведённых операций, где он однозначно выводится из payable
        FinanceCounterpartyBackfill::run();
    }

    public function down(): void
    {
        // Системные статьи удаляем только если они ещё помечены как системные
        // (пользователь не мог их править — блокировка в контроллере), мягко,
        // чтобы ссылки транзакций (FK nullOnDelete) не оборвались жёстко.
        TransactionCategory::where('is_system', true)
            ->whereIn('value', array_keys(self::SYSTEM_CATEGORIES))
            ->delete();

        Schema::table('transaction_categories', function (Blueprint $table) {
            $table->dropUnique(['value']);
            $table->dropColumn(['value', 'is_system']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropMorphs('counterparty');
        });
    }
};