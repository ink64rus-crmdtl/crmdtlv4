<script setup>
import { useForm } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import { watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    entityType: {
        type: String,
        required: true,
    },
    availableColumns: {
        type: Array,
        default: () => [], // [{ key, label }]
    },
    visibleColumns: {
        type: Array,
        default: () => [], // текущий порядок ключей
    },
});

const emit = defineEmits(['close', 'saved']);

const form = useForm({
    entity_type: props.entityType,
    visible_columns: [],
});

// Подтягиваем актуальный список каждый раз при открытии — на случай если он менялся снаружи
watch(() => props.show, (show) => {
    if (show) {
        form.visible_columns = [...props.visibleColumns];
    }
});

const toggleColumn = (key) => {
    const index = form.visible_columns.indexOf(key);
    if (index > -1) {
        form.visible_columns.splice(index, 1);
    } else {
        form.visible_columns.push(key);
    }
};

const save = () => {
    form.post(route('list-views.store'), {
        preserveScroll: true,
        onSuccess: () => emit('saved'),
    });
};
</script>

<template>
    <div v-if="show" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
        <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                    Настройка столбцов
                </h3>
                <button @click="emit('close')" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                    <i class="ri-close-line text-xl"></i>
                </button>
            </div>
            <div class="p-6 space-y-4 max-h-[60vh] overflow-y-auto custom-scrollbar">
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Выберите столбцы для отображения и настройте их порядок.</p>

                <div class="space-y-2 mb-6">
                    <h4 class="text-xs font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider mb-2">Отображаемые столбцы</h4>
                    <draggable
                        v-model="form.visible_columns"
                        :item-key="(key) => key"
                        class="space-y-2"
                        handle=".col-drag-handle"
                    >
                        <template #item="{ element: key }">
                            <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded px-3 py-2">
                                <div class="flex items-center gap-2">
                                    <i class="ri-draggable col-drag-handle text-gray-400 cursor-grab active:cursor-grabbing"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {{ availableColumns.find(c => c.key === key)?.label || key }}
                                    </span>
                                </div>
                                <button type="button" @click="toggleColumn(key)" class="text-danger hover:text-danger/80"><i class="ri-close-circle-line text-lg"></i></button>
                            </div>
                        </template>
                    </draggable>
                    <p v-if="form.visible_columns.length === 0" class="text-xs text-gray-400 text-center py-2">Ни одного столбца не выбрано.</p>
                </div>

                <div class="space-y-2">
                    <h4 class="text-xs font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider mb-2">Доступные столбцы</h4>
                    <div class="grid grid-cols-1 gap-2">
                        <label v-for="col in availableColumns.filter(c => !form.visible_columns.includes(c.key))" :key="col.key" class="flex items-center cursor-pointer group p-2 hover:bg-gray-50 dark:hover:bg-gray-800/50 rounded border border-transparent hover:border-gray-200 dark:hover:border-gray-700 transition-colors">
                            <input type="checkbox" :checked="false" @change="toggleColumn(col.key)" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                            <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ col.label }}</span>
                        </label>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                <button type="button" @click="emit('close')" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                    Отмена
                </button>
                <button type="button" @click="save()" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                    Сохранить вид
                </button>
            </div>
        </div>
    </div>
</template>
