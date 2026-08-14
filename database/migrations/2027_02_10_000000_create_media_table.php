<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (landlord) БД. Копия тенантской database/migrations/tenant/
 * 2027_01_25_000002_create_media_table.php (сам штатный стаб пакета
 * spatie/laravel-medialibrary) — до App\Models\Central\
 * PlatformDocumentTemplate ни одна central-модель HasMedia не использовала,
 * поэтому таблица media существовала только в тенантских БД. media —
 * per-connection таблица (как и everything у Spatie MediaLibrary), поэтому
 * нужна отдельная копия здесь, а не переиспользование тенантской миграции.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();

            $table->morphs('model');
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index();

            $table->nullableTimestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
