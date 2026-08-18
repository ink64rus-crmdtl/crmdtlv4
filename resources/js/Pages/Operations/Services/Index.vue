<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import OperationsNav from '@/Components/OperationsNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import ColumnSettingsModal from '@/Components/ColumnSettingsModal.vue';
import TableFitToggle from '@/Components/TableFitToggle.vue';
import DataTable from '@/Components/DataTable.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, useForm, router, Link } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import axios from 'axios';

const props = defineProps({
    services: Object,
    categories: Array,
    businessDirections: Array,
    pricingBasis: String,
    lookups: Object,
    filters: Object,
    availableColumns: { type: Array, default: () => [] },
    listView: { type: Object, default: () => ({ visible_columns: [] }) },
    materialProducts: { type: Array, default: () => [] },
    workerBaseExcludesMaterials: { type: Boolean, default: true },
});

const isModalOpen = ref(false);
const isCategoryModalOpen = ref(false);
const editingService = ref(null);
const editingCategory = ref(null);

const pricesInput = ref({});

const form = useForm({
    service_category_id: '',
    business_direction_id: '',
    name: '',
    price: 0,
    prices: {},
    duration_minutes: 60,
    is_active: true,
});

const categoryForm = useForm({
    name: '',
    business_direction_id: '',
});

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const initialFilters = {
    service_category_id: props.filters?.filters?.service_category_id || '',
    business_direction_id: props.filters?.filters?.business_direction_id || '',
    is_active: props.filters?.filters?.is_active ?? '',
};
const filtersForm = reactive(initialFilters);
const isFiltersOpen = ref(false);
const isColumnsModalOpen = ref(false);
const fitColumns = ref(localStorage.getItem('services.fit-columns') === '1');

const hasActiveFilters = computed(() => Object.values(filtersForm).some(v => v !== '' && v !== null));

const fetchFiltered = useDebounceFn(() => {
    router.get(route('operations.services.index'), {
        search: search.value,
        filters: filtersForm,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
watch(filtersForm, () => fetchFiltered(), { deep: true });

const resetFilters = () => {
    Object.keys(filtersForm).forEach(key => {
        filtersForm[key] = '';
    });
};

const { sort, onSort } = useServerSort('operations.services.index', () => props.filters, () => ({ search: search.value, filters: filtersForm }));

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const selectAll = computed({
    get: () => props.services.data.length > 0 && selectedIds.value.length === props.services.data.length,
    set: (value) => {
        if (value) {
            selectedIds.value = props.services.data.map(s => s.id);
        } else {
            selectedIds.value = [];
        }
    }
});

const bulkDelete = () => {
    if (confirm(`Удалить выбранные услуги (${selectedIds.value.length})?`)) {
        router.post(route('operations.services.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('operations.services.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `services_export_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
    } catch (error) {
        console.error("Export failed", error);
        alert("Ошибка при экспорте данных");
    }
};

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

// Когда включено ценообразование по справочнику — в таблице появляется отдельная
// колонка на каждое его значение (плюс колонка "База" как фолбэк), вместо одной
// скрытой за модалкой "Базовой цены".
const matrixLookups = computed(() => props.lookups?.[props.pricingBasis] || []);
const matrixActive = computed(() => props.pricingBasis !== 'none' && matrixLookups.value.length > 0);
const matrixBasisLabel = computed(() => props.pricingBasis === 'vehicle_body' ? 'Тип кузова' : 'Класс авто');
const totalColumns = computed(() => 7 + (matrixActive.value ? matrixLookups.value.length + 1 : 1));

// Плоский режим (!matrixActive) мигрирован на DataTable.vue. Матричный режим
// (rowspan/colspan-заголовок, динамическая колонка цены на каждое значение
// справочника) DataTable не поддерживает (один заголовочный ряд) — остаётся
// raw-таблицей ниже, см. docs/table-refactor/baseline/operations-services.md.
// category/business_direction (связи) и name (translatable, JSON) — не сортируются простым orderBy.
const activeColumns = computed(() => {
    const visibleKeys = props.listView?.visible_columns || [];
    return visibleKeys.map(key => props.availableColumns.find(c => c.key === key)).filter(Boolean);
});

const SORTABLE_COLUMN_KEYS = ['price', 'duration_minutes', 'status'];
const SORT_KEY_MAP = { status: 'is_active' };
const COLUMN_ALIGN = { price: 'right', duration_minutes: 'right' };
const dataTableColumns = computed(() => activeColumns.value.map(col => ({
    ...col,
    align: COLUMN_ALIGN[col.key],
    sortable: SORTABLE_COLUMN_KEYS.includes(col.key),
    ...(SORT_KEY_MAP[col.key] ? { sortKey: SORT_KEY_MAP[col.key] } : {}),
})));

const openModal = (service = null) => {
    editingService.value = service;
    pricesInput.value = {};

    if (service) {
        form.service_category_id = service.service_category_id || '';
        form.business_direction_id = service.business_direction_id || '';
        form.name = getLocalizedLabel(service.name);
        form.price = service.price / 100;
        form.duration_minutes = service.duration_minutes;
        form.is_active = Boolean(service.is_active);
        
        if (service.prices) {
            Object.keys(service.prices).forEach(key => {
                pricesInput.value[key] = service.prices[key] / 100;
            });
        }
    } else {
        form.reset();
        form.is_active = true;
        form.duration_minutes = 60;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingService.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    const pricesInCents = {};
    Object.keys(pricesInput.value).forEach(key => {
        if (pricesInput.value[key] !== null && pricesInput.value[key] !== '') {
            pricesInCents[key] = Math.round(parseFloat(pricesInput.value[key]) * 100);
        }
    });
    form.prices = pricesInCents;

    if (editingService.value) {
        form.put(route('operations.services.update', editingService.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('operations.services.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteService = (service) => {
    if (confirm(`Удалить услугу "${getLocalizedLabel(service.name)}"?`)) {
        form.delete(route('operations.services.destroy', service.id));
    }
};

const openCategoryModal = () => {
    categoryForm.reset();
    isCategoryModalOpen.value = true;
};

const closeCategoryModal = () => {
    isCategoryModalOpen.value = false;
    editingCategory.value = null;
    categoryForm.reset();
    categoryForm.clearErrors();
};

const editCategory = (category) => {
    editingCategory.value = category;
    categoryForm.name = getLocalizedLabel(category.name);
    categoryForm.business_direction_id = category.business_direction_id || '';
    categoryForm.clearErrors();
};

const cancelCategoryEdit = () => {
    editingCategory.value = null;
    categoryForm.reset();
    categoryForm.clearErrors();
};

const submitCategory = () => {
    if (editingCategory.value) {
        categoryForm.put(route('operations.service-categories.update', editingCategory.value.id), {
            onSuccess: () => cancelCategoryEdit(),
        });
    } else {
        categoryForm.post(route('operations.service-categories.store'), {
            onSuccess: () => closeCategoryModal(),
        });
    }
};

const pluralize = (n) => {
    const mod10 = n % 10, mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return 'услуга';
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return 'услуги';
    return 'услуг';
};

const categoryDirectionFilter = ref('');

const filteredCategories = computed(() => {
    if (!categoryDirectionFilter.value) return props.categories;
    return props.categories.filter(cat => cat.business_direction_id === categoryDirectionFilter.value);
});

const deleteCategory = (category) => {
    const count = category.services_count || 0;
    const warning = count > 0 ? `\nВнутри ${count} ${pluralize(count)} — они останутся без категории.` : '';
    if (confirm(`Удалить категорию "${getLocalizedLabel(category.name)}"?${warning}`)) {
        categoryForm.delete(route('operations.service-categories.destroy', category.id));
    }
};

// --- Материалы по умолчанию (CLAUDE.md «Материалы на услугу») ---
const materialForm = useForm({ product_id: '', quantity: 1 });

const materialProductOptions = computed(() => props.materialProducts.map(p => ({ value: p.id, label: `${getLocalizedLabel(p.name)} (${p.unit})` })));

// editingService — снэпшот на момент открытия модалки; после добавления/удаления
// материала Inertia обновляет props.services, а не сам этот снэпшот — поэтому
// список материалов читаем из СВЕЖЕГО props.services по id, а не из снэпшота.
const currentServiceMaterials = computed(() => {
    if (!editingService.value) return [];
    return props.services.data.find(s => s.id === editingService.value.id)?.default_materials || [];
});

const addDefaultMaterial = () => {
    if (!editingService.value || !materialForm.product_id) return;
    materialForm.post(route('operations.services.materials.store', editingService.value.id), {
        preserveScroll: true,
        onSuccess: () => { materialForm.reset(); materialForm.quantity = 1; },
    });
};

const removeDefaultMaterial = (material) => {
    if (!confirm(`Убрать «${getLocalizedLabel(material.product?.name)}» из правила автодобавления?`)) return;
    router.delete(route('operations.services.materials.destroy', material.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Прайс-лист услуг" />

    <AuthenticatedLayout>
        <template #header>
            Операции и Заказы
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <OperationsNav />

            <PageHelper title="Прайс-лист услуг">
                <p>Здесь настраивается весь список услуг компании. Указанные базовые цены будут автоматически применяться при наборе заказ-наряда.</p>
                <p v-if="pricingBasis !== 'none'" class="mt-2 text-info text-xs font-bold">
                    <i class="ri-information-line"></i> Активно динамическое ценообразование ({{ pricingBasis === 'vehicle_body' ? 'По типу кузова' : 'По классу авто' }}).
                </p>
            </PageHelper>

            <!-- Action Bar -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="услуг" 
                @export="bulkExport" 
                @delete="bulkDelete" 
            />

            <!-- Table Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="hasActiveFilters"
                    @open-filters="isFiltersOpen = true"
                    @open-columns="isColumnsModalOpen = true"
                    placeholder="Поиск по названию услуги..."
                >
                    <template #actions>
                        <TableFitToggle v-model="fitColumns" storage-key="services.fit-columns" />
                        <button
                            @click="openCategoryModal()"
                            class="hidden sm:inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all bg-secondary/10 text-secondary hover:bg-secondary hover:text-white shadow-sm"
                        >
                            <i class="ri-folder-2-line mr-1.5"></i> Категории услуг
                        </button>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Добавить услугу
                        </button>
                    </template>
                </DataTableToolbar>
                <!-- Плоский режим (без матрицы цен по кузову/классу) — DataTable.vue -->
                <div v-if="!matrixActive" class="overflow-x-auto w-full">
                    <DataTable
                        :columns="dataTableColumns"
                        :fit-columns="fitColumns"
                        :rows="services.data"
                        selectable
                        v-model="selectedIds"
                        has-actions
                        :sort="sort"
                        @sort="onSort"
                        empty-message="Услуги еще не добавлены."
                    >
                        <template #cell-category="{ row: service }">
                            <span v-if="service.category" class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300">
                                <i class="ri-folder-line"></i> {{ getLocalizedLabel(service.category.name) }}
                            </span>
                            <span v-else class="text-gray-400 text-xs">—</span>
                        </template>
                        <template #cell-business_direction="{ row: service }">
                            <span v-if="service.business_direction" class="inline-flex items-center gap-1.5 bg-info/10 text-info px-2 py-0.5 rounded text-xs font-medium">
                                <i class="ri-node-tree"></i> {{ service.business_direction.name }}
                            </span>
                            <span v-else class="text-gray-400 text-xs">—</span>
                        </template>
                        <template #cell-name="{ row: service }">
                            <span class="font-bold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(service.name) }}</span>
                        </template>
                        <template #cell-price="{ row: service }">
                            <span class="font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(service.price) }}</span>
                        </template>
                        <template #cell-duration_minutes="{ row: service }">{{ service.duration_minutes }} мин</template>
                        <template #cell-status="{ row: service }">
                            <span :class="[service.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                {{ service.is_active ? 'Активно' : 'Неактивно' }}
                            </span>
                        </template>
                        <template #actions="{ row: service }">
                            <button @click="openModal(service)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать"><i class="ri-pencil-line"></i></button>
                            <button @click="deleteService(service)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                        </template>
                    </DataTable>
                </div>

                <!-- Матричный режим (динамическая колонка цены на каждое значение справочника, rowspan/colspan-заголовок) —
                     DataTable.vue не поддерживает многострочный thead, остаётся raw-таблицей. -->
                <div v-else class="overflow-x-auto w-full">
                    <table :class="['text-left', fitColumns ? 'w-full table-fixed whitespace-normal' : 'min-w-full whitespace-nowrap']">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th rowspan="2" class="py-3 px-4 w-10 border-b border-gray-200 dark:border-gray-700 text-center align-bottom">
                                    <input type="checkbox" v-model="selectAll" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </th>
                                <th rowspan="2" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 align-bottom">Категория</th>
                                <th rowspan="2" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 align-bottom">Направление</th>
                                <th rowspan="2" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 align-bottom">Название услуги</th>
                                <th :colspan="matrixLookups.length + 1" class="py-2 px-6 text-xs font-bold text-info uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-center bg-info/5">
                                    Цена: {{ matrixBasisLabel }}
                                </th>
                                <th rowspan="2" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right align-bottom">Нормо-время</th>
                                <th rowspan="2" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 align-bottom">Статус</th>
                                <th rowspan="2" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right align-bottom">Действия</th>
                            </tr>
                            <tr>
                                <th class="py-2 px-4 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right" title="Используется, если для конкретного значения цена не задана">База</th>
                                <th v-for="lookup in matrixLookups" :key="lookup.id" class="py-2 px-4 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">{{ lookup.value }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="service in services.data" :key="service.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center">
                                    <input type="checkbox" :value="service.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <span v-if="service.category" class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300">
                                        <i class="ri-folder-line"></i> {{ getLocalizedLabel(service.category.name) }}
                                    </span>
                                    <span v-else class="text-gray-400 text-xs">—</span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <span v-if="service.business_direction" class="inline-flex items-center gap-1.5 bg-info/10 text-info px-2 py-0.5 rounded text-xs font-medium">
                                        <i class="ri-node-tree"></i> {{ service.business_direction.name }}
                                    </span>
                                    <span v-else class="text-gray-400 text-xs">—</span>
                                </td>
                                <td :class="['py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50', fitColumns ? 'break-words align-top' : '']">
                                    {{ getLocalizedLabel(service.name) }}
                                </td>
                                <td class="py-4 px-4 text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50 text-right">
                                    {{ formatMoney(service.price) }}
                                </td>
                                <td v-for="lookup in matrixLookups" :key="lookup.id" class="py-4 px-4 text-sm border-b border-gray-100 dark:border-gray-700/50 text-right">
                                    <span v-if="service.prices && service.prices[lookup.value] !== undefined" class="font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(service.prices[lookup.value]) }}</span>
                                    <span v-else class="text-gray-400" title="Цена не задана — используется базовая">= база</span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right">
                                    {{ service.duration_minutes }} мин
                                </td>
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50">
                                    <span :class="[service.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                        {{ service.is_active ? 'Активно' : 'Неактивно' }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-sm text-right border-b border-gray-100 dark:border-gray-700/50 space-x-2">
                                    <button @click="openModal(service)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать"><i class="ri-pencil-line"></i></button>
                                    <button @click="deleteService(service)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                </td>
                            </tr>
                            <tr v-if="services.data.length === 0">
                                <td :colspan="totalColumns" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Услуги еще не добавлены.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="services" />
            </div>
        </div>

        <!-- Модалка Услуги (Ширина 4xl) -->
        <Modal :show="isModalOpen" @close="closeModal" maxWidth="4xl">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingService ? 'Редактирование услуги' : 'Новая услуга' }}
                    </h3>
                    <button @click="closeModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория</label>
                                <select v-model="form.service_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" class="bg-white dark:bg-gray-800">Без категории</option>
                                    <option v-for="cat in categories" :key="cat.id" :value="cat.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(cat.name) }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Направление бизнеса</label>
                                <select v-model="form.business_direction_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" class="bg-white dark:bg-gray-800">Общие (Без направления)</option>
                                    <option v-for="dir in businessDirections" :key="dir.id" :value="dir.id" class="bg-white dark:bg-gray-800">{{ dir.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Наименование услуги <span class="text-danger">*</span></label>
                            <input v-model="form.name" type="text" required placeholder="Например: Трехфазная мойка кузова" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Базовая цена (₽) <span class="text-danger">*</span></label>
                                <input v-model="form.price" type="number" step="0.01" min="0" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Нормо-время (минуты) <span class="text-danger">*</span></label>
                                <input v-model="form.duration_minutes" type="number" min="0" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                        </div>

                        <!-- Матрица цен -->
                        <div v-if="pricingBasis !== 'none'" class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-4">
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">
                                Индивидуальные цены по {{ pricingBasis === 'vehicle_body' ? 'типам кузова' : 'классам автомобилей' }}
                            </h4>
                            <p class="text-xs text-gray-500 mb-3">Оставьте значение пустым, чтобы подставлять базовую цену.</p>
                            
                            <!-- auto-fit (не auto-fill): пустые треки схлопываются, и блоки
                                 цен делят ВСЮ ширину формы поровну — 2 категории → по 50%
                                 каждый, 3 → по трети и т.д. auto-fill оставлял бы пустой
                                 трек, и пара категорий занимала бы только часть ширины. -->
                            <div class="grid grid-cols-[repeat(auto-fit,minmax(180px,1fr))] gap-3">
                                <div v-for="lookup in (lookups[pricingBasis] || [])" :key="lookup.id" class="bg-gray-50 dark:bg-gray-800/40 p-2.5 rounded border border-gray-200 dark:border-gray-700">
                                    <label class="block text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">{{ lookup.value }} (₽)</label>
                                    <input v-model="pricesInput[lookup.value]" type="number" step="0.01" min="0" class="block w-full rounded border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 py-1 px-2 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Услуга активна (доступна в заказах)
                            </label>
                        </div>

                        <!-- Материалы по умолчанию (CLAUDE.md «Материалы на услугу») —
                             доступно только для уже сохранённой услуги: правило
                             ссылается на её id, для новой его ещё нет. -->
                        <div v-if="editingService" class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-2">
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-1">Материалы по умолчанию</h4>
                            <p class="text-xs text-gray-500 mb-3">
                                При добавлении этой услуги в заказ система предложит (или сразу добавит — зависит от настройки в Настройки → Склад) эти материалы в заданном количестве. Материалы скрыты от клиента и {{ workerBaseExcludesMaterials ? 'по текущим настройкам уменьшают базу расчёта ЗП' : 'по текущим настройкам не влияют на базу расчёта ЗП (вычитаться не будут)' }}. <Link :href="route('settings.payroll.index')" class="text-primary hover:underline">Можно настроить здесь</Link>.
                            </p>

                            <div v-if="currentServiceMaterials.length > 0" class="space-y-1.5 mb-3">
                                <div v-for="material in currentServiceMaterials" :key="material.id" class="flex items-center justify-between gap-2 p-2 rounded border border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                                    <span class="text-sm text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(material.product?.name) }}</span>
                                    <div class="flex items-center gap-2 shrink-0">
                                        <span class="text-xs text-gray-500">{{ parseFloat(material.quantity) }} {{ material.product?.unit }}</span>
                                        <button type="button" @click="removeDefaultMaterial(material)" class="text-gray-400 hover:text-danger transition-colors p-1" title="Убрать"><i class="ri-delete-bin-line"></i></button>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-xs text-gray-400 mb-3">Материалы по умолчанию не заданы.</p>

                            <div class="flex items-end gap-2">
                                <div class="flex-1">
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Материал</label>
                                    <SearchableSelect v-model="materialForm.product_id" :options="materialProductOptions" placeholder="Выберите товар" />
                                </div>
                                <div class="w-24">
                                    <label class="block text-xs font-medium text-gray-500 mb-1">Кол-во</label>
                                    <input v-model.number="materialForm.quantity" type="number" step="any" min="0.001" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                </div>
                                <button type="button" @click="addDefaultMaterial" :disabled="!materialForm.product_id || materialForm.processing" class="inline-flex items-center justify-center rounded px-3 py-2 text-sm font-medium bg-primary/10 text-primary hover:bg-primary hover:text-white disabled:opacity-50 shrink-0"><i class="ri-add-line"></i></button>
                            </div>
                            <p v-if="materialForm.errors.product_id" class="text-xs text-danger mt-1">{{ materialForm.errors.product_id }}</p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary rounded">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="px-4 py-2 text-sm font-medium bg-primary text-white rounded">Сохранить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Модалка управления категориями услуг -->
        <Modal :show="isCategoryModalOpen" @close="closeCategoryModal" maxWidth="2xl">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingCategory ? 'Редактирование категории' : 'Категории услуг' }}</h3>
                    <button @click="closeCategoryModal()" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>

                <!-- Список существующих категорий -->
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between gap-3 mb-4">
                        <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200">
                            Существующие категории
                            <span class="text-xs font-medium text-gray-400 ml-1">({{ filteredCategories.length }})</span>
                        </h4>
                        <select v-model="categoryDirectionFilter" class="block w-56 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-2.5 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                            <option value="" class="bg-white dark:bg-gray-800">Все направления</option>
                            <option v-for="dir in businessDirections" :key="dir.id" :value="dir.id" class="bg-white dark:bg-gray-800">{{ dir.name }}</option>
                        </select>
                    </div>
                    <div class="max-h-72 overflow-y-auto space-y-2">
                        <p v-if="filteredCategories.length === 0" class="text-sm text-gray-400">
                            {{ categoryDirectionFilter ? 'По выбранному направлению категорий нет.' : 'Категорий пока нет — добавьте первую ниже.' }}
                        </p>
                        <div v-else v-for="cat in filteredCategories" :key="cat.id" class="flex items-center justify-between gap-3 p-2.5 rounded border border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <i class="ri-folder-line text-gray-400 shrink-0"></i>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-gray-800 dark:text-gray-200 truncate">{{ getLocalizedLabel(cat.name) }}</p>
                                <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                                    <span v-if="cat.business_direction" class="inline-flex items-center gap-1 bg-info/10 text-info px-2 py-0.5 rounded text-xs font-medium">
                                        <i class="ri-node-tree"></i> {{ cat.business_direction.name }}
                                    </span>
                                    <span v-else class="text-xs text-gray-400">Общая категория</span>
                                    <span class="text-xs text-gray-500">{{ cat.services_count }} {{ pluralize(cat.services_count) }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 shrink-0">
                            <button @click="editCategory(cat)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать"><i class="ri-pencil-line"></i></button>
                            <button @click="deleteCategory(cat)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                        </div>
                    </div>
                </div>
                </div>

                <form @submit.prevent="submitCategory">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                            <input v-model="categoryForm.name" type="text" required placeholder="Например: Мойка" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Направление бизнеса</label>
                            <select v-model="categoryForm.business_direction_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" class="bg-white dark:bg-gray-800">Общая категория (Без направления)</option>
                                <option v-for="dir in businessDirections" :key="dir.id" :value="dir.id" class="bg-white dark:bg-gray-800">{{ dir.name }}</option>
                            </select>
                        </div>
                        <p v-if="categoryForm.errors.name" class="text-xs text-danger">{{ categoryForm.errors.name }}</p>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50">
                        <button type="button" @click="editingCategory ? cancelCategoryEdit() : closeCategoryModal()" class="px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary rounded">{{ editingCategory ? 'Отменить редактирование' : 'Закрыть' }}</button>
                        <button type="submit" :disabled="categoryForm.processing" class="px-4 py-2 text-sm font-medium bg-primary text-white rounded">{{ editingCategory ? 'Сохранить' : 'Создать' }}</button>
                    </div>
                </form>
            </div>
        </Modal>

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
                        <select v-model="filtersForm.service_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="" class="bg-white dark:bg-gray-800">Все категории</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(cat.name) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Направление бизнеса</label>
                        <select v-model="filtersForm.business_direction_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="" class="bg-white dark:bg-gray-800">Все направления</option>
                            <option v-for="dir in businessDirections" :key="dir.id" :value="dir.id" class="bg-white dark:bg-gray-800">{{ dir.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус</label>
                        <select v-model="filtersForm.is_active" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="" class="bg-white dark:bg-gray-800">Любой</option>
                            <option :value="1" class="bg-white dark:bg-gray-800">Активные</option>
                            <option :value="0" class="bg-white dark:bg-gray-800">Неактивные</option>
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

        <ColumnSettingsModal
            :show="isColumnsModalOpen"
            entity-type="service"
            :available-columns="availableColumns"
            :visible-columns="listView.visible_columns"
            @close="isColumnsModalOpen = false"
            @saved="isColumnsModalOpen = false"
        />

    </AuthenticatedLayout>
</template>