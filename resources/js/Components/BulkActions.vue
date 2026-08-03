<script setup>
defineProps({
    selectedCount: {
        type: Number,
        required: true,
    },
    noun: {
        type: String,
        required: true,
    },
});

defineEmits(['export', 'delete']);
</script>

<template>
    <div class="bg-primary/10 border border-primary/20 rounded-md p-3 flex justify-between items-center transition-all">
        <div class="text-sm font-semibold text-primary flex items-center gap-2">
            <i class="ri-checkbox-multiple-line text-lg"></i>
            Выбрано {{ noun }}: {{ selectedCount }}
        </div>
        <div class="flex gap-2">
            <button @click="$emit('export')" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">
                <i class="ri-file-excel-2-line mr-1.5 text-success"></i> Экспорт в Excel
            </button>
            <button @click="$emit('delete')" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger text-white hover:bg-danger-600 shadow-sm">
                <i class="ri-delete-bin-line mr-1.5"></i> Удалить выбранные
            </button>
        </div>
    </div>
</template>