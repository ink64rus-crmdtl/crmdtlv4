<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SalesNav from '@/Components/SalesNav.vue';
import PageHelper from '@/Components/PageHelper.vue';
import DataTable from '@/Components/DataTable.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    tasks: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    taskTypes: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
});

// --- ФИЛЬТРЫ ---
const search = ref(props.filters?.search || '');
const mine = ref(props.filters?.mine !== '0');
const status = ref(props.filters?.status || 'open');

const reload = () => {
    router.get(route('tasks.index'), {
        search: search.value || undefined,
        mine: mine.value ? '1' : '0',
        status: status.value,
    }, { preserveState: true, preserveScroll: true });
};

watch(search, useDebounceFn(reload, 300));
watch([mine, status], reload);

const columns = [
    { key: 'title', label: 'Задача' },
    { key: 'taskable_label', label: 'Связано с' },
    { key: 'type', label: 'Тип' },
    { key: 'due_at', label: 'Срок', align: 'right' },
    { key: 'assigned_to', label: 'Ответственный' },
];

const formatDue = (iso) => iso ? new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';

const taskableRoute = (task) => {
    const map = {
        Deal: 'sales.deals.show',
        Client: 'crm.clients.show',
        WorkOrder: 'operations.work-orders.show',
        Vehicle: 'crm.vehicles.show',
    };
    return map[task.taskable_type] ? route(map[task.taskable_type], task.taskable_id) : null;
};

const complete = (task) => {
    axios.post(route('tasks.complete', task.id)).then(() => router.reload({ only: ['tasks'] }));
};

const reopen = (task) => {
    axios.post(route('tasks.reopen', task.id)).then(() => router.reload({ only: ['tasks'] }));
};

const destroy = (task) => {
    if (!confirm(`Удалить задачу «${task.title}»?`)) return;
    router.delete(route('tasks.destroy', task.id), { preserveScroll: true });
};

// --- СОЗДАНИЕ ---
const isModalOpen = ref(false);
const taskForm = useForm({
    taskable_type: '',
    taskable_id: '',
    assigned_to_user_id: '',
    type: '',
    title: '',
    description: '',
    due_at: '',
});

const openModal = () => {
    taskForm.reset();
    isModalOpen.value = true;
};

const submit = () => {
    taskForm.post(route('tasks.store'), {
        preserveScroll: true,
        onSuccess: () => { isModalOpen.value = false; },
    });
};
</script>

<template>
    <Head title="Мои задачи" />

    <AuthenticatedLayout>
        <template #header>Продажи</template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">

            <SalesNav />

            <PageHelper title="Как работают задачи">
                <p>Задача — это личное напоминание менеджеру: позвонить, отправить КП, приехать на замер. Задачу можно поставить как «в воздухе», так и привязать к сделке, клиенту, заказу или автомобилю.</p>
                <p>Открытая сделка без единой незавершённой задачи считается «брошенной» — это отдельно подсвечивается на доске сделок.</p>
            </PageHelper>

            <!-- Заголовок + фильтры -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6">
                <div class="flex flex-wrap justify-between items-start gap-4">
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Мои задачи</h1>
                    <button @click="openModal" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                        <i class="ri-add-line text-base"></i> Новая задача
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-3 mt-5 pt-5 border-t border-gray-200 dark:border-gray-700">
                    <div class="relative flex-1 min-w-[220px]">
                        <i class="ri-search-line absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input v-model="search" type="text" placeholder="Поиск по названию..." class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 pl-9 pr-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                    </div>
                    <select v-model="status" class="rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                        <option value="open" class="bg-white dark:bg-gray-800">Открытые</option>
                        <option value="overdue" class="bg-white dark:bg-gray-800">Просроченные</option>
                        <option value="completed" class="bg-white dark:bg-gray-800">Выполненные</option>
                        <option value="all" class="bg-white dark:bg-gray-800">Все</option>
                    </select>
                    <button
                        @click="mine = !mine"
                        :class="[mine ? 'bg-primary text-white border-primary' : 'bg-transparent text-gray-500 border-gray-200 dark:border-gray-700 hover:border-primary hover:text-primary', 'inline-flex items-center gap-1.5 rounded-md border px-3 py-2 text-sm font-medium transition-colors']"
                    >
                        <i class="ri-user-line"></i> Только мои
                    </button>
                </div>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                <DataTable :columns="columns" :rows="tasks.data" empty-message="Задач по этому фильтру не найдено." has-actions>
                    <template #cell-title="{ row }">
                        <div class="flex items-start gap-2">
                            <button
                                @click="row.completed_at ? reopen(row) : complete(row)"
                                :class="[row.completed_at ? 'bg-success text-white border-success' : 'border-gray-300 dark:border-gray-600 hover:border-success', 'w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 mt-0.5']"
                                :title="row.completed_at ? 'Вернуть в работу' : 'Отметить выполненной'"
                            >
                                <i v-if="row.completed_at" class="ri-check-line text-[11px]"></i>
                            </button>
                            <div class="min-w-0">
                                <div :class="[row.completed_at ? 'line-through text-gray-500' : 'text-gray-800 dark:text-gray-200', 'text-sm font-medium']">{{ row.title }}</div>
                                <div v-if="row.description" class="text-xs text-gray-500 mt-0.5 truncate max-w-md">{{ row.description }}</div>
                            </div>
                        </div>
                    </template>
                    <template #cell-taskable_label="{ row }">
                        <Link v-if="row.taskable_label && taskableRoute(row)" :href="taskableRoute(row)" class="text-xs text-primary hover:underline">{{ row.taskable_label }}</Link>
                        <span v-else-if="row.taskable_label" class="text-xs text-gray-500">{{ row.taskable_label }}</span>
                        <span v-else class="text-xs text-gray-300 dark:text-gray-600">—</span>
                    </template>
                    <template #cell-type="{ row }">
                        <span v-if="row.type" class="text-xs text-gray-500">{{ row.type }}</span>
                        <span v-else class="text-xs text-gray-300 dark:text-gray-600">—</span>
                    </template>
                    <template #cell-due_at="{ row }">
                        <span :class="[row.is_overdue && !row.completed_at ? 'text-danger font-medium' : 'text-gray-500', 'text-xs whitespace-nowrap']">{{ formatDue(row.due_at) }}</span>
                    </template>
                    <template #cell-assigned_to="{ row }">
                        <span class="text-xs text-gray-500">{{ row.assigned_to?.name || '—' }}</span>
                    </template>
                    <template #actions="{ row }">
                        <button @click.stop="destroy(row)" class="w-8 h-8 rounded-full bg-danger/10 text-danger flex items-center justify-center hover:bg-danger hover:text-white transition-colors" title="Удалить задачу">
                            <i class="ri-delete-bin-line"></i>
                        </button>
                    </template>
                </DataTable>
                <Pagination :meta="tasks" preserve-scroll preserve-state />
            </div>
        </div>

        <!-- Новая задача -->
        <Modal :show="isModalOpen" @close="isModalOpen = false" max-width="md">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Новая задача</h3>
                <button @click="isModalOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-xl"></i></button>
            </div>
            <form @submit.prevent="submit" class="flex flex-col">
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                        <input v-model="taskForm.title" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        <p v-if="taskForm.errors.title" class="text-xs text-danger mt-1">{{ taskForm.errors.title }}</p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div v-if="taskTypes.length > 0">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип</label>
                            <select v-model="taskForm.type" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" class="bg-white dark:bg-gray-800">Не указан</option>
                                <option v-for="t in taskTypes" :key="t.id" :value="t.value" class="bg-white dark:bg-gray-800">{{ t.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Срок</label>
                            <input v-model="taskForm.due_at" type="datetime-local" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ответственный</label>
                        <select v-model="taskForm.assigned_to_user_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                            <option value="" class="bg-white dark:bg-gray-800">Я</option>
                            <option v-for="u in users" :key="u.id" :value="u.id" class="bg-white dark:bg-gray-800">{{ u.name }}</option>
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1">Задача из этой формы не привязывается к записи — только личное напоминание. Для задачи по конкретной сделке используйте вкладку «Задачи» на её Карточке.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Описание</label>
                        <textarea v-model="taskForm.description" rows="3" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"></textarea>
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                    <button type="button" @click="isModalOpen = false" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                    <button type="submit" :disabled="taskForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Создать</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
