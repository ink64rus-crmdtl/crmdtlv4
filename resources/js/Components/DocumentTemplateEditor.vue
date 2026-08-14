<script setup>
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { Table, TableRow, TableHeader, TableCell } from '@tiptap/extension-table';

/**
 * Общий редактор тела шаблона документа (Tiptap + HTML-код + панель
 * плейсхолдеров/условий) — извлечён из Settings/DocumentTemplates/Index.vue
 * и Central/Admin/DocumentTemplates/Index.vue (обе страницы раньше держали
 * идентичную копию этого блока). Про "полноэкранный режим" компонент
 * ничего не знает — он просто заполняет высоту корневого элемента (h-full/
 * flex-1 внутри), а родительская страница управляет высотой снаружи через
 * :class на самом компоненте (Vue attribute fallthrough на корневой div).
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    format: { type: String, default: 'html' }, // 'html' | 'docx'
    placeholders: { type: Object, default: () => ({}) }, // уже смерженные common+entity, готовые к выводу
    tableSection: { type: Object, default: null }, // { section, fields, conditions? } | null
    conditions: { type: Object, default: () => ({}) }, // условия уровня записи
    downloadFileName: { type: String, default: 'плейсхолдеры.txt' },
});

const emit = defineEmits(['update:modelValue']);

const editorMode = ref('visual'); // 'visual' | 'code'
const codeTextarea = ref(null);

const editor = useEditor({
    content: props.modelValue || '',
    extensions: [
        StarterKit,
        Table.configure({ resizable: false }),
        TableRow,
        TableHeader,
        TableCell,
    ],
    onUpdate: ({ editor: e }) => {
        emit('update:modelValue', e.getHTML());
    },
});

onBeforeUnmount(() => {
    editor.value?.destroy();
});

// Тело шаблона может смениться извне (открытие другой записи на
// редактирование) — синхронизируем визуальный редактор, только если сейчас
// в нём (в code-режиме синхронизация происходит при переключении обратно,
// см. watch(editorMode) ниже — тот же принцип, что был на исходных страницах).
watch(() => props.modelValue, (value) => {
    if (editorMode.value === 'visual' && editor.value && editor.value.getHTML() !== (value || '')) {
        editor.value.commands.setContent(value || '');
    }
});

watch(editorMode, (mode) => {
    if (mode === 'visual') {
        editor.value?.commands.setContent(props.modelValue || '');
    }
});

const currentTableConditions = computed(() => props.tableSection?.conditions || null);

const wrapTag = (key) => '{{' + key + '}}';
const conditionTag = (key) => '{{#if ' + key + '}}...{{/if}}';

const setBody = (value) => emit('update:modelValue', value);

const downloadPlaceholders = () => {
    const lines = [];
    lines.push('Плейсхолдеры шаблона документа');
    lines.push('');

    for (const [key, label] of Object.entries(props.placeholders)) {
        lines.push(`${wrapTag(key)} — ${label}`);
    }

    if (props.tableSection) {
        lines.push('');
        lines.push('Таблица позиций (строка таблицы клонируется на каждую позицию заказа):');
        for (const [key, label] of Object.entries(props.tableSection.fields || {})) {
            lines.push(`${wrapTag(key)} — ${label}`);
        }
        if (currentTableConditions.value) {
            lines.push('');
            lines.push('Условия строки (оборачивают текст в {{#if ключ}}...{{/if}} — печатается, только если условие верно):');
            for (const [key, label] of Object.entries(currentTableConditions.value)) {
                lines.push(`{{#if ${key}}}...{{/if}} — ${label}`);
            }
        }
    }

    if (Object.keys(props.conditions).length) {
        lines.push('');
        lines.push('Условия (оборачивают текст в {{#if ключ}}...{{/if}} — печатается, только если условие верно):');
        for (const [key, label] of Object.entries(props.conditions)) {
            lines.push(`{{#if ${key}}}...{{/if}} — ${label}`);
        }
    }

    const blob = new Blob([lines.join('\n')], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = props.downloadFileName;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
};

const insertPlaceholder = (key) => {
    const tag = '{{' + key + '}}';
    if (props.format === 'docx') {
        // Нет редактора для вставки — плейсхолдеры копируются руками в Word.
        navigator.clipboard?.writeText(tag);
        return;
    }
    if (editorMode.value === 'visual') {
        editor.value?.chain().focus().insertContent(tag).run();
        return;
    }
    const el = codeTextarea.value;
    if (!el) { setBody((props.modelValue || '') + tag); return; }
    const start = el.selectionStart ?? (props.modelValue || '').length;
    const end = el.selectionEnd ?? (props.modelValue || '').length;
    const body = props.modelValue || '';
    setBody(body.slice(0, start) + tag + body.slice(end));
    setTimeout(() => { el.focus(); el.selectionStart = el.selectionEnd = start + tag.length; }, 0);
};

const insertTable = () => {
    editor.value?.chain().focus().insertTable({ rows: 2, cols: 3, withHeaderRow: true }).run();
};

// Оборачивает ВЫДЕЛЕННЫЙ текст в {{#if key}}...{{/if}} — если ничего не
// выделено, вставляет пустую пару с курсором между маркерами.
const insertCondition = (key) => {
    const openTag = '{{#if ' + key + '}}';
    const closeTag = '{{/if}}';

    if (props.format === 'docx') {
        navigator.clipboard?.writeText(openTag + closeTag);
        return;
    }

    if (editorMode.value === 'visual') {
        const sel = editor.value?.state?.selection;
        if (sel && !sel.empty) {
            const text = editor.value.state.doc.textBetween(sel.from, sel.to);
            editor.value.chain().focus().insertContentAt({ from: sel.from, to: sel.to }, openTag + text + closeTag).run();
        } else {
            editor.value?.chain().focus().insertContent(openTag + closeTag).run();
        }
        return;
    }

    const el = codeTextarea.value;
    const body = props.modelValue || '';
    if (!el) { setBody(body + openTag + closeTag); return; }
    const start = el.selectionStart ?? body.length;
    const end = el.selectionEnd ?? body.length;
    const selected = body.slice(start, end);
    setBody(body.slice(0, start) + openTag + selected + closeTag + body.slice(end));
    setTimeout(() => {
        el.focus();
        el.selectionStart = start + openTag.length;
        el.selectionEnd = start + openTag.length + selected.length;
    }, 0);
};
</script>

<template>
    <div class="grid grid-cols-3 gap-4 h-full">
        <div class="col-span-2 flex flex-col gap-2 min-h-0 h-full">
            <template v-if="format === 'html'">
                <div class="flex items-center justify-between shrink-0">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Тело документа <span class="text-danger">*</span></label>
                    <div class="flex rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden text-xs">
                        <button type="button" @click="editorMode = 'visual'" :class="[editorMode === 'visual' ? 'bg-primary text-white' : 'bg-white dark:bg-transparent text-gray-600 dark:text-gray-300', 'px-3 py-1.5 transition-colors']">Визуальный редактор</button>
                        <button type="button" @click="editorMode = 'code'" :class="[editorMode === 'code' ? 'bg-primary text-white' : 'bg-white dark:bg-transparent text-gray-600 dark:text-gray-300', 'px-3 py-1.5 transition-colors']">HTML-код</button>
                    </div>
                </div>

                <div v-show="editorMode === 'visual'" class="border border-gray-200 dark:border-gray-700 rounded-md overflow-hidden flex flex-col flex-1 min-h-0">
                    <div class="flex flex-wrap gap-1 p-2 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50 shrink-0">
                        <button type="button" @click="editor?.chain().focus().toggleBold().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('bold') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Жирный"><i class="ri-bold"></i></button>
                        <button type="button" @click="editor?.chain().focus().toggleItalic().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('italic') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Курсив"><i class="ri-italic"></i></button>
                        <button type="button" @click="editor?.chain().focus().toggleUnderline().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('underline') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Подчёркнутый"><i class="ri-underline"></i></button>
                        <button type="button" @click="editor?.chain().focus().toggleStrike().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('strike') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Зачёркнутый"><i class="ri-strikethrough"></i></button>
                        <span class="w-px bg-gray-200 dark:bg-gray-700 mx-1"></span>
                        <button type="button" @click="editor?.chain().focus().toggleHeading({ level: 1 }).run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('heading', { level: 1 }) ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Заголовок 1"><i class="ri-h-1"></i></button>
                        <button type="button" @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('heading', { level: 2 }) ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Заголовок 2"><i class="ri-h-2"></i></button>
                        <button type="button" @click="editor?.chain().focus().toggleHeading({ level: 3 }).run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('heading', { level: 3 }) ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Заголовок 3"><i class="ri-h-3"></i></button>
                        <span class="w-px bg-gray-200 dark:bg-gray-700 mx-1"></span>
                        <button type="button" @click="editor?.chain().focus().toggleBulletList().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('bulletList') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Список"><i class="ri-list-unordered"></i></button>
                        <button type="button" @click="editor?.chain().focus().toggleOrderedList().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('orderedList') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Нумерованный список"><i class="ri-list-ordered"></i></button>
                        <button type="button" @click="editor?.chain().focus().toggleBlockquote().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('blockquote') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Цитата"><i class="ri-double-quotes-l"></i></button>
                        <button type="button" @click="editor?.chain().focus().toggleCodeBlock().run()" :class="['w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700', editor?.isActive('codeBlock') ? 'bg-gray-200 dark:bg-gray-700 text-primary' : 'text-gray-600 dark:text-gray-300']" title="Блок кода"><i class="ri-code-box-line"></i></button>
                        <button type="button" @click="editor?.chain().focus().setHorizontalRule().run()" class="w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300" title="Горизонтальная линия"><i class="ri-separator"></i></button>
                        <span class="w-px bg-gray-200 dark:bg-gray-700 mx-1"></span>
                        <button type="button" @click="insertTable" class="w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300" title="Вставить таблицу"><i class="ri-table-2"></i></button>
                        <span class="w-px bg-gray-200 dark:bg-gray-700 mx-1"></span>
                        <button type="button" @click="editor?.chain().focus().undo().run()" class="w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300" title="Отменить"><i class="ri-arrow-go-back-line"></i></button>
                        <button type="button" @click="editor?.chain().focus().redo().run()" class="w-8 h-8 rounded flex items-center justify-center text-sm hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-300" title="Повторить"><i class="ri-arrow-go-forward-line"></i></button>
                    </div>
                    <EditorContent :editor="editor" class="prose prose-sm dark:prose-invert max-w-none p-3 flex-1 min-h-[280px] overflow-y-auto custom-scrollbar text-gray-800 dark:text-gray-200 [&_table]:border [&_table]:border-collapse [&_td]:border [&_td]:border-gray-300 [&_td]:p-1.5 [&_th]:border [&_th]:border-gray-300 [&_th]:p-1.5" />
                </div>
                <textarea
                    v-show="editorMode === 'code'"
                    ref="codeTextarea"
                    :value="modelValue"
                    @input="setBody($event.target.value)"
                    required
                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 font-mono flex-1 min-h-[280px]"
                    placeholder="<h2>Акт №___</h2>..."
                ></textarea>
            </template>

            <template v-else>
                <slot name="docx-upload" />
            </template>
        </div>

        <div class="border border-gray-200 dark:border-gray-700 rounded-md overflow-hidden flex flex-col h-full">
            <div class="px-3 py-2 bg-gray-50/50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center justify-between gap-2 shrink-0">
                <span>Плейсхолдеры {{ format === 'docx' ? '(скопировать в Word)' : '(клик — вставить)' }}</span>
                <button
                    type="button"
                    @click="downloadPlaceholders"
                    class="shrink-0 text-gray-400 hover:text-primary transition-colors normal-case font-normal"
                    title="Скачать список плейсхолдеров с пояснениями (.txt)"
                >
                    <i class="ri-download-2-line"></i>
                </button>
            </div>
            <div class="overflow-y-auto custom-scrollbar p-2 space-y-1 flex-1 min-h-0">
                <button
                    v-for="(label, key) in placeholders"
                    :key="key"
                    type="button"
                    @click="insertPlaceholder(key)"
                    class="w-full text-left px-2 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
                >
                    <code class="text-xs text-primary block">{{ wrapTag(key) }}</code>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ label }}</span>
                </button>

                <template v-if="tableSection">
                    <div class="px-2 pt-2 pb-1 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-t border-gray-200 dark:border-gray-700 mt-1">Таблица позиций</div>
                    <button
                        v-for="(label, key) in tableSection.fields"
                        :key="key"
                        type="button"
                        @click="insertPlaceholder(key)"
                        class="w-full text-left px-2 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
                    >
                        <code class="text-xs text-primary block">{{ wrapTag(key) }}</code>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ label }}</span>
                    </button>

                    <template v-if="currentTableConditions">
                        <div class="px-2 pt-2 pb-1 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-t border-gray-200 dark:border-gray-700 mt-1">Условия строки</div>
                        <button
                            v-for="(label, key) in currentTableConditions"
                            :key="key"
                            type="button"
                            @click="insertCondition(key)"
                            :title="format === 'html' ? 'Оборачивает выделенный текст (или вставляет пустую пару, если ничего не выделено)' : null"
                            class="w-full text-left px-2 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
                        >
                            <code class="text-xs text-info block">{{ conditionTag(key) }}</code>
                            <span class="text-xs text-gray-500 dark:text-gray-400">{{ label }}</span>
                        </button>
                    </template>
                </template>

                <template v-if="Object.keys(conditions).length">
                    <div class="px-2 pt-2 pb-1 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-t border-gray-200 dark:border-gray-700 mt-1">Условия</div>
                    <button
                        v-for="(label, key) in conditions"
                        :key="key"
                        type="button"
                        @click="insertCondition(key)"
                        :title="format === 'html' ? 'Оборачивает выделенный текст (или вставляет пустую пару, если ничего не выделено)' : null"
                        class="w-full text-left px-2 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-800/40 transition-colors"
                    >
                        <code class="text-xs text-info block">{{ conditionTag(key) }}</code>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ label }}</span>
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>
