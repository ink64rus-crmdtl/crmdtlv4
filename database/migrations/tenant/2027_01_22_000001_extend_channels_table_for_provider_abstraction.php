<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // provider = чей API используем (wappi_pro, green_api, sms_aero...) — сама
            // реализация подключается через MessengerProviderInterface/SmsProviderInterface
            // (см. app/Services/Messaging), это поле только выбирает конкретный класс.
            // messenger_type = какой канал связи это логически (whatsapp/telegram/max/sms/
            // internal) — не совпадает с provider: у одного provider'а (wappi_pro) может
            // быть несколько messenger_type (whatsapp И telegram через один и тот же API).
            $table->string('messenger_type')->nullable()->after('provider')->comment('whatsapp, telegram, max, sms, internal');
            $table->foreignId('branch_id')->nullable()->after('id')->constrained('branches')->nullOnDelete()->comment('null — канал общий на весь тенант');
            $table->string('external_profile_id')->nullable()->after('credentials')->comment('profile_id в Wappi / instance id в Green API и т.п.');
            $table->string('phone_number')->nullable()->after('external_profile_id');
            $table->uuid('webhook_token')->nullable()->after('phone_number')->unique()->comment('Часть URL вебхука — по нему находим канал без доверия к данным от провайдера');
            $table->string('status')->default('pending')->after('webhook_token')->comment('pending, connected, disconnected');
        });

        DB::table('channels')->whereNull('webhook_token')->get(['id'])->each(function ($channel) {
            DB::table('channels')->where('id', $channel->id)->update(['webhook_token' => (string) Str::uuid()]);
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['messenger_type', 'external_profile_id', 'phone_number', 'webhook_token', 'status']);
        });
    }
};
