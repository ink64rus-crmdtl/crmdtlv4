<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

class TransactionCategory extends Model
{
    use SoftDeletes, HasTranslations;

    protected $fillable = ['name', 'type', 'is_active', 'is_system', 'value'];
    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * id системной статьи по стабильному value-слагу (например 'order_payment').
     * Используется для авто-простановки статьи при проведении типовых операций
     * (оплата заказа, выплата ЗП, оплата поставщику, комиссия эквайринга).
     */
    public static function systemId(string $value): ?int
    {
        return static::where('value', $value)->where('is_system', true)->value('id');
    }
}
