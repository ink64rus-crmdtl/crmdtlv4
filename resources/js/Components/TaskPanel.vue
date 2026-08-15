<script setup>
import Modal from '@/Components/Modal.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';
import axios from 'axios';

// Встраиваемая панель задач (Фаза 17, этап 2) — Task ОБЩЕСИСТЕМНАЯ и
// полиморфная, поэтому этот компонент годится под Карточку любой сущности
// (Deal/Client/WorkOrder/Vehicle), а не только сделки.
const props = defineProps({
    taskableType: { type: String, required: true },
    taskableId: { type: Number, required: true },
    branchId: { type: [Number, String], default: null },
    taskTypes: { type: Array, default: () => [] },
});

const tasks = ref([]);
const loading = ref(true);

const load = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get(route('tasks.index'), {
            params: { taskable_type: props.taskableType, taskable_id: props.taskableId, status: 'all' },
        });
        tasks.value = data;
    } finally {
        loading.value = false;
    }
};

onMounted(load);

const isModalOpen = ref(false);
const taskForm = useForm({
    taskable_type: props.taskableType,
    taskable_id: props.taskableId,
    branch_id: props.branchId,
    type: '',
    title: '',
    description: '',
    due_at: '',
});

const openModal = () => {
    taskForm.reset();
    taskForm.taskable_type = props.taskableType;
    taskForm.taskable_id = props.taskableId;
    taskForm.branch_id = props.branchId;
    isModalOpen.value = true;
};

const submit = () => {
    taskForm.post(route('tasks.store'), {
        preserveScroll: true,
        onSuccess: () => { isModalOpen.value = false; load(); },
    });
};

const complete = async (task) => {
    await axios.post(route('tasks.complete', task.id));
    load();
};

const reopen = async (task) => {
    await axios.post(route('tasks.reopen', task.id));
    load();
};

const destroy = async (task) => {
    if (!confirm(`Удалить задачу «${task.title}»?`)) return;
    await axios.delete(route('tasks.destroy', task.id));
    load();
};

const formatDue = (iso) => iso ? new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : null;
</script>

<template>
    <div>
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Задачи</h3>
            <button @click="openModal" class="inline-flex items-center gap-1.5 rounded px-3 py-1.5 text-xs font-medium bg-primary text-white hover:bg-primary-600">
                <i class="ri-add-line"></i> Задача
            </button>
        </div>

        <div v-if="loading" class="text-center py-6 text-sm text-gray-400">Загрузка...</div>

        <div v-else-if="tasks.length === 0" class="text-center py-8">
            <div class="w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-400 flex items-center justify-center mx-auto mb-3">
                <i class="ri-list-check-2 text-xl"></i>
            </div>
            <p class="text-sm text-gray-500">Задач пока нет.</p>
        </div>

        <div v-else class="space-y-2">
            <div
                v-for="task in tasks"
                :key="task.id"
                :class="[
                    task.completed_at ? 'border-gray-200 dark:border-gray-700 opacity-60' : (task.is_overdue ? 'border-danger/50 bg-danger/5' : 'border-gray-200/80 dark:border-gray-700/80'),
                    'rounded-md border p-3'
                ]"
            >
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-start gap-2 min-w-0">
                        <button
                            @click="task.completed_at ? reopen(task) : complete(task)"
                            :class="[task.completed_at ? 'bg-success text-white border-success' : 'border-gray-300 dark:border-gray-600 hover:border-success', 'w-5 h-5 rounded-full border-2 flex items-center justify-center shrink-0 mt-0.5']"
                            :title="task.completed_at ? 'Вернуть в работу' : 'Отметить выполненной'"
                        >
                            <i v-if="task.completed_at" class="ri-check-line text-[11px]"></i>
                        </button>
                        <div class="min-w-0">
                            <div :class="[task.completed_at ? 'line-through text-gray-500' : 'text-gray-800 dark:text-gray-200', 'text-sm font-medium']">{{ task.title }}</div>
                            <div v-if="task.description" class="text-xs text-gray-500 mt-0.5">{{ task.description }}</div>
                            <div class="flex items-center gap-2 mt-1 flex-wrap">
                                <span v-if="task.type" class="text-[11px] text-gray-400">{{ task.type }}</span>
                                <span v-if="task.due_at" :class="[task.is_overdue && !task.completed_at ? 'text-danger font-medium' : 'text-gray-400', 'text-[11px] inline-flex items-center gap-1']">
                                    <i class="ri-time-line"></i> {{ formatDue(task.due_at) }}
                                </span>
                                <span v-if="task.assigned_to" class="text-[11px] text-gray-400">{{ task.assigned_to.name }}</span>
                            </div>
                        </div>
                    </div>
                    <button @click="destroy(task)" class="w-7 h-7 rounded-full bg-danger/10 text-danger flex items-center justify-center shrink-0 hover:bg-danger hover:text-white transition-colors" title="Удалить задачу">
                        <i class="ri-delete-bin-line text-xs"></i>
                    </button>
                </div>
            </div>
        </div>

        <Modal :show="isModalOpen" @close="isModalOpen = false" max-width="md">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Новая задача</h3>
                <button @click="isModalOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-xl"></i></button>
            </div>
            <form @submit.prevent="submit" class="flex flex-col">
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                        <input v-model="taskForm.title" type="text" required placeholder="Например: Позвонить, уточнить сроки" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
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
    </div>
</template>
