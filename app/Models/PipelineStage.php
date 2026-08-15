<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Стадия воронки. type — не косметика, а поведение:
 *   open — сделка в работе (обычная колонка доски);
 *   won  — успех; в неё сделка переходит автоматически при оплате связанного заказа;
 *   lost — проигрыш; требует причины (loss_reason_lookup_id).
 * Стадии won/lost неудаляемы в принципе — на них завязаны отчёты воронки
 * и автопереход при оплате (см. PipelineSettingsController::destroyStage()).
 */
class PipelineStage extends Model
{
    public const TYPE_OPEN = 'open';

    public const TYPE_WON = 'won';

    public const TYPE_LOST = 'lost';

    public const TYPES = [
        self::TYPE_OPEN => 'В работе',
        self::TYPE_WON => 'Успех',
        self::TYPE_LOST => 'Проигрыш',
    ];

    protected $fillable = [
        'pipeline_id',
        'name',
        'color',
        'type',
        'probability',
        'rotting_days',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'probability' => 'integer',
            'rotting_days' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /** Стадия закрытия (успех или проигрыш) — сделка в ней больше не «в работе». */
    public function isClosing(): bool
    {
        return in_array($this->type, [self::TYPE_WON, self::TYPE_LOST], true);
    }
}
