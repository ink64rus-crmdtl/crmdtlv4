<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_daily_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->date('snapshot_date');

            // Все суммы в минимальных единицах валюты (копейках)
            $table->integer('opening_balance')->default(0);
            $table->integer('income_total')->default(0);
            $table->integer('expense_total')->default(0);
            $table->integer('transfer_in_total')->default(0);
            $table->integer('transfer_out_total')->default(0);
            $table->integer('closing_balance')->default(0);

            $table->timestamps();

            $table->unique(['account_id', 'snapshot_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_daily_snapshots');
    }
};
