<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRule extends Model
{
    protected $fillable = [
        'position_id', 'employee_id', 'client_id', 'service_id', 'service_category_id',
        'type', 'fixed_amount', 'percentage_value', 'is_default_for_unlisted',
        'branch_id', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'fixed_amount' => 'integer',
            'percentage_value' => 'decimal:2',
            'is_active' => 'boolean',
            'is_default_for_unlisted' => 'boolean',
        ];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Персональная ставка подрядчика. У подрядчика нет должности, поэтому
     * каскад приоритетов для него короче, чем для сотрудника: только
     * client_id + услуга/группа/по умолчанию, без ступени «ставка должности»
     * (см. PayrollCalculationService::resolveRate()).
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
