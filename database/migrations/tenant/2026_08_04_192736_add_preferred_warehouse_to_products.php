<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('preferred_warehouse_id')->nullable()->after('accounting_type')->constrained('warehouses')->nullOnDelete()->comment('Для смешанного режима склада');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['preferred_warehouse_id']);
            $table->dropColumn('preferred_warehouse_id');
        });
    }
};