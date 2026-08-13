<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Роль клиента была одиночной строкой (clients.role, свободный текст из
 * справочника client_role) — у клиента реально может быть сразу несколько
 * ролей одновременно (например, он и Подрядчик, и Поставщик), старая схема
 * этого не допускала. Было 1:0..1, стало многие-ко-многим через пивот на
 * тот же справочник Lookup (type=client_role) — управление списком
 * возможных ролей (добавление/переименование/удаление) остаётся прежним,
 * меняется только то, как роль ПРИСВАИВАЕТСЯ клиенту.
 *
 * Заодно заводим 3 базовые системные роли (is_system=true, как и остальные
 * защищённые записи справочников, см. LookupController::update()/destroy())
 * — их нельзя переименовать/удалить, но можно продолжать добавлять свои
 * поверх них, как и раньше.
 *
 * up() переносит текущие одиночные роли в пивот и удаляет исходную колонку —
 * сознательное отступление от "никогда не удаляй колонки" (CLAUDE.md, п.8):
 * прямо запрошенная архитектурная замена связи, данные переносятся, а не
 * выбрасываются (см. прецедент branch_legal_entity, миграция 2027_01_28).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('lookup_id')->constrained('lookups')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'lookup_id']);
        });

        $now = now();

        // 3 базовые системные роли — заводим, если ещё нет; если пользователь
        // уже успел сам создать lookup с таким же значением до этой миграции,
        // просто помечаем его системным задним числом (не дублируем запись).
        foreach (['Клиент', 'Подрядчик', 'Поставщик'] as $index => $value) {
            $existingId = DB::table('lookups')->where('type', 'client_role')->where('value', $value)->value('id');

            if ($existingId) {
                DB::table('lookups')->where('id', $existingId)->update(['is_system' => true, 'updated_at' => $now]);
            } else {
                DB::table('lookups')->insert([
                    'type' => 'client_role',
                    'value' => $value,
                    'sort_order' => $index,
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Переносим уже проставленные одиночные роли клиентов в пивот —
        // создаём lookup под значение, если такого ещё нет в справочнике
        // (у клиента могло быть введено произвольное значение до появления
        // мультивыбора, не совпадающее ни с одной из 3 базовых ролей).
        DB::table('clients')
            ->whereNotNull('role')
            ->where('role', '!=', '')
            ->select('id', 'role')
            ->orderBy('id')
            ->get()
            ->each(function ($client) use ($now) {
                $lookupId = DB::table('lookups')->where('type', 'client_role')->where('value', $client->role)->value('id');

                if (! $lookupId) {
                    $nextSortOrder = (int) DB::table('lookups')->where('type', 'client_role')->max('sort_order') + 1;
                    $lookupId = DB::table('lookups')->insertGetId([
                        'type' => 'client_role',
                        'value' => $client->role,
                        'sort_order' => $nextSortOrder,
                        'is_system' => false,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('client_roles')->insertOrIgnore([
                    'client_id' => $client->id,
                    'lookup_id' => $lookupId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('role')->nullable()->after('type');
        });

        // М:М -> 1:0..1 необратимо без потери информации при 2+ ролях —
        // восстанавливаем первую попавшуюся (по алфавиту значения), остальные
        // при откате теряются.
        DB::table('client_roles')
            ->join('lookups', 'lookups.id', '=', 'client_roles.lookup_id')
            ->select('client_roles.client_id', DB::raw('MIN(lookups.value) as role'))
            ->groupBy('client_roles.client_id')
            ->get()
            ->each(fn ($row) => DB::table('clients')->where('id', $row->client_id)->update(['role' => $row->role]));

        Schema::dropIfExists('client_roles');

        // Примечание: 3 системные lookup-записи (Клиент/Подрядчик/Поставщик)
        // сознательно не удаляем при откате — это безопасные справочные
        // данные, не мусор миграции, удалять их могло бы задеть записи,
        // созданные пользователем самостоятельно с тем же названием.
    }
};
