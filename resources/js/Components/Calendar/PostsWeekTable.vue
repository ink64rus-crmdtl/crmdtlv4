<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { usePostRowHeight } from '@/Composables/usePostRowHeight.js';
import { appointmentCardLines } from '@/Utils/appointmentCard.js';

const props = defineProps({
    posts: { type: Array, default: () => [] },
    appointments: { type: Array, default: () => [] },
    weekDays: { type: Array, default: () => [] },
    loading: Boolean,
    cardFields: { type: Array, default: undefined },
});

const emit = defineEmits(['edit', 'create', 'reschedule', 'hover', 'unhover']);

const scrollContainerRef = ref(null);
const rowsCount = computed(() => props.posts.length + 1); // + строка "Без поста"
const { rowHeight, containerMaxHeight, isDragging, startDrag } = usePostRowHeight(scrollContainerRef, rowsCount);

// postId === null означает строку "Без поста" (запись без назначенного поста)
const appointmentsFor = (postId, iso) => {
    return props.appointments
        .filter(a => {
            const matchesPost = postId === null ? !a.post_id : a.post_id === postId;
            return matchesPost && a.start_at_local && a.start_at_local.slice(0, 10) === iso;
        })
        .sort((a, b) => a.start_at_local.localeCompare(b.start_at_local));
};

// Записи в ячейке делят высоту ячейки поровну (как в дневном виде), не более
// MAX_VISIBLE — остальные сворачиваются в блок "ещё N записей" с попапом.
const MAX_VISIBLE = 3;

const pluralizeRecords = (n) => {
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return 'запись';
    if ([2, 3, 4].includes(mod10) && ![12, 13, 14].includes(mod100)) return 'записи';
    return 'записей';
};

const cellLayout = (postId, iso) => {
    const items = appointmentsFor(postId, iso);
    if (items.length <= MAX_VISIBLE) return { visible: items, hidden: [] };
    return { visible: items.slice(0, MAX_VISIBLE), hidden: items.slice(MAX_VISIBLE) };
};

const onCellClick = (post, day) => {
    emit('create', {
        branch_id: post ? post.branch_id : null,
        post_id: post ? post.id : null,
        start_at: `${day.iso}T09:00`,
        end_at: `${day.iso}T10:00`,
    });
};

// --- ПОПАП СО СКРЫТЫМИ ЗАПИСЯМИ ("ещё N записей") ---
const overflowPopover = ref(null);

const showOverflowPopover = (event, items) => {
    const rect = event.currentTarget.getBoundingClientRect();
    overflowPopover.value = {
        items,
        style: { position: 'fixed', top: `${rect.bottom + 4}px`, left: `${Math.min(rect.left, window.innerWidth - 260)}px` },
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

// --- ПЕРЕНОС ЗАПИСИ МЕЖДУ ДНЯМИ/ПОСТАМИ ПЕРЕТАСКИВАНИЕМ (нативный HTML5 DnD) ---
// Время суток и длительность записи сохраняются — переносится только дата
// (и пост, если запись перетащили в другую строку).
const draggingApptId = ref(null);

const onChipDragStart = (event, appt) => {
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', String(appt.id));
    draggingApptId.value = appt.id;
};

const onChipDragEnd = () => {
    draggingApptId.value = null;
};

const shiftDateByDays = (isoDateStr, deltaDays) => {
    const d = new Date(`${isoDateStr}T00:00:00`);
    d.setDate(d.getDate() + deltaDays);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const onCellDrop = (event, post, day) => {
    event.preventDefault();
    const apptId = Number(event.dataTransfer.getData('text/plain'));
    draggingApptId.value = null;
    const appt = props.appointments.find(a => a.id === apptId);
    if (!appt) return;

    const targetPostId = post ? post.id : null;
    const samePost = targetPostId === null ? !appt.post_id : appt.post_id === targetPostId;
    const oldStartDate = appt.start_at_local.slice(0, 10);
    if (samePost && oldStartDate === day.iso) return; // ничего не изменилось

    const deltaDays = Math.round((new Date(`${day.iso}T00:00:00`) - new Date(`${oldStartDate}T00:00:00`)) / 86400000);
    const newEndDate = shiftDateByDays(appt.end_at_local.slice(0, 10), deltaDays);

    emit('reschedule', {
        appointment: appt,
        start_at_local: `${day.iso}T${appt.start_at_local.slice(11, 16)}`,
        end_at_local: `${newEndDate}T${appt.end_at_local.slice(11, 16)}`,
        post_id: targetPostId,
    });
};
</script>

<template>
    <div ref="scrollContainerRef" class="overflow-auto" :style="containerMaxHeight ? { maxHeight: containerMaxHeight + 'px' } : {}">
        <table class="min-w-full border-collapse text-sm">
            <thead>
                <tr>
                    <th class="sticky top-0 z-10 w-40 shrink-0 py-2 px-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46]">Пост</th>
                    <th v-for="day in weekDays" :key="day.iso" class="sticky top-0 z-10 py-2 px-2 text-center text-xs font-bold text-gray-500 dark:text-gray-400 uppercase border-b border-l border-gray-200 dark:border-gray-700 min-w-[130px] bg-white dark:bg-[#313a46]">
                        {{ day.label }} <span class="font-normal normal-case text-gray-400">{{ day.dayNumber }}</span>
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="posts.length === 0">
                    <td :colspan="weekDays.length + 1" class="py-8 text-center text-sm text-gray-400">
                        Посты не добавлены — Настройки → Посты
                    </td>
                </tr>
                <tr v-for="post in posts" :key="post.id" class="border-b border-gray-100 dark:border-gray-700/50">
                    <td class="relative p-0 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-50/50 dark:bg-gray-800/30 align-top group">
                        <div class="flex items-center gap-1.5 px-3 overflow-hidden" :style="{ height: rowHeight + 'px' }">
                            <i v-if="post.icon" :class="post.icon" class="shrink-0 text-gray-400"></i>
                            <span class="truncate">{{ post.name }}</span>
                        </div>
                        <div
                            @mousedown="startDrag"
                            :class="isDragging ? 'bg-primary/40' : 'bg-transparent group-hover:bg-primary/20'"
                            class="absolute left-0 right-0 -bottom-0.5 h-1.5 cursor-row-resize transition-colors z-20"
                            title="Потяните, чтобы изменить высоту строк"
                        ></div>
                    </td>
                    <td
                        v-for="day in weekDays"
                        :key="day.iso"
                        @click="onCellClick(post, day)"
                        @dragover.prevent
                        @drop="onCellDrop($event, post, day)"
                        class="align-top p-1 border-l border-gray-100 dark:border-gray-700/50 cursor-pointer hover:bg-gray-50/50 dark:hover:bg-gray-800/20 transition-colors"
                    >
                        <div class="flex flex-col gap-0.5" :style="{ height: rowHeight + 'px' }">
                            <div
                                v-for="appt in cellLayout(post.id, day.iso).visible"
                                :key="appt.id"
                                draggable="true"
                                @dragstart="onChipDragStart($event, appt)"
                                @dragend="onChipDragEnd"
                                @click.stop="emit('edit', appt)"
                                @mouseenter="emit('hover', appt, $event.currentTarget)"
                                @mouseleave="emit('unhover')"
                                :class="draggingApptId === appt.id ? 'opacity-40' : ''"
                                class="flex-1 min-h-0 flex flex-col justify-center gap-px overflow-hidden rounded px-1.5 py-1 cursor-move text-white shadow-sm transition-opacity"
                                :style="{ backgroundColor: appt.color }"
                            >
                                <div v-for="line in appointmentCardLines(appt, cardFields)" :key="line.key" class="flex items-center gap-1 text-[12px] leading-tight truncate">
                                    <i :class="line.icon" class="shrink-0"></i><span class="truncate">{{ line.text }}</span>
                                </div>
                            </div>
                            <div
                                v-if="cellLayout(post.id, day.iso).hidden.length"
                                @click.stop="showOverflowPopover($event, cellLayout(post.id, day.iso).hidden)"
                                class="flex-1 min-h-0 flex items-center justify-center text-[11px] font-medium rounded px-1.5 text-white cursor-pointer bg-gray-500/90 hover:bg-gray-600 shadow-sm transition-colors"
                            >
                                ещё {{ cellLayout(post.id, day.iso).hidden.length }} {{ pluralizeRecords(cellLayout(post.id, day.iso).hidden.length) }}
                            </div>
                        </div>
                    </td>
                </tr>
                <tr v-if="posts.length > 0" class="border-t-2 border-gray-200 dark:border-gray-700">
                    <td class="relative p-0 text-sm font-medium text-gray-500 dark:text-gray-400 bg-gray-50/50 dark:bg-gray-800/30 align-top group">
                        <div class="flex items-center px-3 overflow-hidden" :style="{ height: rowHeight + 'px' }">Без поста</div>
                        <div
                            @mousedown="startDrag"
                            :class="isDragging ? 'bg-primary/40' : 'bg-transparent group-hover:bg-primary/20'"
                            class="absolute left-0 right-0 -bottom-0.5 h-1.5 cursor-row-resize transition-colors z-20"
                            title="Потяните, чтобы изменить высоту строк"
                        ></div>
                    </td>
                    <td
                        v-for="day in weekDays"
                        :key="day.iso"
                        @dragover.prevent
                        @drop="onCellDrop($event, null, day)"
                        class="align-top p-1 border-l border-gray-100 dark:border-gray-700/50"
                    >
                        <div class="flex flex-col gap-0.5" :style="{ height: rowHeight + 'px' }">
                            <div
                                v-for="appt in cellLayout(null, day.iso).visible"
                                :key="appt.id"
                                draggable="true"
                                @dragstart="onChipDragStart($event, appt)"
                                @dragend="onChipDragEnd"
                                @click="emit('edit', appt)"
                                @mouseenter="emit('hover', appt, $event.currentTarget)"
                                @mouseleave="emit('unhover')"
                                :class="draggingApptId === appt.id ? 'opacity-40' : ''"
                                class="flex-1 min-h-0 flex flex-col justify-center gap-px overflow-hidden rounded px-1.5 py-1 cursor-move text-white shadow-sm transition-opacity"
                                :style="{ backgroundColor: appt.color }"
                            >
                                <div v-for="line in appointmentCardLines(appt, cardFields)" :key="line.key" class="flex items-center gap-1 text-[12px] leading-tight truncate">
                                    <i :class="line.icon" class="shrink-0"></i><span class="truncate">{{ line.text }}</span>
                                </div>
                            </div>
                            <div
                                v-if="cellLayout(null, day.iso).hidden.length"
                                @click.stop="showOverflowPopover($event, cellLayout(null, day.iso).hidden)"
                                class="flex-1 min-h-0 flex items-center justify-center text-[11px] font-medium rounded px-1.5 text-white cursor-pointer bg-gray-500/90 hover:bg-gray-600 shadow-sm transition-colors"
                            >
                                ещё {{ cellLayout(null, day.iso).hidden.length }} {{ pluralizeRecords(cellLayout(null, day.iso).hidden.length) }}
                            </div>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        <div v-if="loading" class="text-center py-4 text-xs text-gray-400">Загрузка...</div>
    </div>

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
