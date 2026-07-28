<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('entity_type')->index();
            $table->string('field_key')->index()->comment('System field name or custom_field_definition_id');
            $table->boolean('can_view')->default(true);
            $table->boolean('can_edit')->default(true);
            $table->timestamps();

            $table->unique(['role_id', 'entity_type', 'field_key'], 'field_permissions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_permissions');
    }
};