<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import BulkActions from '@/Components/BulkActions.vue';
import CalendarColorPicker from '@/Components/CalendarColorPicker.vue';
import HRNav from '@/Components/HRNav.vue';
import draggable from 'vuedraggable';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    employees: Object,
    filters: Object,
    branches: Array,
    positions: Array,
    roles: Array,
    scopes: Object,
    userScopes: Object,
    tenantCountry: String,
    availableColumns: Array,
    customFieldDefs: Array,
    listView: Object,
});

const page = usePage();

const isModalOpen = ref(false);
const isOffcanvasOpen = ref(false);
const isColumnsModalOpen = ref(false);
const previewEmployee = ref(null);
const editingEmployee = ref(null);
const activeTab = ref('main'); // 'main', 'work', 'crm', 'scopes', 'documents', 'payroll'

const needsMiddleName = computed(() => {
    return ['RU', 'BY', 'KZ'].includes(props.tenantCountry);
});

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const initialFilters = {
    branch_id: props.filters?.filters?.branch_id || '',
    position_id: props.filters?.filters?.position_id || '',
    type: props.filters?.filters?.type || '',
    is_active: props.filters?.filters?.is_active || '',
};
if (props.customFieldDefs) {
    props.customFieldDefs.filter(f => f.is_filterable).forEach(def => {
        initialFilters['cf_' + def.key] = props.filters?.filters?.['cf_' + def.key] || '';
    });
}

const filtersForm = reactive(initialFilters);
const isFiltersOpen = ref(false);

const fetchFiltered = useDebounceFn(() => {
    router.get(route('hr.employees.index'), {
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
    get: () => props.employees.data.length > 0 && selectedIds.value.length === props.employees.data.length,
    set: (value) => {
        if (value) {
            selectedIds.value = props.employees.data.map(e => e.id);
        } else {
            selectedIds.value = [];
        }
    }
});

const bulkDelete = () => {
    if (confirm(`Удалить выбранных сотрудников (${selectedIds.value.length})? Это также удалит их доступ в CRM.`)) {
        router.post(route('hr.employees.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
                if (previewEmployee.value && !props.employees.data.find(e => e.id === previewEmployee.value.id)) {
                    closePreview();
                }
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('hr.employees.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `employees_export_${new Date().toISOString().slice(0,10)}.csv`);
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
    entity_type: 'employee',
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

// ---------------------------

const form = useForm({
    first_name: '',
    last_name: '',
    middle_name: '',
    phone: '',
    personal_email: '',
    birth_date: '',
    
    branch_id: '',
    position_id: '',
    type: 'staff',
    hire_date: '',
    termination_date: '',
    is_active: true,
    calendar_color: null,

    passport_data: {
        series: '',
        number: '',
        issued_by: '',
        issue_date: '',
        department_code: '',
        registration_address: ''
    },

    // CRM Access
    has_crm_access: false,
    email: '',
    password: '',
    role_id: '',

    // Scopes (Индивидуальные доступы)
    scopes: {
        branches: [],
        legal_entities: [],
        business_directions: [],
        warehouses: [],
        accounts: []
    }
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

const openPreview = (employee) => {
    previewEmployee.value = employee;
    isOffcanvasOpen.value = true;
};

const closePreview = () => {
    isOffcanvasOpen.value = false;
    setTimeout(() => {
        previewEmployee.value = null;
    }, 300); // Ожидание окончания анимации закрытия
};

const openModal = (employee = null) => {
    // Если открываем из режима просмотра - закрываем панель
    if (isOffcanvasOpen.value) {
        isOffcanvasOpen.value = false;
    }

    editingEmployee.value = employee;
    activeTab.value = 'main';
    
    if (employee) {
        form.first_name = employee.first_name;
        form.last_name = employee.last_name || '';
        form.middle_name = employee.middle_name || '';
        form.phone = employee.phone || '';
        form.personal_email = employee.personal_email || '';
        form.birth_date = employee.birth_date ? employee.birth_date.substring(0, 10) : '';
        
        form.branch_id = employee.branch_id;
        form.position_id = employee.position_id || '';
        form.type = employee.type;
        form.hire_date = employee.hire_date ? employee.hire_date.substring(0, 10) : '';
        form.termination_date = employee.termination_date ? employee.termination_date.substring(0, 10) : '';
        form.is_active = Boolean(employee.is_active);
        form.calendar_color = employee.calendar_color || null;

        form.passport_data = employee.passport_data || {
            series: '', number: '', issued_by: '', issue_date: '', department_code: '', registration_address: ''
        };

        if (employee.user_id && employee.user) {
            form.has_crm_access = true;
            form.email = employee.user.email;
            form.password = ''; // Не заполняем пароль при редактировании
            form.role_id = employee.user.roles && employee.user.roles.length > 0 ? employee.user.roles[0].id : '';
            
            // Загружаем индивидуальные доступы
            const uScopes = props.userScopes[employee.user_id] || {};
            form.scopes.branches = uScopes.branches || [];
            form.scopes.legal_entities = uScopes.legal_entities || [];
            form.scopes.business_directions = uScopes.business_directions || [];
            form.scopes.warehouses = uScopes.warehouses || [];
            form.scopes.accounts = uScopes.accounts || [];
        } else {
            form.has_crm_access = false;
            form.email = '';
            form.password = '';
            form.role_id = '';
            form.scopes = { branches: [], legal_entities: [], business_directions: [], warehouses: [], accounts: [] };
        }
    } else {
        form.reset();
        // Предзаполняем локацию из текущей сессии для избежания "пропажи" сотрудника
        form.branch_id = page.props.current_branch_id || (props.branches.length > 0 ? props.branches[0].id : '');
        form.type = 'staff';
        form.is_active = true;
        form.has_crm_access = false;
        form.passport_data = { series: '', number: '', issued_by: '', issue_date: '', department_code: '', registration_address: '' };
        form.scopes = { branches: [], legal_entities: [], business_directions: [], warehouses: [], accounts: [] };
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingEmployee.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingEmployee.value) {
        form.put(route('hr.employees.update', editingEmployee.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('hr.employees.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteEmployee = (employee) => {
    if (confirm(`Удалить сотрудника "${employee.first_name} ${employee.last_name || ''}"? Это также удалит его доступ в CRM.`)) {
        form.delete(route('hr.employees.destroy', employee.id));
        if (previewEmployee.value && previewEmployee.value.id === employee.id) {
            closePreview();
        }
    }
};

const employeeTypes = {
    'staff': 'В штате',
    'self_employed': 'Самозанятый',
};
</script>

<template>
    <Head title="Сотрудники" />

    <AuthenticatedLayout>
        <template #header>
            Сотрудники и HR
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">

            <HRNav />

            <!-- Header Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Сотрудники</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Управление персоналом, должностями и индивидуальными правами доступа.
                    </p>
                </div>
            </div>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="сотрудников" 
                @export="bulkExport" 
                @delete="bulkDelete" 
            />

            <!-- Table Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="Object.values(filtersForm).some(v => v !== '' && v !== null)"
                    @open-filters="isFiltersOpen = true"
                    @open-columns="openColumnsModal"
                    placeholder="Поиск по имени, телефону, email..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-user-add-line text-base"></i>
                            Добавить сотрудника
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
                            <!-- Клик по строке открывает Offcanvas Быстрого просмотра -->
                            <tr v-for="employee in employees.data" :key="employee.id" @click="openPreview(employee)" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors cursor-pointer group">
                                
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center" @click.stop>
                                    <input type="checkbox" :value="employee.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>

                                <td v-for="col in activeColumns" :key="col.key" class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    
                                    <template v-if="col.key === 'employee_name'">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold shrink-0">
                                                {{ employee.first_name.charAt(0) }}
                                            </div>
                                            <div>
                                                <div class="text-gray-800 dark:text-gray-200 group-hover:text-primary transition-colors font-semibold">{{ employee.last_name }} {{ employee.first_name }} {{ employee.middle_name || '' }}</div>
                                                <div class="text-xs text-gray-500 font-normal mt-0.5">{{ employee.phone || 'Нет телефона' }}</div>
                                            </div>
                                        </div>
                                    </template>

                                    <template v-else-if="col.key === 'position_type'">
                                        <div class="font-medium">{{ employee.position ? getLocalizedLabel(employee.position.name) : 'Без должности' }}</div>
                                        <div class="text-xs text-gray-500 mt-0.5">{{ employeeTypes[employee.type] }}</div>
                                    </template>

                                    <template v-else-if="col.key === 'branch'">
                                        <span class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300">
                                            <i class="ri-store-2-line"></i> {{ employee.branch ? employee.branch.name : '—' }}
                                        </span>
                                    </template>

                                    <template v-else-if="col.key === 'crm_access'">
                                        <div v-if="employee.user_id" class="flex flex-col items-start gap-1">
                                            <span class="inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium bg-success/10 text-success">
                                                <i class="ri-shield-keyhole-line"></i> Есть доступ
                                            </span>
                                            <span class="text-xs text-gray-500 font-medium">
                                                Роль: {{ employee.user?.roles && employee.user.roles.length > 0 ? employee.user.roles[0].name : 'Нет роли' }}
                                            </span>
                                        </div>
                                        <span v-else class="inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400">
                                            Нет доступа
                                        </span>
                                    </template>

                                    <template v-else-if="col.key === 'phone'">
                                        {{ employee.phone || '—' }}
                                    </template>

                                    <template v-else-if="col.key === 'personal_email'">
                                        {{ employee.personal_email || '—' }}
                                    </template>

                                    <template v-else-if="col.key === 'birth_date'">
                                        {{ employee.birth_date || '—' }}
                                    </template>

                                    <template v-else-if="col.key === 'hire_date'">
                                        {{ employee.hire_date || '—' }}
                                    </template>

                                    <template v-else-if="col.key === 'termination_date'">
                                        {{ employee.termination_date || '—' }}
                                    </template>

                                    <template v-else>
                                        <!-- Кастомные поля -->
                                        {{ employee.custom_fields && employee.custom_fields[col.key] !== undefined && employee.custom_fields[col.key] !== null && employee.custom_fields[col.key] !== '' ? employee.custom_fields[col.key] : '—' }}
                                    </template>

                                </td>

                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
                                    <Link 
                                        :href="route('hr.employees.show', employee.id)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-info/10 text-info hover:bg-info hover:text-white"
                                        title="Карточка"
                                        @click.stop
                                    >
                                        <i class="ri-eye-line"></i>
                                    </Link>
                                    <button 
                                        @click.stop="openModal(employee)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                        title="Редактировать форму"
                                    >
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button 
                                        @click.stop="deleteEmployee(employee)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                        title="Удалить"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="employees.data.length === 0">
                                <td :colspan="activeColumns.length + 2" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Сотрудники не найдены.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="employees" />
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
                    
                    <!-- Список выбранных колонок (с сортировкой) -->
                    <div class="space-y-2 mb-6">
                        <h4 class="text-xs font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider mb-2">Отображаемые столбцы</h4>
                        <draggable v-model="columnsForm.visible_columns" :item-key="(key) => key" class="space-y-2" handle=".col-drag-handle">
                            <template #item="{ element: key }">
                                <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <i class="ri-draggable col-drag-handle text-gray-400 cursor-grab active:cursor-grabbing"></i>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                            {{ availableColumns.find(c => c.key === key)?.label || key }}
                                        </span>
                                    </div>
                                    <button type="button" @click="toggleColumn(key)" class="text-danger hover:text-danger/80"><i class="ri-close-circle-line text-lg"></i></button>
                                </div>
                            </template>
                        </draggable>
                    </div>

                    <!-- Список доступных колонок -->
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
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация</label>
                        <select v-model="filtersForm.branch_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все локации</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Должность</label>
                        <select v-model="filtersForm.position_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все должности</option>
                            <option v-for="pos in positions" :key="pos.id" :value="pos.id">{{ getLocalizedLabel(pos.name) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип оформления</label>
                        <select v-model="filtersForm.type" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все типы</option>
                            <option v-for="(label, key) in employeeTypes" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус</label>
                        <select v-model="filtersForm.is_active" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все статусы</option>
                            <option value="1">Активен</option>
                            <option value="0">Уволен</option>
                        </select>
                    </div>
                    
                    <!-- Кастомные фильтры -->
                    <template v-if="customFieldDefs">
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

        <!-- TRI-STATE 1: Offcanvas (Панель просмотра) -->
        <Offcanvas :show="isOffcanvasOpen" @close="closePreview" maxWidth="md">
            <div class="flex flex-col h-full" v-if="previewEmployee">
                <!-- Offcanvas Header -->
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-start bg-gray-50/50 dark:bg-gray-800/30">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-xl shrink-0">
                            {{ previewEmployee.first_name.charAt(0) }}
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight">
                                {{ previewEmployee.last_name }} {{ previewEmployee.first_name }}
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">
                                {{ previewEmployee.position ? getLocalizedLabel(previewEmployee.position.name) : 'Без должности' }}
                            </p>
                        </div>
                    </div>
                    <button @click="closePreview" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-lg"></i>
                    </button>
                </div>

                <!-- Offcanvas Body -->
                <div class="flex-1 overflow-y-auto p-6 space-y-6">
                    
                    <!-- Статус и Локация -->
                    <div class="flex items-center gap-3">
                        <span :class="[previewEmployee.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-1 px-2.5 rounded-md text-xs font-bold tracking-wide uppercase']">
                            {{ previewEmployee.is_active ? 'Активен' : 'Уволен' }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 py-1 px-2.5 rounded-md text-xs font-bold tracking-wide uppercase bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600">
                            <i class="ri-store-2-line"></i> {{ previewEmployee.branch ? previewEmployee.branch.name : '—' }}
                        </span>
                    </div>

                    <!-- Контакты -->
                    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 border border-gray-100 dark:border-gray-700/50 space-y-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Телефон</p>
                            <a :href="'tel:' + previewEmployee.phone" class="text-sm font-medium text-gray-800 dark:text-gray-200 hover:text-primary transition-colors flex items-center gap-2">
                                <i class="ri-phone-line text-gray-400"></i> {{ previewEmployee.phone || 'Не указан' }}
                            </a>
                        </div>
                        <div v-if="previewEmployee.personal_email">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Личный Email</p>
                            <a :href="'mailto:' + previewEmployee.personal_email" class="text-sm font-medium text-gray-800 dark:text-gray-200 hover:text-primary transition-colors flex items-center gap-2">
                                <i class="ri-mail-line text-gray-400"></i> {{ previewEmployee.personal_email }}
                            </a>
                        </div>
                    </div>

                    <!-- Системный доступ (CRM) -->
                    <div class="space-y-3">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Доступ в CRM</h3>
                        
                        <div v-if="previewEmployee.user_id" class="bg-primary/5 border border-primary/20 rounded-lg p-4">
                            <div class="flex items-center gap-2 mb-3">
                                <i class="ri-shield-check-fill text-success text-xl"></i>
                                <span class="text-sm font-bold text-gray-800 dark:text-gray-200">Аккаунт активен</span>
                            </div>
                            <div class="space-y-2">
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">Логин:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ previewEmployee.user?.email }}</span>
                                </div>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">Роль:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ previewEmployee.user?.roles && previewEmployee.user.roles.length > 0 ? previewEmployee.user.roles[0].name : '—' }}</span>
                                </div>
                            </div>
                        </div>
                        <div v-else class="bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded-lg p-4 flex items-center gap-3">
                            <i class="ri-shield-keyhole-line text-gray-400 text-xl"></i>
                            <span class="text-sm text-gray-500 dark:text-gray-400">Доступ в систему не предоставлялся</span>
                        </div>
                    </div>

                </div>

                <!-- Offcanvas Footer (Действия) -->
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/80 flex justify-between gap-3">
                    <button 
                        @click="openModal(previewEmployee)" 
                        class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm"
                    >
                        <i class="ri-pencil-line mr-2"></i> Редактировать
                    </button>
                    <Link 
                        :href="route('hr.employees.show', previewEmployee.id)" 
                        class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 shadow-sm"
                    >
                        Карточка <i class="ri-arrow-right-line ml-2"></i>
                    </Link>
                </div>
            </div>
        </Offcanvas>

        <!-- TRI-STATE 3: Форма (Focused Modal) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-4xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingEmployee ? 'Редактирование данных: ' + form.last_name + ' ' + form.first_name : 'Добавление сотрудника' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <!-- Вкладки внутри модалки -->
                <div class="flex overflow-x-auto border-b border-gray-200 dark:border-gray-700 px-6 bg-white dark:bg-[#313a46] custom-scrollbar">
                    <button
                        type="button"
                        @click="activeTab = 'main'"
                        :class="[activeTab === 'main' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-user-line"></i> Основные данные
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'work'"
                        :class="[activeTab === 'work' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-briefcase-line"></i> Работа
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'crm'"
                        :class="[activeTab === 'crm' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-shield-keyhole-line"></i> Доступ в CRM
                    </button>
                    <button
                        v-if="form.has_crm_access"
                        type="button"
                        @click="activeTab = 'scopes'"
                        :class="[activeTab === 'scopes' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-database-2-line"></i> Индивидуальные доступы
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'documents'"
                        :class="[activeTab === 'documents' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-profile-line"></i> Документы
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'payroll'"
                        :class="[activeTab === 'payroll' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-team-line"></i> Зарплата
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    
                    <!-- Вкладка 1: Основные данные -->
                    <div v-show="activeTab === 'main'" class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Фамилия <span class="text-danger">*</span></label>
                                <input 
                                    v-model="form.last_name" 
                                    type="text" 
                                    required 
                                    placeholder="Иванов" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                                <span v-if="form.errors.last_name" class="text-xs text-danger mt-1">{{ form.errors.last_name }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Имя <span class="text-danger">*</span></label>
                                <input 
                                    v-model="form.first_name" 
                                    type="text" 
                                    required 
                                    placeholder="Иван" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                                <span v-if="form.errors.first_name" class="text-xs text-danger mt-1">{{ form.errors.first_name }}</span>
                            </div>
                            <div v-if="needsMiddleName">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Отчество <span class="text-danger">*</span></label>
                                <input 
                                    v-model="form.middle_name" 
                                    type="text" 
                                    required
                                    placeholder="Иванович" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                                <span v-if="form.errors.middle_name" class="text-xs text-danger mt-1">{{ form.errors.middle_name }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Телефон <span class="text-danger">*</span></label>
                                <input 
                                    v-model="form.phone" 
                                    type="text" 
                                    required
                                    placeholder="+7 (999) 000-00-00" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                                <span v-if="form.errors.phone" class="text-xs text-danger mt-1 block leading-tight">{{ form.errors.phone }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Личный Email</label>
                                <input 
                                    v-model="form.personal_email" 
                                    type="email" 
                                    placeholder="ivanov@mail.ru" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата рождения</label>
                                <input 
                                    v-model="form.birth_date" 
                                    type="date" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Вкладка 2: Работа -->
                    <div v-show="activeTab === 'work'" class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Основная локация <span class="text-danger">*</span></label>
                                <select
                                    v-model="form.branch_id"
                                    required
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите локацию...</option>
                                    <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                                </select>
                                <span v-if="form.errors.branch_id" class="text-xs text-danger mt-1">{{ form.errors.branch_id }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Должность <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.position_id" 
                                    required
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите должность...</option>
                                    <option v-for="position in positions" :key="position.id" :value="position.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(position.name) }}</option>
                                </select>
                                <span v-if="form.errors.position_id" class="text-xs text-danger mt-1">{{ form.errors.position_id }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип оформления <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.type" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option v-for="(label, key) in employeeTypes" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата приема на работу</label>
                                <input 
                                    v-model="form.hire_date" 
                                    type="date" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата увольнения</label>
                                <input 
                                    v-model="form.termination_date" 
                                    type="date" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                        </div>

                        <div class="pt-2 border-t border-gray-200 dark:border-gray-700 mt-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цвет в календаре записей</label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Используется, если в настройках CRM источник цвета записи — «Исполнитель».</p>
                            <CalendarColorPicker v-model="form.calendar_color" />
                        </div>

                        <!-- Toggle Switch -->
                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-4">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Сотрудник активен (работает)
                            </label>
                        </div>
                    </div>

                    <!-- Вкладка 3: Доступ в CRM -->
                    <div v-show="activeTab === 'crm'" class="p-6 space-y-4">
                        
                        <div class="bg-info/10 border border-info/20 rounded-md p-4 mb-4">
                            <div class="flex items-center">
                                <div @click="form.has_crm_access = !form.has_crm_access" :class="[form.has_crm_access ? 'bg-success' : 'bg-gray-300 dark:bg-gray-600', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                    <div :class="[form.has_crm_access ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-3 block text-sm font-bold text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.has_crm_access = !form.has_crm_access">
                                    Предоставить доступ в CRM
                                </label>
                            </div>
                            <p class="text-xs text-info mt-2 ml-12">
                                Если включено, для сотрудника будет создан аккаунт (логин и пароль) для входа в систему.
                            </p>
                        </div>

                        <div v-if="form.has_crm_access" class="space-y-4 animate-fade-in">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email (Логин) <span class="text-danger">*</span></label>
                                    <input 
                                        v-model="form.email" 
                                        type="email" 
                                        :required="form.has_crm_access"
                                        placeholder="employee@company.com" 
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                    />
                                    <span v-if="form.errors.email" class="text-xs text-danger mt-1">{{ form.errors.email }}</span>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Пароль <span v-if="!editingEmployee?.user_id" class="text-danger">*</span></label>
                                    <input 
                                        v-model="form.password" 
                                        type="password" 
                                        :required="form.has_crm_access && !editingEmployee?.user_id"
                                        :placeholder="editingEmployee?.user_id ? 'Оставьте пустым, чтобы не менять' : 'Минимум 8 символов'" 
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                    />
                                    <span v-if="form.errors.password" class="text-xs text-danger mt-1">{{ form.errors.password }}</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Системная Роль (Права доступа) <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.role_id" 
                                    :required="form.has_crm_access"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите роль...</option>
                                    <option v-for="role in roles" :key="role.id" :value="role.id" class="bg-white dark:bg-gray-800">{{ role.name }}</option>
                                </select>
                                <span v-if="form.errors.role_id" class="text-xs text-danger mt-1">{{ form.errors.role_id }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Вкладка 4: Индивидуальные доступы (Scopes) -->
                    <div v-show="activeTab === 'scopes'" class="p-6 space-y-6">
                        <div class="bg-warning/10 border border-warning/20 rounded-md p-4 mb-2">
                            <p class="text-sm text-warning font-medium">
                                <i class="ri-information-line mr-1"></i>
                                Внимание: Галочки, установленные здесь, перекрывают (дополняют) базовые права выбранной Роли. Используйте это для выдачи индивидуальных исключений (например, доступ к дополнительной локации).
                            </p>
                        </div>

                        <!-- Локации -->
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-1">Доступные Локации</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label v-for="branch in scopes.branches" :key="branch.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="branch.id" v-model="form.scopes.branches" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ branch.name }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Юрлица -->
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-1">Доступные Юрлица</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label v-for="entity in scopes.legalEntities" :key="entity.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="entity.id" v-model="form.scopes.legal_entities" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ entity.name }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Направления -->
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-1">Доступные Направления</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label v-for="dir in scopes.businessDirections" :key="dir.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="dir.id" v-model="form.scopes.business_directions" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ dir.name }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Склады -->
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-1">Доступные Склады</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label v-for="wh in scopes.warehouses" :key="wh.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="wh.id" v-model="form.scopes.warehouses" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ wh.name }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Счета -->
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-1">Доступные Расчетные счета (Кассы)</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label v-for="acc in scopes.accounts" :key="acc.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="acc.id" v-model="form.scopes.accounts" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ acc.name }}</span>
                                </label>
                            </div>
                        </div>

                    </div>

                    <!-- Вкладка 5: Документы (Паспорт) -->
                    <div v-show="activeTab === 'documents'" class="p-6 space-y-5">
                        <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Паспортные данные</h4>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Серия</label>
                                <input 
                                    v-model="form.passport_data.series" 
                                    type="text" 
                                    placeholder="1234" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Номер</label>
                                <input 
                                    v-model="form.passport_data.number" 
                                    type="text" 
                                    placeholder="567890" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Код подразделения</label>
                                <input 
                                    v-model="form.passport_data.department_code" 
                                    type="text" 
                                    placeholder="123-456" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Кем выдан</label>
                                <input 
                                    v-model="form.passport_data.issued_by" 
                                    type="text" 
                                    placeholder="ГУ МВД России..." 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата выдачи</label>
                                <input 
                                    v-model="form.passport_data.issue_date" 
                                    type="date" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Адрес регистрации (прописка)</label>
                                <textarea 
                                    v-model="form.passport_data.registration_address" 
                                    rows="2"
                                    placeholder="г. Москва, ул. Ленина..." 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Вкладка 6: Зарплата -->
                    <div v-show="activeTab === 'payroll'" class="p-6 flex flex-col items-center justify-center text-center py-16">
                        <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4">
                            <i class="ri-team-line text-3xl text-gray-400 dark:text-gray-500"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-2">Настройки мотивации</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md">
                            Вкладка зарезервирована для модуля расчета заработной платы (оклады, проценты от услуг, KPI). Данный функционал будет доступен в следующих обновлениях.
                        </p>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                            Отмена
                        </button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            Сохранить
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>