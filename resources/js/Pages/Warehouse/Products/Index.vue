<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import WarehouseNav from '@/Components/WarehouseNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import ColumnSettingsModal from '@/Components/ColumnSettingsModal.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    products: Object,
    categories: Array,
    warehouses: Array,
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

const form = useForm({
    product_category_id: '',
    name: '',
    sku: '',
    unit: 'шт',
    accounting_type: 'average',
    preferred_warehouse_id: '',
    is_active: true,
});

const categoryForm = useForm({
    name: '',
});

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const fetchFiltered = useDebounceFn(() => {
    router.get(route('warehouse.products.index'), {
        search: search.value,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const selectAll = computed({
    get: () => props.products.data.length > 0 && selectedIds.value.length === props.products.data.length,
    set: (value) => {
        if (value) {
            selectedIds.value = props.products.data.map(p => p.id);
        } else {
            selectedIds.value = [];
        }
    }
});

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
    } else {
        form.reset();
        form.is_active = true;
        form.unit = 'шт';
        form.accounting_type = 'average';
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
                    :has-filters="false"
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
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-4 w-10 border-b border-gray-200 dark:border-gray-700 text-center">
                                    <input type="checkbox" v-model="selectAll" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </th>
                                <th v-for="col in activeColumns" :key="col.key" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">{{ col.label }}</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="product in products.data" :key="product.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center">
                                    <input type="checkbox" :value="product.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>
                                <td v-for="col in activeColumns" :key="col.key" class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <template v-if="col.key === 'category'">
                                        <span v-if="product.category" class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300">
                                            <i class="ri-folder-line"></i> {{ getLocalizedLabel(product.category.name) }}
                                        </span>
                                        <span v-else class="text-gray-400 text-xs">—</span>
                                    </template>
                                    <template v-else-if="col.key === 'sku'">
                                        <span class="font-mono text-xs">{{ product.sku || '—' }}</span>
                                    </template>
                                    <template v-else-if="col.key === 'name'">
                                        <span class="font-bold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(product.name) }}</span>
                                    </template>
                                    <template v-else-if="col.key === 'unit'">
                                        {{ product.unit }}
                                    </template>
                                    <template v-else-if="col.key === 'accounting_type'">
                                        <span v-if="product.accounting_type === 'average'" class="inline-flex items-center gap-1 text-xs font-medium text-info"><i class="ri-scales-3-line"></i> Средневзвешенный</span>
                                        <span v-else class="inline-flex items-center gap-1 text-xs font-medium text-warning"><i class="ri-stack-line"></i> Партионный (FIFO)</span>
                                    </template>
                                    <template v-else-if="col.key === 'status'">
                                        <span :class="[product.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                            {{ product.is_active ? 'Активно' : 'Неактивно' }}
                                        </span>
                                    </template>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
                                    <button @click="openModal(product)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать">
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button @click="deleteProduct(product)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="products.data.length === 0">
                                <td :colspan="activeColumns.length + 2" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Товары не найдены.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="products" />
            </div>
        </div>

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

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Привязка к складу (Для Смешанного режима)</label>
                            <select v-model="form.preferred_warehouse_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                <option value="" class="bg-white dark:bg-gray-800">Определять автоматически по филиалу</option>
                                <option v-for="wh in warehouses" :key="wh.id" :value="wh.id" class="bg-white dark:bg-gray-800">Всегда списывать с: {{ wh.name }}</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Используется только если в настройках включен "Смешанный" режим склада.</p>
                        </div>

                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Товар активен
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