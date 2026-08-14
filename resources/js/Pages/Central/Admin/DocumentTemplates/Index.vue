<script setup>
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue';
import DocumentTemplateEditor from '@/Components/DocumentTemplateEditor.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    templates: { type: Array, default: () => [] },
    countries: { type: Object, default: () => ({}) },
    entityTypes: { type: Object, default: () => ({}) },
    commonPlaceholders: { type: Object, default: () => ({}) },
    entityPlaceholders: { type: Object, default: () => ({}) },
    entityTablePlaceholders: { type: Object, default: () => ({}) },
    entityConditions: { type: Object, default: () => ({}) },
});

const isModalOpen = ref(false);
const editingTemplate = ref(null);
const isFullscreen = ref(false);

// Фильтры списка — чисто клиентские (список небольшой, без пагинации, тот
// же принцип, что и у остального central-admin — см. Tenants/Index.vue).
const filterCountry = ref('');
const filterEntityType = ref('');

const filteredTemplates = computed(() => props.templates.filter((t) => {
    if (filterCountry.value === 'universal' && t.country_code !== null) return false;
    if (filterCountry.value && filterCountry.value !== 'universal' && t.country_code !== filterCountry.value) return false;
    if (filterEntityType.value && t.entity_type !== filterEntityType.value) return false;
    return true;
}));

const form = useForm({
    country_code: null,
    name: '',
    entity_type: 'work_order',
    format: 'html',
    body: '',
    source_file: null,
    is_active: true,
});

const openModal = (template = null) => {
    editingTemplate.value = template;
    isFullscreen.value = false;
    if (template) {
        form.country_code = template.country_code;
        form.name = template.name;
        form.entity_type = template.entity_type;
        form.format = template.format || 'html';
        form.body = template.body || '';
        form.source_file = null;
        form.is_active = Boolean(template.is_active);
    } else {
        form.reset();
        form.country_code = null;
        form.entity_type = 'work_order';
        form.format = 'html';
        form.is_active = true;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingTemplate.value = null;
    isFullscreen.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingTemplate.value) {
        form.transform(data => ({ ...data, _method: 'put' })).post(route('central.admin.document-templates.update', editingTemplate.value.id), { onSuccess: closeModal, forceFormData: true });
    } else {
        form.post(route('central.admin.document-templates.store'), { onSuccess: closeModal, forceFormData: true });
    }
};

const deleteTemplate = (template) => {
    if (confirm(`Удалить шаблон "${template.name}"?`)) {
        form.delete(route('central.admin.document-templates.destroy', template.id));
    }
};

const onFileSelected = (e) => {
    form.source_file = e.target.files[0] || null;
};

const currentPlaceholders = computed(() => ({
    ...props.commonPlaceholders,
    ...(props.entityPlaceholders[form.entity_type] || {}),
}));

const currentTableSection = computed(() => props.entityTablePlaceholders[form.entity_type] || null);
const currentConditions = computed(() => props.entityConditions[form.entity_type] || {});

// См. пояснение в Settings/DocumentTemplates/Index.vue — тот же приём: эта
// модалка сознательно остаётся hand-rolled div, не переведена на <Modal>.
const modalContainerClass = computed(() => isFullscreen.value
    ? 'w-full h-[calc(100vh-2rem)] my-4 mx-auto max-w-none flex flex-col'
    : 'w-full sm:max-w-5xl my-8 mx-auto flex flex-col');

const editorHeightClass = computed(() => isFullscreen.value ? 'h-[calc(100vh-380px)]' : 'h-[420px]');
</script>

<template>
    <Head title="Шаблоны документов — Admin" />

    <CentralAdminLayout>
        <template #header>Шаблоны документов</template>

        <div class="space-y-6">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6">
                <p class="text-sm text-gray-500 dark:text-gray-400">Эталонные шаблоны печатных форм по странам — тенант копирует понравившийся себе как стартовую точку (Настройки → Шаблоны документов → «Библиотека шаблонов платформы»). Дальше это независимая копия, правки здесь на уже скопированные шаблоны не влияют.</p>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 space-y-4">
                <div class="flex justify-between items-center">
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Библиотека шаблонов</h1>
                    <button @click="openModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                        <i class="ri-add-line text-base"></i> Добавить шаблон
                    </button>
                </div>
                <div class="flex items-center gap-3">
                    <select v-model="filterCountry" class="rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-3 text-sm text-gray-700 dark:text-gray-300 focus:border-primary focus:ring-0">
                        <option value="">Все страны</option>
                        <option value="universal">Общие (без страны)</option>
                        <option v-for="(country, code) in countries" :key="code" :value="code">{{ country.name }}</option>
                    </select>
                    <select v-model="filterEntityType" class="rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-3 text-sm text-gray-700 dark:text-gray-300 focus:border-primary focus:ring-0">
                        <option value="">Все сущности</option>
                        <option v-for="(label, key) in entityTypes" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <button v-if="filterCountry || filterEntityType" @click="filterCountry = ''; filterEntityType = ''" class="text-xs text-gray-400 hover:text-primary transition-colors">Сбросить</button>
                </div>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Страна</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Сущность</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Источник</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статус</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="template in filteredTemplates" :key="template.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50">{{ template.name }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <span v-if="template.country_code" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-secondary/10 text-secondary">{{ countries[template.country_code]?.name || template.country_code }}</span>
                                    <span v-else class="text-gray-400">Все страны</span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ entityTypes[template.entity_type] || template.entity_type }}</td>
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50">
                                    <span v-if="template.format === 'docx'" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-info/10 text-info"><i class="ri-file-word-2-line"></i> {{ template.source_file_name || 'Word' }}</span>
                                    <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"><i class="ri-code-line"></i> HTML</span>
                                </td>
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50">
                                    <span :class="[template.is_active ? 'bg-success/10 text-success' : 'bg-gray-100 text-gray-600', 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium']">{{ template.is_active ? 'Активен' : 'Выключен' }}</span>
                                </td>
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
                                    <button @click="openModal(template)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать">
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button @click="deleteTemplate(template)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="templates.length === 0">
                                <td colspan="6" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">Шаблонов ещё нет. Нажмите "Добавить шаблон".</td>
                            </tr>
                            <tr v-else-if="filteredTemplates.length === 0">
                                <td colspan="6" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">По выбранным фильтрам ничего не найдено.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80" :class="modalContainerClass">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center shrink-0">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingTemplate ? 'Редактирование шаблона' : 'Новый шаблон' }}</h3>
                    <div class="flex items-center gap-3">
                        <button type="button" @click="isFullscreen = !isFullscreen" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none" :title="isFullscreen ? 'Свернуть окно' : 'На весь экран'">
                            <i :class="isFullscreen ? 'ri-fullscreen-exit-line' : 'ri-fullscreen-line'" class="text-xl"></i>
                        </button>
                        <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                    </div>
                </div>
                <form @submit.prevent="submit" class="flex flex-col flex-1 min-h-0">
                    <div class="p-6 space-y-4 flex-1 flex flex-col min-h-0 overflow-y-auto">
                        <div class="grid grid-cols-4 gap-4 shrink-0">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                                <input v-model="form.name" type="text" required placeholder="Например: Акт выполненных работ" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Страна</label>
                                <select v-model="form.country_code" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option :value="null">Все страны</option>
                                    <option v-for="(country, code) in countries" :key="code" :value="code">{{ country.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сущность <span class="text-danger">*</span></label>
                                <select v-model="form.entity_type" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option v-for="(label, key) in entityTypes" :key="key" :value="key">{{ label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Источник шаблона</label>
                                <select v-model="form.format" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="html">HTML (редактор здесь)</option>
                                    <option value="docx">Загрузить Word (.docx)</option>
                                </select>
                            </div>
                        </div>

                        <DocumentTemplateEditor
                            v-model="form.body"
                            :format="form.format"
                            :placeholders="currentPlaceholders"
                            :table-section="currentTableSection"
                            :conditions="currentConditions"
                            :download-file-name="`плейсхолдеры-${form.entity_type}.txt`"
                            :class="editorHeightClass"
                        >
                            <template #docx-upload>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Файл шаблона (.docx) <span v-if="!editingTemplate" class="text-danger">*</span></label>
                                <input type="file" accept=".docx" @change="onFileSelected" class="block w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-primary/10 file:text-primary file:text-sm file:font-medium hover:file:bg-primary hover:file:text-white file:transition-colors" />
                                <p v-if="editingTemplate && editingTemplate.source_file_name" class="text-xs text-gray-400 mt-1.5">Текущий файл: {{ editingTemplate.source_file_name }}. Загрузите новый, чтобы заменить.</p>
                                <p class="text-xs text-gray-400 mt-2">Впишите плейсхолдеры (список справа) прямо в текст документа в Word — обычным текстом, без специальной разметки.</p>
                            </template>
                        </DocumentTemplateEditor>

                        <div class="flex items-center pt-2 shrink-0">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">Шаблон активен (виден тенантам в библиотеке)</label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent shrink-0">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    </CentralAdminLayout>
</template>
