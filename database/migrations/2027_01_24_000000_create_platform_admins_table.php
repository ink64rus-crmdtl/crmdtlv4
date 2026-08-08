<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central (landlord) БД — НЕ database/migrations/tenant/. Отдельная таблица
 * от тенантских users: администратор платформы не должен тащить с собой
 * тенантские трейты/связи (роли, Employee и т.п.), которых в central-контексте
 * нет и не должно быть. Гвард — 'platform_admin' (config/auth.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
