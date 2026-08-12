<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import WarehouseNav from '@/Components/WarehouseNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import ColumnSettingsModal from '@/Components/ColumnSettingsModal.vue';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    movements: Object,
    warehouses: Array,
    branches: Array,
    products: Array,
    filters: Object,
    availableColumns: { type: Array, default: () => [] },
    listView: { type: Object, default: () => ({ visible_columns: [] }) },
});

const page = usePage();

const isModalOpen = ref(false);
const isColumnsModalOpen = ref(false);

const activeColumns = computed(() => {
    return props.listView.visible_columns
        .map(key => props.availableColumns.find(c => c.key === key))
        .filter(Boolean);
});

// Форма оприходования (Receipt)
const form = useForm({
    warehouse_id: '',
    branch_id: page.props.current_branch_id || (props.branches.length > 0 ? props.branches[0].id : ''),
    items: [
        { product_id: '', quantity: 1, cost_price: 0 }
    ],
});

const addItem = () => {
    form.items.push({ product_id: '', quantity: 1, cost_price: 0 });
};

const removeItem = (index) => {
    if (form.items.length > 1) {
        form.items.splice(index, 1);
    }
};

const openModal = () => {
    form.reset();
    form.branch_id = page.props.current_branch_id || (props.branches.length > 0 ? props.branches[0].id : '');
    form.items = [{ product_id: '', quantity: 1, cost_price: 0 }];
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    form.post(route('warehouse.movements.receipt'), {
        onSuccess: () => closeModal(),
    });
};

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

const resetFilters = () => {
    filtersForm.warehouse_id = '';
    filtersForm.branch_id = '';
    filtersForm.type = '';
};
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const selectAll = computed({
    get: () => props.movements.data.length > 0 && selectedIds.value.length === props.movements.data.length,
    set: (value) => {
        if (value) {
            selectedIds.value = props.movements.data.map(m => m.id);
        } else {
            selectedIds.value = [];
        }
    }
});

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

            <PageHelper title="Движения и Оприходование">
                <p>Здесь фиксируется вся история изменения остатков на складах: приходы (закупки), расходы (списания в заказ-наряды) и перемещения.</p>
                <p>Чтобы добавить товар на склад, нажмите кнопку <strong>«Оприходовать товар»</strong>. Система автоматически пересчитает среднюю себестоимость или создаст новую партию в зависимости от настроек товара.</p>
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
                @delete="() => {}" 
            />

            <!-- Table Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="Object.values(filtersForm).some(v => v !== '' && v !== null)"
                    @open-filters="isFiltersOpen = true"
                    @open-columns="isColumnsModalOpen = true"
                    placeholder="Поиск по названию товара или номеру заказа..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-download-line text-base"></i>
                            Оприходовать товар
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
                                <th v-for="col in activeColumns" :key="col.key" :class="['py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700', ['quantity','cost_price'].includes(col.key) ? 'text-right' : '']">{{ col.label }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="movement in movements.data" :key="movement.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center">
                                    <input type="checkbox" :value="movement.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>
                                <td v-for="col in activeColumns" :key="col.key" :class="['py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50', ['quantity','cost_price'].includes(col.key) ? 'text-right' : '']">
                                    <template v-if="col.key === 'date'">
                                        {{ new Date(movement.created_at).toLocaleString('ru-RU', {day: 'numeric', month: 'short', hour: '2-digit', minute:'2-digit'}) }}
                                    </template>
                                    <template v-else-if="col.key === 'type'">
                                        <span :class="[movementTypes[movement.type]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                            <i :class="movementTypes[movement.type]?.icon"></i> {{ movementTypes[movement.type]?.label || movement.type }}
                                        </span>
                                    </template>
                                    <template v-else-if="col.key === 'warehouse_branch'">
                                        <div class="font-medium"><i class="ri-building-4-line text-gray-400"></i> {{ movement.warehouse ? movement.warehouse.name : '—' }}</div>
                                        <div class="text-xs text-gray-500 mt-0.5"><i class="ri-store-2-line"></i> {{ movement.branch ? movement.branch.name : '—' }}</div>
                                    </template>
                                    <template v-else-if="col.key === 'product'">
                                        <div class="font-bold text-gray-800 dark:text-gray-200">{{ movement.product ? getLocalizedLabel(movement.product.name) : '—' }}</div>
                                        <div v-if="movement.batch" class="text-xs text-warning mt-0.5">Партия #{{ movement.batch.id }}</div>
                                    </template>
                                    <template v-else-if="col.key === 'quantity'">
                                        <span :class="[movement.type === 'in' ? 'text-success' : 'text-danger', 'font-bold']">
                                            {{ movement.type === 'in' ? '+' : '-' }}{{ parseFloat(movement.quantity) }} {{ movement.product ? movement.product.unit : '' }}
                                        </span>
                                    </template>
                                    <template v-else-if="col.key === 'cost_price'">
                                        {{ formatMoney(movement.cost_price) }}
                                    </template>
                                    <template v-else-if="col.key === 'reason'">
                                        <Link v-if="movement.workOrder" :href="route('operations.work-orders.show', movement.workOrder.id)" class="text-primary hover:underline font-medium">
                                            Заказ #{{ String(movement.workOrder.id).padStart(6, '0') }}
                                        </Link>
                                        <span v-else class="text-xs text-gray-500">{{ movement.comment || 'Ручная операция' }}</span>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="movements.data.length === 0">
                                <td :colspan="activeColumns.length + 1" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Движения не найдены.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="movements" />
            </div>
        </div>

        <!-- Модальное окно Оприходования -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-4xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Оприходование товаров
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-6">
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Склад получатель <span class="text-danger">*</span></label>
                                <select v-model="form.warehouse_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите склад...</option>
                                    <option v-for="wh in warehouses" :key="wh.id" :value="wh.id" class="bg-white dark:bg-gray-800">{{ wh.name }}</option>
                                </select>
                                <span v-if="form.errors.warehouse_id" class="text-xs text-danger mt-1">{{ form.errors.warehouse_id }}</span>
                            </div>
                            <div v-if="branches.length > 1">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация инициатор <span class="text-danger">*</span></label>
                                <select v-model="form.branch_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите локацию...</option>
                                    <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                                </select>
                                <span v-if="form.errors.branch_id" class="text-xs text-danger mt-1">{{ form.errors.branch_id }}</span>
                            </div>
                        </div>

                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                            <div class="flex justify-between items-center mb-3">
                                <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200">Позиции к оприходованию</h4>
                                <button type="button" @click="addItem" class="text-sm text-primary hover:text-primary-600 font-medium flex items-center gap-1">
                                    <i class="ri-add-line"></i> Добавить строку
                                </button>
                            </div>

                            <div class="space-y-3">
                                <div v-for="(item, index) in form.items" :key="index" class="flex gap-3 items-start bg-gray-50/50 dark:bg-gray-800/30 p-3 rounded-md border border-gray-200 dark:border-gray-700">
                                    <div class="flex-1">
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Товар <span class="text-danger">*</span></label>
                                        <select v-model="item.product_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46] py-1.5 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                            <option value="" disabled>Выберите товар...</option>
                                            <option v-for="p in products" :key="p.id" :value="p.id">
                                                {{ getLocalizedLabel(p.name) }} ({{ p.accounting_type === 'batch' ? 'Партионный' : 'Средневзвешенный' }})
                                            </option>
                                        </select>
                                        <span v-if="form.errors[`items.${index}.product_id`]" class="text-xs text-danger mt-1">{{ form.errors[`items.${index}.product_id`] }}</span>
                                    </div>
                                    <div class="w-32">
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Кол-во <span class="text-danger">*</span></label>
                                        <input v-model="item.quantity" type="number" step="any" min="0.001" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46] py-1.5 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                        <span v-if="form.errors[`items.${index}.quantity`]" class="text-xs text-danger mt-1">{{ form.errors[`items.${index}.quantity`] }}</span>
                                    </div>
                                    <div class="w-40">
                                        <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Цена закупки (₽) <span class="text-danger">*</span></label>
                                        <input v-model="item.cost_price" type="number" step="0.01" min="0" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46] py-1.5 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                        <span v-if="form.errors[`items.${index}.cost_price`]" class="text-xs text-danger mt-1">{{ form.errors[`items.${index}.cost_price`] }}</span>
                                    </div>
                                    <div class="pt-6">
                                        <button type="button" @click="removeItem(index)" :disabled="form.items.length === 1" class="text-danger hover:text-danger-600 disabled:opacity-30 p-1">
                                            <i class="ri-delete-bin-line text-lg"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            <i class="ri-download-line mr-2"></i> Оприходовать
                        </button>
                    </div>
                </form>
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