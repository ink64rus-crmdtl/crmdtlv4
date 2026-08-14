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
import { Head, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import axios from 'axios';

const props = defineProps({
    movements: Object,
    warehouses: Array,
    branches: Array,
    filters: Object,
    availableColumns: { type: Array, default: () => [] },
    listView: { type: Object, default: () => ({ visible_columns: [] }) },
});

const page = usePage();

const isColumnsModalOpen = ref(false);

const activeColumns = computed(() => {
    return props.listView.visible_columns
        .map(key => props.availableColumns.find(c => c.key === key))
        .filter(Boolean);
});

// Числовые колонки — выравнивание по правому краю (и в шапке, и в ячейках).
const RIGHT_ALIGNED_COLUMNS = ['quantity', 'cost_price'];
// Сортировка — только реальные колонки stock_movements (белый список зеркалит
// StockMovementController::index()). warehouse_branch/product/reason — связи
// и составные ячейки, простым orderBy не сортируются.
const SORT_KEY_MAP = { date: 'created_at' };
const SORTABLE_COLUMN_KEYS = ['date', 'type', 'quantity', 'cost_price'];
const dataTableColumns = computed(() => activeColumns.value.map(col => ({
    ...col,
    align: RIGHT_ALIGNED_COLUMNS.includes(col.key) ? 'right' : undefined,
    sortable: SORTABLE_COLUMN_KEYS.includes(col.key),
    sortKey: SORT_KEY_MAP[col.key],
})));

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const filtersForm = reactive({
    warehouse_id: props.filters?.filters?.warehouse_id || '',
    branch_id: props.filters?.filters?.branch_id || '',
    type: props.filters?.filters?.type || '',
});

const isFiltersOpen = ref(false);

// StockMovement защищён глобальным BranchScope (см. app/Models/Scopes/BranchScope.php) —
// пока в шапке выбрана КОНКРЕТНАЯ точка, он и так молча добавляет
// WHERE branch_id = <текущая точка> к любому запросу. Если при этом фильтр
// "Локация" здесь выбрать на ДРУГУЮ точку — получится WHERE branch_id = А
// AND branch_id = Б, то есть 0 строк всегда, а фильтр выглядит "сломанным".
// Поэтому сам выбор локации в фильтре имеет смысл только при "Все локации"
// в шапке — иначе прячем его и объясняем причину, а не оставляем как
// неработающую ловушку.
const hasSpecificBranchContext = computed(() => !!page.props.current_branch_id);

// Если локация в шапке переключилась на конкретную ПОСЛЕ того, как уже был
// выбран фильтр по другой локации — сбрасываем его. Иначе получится тот же
// 0-строк-навсегда эффект, просто без видимого select'а, который бы это объяснил.
watch(hasSpecificBranchContext, (isSpecific) => {
    if (isSpecific) {
        filtersForm.branch_id = '';
    }
});

const fetchFiltered = useDebounceFn(() => {
    router.get(route('warehouse.movements.index'), {
        search: search.value,
        filters: filtersForm,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
watch(filtersForm, () => fetchFiltered(), { deep: true });

const { sort, onSort } = useServerSort('warehouse.movements.index', () => props.filters, () => ({ search: search.value, filters: filtersForm }));

const resetFilters = () => {
    filtersForm.warehouse_id = '';
    filtersForm.branch_id = '';
    filtersForm.type = '';
};
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
// select-all/сброс выбора теперь считает сам DataTable (v-model="selectedIds").
const selectedIds = ref([]);

const bulkExport = async () => {
    try {
        const response = await axios.post(route('warehouse.movements.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `stock_movements_export_${new Date().toISOString().slice(0,10)}.csv`);
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

const movementTypes = {
    'in': { label: 'Приход', class: 'bg-success/10 text-success', icon: 'ri-arrow-right-down-line' },
    'out': { label: 'Расход', class: 'bg-danger/10 text-danger', icon: 'ri-arrow-right-up-line' },
    'transfer': { label: 'Перемещение', class: 'bg-info/10 text-info', icon: 'ri-arrow-left-right-line' },
    'audit': { label: 'Инвентаризация', class: 'bg-warning/10 text-warning', icon: 'ri-file-search-line' },
    'consolidation': { label: 'Консолидация', class: 'bg-purple-100 text-purple-700', icon: 'ri-git-merge-line' },
};
</script>

<template>
    <Head title="Движения товаров" />

    <AuthenticatedLayout>
        <template #header>
            Склад и Прайс
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <WarehouseNav />

            <PageHelper title="Движения товаров">
                <p>Здесь фиксируется вся история изменения остатков на складах: приходы (по накладным от поставщиков), расходы (списания в заказ-наряды) и перемещения.</p>
                <p>Чтобы добавить товар на склад, оформите <Link :href="route('warehouse.goods-receipts.index')" class="text-primary hover:underline font-medium">приходную накладную</Link> — вкладка выше. Система автоматически пересчитает среднюю себестоимость или создаст новую партию в зависимости от настроек товара.</p>
            </PageHelper>

            <!-- Блок ошибок -->
            <div v-if="page.props.errors.error" class="p-4 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-start gap-3">
                <i class="ri-error-warning-fill text-xl shrink-0"></i>
                <div>
                    <p class="font-bold mb-1">Ошибка:</p>
                    <p>{{ page.props.errors.error }}</p>
                </div>
            </div>

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
                    :has-filters="Object.values(filtersForm).some(v => v !== '' && v !== null)"
                    @open-filters="isFiltersOpen = true"
                    @open-columns="isColumnsModalOpen = true"
                    placeholder="Поиск по названию товара или номеру заказа..."
                />
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="dataTableColumns"
                        :rows="movements.data"
                        selectable
                        v-model="selectedIds"
                        empty-message="Движения не найдены."
                        :sort="sort"
                        @sort="onSort"
                    >
                        <template #cell-date="{ row: movement }">{{ new Date(movement.created_at).toLocaleString('ru-RU', {day: 'numeric', month: 'short', hour: '2-digit', minute:'2-digit'}) }}</template>
                        <template #cell-type="{ row: movement }">
                            <span :class="[movementTypes[movement.type]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                <i :class="movementTypes[movement.type]?.icon"></i> {{ movementTypes[movement.type]?.label || movement.type }}
                            </span>
                        </template>
                        <template #cell-warehouse_branch="{ row: movement }">
                            <div class="font-medium"><i class="ri-building-4-line text-gray-400"></i> {{ movement.warehouse ? movement.warehouse.name : '—' }}</div>
                            <div class="text-xs text-gray-500 mt-0.5"><i class="ri-store-2-line"></i> {{ movement.branch ? movement.branch.name : '—' }}</div>
                        </template>
                        <template #cell-product="{ row: movement }">
                            <div class="font-bold text-gray-800 dark:text-gray-200">{{ movement.product ? getLocalizedLabel(movement.product.name) : '—' }}</div>
                            <div v-if="movement.batch" class="text-xs text-warning mt-0.5">Партия #{{ movement.batch.id }}</div>
                        </template>
                        <template #cell-quantity="{ row: movement }">
                            <span :class="[movement.type === 'in' ? 'text-success' : 'text-danger', 'font-bold']">
                                {{ movement.type === 'in' ? '+' : '-' }}{{ parseFloat(movement.quantity) }} {{ movement.product ? movement.product.unit : '' }}
                            </span>
                        </template>
                        <template #cell-cost_price="{ row: movement }">{{ formatMoney(movement.cost_price) }}</template>
                        <template #cell-reason="{ row: movement }">
                            <Link v-if="movement.work_order" :href="route('operations.work-orders.show', movement.work_order.id)" class="text-primary hover:underline font-medium">
                                Заказ #{{ String(movement.work_order.id).padStart(6, '0') }}
                            </Link>
                            <Link v-else-if="movement.goods_receipt" :href="route('warehouse.goods-receipts.show', movement.goods_receipt.id)" class="text-primary hover:underline font-medium">
                                Накладная №{{ String(movement.goods_receipt.id).padStart(6, '0') }}<span v-if="movement.goods_receipt.supplier"> ({{ movement.goods_receipt.supplier.name }})</span>
                            </Link>
                            <span v-else class="text-xs text-gray-500">{{ movement.comment || 'Ручная операция' }}</span>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="movements" />
            </div>
        </div>

        <!-- Offcanvas Фильтры -->
        <Offcanvas :show="isFiltersOpen" @close="isFiltersOpen = false" maxWidth="sm">
            <div class="flex flex-col h-full">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Фильтры движений</h3>
                    <button @click="isFiltersOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-6 space-y-5 custom-scrollbar">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип операции</label>
                        <select v-model="filtersForm.type" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все операции</option>
                            <option v-for="(type, key) in movementTypes" :key="key" :value="key">{{ type.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Склад</label>
                        <select v-model="filtersForm.warehouse_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все склады</option>
                            <option v-for="wh in warehouses" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация</label>
                        <select v-if="!hasSpecificBranchContext" v-model="filtersForm.branch_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все локации</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                        <p v-else class="text-xs text-gray-400">В шапке выбрана конкретная локация — движения и так показаны только по ней. Переключите шапку на «Все локации», чтобы фильтровать по другой.</p>
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
            entity-type="stock_movement"
            :available-columns="availableColumns"
            :visible-columns="listView.visible_columns"
            @close="isColumnsModalOpen = false"
            @saved="isColumnsModalOpen = false"
        />

    </AuthenticatedLayout>
</template>