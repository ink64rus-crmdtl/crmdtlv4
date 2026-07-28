<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Module extends Model
{
    use HasTranslations;

    protected $fillable = [
        'key',
        'label',
        'icon',
        'is_core',
        'is_enabled',
        'sort_order',
        'parent_id',
        'required_permission',
    ];

    public array $translatable = ['label'];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(Module::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Module::class, 'parent_id')->orderBy('sort_order');
    }
}