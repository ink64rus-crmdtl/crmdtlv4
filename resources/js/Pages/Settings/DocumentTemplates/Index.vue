<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DocumentTemplateEditor from '@/Components/DocumentTemplateEditor.vue';
import Modal from '@/Components/Modal.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import { useClientSort } from '@/Composables/useClientSort.js';
import axios from 'axios';

const props = defineProps({
    templates: { type: Array, default: () => [] },
    entityTypes: { type: Object, default: () => ({}) },
    commonPlaceholders: { type: Object, default: () => ({}) },
    entityPlaceholders: { type: Object, default: () => ({}) },
    entityTablePlaceholders: { type: Object, default: () => ({}) },
    entityConditions: { type: Object, default: () => ({}) },
});

const isModalOpen = ref(false);
const editingTemplate = ref(null);
const isFullscreen = ref(false);

const form = useForm({
    name: '',
    entity_type: 'work_order',
    format: 'html',
    body: '',
    source_file: null,
    number_prefix: '',
    number_reset_yearly: true,
    is_active: true,
});

const openModal = (template = null) => {
    editingTemplate.value = template;
    isFullscreen.value = false;
    if (template) {
        form.name = template.name;
        form.entity_type = template.entity_type;
        form.format = template.format || 'html';
        form.body = template.body || '';
        form.source_file = null;
        form.number_prefix = template.number_prefix ?? '';
        form.number_reset_yearly = Boolean(template.number_reset_yearly);
        form.is_active = Boolean(template.is_active);
    } else {
        form.reset();
        form.entity_type = 'work_order';
        form.format = 'html';
        form.number_reset_yearly = true;
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
        form.transform(data => ({ ...data, _method: 'put' })).post(route('settings.document-templates.update', editingTemplate.value.id), { onSuccess: closeModal, forceFormData: true });
    } else {
        form.post(route('settings.document-templates.store'), { onSuccess: closeModal, forceFormData: true });
    }
};

const deleteTemplate = (template) => {
    if (confirm(`Удалить шаблон "${template.name}"?`)) {
        form.delete(route('settings.document-templates.destroy', template.id));
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

// Модалка редактирования — hand-rolled div (не <Modal>), сознательно не
// переведена на компонент в этой доработке: не вложена в другую модалку
// (единственная причина строгого правила про <dialog>/top-layer), расширение
// Modal.vue под полноэкранный режим задело бы каждое его текущее
// использование в проекте — риск непропорционален пользе именно здесь.
const modalContainerClass = computed(() => isFullscreen.value
    ? 'w-full h-[calc(100vh-2rem)] my-4 mx-auto max-w-none flex flex-col'
    : 'w-full sm:max-w-5xl my-8 mx-auto flex flex-col');

// DocumentTemplateEditor сам не знает про полноэкранный режим — просто
// заполняет высоту своего корневого элемента (h-full/flex-1 внутри), а эта
// страница управляет высотой снаружи через :class на самом компоненте.
const editorHeightClass = computed(() => isFullscreen.value ? 'h-[calc(100vh-380px)]' : 'h-[420px]');

// Библиотека шаблонов платформы (App\Services\Documents\
// PlatformDocumentTemplateService) — эталоны по странам, заводит
// администратор платформы в /admin/document-templates. Импорт — снепшот
// (копия), не живая ссылка: дальше это обычный тенантский шаблон.
const isLibraryModalOpen = ref(false);
const libraryLoading = ref(false);
const libraryTemplates = ref([]);
const importingId = ref(null);

const openLibrary = async () => {
    isLibraryModalOpen.value = true;
    libraryLoading.value = true;
    try {
        const { data } = await axios.get(route('settings.document-templates.library'));
        libraryTemplates.value = data.templates;
    } finally {
        libraryLoading.value = false;
    }
};

const closeLibrary = () => {
    isLibraryModalOpen.value = false;
};

const importTemplate = (template) => {
    importingId.value = template.id;
    router.post(route('settings.document-templates.import'), { platform_document_template_id: template.id }, {
        preserveScroll: true,
        onSuccess: closeLibrary,
        onFinish: () => { importingId.value = null; },
    });
};

// numbering — составной (number_prefix + number_reset_yearly) — не сортируется.
const templateColumns = [
    { key: 'name', label: 'Название', sortable: true },
    { key: 'entity_type', label: 'Сущность', sortable: true },
    { key: 'source', label: 'Источник', sortable: true, sortKey: 'format' },
    { key: 'numbering', label: 'Нумерация' },
    { key: 'status', label: 'Статус', sortable: true, sortKey: 'is_active' },
];

const { sort, onSort, sortedRows: sortedTemplates } = useClientSort(() => props.templates);
</script>

<template>
    <Head title="Шаблоны документов" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            <SettingsNav />

            <PageHelper title="Как это устроено">
                <p>Шаблон — печатная форма (акт, счёт, договор) с плейсхолдерами <code v-pre>{{ключ}}</code>. Можно написать HTML прямо в СРМ (визуальный редактор или код), либо подготовить документ офлайн в Word и просто загрузить готовый <code>.docx</code>-файл — плейсхолдеры печатаются как обычный текст, система сама подставит значения при формировании документа.</p>
                <p>Для таблицы позиций — создайте в редакторе (или в Word) таблицу с одной строкой данных, где в ячейках — <code v-pre>{{item.name}}</code>, <code v-pre>{{item.quantity}}</code> и т.п.: система сама найдёт эту строку по плейсхолдерам и размножит её по числу позиций, без специальной разметки.</p>
                <p>Условия (раздел «Условия» в списке справа) — оборачивают кусок текста в <code v-pre>{{#if ключ}}...{{/if}}</code>: этот кусок напечатается, только если условие верно (например «НДС включён в цену»), иначе исчезнет целиком. Так один шаблон одинаково корректно печатается для разных состояний записи (с НДС/без НДС и т.п.).</p>
                <p class="text-xs text-gray-400 mt-2">Номер документа выдаётся автоматически при генерации (префикс + порядковый номер, при необходимости — с годом). Кнопка в правом верхнем углу редактора разворачивает его на весь экран.</p>
            </PageHelper>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Шаблоны документов</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Печатные формы для заказов, транзакций, клиентов</p>
                </div>
                <div class="flex items-center gap-2">
                    <button @click="openLibrary()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-info/10 text-info hover:bg-info hover:text-white gap-1.5">
                        <i class="ri-book-2-line text-base"></i> Библиотека шаблонов платформы
                    </button>
                    <button @click="openModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                        <i class="ri-add-line text-base"></i> Добавить шаблон
                    </button>
                </div>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="templateColumns"
                        :rows="sortedTemplates"
                        has-actions
                        :sort="sort"
                        @sort="onSort"
                        empty-message='Шаблонов ещё нет. Нажмите "Добавить шаблон".'
                    >
                        <template #cell-name="{ row: template }">{{ template.name }}</template>
                        <template #cell-entity_type="{ row: template }">{{ entityTypes[template.entity_type] || template.entity_type }}</template>
                        <template #cell-source="{ row: template }">
                            <span v-if="template.format === 'docx'" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-info/10 text-info"><i class="ri-file-word-2-line"></i> {{ template.source_file_name || 'Word' }}</span>
                            <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"><i class="ri-code-line"></i> HTML</span>
                        </template>
                        <template #cell-numbering="{ row: template }">{{ template.number_prefix || '—' }}{{ template.number_reset_yearly ? ' / год' : '' }}</template>
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
                        <div class="grid grid-cols-3 gap-4 shrink-0">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                                <input v-model="form.name" type="text" required placeholder="Например: Акт выполненных работ" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
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
                                    <option value="html">HTML (редактор в СРМ)</option>
                                    <option value="docx">Загрузить Word (.docx)</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 shrink-0">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Префикс номера</label>
                                <input v-model="form.number_prefix" type="text" placeholder="АКТ-" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                            <div class="flex items-center pt-6">
                                <div @click="form.number_reset_yearly = !form.number_reset_yearly" :class="[form.number_reset_yearly ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                    <div :class="[form.number_reset_yearly ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.number_reset_yearly = !form.number_reset_yearly">Сбрасывать нумерацию каждый год</label>
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
                                <p class="text-xs text-gray-400 mt-2">Впишите плейсхолдеры (список справа) прямо в текст документа в Word — обычным текстом, без специальной разметки. Для таблицы позиций — одна строка данных с плейсхолдерами вида <code v-pre>{{item.name}}</code> в ячейках, система найдёт её сама.</p>
                            </template>
                        </DocumentTemplateEditor>

                        <div class="flex items-center pt-2 shrink-0">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">Шаблон активен</label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent shrink-0">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

        <Modal :show="isLibraryModalOpen" max-width="2xl" @close="closeLibrary">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Библиотека шаблонов платформы</h3>
                    <button @click="closeLibrary" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <p class="text-xs text-gray-400 mb-4">Эталонные шаблоны под вашу страну (и общие для всех стран). Импорт создаёт независимую копию — дальше её можно редактировать как обычный шаблон.</p>

                <div v-if="libraryLoading" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">Загрузка…</div>
                <div v-else-if="libraryTemplates.length === 0" class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">Для вашей страны пока нет шаблонов в библиотеке платформы.</div>
                <div v-else class="space-y-2 max-h-[420px] overflow-y-auto custom-scrollbar">
                    <div v-for="template in libraryTemplates" :key="template.id" class="flex items-center justify-between p-3 rounded-md border border-gray-200 dark:border-gray-700">
                        <div>
                            <div class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ template.name }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 flex items-center gap-2">
                                <span>{{ entityTypes[template.entity_type] || template.entity_type }}</span>
                                <span v-if="template.country_code" class="inline-flex items-center px-1.5 py-0.5 rounded bg-secondary/10 text-secondary">{{ template.country_code }}</span>
                                <span v-else class="text-gray-400">Все страны</span>
                            </div>
                        </div>
                        <button
                            @click="importTemplate(template)"
                            :disabled="importingId === template.id"
                            class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white disabled:opacity-50 gap-1"
                        >
                            <i class="ri-download-2-line"></i> Импортировать
                        </button>
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
