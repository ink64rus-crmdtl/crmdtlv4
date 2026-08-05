<?php

namespace App\Models;

use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class WorkOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id',
        'client_id',
        'vehicle_id',
        'status',
        'payment_status',
        'mileage',
        'total_amount',
        'discount_amount',
        'final_amount',
        'currency_id',
    ];

    protected function casts(): array
    {
        return [
            'mileage' => 'integer',
            'total_amount' => 'integer',
            'discount_amount' => 'integer',
            'final_amount' => 'integer',
            'currency_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope());
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'payable');
    }

    /**
     * Пересчитывает payment_status на основе суммы поступивших (income) транзакций.
     * O(1) относительно общего числа транзакций в системе — агрегат по индексу payable_type+payable_id.
     */
    public function syncPaymentStatus(): void
    {
        $paidTotal = $this->transactions()->where('type', 'income')->sum('amount');

        if ($this->final_amount > 0 && $paidTotal >= $this->final_amount) {
            $this->payment_status = 'paid';
        } elseif ($paidTotal > 0) {
            $this->payment_status = 'partial';
        } else {
            $this->payment_status = 'unpaid';
        }

        $this->save();
    }
}