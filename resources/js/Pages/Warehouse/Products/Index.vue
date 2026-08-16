<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import WarehouseNav from '@/Components/WarehouseNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTable from '@/Components/DataTable.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import ColumnSettingsModal from '@/Components/ColumnSettingsModal.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import axios from 'axios';

const props = defineProps({
    products: Object,
    categories: Array,
    warehouses: Array,
    warehouseEnabled: { type: Boolean, default: true },
    filters: Object,
    availableColumns: { type: Array, default: () => [] },
    listView: { type: Object, default: () => ({ visible_columns: [] }) },
});

const isModalOpen = ref(false);
const isCategoryModalOpen = ref(false);
const isColumnsModalOpen = ref(false);
const editingProduct = ref(null);

const activeColumns = computed(() => {
    return props.listView.visible_columns
        .map(key => props.availableColumns.find(c => c.key === key))
        .filter(Boolean);
});

// Сортировка — только реальные колонки products (белый список зеркалит
// ProductController::index()). category — связь, name — переводимый JSON
// (сортировка по сырому JSON дала бы бессмысленный порядок) — не sortable.
const SORTABLE_COLUMN_KEYS = ['sku', 'unit', 'accounting_type', 'status', 'price'];
const SORT_KEY_MAP = { status: 'is_active', price: 'base_price' };
const dataTableColumns = computed(() => activeColumns.value.map(col => (
    SORTABLE_COLUMN_KEYS.includes(col.key)
        ? { ...col, sortable: true, sortKey: SORT_KEY_MAP[col.key] }
        : col
)));

const form = useForm({
    product_category_id: '',
    name: '',
    sku: '',
    unit: 'шт',
    accounting_type: 'average',
    preferred_warehouse_id: '',
    is_active: true,
    base_price: '',
    markup_percent: '',
    discount_percent: '',
    affects_payroll_by_default: true,
    allow_negative_stock_by_default: false,
});

const formatMoney = (cents) => {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format((cents || 0) / 100);
};

// Средняя себестоимость товара по всем складам (взвешенная) — только справочно,
// для подсказки наценки в форме; появляется, только если склад ведётся
// (product.stock_balances приходит с бэкенда исключительно тогда).
const averageCost = (product) => {
    const balances = product?.stock_balances || [];
    const totalQty = balances.reduce((sum, b) => sum + Number(b.quantity), 0);
    if (totalQty <= 0) return null;
    const totalCost = balances.reduce((sum, b) => sum + Number(b.quantity) * b.avg_cost, 0);
    return totalCost / totalQty;
};

// Живая подсказка "цена по наценке" в форме редактирования — считается один
// раз в момент ввода, ничего не сохраняет и не пересчитывается фоном.
const editingAverageCost = computed(() => editingProduct.value ? averageCost(editingProduct.value) : null);
const markupSuggestedPrice = computed(() => {
    if (editingAverageCost.value === null || !form.markup_percent) return null;
    return editingAverageCost.value * (1 + Number(form.markup_percent) / 100);
});

const categoryForm = useForm({
    name: '',
});

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const filtersForm = reactive({
    product_category_id: props.filters?.filters?.product_category_id || '',
    accounting_type: props.filters?.filters?.accounting_type || '',
    is_active: props.filters?.filters?.is_active ?? '',
});

const isFiltersOpen = ref(false);

const fetchFiltered = useDebounceFn(() => {
    router.get(route('warehouse.products.index'), {
        search: search.value,
        filters: filtersForm,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
watch(filtersForm, () => fetchFiltered(), { deep: true });

const { sort, onSort } = useServerSort('warehouse.products.index', () => props.filters, () => ({ search: search.value, filters: filtersForm }));

const resetFilters = () => {
    filtersForm.product_category_id = '';
    filtersForm.accounting_type = '';
    filtersForm.is_active = '';
};
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
// select-all/сброс выбора теперь считает сам DataTable (v-model="selectedIds").
const selectedIds = ref([]);

const bulkDelete = () => {
    if (confirm(`Удалить выбранные товары (${selectedIds.value.length})?`)) {
        router.post(route('warehouse.products.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('warehouse.products.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `products_export_${new Date().toISOString().slice(0,10)}.csv`);
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

const openModal = (product = null) => {
    editingProduct.value = product;
    if (product) {
        form.product_category_id = product.product_category_id || '';
        form.name = getLocalizedLabel(product.name);
        form.sku = product.sku || '';
        form.unit = product.unit || 'шт';
        form.accounting_type = product.accounting_type || 'average';
        form.preferred_warehouse_id = product.preferred_warehouse_id || '';
        form.is_active = Boolean(product.is_active);
        form.base_price = product.base_price ? product.base_price / 100 : '';
        form.markup_percent = product.markup_percent ?? '';
        form.discount_percent = product.discount_percent ?? '';
        form.affects_payroll_by_default = product.affects_payroll_by_default !== false;
        form.allow_negative_stock_by_default = Boolean(product.allow_negative_stock_by_default);
    } else {
        form.reset();
        form.is_active = true;
        form.unit = 'шт';
        form.accounting_type = 'average';
        form.affects_payroll_by_default = true;
        form.allow_negative_stock_by_default = false;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingProduct.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingProduct.value) {
        form.put(route('warehouse.products.update', editingProduct.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('warehouse.products.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteProduct = (product) => {
    if (confirm(`Удалить товар "${getLocalizedLabel(product.name)}"?`)) {
        form.delete(route('warehouse.products.destroy', product.id));
    }
};

const openCategoryModal = () => {
    categoryForm.reset();
    isCategoryModalOpen.value = true;
};

const closeCategoryModal = () => {
    isCategoryModalOpen.value = false;
    categoryForm.reset();
    categoryForm.clearErrors();
};

const submitCategory = () => {
    categoryForm.post(route('warehouse.product-categories.store'), {
        onSuccess: () => closeCategoryModal(),
    });
};
</script>

<template>
    <Head title="Товары и Материалы" />

    <AuthenticatedLayout>
        <template #header>
            Склад и Прайс
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <WarehouseNav />

            <PageHelper title="Номенклатура товаров">
                <p>Здесь вы заводите карточки товаров и расходных материалов (химия, пленки, фибры). Сами остатки и цены закупки указываются в момент <strong>Оприходования</strong> на склад.</p>
                <p><strong>Тип учета:</strong><br>
                - <em>Средневзвешенный:</em> Подходит для химии и расходников. Цена списания усредняется.<br>
                - <em>Партионный (FIFO):</em> Подходит для дорогих пленок. Списание идет строго по цене конкретной закупленной партии.</p>
            </PageHelper>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="товаров" 
                @export="bulkExport" 
                @delete="bulkDelete" 
            />

            <!-- Table Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="Object.values(filtersForm).some(v => v !== '' && v !== null)"
                    @open-filters="isFiltersOpen = true"
                    @open-columns="isColumnsModalOpen = true"
                    placeholder="Поиск по названию или артикулу..."
                >
                    <template #actions>
                        <button
                            @click="openCategoryModal()"
                            class="hidden sm:inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white shadow-sm"
                        >
                            <i class="ri-folder-add-line mr-1.5"></i> Новая категория
                        </button>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Добавить товар
                        </button>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="dataTableColumns"
                        :rows="products.data"
                        selectable
                        v-model="selectedIds"
                        has-actions
                        empty-message="Товары не найдены."
                        :sort="sort"
                        @sort="onSort"
                    >
                        <template #cell-category="{ row: product }">
                            <span v-if="product.category" class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300">
                                <i class="ri-folder-line"></i> {{ getLocalizedLabel(product.category.name) }}
                            </span>
                            <span v-else class="text-gray-400 text-xs">—</span>
                        </template>
                        <template #cell-sku="{ row: product }">
                            <span class="font-mono text-xs">{{ product.sku || '—' }}</span>
                        </template>
                        <template #cell-name="{ row: product }">
                            <span class="font-bold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(product.name) }}</span>
                        </template>
                        <template #cell-unit="{ row: product }">{{ product.unit }}</template>
                        <template #cell-price="{ row: product }">
                            <span v-if="product.base_price" class="font-medium">{{ formatMoney(product.base_price) }}</span>
                            <span v-else class="text-gray-400">—</span>
                        </template>
                        <template #cell-accounting_type="{ row: product }">
                            <span v-if="product.accounting_type === 'average'" class="inline-flex items-center gap-1 text-xs font-medium text-info"><i class="ri-scales-3-line"></i> Средневзвешенный</span>
                            <span v-else class="inline-flex items-center gap-1 text-xs font-medium text-warning"><i class="ri-stack-line"></i> Партионный (FIFO)</span>
                        </template>
                        <template #cell-status="{ row: product }">
                            <span :class="[product.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                {{ product.is_active ? 'Активно' : 'Неактивно' }}
                            </span>
                        </template>
                        <template #actions="{ row: product }">
                            <button @click="openModal(product)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать">
                                <i class="ri-pencil-line"></i>
                            </button>
                            <button @click="deleteProduct(product)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="products" />
            </div>
        </div>

        <!-- Offcanvas Фильтры -->
        <Offcanvas :show="isFiltersOpen" @close="isFiltersOpen = false" maxWidth="sm">
            <div class="flex flex-col h-full">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Фильтры</h3>
                    <button @click="isFiltersOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-6 space-y-5 custom-scrollbar">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория</label>
                        <select v-model="filtersForm.product_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все категории</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ getLocalizedLabel(cat.name) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип учёта</label>
                        <select v-model="filtersForm.accounting_type" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Любой</option>
                            <option value="average">Средневзвешенный</option>
                            <option value="batch">Партионный (FIFO)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус</label>
                        <select v-model="filtersForm.is_active" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все</option>
                            <option value="1">Активные</option>
                            <option value="0">Неактивные</option>
                        </select>
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

        <!-- Модальное окно Товара -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingProduct ? 'Редактирование товара' : 'Новый товар' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория</label>
                                <select v-model="form.product_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                    <option value="" class="bg-white dark:bg-gray-800">Без категории</option>
                                    <option v-for="cat in categories" :key="cat.id" :value="cat.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(cat.name) }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Артикул / SKU</label>
                                <input v-model="form.sku" type="text" placeholder="Например: CH-001" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Наименование товара <span class="text-danger">*</span></label>
                            <input v-model="form.name" type="text" required placeholder="Например: Шампунь для бесконтактной мойки" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Единица измерения <span class="text-danger">*</span></label>
                                <select v-model="form.unit" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                    <option value="шт" class="bg-white dark:bg-gray-800">Штуки (шт)</option>
                                    <option value="мл" class="bg-white dark:bg-gray-800">Миллилитры (мл)</option>
                                    <option value="л" class="bg-white dark:bg-gray-800">Литры (л)</option>
                                    <option value="гр" class="bg-white dark:bg-gray-800">Граммы (гр)</option>
                                    <option value="кг" class="bg-white dark:bg-gray-800">Килограммы (кг)</option>
                                    <option value="пог.м" class="bg-white dark:bg-gray-800">Погонные метры (пог.м)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип учета <span class="text-danger">*</span></label>
                                <select v-model="form.accounting_type" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                    <option value="average" class="bg-white dark:bg-gray-800">Средневзвешенный (Химия, Расходники)</option>
                                    <option value="batch" class="bg-white dark:bg-gray-800">Партионный / FIFO (Пленки, Керамика)</option>
                                </select>
                            </div>
                        </div>

                        <div v-if="warehouseEnabled">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Привязка к складу (Для Смешанного режима)</label>
                            <select v-model="form.preferred_warehouse_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                <option value="" class="bg-white dark:bg-gray-800">Определять автоматически по локации</option>
                                <option v-for="wh in warehouses" :key="wh.id" :value="wh.id" class="bg-white dark:bg-gray-800">Всегда списывать с: {{ wh.name }}</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Используется только если в настройках включен "Смешанный" режим склада.</p>
                        </div>

                        <!-- Цена, наценка, скидка — независимые поля (CLAUDE.md: наценка подсказывает
                             цену от текущей средней себестоимости при вводе, разово, без фоновой
                             привязки; скидка даёт цену по умолчанию при добавлении товара в заказ). -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2 border-t border-gray-200 dark:border-gray-700">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цена продажи, ₽</label>
                                <input v-model="form.base_price" type="number" step="0.01" min="0" placeholder="0" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Наценка, %</label>
                                <input v-model="form.markup_percent" type="number" step="0.01" min="0" placeholder="0" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                <p v-if="markupSuggestedPrice !== null" class="text-[11px] text-gray-400 mt-1">
                                    От себестоимости {{ formatMoney(editingAverageCost) }} ≈ <button type="button" @click="form.base_price = (Math.round(markupSuggestedPrice) / 100).toFixed(2)" class="text-primary hover:underline font-medium">{{ formatMoney(markupSuggestedPrice) }}</button>
                                </p>
                                <p v-else-if="editingProduct && warehouseEnabled" class="text-[11px] text-gray-400 mt-1">Нет данных о себестоимости — товар ещё не приходовался.</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Скидка по умолчанию, %</label>
                                <input v-model="form.discount_percent" type="number" step="0.01" min="0" max="100" placeholder="0" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                <p class="text-[11px] text-gray-400 mt-1">Такую цену увидит менеджер при добавлении товара в заказ-наряд — её всегда можно поправить вручную.</p>
                            </div>
                        </div>

                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Товар активен
                            </label>
                        </div>

                        <div class="flex items-start pt-2">
                            <div @click="form.affects_payroll_by_default = !form.affects_payroll_by_default" :class="[form.affects_payroll_by_default ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0 mt-0.5']">
                                <div :class="[form.affects_payroll_by_default ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <div class="ml-2.5">
                                <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.affects_payroll_by_default = !form.affects_payroll_by_default">
                                    Учитывать при расчёте ЗП по умолчанию
                                </label>
                                <p class="text-[11px] text-gray-400 mt-0.5">Когда этот товар добавляют на заказ как материал, потраченный на услугу (CLAUDE.md «Материалы на услугу»), вычитать его стоимость из базы ЗП мастера по умолчанию — можно переопределить на каждом заказе отдельно.</p>
                            </div>
                        </div>

                        <div v-if="warehouseEnabled" class="flex items-start pt-2">
                            <div @click="form.allow_negative_stock_by_default = !form.allow_negative_stock_by_default" :class="[form.allow_negative_stock_by_default ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0 mt-0.5']">
                                <div :class="[form.allow_negative_stock_by_default ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <div class="ml-2.5">
                                <label class="block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.allow_negative_stock_by_default = !form.allow_negative_stock_by_default">
                                    Разрешить списание в минус по умолчанию
                                </label>
                                <p class="text-[11px] text-gray-400 mt-0.5">При завершении заказа этот товар спишется со склада даже при недостатке остатка — и как обычная проданная позиция, и как материал на услугу. Можно переопределить на каждой позиции отдельно.</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Модальное окно Категории -->
        <div v-if="isCategoryModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Новая категория товаров
                    </h3>
                    <button @click="closeCategoryModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <form @submit.prevent="submitCategory" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название категории <span class="text-danger">*</span></label>
                            <input v-model="categoryForm.name" type="text" required placeholder="Например: Автохимия" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeCategoryModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="categoryForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Создать</button>
                    </div>
                </form>
            </div>
        </div>

        <ColumnSettingsModal
            :show="isColumnsModalOpen"
            entity-type="product"
            :available-columns="availableColumns"
            :visible-columns="listView.visible_columns"
            @close="isColumnsModalOpen = false"
            @saved="isColumnsModalOpen = false"
        />

    </AuthenticatedLayout>
</template>