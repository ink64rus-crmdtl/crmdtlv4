<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import OperationsNav from '@/Components/OperationsNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import Modal from '@/Components/Modal.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
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
});

const isModalOpen = ref(false);
const isCategoryModalOpen = ref(false);
const editingService = ref(null);

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

const fetchFiltered = useDebounceFn(() => {
    router.get(route('operations.services.index'), {
        search: search.value,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());

const { sort, onSort } = useServerSort('operations.services.index', () => props.filters, () => ({ search: search.value }));

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
const flatColumns = [
    { key: 'category', label: 'Категория' },
    { key: 'business_direction', label: 'Направление' },
    { key: 'name', label: 'Название услуги' },
    { key: 'price', label: 'Базовая цена', align: 'right', sortable: true },
    { key: 'duration_minutes', label: 'Нормо-время', align: 'right', sortable: true },
    { key: 'status', label: 'Статус', sortable: true, sortKey: 'is_active' },
];

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
    categoryForm.reset();
    categoryForm.clearErrors();
};

const submitCategory = () => {
    categoryForm.post(route('operations.service-categories.store'), {
        onSuccess: () => closeCategoryModal(),
    });
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
                    :has-filters="false"
                    placeholder="Поиск по названию услуги..."
                >
                    <template #actions>
                        <button
                            @click="openCategoryModal()"
                            class="hidden sm:inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all bg-secondary/10 text-secondary hover:bg-secondary hover:text-white shadow-sm"
                        >
                            <i class="ri-folder-add-line mr-1.5"></i> Новая категория
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
                        :columns="flatColumns"
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
                    <table class="min-w-full text-left whitespace-nowrap">
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
                                <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50">
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
                            
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
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
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary rounded">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="px-4 py-2 text-sm font-medium bg-primary text-white rounded">Сохранить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Модалка Категории -->
        <Modal :show="isCategoryModalOpen" @close="closeCategoryModal">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Новая категория услуг</h3>
                    <button @click="closeCategoryModal()" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
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
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50">
                        <button type="button" @click="closeCategoryModal()" class="px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary rounded">Отмена</button>
                        <button type="submit" :disabled="categoryForm.processing" class="px-4 py-2 text-sm font-medium bg-primary text-white rounded">Создать</button>
                    </div>
                </form>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>