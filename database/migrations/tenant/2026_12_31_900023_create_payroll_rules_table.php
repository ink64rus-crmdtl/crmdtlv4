<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->nullable()->constrained('positions')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->cascadeOnDelete();
            
            $table->string('type')->default('fixed')->comment('fixed, percentage');
            $table->integer('fixed_amount')->default(0)->comment('In minimal currency units');
            $table->decimal('percentage_value', 5, 2)->default(0.00)->comment('e.g. 15.50 for 15.5%');
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_rules');
    }
};