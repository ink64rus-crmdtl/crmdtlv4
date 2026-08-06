<script setup>
import FullCalendar from '@fullcalendar/vue3';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import ruLocale from '@fullcalendar/core/locales/ru';
import { computed } from 'vue';
import { appointmentCardLines, escapeHtml } from '@/Utils/appointmentCard.js';

const props = defineProps({
    posts: { type: Array, default: () => [] },
    appointments: { type: Array, default: () => [] },
    date: { type: Date, required: true },
    loading: Boolean,
    cardFields: { type: Array, default: undefined },
});

const emit = defineEmits(['edit', 'create', 'reschedule', 'hover', 'unhover']);

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

// Локальная (не UTC) строка "YYYY-MM-DDTHH:mm" — тот же контракт, что и
// start_at_local/end_at_local везде в календаре записей.
const toLocalDateTimeStr = (date) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
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
    editable: true,
    eventResizableFromStart: true,
    events: eventsForPost(post ? post.id : null),
    eventContent: (arg) => {
        const lines = appointmentCardLines(arg.event.extendedProps.appointment, props.cardFields);
        const html = lines
            .map(l => `<div class="flex items-center gap-1 truncate"><i class="${l.icon} shrink-0"></i><span class="truncate">${escapeHtml(l.text)}</span></div>`)
            .join('');
        return { html: `<div class="flex flex-col justify-center gap-px h-full px-1 py-0.5 text-[12px] leading-tight overflow-hidden">${html}</div>` };
    },
    eventClick: (info) => emit('edit', info.event.extendedProps.appointment),
    eventMouseEnter: (info) => emit('hover', info.event.extendedProps.appointment, info.el),
    eventMouseLeave: () => emit('unhover'),
    eventDrop: (info) => emit('reschedule', {
        appointment: info.event.extendedProps.appointment,
        start_at_local: toLocalDateTimeStr(info.event.start),
        end_at_local: toLocalDateTimeStr(info.event.end),
        revert: info.revert,
    }),
    eventResize: (info) => emit('reschedule', {
        appointment: info.event.extendedProps.appointment,
        start_at_local: toLocalDateTimeStr(info.event.start),
        end_at_local: toLocalDateTimeStr(info.event.end),
        revert: info.revert,
    }),
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
    const list = props.posts.map(p => ({ key: String(p.id), label: p.name, icon: p.icon, options: optionsFor(p) }));
    list.push({ key: 'none', label: 'Без поста', icon: null, options: optionsFor(null) });
    return list;
});
</script>

<template>
    <div v-if="posts.length === 0" class="text-sm text-gray-400 py-8 text-center">
        Посты не добавлены — Настройки → Посты
    </div>
    <div v-else class="flex gap-3 overflow-x-auto pb-2">
        <div v-for="col in columns" :key="col.key" class="min-w-[240px] flex-1">
            <div class="flex items-center justify-center gap-1.5 text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 py-1.5 bg-gray-50/50 dark:bg-gray-800/30 rounded-md border border-gray-200 dark:border-gray-700">
                <i v-if="col.icon" :class="col.icon" class="text-gray-400"></i>
                {{ col.label }}
            </div>
            <FullCalendar :options="col.options" />
        </div>
    </div>
    <div v-if="loading" class="text-center py-4 text-xs text-gray-400">Загрузка...</div>
</template>
