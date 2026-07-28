<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete()->comment('Null if central company account');
            $table->string('name');
            $table->string('type')->default('cash')->comment('cash, bank, acquiring');
            $table->unsignedBigInteger('currency_id')->nullable();
            $table->integer('balance')->default(0)->comment('Cached balance in minimal currency units');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};