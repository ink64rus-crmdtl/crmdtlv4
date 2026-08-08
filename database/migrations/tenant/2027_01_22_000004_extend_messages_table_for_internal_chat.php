<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('sender_type')->default('client')->after('chat_id')->comment('client, user, system');
            $table->foreignId('sender_user_id')->nullable()->after('sender_type')->constrained('users')->nullOnDelete()->comment('Заполнено, когда sender_type=user (менеджер отправил внешнее сообщение или участник internal-чата)');
            $table->json('attachments')->nullable()->after('content');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->string('direction')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sender_user_id');
            $table->dropColumn(['sender_type', 'attachments']);
        });
    }
};
