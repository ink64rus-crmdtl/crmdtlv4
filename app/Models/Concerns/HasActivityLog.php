<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\Activitylog\Models\Activity;

/**
 * Даёт модели ленту "История" (activities) без автологирования пакета
 * spatie/laravel-activitylog — записи создаются вручную через
 * App\Services\ActivityLogger в контроллерах, в осмысленных точках.
 */
trait HasActivityLog
{
    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest();
    }
}
