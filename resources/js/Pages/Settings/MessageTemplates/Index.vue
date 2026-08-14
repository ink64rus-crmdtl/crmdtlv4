<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useClientSort } from '@/Composables/useClientSort.js';

const props = defineProps({
    templates: { type: Array, default: () => [] },
    triggers: { type: Object, default: () => ({}) },
});

const localizedBody = (template) => {
    const body = template.body;
    if (!body) return '';
    if (typeof body === 'string') return body;
    return body['ru'] || body['en'] || Object.values(body)[0] || '';
};

// text (JSON-переводимое тело) — не сортируется.
const messageTemplateColumns = [
    { key: 'name', label: 'Название', sortable: true },
    { key: 'trigger', label: 'Триггер', sortable: true, sortKey: 'event_trigger' },
    { key: 'text', label: 'Текст' },
    { key: 'status', label: 'Статус', sortable: true, sortKey: 'is_active' },
];

const { sort, onSort, sortedRows: sortedTemplates } = useClientSort(() => props.templates);

const isModalOpen = ref(false);
const editingTemplate = ref(null);

const form = useForm({
    name: '',
    event_trigger: '',
    body: '',
    is_active: true,
});

const openModal = (template = null) => {
    editingTemplate.value = template;
    if (template) {
        form.name = template.name;
        form.event_trigger = template.event_trigger ?? '';
        form.body = localizedBody(template);
        form.is_active = Boolean(template.is_active);
    } else {
        form.reset();
        form.is_active = true;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingTemplate.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingTemplate.value) {
        form.put(route('settings.message-templates.update', editingTemplate.value.id), { onSuccess: closeModal });
    } else {
        form.post(route('settings.message-templates.store'), { onSuccess: closeModal });
    }
};

const deleteTemplate = (template) => {
    if (confirm(`Удалить шаблон "${template.name}"?`)) {
        form.delete(route('settings.message-templates.destroy', template.id));
    }
};
</script>

<template>
    <Head title="Шаблоны сообщений" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            <SettingsNav />

            <PageHelper title="Как это устроено">
                <p>Шаблон без триггера можно использовать только вручную (в будущем — как быструю заготовку в чате). Шаблон с триггером отправляется автоматически при наступлении события — только один активный шаблон на триггер учитывается.</p>
                <p v-pre>Доступные плейсхолдеры зависят от триггера: <code>{{client.name}}</code> — везде; <code>{{work_order.id}}</code>, <code>{{work_order.final_amount}}</code> — для «Заказ готов»; <code>{{appointment.start_at}}</code>, <code>{{branch.name}}</code> — для «Напоминание о записи».</p>
            </PageHelper>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Шаблоны сообщений</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Автоматические уведомления клиентам через подключённые каналы</p>
                </div>
                <button @click="openModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                    <i class="ri-add-line text-base"></i> Добавить шаблон
                </button>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="messageTemplateColumns"
                        :rows="sortedTemplates"
                        has-actions
                        :sort="sort"
                        @sort="onSort"
                        empty-message='Шаблонов ещё нет. Нажмите "Добавить шаблон".'
                    >
                        <template #cell-name="{ row: template }">{{ template.name }}</template>
                        <template #cell-trigger="{ row: template }">
                            <span v-if="template.event_trigger" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-primary/10 text-primary">{{ triggers[template.event_trigger] || template.event_trigger }}</span>
                            <span v-else class="text-gray-400">Вручную</span>
                        </template>
                        <template #cell-text="{ row: template }">
                            <span class="block max-w-md truncate" :title="localizedBody(template)">{{ localizedBody(template) }}</span>
                        </template>
                        <template #cell-status="{ row: template }">
                            <span :class="[template.is_active ? 'bg-success/10 text-success' : 'bg-gray-100 text-gray-600', 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium']">{{ template.is_active ? 'Активен' : 'Выключен' }}</span>
                        </template>
                        <template #actions="{ row: template }">
                            <button @click="openModal(template)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать">
                                <i class="ri-pencil-line"></i>
                            </button>
                            <button @click="deleteTemplate(template)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>

        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingTemplate ? 'Редактирование шаблона' : 'Новый шаблон' }}</h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                            <input v-model="form.name" type="text" required placeholder="Например: Заказ готов" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Триггер</label>
                            <select v-model="form.event_trigger" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="">Без автотриггера (вручную)</option>
                                <option v-for="(label, key) in triggers" :key="key" :value="key">{{ label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Текст сообщения <span class="text-danger">*</span></label>
                            <textarea v-model="form.body" required rows="4" maxlength="4096" placeholder="Здравствуйте, {{client.name}}! Ваш заказ №{{work_order.id}} готов." class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 resize-none"></textarea>
                        </div>
                        <div class="flex items-center pt-2">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">Шаблон активен</label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
