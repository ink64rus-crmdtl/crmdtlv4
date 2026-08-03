<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('client_group_id')->nullable()->after('branch_id')->constrained('client_groups')->nullOnDelete();
            $table->string('alias')->nullable()->after('name')->comment('Псевдоним / Краткое название');
            $table->string('phone_2')->nullable()->after('phone');
            $table->string('source')->nullable()->after('email')->comment('Источник привлечения');
            $table->date('birth_date')->nullable()->after('source');
            $table->text('comment')->nullable()->after('birth_date');
            $table->integer('balance')->default(0)->after('comment')->comment('Лицевой счет (в копейках)');
            $table->integer('bonus_points')->default(0)->after('balance')->comment('Бонусные баллы');
            $table->json('requisites')->nullable()->after('bonus_points')->comment('Реквизиты (Паспорт или Юр. данные)');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['client_group_id']);
            $table->dropColumn([
                'client_group_id',
                'alias',
                'phone_2',
                'source',
                'birth_date',
                'comment',
                'balance',
                'bonus_points',
                'requisites'
            ]);
        });
    }
};