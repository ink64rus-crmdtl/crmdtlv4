<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { Table, TableRow, TableHeader, TableCell } from '@tiptap/extension-table';

const props = defineProps({
    templates: { type: Array, default: () => [] },
    entityTypes: { type: Object, default: () => ({}) },
    commonPlaceholders: { type: Object, default: () => ({}) },
    entityPlaceholders: { type: Object, default: () => ({}) },
    entityTablePlaceholders: { type: Object, default: () => ({}) },
});

const isModalOpen = ref(false);
const editingTemplate = ref(null);
const editorMode = ref('visual'); // 'visual' | 'code'
const codeTextarea = ref(null);

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

const editor = useEditor({
    content: '',
    extensions: [
        StarterKit,
        Table.configure({ resizable: false }),
        TableRow,
        TableHeader,
        TableCell,
    ],
    onUpdate: ({ editor: e }) => {
        form.body = e.getHTML();
    },
});

onBeforeUnmount(() => {
    editor.value?.destroy();
});

const openModal = (template = null) => {
    editingTemplate.value = template;
    editorMode.value = 'visual';
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
    setTimeout(() => editor.value?.commands.setContent(form.body || ''), 0);
};

const closeModal = () => {
    isModalOpen.value = false;
    editingTemplate.value = null;
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

// Переключение "Визуальный редактор" ⇄ "HTML-код" — form.body остаётся
// единственным источником истины, редактор просто синхронизируется при входе в визуальный режим.
watch(editorMode, (mode) => {
    if (mode === 'visual') {
        editor.value?.commands.setContent(form.body || '');
    }
});

const currentPlaceholders = computed(() => ({
    ...props.commonPlaceholders,
    ...(props.entityPlaceholders[form.entity_type] || {}),
}));

const currentTableSection = computed(() => props.entityTablePlaceholders[form.entity_type] || null);

const wrapTag = (key) => '{{' + key + '}}';

// Полный список плейсхолдеров текущего типа сущности (общие + специфичные +
// таблица позиций, если есть) — тот же набор, что уже отрисован справа,
// просто в виде текстового файла для копирования в Word/памятки менеджеру.
const downloadPlaceholders = () => {
    const lines = [];
    const entityLabel = props.entityTypes[form.entity_type] || form.entity_type;
    lines.push(`Плейсхолдеры для шаблона документа: ${entityLabel}`);
    lines.push('');

    for (const [key, label] of Object.entries(currentPlaceholders.value)) {
        lines.push(`${wrapTag(key)} — ${label}`);
    }

    if (currentTableSection.value) {
        lines.push('');
        lines.push('Таблица позиций (строка таблицы клонируется на каждую позицию заказа):');
        for (const [key, label] of Object.entries(currentTableSection.value.fields)) {
            lines.push(`${wrapTag(key)} — ${label}`);
        }
    }

    const blob = new Blob([lines.join('\n')], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `плейсхолдеры-${form.entity_type}.txt`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
};

const insertPlaceholder = (key) => {
    const tag = '{{' + key + '}}';
    if (form.format === 'docx') {
        // Нет редактора для вставки — плейсхолдеры копируются руками в Word.
        navigator.clipboard?.writeText(tag);
        return;
    }
    if (editorMode.value === 'visual') {
        editor.value?.chain().focus().insertContent(tag).run();
        return;
    }
    const el = codeTextarea.value;
    if (!el) { form.body += tag; return; }
    const start = el.selectionStart ?? form.body.length;
    const end = el.selectionEnd ?? form.body.length;
    form.body = form.body.slice(0, start) + tag + form.body.slice(end);
    setTimeout(() => { el.focus(); el.selectionStart = el.selectionEnd = start + tag.length; }, 0);
};

const insertTable = () => {
    editor.value?.chain().focus().insertTable({ rows: 2, cols: 3, withHeaderRow: true }).run();
};
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
                <p class="text-xs text-gray-400 mt-2">Номер документа выдаётся автоматически при генерации (префикс + порядковый номер, при необходимости — с годом).</p>
            </PageHelper>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Шаблоны документов</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Печатные формы для заказов, транзакций, клиентов</p>
                </div>
                <button @click="openModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                    <i class="ri-add-line text-base"></i> Добавить шаблон
                </button>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Сущность</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Источник</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Нумерация</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статус</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="template in templates" :key="template.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50">{{ template.name }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ entityTypes[template.entity_type] || template.entity_type }}</td>
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50">
                                    <span v-if="template.format === 'docx'" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-info/10 text-info"><i class="ri-file-word-2-line"></i> {{ template.source_file_name || 'Word' }}</span>
                                    <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300"><i class="ri-code-line"></i> HTML</span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ template.number_prefix || '—' }}{{ template.number_reset_yearly ? ' / год' : '' }}</td>
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
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Модалка ~1.5× шире прежней (было max-w-2xl) — редактору с панелью плейсхолдеров тесно не помещалось -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-5xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingTemplate ? 'Редактирование шаблона' : 'Новый шаблон' }}</h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div class="grid grid-cols-3 gap-4">
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

                        <div class="grid grid-cols-2 gap-4">
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

                        <!-- Редактор (слева) + плейсхолдеры с пояснениями (справа) -->
                        <div class="grid grid-cols-3 gap-4">
                            <div class="col-span-2 space-y-2">
                                <template v-if="form.format === 'html'">
                                    <div class="flex items-center justify-between">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Тело документа <span class="text-danger">*</span></label>
                                        <div class="flex rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden text-xs">
                                            <button type="button" @click="editorMode = 'visual'" :class="[editorMode === 'visual' ? 'bg-primary text-white' : 'bg-white dark:bg-transparent text-gray-600 dark:text-gray-300', 'px-3 py-1.5 transition-colors']">Визуальный редактор</button>
                                            <button type="button" @click="editorMode = 'code'" :class="[editorMode === 'code' ? 'bg-primary text-white' : 'bg-white dark:bg-transparent text-gray-600 dark:text-gray-300', 'px-3 py-1.5 transition-colors']">HTML-код</button>
                                        </div>
                                    </div>

                                    <div v-show="editorMode === 'visual'" class="border border-gray-200 dark:border-gray-700 rounded-md overflow-hidden">
                                        <div class="flex flex-wrap gap-1 p-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50">
                                            <button type="button" @click="editor?.chain().focus().toggleBold().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('bold') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Жирный"><i class="ri-bold"></i></button>
                                            <button type="button" @click="editor?.chain().focus().toggleItalic().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('italic') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Курсив"><i class="ri-italic"></i></button>
                                            <button type="button" @click="editor?.chain().focus().toggleStrike().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('strike') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Зачёркнутый"><i class="ri-strikethrough"></i></button>
                                            <span class="w-px bg-gray-200 dark:bg-gray-700 mx-1"></span>
                                            <button type="button" @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('heading', { level: 2 }) ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Заголовок"><i class="ri-h-2"></i></button>
                                            <button type="button" @click="editor?.chain().focus().toggleBulletList().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('bulletList') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Список"><i class="ri-list-unordered"></i></button>
                                            <button type="button" @click="editor?.chain().focus().toggleOrderedList().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('orderedList') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Нумерованный список"><i class="ri-list-ordered"></i></button>
                                            <span class="w-px bg-gray-200 dark:bg-gray-700 mx-1"></span>
                                            <button type="button" @click="insertTable" class="w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300" title="Вставить таблицу"><i class="ri-table-2"></i></button>
                                            <span class="w-px bg-gray-200 dark:bg-gray-700 mx-1"></span>
                                            <button type="button" @click="editor?.chain().focus().undo().run()" class="w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300" title="Отменить"><i class="ri-arrow-go-back-line"></i></button>
                                            <button type="button" @click="editor?.chain().focus().redo().run()" class="w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300" title="Повторить"><i class="ri-arrow-go-forward-line"></i></button>
                                        </div>
                                        <EditorContent :editor="editor" class="prose prose-sm dark:prose-invert max-w-none p-3 min-h-[280px] max-h-[280px] overflow-y-auto custom-scrollbar text-gray-800 dark:text-gray-200 [&_table]:border [&_table]:border-collapse [&_td]:border [&_td]:border-gray-300 [&_td]:p-1.5 [&_th]:border [&_th]:border-gray-300 [&_th]:p-1.5" />
                                    </div>
                                    <textarea v-show="editorMode === 'code'" ref="codeTextarea" v-model="form.body" required rows="13" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 font-mono" placeholder="<h2>Акт №___</h2>..."></textarea>
                                </template>

                                <template v-else>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Файл шаблона (.docx) <span v-if="!editingTemplate" class="text-danger">*</span></label>
                                    <input type="file" accept=".docx" @change="onFileSelected" class="block w-full text-sm text-gray-600 dark:text-gray-300 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-primary/10 file:text-primary file:text-sm file:font-medium hover:file:bg-primary hover:file:text-white file:transition-colors" />
                                    <p v-if="editingTemplate && editingTemplate.source_file_name" class="text-xs text-gray-400 mt-1.5">Текущий файл: {{ editingTemplate.source_file_name }}. Загрузите новый, чтобы заменить.</p>
                                    <p class="text-xs text-gray-400 mt-2">Впишите плейсхолдеры (список справа) прямо в текст документа в Word — обычным текстом, без специальной разметки. Для таблицы позиций — одна строка данных с плейсхолдерами вида <code v-pre>{{item.name}}</code> в ячейках, система найдёт её сама.</p>
                                </template>
                            </div>

                            <!-- Плейсхолдеры с пояснениями — рядом с редактором, не сверху -->
                            <div class="border border-gray-200 dark:border-gray-700 rounded-md overflow-hidden flex flex-col max-h-[360px]">
                                <div class="px-3 py-2 bg-gray-50/50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center justify-between gap-2">
                                    <span>Плейсхолдеры {{ form.format === 'docx' ? '(скопировать в Word)' : '(клик — вставить)' }}</span>
                                    <button
                                        type="button"
                                        @click="downloadPlaceholders"
                                        class="shrink-0 text-gray-400 hover:text-primary transition-colors normal-case font-normal"
                                        title="Скачать список плейсхолдеров с пояснениями (.txt)"
                                    >
                                        <i class="ri-download-2-line"></i>
                                    </button>
                                </div>
                                <div class="overflow-y-auto custom-scrollbar p-2 space-y-1 flex-1">
                                    <button
                                        v-for="(label, key) in currentPlaceholders"
                                        :key="key"
                                        type="button"
                                        @click="insertPlaceholder(key)"
                                        class="w-full text-left px-2 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
                                    >
                                        <code class="text-xs text-primary block">{{ wrapTag(key) }}</code>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ label }}</span>
                                    </button>

                                    <template v-if="currentTableSection">
                                        <div class="px-2 pt-2 pb-1 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-t border-gray-200 dark:border-gray-700 mt-1">Таблица позиций</div>
                                        <button
                                            v-for="(label, key) in currentTableSection.fields"
                                            :key="key"
                                            type="button"
                                            @click="insertPlaceholder(key)"
                                            class="w-full text-left px-2 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
                                        >
                                            <code class="text-xs text-primary block">{{ wrapTag(key) }}</code>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ label }}</span>
                                        </button>
                                    </template>
                                </div>
                            </div>
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
