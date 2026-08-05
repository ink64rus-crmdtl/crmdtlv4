<script setup>
import { computed } from 'vue';

const props = defineProps({
    posts: { type: Array, default: () => [] },
    appointments: { type: Array, default: () => [] },
    date: { type: Date, required: true },
    loading: Boolean,
});

const emit = defineEmits(['edit', 'create']);

const DAY_START = 7; // 07:00
const DAY_END = 22; // 22:00
const TOTAL_MIN = (DAY_END - DAY_START) * 60;

const hourMarks = computed(() => {
    const marks = [];
    for (let h = DAY_START; h <= DAY_END; h++) marks.push(h);
    return marks;
});

const hourMarkPercent = (h) => ((h - DAY_START) / (DAY_END - DAY_START)) * 100;

const isoDate = computed(() => {
    const d = props.date;
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
});

const minutesSinceStart = (localDateTime) => {
    const timePart = localDateTime.split('T')[1];
    const [h, m] = timePart.split(':').map(Number);
    return (h - DAY_START) * 60 + m;
};

const appointmentsForPost = (postId) => {
    return props.appointments.filter(a => {
        const matchesPost = postId === null ? !a.post_id : a.post_id === postId;
        return matchesPost && a.start_at_local && a.start_at_local.slice(0, 10) === isoDate.value;
    });
};

const barStyle = (appt) => {
    const startMin = Math.min(TOTAL_MIN, Math.max(0, minutesSinceStart(appt.start_at_local)));
    const endMin = Math.min(TOTAL_MIN, Math.max(0, minutesSinceStart(appt.end_at_local)));
    const left = (startMin / TOTAL_MIN) * 100;
    const width = Math.max(1, ((endMin - startMin) / TOTAL_MIN) * 100);
    return { left: `${left}%`, width: `${width}%`, backgroundColor: appt.color };
};

const onRowClick = (post, event) => {
    const rect = event.currentTarget.getBoundingClientRect();
    const fraction = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
    const stepMinutes = Math.round((fraction * TOTAL_MIN) / 15) * 15;
    const pad = (n) => String(n).padStart(2, '0');

    const toTimeStr = (totalMinutes) => {
        const h = DAY_START + Math.floor(totalMinutes / 60);
        const m = totalMinutes % 60;
        return `${isoDate.value}T${pad(h)}:${pad(m)}`;
    };

    emit('create', {
        branch_id: post ? post.branch_id : null,
        post_id: post ? post.id : null,
        start_at: toTimeStr(stepMinutes),
        end_at: toTimeStr(Math.min(TOTAL_MIN, stepMinutes + 60)),
    });
};

const rows = computed(() => {
    const list = props.posts.map(p => ({ key: String(p.id), post: p }));
    list.push({ key: 'none', post: null });
    return list;
});
</script>

<template>
    <div v-if="posts.length === 0" class="text-sm text-gray-400 py-8 text-center">
        Посты не добавлены — Настройки → Посты
    </div>
    <div v-else class="border border-gray-200 dark:border-gray-700 rounded-md overflow-hidden">
        <div class="flex border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
            <div class="w-40 shrink-0"></div>
            <div class="relative flex-1 h-7">
                <span
                    v-for="h in hourMarks"
                    :key="h"
                    class="absolute top-1 text-[10px] text-gray-400 -translate-x-1/2"
                    :style="{ left: hourMarkPercent(h) + '%' }"
                >{{ h }}:00</span>
            </div>
        </div>

        <div v-for="row in rows" :key="row.key" class="flex border-b border-gray-100 dark:border-gray-700/50 last:border-b-0 min-h-[56px]">
            <div class="w-40 shrink-0 flex items-center px-3 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-50/30 dark:bg-gray-800/20">
                {{ row.post ? row.post.name : 'Без поста' }}
            </div>
            <div class="relative flex-1 cursor-pointer" @click="onRowClick(row.post, $event)">
                <div class="absolute inset-0 flex pointer-events-none">
                    <div v-for="h in hourMarks.slice(0, -1)" :key="h" class="flex-1 border-l border-gray-100 dark:border-gray-700/30"></div>
                </div>
                <div
                    v-for="appt in appointmentsForPost(row.post ? row.post.id : null)"
                    :key="appt.id"
                    @click.stop="emit('edit', appt)"
                    class="absolute top-1.5 bottom-1.5 rounded px-2 py-1 text-[11px] text-white cursor-pointer overflow-hidden shadow-sm hover:opacity-90 transition-opacity"
                    :style="barStyle(appt)"
                    :title="appt.title"
                >
                    {{ appt.title }}
                </div>
            </div>
        </div>
    </div>
    <div v-if="loading" class="text-center py-4 text-xs text-gray-400">Загрузка...</div>
</template>
