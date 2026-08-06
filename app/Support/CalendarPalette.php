<?php

namespace App\Support;

/**
 * Фиксированный набор цветов для выбора у Исполнителя/Поста (карточка календаря)
 * и фиксированный набор иконок для Поста. Значения валидируются по этим спискам
 * в контроллерах — тот же список продублирован во фронтенд-компонентах
 * CalendarColorPicker.vue / PostIconPicker.vue для отображения палитры.
 */
class CalendarPalette
{
    public const COLORS = [
        '#3e60d5', '#47ad77', '#f15776', '#ffc35a', '#16a7e9', '#6c757d',
        '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#84cc16', '#64748b',
    ];

    public const POST_ICONS = [
        'ri-car-line',
        'ri-car-washing-line',
        'ri-drop-line',
        'ri-sparkling-2-line',
        'ri-shield-check-line',
        'ri-paint-brush-line',
        'ri-tools-line',
        'ri-sun-line',
        'ri-contrast-2-line',
        'ri-parking-box-line',
    ];
}
