<script setup>
import FullCalendar from '@fullcalendar/vue3';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import ruLocale from '@fullcalendar/core/locales/ru';
import { computed } from 'vue';

const props = defineProps({
    posts: { type: Array, default: () => [] },
    appointments: { type: Array, default: () => [] },
    date: { type: Date, required: true },
    loading: Boolean,
});

const emit = defineEmits(['edit', 'create']);

const isoDate = computed(() => {
    const d = props.date;
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
});

const eventsForPost = (postId) => {
    return props.appointments
        .filter(a => (postId === null ? !a.post_id : a.post_id === postId))
        .map(a => ({ id: a.id, title: a.title, start: a.start, end: a.end, color: a.color, extendedProps: { appointment: a } }));
};

const optionsFor = (post) => ({
    plugins: [timeGridPlugin, interactionPlugin],
    initialView: 'timeGridDay',
    initialDate: isoDate.value,
    headerToolbar: false,
    locales: [ruLocale],
    locale: 'ru',
    height: 'auto',
    slotMinTime: '07:00:00',
    slotMaxTime: '22:00:00',
    nowIndicator: true,
    timeZone: 'local',
    allDaySlot: false,
    slotLabelFormat: { hour: '2-digit', minute: '2-digit' },
    events: eventsForPost(post ? post.id : null),
    eventClick: (info) => emit('edit', info.event.extendedProps.appointment),
    dateClick: (info) => emit('create', {
        branch_id: post ? post.branch_id : null,
        post_id: post ? post.id : null,
        start_at: info.dateStr.slice(0, 16),
        end_at: null,
    }),
});

// Пересчитывается вместе с изменением даты/записей — сами мини-календари реагируют
// на новый массив events реактивно, без полной пересборки FullCalendar-инстанса.
const columns = computed(() => {
    if (props.posts.length === 0) return [];
    const list = props.posts.map(p => ({ key: String(p.id), label: p.name, options: optionsFor(p) }));
    list.push({ key: 'none', label: 'Без поста', options: optionsFor(null) });
    return list;
});
</script>

<template>
    <div v-if="posts.length === 0" class="text-sm text-gray-400 py-8 text-center">
        Посты не добавлены — Настройки → Посты
    </div>
    <div v-else class="flex gap-3 overflow-x-auto pb-2">
        <div v-for="col in columns" :key="col.key" class="min-w-[240px] flex-1">
            <div class="text-center text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 py-1.5 bg-gray-50/50 dark:bg-gray-800/30 rounded-md border border-gray-200 dark:border-gray-700">
                {{ col.label }}
            </div>
            <FullCalendar :options="col.options" />
        </div>
    </div>
    <div v-if="loading" class="text-center py-4 text-xs text-gray-400">Загрузка...</div>
</template>
