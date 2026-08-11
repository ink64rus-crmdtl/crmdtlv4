<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => [], // массив ID выбранных сотрудников
    },
    options: {
        type: Array,
        default: () => [], // полный список сотрудников {id, first_name, last_name}
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

const emit = defineEmits(['update:modelValue']);

const isOpen = ref(false);
const containerRef = ref(null);
const buttonRef = ref(null);
const panelRef = ref(null);
const panelStyle = ref({});
// См. SearchableSelect.vue — тот же обход конфликта Teleport to="body" с
// нативным <dialog> (top layer перекрывает обычный z-index панели, клики
// по пунктам не доходят), если компонент открыт внутри формы в <Modal>.
const teleportTarget = ref(typeof document !== 'undefined' ? document.body : null);

const employeeLabel = (emp) => `${emp.last_name || ''} ${(emp.first_name || '').charAt(0)}${emp.first_name ? '.' : ''}`.trim();

const selectedEmployees = computed(() => props.options.filter(o => props.modelValue.includes(o.id)));

// Позиционируем панель через fixed-координаты и телепортируем в <body>,
// чтобы её не обрезали overflow-контейнеры и не перекрывали оверлеи с большим z-index (например Offcanvas).
const updatePosition = () => {
    if (!buttonRef.value) return;
    const rect = buttonRef.value.getBoundingClientRect();
    panelStyle.value = {
        position: 'fixed',
        top: `${rect.bottom + 4}px`,
        left: `${rect.left}px`,
        minWidth: `${Math.max(rect.width, 200)}px`,
    };
};

const toggle = async () => {
    if (props.disabled) return;
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        teleportTarget.value = containerRef.value?.closest('dialog') || document.body;
        await nextTick();
        updatePosition();
    }
};

const toggleEmployee = (id) => {
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
    <div class="relative inline-flex flex-wrap items-center gap-1" ref="containerRef">
        <!-- Все выбранные показаны чипами, чтобы не приходилось открывать список ради полного состава -->
        <span
            v-for="emp in selectedEmployees"
            :key="emp.id"
            class="inline-flex items-center gap-1 pl-2 pr-1 py-0.5 rounded-full bg-primary/10 text-primary text-[11px] font-medium"
        >
            {{ employeeLabel(emp) }}
            <button
                v-if="!disabled"
                type="button"
                @click.stop="toggleEmployee(emp.id)"
                class="hover:text-danger transition-colors"
                title="Убрать исполнителя"
            >
                <i class="ri-close-line text-xs"></i>
            </button>
        </span>

        <span v-if="disabled && selectedEmployees.length === 0" class="text-[11px] text-gray-400">{{ placeholder }}</span>

        <button
            v-if="!disabled"
            ref="buttonRef"
            type="button"
            @click.stop="toggle"
            class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-full border border-dashed border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400 hover:border-primary hover:text-primary text-[11px] font-medium transition-colors"
        >
            <i class="ri-add-line text-xs"></i>
            <span v-if="selectedEmployees.length === 0">{{ placeholder }}</span>
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
                    class="z-[250] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg py-1 max-h-64 overflow-y-auto"
                >
                    <label
                        v-for="emp in options"
                        :key="emp.id"
                        class="flex items-center gap-2 px-3 py-1.5 text-xs text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700/60 cursor-pointer transition-colors"
                    >
                        <input
                            type="checkbox"
                            :checked="modelValue.includes(emp.id)"
                            @change="toggleEmployee(emp.id)"
                            class="h-3.5 w-3.5 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer"
                        />
                        <span>{{ emp.last_name }} {{ emp.first_name }}</span>
                    </label>
                    <div v-if="!options.length" class="px-3 py-2 text-xs text-gray-400">Нет сотрудников</div>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
