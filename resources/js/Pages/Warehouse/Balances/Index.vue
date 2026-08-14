<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import WarehouseNav from '@/Components/WarehouseNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTable from '@/Components/DataTable.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import ColumnSettingsModal from '@/Components/ColumnSettingsModal.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import axios from 'axios';

const props = defineProps({
    balances: Object,
    warehouses: Array,
    categories: Array,
    filters: Object,
    availableColumns: { type: Array, default: () => [] },
    listView: { type: Object, default: () => ({ visible_columns: [] }) },
});

const isColumnsModalOpen = ref(false);

const activeColumns = computed(() => {
    return props.listView.visible_columns
        .map(key => props.availableColumns.find(c => c.key === key))
        .filter(Boolean);
});

// Числовые колонки — выравнивание по правому краю (и в шапке, и в ячейках).
const RIGHT_ALIGNED_COLUMNS = ['quantity', 'avg_cost', 'total_value'];
// Сортировка — только реальные колонки stock_balances (белый список зеркалит
// StockBalanceController::index()). total_value — вычисляемый аксессор
// (quantity * avg_cost), не сортируется простым orderBy.
const SORTABLE_COLUMN_KEYS = ['quantity', 'avg_cost'];
const dataTableColumns = computed(() => activeColumns.value.map(col => ({
    ...col,
    align: RIGHT_ALIGNED_COLUMNS.includes(col.key) ? 'right' : undefined,
    sortable: SORTABLE_COLUMN_KEYS.includes(col.key),
})));

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const filtersForm = reactive({
    warehouse_id: props.filters?.filters?.warehouse_id || '',
    product_category_id: props.filters?.filters?.product_category_id || '',
    hide_empty: props.filters?.filters?.hide_empty ?? '1',
});

const isFiltersOpen = ref(false);

const fetchFiltered = useDebounceFn(() => {
    router.get(route('warehouse.balances.index'), {
        search: search.value,
        filters: filtersForm,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
watch(filtersForm, () => fetchFiltered(), { deep: true });

const { sort, onSort } = useServerSort('warehouse.balances.index', () => props.filters, () => ({ search: search.value, filters: filtersForm }));

const resetFilters = () => {
    filtersForm.warehouse_id = '';
    filtersForm.product_category_id = '';
    filtersForm.hide_empty = '1';
};
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
// select-all/сброс выбора теперь считает сам DataTable (v-model="selectedIds").
const selectedIds = ref([]);

const bulkExport = async () => {
    try {
        const response = await axios.post(route('warehouse.balances.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `stock_balances_export_${new Date().toISOString().slice(0,10)}.csv`);
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

const formatMoney = (amount) => {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(amount / 100);
};
</script>

<template>
    <Head title="Остатки на складах" />

    <AuthenticatedLayout>
        <template #header>
            Склад и Прайс
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <WarehouseNav />

            <PageHelper title="Текущие остатки">
                <p>Здесь отображается актуальное количество товаров и материалов на всех доступных вам складах.</p>
                <p><strong>Средняя себестоимость</strong> пересчитывается автоматически при каждом новом оприходовании (для товаров со средневзвешенным учетом). Для товаров с партионным учетом (FIFO) выводится средняя цена по всем непустым партиям.</p>
            </PageHelper>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length"
                noun="записей"
                @export="bulkExport"
                hide-delete
            />

            <!-- Table Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="Object.values(filtersForm).some(v => v !== '' && v !== null && v !== '1')"
                    @open-filters="isFiltersOpen = true"
                    @open-columns="isColumnsModalOpen = true"
                    placeholder="Поиск по названию или артикулу товара..."
                >
                    <template #actions>
                        <!-- Кнопка Оприходовать ведет на вкладку Движений -->
                        <a :href="route('warehouse.movements.index')" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                            <i class="ri-download-line text-base"></i>
                            Оприходовать товар
                        </a>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="dataTableColumns"
                        :rows="balances.data"
                        selectable
                        v-model="selectedIds"
                        empty-message="Остатки не найдены."
                        :sort="sort"
                        @sort="onSort"
                    >
                        <template #cell-warehouse="{ row: balance }">
                            <span class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300">
                                <i class="ri-building-4-line"></i> {{ balance.warehouse ? balance.warehouse.name : '—' }}
                            </span>
                        </template>
                        <template #cell-category="{ row: balance }">{{ balance.product && balance.product.category ? getLocalizedLabel(balance.product.category.name) : '—' }}</template>
                        <template #cell-product="{ row: balance }">
                            <div class="font-bold text-gray-800 dark:text-gray-200">{{ balance.product ? getLocalizedLabel(balance.product.name) : '—' }}</div>
                            <div class="text-xs text-gray-500 font-mono mt-0.5">{{ balance.product ? balance.product.sku : '' }}</div>
                        </template>
                        <template #cell-quantity="{ row: balance }">
                            <span :class="[balance.quantity <= 0 ? 'text-danger' : 'text-gray-800 dark:text-gray-200', 'font-bold']">
                                {{ parseFloat(balance.quantity) }} {{ balance.product ? balance.product.unit : '' }}
                            </span>
                        </template>
                        <template #cell-avg_cost="{ row: balance }">{{ formatMoney(balance.avg_cost) }}</template>
                        <template #cell-total_value="{ row: balance }">
                            <span class="font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(balance.quantity * balance.avg_cost) }}</span>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="balances" />
            </div>
        </div>

        <!-- Offcanvas Фильтры -->
        <Offcanvas :show="isFiltersOpen" @close="isFiltersOpen = false" maxWidth="sm">
            <div class="flex flex-col h-full">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Фильтры остатков</h3>
                    <button @click="isFiltersOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-6 space-y-5 custom-scrollbar">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Склад</label>
                        <select v-model="filtersForm.warehouse_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все склады</option>
                            <option v-for="wh in warehouses" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория товара</label>
                        <select v-model="filtersForm.product_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все категории</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ getLocalizedLabel(cat.name) }}</option>
                        </select>
                    </div>
                    <div class="flex items-center pt-2">
                        <div @click="filtersForm.hide_empty = filtersForm.hide_empty === '1' ? '0' : '1'" :class="[filtersForm.hide_empty === '1' ? 'bg-primary' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                            <div :class="[filtersForm.hide_empty === '1' ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="filtersForm.hide_empty = filtersForm.hide_empty === '1' ? '0' : '1'">
                            Скрывать нулевые остатки
                        </label>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/80 flex gap-3">
                    <button @click="resetFilters" class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">
                        Сбросить
                    </button>
                    <button @click="isFiltersOpen = false" class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 shadow-sm">
                        Применить
                    </button>
                </div>
            </div>
        </Offcanvas>

        <ColumnSettingsModal
            :show="isColumnsModalOpen"
            entity-type="stock_balance"
            :available-columns="availableColumns"
            :visible-columns="listView.visible_columns"
            @close="isColumnsModalOpen = false"
            @saved="isColumnsModalOpen = false"
        />

    </AuthenticatedLayout>
</template>