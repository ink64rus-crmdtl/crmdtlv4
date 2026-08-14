<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вынесено из 2026_07_30_114524_create_user_scopes_tables.php и
 * 2026_07_30_132702_create_role_scopes_tables.php.php — там эти четыре
 * таблицы (user_warehouses/user_accounts/role_warehouses/role_accounts)
 * падали с "Failed to open the referenced table" на новой базе тенанта,
 * т.к. warehouses/accounts создаются миграциями 2026_12_31_900005/900012,
 * которые по порядку выполнения идут ПОСЛЕ 2026_07_30 — регистрация
 * НОВОГО тенанта была сломана целиком (ни один тенант не мог
 * зарегистрироваться, пока чинил план внедрения НДС и наткнулся на это).
 * Здесь порядок гарантированно верный. Schema::hasTable()-проверки — на
 * уже смигрировавших тенантах (создавших все четыре таблицы через старые
 * версии тех миграций ДО этого фикса) обычный Schema::create() упал бы с
 * "table already exists" при первом же tenants:migrate после обновления кода.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_warehouses')) {
            Schema::create('user_warehouses', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->primary(['user_id', 'warehouse_id']);
            });
        }

        if (! Schema::hasTable('user_accounts')) {
            Schema::create('user_accounts', function (Blueprint $table) {
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
                $table->primary(['user_id', 'account_id']);
            });
        }

        if (! Schema::hasTable('role_warehouses')) {
            Schema::create('role_warehouses', function (Blueprint $table) {
                $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->primary(['role_id', 'warehouse_id']);
            });
        }

        if (! Schema::hasTable('role_accounts')) {
            Schema::create('role_accounts', function (Blueprint $table) {
                $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
                $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
                $table->primary(['role_id', 'account_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_accounts');
        Schema::dropIfExists('role_warehouses');
        Schema::dropIfExists('user_accounts');
        Schema::dropIfExists('user_warehouses');
    }
};
