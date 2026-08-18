<script setup>
import { computed } from 'vue';

const props = defineProps({
    modelValue: {
        type: Array,
        default: () => null, // null = не задано, будет использован фолбэк (Branch -> тенант)
    },
});

const emit = defineEmits(['update:modelValue']);

const DAYS = [
    { key: 'mon', label: 'Понедельник' },
    { key: 'tue', label: 'Вторник' },
    { key: 'wed', label: 'Среда' },
    { key: 'thu', label: 'Четверг' },
    { key: 'fri', label: 'Пятница' },
    { key: 'sat', label: 'Суббота' },
    { key: 'sun', label: 'Воскресенье' },
];

const defaultSchedule = () => DAYS.map(d => ({
    day: d.key,
    is_open: d.key !== 'sun',
    open: '09:00',
    close: '20:00',
}));

const schedule = computed(() => {
    if (Array.isArray(props.modelValue) && props.modelValue.length === 7) {
        return props.modelValue;
    }
    return defaultSchedule();
});

const updateDay = (index, patch) => {
    const next = schedule.value.map((d, i) => i === index ? { ...d, ...patch } : d);
    emit('update:modelValue', next);
};

const copyFromMonday = () => {
    const monday = schedule.value[0];
    if (!monday) return;
    const next = schedule.value.map((d, i) => i === 0 ? d : { ...d, open: monday.open, close: monday.close });
    emit('update:modelValue', next);
};
</script>

<template>
    <div>
        <div class="space-y-2">
        <div v-for="(d, index) in schedule" :key="d.day" class="flex flex-wrap items-center gap-3 py-1">
            <div class="w-40 flex items-center gap-2 shrink-0">
                <div
                    @click="updateDay(index, { is_open: !d.is_open })"
                    :class="[d.is_open ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']"
                >
                    <div :class="[d.is_open ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                </div>
                <span class="text-sm text-gray-700 dark:text-gray-300 cursor-pointer" @click="updateDay(index, { is_open: !d.is_open })">{{ DAYS[index].label }}</span>
            </div>
            <template v-if="d.is_open">
                <input
                    type="time"
                    :value="d.open"
                    @input="updateDay(index, { open: $event.target.value })"
                    class="rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-2 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                />
                <span class="text-gray-400 text-sm">—</span>
                <input
                    type="time"
                    :value="d.close"
                    @input="updateDay(index, { close: $event.target.value })"
                    class="rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-2 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                />
                <button
                    v-if="index === 0"
                    type="button"
                    @click="copyFromMonday"
                    class="inline-flex items-center gap-1 text-xs text-primary hover:text-primary-600 dark:hover:text-primary-400 transition-colors"
                    title="Скопировать время открытия/закрытия понедельника на вторник–воскресенье (состояние «выходной» у дней не меняется)"
                >
                    <i class="ri-file-copy-line"></i> Скопировать время из понедельника во все дни
                </button>
            </template>
            <span v-else class="text-xs text-gray-400">Выходной</span>
        </div>
        </div>
    </div>
</template>
