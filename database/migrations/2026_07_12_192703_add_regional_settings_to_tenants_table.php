<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('country_code', 2)->default('DE')->comment('ISO 3166-1 alpha-2 (e.g., DE, RU, KZ)');
            $table->string('base_currency', 3)->default('EUR')->comment('ISO 4217 (e.g., EUR, RUB, KZT)');
            $table->string('timezone', 50)->default('Europe/Berlin')->comment('PHP Timezone (e.g., Europe/Berlin)');
            $table->string('default_locale', 5)->default('de')->comment('Locale (e.g., de, ru, en)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn([
                'country_code',
                'base_currency',
                'timezone',
                'default_locale',
            ]);
        });
    }
};