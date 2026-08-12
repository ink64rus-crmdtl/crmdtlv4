<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import PageHelper from '@/Components/PageHelper.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import BulkActions from '@/Components/BulkActions.vue';
import CreatableSelect from '@/Components/CreatableSelect.vue';
import GroupColorPicker, { groupColorMeta } from '@/Components/GroupColorPicker.vue';
import Modal from '@/Components/Modal.vue';
import draggable from 'vuedraggable';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    clients: Object,
    filters: Object,
    branches: Array,
    clientGroups: Array,
    lookups: Object,
    customFieldDefs: Array,
    availableColumns: Array,
    listView: Object,
    tenantCountry: String,
    countryConfig: Object,
});

const page = usePage();

const isModalOpen = ref(false);
const isOffcanvasOpen = ref(false);
const isColumnsModalOpen = ref(false);
const isGroupModalOpen = ref(false);

const previewClient = ref(null);
const editingClient = ref(null);
const activeTab = ref('main'); // 'main', 'contacts', 'requisites', 'settings'

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const initialFilters = {
    type: props.filters?.filters?.type || '',
    is_lead: props.filters?.filters?.is_lead || '',
    client_group_id: props.filters?.filters?.client_group_id || '',
    segment: props.filters?.filters?.segment || '',
};
props.customFieldDefs.filter(f => f.is_filterable).forEach(def => {
    initialFilters['cf_' + def.key] = props.filters?.filters?.['cf_' + def.key] || '';
});

const filtersForm = reactive(initialFilters);
const isFiltersOpen = ref(false);

const fetchFiltered = useDebounceFn(() => {
    router.get(route('crm.clients.index'), {
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
    get: () => props.clients.data.length > 0 && selectedIds.value.length === props.clients.data.length,
    set: (value) => {
        if (value) {
            selectedIds.value = props.clients.data.map(c => c.id);
        } else {
            selectedIds.value = [];
        }
    }
});

const bulkDelete = () => {
    if (confirm(`Удалить выбранных клиентов (${selectedIds.value.length})? Это также удалит их автомобили и связи.`)) {
        router.post(route('crm.clients.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
                if (previewClient.value && !props.clients.data.find(c => c.id === previewClient.value.id)) {
                    closePreview();
                }
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('crm.clients.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `clients_export_${new Date().toISOString().slice(0,10)}.csv`);
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
    entity_type: 'client',
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

// --- ГРУППЫ (ГРЕЙДЫ) КЛИЕНТОВ: добавление, кэшбек, удаление ---
const groupForm = useForm({
    name: '',
    color: 'gray',
    cashback_percent: 0,
});

const editingGroupId = ref(null);
const editGroupForm = useForm({
    name: '',
    color: 'gray',
    cashback_percent: 0,
});

const groupColorClass = (color) => groupColorMeta(color).badge;

const openGroupModal = () => {
    groupForm.reset();
    editingGroupId.value = null;
    isGroupModalOpen.value = true;
};

const closeGroupModal = () => {
    isGroupModalOpen.value = false;
    groupForm.reset();
    editingGroupId.value = null;
};

const submitGroup = () => {
    groupForm.post(route('crm.client-groups.store'), {
        preserveScroll: true,
        onSuccess: () => {
            groupForm.reset();
        },
    });
};

const startEditGroup = (group) => {
    editingGroupId.value = group.id;
    editGroupForm.clearErrors();
    editGroupForm.name = group.name;
    editGroupForm.color = group.color;
    editGroupForm.cashback_percent = group.cashback_percent;
};

const cancelEditGroup = () => {
    editingGroupId.value = null;
};

const submitEditGroup = (group) => {
    editGroupForm.put(route('crm.client-groups.update', group.id), {
        preserveScroll: true,
        onSuccess: () => {
            editingGroupId.value = null;
        },
    });
};

const deleteGroup = (group) => {
    if (!confirm(`Удалить группу «${group.name}»? У клиентов этой группы группа будет сброшена на «Без группы».`)) return;
    router.delete(route('crm.client-groups.destroy', group.id), { preserveScroll: true });
};
// ---------------------------------

// --- RFM-СЕГМЕНТ КЛИЕНТА (Фаза 14.3) ---
const segmentClasses = {
    new: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    loyal: 'bg-success/10 text-success',
    dormant: 'bg-warning/10 text-warning',
    regular: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
};
const segmentClass = (segment) => segmentClasses[segment] || segmentClasses.regular;
// ---------------------------------

const form = useForm({
    branch_id: '',
    client_group_id: '',
    is_lead: false,
    type: 'b2c',
    role: '',
    name: '',
    alias: '',
    phone: '',
    phone_required: true,
    phone_2: '',
    email: '',
    source: '',
    birth_date: '',
    comment: '',
    discount_percent: 0,
    requisites: {},
    custom_fields: {},
});

const currentCountrySchema = computed(() => {
    return props.countryConfig?.requisite_schema || [];
});

const signatorySchema = computed(() => {
    return props.countryConfig?.signatory_schema || [];
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

const openPreview = (client) => {
    previewClient.value = client;
    isOffcanvasOpen.value = true;
};

const closePreview = () => {
    isOffcanvasOpen.value = false;
    setTimeout(() => {
        previewClient.value = null;
    }, 300);
};

const openModal = (client = null) => {
    if (isOffcanvasOpen.value) {
        isOffcanvasOpen.value = false;
    }

    editingClient.value = client;
    activeTab.value = 'main';
    
    if (client) {
        form.branch_id = client.branch_id;
        form.client_group_id = client.client_group_id || '';
        form.is_lead = Boolean(client.is_lead);
        form.type = client.type;
        form.role = client.role || '';
        form.name = client.name;
        form.alias = client.alias || '';
        form.phone = client.phone || '';
        // У уже существующего клиента без телефона не форсируем требование
        // сразу при открытии формы — иначе сохранение без единой правки
        // упрётся в валидацию, хотя раньше это же значение приняли.
        form.phone_required = Boolean(client.phone);
        form.phone_2 = client.phone_2 || '';
        form.email = client.email || '';
        form.source = client.source || '';
        form.birth_date = client.birth_date ? client.birth_date.substring(0, 10) : '';
        form.comment = client.comment || '';
        form.discount_percent = client.discount_percent || 0;
        form.requisites = client.requisites || {};
        
        const cf = {};
        props.customFieldDefs.forEach(def => {
            cf[def.key] = client.custom_fields && client.custom_fields[def.key] !== undefined 
                ? client.custom_fields[def.key] 
                : (def.type === 'checkbox' ? false : '');
        });
        form.custom_fields = cf;
    } else {
        form.reset();
        form.branch_id = page.props.current_branch_id || (props.branches.length > 0 ? props.branches[0].id : '');
        form.is_lead = false;
        form.type = 'b2c';
        form.role = '';
        form.discount_percent = 0;
        form.requisites = {};
        
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
    editingClient.value = null;
    form.reset();
    form.clearErrors();
};

const resetGroupAuto = () => {
    if (!editingClient.value) return;
    router.post(route('crm.clients.group.auto', editingClient.value.id), {}, {
        preserveScroll: true,
        onSuccess: () => {
            const fresh = props.clients.data.find(c => c.id === editingClient.value.id);
            if (fresh) {
                editingClient.value = fresh;
                form.client_group_id = fresh.client_group_id || '';
            }
        },
    });
};

const submit = () => {
    if (editingClient.value) {
        form.put(route('crm.clients.update', editingClient.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('crm.clients.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteClient = (client) => {
    if (confirm(`Удалить клиента "${client.name}"?`)) {
        form.delete(route('crm.clients.destroy', client.id));
        if (previewClient.value && previewClient.value.id === client.id) {
            closePreview();
        }
    }
};

const formatMoney = (amount) => {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(amount / 100);
};
</script>

<template>
    <Head title="Клиенты" />

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

            <PageHelper title="База клиентов">
                <p>Здесь хранится единая база всех ваших клиентов (как физических, так и юридических лиц). Вы можете настраивать отображаемые колонки таблицы, использовать массовые операции и добавлять собственные поля (через раздел Настройки -> Кастомные поля).</p>
            </PageHelper>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="клиентов" 
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
                    placeholder="Поиск по имени, телефону, email, номеру авто..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-user-add-line text-base"></i>
                            Добавить клиента
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
                            <tr v-for="client in clients.data" :key="client.id" @click="openPreview(client)" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors cursor-pointer group">
                                
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center" @click.stop>
                                    <input type="checkbox" :value="client.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>

                                <td v-for="col in activeColumns" :key="col.key" class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    
                                    <template v-if="col.key === 'client_name'">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold shrink-0">
                                                {{ client.name.charAt(0) }}
                                            </div>
                                            <div>
                                                <div class="font-semibold group-hover:text-primary transition-colors">
                                                    {{ client.name }}
                                                </div>
                                                <div v-if="client.alias" class="text-xs text-gray-500 font-normal mt-0.5">«{{ client.alias }}»</div>
                                            </div>
                                        </div>
                                    </template>

                                    <template v-else-if="col.key === 'client_group'">
                                        <span v-if="client.group" :class="[groupColorMeta(client.group.color).badge, 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium']">
                                            {{ client.group.name }}
                                        </span>
                                        <span v-else class="text-xs text-gray-400">—</span>
                                    </template>

                                    <template v-else-if="col.key === 'segment'">
                                        <span :class="[segmentClass(client.segment), 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium']">
                                            {{ client.segment_label }}
                                        </span>
                                    </template>

                                    <template v-else-if="col.key === 'phone'">
                                        {{ client.phone || '—' }}
                                    </template>

                                    <template v-else-if="col.key === 'phone_2'">
                                        {{ client.phone_2 || '—' }}
                                    </template>

                                    <template v-else-if="col.key === 'email'">
                                        {{ client.email || '—' }}
                                    </template>

                                    <template v-else-if="col.key === 'type'">
                                        <span class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300">
                                            <i :class="client.type === 'b2b' ? 'ri-building-line' : 'ri-user-line'"></i>
                                            {{ client.type === 'b2b' ? 'Юрлицо' : 'Физлицо' }}
                                        </span>
                                    </template>

                                    <template v-else-if="col.key === 'is_lead'">
                                        <span :class="[client.is_lead ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                            {{ client.is_lead ? 'Лид' : 'Клиент' }}
                                        </span>
                                    </template>

                                    <template v-else-if="col.key === 'source'">
                                        {{ client.source || '—' }}
                                    </template>

                                    <template v-else-if="col.key === 'balance'">
                                        <span :class="client.balance < 0 ? 'text-danger font-semibold' : 'text-success font-semibold'">
                                            {{ formatMoney(client.balance) }}
                                        </span>
                                    </template>

                                    <template v-else-if="col.key === 'bonus_points'">
                                        <span class="text-warning font-semibold"><i class="ri-star-fill"></i> {{ client.bonus_points }}</span>
                                    </template>

                                    <template v-else-if="col.key === 'discount_percent'">
                                        {{ client.discount_percent }}%
                                    </template>

                                    <template v-else-if="col.key === 'branch'">
                                        <span class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300">
                                            <i class="ri-store-2-line"></i> {{ client.branch ? client.branch.name : '—' }}
                                        </span>
                                    </template>

                                    <template v-else>
                                        <!-- Кастомные поля -->
                                        {{ client.custom_fields && client.custom_fields[col.key] !== undefined && client.custom_fields[col.key] !== null && client.custom_fields[col.key] !== '' ? client.custom_fields[col.key] : '—' }}
                                    </template>

                                </td>

                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
                                    <Link 
                                        :href="route('crm.clients.show', client.id)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-info/10 text-info hover:bg-info hover:text-white"
                                        title="Полная карточка"
                                        @click.stop
                                    >
                                        <i class="ri-eye-line"></i>
                                    </Link>
                                    <button 
                                        @click.stop="openModal(client)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                        title="Редактировать форму"
                                    >
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button 
                                        @click.stop="deleteClient(client)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                        title="Удалить"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="clients.data.length === 0">
                                <td :colspan="activeColumns.length + 2" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Клиенты не найдены.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="clients" />
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
            <div class="flex flex-col h-full" v-if="previewClient">
                <!-- Offcanvas Header -->
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-start bg-gray-50/50 dark:bg-gray-800/30">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-xl shrink-0">
                            {{ previewClient.name.charAt(0) }}
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight flex items-center gap-2">
                                {{ previewClient.name }}
                                <span v-if="previewClient.alias" class="text-sm font-normal text-gray-500">«{{ previewClient.alias }}»</span>
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mt-0.5 flex items-center gap-2">
                                <i :class="previewClient.type === 'b2b' ? 'ri-building-line' : 'ri-user-line'"></i>
                                {{ previewClient.type === 'b2b' ? 'Юридическое лицо' : 'Физическое лицо' }}
                                <span v-if="previewClient.group" :class="[groupColorMeta(previewClient.group.color).badge, 'px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wider font-bold ml-1']">
                                    {{ previewClient.group.name }}
                                </span>
                                <span v-if="previewClient.segment" :class="[segmentClass(previewClient.segment), 'px-1.5 py-0.5 rounded text-[10px] uppercase tracking-wider font-bold']">
                                    {{ previewClient.segment_label }}
                                </span>
                            </p>
                        </div>
                    </div>
                    <button @click="closePreview" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-lg"></i>
                    </button>
                </div>

                <!-- Offcanvas Body -->
                <div class="flex-1 overflow-y-auto p-6 space-y-6">
                    
                    <!-- KPI Мини-Дашборд -->
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3 border border-gray-100 dark:border-gray-700/50">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Баланс</p>
                            <p :class="[previewClient.balance < 0 ? 'text-danger' : 'text-success', 'text-lg font-bold']">
                                {{ formatMoney(previewClient.balance) }}
                            </p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3 border border-gray-100 dark:border-gray-700/50">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Бонусы</p>
                            <p class="text-lg font-bold text-warning flex items-center gap-1">
                                <i class="ri-star-fill text-sm"></i> {{ previewClient.bonus_points }}
                            </p>
                        </div>
                    </div>

                    <!-- Статус и Скидка -->
                    <div class="flex items-center gap-3">
                        <span :class="[previewClient.is_lead ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success', 'inline-flex items-center gap-1.5 py-1 px-2.5 rounded-md text-xs font-bold tracking-wide uppercase']">
                            {{ previewClient.is_lead ? 'Лид' : 'Постоянный клиент' }}
                        </span>
                        <span v-if="previewClient.discount_percent > 0" class="inline-flex items-center gap-1.5 py-1 px-2.5 rounded-md text-xs font-bold tracking-wide uppercase bg-info/10 text-info">
                            <i class="ri-percent-line"></i> Скидка: {{ previewClient.discount_percent }}%
                        </span>
                    </div>

                    <!-- Контакты -->
                    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 border border-gray-100 dark:border-gray-700/50 space-y-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Основной телефон</p>
                            <a :href="'tel:' + previewClient.phone" class="text-sm font-medium text-gray-800 dark:text-gray-200 hover:text-primary transition-colors flex items-center gap-2">
                                <i class="ri-phone-line text-gray-400"></i> {{ previewClient.phone || 'Не указан' }}
                            </a>
                        </div>
                        <div v-if="previewClient.phone_2">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Доп. телефон</p>
                            <a :href="'tel:' + previewClient.phone_2" class="text-sm font-medium text-gray-800 dark:text-gray-200 hover:text-primary transition-colors flex items-center gap-2">
                                <i class="ri-phone-line text-gray-400"></i> {{ previewClient.phone_2 }}
                            </a>
                        </div>
                        <div v-if="previewClient.email">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Email</p>
                            <a :href="'mailto:' + previewClient.email" class="text-sm font-medium text-gray-800 dark:text-gray-200 hover:text-primary transition-colors flex items-center gap-2">
                                <i class="ri-mail-line text-gray-400"></i> {{ previewClient.email }}
                            </a>
                        </div>
                        <div class="grid grid-cols-2 gap-4 pt-2">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Источник</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ previewClient.source || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Точка</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 flex items-center gap-1">
                                    <i class="ri-store-2-line text-gray-400"></i> {{ previewClient.branch ? previewClient.branch.name : '—' }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Комментарий -->
                    <div v-if="previewClient.comment" class="bg-warning/5 border border-warning/20 rounded-md p-4">
                        <p class="text-xs font-semibold text-warning uppercase tracking-wider mb-1">Комментарий</p>
                        <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ previewClient.comment }}</p>
                    </div>

                </div>

                <!-- Offcanvas Footer (Действия) -->
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/80 flex justify-between gap-3">
                    <button 
                        @click="openModal(previewClient)" 
                        class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm"
                    >
                        <i class="ri-pencil-line mr-2"></i> Редактировать
                    </button>
                    <Link 
                        :href="route('crm.clients.show', previewClient.id)" 
                        class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 shadow-sm"
                    >
                        Полная карточка <i class="ri-arrow-right-line ml-2"></i>
                    </Link>
                </div>
            </div>
        </Offcanvas>

        <!-- TRI-STATE 3: Форма редактирования (Focused Modal) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-3xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingClient ? 'Редактирование клиента: ' + form.name : 'Добавление клиента' }}
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
                        @click="activeTab = 'contacts'"
                        :class="[activeTab === 'contacts' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-contacts-book-2-line"></i> Контакты
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'requisites'"
                        :class="[activeTab === 'requisites' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-file-list-3-line"></i> Реквизиты
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'signatures'"
                        :class="[activeTab === 'signatures' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-quill-pen-line"></i> Подписи
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'settings'"
                        :class="[activeTab === 'settings' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-settings-3-line"></i> Настройки и Поля
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    
                    <!-- Вкладка 1: Основные данные -->
                    <div v-show="activeTab === 'main'" class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип клиента <span class="text-danger">*</span></label>
                                <select
                                    v-model="form.type"
                                    required
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="b2c" class="bg-white dark:bg-gray-800">Физическое лицо</option>
                                    <option value="b2b" class="bg-white dark:bg-gray-800">Юридическое лицо (B2B)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Роль клиента</label>
                                <CreatableSelect
                                    v-model="form.role"
                                    :options="lookups.client_role?.map(l => l.value) || []"
                                    lookupType="client_role"
                                    placeholder="Выберите роль..."
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Источник привлечения</label>
                                <CreatableSelect
                                    v-model="form.source"
                                    :options="lookups.client_source?.map(l => l.value) || []"
                                    lookupType="client_source"
                                    placeholder="Авито, 2GIS, Рекомендация..."
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Имя / Название <span class="text-danger">*</span></label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    required
                                    placeholder="Иван Иванов"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                />
                                <span v-if="form.errors.name" class="text-xs text-danger mt-1">{{ form.errors.name }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Псевдоним (Кратко)</label>
                                <input
                                    v-model="form.alias"
                                    type="text"
                                    placeholder="Иван BMW"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата рождения / основания</label>
                                <input
                                    v-model="form.birth_date"
                                    type="date"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Группа лояльности</label>
                                <div class="flex gap-2">
                                    <select
                                        v-model="form.client_group_id"
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                    >
                                        <option value="" class="bg-white dark:bg-gray-800">Без группы</option>
                                        <option v-for="group in clientGroups" :key="group.id" :value="group.id" class="bg-white dark:bg-gray-800">{{ group.name }}</option>
                                    </select>
                                    <button type="button" @click="openGroupModal" class="shrink-0 inline-flex items-center justify-center rounded-md border border-primary/30 dark:border-primary/40 bg-primary/10 dark:bg-primary/15 px-3 hover:bg-primary/20 dark:hover:bg-primary/25 transition-colors" title="Добавить группу">
                                        <i class="ri-add-line text-primary"></i>
                                    </button>
                                </div>
                                <p v-if="editingClient?.client_group_locked" class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 flex items-center gap-1.5">
                                    <i class="ri-lock-line"></i> Выбрана вручную.
                                    <button type="button" @click="resetGroupAuto" class="text-primary hover:underline">Вернуть на автоподбор</button>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Вкладка 2: Контакты -->
                    <div v-show="activeTab === 'contacts'" class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Основной телефон <span v-if="form.phone_required" class="text-danger">*</span>
                                    </label>
                                    <label class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 cursor-pointer select-none">
                                        <input
                                            type="checkbox"
                                            :checked="!form.phone_required"
                                            @change="form.phone_required = !$event.target.checked"
                                            class="rounded border-gray-300 dark:border-gray-600 text-primary focus:ring-primary focus:ring-offset-0"
                                        />
                                        Без номера
                                    </label>
                                </div>
                                <input
                                    v-model="form.phone"
                                    type="text"
                                    :required="form.phone_required"
                                    placeholder="+7 (999) 000-00-00"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                />
                                <span v-if="form.errors.phone" class="text-xs text-danger mt-1 block">{{ form.errors.phone }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дополнительный телефон</label>
                                <input
                                    v-model="form.phone_2"
                                    type="text"
                                    placeholder="+7 (999) 111-11-11"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                />
                            </div>
                        </div>

                        <p v-if="!form.phone_required" class="text-[11px] text-warning bg-warning/5 border border-warning/20 rounded-md px-3 py-2 flex items-start gap-1.5">
                            <i class="ri-error-warning-line mt-0.5"></i>
                            <span>Без номера телефона высок риск случайно создать дубль клиента — именно по телефону система обычно определяет, что клиент уже есть в базе. Указывайте это только если контакта действительно нет.</span>
                        </p>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email</label>
                            <input
                                v-model="form.email"
                                type="email"
                                placeholder="client@mail.ru"
                                class="block w-full sm:w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Точка регистрации <span class="text-danger">*</span></label>
                            <select
                                v-model="form.branch_id"
                                required
                                class="block w-full sm:w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                            >
                                <option value="" disabled class="bg-white dark:bg-gray-800">Выберите точку...</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Вкладка 3: Реквизиты -->
                    <div v-show="activeTab === 'requisites'" class="p-6 space-y-5">
                        
                        <!-- Для B2C (Физлицо) - Паспорт -->
                        <template v-if="form.type === 'b2c'">
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Паспортные данные</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Серия</label>
                                    <input v-model="form.requisites.passport_series" type="text" placeholder="1234" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Номер</label>
                                    <input v-model="form.requisites.passport_number" type="text" placeholder="567890" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Код подразделения</label>
                                    <input v-model="form.requisites.passport_code" type="text" placeholder="123-456" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Кем выдан</label>
                                    <input v-model="form.requisites.passport_issued_by" type="text" placeholder="ГУ МВД России..." class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата выдачи</label>
                                    <input v-model="form.requisites.passport_date" type="date" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Адрес регистрации (прописка)</label>
                                    <textarea v-model="form.requisites.passport_address" rows="2" placeholder="г. Москва, ул. Ленина..." class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"></textarea>
                                </div>
                            </div>
                        </template>

                        <!-- Для B2B (Юрлицо) - Динамическая схема -->
                        <template v-else>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Реквизиты компании</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div v-for="field in currentCountrySchema" :key="field.key" :class="field.type === 'textarea' ? 'sm:col-span-2' : ''">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                        {{ field.label }}
                                    </label>
                                    <textarea
                                        v-if="field.type === 'textarea'"
                                        v-model="form.requisites[field.key]"
                                        :placeholder="field.placeholder"
                                        rows="2"
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                    ></textarea>
                                    <input
                                        v-else
                                        v-model="form.requisites[field.key]"
                                        :type="field.type"
                                        :placeholder="field.placeholder"
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                    />
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Вкладка: Подписи в документах (только для B2B — клиент сам сторона сделки в договоре) -->
                    <div v-show="activeTab === 'signatures'" class="p-6 space-y-5">
                        <template v-if="form.type === 'b2b'">
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Подписи в документах</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Должность и ФИО для блока подписи в договорах и печатных формах, где этот клиент — сторона сделки. Необязательно.</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div v-for="field in signatorySchema" :key="field.key">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                        {{ field.label }}
                                    </label>
                                    <input
                                        v-model="form.requisites[field.key]"
                                        type="text"
                                        :placeholder="field.placeholder"
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                    />
                                </div>
                            </div>
                        </template>
                        <p v-else class="text-sm text-gray-500 dark:text-gray-400 text-center py-8">
                            Подписи доступны только для клиентов-организаций (B2B) — переключите тип клиента на вкладке «Основные данные».
                        </p>
                    </div>

                    <!-- Вкладка 4: Настройки и Поля -->
                    <div v-show="activeTab === 'settings'" class="p-6 space-y-5">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Персональная скидка (%)</label>
                                <input 
                                    v-model="form.discount_percent" 
                                    type="number" 
                                    min="0" max="100"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Начальный баланс (₽)</label>
                                <input 
                                    v-model="form.balance" 
                                    type="number" 
                                    step="0.01"
                                    placeholder="0.00"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                                <p class="text-xs text-gray-500 mt-1">Отрицательный — долг, положительный — депозит.</p>
                            </div>
                        </div>

                        <div class="flex items-center pt-2">
                            <div @click="form.is_lead = !form.is_lead" :class="[form.is_lead ? 'bg-warning' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_lead ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_lead = !form.is_lead">
                                Это Лид (потенциальный клиент)
                            </label>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Комментарий менеджера</label>
                            <textarea 
                                v-model="form.comment" 
                                rows="3"
                                placeholder="Особые приметы, договоренности..." 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                            ></textarea>
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
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип клиента</label>
                        <select v-model="filtersForm.type" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все типы</option>
                            <option value="b2c">Физическое лицо</option>
                            <option value="b2b">Юридическое лицо</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус</label>
                        <select v-model="filtersForm.is_lead" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все статусы</option>
                            <option value="1">Лид</option>
                            <option value="0">Постоянный клиент</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Группа</label>
                        <select v-model="filtersForm.client_group_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все группы</option>
                            <option v-for="group in clientGroups" :key="group.id" :value="group.id">{{ group.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сегмент</label>
                        <select v-model="filtersForm.segment" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все сегменты</option>
                            <option value="new">Новичок</option>
                            <option value="loyal">Лояльный</option>
                            <option value="dormant">Спящий</option>
                            <option value="regular">Обычный</option>
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

        <!-- Модальное окно управления группами (грейдами) клиентов и кэшбеком —
        через общий <Modal>: открывается кнопкой "+" ИЗНУТРИ формы добавления
        клиента, см. CLAUDE.md про пополняемые списки — голый div с z-index
        здесь ненадёжен, если форму клиента когда-нибудь переведут на <Modal>. -->
        <Modal :show="isGroupModalOpen" @close="closeGroupModal" maxWidth="lg">
            <div class="bg-white dark:bg-[#313a46] rounded-md flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Группы (грейды) клиентов
                    </h3>
                    <button @click="closeGroupModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <div class="p-6 space-y-6 max-h-[70vh] overflow-y-auto custom-scrollbar">
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Грейд определяет процент кэшбека в бонусные баллы клиента при оплате заказа деньгами (не бонусами). Курс баллов задаётся в Настройках CRM.
                    </p>

                    <!-- Список существующих групп -->
                    <div class="space-y-2">
                        <div v-for="group in clientGroups" :key="group.id" class="border border-gray-200 dark:border-gray-700 rounded-md p-3">
                            <div v-if="editingGroupId !== group.id" class="flex items-center justify-between gap-3">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span :class="[groupColorClass(group.color), 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium shrink-0']">{{ group.name }}</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 shrink-0">Кэшбек: {{ group.cashback_percent }}%</span>
                                </div>
                                <div class="flex items-center gap-1 shrink-0">
                                    <button type="button" @click="startEditGroup(group)" class="text-gray-400 hover:text-primary p-1" title="Редактировать"><i class="ri-pencil-line"></i></button>
                                    <button type="button" @click="deleteGroup(group)" class="text-gray-400 hover:text-danger p-1" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                </div>
                            </div>
                            <div v-else class="space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Название</label>
                                        <input v-model="editGroupForm.name" type="text" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-2.5 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Кэшбек, %</label>
                                        <input v-model="editGroupForm.cashback_percent" type="number" step="0.01" min="0" max="100" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-2.5 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    </div>
                                </div>
                                <GroupColorPicker v-model="editGroupForm.color" />
                                <div class="flex justify-end gap-2">
                                    <button type="button" @click="cancelEditGroup()" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        Отмена
                                    </button>
                                    <button type="button" @click="submitEditGroup(group)" :disabled="editGroupForm.processing" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                                        Сохранить
                                    </button>
                                </div>
                            </div>
                        </div>
                        <p v-if="clientGroups.length === 0" class="text-sm text-gray-400 text-center py-2">Групп ещё нет</p>
                    </div>

                    <!-- Добавление новой группы -->
                    <form @submit.prevent="submitGroup" class="border-t border-gray-200 dark:border-gray-700 pt-4 space-y-3">
                        <h4 class="text-xs font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider">Новая группа</h4>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Название <span class="text-danger">*</span></label>
                                <input v-model="groupForm.name" type="text" required placeholder="Например, VIP" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-2.5 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <span v-if="groupForm.errors.name" class="text-xs text-danger mt-1">{{ groupForm.errors.name }}</span>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">Кэшбек, %</label>
                                <input v-model="groupForm.cashback_percent" type="number" step="0.01" min="0" max="100" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-2.5 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                        </div>
                        <GroupColorPicker v-model="groupForm.color" />
                        <div class="flex justify-end">
                            <button type="submit" :disabled="groupForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                                <i class="ri-add-line mr-1"></i> Добавить группу
                            </button>
                        </div>
                    </form>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/80 flex justify-end">
                    <button @click="closeGroupModal()" class="inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">
                        Закрыть
                    </button>
                </div>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>