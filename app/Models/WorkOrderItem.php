<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkOrderItem extends Model
{
    protected $fillable = [
        'work_order_id',
        'employee_id',
        'itemable_type',
        'itemable_id',
        'name',
        'quantity',
        'price',
        'discount_amount',
        'total',
        'currency_id',
        'sort_order',
        'admin_override',
        'linked_item_id',
        'is_billable',
        'cost_price',
        'affects_payroll',
        'payroll_uses_cost_only',
        'stock_deduction_disabled',
        'allow_negative_stock',
    ];

    protected function casts(): array
    {
        return [
            'employee_id' => 'integer',
            'quantity' => 'decimal:3',
            'price' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
            'currency_id' => 'integer',
            'sort_order' => 'integer',
            'is_billable' => 'boolean',
            'cost_price' => 'integer',
            'affects_payroll' => 'boolean',
            'payroll_uses_cost_only' => 'boolean',
            'stock_deduction_disabled' => 'boolean',
            'allow_negative_stock' => 'boolean',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Штатные исполнители позиции. Живут в общей полиморфной таблице
     * work_order_item_assignees вместе с подрядчиками (см. contractors()) —
     * withPivotValue и фильтрует выборку по assignee_type, и проставляет его
     * при attach()/sync(), поэтому обе связи можно использовать как обычные
     * BelongsToMany, не думая о полиморфной колонке.
     *
     * withoutGlobalScope(BranchScope) — осознанно: уже назначенный исполнитель
     * не имеет права молча пропасть из выборки из-за того, что в шапке выбрана
     * другая локация. Иначе PayrollCalculationService не увидел бы его и
     * НЕ НАЧИСЛИЛ БЫ ему деньги — тихая ошибка в расчёте вместо явной. Доступ
     * к самой позиции уже ограничен BranchScope на WorkOrder, так что это не
     * ослабление изоляции: кто видит заказ, тот видит и его исполнителей.
     * По той же причине withTrashed() — уволенный сотрудник должен получить
     * начисление за работу, которую успел выполнить до увольнения.
     */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'work_order_item_assignees', 'work_order_item_id', 'assignee_id')
            ->withPivotValue('assignee_type', Employee::class)
            ->withTimestamps()
            ->withPivot(['share_percent', 'manual_amount_override', 'manual_percent_override'])
            ->withoutGlobalScope(BranchScope::class)
            ->withTrashed();
    }

    /**
     * Подрядчики-исполнители позиции — Client с ролью «Подрядчик» (client_roles).
     * Бизнес-правило: на одной позиции не может быть одновременно штатных
     * исполнителей и подрядчиков (проверяется в WorkOrderController, на уровне
     * схемы не выражается). Расчёт ЗП тем не менее обрабатывает обе коллекции
     * независимо — если инвариант когда-то нарушат в обход контроллера, деньги
     * посчитаются корректно для всех, а не потеряются.
     *
     * Про withoutGlobalScope/withTrashed — см. комментарий к employees().
     */
    public function contractors(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'work_order_item_assignees', 'work_order_item_id', 'assignee_id')
            ->withPivotValue('assignee_type', Client::class)
            ->withTimestamps()
            ->withPivot(['share_percent', 'manual_amount_override', 'manual_percent_override'])
            ->withoutGlobalScope(BranchScope::class)
            ->withTrashed();
    }

    /**
     * Администраторы этой конкретной позиции — используется только при
     * admin_override=custom (иначе резолвится через WorkOrder::admins(),
     * см. PayrollCalculationService::resolveAdmins()). Многие-ко-многим
     * (work_order_item_admins), та же тройка share/override, что у employees().
     */
    public function admins(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'work_order_item_admins')
            ->withTimestamps()
            ->withPivot(['share_percent', 'manual_amount_override', 'manual_percent_override'])
            ->withoutGlobalScope(BranchScope::class)
            ->withTrashed();
    }

    /**
     * Позиция-услуга, к которой привязана эта позиция-материал (для вычета
     * стоимости материалов из базы расчёта ЗП именно этой услуги).
     */
    public function linkedItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderItem::class, 'linked_item_id');
    }

    /**
     * Обратная связь: материалы, привязанные к этой позиции-услуге.
     */
    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'linked_item_id');
    }

    /** Позиция-материал — та, что привязана к услуге, а не сама услуга/товар. */
    public function isMaterial(): bool
    {
        return $this->linked_item_id !== null;
    }

    /**
     * Сумма, вычитаемая из базы ЗП исполнителя за этот материал
     * (PayrollCalculationService::calculate(), CLAUDE.md «Материалы на услугу»).
     * payroll_uses_cost_only — вычитать себестоимость, а не цену (если
     * материал продан клиенту с наценкой, наценка не должна теряться из
     * заработка мастера/бизнеса просто из-за списания материала).
     */
    public function payrollDeductionAmount(): int
    {
        if ($this->payroll_uses_cost_only && $this->cost_price !== null) {
            return (int) round($this->cost_price * (float) $this->quantity);
        }

        return $this->total;
    }
}
