<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';

const props = defineProps({
    modelValue: {
        type: String,
        default: '',
    },
    options: {
        type: Array,
        default: () => [],
    },
    disabled: {
        type: Boolean,
        default: false,
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

const colorClasses = {
    info: 'bg-info/10 text-info',
    warning: 'bg-warning/10 text-warning',
    success: 'bg-success/10 text-success',
    danger: 'bg-danger/10 text-danger',
    primary: 'bg-primary/10 text-primary',
    gray: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
};

const badgeClass = (color) => colorClasses[color] || colorClasses.gray;

const current = computed(() => props.options.find(o => o.value === props.modelValue));
const currentLabel = computed(() => current.value?.label || props.modelValue || '—');
const currentClass = computed(() => badgeClass(current.value?.color));

const selectableOptions = computed(() => props.options.filter(o => o.is_active !== false || o.value === props.modelValue));

// Позиционируем панель через fixed-координаты и телепортируем в <body>,
// чтобы её не обрезали overflow-контейнеры (например overflow-x-auto у таблицы).
const updatePosition = () => {
    if (!buttonRef.value) return;
    const rect = buttonRef.value.getBoundingClientRect();
    panelStyle.value = {
        position: 'fixed',
        top: `${rect.bottom + 4}px`,
        left: `${rect.left}px`,
        minWidth: `${Math.max(rect.width, 160)}px`,
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

const select = (option) => {
    isOpen.value = false;
    if (option.value === props.modelValue) return;
    emit('update:modelValue', option.value);
};

const closeDropdown = (e) => {
    if (
        containerRef.value && !containerRef.value.contains(e.target) &&
        panelRef.value && !panelRef.value.contains(e.target)
    ) {
        isOpen.value = false;
    }
};

// Скролл (в т.ч. внутри overflow-x-auto таблицы) сбивает fixed-координаты — проще закрыть список.
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
    <div class="relative inline-block" ref="containerRef">
        <button
            ref="buttonRef"
            type="button"
            @click.stop="toggle"
            :disabled="disabled"
            :class="[currentClass, 'inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium transition-colors', disabled ? 'cursor-default' : 'cursor-pointer hover:opacity-80']"
        >
            {{ currentLabel }}
            <i v-if="!disabled" class="ri-arrow-down-s-line text-[13px]"></i>
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
                    <button
                        v-for="option in selectableOptions"
                        :key="option.value"
                        type="button"
                        @click="select(option)"
                        class="w-full flex items-center gap-2 px-3 py-1.5 text-left text-xs hover:bg-gray-100 dark:hover:bg-gray-700/60 transition-colors"
                    >
                        <span :class="[badgeClass(option.color), 'inline-flex items-center px-2 py-0.5 rounded font-medium']">
                            {{ option.label || option.value }}
                        </span>
                        <i v-if="option.value === modelValue" class="ri-check-line text-primary ml-auto"></i>
                    </button>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
