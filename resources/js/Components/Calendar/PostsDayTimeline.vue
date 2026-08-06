<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { usePostRowHeight } from '@/Composables/usePostRowHeight.js';

const props = defineProps({
    posts: { type: Array, default: () => [] },
    appointments: { type: Array, default: () => [] },
    date: { type: Date, required: true },
    loading: Boolean,
});

const emit = defineEmits(['edit', 'create']);

const scrollContainerRef = ref(null);
const rowsCount = computed(() => props.posts.length + 1); // + строка "Без поста"
const { rowHeight, containerMaxHeight, isDragging, startDrag } = usePostRowHeight(scrollContainerRef, rowsCount);

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

// Записи, пересекающиеся по времени в одной строке поста, делят высоту строки
// между собой (как в Google/Outlook), а не рисуются друг поверх друга на всю
// высоту. Если одновременно пересекается больше MAX_VISIBLE_LANES записей —
// показываем первые MAX_VISIBLE_LANES, а остальные сворачиваем в один блок
// "ещё N записей" с попапом по клику.
const MAX_VISIBLE_LANES = 3;

const pluralizeRecords = (n) => {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return 'запись';
    if ([2, 3, 4].includes(mod10) && ![12, 13, 14].includes(mod100)) return 'записи';
    return 'записей';
};

const layoutForPost = (postId) => {
    const items = appointmentsForPost(postId)
        .map(a => ({
            appt: a,
            startMin: Math.min(TOTAL_MIN, Math.max(0, minutesSinceStart(a.start_at_local))),
            endMin: Math.min(TOTAL_MIN, Math.max(0, minutesSinceStart(a.end_at_local))),
        }))
        .sort((a, b) => a.startMin - b.startMin || a.endMin - b.endMin);

    // Группируем в кластеры пересекающихся по времени записей — записи из
    // разных кластеров друг на друга по высоте не влияют.
    const clusters = [];
    let current = [];
    let clusterEnd = -Infinity;
    for (const item of items) {
        if (current.length === 0 || item.startMin < clusterEnd) {
            current.push(item);
            clusterEnd = Math.max(clusterEnd, item.endMin);
        } else {
            clusters.push(current);
            current = [item];
            clusterEnd = item.endMin;
        }
    }
    if (current.length) clusters.push(current);

    const bars = [];
    const overflowGroups = [];

    for (const cluster of clusters) {
        // Жадное распределение по дорожкам внутри кластера.
        const laneEnds = [];
        const withLane = cluster.map(item => {
            let lane = laneEnds.findIndex(end => end <= item.startMin);
            if (lane === -1) lane = laneEnds.length;
            laneEnds[lane] = item.endMin;
            return { ...item, lane };
        });
        const totalLanes = laneEnds.length;

        const toBar = (item, top, height) => ({
            appt: item.appt,
            left: `${(item.startMin / TOTAL_MIN) * 100}%`,
            width: `${Math.max(1, ((item.endMin - item.startMin) / TOTAL_MIN) * 100)}%`,
            top: `${top * 100}%`,
            height: `${height * 100}%`,
        });

        if (totalLanes <= MAX_VISIBLE_LANES) {
            withLane.forEach(item => bars.push(toBar(item, item.lane / totalLanes, 1 / totalLanes)));
            continue;
        }

        const bands = MAX_VISIBLE_LANES + 1; // видимые записи + одна полоса "ещё N"
        const visible = withLane.filter(item => item.lane < MAX_VISIBLE_LANES);
        const hidden = withLane.filter(item => item.lane >= MAX_VISIBLE_LANES);
        visible.forEach(item => bars.push(toBar(item, item.lane / bands, 1 / bands)));

        if (hidden.length) {
            const spanStart = Math.min(...hidden.map(h => h.startMin));
            const spanEnd = Math.max(...hidden.map(h => h.endMin));
            overflowGroups.push({
                key: `overflow-${postId ?? 'none'}-${spanStart}-${spanEnd}`,
                items: hidden.map(h => h.appt),
                left: `${(spanStart / TOTAL_MIN) * 100}%`,
                width: `${Math.max(1, ((spanEnd - spanStart) / TOTAL_MIN) * 100)}%`,
                top: `${(MAX_VISIBLE_LANES / bands) * 100}%`,
                height: `${(1 / bands) * 100}%`,
            });
        }
    }

    return { bars, overflowGroups };
};

const rowLayouts = computed(() => {
    const map = {};
    for (const row of rows.value) {
        map[row.key] = layoutForPost(row.post ? row.post.id : null);
    }
    return map;
});

// --- ПОПАП СО СКРЫТЫМИ ЗАПИСЯМИ ("ещё N записей") ---
const overflowPopover = ref(null);

const showOverflowPopover = (event, items) => {
    const rect = event.currentTarget.getBoundingClientRect();
    overflowPopover.value = {
        items,
        style: {
            position: 'fixed',
            top: `${rect.bottom + 4}px`,
            left: `${Math.min(rect.left, window.innerWidth - 260)}px`,
        },
    };
};

const closeOverflowPopover = () => {
    overflowPopover.value = null;
};

const onOverflowItemClick = (appt) => {
    closeOverflowPopover();
    emit('edit', appt);
};

const onGlobalKeydown = (e) => {
    if (e.key === 'Escape') closeOverflowPopover();
};

onMounted(() => {
    document.addEventListener('mousedown', closeOverflowPopover);
    document.addEventListener('keydown', onGlobalKeydown);
});

onUnmounted(() => {
    document.removeEventListener('mousedown', closeOverflowPopover);
    document.removeEventListener('keydown', onGlobalKeydown);
});

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
    <div v-else ref="scrollContainerRef" class="border border-gray-200 dark:border-gray-700 rounded-md overflow-auto" :style="containerMaxHeight ? { maxHeight: containerMaxHeight + 'px' } : {}">
        <div class="sticky top-0 z-10 flex border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
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

        <div v-for="row in rows" :key="row.key" class="relative flex border-b border-gray-100 dark:border-gray-700/50 last:border-b-0 group" :style="{ height: rowHeight + 'px' }">
            <div class="w-40 shrink-0 flex items-center px-3 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-50/30 dark:bg-gray-800/20 overflow-hidden">
                {{ row.post ? row.post.name : 'Без поста' }}
            </div>
            <div class="relative flex-1 cursor-pointer" @click="onRowClick(row.post, $event)">
                <div class="absolute inset-0 flex pointer-events-none">
                    <div v-for="h in hourMarks.slice(0, -1)" :key="h" class="flex-1 border-l border-gray-100 dark:border-gray-700/30"></div>
                </div>
                <div
                    v-for="bar in rowLayouts[row.key].bars"
                    :key="bar.appt.id"
                    @click.stop="emit('edit', bar.appt)"
                    class="absolute rounded px-2 py-1 text-[11px] text-white cursor-pointer overflow-hidden shadow-sm hover:opacity-90 transition-opacity"
                    :style="{ left: bar.left, width: bar.width, top: bar.top, height: bar.height, backgroundColor: bar.appt.color }"
                    :title="bar.appt.title"
                >
                    {{ bar.appt.title }}
                </div>
                <div
                    v-for="group in rowLayouts[row.key].overflowGroups"
                    :key="group.key"
                    @click.stop="showOverflowPopover($event, group.items)"
                    class="absolute rounded px-2 flex items-center justify-center text-[11px] font-medium text-white cursor-pointer bg-gray-500/90 hover:bg-gray-600 shadow-sm transition-colors"
                    :style="{ left: group.left, width: group.width, top: group.top, height: group.height }"
                >
                    ещё {{ group.items.length }} {{ pluralizeRecords(group.items.length) }}
                </div>
            </div>
            <div
                @mousedown="startDrag"
                :class="isDragging ? 'bg-primary/40' : 'bg-transparent group-hover:bg-primary/20'"
                class="absolute left-0 right-0 -bottom-0.5 h-1.5 cursor-row-resize transition-colors z-20"
                title="Потяните, чтобы изменить высоту строк"
            ></div>
        </div>
    </div>
    <div v-if="loading" class="text-center py-4 text-xs text-gray-400">Загрузка...</div>

    <Teleport to="body">
        <div
            v-if="overflowPopover"
            :style="overflowPopover.style"
            @mousedown.stop
            class="z-[250] w-60 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg overflow-hidden"
        >
            <div class="px-3 py-2 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase">
                Записи ({{ overflowPopover.items.length }})
            </div>
            <div class="max-h-64 overflow-y-auto custom-scrollbar">
                <button
                    v-for="appt in overflowPopover.items"
                    :key="appt.id"
                    type="button"
                    @click="onOverflowItemClick(appt)"
                    class="w-full text-left px-3 py-2 text-xs hover:bg-gray-50 dark:hover:bg-gray-700/60 flex items-center gap-2 border-b border-gray-50 dark:border-gray-700/40 last:border-b-0 transition-colors"
                >
                    <span class="h-2 w-2 rounded-full shrink-0" :style="{ backgroundColor: appt.color }"></span>
                    <span class="font-medium text-gray-700 dark:text-gray-300 shrink-0">{{ appt.start_at_local.slice(11, 16) }}–{{ appt.end_at_local.slice(11, 16) }}</span>
                    <span class="truncate text-gray-600 dark:text-gray-400">{{ appt.title }}</span>
                </button>
            </div>
        </div>
    </Teleport>
</template>
