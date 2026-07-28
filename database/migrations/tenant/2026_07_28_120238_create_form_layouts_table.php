<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->index();
            $table->string('name');
            $table->json('layout');
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('role_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_layouts');
    }
};