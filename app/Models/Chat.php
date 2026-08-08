<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends Model
{
    use SoftDeletes;

    protected $fillable = ['type', 'branch_id', 'client_id', 'channel_id', 'external_chat_id', 'title', 'last_message_at'];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
        ];
    }

    // Без глобального BranchScope: internal-чаты сотрудников (branch_id=null,
    // участники могут быть из разных филиалов) не должны пропадать из выборки,
    // как только у пользователя выбран конкретный филиал — BranchScope такие
    // NULL-строки как раз отфильтровал бы. Фильтрация по филиалу для внешних
    // (client) чатов делается явно в контроллере, а не глобальным скоупом.

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_participants')->withPivot('last_read_at')->withTimestamps();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}