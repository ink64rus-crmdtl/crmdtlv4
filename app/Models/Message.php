<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = ['chat_id', 'direction', 'content', 'status', 'external_message_id'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}