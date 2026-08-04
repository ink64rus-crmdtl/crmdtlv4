<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import PageHelper from '@/Components/PageHelper.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import BulkActions from '@/Components/BulkActions.vue';
import { Head, useForm, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    vehicles: Object,
    filters: Object,
    clients: Array,
    makes: Array,
    models: Array,
    strictPlateValidation: Boolean,
    tenantCountry: String,
    customFieldDefs: Array,
    availableColumns: Array,
    listView: Object,
});

const isModalOpen = ref(false);
const isOffcanvasOpen = ref(false);
const isColumnsModalOpen = ref(false);
const previewVehicle = ref(null);
const editingVehicle = ref(null);

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const initialFilters = {
    vehicle_make_id: props.filters?.filters?.vehicle_make_id || '',
    vehicle_model_id: props.filters?.filters?.vehicle_model_id || '',
    year: props.filters?.filters?.year || '',
};
props.customFieldDefs.filter(f => f.is_filterable).forEach(def => {
    initialFilters['cf_' + def.key] = props.filters?.filters?.['cf_' + def.key] || '';
});

const filtersForm = reactive(initialFilters);
const isFiltersOpen = ref(false);

// Фильтрация моделей для фильтра
const filterModels = computed(() => {
    if (!filtersForm.vehicle_make_id) return [];
    return props.models.filter(m => m.vehicle_make_id == filtersForm.vehicle_make_id);
});

const fetchFiltered = useDebounceFn(() => {
    router.get(route('crm.vehicles.index'), {
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
    get: () => props.vehicles.data.length > 0 && selectedIds.value.length === props.vehicles.data.length,
    set: (value) => {
        if (value) {
            selectedIds.value = props.vehicles.data.map(v => v.id);
        } else {
            selectedIds.value = [];
        }
    }
});

const bulkDelete = () => {
    if (confirm(`Удалить выбранные автомобили (${selectedIds.value.length})?`)) {
        router.post(route('crm.vehicles.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
                if (previewVehicle.value && !props.vehicles.data.find(v => v.id === previewVehicle.value.id)) {
                    closePreview();
                }
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('crm.vehicles.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `vehicles_export_${new Date().toISOString().slice(0,10)}.csv`);
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
    entity_type: 'vehicle',
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
    client_id: '',
    vehicle_make_id: '',
    vehicle_model_id: '',
    plate_number: '',
    vin: '',
    year: '',
    custom_fields: {},
});

// Фильтрация моделей по выбранной марке
const filteredModels = computed(() => {
    if (!form.vehicle_make_id) return [];
    return props.models.filter(m => m.vehicle_make_id === form.vehicle_make_id);
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

const openPreview = (vehicle) => {
    previewVehicle.value = vehicle;
    isOffcanvasOpen.value = true;
};

const closePreview = () => {
    isOffcanvasOpen.value = false;
    setTimeout(() => {
        previewVehicle.value = null;
    }, 300);
};

const openModal = (vehicle = null) => {
    if (isOffcanvasOpen.value) {
        isOffcanvasOpen.value = false;
    }

    editingVehicle.value = vehicle;
    
    if (vehicle) {
        form.client_id = vehicle.client_id;
        form.vehicle_make_id = vehicle.vehicle_make_id || '';
        form.vehicle_model_id = vehicle.vehicle_model_id || '';
        form.plate_number = vehicle.plate_number || '';
        form.vin = vehicle.vin || '';
        form.year = vehicle.year || '';
        
        const cf = {};
        props.customFieldDefs.forEach(def => {
            cf[def.key] = vehicle.custom_fields && vehicle.custom_fields[def.key] !== undefined 
                ? vehicle.custom_fields[def.key] 
                : (def.type === 'checkbox' ? false : '');
        });
        form.custom_fields = cf;
    } else {
        form.reset();
        
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
    editingVehicle.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingVehicle.value) {
        form.put(route('crm.vehicles.update', editingVehicle.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('crm.vehicles.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteVehicle = (vehicle) => {
    if (confirm(`Удалить автомобиль "${vehicle.make?.name} ${vehicle.vehicleModel?.name}"?`)) {
        form.delete(route('crm.vehicles.destroy', vehicle.id));
        if (previewVehicle.value && previewVehicle.value.id === vehicle.id) {
            closePreview();
        }
    }
};
</script>

<template>
    <Head title="Автомобили" />

    <AuthenticatedLayout>
        <template #header>
            Клиенты и Автомобили
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <!-- Навигация по CRM (Attex Tabs) -->
            <div class="border-b border-gray-200 dark:border-gray-700 mb-6">
                <nav class="-mb-px flex space-x-8 overflow-x-auto">
                    <Link
                        :href="route('crm.clients.index')"
                        :class="[
                            route().current('crm.clients.*')
                                ? 'border-primary text-primary'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600',
                            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors'
                        ]"
                    >
                        Клиенты
                    </Link>
                    <Link
                        :href="route('crm.vehicles.index')"
                        :class="[
                            route().current('crm.vehicles.*')
                                ? 'border-primary text-primary'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600',
                            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors'
                        ]"
                    >
                        Автомобили
                    </Link>
                </nav>
            </div>

            <PageHelper title="База автомобилей">
                <p>Здесь отображаются все автомобили, привязанные к вашим клиентам. Автомобили фильтруются автоматически: вы видите только те машины, владельцы которых обслуживаются в доступных вам филиалах.</p>
                <p v-if="strictPlateValidation" class="text-xs mt-2 opacity-80"><i class="ri-shield-check-fill text-success"></i> Включена строгая проверка госномеров (маска {{ tenantCountry }}).</p>
            </PageHelper>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="автомобилей" 
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
                    placeholder="Поиск по госномеру, VIN..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-car-line text-base"></i>
                            Добавить авто
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
                            <tr v-for="vehicle in vehicles.data" :key="vehicle.id" @click="openPreview(vehicle)" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors cursor-pointer group">
                                
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center" @click.stop>
                                    <input type="checkbox" :value="vehicle.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>

                                <td v-for="col in activeColumns" :key="col.key" class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    
                                    <template v-if="col.key === 'vehicle_info'">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-gray-500 dark:text-gray-400 shrink-0">
                                                <i class="ri-car-line"></i>
                                            </div>
                                            <div>
                                                <div class="font-semibold group-hover:text-primary transition-colors">
                                                    {{ vehicle.make ? vehicle.make.name : '' }} {{ vehicle.vehicle_model ? vehicle.vehicle_model.name : '' }}
                                                </div>
                                                <div class="text-xs text-gray-500 font-normal mt-0.5">{{ vehicle.year ? vehicle.year + ' г.в.' : '' }}</div>
                                            </div>
                                        </div>
                                    </template>

                                    <template v-else-if="col.key === 'client'">
                                        <Link v-if="vehicle.client" :href="route('crm.clients.show', vehicle.client.id)" class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300 hover:text-primary dark:hover:text-primary transition-colors" @click.stop>
                                            <i class="ri-user-line"></i> {{ vehicle.client.name }}
                                        </Link>
                                        <span v-else class="text-gray-400">—</span>
                                    </template>

                                    <template v-else-if="col.key === 'plate_number'">
                                        <span class="inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-bold tracking-wide uppercase bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600">
                                            {{ vehicle.plate_number || '—' }}
                                        </span>
                                    </template>

                                    <template v-else-if="col.key === 'vin'">
                                        <span class="font-mono text-xs">{{ vehicle.vin || '—' }}</span>
                                    </template>

                                    <template v-else-if="col.key === 'year'">
                                        {{ vehicle.year || '—' }}
                                    </template>

                                    <template v-else>
                                        <!-- Кастомные поля -->
                                        {{ vehicle.custom_fields && vehicle.custom_fields[col.key] !== undefined && vehicle.custom_fields[col.key] !== null && vehicle.custom_fields[col.key] !== '' ? vehicle.custom_fields[col.key] : '—' }}
                                    </template>

                                </td>

                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
                                    <Link 
                                        :href="route('crm.vehicles.show', vehicle.id)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-info/10 text-info hover:bg-info hover:text-white"
                                        title="Полная карточка"
                                        @click.stop
                                    >
                                        <i class="ri-eye-line"></i>
                                    </Link>
                                    <button 
                                        @click.stop="openModal(vehicle)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                        title="Редактировать форму"
                                    >
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button 
                                        @click.stop="deleteVehicle(vehicle)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                        title="Удалить"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="vehicles.data.length === 0">
                                <td :colspan="activeColumns.length + 2" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Автомобили не найдены.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="vehicles" />
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
            <div class="flex flex-col h-full" v-if="previewVehicle">
                <!-- Offcanvas Header -->
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-start bg-gray-50/50 dark:bg-gray-800/30">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded bg-primary/10 flex items-center justify-center text-primary font-bold text-xl shrink-0">
                            <i class="ri-car-line"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight">
                                {{ previewVehicle.make ? previewVehicle.make.name : '' }} {{ previewVehicle.vehicle_model ? previewVehicle.vehicle_model.name : '' }}
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mt-0.5">
                                {{ previewVehicle.year ? previewVehicle.year + ' г.в.' : 'Год не указан' }}
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
                        <span class="inline-flex items-center gap-1.5 py-1 px-2.5 rounded-md text-xs font-bold tracking-wide uppercase bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600">
                            {{ previewVehicle.plate_number || 'Госномер не указан' }}
                        </span>
                        <span v-if="previewVehicle.vin" class="inline-flex items-center gap-1.5 py-1 px-2.5 rounded-md text-xs font-bold tracking-wide uppercase bg-info/10 text-info">
                            VIN: {{ previewVehicle.vin }}
                        </span>
                    </div>

                    <!-- Владелец -->
                    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 border border-gray-100 dark:border-gray-700/50 space-y-4">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Владелец</h3>
                        <div v-if="previewVehicle.client">
                            <Link :href="route('crm.clients.show', previewVehicle.client.id)" class="text-sm font-bold text-primary hover:text-primary-600 transition-colors flex items-center gap-2 mb-2">
                                <i class="ri-user-line"></i> {{ previewVehicle.client.name }}
                            </Link>
                            <a v-if="previewVehicle.client.phone" :href="'tel:' + previewVehicle.client.phone" class="text-sm font-medium text-gray-600 dark:text-gray-400 hover:text-primary transition-colors flex items-center gap-2">
                                <i class="ri-phone-line text-gray-400"></i> {{ previewVehicle.client.phone }}
                            </a>
                        </div>
                        <div v-else class="text-sm text-gray-500">Владелец не указан</div>
                    </div>

                    <!-- Кастомные поля (если есть) -->
                    <div v-if="customFieldDefs.length > 0" class="space-y-3">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Дополнительная информация</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div v-for="def in customFieldDefs" :key="def.id">
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ getLocalizedLabel(def.label) }}</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                    <template v-if="def.type === 'checkbox'">
                                        {{ previewVehicle.custom_fields && previewVehicle.custom_fields[def.key] == '1' ? 'Да' : 'Нет' }}
                                    </template>
                                    <template v-else>
                                        {{ previewVehicle.custom_fields && previewVehicle.custom_fields[def.key] ? previewVehicle.custom_fields[def.key] : '—' }}
                                    </template>
                                </p>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Offcanvas Footer (Действия) -->
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/80 flex justify-between gap-3">
                    <button 
                        @click="openModal(previewVehicle)" 
                        class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm"
                    >
                        <i class="ri-pencil-line mr-2"></i> Редактировать
                    </button>
                    <Link 
                        :href="route('crm.vehicles.show', previewVehicle.id)" 
                        class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 shadow-sm"
                    >
                        Полная карточка <i class="ri-arrow-right-line ml-2"></i>
                    </Link>
                </div>
            </div>
        </Offcanvas>

        <!-- TRI-STATE 3: Форма редактирования (Focused Modal) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingVehicle ? 'Редактирование автомобиля' : 'Добавление автомобиля' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-5">
                        
                        <div v-if="form.errors.plate_number" class="p-3 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-center gap-2">
                            <i class="ri-error-warning-line text-lg"></i> {{ form.errors.plate_number }}
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Владелец (Клиент) <span class="text-danger">*</span></label>
                            <select 
                                v-model="form.client_id" 
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

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Марка <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.vehicle_make_id" 
                                    @change="form.vehicle_model_id = ''"
                                    required
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите марку...</option>
                                    <option v-for="make in makes" :key="make.id" :value="make.id" class="bg-white dark:bg-gray-800">{{ make.name }}</option>
                                </select>
                                <span v-if="form.errors.vehicle_make_id" class="text-xs text-danger mt-1">{{ form.errors.vehicle_make_id }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Модель <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.vehicle_model_id" 
                                    required
                                    :disabled="!form.vehicle_make_id"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:bg-gray-100 dark:disabled:bg-gray-800 disabled:text-gray-400"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите модель...</option>
                                    <option v-for="model in filteredModels" :key="model.id" :value="model.id" class="bg-white dark:bg-gray-800">{{ model.name }}</option>
                                </select>
                                <span v-if="form.errors.vehicle_model_id" class="text-xs text-danger mt-1">{{ form.errors.vehicle_model_id }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Госномер</label>
                                <input 
                                    v-model="form.plate_number" 
                                    type="text" 
                                    :placeholder="strictPlateValidation ? (tenantCountry === 'RU' ? 'А 000 АА 77' : '0000 AA-7') : 'Любой формат'" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500 uppercase" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">VIN код</label>
                                <input 
                                    v-model="form.vin" 
                                    type="text" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 font-mono uppercase" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Год выпуска</label>
                                <input 
                                    v-model="form.year" 
                                    type="number" 
                                    min="1900" 
                                    :max="new Date().getFullYear() + 1"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
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
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Марка</label>
                        <select v-model="filtersForm.vehicle_make_id" @change="filtersForm.vehicle_model_id = ''" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все марки</option>
                            <option v-for="make in makes" :key="make.id" :value="make.id">{{ make.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Модель</label>
                        <select v-model="filtersForm.vehicle_model_id" :disabled="!filtersForm.vehicle_make_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:opacity-50">
                            <option value="">Все модели</option>
                            <option v-for="model in filterModels" :key="model.id" :value="model.id">{{ model.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Год выпуска</label>
                        <input type="number" v-model="filtersForm.year" placeholder="Например: 2020" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
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