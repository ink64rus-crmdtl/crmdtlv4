<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLog;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasActivityLog, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'client_id',
        'vehicle_id',
        'employee_id',
        'contractor_id',
        'post_id',
        'work_order_id',
        'type',
        'status',
        'reminder_sent_at',
        'start_at',
        'end_at',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope);
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Исполнитель-подрядчик — альтернатива employee(), заполнен максимум
     * один из двух (см. миграцию 2027_02_05, тот же паттерн, что у
     * Payroll::employee()/client()). Инвариант держит
     * AppointmentController::validateAppointment().
     */
    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'contractor_id');
    }

    /**
     * Кто реально исполняет запись — сотрудник или подрядчик, независимо от
     * того, в какой из двух колонок он записан (тот же приём, что и
     * Payroll::payee()/payeeName()).
     */
    public function assignee(): Employee|Client|null
    {
        return $this->employee ?: $this->contractor;
    }

    public function assigneeName(): ?string
    {
        $assignee = $this->assignee();

        if (! $assignee) {
            return null;
        }

        return $assignee instanceof Employee
            ? trim($assignee->first_name.' '.$assignee->last_name)
            : $assignee->name;
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AppointmentItem::class);
    }
}
