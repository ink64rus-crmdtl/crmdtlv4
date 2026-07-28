<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            
            $table->string('status')->default('new')->comment('new, in_progress, ready, completed, canceled');
            $table->string('payment_status')->default('unpaid')->comment('unpaid, partial, paid');
            $table->integer('mileage')->nullable()->comment('Vehicle mileage at the time of order');
            
            // Денежные поля (Фаза 5: integer + currency_id)
            $table->integer('total_amount')->default(0)->comment('In minimal currency units (cents/kopecks)');
            $table->integer('discount_amount')->default(0);
            $table->integer('final_amount')->default(0);
            $table->unsignedBigInteger('currency_id')->nullable()->comment('References tenant_currencies or central currencies');
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};