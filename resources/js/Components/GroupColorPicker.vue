<script>
/**
 * Единая именованная палитра для бейджей (грейды клиентов, справочники и
 * т.п.) — 12 цветов в гамме темы Attex: первые 6 зеркалят семантические
 * токены темы (primary/success/danger/warning/info/secondary из
 * tailwind.config.js), остальные 6 — те же дополнительные цвета, что и у
 * CalendarColorPicker (там hex под события календаря, здесь — именованные
 * Tailwind-классы под бейджи: `bg-{value}-100 text-{value}-700` и т.п.).
 * Полные строки классов ниже — ЛИТЕРАЛЬНО в коде намеренно (не собираются
 * динамическим шаблонным литералом здесь) — Tailwind JIT сканирует только
 * реальные вхождения класса в файлах (content: './resources/js/**\/*.vue'),
 * .js-модули не сканируются вообще. Другие файлы, использующие обычный
 * `${color}`-интерполированный класс в шаблоне (см. CRM/Clients/Show.vue),
 * полагаются именно на то, что классы уже присутствуют здесь как литералы —
 * не убирай ни одного цвета/варианта без проверки всех потребителей.
 */
export const GROUP_COLORS = [
    { value: 'gray', label: 'Серый', swatch: 'bg-gray-400', badge: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300', icon: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' },
    { value: 'blue', label: 'Синий', swatch: 'bg-blue-500', badge: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400', icon: 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400' },
    { value: 'green', label: 'Зелёный', swatch: 'bg-green-500', badge: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400', icon: 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400' },
    { value: 'red', label: 'Красный', swatch: 'bg-red-500', badge: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400', icon: 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400' },
    { value: 'yellow', label: 'Жёлтый', swatch: 'bg-yellow-400', badge: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400', icon: 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900/30 dark:text-yellow-400' },
    { value: 'purple', label: 'Фиолетовый', swatch: 'bg-purple-500', badge: 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400', icon: 'bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400' },
    { value: 'pink', label: 'Розовый', swatch: 'bg-pink-500', badge: 'bg-pink-100 text-pink-700 dark:bg-pink-900/30 dark:text-pink-400', icon: 'bg-pink-100 text-pink-600 dark:bg-pink-900/30 dark:text-pink-400' },
    { value: 'teal', label: 'Бирюзовый', swatch: 'bg-teal-500', badge: 'bg-teal-100 text-teal-700 dark:bg-teal-900/30 dark:text-teal-400', icon: 'bg-teal-100 text-teal-600 dark:bg-teal-900/30 dark:text-teal-400' },
    { value: 'orange', label: 'Оранжевый', swatch: 'bg-orange-500', badge: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400', icon: 'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400' },
    { value: 'lime', label: 'Салатовый', swatch: 'bg-lime-500', badge: 'bg-lime-100 text-lime-700 dark:bg-lime-900/30 dark:text-lime-400', icon: 'bg-lime-100 text-lime-600 dark:bg-lime-900/30 dark:text-lime-400' },
    { value: 'indigo', label: 'Индиго', swatch: 'bg-indigo-500', badge: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400', icon: 'bg-indigo-100 text-indigo-600 dark:bg-indigo-900/30 dark:text-indigo-400' },
    { value: 'cyan', label: 'Голубой', swatch: 'bg-cyan-500', badge: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/30 dark:text-cyan-400', icon: 'bg-cyan-100 text-cyan-600 dark:bg-cyan-900/30 dark:text-cyan-400' },
];

export const groupColorMeta = (color) => GROUP_COLORS.find(c => c.value === color) || GROUP_COLORS[0];
</script>

<script setup>
const props = defineProps({
    modelValue: { type: String, default: 'gray' },
});

const emit = defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2.5">
        <button
            v-for="c in GROUP_COLORS"
            :key="c.value"
            type="button"
            @click="emit('update:modelValue', c.value)"
            :class="[c.swatch, modelValue === c.value ? 'ring-2 ring-offset-2 ring-gray-400 dark:ring-offset-gray-800 scale-110' : 'hover:scale-110']"
            class="h-8 w-8 rounded-full shadow-sm transition-transform flex items-center justify-center"
            :title="c.label"
        >
            <i v-if="modelValue === c.value" class="ri-check-line text-white text-base"></i>
        </button>
    </div>
</template>
