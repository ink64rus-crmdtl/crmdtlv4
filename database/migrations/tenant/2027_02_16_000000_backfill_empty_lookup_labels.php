<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Бэкфилл живого бага LookupController::store()/update(): исторически label
 * никогда не заполнялся при создании записи через quick-add (<CreatableSelect>
 * и форму Settings/Dictionaries), только value. Затрагивает записи, у которых
 * label пуст — записи, заведённые миграциями/сидерами (work_order_status и
 * т.п.), уже имеют свой label и не трогаются.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('lookups')
            ->where(function ($query) {
                $query->whereNull('label')->orWhere('label', '');
            })
            ->whereNotNull('value')
            ->update(['label' => DB::raw('value')]);
    }

    public function down(): void
    {
        // Необратимо намеренно: до фикса эти label и так были пусты —
        // откатывать их обратно в пустоту не имеет смысла.
    }
};
