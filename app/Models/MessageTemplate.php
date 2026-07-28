<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class MessageTemplate extends Model
{
    use SoftDeletes, HasTranslations;

    protected $fillable = ['name', 'event_trigger', 'body', 'is_active'];
    
    public array $translatable = ['body'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}