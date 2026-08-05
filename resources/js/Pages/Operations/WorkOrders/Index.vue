<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import PageHelper from '@/Components/PageHelper.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import BulkActions from '@/Components/BulkActions.vue';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    workOrders: Object,
    filters: Object,
    branches: Array,
    clients: Array,
    vehicles: Array,
    customFieldDefs: Array,
    availableColumns: Array,
    listView: Object,
});

const page = usePage();

const isModalOpen = ref(false);
const isOffcanvasOpen = ref(false);
const isColumnsModalOpen = ref(false);
const previewOrder = ref(null);
const editingOrder = ref(null);

const statuses = {
    'new': { label: 'Новый', class: 'bg-info/10 text-info' },
    'in_progress': { label: 'В работе', class: 'bg-warning/10 text-warning' },
    'ready': { label: 'Готов', class: 'bg-success/10 text-success' },
    'completed': { label: 'Выдан', class: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' },
    'canceled': { label: 'Отменен', class: 'bg-danger/10 text-danger' },
};

const paymentStatuses = {
    'unpaid': { label: 'Не оплачен', class: 'bg-danger/10 text-danger' },
    'partial': { label: 'Частично', class: 'bg-warning/10 text-warning' },
    'paid': { label: 'Оплачен', class: 'bg-success/10 text-success' },
};

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const initialFilters = {
    status: props.filters?.filters?.status || '',
    payment_status: props.filters?.filters?.payment_status || '',
    branch_id: props.filters?.filters?.branch_id || '',
    client_id: props.filters?.filters?.client_id || '',
};
props.customFieldDefs.filter(f => f.is_filterable).forEach(def => {
    initialFilters['cf_' + def.key] = props.filters?.filters?.['cf_' + def.key] || '';
});

const filtersForm = reactive(initialFilters);
const isFiltersOpen = ref(false);

const fetchFiltered = useDebounceFn(() => {
    router.get(route('operations.work-orders.index'), {
        search: search.value,
        filters: filtersForm,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
watch(filtersForm, () => fetchFiltered(), { deep: true });

const resetFilters = () => {
    Object.keys(filtersForm).forEach(key => {
        filtersForm[key] = '';
    });
};
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const selectAll = computed({
    get: () => props.workOrders.data.length > 0 && selectedIds.value.length === props.workOrders.data.length,
    set: (value) => {
        if (value) {
            selectedIds.value = props.workOrders.data.map(o => o.id);
        } else {
            selectedIds.value = [];
        }
    }
});

const bulkDelete = () => {
    if (confirm(`Удалить выбранные заказ-наряды (${selectedIds.value.length})? Это действие необратимо.`)) {
        router.post(route('operations.work-orders.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
                if (previewOrder.value && !props.workOrders.data.find(o => o.id === previewOrder.value.id)) {
                    closePreview();
                }
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('operations.work-orders.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `work_orders_export_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
    } catch (error) {
        console.error("Export failed", error);
        alert("Ошибка при экспорте данных");
    }
};
// ----------------------------------------

// --- ДИНАМИЧЕСКИЕ КОЛОНКИ ---
const activeColumns = computed(() => {
    const visibleKeys = props.listView?.visible_columns || [];
    return visibleKeys.map(key => props.availableColumns.find(c => c.key === key)).filter(Boolean);
});

const columnsForm = useForm({
    entity_type: 'work_order',
    visible_columns: [],
});

const openColumnsModal = () => {
    columnsForm.visible_columns = [...(props.listView?.visible_columns || [])];
    isColumnsModalOpen.value = true;
};

const closeColumnsModal = () => {
    isColumnsModalOpen.value = false;
};

const saveColumns = () => {
    columnsForm.post(route('list-views.store'), {
        onSuccess: () => closeColumnsModal(),
    });
};

const toggleColumn = (key) => {
    const index = columnsForm.visible_columns.indexOf(key);
    if (index > -1) {
        columnsForm.visible_columns.splice(index, 1);
    } else {
        columnsForm.visible_columns.push(key);
    }
};

const moveColumn = (index, direction) => {
    if (direction === 'up' && index > 0) {
        const temp = columnsForm.visible_columns[index];
        columnsForm.visible_columns[index] = columnsForm.visible_columns[index - 1];
        columnsForm.visible_columns[index - 1] = temp;
    } else if (direction === 'down' && index < columnsForm.visible_columns.length - 1) {
        const temp = columnsForm.visible_columns[index];
        columnsForm.visible_columns[index] = columnsForm.visible_columns[index + 1];
        columnsForm.visible_columns[index + 1] = temp;
    }
};
// ---------------------------

const form = useForm({
    branch_id: '',
    client_id: '',
    vehicle_id: '',
    status: 'new',
    mileage: '',
    custom_fields: {},
});

const filteredVehicles = computed(() => {
    if (!form.client_id) return [];
    return props.vehicles.filter(v => v.client_id === form.client_id);
});

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

const openPreview = (order) => {
    previewOrder.value = order;
    isOffcanvasOpen.value = true;
};

const closePreview = () => {
    isOffcanvasOpen.value = false;
    setTimeout(() => {
        previewOrder.value = null;
    }, 300);
};

const openModal = (order = null) => {
    if (isOffcanvasOpen.value) {
        isOffcanvasOpen.value = false;
    }

    editingOrder.value = order;
    
    if (order) {
        form.branch_id = order.branch_id;
        form.client_id = order.client_id;
        form.vehicle_id = order.vehicle_id || '';
        form.status = order.status;
        form.mileage = order.mileage || '';
        
        const cf = {};
        props.customFieldDefs.forEach(def => {
            cf[def.key] = order.custom_fields && order.custom_fields[def.key] !== undefined 
                ? order.custom_fields[def.key] 
                : (def.type === 'checkbox' ? false : '');
        });
        form.custom_fields = cf;
    } else {
        form.reset();
        form.branch_id = page.props.current_branch_id || (props.branches.length > 0 ? props.branches[0].id : '');
        form.status = 'new';
        
        const cf = {};
        props.customFieldDefs.forEach(def => {
            cf[def.key] = def.type === 'checkbox' ? false : '';
        });
        form.custom_fields = cf;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingOrder.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingOrder.value) {
        form.put(route('operations.work-orders.update', editingOrder.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('operations.work-orders.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteOrder = (order) => {
    if (confirm(`Удалить заказ-наряд #${String(order.id).padStart(6, '0')}?`)) {
        form.delete(route('operations.work-orders.destroy', order.id));
        if (previewOrder.value && previewOrder.value.id === order.id) {
            closePreview();
        }
    }
};
</script>

<template>
    <Head title="Заказ-наряды" />

    <AuthenticatedLayout>
        <template #header>
            Операции и Заказы
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <PageHelper title="Заказ-наряды (Work Orders)">
                <p>Это центральный документ системы. В заказ-наряде фиксируются оказанные услуги, использованные материалы, исполнители и расчеты с клиентом.</p>
            </PageHelper>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="заказов" 
                @export="bulkExport" 
                @delete="bulkDelete" 
            />

            <!-- Table Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="Object.values(filtersForm).some(v => v !== '' && v !== null)"
                    @open-filters="isFiltersOpen = true"
                    @open-columns="openColumnsModal"
                    placeholder="Поиск по номеру заказа..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Создать заказ
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
                                <th v-for="col in activeColumns" :key="col.key" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">
                                    {{ col.label }}
                                </th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="order in workOrders.data" :key="order.id" @click="openPreview(order)" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors cursor-pointer group">
                                
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center" @click.stop>
                                    <input type="checkbox" :value="order.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>

                                <td v-for="col in activeColumns" :key="col.key" class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    
                                    <template v-if="col.key === 'id'">
                                        <span class="font-mono font-semibold text-gray-800 dark:text-gray-200 group-hover:text-primary transition-colors">
                                            #{{ String(order.id).padStart(6, '0') }}
                                        </span>
                                    </template>

                                    <template v-else-if="col.key === 'created_at'">
                                        {{ new Date(order.created_at).toLocaleDateString('ru-RU') }}
                                    </template>

                                    <template v-else-if="col.key === 'client'">
                                        <Link v-if="order.client" :href="route('crm.clients.show', order.client.id)" class="inline-flex items-center gap-1.5 hover:text-primary transition-colors font-medium" @click.stop>
                                            <i class="ri-user-line text-gray-400"></i> {{ order.client.name }}
                                        </Link>
                                        <span v-else class="text-gray-400">—</span>
                                    </template>

                                    <template v-else-if="col.key === 'vehicle'">
                                        <Link v-if="order.vehicle" :href="route('crm.vehicles.show', order.vehicle.id)" class="inline-flex items-center gap-1.5 hover:text-primary transition-colors font-medium" @click.stop>
                                            <i class="ri-car-line text-gray-400"></i> {{ order.vehicle.make ? order.vehicle.make.name : '' }} {{ order.vehicle.vehicle_model ? order.vehicle.vehicle_model.name : '' }}
                                        </Link>
                                        <span v-else class="text-gray-400">—</span>
                                    </template>

                                    <template v-else-if="col.key === 'status'">
                                        <span :class="[statuses[order.status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium']">
                                            {{ statuses[order.status]?.label || order.status }}
                                        </span>
                                    </template>

                                    <template v-else-if="col.key === 'payment_status'">
                                        <span :class="[paymentStatuses[order.payment_status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium']">
                                            {{ paymentStatuses[order.payment_status]?.label || order.payment_status }}
                                        </span>
                                    </template>

                                    <template v-else-if="col.key === 'final_amount'">
                                        <span class="font-bold">{{ formatMoney(order.final_amount) }}</span>
                                    </template>

                                    <template v-else-if="col.key === 'branch'">
                                        <span class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300">
                                            <i class="ri-store-2-line"></i> {{ order.branch ? order.branch.name : '—' }}
                                        </span>
                                    </template>

                                    <template v-else-if="col.key === 'mileage'">
                                        {{ order.mileage ? order.mileage + ' км' : '—' }}
                                    </template>

                                    <template v-else>
                                        <!-- Кастомные поля -->
                                        {{ order.custom_fields && order.custom_fields[col.key] !== undefined && order.custom_fields[col.key] !== null && order.custom_fields[col.key] !== '' ? order.custom_fields[col.key] : '—' }}
                                    </template>

                                </td>

                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
                                    <Link 
                                        :href="route('operations.work-orders.show', order.id)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-info/10 text-info hover:bg-info hover:text-white"
                                        title="Открыть заказ"
                                        @click.stop
                                    >
                                        <i class="ri-folder-open-line"></i>
                                    </Link>
                                    <button 
                                        @click.stop="openModal(order)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                        title="Редактировать шапку"
                                    >
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button 
                                        @click.stop="deleteOrder(order)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                        title="Удалить"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="workOrders.data.length === 0">
                                <td :colspan="activeColumns.length + 2" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Заказ-наряды не найдены.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="workOrders" />
            </div>
        </div>

        <!-- Модальное окно настройки колонок -->
        <div v-if="isColumnsModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Настройка столбцов
                    </h3>
                    <button @click="closeColumnsModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <div class="p-6 space-y-4 max-h-[60vh] overflow-y-auto custom-scrollbar">
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Выберите столбцы для отображения и настройте их порядок.</p>
                    
                    <div class="space-y-2 mb-6">
                        <h4 class="text-xs font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider mb-2">Отображаемые столбцы</h4>
                        <div v-for="(key, index) in columnsForm.visible_columns" :key="key" class="flex items-center justify-between bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded px-3 py-2">
                            <div class="flex items-center gap-2">
                                <i class="ri-draggable text-gray-400"></i>
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ availableColumns.find(c => c.key === key)?.label || key }}
                                </span>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" @click="moveColumn(index, 'up')" :disabled="index === 0" class="text-gray-400 hover:text-primary disabled:opacity-30"><i class="ri-arrow-up-s-line text-lg"></i></button>
                                <button type="button" @click="moveColumn(index, 'down')" :disabled="index === columnsForm.visible_columns.length - 1" class="text-gray-400 hover:text-primary disabled:opacity-30"><i class="ri-arrow-down-s-line text-lg"></i></button>
                                <button type="button" @click="toggleColumn(key)" class="text-danger hover:text-danger/80 ml-2"><i class="ri-close-circle-line text-lg"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <h4 class="text-xs font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider mb-2">Доступные столбцы</h4>
                        <div class="grid grid-cols-1 gap-2">
                            <label v-for="col in availableColumns.filter(c => !columnsForm.visible_columns.includes(c.key))" :key="col.key" class="flex items-center cursor-pointer group p-2 hover:bg-gray-50 dark:hover:bg-gray-800/50 rounded border border-transparent hover:border-gray-200 dark:hover:border-gray-700 transition-colors">
                                <input type="checkbox" :checked="false" @change="toggleColumn(col.key)" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ col.label }}</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                    <button type="button" @click="closeColumnsModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                        Отмена
                    </button>
                    <button type="button" @click="saveColumns()" :disabled="columnsForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                        Сохранить вид
                    </button>
                </div>
            </div>
        </div>

        <!-- TRI-STATE 1: Offcanvas (Быстрый просмотр) -->
        <Offcanvas :show="isOffcanvasOpen" @close="closePreview" maxWidth="md">
            <div class="flex flex-col h-full" v-if="previewOrder">
                <!-- Offcanvas Header -->
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-start bg-gray-50/50 dark:bg-gray-800/30">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded bg-primary/10 flex items-center justify-center text-primary font-bold text-xl shrink-0">
                            <i class="ri-briefcase-line"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight">
                                Заказ #{{ String(previewOrder.id).padStart(6, '0') }}
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mt-0.5">
                                от {{ new Date(previewOrder.created_at).toLocaleDateString('ru-RU') }}
                            </p>
                        </div>
                    </div>
                    <button @click="closePreview" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-lg"></i>
                    </button>
                </div>

                <!-- Offcanvas Body -->
                <div class="flex-1 overflow-y-auto p-6 space-y-6">
                    
                    <div class="flex flex-wrap gap-2">
                        <span :class="[statuses[previewOrder.status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold tracking-wide uppercase']">
                            {{ statuses[previewOrder.status]?.label || previewOrder.status }}
                        </span>
                        <span :class="[paymentStatuses[previewOrder.payment_status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold tracking-wide uppercase']">
                            {{ paymentStatuses[previewOrder.payment_status]?.label || previewOrder.payment_status }}
                        </span>
                    </div>

                    <!-- Клиент и Авто -->
                    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 border border-gray-100 dark:border-gray-700/50 space-y-4">
                        <div v-if="previewOrder.client">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Клиент</p>
                            <Link :href="route('crm.clients.show', previewOrder.client.id)" class="text-sm font-bold text-primary hover:text-primary-600 transition-colors flex items-center gap-2">
                                <i class="ri-user-line"></i> {{ previewOrder.client.name }}
                            </Link>
                        </div>
                        <div v-if="previewOrder.vehicle">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Автомобиль</p>
                            <Link :href="route('crm.vehicles.show', previewOrder.vehicle.id)" class="text-sm font-bold text-primary hover:text-primary-600 transition-colors flex items-center gap-2">
                                <i class="ri-car-line"></i> {{ previewOrder.vehicle.make ? previewOrder.vehicle.make.name : '' }} {{ previewOrder.vehicle.vehicle_model ? previewOrder.vehicle.vehicle_model.name : '' }}
                            </Link>
                            <p class="text-xs text-gray-500 mt-1 ml-6">{{ previewOrder.vehicle.plate_number || 'Госномер не указан' }}</p>
                        </div>
                    </div>

                    <!-- Финансы -->
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3 border border-gray-100 dark:border-gray-700/50">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Сумма заказа</p>
                            <p class="text-lg font-bold text-gray-800 dark:text-gray-200">
                                {{ formatMoney(previewOrder.total_amount) }}
                            </p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3 border border-gray-100 dark:border-gray-700/50">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">К оплате</p>
                            <p class="text-lg font-bold text-success">
                                {{ formatMoney(previewOrder.final_amount) }}
                            </p>
                        </div>
                    </div>

                    <!-- Кастомные поля (если есть) -->
                    <div v-if="customFieldDefs.length > 0" class="space-y-3">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Дополнительно</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div v-for="def in customFieldDefs" :key="def.id">
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ getLocalizedLabel(def.label) }}</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                    <template v-if="def.type === 'checkbox'">
                                        {{ previewOrder.custom_fields && previewOrder.custom_fields[def.key] == '1' ? 'Да' : 'Нет' }}
                                    </template>
                                    <template v-else>
                                        {{ previewOrder.custom_fields && previewOrder.custom_fields[def.key] ? previewOrder.custom_fields[def.key] : '—' }}
                                    </template>
                                </p>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Offcanvas Footer (Действия) -->
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/80 flex justify-between gap-3">
                    <button 
                        @click="openModal(previewOrder)" 
                        class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm"
                    >
                        <i class="ri-pencil-line mr-2"></i> Шапка
                    </button>
                    <Link 
                        :href="route('operations.work-orders.show', previewOrder.id)" 
                        class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 shadow-sm"
                    >
                        Открыть заказ <i class="ri-arrow-right-line ml-2"></i>
                    </Link>
                </div>
            </div>
        </Offcanvas>

        <!-- TRI-STATE 3: Форма редактирования (Focused Modal) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingOrder ? 'Редактирование шапки заказа' : 'Создание заказ-наряда' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-5">
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Филиал <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.branch_id" 
                                    required
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите филиал...</option>
                                    <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                                </select>
                                <span v-if="form.errors.branch_id" class="text-xs text-danger mt-1">{{ form.errors.branch_id }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.status" 
                                    required
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option v-for="(status, key) in statuses" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ status.label }}</option>
                                </select>
                                <span v-if="form.errors.status" class="text-xs text-danger mt-1">{{ form.errors.status }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Клиент <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.client_id" 
                                    @change="form.vehicle_id = ''"
                                    required
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите клиента...</option>
                                    <option v-for="client in clients" :key="client.id" :value="client.id" class="bg-white dark:bg-gray-800">
                                        {{ client.name }} {{ client.phone ? `(${client.phone})` : '' }}
                                    </option>
                                </select>
                                <span v-if="form.errors.client_id" class="text-xs text-danger mt-1">{{ form.errors.client_id }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Автомобиль</label>
                                <select 
                                    v-model="form.vehicle_id" 
                                    :disabled="!form.client_id"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:bg-gray-100 dark:disabled:bg-gray-800 disabled:text-gray-400"
                                >
                                    <option value="" class="bg-white dark:bg-gray-800">Без автомобиля</option>
                                    <option v-for="vehicle in filteredVehicles" :key="vehicle.id" :value="vehicle.id" class="bg-white dark:bg-gray-800">
                                        {{ vehicle.make ? vehicle.make.name : '' }} {{ vehicle.vehicleModel ? vehicle.vehicleModel.name : '' }} {{ vehicle.plate_number ? `[${vehicle.plate_number}]` : '' }}
                                    </option>
                                </select>
                                <span v-if="form.errors.vehicle_id" class="text-xs text-danger mt-1">{{ form.errors.vehicle_id }}</span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Пробег (км)</label>
                            <input 
                                v-model="form.mileage" 
                                type="number" 
                                min="0"
                                placeholder="Например: 150000" 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                            />
                        </div>

                        <!-- Кастомные поля (EAV) -->
                        <div v-if="customFieldDefs.length > 0" class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-4 space-y-4">
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-2">Дополнительные поля</h4>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div v-for="def in customFieldDefs" :key="def.id">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                        {{ getLocalizedLabel(def.label) }} <span v-if="def.is_required" class="text-danger">*</span>
                                    </label>
                                    
                                    <template v-if="def.type === 'text'">
                                        <input type="text" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                    </template>
                                    
                                    <template v-else-if="def.type === 'number'">
                                        <input type="number" step="any" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                    </template>
                                    
                                    <template v-else-if="def.type === 'date'">
                                        <input type="date" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                    </template>
                                    
                                    <template v-else-if="def.type === 'select'">
                                        <select v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                            <option value="" disabled class="bg-white dark:bg-gray-800">Выберите...</option>
                                            <option v-for="opt in def.options" :key="opt" :value="opt" class="bg-white dark:bg-gray-800">{{ opt }}</option>
                                        </select>
                                    </template>
                                    
                                    <template v-else-if="def.type === 'checkbox'">
                                        <div class="flex items-center pt-2">
                                            <div @click="form.custom_fields[def.key] = !form.custom_fields[def.key]" :class="[form.custom_fields[def.key] ? 'bg-primary' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                                <div :class="[form.custom_fields[def.key] ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
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
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус заказа</label>
                        <select v-model="filtersForm.status" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все статусы</option>
                            <option v-for="(status, key) in statuses" :key="key" :value="key">{{ status.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус оплаты</label>
                        <select v-model="filtersForm.payment_status" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все статусы</option>
                            <option v-for="(status, key) in paymentStatuses" :key="key" :value="key">{{ status.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Филиал</label>
                        <select v-model="filtersForm.branch_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все филиалы</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </div>
                    
                    <!-- Кастомные фильтры -->
                    <template v-for="def in customFieldDefs.filter(f => f.is_filterable)" :key="def.id">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">{{ getLocalizedLabel(def.label) }}</label>
                            <template v-if="def.type === 'select'">
                                <select v-model="filtersForm['cf_' + def.key]" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                    <option value="">Все</option>
                                    <option v-for="opt in def.options" :key="opt" :value="opt">{{ opt }}</option>
                                </select>
                            </template>
                            <template v-else-if="def.type === 'checkbox'">
                                <select v-model="filtersForm['cf_' + def.key]" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                    <option value="">Все</option>
                                    <option value="1">Да</option>
                                    <option value="0">Нет</option>
                                </select>
                            </template>
                            <template v-else>
                                <input :type="def.type === 'number' ? 'number' : (def.type === 'date' ? 'date' : 'text')" v-model="filtersForm['cf_' + def.key]" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                            </template>
                        </div>
                    </template>
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

    </AuthenticatedLayout>
</template>