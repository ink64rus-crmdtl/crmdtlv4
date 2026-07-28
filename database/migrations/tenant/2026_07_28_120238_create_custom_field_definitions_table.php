<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->index();
            $table->string('key');
            $table->json('label');
            $table->string('type');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_filterable')->default(false);
            $table->boolean('is_visible_in_list')->default(true);
            $table->integer('sort_order')->default(0);
            $table->json('validation_rules')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('module_id')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['entity_type', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_definitions');
    }
};