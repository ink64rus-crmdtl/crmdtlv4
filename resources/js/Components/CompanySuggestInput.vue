<script setup>
/**
 * Текстовое поле "Наименование"/"ИНН" организации с живой подсказкой из
 * DaData (suggest/party) — один и тот же эндпоинт ищет и по названию, и по
 * ИНН одновременно, поэтому один компонент годится для обоих полей формы.
 * От 3 символов, debounce как у серверных фильтров списков (useDebounceFn,
 * 300мс). Сам компонент — обычный <input> (v-model работает как у нативного
 * поля), плюс всплывающая панель вариантов; при выборе эмитит 'select' с
 * ПОЛНЫМ объектом реквизитов — какие поля из него использовать, решает
 * родительская форма (см. Settings/LegalEntities/Index.vue, CRM/Clients/Index.vue).
 *
 * Если DaData не настроен администратором платформы — компонент просто
 * ведёт себя как обычный <input> без подсказок, никаких блокировок и
 * предупреждений (CLAUDE.md: не блокируй функциональность из-за
 * неподключённой сторонней интеграции).
 */
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    required: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'select']);

const isOpen = ref(false);
const isLoading = ref(false);
const suggestions = ref([]);
const dadataConfigured = ref(true); // оптимистично — узнаём точно только после первого запроса

const inputRef = ref(null);
const containerRef = ref(null);
const panelRef = ref(null);
const panelStyle = ref({});
// См. SearchableSelect.vue — обход конфликта Teleport to="body" с нативным
// <dialog>: панель должна оказаться в том же top layer, что и модалка.
const teleportTarget = ref(typeof document !== 'undefined' ? document.body : null);

const updatePosition = () => {
    if (!inputRef.value) return;
    const rect = inputRef.value.getBoundingClientRect();
    panelStyle.value = {
        position: 'fixed',
        top: `${rect.bottom + 4}px`,
        left: `${rect.left}px`,
        width: `${rect.width}px`,
    };
};

const fetchSuggestions = useDebounceFn(async (query) => {
    isLoading.value = true;
    try {
        const { data } = await axios.get(route('dadata.party-suggest'), { params: { query } });
        dadataConfigured.value = data.configured;
        suggestions.value = data.suggestions || [];
        // Интеграция не настроена — закрываем панель целиком, а не показываем
        // "Ничего не найдено" (это не то же самое, что пустая выдача поиска,
        // и молчаливое закрытие честнее, чем создавать видимость поломки).
        if (!data.configured) isOpen.value = false;
    } catch (e) {
        suggestions.value = [];
    } finally {
        isLoading.value = false;
    }
}, 300);

const onInput = async (e) => {
    const value = e.target.value;
    emit('update:modelValue', value);

    const query = value.trim();
    if (query.length < 3 || !dadataConfigured.value) {
        isOpen.value = false;
        suggestions.value = [];
        return;
    }

    teleportTarget.value = containerRef.value?.closest('dialog') || document.body;
    await nextTick();
    updatePosition();
    isOpen.value = true;
    fetchSuggestions(query);
};

const select = (suggestion) => {
    isOpen.value = false;
    suggestions.value = [];
    emit('update:modelValue', suggestion.name);
    emit('select', suggestion);
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
    if (isOpen.value) { isOpen.value = false; updatePosition(); }
};

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
        <input
            ref="inputRef"
            :value="modelValue"
            @input="onInput"
            @keydown.escape="isOpen = false"
            type="text"
            :required="required"
            :placeholder="placeholder"
            autocomplete="off"
            class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
        />

        <Teleport :to="teleportTarget">
            <div
                v-if="isOpen"
                ref="panelRef"
                :style="panelStyle"
                @click.stop
                class="z-[250] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg overflow-hidden"
            >
                <div v-if="isLoading" class="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500 flex items-center justify-center gap-1.5">
                    <i class="ri-loader-4-line animate-spin"></i> Ищем в DaData...
                </div>
                <div v-else class="max-h-72 overflow-y-auto custom-scrollbar py-1">
                    <button
                        v-for="(s, idx) in suggestions"
                        :key="idx"
                        type="button"
                        @click="select(s)"
                        class="w-full text-left px-3 py-2 text-sm transition-colors text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700/60 border-b border-gray-50 dark:border-gray-700/50 last:border-0"
                    >
                        <div class="font-medium flex items-center gap-1.5">
                            {{ s.name }}
                            <span v-if="!s.is_active" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-danger/10 text-danger">ликвидирована</span>
                        </div>
                        <div class="text-xs text-gray-400 mt-0.5">
                            ИНН {{ s.inn || '—' }}<span v-if="s.address_display"> · {{ s.address_display }}</span>
                        </div>
                    </button>
                    <p v-if="suggestions.length === 0" class="px-3 py-4 text-center text-xs text-gray-400 dark:text-gray-500">Ничего не найдено</p>
                </div>
            </div>
        </Teleport>
    </div>
</template>
