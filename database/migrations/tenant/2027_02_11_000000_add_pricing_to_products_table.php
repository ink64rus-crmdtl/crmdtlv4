<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('base_price')->nullable()->comment('Цена продажи, копейки')->after('unit');
            $table->decimal('markup_percent', 5, 2)->nullable()->comment('Наценка от текущей средней себестоимости, %')->after('base_price');
            $table->decimal('discount_percent', 5, 2)->nullable()->comment('Скидка от цены продажи по умолчанию при добавлении в заказ, %')->after('markup_percent');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['base_price', 'markup_percent', 'discount_percent']);
        });
    }
};
