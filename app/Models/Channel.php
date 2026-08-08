<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Channel extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'name', 'provider', 'messenger_type', 'credentials',
        'external_profile_id', 'phone_number', 'webhook_token', 'status', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array', // Автоматическое шифрование токенов в БД
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Channel $channel) {
            $channel->webhook_token ??= (string) Str::uuid();
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }
}