<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            // nullOnDelete, а не cascade: движение — исторический факт склада,
            // не должно исчезать вслед за накладной (которая в норме и не
            // удаляется — см. GoodsReceipt::cancel(), это реверс, а не delete).
            $table->foreignId('goods_receipt_id')->nullable()->after('work_order_id')
                ->constrained('goods_receipts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('goods_receipt_id');
        });
    }
};
