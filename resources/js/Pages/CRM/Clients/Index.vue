<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import PageHelper from '@/Components/PageHelper.vue';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    clients: Array,
    branches: Array,
    customFieldDefs: Array,
    availableColumns: Array,
    listView: Object,
});

const page = usePage();

const isModalOpen = ref(false);
const isOffcanvasOpen = ref(false);
const isColumnsModalOpen = ref(false);
const previewClient = ref(null);
const editingClient = ref(null);

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const selectAll = computed({
    get: () => props.clients.length > 0 && selectedIds.value.length === props.clients.length,
    set: (value) => {
        if (value) {
            selectedIds.value = props.clients.map(c => c.id);
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
                if (previewClient.value && !props.clients.find(c => c.id === previewClient.value.id)) {
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
    is_lead: false,
    type: 'b2c',
    name: '',
    phone: '',
    email: '',
    discount_percent: 0,
    custom_fields: {},
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
    
    if (client) {
        form.branch_id = client.branch_id;
        form.is_lead = Boolean(client.is_lead);
        form.type = client.type;
        form.name = client.name;
        form.phone = client.phone || '';
        form.email = client.email || '';
        form.discount_percent = client.discount_percent || 0;
        
        // Инициализация кастомных полей
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
        form.discount_percent = 0;
        
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
</script>

<template>
    <Head title="Клиенты" />

    <AuthenticatedLayout>
        <template #header>
            Клиенты и Автомобили
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <PageHelper title="База клиентов">
                <p>Здесь хранится единая база всех ваших клиентов (как физических, так и юридических лиц). Вы можете настраивать отображаемые колонки таблицы, использовать массовые операции и добавлять собственные поля (через раздел Настройки -> Кастомные поля).</p>
            </PageHelper>

            <!-- Header Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Клиенты</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Управление клиентской базой
                    </p>
                </div>
                <div class="flex gap-3">
                    <button
                        @click="openModal()"
                        class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5"
                    >
                        <i class="ri-user-add-line text-base"></i>
                        Добавить клиента
                    </button>
                </div>
            </div>

            <!-- Action Bar (Bulk Actions) -->
            <div v-if="selectedIds.length > 0" class="bg-primary/10 border border-primary/20 rounded-md p-3 flex justify-between items-center transition-all">
                <div class="text-sm font-semibold text-primary flex items-center gap-2">
                    <i class="ri-checkbox-multiple-line text-lg"></i>
                    Выбрано клиентов: {{ selectedIds.length }}
                </div>
                <div class="flex gap-2">
                    <button @click="bulkExport" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">
                        <i class="ri-file-excel-2-line mr-1.5 text-success"></i> Экспорт в Excel
                    </button>
                    <button @click="bulkDelete" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger text-white hover:bg-danger-600 shadow-sm">
                        <i class="ri-delete-bin-line mr-1.5"></i> Удалить выбранных
                    </button>
                </div>
            </div>

            <!-- Table Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Список клиентов</h3>
                    <button @click="openColumnsModal()" class="text-xs font-medium text-gray-500 hover:text-primary transition-colors flex items-center gap-1">
                        <i class="ri-layout-column-line"></i> Настроить столбцы
                    </button>
                </div>
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
                            <tr v-for="client in clients" :key="client.id" @click="openPreview(client)" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors cursor-pointer group">
                                
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center" @click.stop>
                                    <input type="checkbox" :value="client.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>

                                <td v-for="col in activeColumns" :key="col.key" class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    
                                    <template v-if="col.key === 'client_name'">
                                        <div class="flex items-center gap-3">
                                            <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold shrink-0">
                                                {{ client.name.charAt(0) }}
                                            </div>
                                            <div class="font-semibold group-hover:text-primary transition-colors">
                                                {{ client.name }}
                                            </div>
                                        </div>
                                    </template>

                                    <template v-else-if="col.key === 'phone'">
                                        {{ client.phone || '—' }}
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
                                    <button 
                                        @click.stop="openModal(client)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                        title="Редактировать"
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
                            <tr v-if="clients.length === 0">
                                <td :colspan="activeColumns.length + 2" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Клиенты еще не добавлены. Нажмите "Добавить клиента".
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
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
                    <button type="button" @click="closeColumnsModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                        Отмена
                    </button>
                    <button type="button" @click="saveColumns()" :disabled="columnsForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
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
                            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight">
                                {{ previewClient.name }}
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mt-0.5">
                                <i :class="previewClient.type === 'b2b' ? 'ri-building-line' : 'ri-user-line'"></i>
                                {{ previewClient.type === 'b2b' ? 'Юридическое лицо' : 'Физическое лицо' }}
                            </p>
                        </div>
                    </div>
                    <button @click="closePreview" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-lg"></i>
                    </button>
                </div>

                <!-- Offcanvas Body -->
                <div class="flex-1 overflow-y-auto p-6 space-y-6">
                    
                    <!-- Статус и Скидка -->
                    <div class="flex items-center gap-3">
                        <span :class="[previewClient.is_lead ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success', 'inline-flex items-center gap-1.5 py-1 px-2.5 rounded-md text-xs font-bold tracking-wide uppercase']">
                            {{ previewClient.is_lead ? 'Лид' : 'Постоянный клиент' }}
                        </span>
                        <span class="inline-flex items-center gap-1.5 py-1 px-2.5 rounded-md text-xs font-bold tracking-wide uppercase bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600">
                            <i class="ri-percent-line"></i> Скидка: {{ previewClient.discount_percent }}%
                        </span>
                    </div>

                    <!-- Контакты -->
                    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 border border-gray-100 dark:border-gray-700/50 space-y-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Телефон</p>
                            <a :href="'tel:' + previewClient.phone" class="text-sm font-medium text-gray-800 dark:text-gray-200 hover:text-primary transition-colors flex items-center gap-2">
                                <i class="ri-phone-line text-gray-400"></i> {{ previewClient.phone || 'Не указан' }}
                            </a>
                        </div>
                        <div v-if="previewClient.email">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Email</p>
                            <a :href="'mailto:' + previewClient.email" class="text-sm font-medium text-gray-800 dark:text-gray-200 hover:text-primary transition-colors flex items-center gap-2">
                                <i class="ri-mail-line text-gray-400"></i> {{ previewClient.email }}
                            </a>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Филиал регистрации</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <i class="ri-store-2-line text-gray-400"></i> {{ previewClient.branch ? previewClient.branch.name : '—' }}
                            </p>
                        </div>
                    </div>

                    <!-- Кастомные поля (если есть) -->
                    <div v-if="customFieldDefs.length > 0" class="space-y-3">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Дополнительная информация</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <div v-for="def in customFieldDefs" :key="def.id">
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ getLocalizedLabel(def.label) }}</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                    <template v-if="def.type === 'checkbox'">
                                        {{ previewClient.custom_fields && previewClient.custom_fields[def.key] == '1' ? 'Да' : 'Нет' }}
                                    </template>
                                    <template v-else>
                                        {{ previewClient.custom_fields && previewClient.custom_fields[def.key] ? previewClient.custom_fields[def.key] : '—' }}
                                    </template>
                                </p>
                            </div>
                        </div>
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
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl lg:max-w-3xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingClient ? 'Редактирование клиента: ' + form.name : 'Добавление клиента' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-5">
                        
                        <!-- Основные поля -->
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
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Телефон</label>
                                <input 
                                    v-model="form.phone" 
                                    type="text" 
                                    placeholder="+7 (999) 000-00-00" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email</label>
                                <input 
                                    v-model="form.email" 
                                    type="email" 
                                    placeholder="client@mail.ru" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                        </div>

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
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Персональная скидка (%)</label>
                                <input 
                                    v-model="form.discount_percent" 
                                    type="number" 
                                    min="0" max="100"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
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