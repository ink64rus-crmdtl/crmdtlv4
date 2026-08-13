<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import axios from 'axios';

// Обобщённый мультивыбор с поиском и чипсами — по образцу EmployeeMultiSelect.vue,
// но для произвольных сущностей {id, label} (услуги, категории и т.п.), а не
// только сотрудников. В отличие от EmployeeMultiSelect добавлен поиск по тексту —
// для реально длинных растущих списков (см. CLAUDE.md про SearchableSelect).
//
// creatable — опциональный режим "добавить на лету", как у CreatableSelect.vue,
// но для мультивыбора: если введённый текст не совпадает ни с одной опцией,
// внизу списка появляется "Добавить «...»", создающая новую запись в
// справочнике (settings.lookups.store, type=lookupType) и сразу выбирающая
// её — родитель получает событие option-created и сам дополняет свой список
// options (компонент не хранит источник правды по списку, только выбор).
const props = defineProps({
    modelValue: {
        type: Array,
        default: () => [], // массив выбранных ID
    },
    options: {
        type: Array,
        default: () => [], // полный список опций {id, label}
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    placeholder: {
        type: String,
        default: 'Выберите...',
    },
    creatable: {
        type: Boolean,
        default: false,
    },
    lookupType: {
        type: String,
        default: null,
    },
});

const emit = defineEmits(['update:modelValue', 'option-created']);

const creating = ref(false);

const isOpen = ref(false);
const search = ref('');
const containerRef = ref(null);
const buttonRef = ref(null);
const panelRef = ref(null);
const searchInputRef = ref(null);
const panelStyle = ref({});
// См. SearchableSelect.vue — тот же обход конфликта Teleport to="body" с
// нативным <dialog> (top layer перекрывает обычный z-index панели, клики
// по пунктам не доходят), если компонент открыт внутри формы в <Modal>.
const teleportTarget = ref(typeof document !== 'undefined' ? document.body : null);

const selectedOptions = computed(() => props.options.filter(o => props.modelValue.includes(o.id)));

const filteredOptions = computed(() => {
    if (!search.value) return props.options;
    const lower = search.value.toLowerCase();
    return props.options.filter(o => o.label.toLowerCase().includes(lower));
});

const showCreateOption = computed(() => {
    if (!props.creatable || !search.value.trim()) return false;
    const lower = search.value.trim().toLowerCase();
    return !props.options.some(o => o.label.toLowerCase() === lower);
});

const createOption = async () => {
    const value = search.value.trim();
    if (!value || creating.value) return;

    creating.value = true;
    try {
        const response = await axios.post(route('settings.lookups.store'), {
            type: props.lookupType,
            value,
            is_active: true,
        });
        const created = response.data?.data;
        if (created) {
            emit('option-created', created);
            emit('update:modelValue', [...props.modelValue, created.id]);
            search.value = '';
        }
    } catch (error) {
        console.error('Failed to add lookup value', error);
    } finally {
        creating.value = false;
    }
};

// Позиционируем панель через fixed-координаты и телепортируем в <body>,
// чтобы её не обрезали overflow-контейнеры и не перекрывали оверлеи с большим z-index.
const updatePosition = () => {
    if (!buttonRef.value) return;
    const rect = buttonRef.value.getBoundingClientRect();
    panelStyle.value = {
        position: 'fixed',
        top: `${rect.bottom + 4}px`,
        left: `${rect.left}px`,
        minWidth: `${Math.max(rect.width, 220)}px`,
    };
};

const toggle = async () => {
    if (props.disabled) return;
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        teleportTarget.value = containerRef.value?.closest('dialog') || document.body;
        search.value = '';
        await nextTick();
        updatePosition();
        searchInputRef.value?.focus();
    }
};

const toggleOption = (id) => {
    const next = props.modelValue.includes(id)
        ? props.modelValue.filter(x => x !== id)
        : [...props.modelValue, id];
    emit('update:modelValue', next);
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
    <div class="relative inline-flex flex-wrap items-center gap-1 w-full" ref="containerRef">
        <!-- Все выбранные показаны чипами, чтобы не приходилось открывать список ради полного состава -->
        <span
            v-for="opt in selectedOptions"
            :key="opt.id"
            class="inline-flex items-center gap-1 pl-2 pr-1 py-0.5 rounded-full bg-primary/10 text-primary text-[11px] font-medium"
        >
            {{ opt.label }}
            <button
                v-if="!disabled"
                type="button"
                @click.stop="toggleOption(opt.id)"
                class="hover:text-danger transition-colors"
                title="Убрать"
            >
                <i class="ri-close-line text-xs"></i>
            </button>
        </span>

        <span v-if="disabled && selectedOptions.length === 0" class="text-[11px] text-gray-400">{{ placeholder }}</span>

        <button
            v-if="!disabled"
            ref="buttonRef"
            type="button"
            @click.stop="toggle"
            class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full border border-dashed border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:border-primary hover:text-primary text-[11px] font-medium transition-colors"
        >
            <i class="ri-add-line text-xs"></i>
            <span v-if="selectedOptions.length === 0">{{ placeholder }}</span>
        </button>

        <Teleport :to="teleportTarget">
            <Transition
                enter-active-class="transition ease-out duration-100"
                enter-from-class="transform opacity-0 scale-95"
                enter-to-class="transform opacity-100 scale-100"
                leave-active-class="transition ease-in duration-75"
                leave-from-class="transform opacity-100 scale-100"
                leave-to-class="transform opacity-0 scale-95"
            >
                <div
                    v-if="isOpen"
                    ref="panelRef"
                    :style="panelStyle"
                    @click.stop
                    class="z-[250] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg py-1 max-h-72 flex flex-col"
                >
                    <div class="px-2 pb-1 border-b border-gray-100 dark:border-gray-700">
                        <input
                            ref="searchInputRef"
                            v-model="search"
                            type="text"
                            placeholder="Поиск..."
                            class="block w-full rounded border border-gray-200 dark:border-gray-700 bg-transparent py-1 px-2 text-xs text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                        />
                    </div>
                    <div class="overflow-y-auto custom-scrollbar">
                        <label
                            v-for="opt in filteredOptions"
                            :key="opt.id"
                            class="flex items-center gap-2 px-3 py-1.5 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700/60 cursor-pointer transition-colors"
                        >
                            <input
                                type="checkbox"
                                :checked="modelValue.includes(opt.id)"
                                @change="toggleOption(opt.id)"
                                class="h-3.5 w-3.5 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer"
                            />
                            <span>{{ opt.label }}</span>
                        </label>
                        <div v-if="!filteredOptions.length && !showCreateOption" class="px-3 py-2 text-xs text-gray-400">Ничего не найдено</div>
                        <div
                            v-if="showCreateOption"
                            @click="createOption"
                            class="flex items-center gap-2 px-3 py-1.5 text-xs text-primary hover:bg-primary/10 cursor-pointer transition-colors border-t border-gray-100 dark:border-gray-700 font-medium"
                        >
                            <i :class="creating ? 'ri-loader-4-line animate-spin' : 'ri-add-line'"></i>
                            Добавить «{{ search.trim() }}»
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
