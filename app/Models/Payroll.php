<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payroll extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'client_id', 'branch_id', 'work_order_id', 'work_order_item_id', 'type', 'role',
        'amount', 'currency_id', 'status', 'comment', 'created_by', 'paid_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'currency_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
    }

    /**
     * Получатель начисления — штатный сотрудник ИЛИ подрядчик (Client с ролью
     * «Подрядчик»). Заполнен ровно один из employee_id/client_id; CHECK-
     * констрейнта в схеме нет намеренно (см. миграцию 2027_01_31_000001),
     * инвариант держат создающие эти строки места — PayrollCalculationService
     * и PayrollController.
     *
     * withTrashed() у обеих связей: начисление и особенно выплата не должны
     * ломаться из-за того, что сотрудника уволили (soft delete), а клиента
     * удалили из базы уже после того, как работа выполнена и деньги начислены.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * Единая точка «кому начислено» — чтобы вызывающий код не ветвился руками
     * на employee/client каждый раз (имя получателя, выплата, отчёты).
     */
    public function payee(): Employee|Client|null
    {
        return $this->employee ?? $this->client;
    }

    public function payeeName(): string
    {
        if ($this->employee) {
            return trim($this->employee->first_name.' '.$this->employee->last_name);
        }

        return $this->client?->name ?? '—';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function workOrderItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'paid_transaction_id');
    }
}
