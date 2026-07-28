<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('e.g., orders, finance, warehouse');
            $table->json('label')->comment('Translatable name');
            $table->string('icon')->nullable();
            $table->boolean('is_core')->default(false)->comment('True for built-in modules');
            $table->boolean('is_enabled')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignId('parent_id')->nullable()->constrained('modules')->nullOnDelete();
            $table->string('required_permission')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};