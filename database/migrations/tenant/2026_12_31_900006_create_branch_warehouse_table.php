<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_warehouse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->integer('priority')->default(1)->comment('Порядок списания');
            $table->timestamps();
            
            $table->unique(['branch_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_warehouse');
    }
};
