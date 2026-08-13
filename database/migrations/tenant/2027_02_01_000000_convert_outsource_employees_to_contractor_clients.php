<?php

use App\Models\Client;
use App\Models\Employee;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * До появления подрядчиков-клиентов (миграции 2027_01_30 / 2027_01_31)
 * «подрядчик» изображался типом сотрудника: Employee.type='outsource'
 * («Аутсорс / Подрядчик» в UI). Теперь подрядчик — это Client с системной
 * ролью «Подрядчик», и два параллельных способа обозначить одно и то же
 * только путали бы: непонятно, где заводить нового подрядчика и почему у
 * одних есть карточка клиента с реквизитами, а у других — карточка
 * сотрудника с датой найма.
 *
 * Эта миграция схлопывает дублирование: каждый outsource-сотрудник
 * превращается в клиента-подрядчика, все его связи и деньги переезжают
 * на нового клиента, а исходная запись сотрудника мягко удаляется
 * (SoftDeletes — история остаётся, из активных списков пропадает).
 *
 * Тип 'self_employed' НЕ трогаем: самозанятый — это именно наёмный
 * исполнитель со своей налоговой спецификой (компенсация налога в
 * PayrollCalculationService), а не внешний подрядчик.
 */
return new class extends Migration
{
    public function up(): void
    {
        $outsourced = DB::table('employees')->where('type', 'outsource')->get();

        if ($outsourced->isEmpty()) {
            return;
        }

        $now = now();
        $contractorRoleId = DB::table('lookups')
            ->where('type', 'client_role')
            ->where('value', 'Подрядчик')
            ->value('id');

        foreach ($outsourced as $employee) {
            $name = trim(implode(' ', array_filter([$employee->last_name, $employee->first_name, $employee->middle_name])));
            $name = $name !== '' ? $name : 'Подрядчик #'.$employee->id;

            // b2b: подрядчик почти всегда юрлицо/ИП, а вкладка «Реквизиты»
            // в карточке клиента показывается именно для b2b — ему эти поля
            // как раз нужны для документов.
            $clientId = DB::table('clients')->insertGetId([
                'branch_id' => $employee->branch_id,
                'type' => 'b2b',
                'name' => $name,
                'phone' => $employee->phone,
                'comment' => 'Перенесён из сотрудников с типом «Аутсорс / Подрядчик» при переходе на подрядчиков-клиентов.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($contractorRoleId) {
                DB::table('client_roles')->insertOrIgnore([
                    'client_id' => $clientId,
                    'lookup_id' => $contractorRoleId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            // Назначения на позициях. insertOrIgnore + delete вместо update:
            // на позиции теоретически мог оказаться и сам этот сотрудник, и
            // уже созданный из него клиент — тогда update упёрся бы в unique.
            DB::table('work_order_item_assignees')
                ->where('assignee_type', Employee::class)
                ->where('assignee_id', $employee->id)
                ->get()
                ->each(function ($row) use ($clientId, $now) {
                    DB::table('work_order_item_assignees')->insertOrIgnore([
                        'work_order_item_id' => $row->work_order_item_id,
                        'assignee_type' => Client::class,
                        'assignee_id' => $clientId,
                        'share_percent' => $row->share_percent,
                        'manual_amount_override' => $row->manual_amount_override,
                        'manual_percent_override' => $row->manual_percent_override,
                        'created_at' => $row->created_at ?? $now,
                        'updated_at' => $now,
                    ]);
                    DB::table('work_order_item_assignees')->where('id', $row->id)->delete();
                });

            // Начисления и персональные ставки: получателем становится клиент.
            DB::table('payrolls')
                ->where('employee_id', $employee->id)
                ->update(['employee_id' => null, 'client_id' => $clientId, 'updated_at' => $now]);

            DB::table('payroll_rules')
                ->where('employee_id', $employee->id)
                ->update(['employee_id' => null, 'client_id' => $clientId, 'updated_at' => $now]);

            // Администратором подрядчик быть не может (на позиции с подрядчиком
            // администратор — всегда штатный сотрудник), поэтому такие привязки
            // не переносим, а снимаем: перенос создал бы состояние, которое
            // система сама больше никогда не позволит собрать.
            DB::table('work_order_admins')->where('employee_id', $employee->id)->delete();
            DB::table('work_order_item_admins')->where('employee_id', $employee->id)->delete();

            DB::table('employees')->where('id', $employee->id)->update([
                'is_active' => false,
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Обратный перенос не делаем: восстановить, какой именно клиент раньше
     * был сотрудником, можно только по служебному комментарию — это догадка,
     * а не данные. Откат вернёт лишь возможность заново заводить сотрудников
     * с типом 'outsource' (сама колонка type не менялась и ничего не теряет).
     */
    public function down(): void
    {
        // Намеренно пусто, см. комментарий выше.
    }
};
