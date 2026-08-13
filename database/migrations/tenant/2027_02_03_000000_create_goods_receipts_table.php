<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Приходная накладная — шапка поставки (Фаза "Поставщики", см. CLAUDE.md).
 * Раньше оприходование (StockMovementController::storeReceipt()) создавало
 * несколько НИЧЕМ не связанных между собой StockMovement за один сабмит —
 * нельзя было увидеть "эту конкретную поставку" целиком, только россыпь
 * строк в общем журнале. Теперь позиции (goods_receipt_items) объединяются
 * под одной накладной, а движения (stock_movements.goods_receipt_id)
 * ссылаются на неё для трассировки.
 *
 * Своего нумератора (DocumentNumerator) НЕ используем — это не исходящий
 * фискальный документ со сплошной нумерацией, а внутренняя запись; для
 * отображения достаточно id (тот же приём, что у WorkOrder — "Заказ #000123"
 * без отдельной колонки number). Номер НА БУМАГЕ от поставщика — отдельное
 * поле supplier_document_number, свободный текст: это чужая нумерация, мы
 * её не контролируем и не проверяем на уникальность.
 *
 * В этой фазе НЕ делали привязку к Transaction/кассе и генерацию PDF через
 * DocumentTemplate — оба добавлены позже отдельными миграциями/правками
 * (см. GoodsReceipt::transactions()/documents(), CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('clients')->cascadeOnDelete()
                ->comment('Client с системной ролью «Поставщик» — проверяется в контроллере, не на уровне схемы');
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->nullable()->constrained('legal_entities')->nullOnDelete()
                ->comment('От чьего имени приняли товар — опционально, как у WorkOrder.legal_entity_id');
            $table->date('receipt_date')->comment('Дата фактического поступления товара, не дата создания записи в системе');
            $table->string('supplier_document_number')->nullable()->comment('Номер накладной/счёта ПОСТАВЩИКА (их бумага, не наша нумерация)');
            $table->string('status')->default('posted')->comment('posted — учтено на складе; canceled — приход отменён, движения реверсированы');
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipts');
    }
};
