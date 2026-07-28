<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('channels')->cascadeOnDelete();
            $table->string('external_chat_id')->comment('ID in the provider system');
            $table->timestamps();
            $table->softDeletes();
            
            $table->unique(['channel_id', 'external_chat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};