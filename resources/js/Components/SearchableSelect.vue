<script setup>
/**
 * Обязательный компонент для любого выпадающего списка в системе, где
 * реально длинный список вариантов (клиенты, авто, сотрудники, филиалы,
 * услуги/товары, посты и т.п.) — см. CLAUDE.md, раздел про UI-конвенции.
 * Короткие фиксированные списки (2-6 пунктов, статусы и т.п.) можно
 * оставлять обычным <select>.
 *
 * Фильтрация — локальная, по уже переданному массиву options. Компонент
 * не делает собственных HTTP-запросов: страница как и раньше загружает
 * список целиком (как это уже сделано почти везде в проекте), а поиск
 * просто сужает то, что показано в открытом списке.
 */
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    options: { type: Array, default: () => [] }, // [{ value, label }]
    placeholder: { type: String, default: 'Выберите...' },
    searchPlaceholder: { type: String, default: 'Поиск...' },
    disabled: { type: Boolean, default: false },
    clearable: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const isOpen = ref(false);
const search = ref('');
const containerRef = ref(null);
const buttonRef = ref(null);
const panelRef = ref(null);
const panelStyle = ref({});
const searchInputRef = ref(null);

const current = computed(() => props.options.find(o => o.value === props.modelValue));

const filteredOptions = computed(() => {
    if (!search.value.trim()) return props.options;
    const q = search.value.trim().toLowerCase();
    return props.options.filter(o => String(o.label ?? '').toLowerCase().includes(q));
});

const updatePosition = () => {
    if (!buttonRef.value) return;
    const rect = buttonRef.value.getBoundingClientRect();
    panelStyle.value = {
        position: 'fixed',
        top: `${rect.bottom + 4}px`,
        left: `${rect.left}px`,
        width: `${rect.width}px`,
    };
};

const toggle = async () => {
    if (props.disabled) return;
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        search.value = '';
        await nextTick();
        updatePosition();
        searchInputRef.value?.focus();
    }
};

const select = (option) => {
    isOpen.value = false;
    emit('update:modelValue', option.value);
};

const clearSelection = (e) => {
    e.stopPropagation();
    emit('update:modelValue', '');
};

const closeDropdown = (e) => {
    if (
        containerRef.value && !containerRef.value.contains(e.target) &&
        panelRef.value && !panelRef.value.contains(e.target)
    ) {
        isOpen.value = false;
    }
};

const closeOnScrollOrResize = () => {
    if (isOpen.value) isOpen.value = false;
};

watch(() => props.disabled, (disabled) => {
    if (disabled) isOpen.value = false;
});

onMounted(() => {
    document.addEventListener('click', closeDropdown);
    window.addEventListener('scroll', closeOnScrollOrResize, true);
    window.addEventListener('resize', closeOnScrollOrResize);
});

onUnmounted(() => {
    document.removeEventListener('click', closeDropdown);
    window.removeEventListener('scroll', closeOnScrollOrResize, true);
    window.removeEventListener('resize', closeOnScrollOrResize);
});
</script>

<template>
    <div class="relative" ref="containerRef">
        <button
            type="button"
            ref="buttonRef"
            @click.stop="toggle"
            :disabled="disabled"
            class="flex items-center justify-between w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-left transition-colors focus:border-primary focus:ring-0 disabled:bg-gray-100 dark:disabled:bg-gray-800 disabled:cursor-not-allowed"
        >
            <span :class="current ? 'text-gray-800 dark:text-gray-200' : 'text-gray-400 dark:text-gray-500'" class="truncate">{{ current ? current.label : placeholder }}</span>
            <span class="flex items-center gap-1.5 shrink-0 ml-2">
                <i v-if="clearable && current" @click="clearSelection" class="ri-close-line text-gray-400 hover:text-danger transition-colors"></i>
                <i class="ri-arrow-down-s-line text-gray-400"></i>
            </span>
        </button>

        <Teleport to="body">
            <div
                v-if="isOpen"
                ref="panelRef"
                :style="panelStyle"
                @click.stop
                class="z-[250] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg overflow-hidden"
            >
                <div class="p-2 border-b border-gray-100 dark:border-gray-700">
                    <input
                        ref="searchInputRef"
                        v-model="search"
                        type="text"
                        :placeholder="searchPlaceholder"
                        class="block w-full rounded border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-2 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                        @keydown.escape="isOpen = false"
                    />
                </div>
                <div class="max-h-56 overflow-y-auto custom-scrollbar py-1">
                    <button
                        v-for="option in filteredOptions"
                        :key="option.value"
                        type="button"
                        @click="select(option)"
                        :class="option.value === modelValue ? 'bg-primary/10 text-primary font-medium' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/60'"
                        class="w-full text-left px-3 py-1.5 text-sm transition-colors truncate"
                    >
                        {{ option.label }}
                    </button>
                    <p v-if="filteredOptions.length === 0" class="px-3 py-4 text-center text-xs text-gray-400 dark:text-gray-500">Ничего не найдено</p>
                </div>
            </div>
        </Teleport>
    </div>
</template>
