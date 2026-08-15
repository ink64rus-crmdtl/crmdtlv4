<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import HRNav from '@/Components/HRNav.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import axios from 'axios';

const props = defineProps({
    positions: Object,
    filters: Object,
});

const isModalOpen = ref(false);
const editingPosition = ref(null);

const form = useForm({
    name: '',
    is_active: true,
    payroll_role: 'worker',
});

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const filtersForm = reactive({
    payroll_role: props.filters?.filters?.payroll_role || '',
    is_active: props.filters?.filters?.is_active ?? '',
});

const isFiltersOpen = ref(false);

const fetchFiltered = useDebounceFn(() => {
    router.get(route('hr.positions.index'), {
        search: search.value,
        filters: filtersForm,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
watch(filtersForm, () => fetchFiltered(), { deep: true });

const { sort, onSort } = useServerSort('hr.positions.index', () => props.filters, () => ({ search: search.value, filters: filtersForm }));

const resetFilters = () => {
    filtersForm.payroll_role = '';
    filtersForm.is_active = '';
};
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const bulkDelete = () => {
    if (confirm(`Удалить выбранные должности (${selectedIds.value.length})?`)) {
        router.post(route('hr.positions.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('hr.positions.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `positions_export_${new Date().toISOString().slice(0,10)}.csv`);
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

// name — переводимый JSON (spatie/laravel-translatable), не sortable.
const positionColumns = [
    { key: 'name', label: 'Название' },
    { key: 'payroll_role', label: 'Роль в расчёте ЗП', sortable: true },
    { key: 'status', label: 'Статус', sortable: true, sortKey: 'is_active' },
];

const openModal = (position = null) => {
    editingPosition.value = position;
    if (position) {
        form.name = getLocalizedLabel(position.name);
        form.is_active = Boolean(position.is_active);
        form.payroll_role = position.payroll_role || 'worker';
    } else {
        form.reset();
        form.is_active = true;
        form.payroll_role = 'worker';
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingPosition.value = null;
    form.reset();
};

const submit = () => {
    if (editingPosition.value) {
        form.put(route('hr.positions.update', editingPosition.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('hr.positions.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deletePosition = (position) => {
    if (confirm(`Удалить должность "${getLocalizedLabel(position.name)}"?`)) {
        form.delete(route('hr.positions.destroy', position.id));
    }
};
</script>

<template>
    <Head title="Справочник должностей" />

    <AuthenticatedLayout>
        <template #header>
            Сотрудники и HR
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">

            <HRNav />

            <!-- Header Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Справочник должностей</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Управление списком должностей для сотрудников компании.
                    </p>
                </div>
            </div>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="должностей" 
                @export="bulkExport" 
                @delete="bulkDelete" 
            />

            <!-- Table Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="Object.values(filtersForm).some(v => v !== '' && v !== null)"
                    @open-filters="isFiltersOpen = true"
                    placeholder="Поиск по названию..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Добавить должность
                        </button>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="positionColumns"
                        :rows="positions.data"
                        selectable
                        v-model="selectedIds"
                        has-actions
                        empty-message='Должности еще не добавлены. Нажмите "Добавить должность".'
                        :sort="sort"
                        @sort="onSort"
                    >
                        <template #cell-name="{ row: position }">
                            <div class="flex items-center gap-2">
                                <i class="ri-medal-line text-primary"></i>
                                {{ getLocalizedLabel(position.name) }}
                            </div>
                        </template>
                        <template #cell-payroll_role="{ row: position }">
                            <span
                                :class="[
                                    position.payroll_role === 'admin' ? 'bg-primary/10 text-primary' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                                    'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium'
                                ]"
                            >
                                {{ position.payroll_role === 'admin' ? 'Администратор' : 'Исполнитель' }}
                            </span>
                        </template>
                        <template #cell-status="{ row: position }">
                            <span
                                :class="[
                                    position.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger',
                                    'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium'
                                ]"
                            >
                                {{ position.is_active ? 'Активно' : 'Неактивно' }}
                            </span>
                        </template>
                        <template #actions="{ row: position }">
                            <button
                                @click="openModal(position)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                title="Редактировать"
                            >
                                <i class="ri-pencil-line"></i>
                            </button>
                            <button
                                @click="deletePosition(position)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                title="Удалить"
                            >
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="positions" />
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
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Роль в расчёте ЗП</label>
                        <select v-model="filtersForm.payroll_role" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все роли</option>
                            <option value="admin">Администратор</option>
                            <option value="worker">Исполнитель</option>
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

        <!-- Модальное окно (Attex Standard: 50% width) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-xl lg:max-w-2xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingPosition ? 'Редактирование должности' : 'Новая должность' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название должности <span class="text-danger">*</span></label>
                            <input 
                                v-model="form.name" 
                                type="text" 
                                required 
                                placeholder="Например: Менеджер по работе с клиентами" 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Роль в расчёте ЗП <span class="text-danger">*</span></label>
                            <select v-model="form.payroll_role" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                <option value="worker">Исполнитель — ЗП считается с базы услуги (доля бригады)</option>
                                <option value="admin">Администратор — ЗП считается % от чека, начисляется по каждой услуге заказа</option>
                            </select>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                                Определяет, по какой формуле считается зарплата сотрудников с этой должностью. Сами ставки (% или фикс. сумма) настраиваются в
                                <a :href="route('settings.payroll.index')" class="text-primary hover:underline">Настройки → Зарплата</a>.
                            </p>
                        </div>

                        <!-- Toggle Switch (Attex Style) -->
                        <div class="flex items-center pt-2">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Должность активна
                            </label>
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