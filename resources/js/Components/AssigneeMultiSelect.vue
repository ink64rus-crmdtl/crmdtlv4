<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';

// Выбор исполнителей позиции заказа. В отличие от EmployeeMultiSelect умеет
// два разных типа исполнителя — штатных сотрудников и подрядчиков (Client с
// ролью «Подрядчик») — и не даёт смешать их на одной позиции: у них
// принципиально разные схемы расчёта ЗП (бригада делит базу долями, подрядчик
// получает свою сумму целиком), поэтому смесь не имела бы понятного смысла.
//
// Тот же запрет продублирован на сервере (WorkOrderController::
// assigneeTypesMixedError) — здесь он ради понятного UX, а не как защита.
const props = defineProps({
    employeeIds: {
        type: Array,
        default: () => [],
    },
    contractorIds: {
        type: Array,
        default: () => [],
    },
    employees: {
        type: Array,
        default: () => [], // {id, first_name, last_name}
    },
    contractors: {
        type: Array,
        default: () => [], // {id, name}
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    placeholder: {
        type: String,
        default: 'Не назначен',
    },
});

// Наружу отдаём именно {type, ids} — родителю не нужно знать, какой из двух
// списков сейчас активен, он просто сохраняет то, что пришло.
const emit = defineEmits(['update']);

const isOpen = ref(false);
const search = ref('');
const containerRef = ref(null);
const buttonRef = ref(null);
const panelRef = ref(null);
const panelStyle = ref({});
// См. SearchableSelect.vue — обход конфликта Teleport to="body" с нативным
// <dialog>: панель должна оказаться в том же top layer, что и модалка.
const teleportTarget = ref(typeof document !== 'undefined' ? document.body : null);

// Активный режим определяется фактическим составом, а не отдельным состоянием:
// так UI не может разойтись с тем, что реально сохранено на позиции.
const activeType = ref(props.contractorIds.length > 0 ? 'contractor' : 'employee');

const selectedIds = computed(() => (activeType.value === 'contractor' ? props.contractorIds : props.employeeIds));

const optionLabel = (option) => (activeType.value === 'contractor'
    ? option.name
    : `${option.last_name || ''} ${(option.first_name || '').charAt(0)}${option.first_name ? '.' : ''}`.trim());

const options = computed(() => (activeType.value === 'contractor' ? props.contractors : props.employees));

const selectedOptions = computed(() => options.value.filter(o => selectedIds.value.includes(o.id)));

const filteredOptions = computed(() => {
    if (!search.value) return options.value;
    const lower = search.value.toLowerCase();
    return options.value.filter(o => optionLabel(o).toLowerCase().includes(lower));
});

// Переключить тип можно только когда по текущему никто не выбран — иначе
// переключение молча осиротило бы уже назначенных исполнителей.
const blockedTypeReason = computed(() => {
    if (props.employeeIds.length > 0 && activeType.value === 'employee') {
        return 'На позиции уже назначены штатные исполнители — уберите их, чтобы назначить подрядчика.';
    }
    if (props.contractorIds.length > 0 && activeType.value === 'contractor') {
        return 'На позиции уже назначен подрядчик — уберите его, чтобы назначить штатных исполнителей.';
    }
    return null;
});

const switchType = (type) => {
    if (type === activeType.value || blockedTypeReason.value) return;
    activeType.value = type;
    search.value = '';
};

const updatePosition = () => {
    if (!buttonRef.value) return;
    const rect = buttonRef.value.getBoundingClientRect();
    panelStyle.value = {
        position: 'fixed',
        top: `${rect.bottom + 4}px`,
        left: `${rect.left}px`,
        minWidth: `${Math.max(rect.width, 240)}px`,
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
    }
};

const toggleOption = (id) => {
    const next = selectedIds.value.includes(id)
        ? selectedIds.value.filter(x => x !== id)
        : [...selectedIds.value, id];

    emit('update', { type: activeType.value, ids: next });
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
    <div class="relative inline-flex flex-wrap items-center gap-1" ref="containerRef">
        <span
            v-for="opt in selectedOptions"
            :key="opt.id"
            :class="[
                activeType === 'contractor' ? 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' : 'bg-primary/10 text-primary',
                'inline-flex items-center gap-1 pl-2 pr-1 py-0.5 rounded-full text-[11px] font-medium'
            ]"
        >
            <i v-if="activeType === 'contractor'" class="ri-briefcase-line text-[10px]"></i>
            {{ optionLabel(opt) }}
            <button
                v-if="!disabled"
                type="button"
                @click.stop="toggleOption(opt.id)"
                class="hover:text-danger transition-colors"
                title="Убрать исполнителя"
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
                    class="z-[250] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg py-1 max-h-80 flex flex-col"
                >
                    <!-- Переключатель типа исполнителя -->
                    <div class="px-2 pb-2 border-b border-gray-100 dark:border-gray-700">
                        <div class="grid grid-cols-2 gap-1 p-0.5 bg-gray-100 dark:bg-gray-700/50 rounded-md">
                            <button
                                type="button"
                                @click="switchType('employee')"
                                :disabled="activeType !== 'employee' && !!blockedTypeReason"
                                :title="activeType !== 'employee' && blockedTypeReason ? blockedTypeReason : ''"
                                :class="[
                                    activeType === 'employee' ? 'bg-white dark:bg-gray-800 text-primary shadow-sm' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200',
                                    activeType !== 'employee' && blockedTypeReason ? 'opacity-40 cursor-not-allowed hover:text-gray-500' : '',
                                    'inline-flex items-center justify-center gap-1 rounded px-2 py-1 text-[11px] font-semibold transition-colors'
                                ]"
                            >
                                <i class="ri-user-line"></i> Наёмные
                            </button>
                            <button
                                type="button"
                                @click="switchType('contractor')"
                                :disabled="activeType !== 'contractor' && !!blockedTypeReason"
                                :title="activeType !== 'contractor' && blockedTypeReason ? blockedTypeReason : ''"
                                :class="[
                                    activeType === 'contractor' ? 'bg-white dark:bg-gray-800 text-purple-600 dark:text-purple-400 shadow-sm' : 'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200',
                                    activeType !== 'contractor' && blockedTypeReason ? 'opacity-40 cursor-not-allowed hover:text-gray-500' : '',
                                    'inline-flex items-center justify-center gap-1 rounded px-2 py-1 text-[11px] font-semibold transition-colors'
                                ]"
                            >
                                <i class="ri-briefcase-line"></i> Подрядчики
                            </button>
                        </div>
                        <p v-if="blockedTypeReason" class="text-[10px] text-gray-400 mt-1.5 leading-snug">
                            {{ blockedTypeReason }}
                        </p>
                    </div>

                    <div class="px-2 py-1 border-b border-gray-100 dark:border-gray-700">
                        <input
                            v-model="search"
                            type="text"
                            :placeholder="activeType === 'contractor' ? 'Поиск подрядчика...' : 'Поиск сотрудника...'"
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
                                :checked="selectedIds.includes(opt.id)"
                                @change="toggleOption(opt.id)"
                                class="h-3.5 w-3.5 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer"
                            />
                            <span>{{ optionLabel(opt) }}</span>
                        </label>
                        <div v-if="!filteredOptions.length" class="px-3 py-2 text-xs text-gray-400">
                            <template v-if="activeType === 'contractor'">
                                Нет подрядчиков. Подрядчик — это клиент с ролью «Подрядчик» в карточке клиента.
                            </template>
                            <template v-else>Ничего не найдено</template>
                        </div>
                    </div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
