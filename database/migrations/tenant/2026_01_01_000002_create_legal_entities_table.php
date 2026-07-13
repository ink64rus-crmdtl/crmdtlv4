<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_entities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('tax_id')->nullable()->comment('VAT, INN, BIN, etc.');
            $table->json('requisites')->nullable()->comment('Flexible JSON for different countries');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_entities');
    }
};
