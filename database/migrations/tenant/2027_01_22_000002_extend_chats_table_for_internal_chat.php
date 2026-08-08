<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * chats изначально проектировался только под внешние переписки (клиент+канал
     * обязательны). Раз планируется PWA — заодно закладываем внутренний чат
     * сотрудников на той же таблице/UI-компоненте, а не городим отдельную сущность:
     * type='internal' — branch_id/client_id/channel_id пустые, участники — в новой
     * chat_participants; type='external' — как было, обязательные client_id+channel_id
     * (это по-прежнему гарантируется в коде контроллера, не в схеме — оба
     * варианта теперь физически допустимы, чтобы одна таблица обслуживала оба).
     */
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('type')->default('external')->after('id')->comment('external — с клиентом через канал; internal — между сотрудниками');
            $table->string('title')->nullable()->after('external_chat_id')->comment('Для internal — название группового чата');
            $table->timestamp('last_message_at')->nullable()->after('title');
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('branch_id')->nullable()->change();
            $table->foreignId('client_id')->nullable()->change();
            $table->foreignId('channel_id')->nullable()->change();
            $table->string('external_chat_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['type', 'title', 'last_message_at']);
        });
    }
};
