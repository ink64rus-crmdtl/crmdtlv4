<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_branches', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->primary(['user_id', 'branch_id']);
        });

        Schema::create('user_legal_entities', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained('legal_entities')->cascadeOnDelete();
            $table->primary(['user_id', 'legal_entity_id']);
        });

        Schema::create('user_business_directions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('business_direction_id')->constrained('business_directions')->cascadeOnDelete();
            $table->primary(['user_id', 'business_direction_id']);
        });

        Schema::create('user_warehouses', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->primary(['user_id', 'warehouse_id']);
        });

        Schema::create('user_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->primary(['user_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_accounts');
        Schema::dropIfExists('user_warehouses');
        Schema::dropIfExists('user_business_directions');
        Schema::dropIfExists('user_legal_entities');
        Schema::dropIfExists('user_branches');
    }
};