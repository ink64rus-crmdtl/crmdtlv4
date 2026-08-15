<script setup>
import { computed, ref } from 'vue';

/**
 * Общий компонент таблицы — заменяет только <table>...</table> (шапка+тело),
 * не карточку-обёртку/тулбар/пагинацию вокруг (те уже общие компоненты,
 * DataTableToolbar.vue/Pagination.vue). Разметка и классы 1:1 повторяют то,
 * что раньше было скопировано вручную на каждой странице (Attex theme,
 * CLAUDE.md §7) — это чистый рефакторинг существующего вида, не новый дизайн.
 *
 * Ячейка без своего слота #cell-<key> рендерится как обычный текст row[key].
 * Кастомная ячейка (бейдж/аватар/инлайн-виджет) — слот #cell-<key>="{ row, value }".
 * Колонка действий — отдельный слот #actions="{ row }", т.к. у неё нет
 * заголовка-данных и она всегда прижата вправо.
 *
 * Слот #row="{ row, columns }" — escape-hatch для строк с нерегулярной
 * структурой (например строка-ошибка с одной colspan-ячейкой вместо N
 * обычных, как у Central/Admin/Tenants при несмигрированной БД тенанта) —
 * полностью переопределяет содержимое <tr> для ВСЕХ строк таблицы (условие
 * "обычная строка или строка-ошибка" пишет сам родитель внутри слота).
 * Не указан — используется дефолтный рендер по columns, как везде.
 *
 * Сортировка — колонка с `sortable: true` кликабельна (стрелка ▲/▼/⇅ в заголовке).
 * Компонент НЕ хранит состояние сортировки сам (как и с остальным — родитель
 * решает, серверная это сортировка через router.get или клиентская по массиву):
 * текущее состояние приходит пропсом `sort` — МАССИВ `[{key, dir}, ...]` в
 * порядке приоритета (первый элемент — главная сортировка), клик эмитит
 * @sort с уже посчитанным новым массивом.
 * `sortKey` — опционально, если реальная колонка БД для сортировки отличается
 * от `key` колонки (например UI-колонка 'status' сортируется по 'is_active').
 * Без sortKey используется сам key.
 *
 * Обычный клик — заменяет сортировку целиком на эту одну колонку (цикл
 * asc → desc → asc, привычное поведение для 90% случаев). Shift+клик —
 * добавляет колонку к уже применённым с следующим приоритетом (или снимает
 * её, если это уже третье нажатие с зажатым Shift на той же колонке: цикл
 * asc → desc → убрать из сортировки). Если сортировок больше одной, у
 * иконки — маленькая цифра приоритета (¹, ², ³...).
 */
const props = defineProps({
    columns: { type: Array, required: true }, // [{key, label, align?: 'left'|'right'|'center', headerClass?, cellClass?, sortable?, sortKey?}]
    rows: { type: Array, default: () => [] },
    rowKey: { type: String, default: 'id' },
    selectable: { type: Boolean, default: false },
    modelValue: { type: Array, default: () => [] }, // выбранные id, актуально только при selectable
    hasActions: { type: Boolean, default: false },
    actionsLabel: { type: String, default: 'Действия' },
    emptyMessage: { type: String, default: 'Ничего не найдено.' },
    rowClickable: { type: Boolean, default: false }, // добавляет cursor-pointer; @row-click эмитится всегда
    sort: { type: Array, default: () => [] }, // [{key, dir: 'asc'|'desc'}, ...] в порядке приоритета
});

const emit = defineEmits(['update:modelValue', 'row-click', 'sort']);

const sortEntry = (key) => props.sort.find((s) => s.key === key);
const sortPriority = (key) => props.sort.findIndex((s) => s.key === key) + 1; // 0 = нет в сортировке

const toggleSort = (col, event) => {
    if (!col.sortable) return;
    const key = col.sortKey || col.key;
    const current = sortEntry(key);

    if (!event?.shiftKey) {
        // Обычный клик — заменяет сортировку целиком одной этой колонкой.
        const nextDir = current && props.sort.length === 1 && current.dir === 'asc' ? 'desc' : 'asc';
        emit('sort', [{ key, dir: nextDir }]);
        return;
    }

    // Shift+клик — добавляет/переключает/убирает колонку в текущем наборе, не трогая остальные.
    if (!current) {
        emit('sort', [...props.sort, { key, dir: 'asc' }]);
    } else if (current.dir === 'asc') {
        emit('sort', props.sort.map((s) => (s.key === key ? { ...s, dir: 'desc' } : s)));
    } else {
        emit('sort', props.sort.filter((s) => s.key !== key));
    }
};

// Тултип на иконке сортировки — не CSS group-hover, а position:fixed +
// Teleport (тот же приём, что у SearchableSelect и остальных плавающих
// панелей — CLAUDE.md, правило про Teleport-компоненты): таблица всегда
// обёрнута в overflow-x-auto, и CSS-тултип, всплывающий локально внутри
// <th>, обрезается этим контейнером как только колонка ближе к правому
// (или левому) краю прокрутки — ровно тот же класс бага, что и с вертикальным
// клиппингом выше. position:fixed уходит из-под any overflow-контейнера,
// поэтому позиция считается вручную от getBoundingClientRect() иконки и
// прижимается (clamp) к границам окна, чтобы не вылезать за экран.
const TOOLTIP_WIDTH = 224; // соответствует w-56
const TOOLTIP_MARGIN = 8;
const hoveredSortKey = ref(null);
const tooltipStyle = ref({});
const tooltipArrowStyle = ref({});
const tooltipTeleportTarget = ref(typeof document !== 'undefined' ? document.body : null);

const showSortTooltip = (col, event) => {
    if (!col.sortable) return;
    const key = col.sortKey || col.key;
    const rect = event.currentTarget.getBoundingClientRect();
    // Тултип может открыться внутри формы, отрендеренной через <Modal>
    // (нативный <dialog>) — телепорт в document.body положил бы его вне
    // top layer диалога, диалог перекрыл бы его несмотря на z-index.
    tooltipTeleportTarget.value = event.currentTarget.closest('dialog') || document.body;

    const idealLeft = rect.left + rect.width / 2 - TOOLTIP_WIDTH / 2;
    const maxLeft = Math.max(window.innerWidth - TOOLTIP_WIDTH - TOOLTIP_MARGIN, TOOLTIP_MARGIN);
    const boxLeft = Math.min(Math.max(idealLeft, TOOLTIP_MARGIN), maxLeft);
    const arrowLeft = Math.min(Math.max(rect.left + rect.width / 2 - boxLeft, 12), TOOLTIP_WIDTH - 12);

    tooltipStyle.value = {
        position: 'fixed',
        top: `${rect.bottom + 8}px`,
        left: `${boxLeft}px`,
        width: `${TOOLTIP_WIDTH}px`,
    };
    tooltipArrowStyle.value = { left: `${arrowLeft}px` };
    hoveredSortKey.value = key;
};

const hideSortTooltip = () => {
    hoveredSortKey.value = null;
};

const selectAll = computed({
    get: () => props.rows.length > 0 && props.modelValue.length === props.rows.length,
    set: (value) => {
        emit('update:modelValue', value ? props.rows.map((r) => r[props.rowKey]) : []);
    },
});

const toggleRow = (id, checked) => {
    const next = checked
        ? [...props.modelValue, id]
        : props.modelValue.filter((x) => x !== id);
    emit('update:modelValue', next);
};

const alignClass = (align) => (align === 'right' ? 'text-right' : align === 'center' ? 'text-center' : '');

const totalColspan = computed(() => props.columns.length + (props.selectable ? 1 : 0) + (props.hasActions ? 1 : 0));
</script>

<template>
    <table class="min-w-full text-left whitespace-nowrap">
        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
            <tr>
                <th v-if="selectable" class="py-3 px-4 w-10 border-b border-gray-200 dark:border-gray-700 text-center">
                    <input type="checkbox" v-model="selectAll" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                </th>
                <th
                    v-for="col in columns"
                    :key="col.key"
                    :class="['py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700', alignClass(col.align), col.sortable ? 'cursor-pointer select-none hover:text-gray-700 dark:hover:text-gray-200' : '', col.headerClass]"
                    @click="toggleSort(col, $event)"
                >
                    <span :class="['inline-flex items-center gap-0.5', col.align === 'right' ? 'flex-row-reverse' : '']">
                        {{ col.label }}
                        <span
                            v-if="col.sortable"
                            class="inline-flex items-center"
                            @mouseenter="showSortTooltip(col, $event)"
                            @mouseleave="hideSortTooltip"
                        >
                            <i
                                :class="[
                                    sortEntry(col.sortKey || col.key) ? (sortEntry(col.sortKey || col.key).dir === 'desc' ? 'ri-arrow-down-s-line text-primary' : 'ri-arrow-up-s-line text-primary') : 'ri-expand-up-down-line text-gray-300 dark:text-gray-600',
                                    'text-sm'
                                ]"
                            ></i>
                            <sup
                                v-if="sort.length > 1 && sortPriority(col.sortKey || col.key) > 0"
                                class="text-[9px] font-bold text-primary -ml-0.5"
                            >{{ sortPriority(col.sortKey || col.key) }}</sup>
                        </span>
                    </span>
                </th>
                <th v-if="hasActions" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">
                    {{ actionsLabel }}
                </th>
            </tr>
        </thead>
        <tbody>
            <tr
                v-for="row in rows"
                :key="row[rowKey]"
                :class="['odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors group', rowClickable ? 'cursor-pointer' : '']"
                @click="emit('row-click', row)"
            >
                <td v-if="selectable" class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center" @click.stop>
                    <input
                        type="checkbox"
                        :checked="modelValue.includes(row[rowKey])"
                        @change="toggleRow(row[rowKey], $event.target.checked)"
                        class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer"
                    />
                </td>
                <slot name="row" :row="row" :columns="columns">
                    <td
                        v-for="col in columns"
                        :key="col.key"
                        :class="['py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50', alignClass(col.align), col.cellClass]"
                    >
                        <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">{{ row[col.key] }}</slot>
                    </td>
                    <td v-if="hasActions" class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2" @click.stop>
                        <slot name="actions" :row="row" />
                    </td>
                </slot>
            </tr>
            <tr v-if="rows.length === 0">
                <td :colspan="totalColspan" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    <slot name="empty">{{ emptyMessage }}</slot>
                </td>
            </tr>
        </tbody>
    </table>

    <Teleport :to="tooltipTeleportTarget">
        <div
            v-if="hoveredSortKey"
            :style="tooltipStyle"
            class="pointer-events-none z-50 rounded bg-gray-800 dark:bg-gray-900 px-2.5 py-1.5 text-left text-[11px] font-normal normal-case leading-snug text-white shadow-lg"
        >
            <span :style="tooltipArrowStyle" class="absolute bottom-full -mb-1 h-2 w-2 -translate-x-1/2 rotate-45 bg-gray-800 dark:bg-gray-900"></span>
            <span class="block"><b class="font-semibold">Клик</b> — сортировать по этой колонке.</span>
            <span class="block mt-0.5"><b class="font-semibold">Shift+клик</b> — добавить ещё одну колонку сортировки (по порядку приоритета).</span>
        </div>
    </Teleport>
</template>
