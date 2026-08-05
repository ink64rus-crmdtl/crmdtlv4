<script setup>
const props = defineProps({
    posts: { type: Array, default: () => [] },
    appointments: { type: Array, default: () => [] },
    weekDays: { type: Array, default: () => [] },
    loading: Boolean,
});

const emit = defineEmits(['edit', 'create']);

// postId === null означает строку "Без поста" (запись без назначенного поста)
const appointmentsFor = (postId, iso) => {
    return props.appointments
        .filter(a => {
            const matchesPost = postId === null ? !a.post_id : a.post_id === postId;
            return matchesPost && a.start_at_local && a.start_at_local.slice(0, 10) === iso;
        })
        .sort((a, b) => a.start_at_local.localeCompare(b.start_at_local));
};

const onCellClick = (post, day) => {
    emit('create', {
        branch_id: post ? post.branch_id : null,
        post_id: post ? post.id : null,
        start_at: `${day.iso}T09:00`,
        end_at: `${day.iso}T10:00`,
    });
};
</script>

<template>
    <div class="overflow-x-auto">
        <table class="min-w-full border-collapse text-sm">
            <thead>
                <tr>
                    <th class="w-40 shrink-0 py-2 px-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase border-b border-gray-200 dark:border-gray-700">Пост</th>
                    <th v-for="day in weekDays" :key="day.iso" class="py-2 px-2 text-center text-xs font-bold text-gray-500 dark:text-gray-400 uppercase border-b border-l border-gray-200 dark:border-gray-700 min-w-[130px]">
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
                    <td class="py-2 px-3 text-sm font-medium text-gray-700 dark:text-gray-300 bg-gray-50/50 dark:bg-gray-800/30 align-top">{{ post.name }}</td>
                    <td
                        v-for="day in weekDays"
                        :key="day.iso"
                        @click="onCellClick(post, day)"
                        class="align-top p-1 border-l border-gray-100 dark:border-gray-700/50 cursor-pointer hover:bg-gray-50/50 dark:hover:bg-gray-800/20 transition-colors"
                    >
                        <div
                            v-for="appt in appointmentsFor(post.id, day.iso)"
                            :key="appt.id"
                            @click.stop="emit('edit', appt)"
                            class="text-[11px] leading-tight rounded px-1.5 py-1 mb-1 cursor-pointer text-white truncate shadow-sm"
                            :style="{ backgroundColor: appt.color }"
                        >
                            {{ appt.start_at_local.slice(11, 16) }} {{ appt.title }}
                        </div>
                    </td>
                </tr>
                <tr v-if="posts.length > 0" class="border-t-2 border-gray-200 dark:border-gray-700">
                    <td class="py-2 px-3 text-sm font-medium text-gray-500 dark:text-gray-400 bg-gray-50/50 dark:bg-gray-800/30 align-top">Без поста</td>
                    <td v-for="day in weekDays" :key="day.iso" class="align-top p-1 border-l border-gray-100 dark:border-gray-700/50">
                        <div
                            v-for="appt in appointmentsFor(null, day.iso)"
                            :key="appt.id"
                            @click="emit('edit', appt)"
                            class="text-[11px] leading-tight rounded px-1.5 py-1 mb-1 cursor-pointer text-white truncate shadow-sm"
                            :style="{ backgroundColor: appt.color }"
                        >
                            {{ appt.start_at_local.slice(11, 16) }} {{ appt.title }}
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        <div v-if="loading" class="text-center py-4 text-xs text-gray-400">Загрузка...</div>
    </div>
</template>
