<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('transaction_category_id')->nullable()->constrained('transaction_categories')->nullOnDelete();
            
            // Полиморфная связь (оплата Заказа, Зарплаты, Закупки и т.д.)
            $table->nullableMorphs('payable');
            
            $table->string('type')->comment('income, expense, transfer');
            $table->integer('amount')->comment('In minimal currency units');
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->decimal('exchange_rate_snapshot', 15, 6)->default(1.000000)->comment('Historical exchange rate to base currency');
            
            $table->text('comment')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};