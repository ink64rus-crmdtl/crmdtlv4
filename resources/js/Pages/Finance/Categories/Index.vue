<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import FinanceNav from '@/Components/FinanceNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import ColumnSettingsModal from '@/Components/ColumnSettingsModal.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import axios from 'axios';

const props = defineProps({
    categories: Object,
    filters: Object,
    availableColumns: { type: Array, default: () => [] },
    listView: { type: Object, default: () => ({ visible_columns: [] }) },
});

const isModalOpen = ref(false);
const editingCategory = ref(null);
const isColumnsModalOpen = ref(false);

const activeColumns = computed(() => {
    return props.listView.visible_columns
        .map(key => props.availableColumns.find(c => c.key === key))
        .filter(Boolean);
});
// name — переводимый JSON (spatie/laravel-translatable), сортировка по сырому
// значению дала бы бессмысленный порядок — не sortable.
const SORTABLE_COLUMN_KEYS = ['type', 'status'];
const SORT_KEY_MAP = { status: 'is_active' };
const dataTableColumns = computed(() => activeColumns.value.map(col => (
    SORTABLE_COLUMN_KEYS.includes(col.key)
        ? { ...col, sortable: true, sortKey: SORT_KEY_MAP[col.key] }
        : col
)));

const form = useForm({
    name: '',
    type: 'expense',
    is_active: true,
});

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const fetchFiltered = useDebounceFn(() => {
    router.get(route('finance.categories.index'), {
        search: search.value,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
// ------------------------------------

const { sort, onSort } = useServerSort('finance.categories.index', () => props.filters, () => ({ search: search.value }));

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const bulkDelete = () => {
    const hasSystem = props.categories.data.some(c => c.is_system && selectedIds.value.includes(c.id));
    const confirmText = hasSystem
        ? `Удалить выбранные статьи (${selectedIds.value.length})? Системные статьи среди выбранных будут отклонены сервером.`
        : `Удалить выбранные статьи (${selectedIds.value.length})?`;
    if (confirm(confirmText)) {
        router.post(route('finance.categories.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('finance.categories.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `transaction_categories_export_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
    } catch (error) {
        console.error("Export failed", error);
        alert("Ошибка при экспорте данных");
    }
};
// ----------------------------------------

const getLocalizedLabel = (label) => {
    if (!label) return '';
    if (typeof label === 'string') {
        try {
            label = JSON.parse(label);
        } catch (e) {
            return label;
        }
    }
    return label['ru'] || label['en'] || Object.values(label)[0] || '';
};

const openModal = (category = null) => {
    if (category?.is_system) return;
    editingCategory.value = category;
    if (category) {
        form.name = getLocalizedLabel(category.name);
        form.type = category.type;
        form.is_active = Boolean(category.is_active);
    } else {
        form.reset();
        form.is_active = true;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingCategory.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingCategory.value) {
        form.put(route('finance.categories.update', editingCategory.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('finance.categories.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteCategory = (category) => {
    if (category.is_system) return;
    if (confirm(`Удалить статью "${getLocalizedLabel(category.name)}"?`)) {
        form.delete(route('finance.categories.destroy', category.id));
    }
};
</script>

<template>
    <Head title="Статьи доходов и расходов" />

    <AuthenticatedLayout>
        <template #header>
            Финансы и Кассы
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <FinanceNav />

            <PageHelper title="Статьи доходов и расходов">
                <p>Справочник статей позволяет вам классифицировать все финансовые операции в компании для последующей аналитики (P&L отчета).</p>
                <p>Например: «Аренда», «Закупка материалов», «Зарплата», «Оплата от клиентов».</p>
                <p class="mt-2"><strong>Системные статьи</strong> («Оплата заказа», «Выплата зарплаты» и др.) созданы платформой и автоматически подставляются в типовые операции — они недоступны для правки и удаления.</p>
            </PageHelper>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="статей" 
                @export="bulkExport" 
                @delete="bulkDelete" 
            />

            <!-- Table Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="false"
                    @open-columns="isColumnsModalOpen = true"
                    placeholder="Поиск по названию..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Добавить статью
                        </button>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="dataTableColumns"
                        :rows="categories.data"
                        selectable
                        v-model="selectedIds"
                        has-actions
                        empty-message="Статьи не найдены."
                        :sort="sort"
                        @sort="onSort"
                    >
                        <template #cell-type="{ row: category }">
                            <span v-if="category.type === 'income'" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-success/10 text-success"><i class="ri-arrow-right-down-line"></i> Доход</span>
                            <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-danger/10 text-danger"><i class="ri-arrow-right-up-line"></i> Расход</span>
                        </template>
                        <template #cell-name="{ row: category }">
                            <span class="inline-flex items-center gap-2">
                                <span class="font-bold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(category.name) }}</span>
                                <span v-if="category.is_system" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-medium bg-secondary/10 text-secondary" title="Системная статья — создана платформой, используется в типовых операциях, недоступна для правки и удаления">
                                    <i class="ri-settings-3-line"></i> Системная
                                </span>
                            </span>
                        </template>
                        <template #cell-status="{ row: category }">
                            <span :class="[category.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                {{ category.is_active ? 'Активно' : 'Неактивно' }}
                            </span>
                        </template>
                        <template #actions="{ row: category }">
                            <button @click="openModal(category)" :disabled="category.is_system" :title="category.is_system ? 'Системная статья — недоступна для редактирования' : 'Редактировать'" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-primary/10">
                                <i class="ri-pencil-line"></i>
                            </button>
                            <button @click="deleteCategory(category)" :disabled="category.is_system" :title="category.is_system ? 'Системная статья — недоступна для удаления' : 'Удалить'" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-danger/10">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="categories" />
            </div>
        </div>

        <!-- Модальное окно -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingCategory ? 'Редактирование статьи' : 'Новая статья' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип операции <span class="text-danger">*</span></label>
                            <select v-model="form.type" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                <option value="expense" class="bg-white dark:bg-gray-800">Расход</option>
                                <option value="income" class="bg-white dark:bg-gray-800">Доход</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название статьи <span class="text-danger">*</span></label>
                            <input v-model="form.name" type="text" required placeholder="Например: Аренда помещения" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" />
                        </div>

                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Статья активна
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

        <ColumnSettingsModal
            :show="isColumnsModalOpen"
            entity-type="transaction_category"
            :available-columns="availableColumns"
            :visible-columns="listView.visible_columns"
            @close="isColumnsModalOpen = false"
            @saved="isColumnsModalOpen = false"
        />
    </AuthenticatedLayout>
</template>