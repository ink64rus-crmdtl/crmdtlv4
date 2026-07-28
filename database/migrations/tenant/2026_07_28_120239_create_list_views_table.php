<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('list_views', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->json('visible_columns');
            $table->json('filters')->nullable();
            $table->json('sort')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_views');
    }
};