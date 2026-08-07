<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLog;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Employee extends Model
{
    use SoftDeletes, HasActivityLog;

    protected $fillable = [
        'user_id', 'branch_id', 'position_id', 'type',
        'first_name', 'last_name', 'middle_name', 'phone', 'personal_email',
        'birth_date', 'hire_date', 'termination_date', 'passport_data',
        'is_active', 'calendar_color',
        'secondary_position_id', 'salary_amount', 'self_employed_tax_percent',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'birth_date' => 'date',
            'hire_date' => 'date',
            'termination_date' => 'date',
            'passport_data' => 'array',
            'salary_amount' => 'integer',
            'self_employed_tax_percent' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new BranchScope());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function secondaryPosition(): BelongsTo
    {
        return $this->belongsTo(Position::class, 'secondary_position_id');
    }
}