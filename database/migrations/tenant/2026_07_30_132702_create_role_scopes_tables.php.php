<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_branches', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->primary(['role_id', 'branch_id']);
        });

        Schema::create('role_legal_entities', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->primary(['role_id', 'legal_entity_id']);
        });

        Schema::create('role_business_directions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('business_direction_id')->constrained('business_directions')->cascadeOnDelete();
            $table->primary(['role_id', 'business_direction_id']);
        });

        Schema::create('role_warehouses', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->primary(['role_id', 'warehouse_id']);
        });

        Schema::create('role_accounts', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->primary(['role_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_accounts');
        Schema::dropIfExists('role_warehouses');
        Schema::dropIfExists('role_business_directions');
        Schema::dropIfExists('role_legal_entities');
        Schema::dropIfExists('role_branches');
    }
};