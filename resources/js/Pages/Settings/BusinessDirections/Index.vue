<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, useForm, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import axios from 'axios';

const props = defineProps({
    businessDirections: Object,
    filters: Object,
    branches: Array,
});

const isModalOpen = ref(false);
const editingDirection = ref(null);

const form = useForm({
    name: '',
    is_active: true,
    branch_ids: [],
});

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const fetchFiltered = useDebounceFn(() => {
    router.get(route('settings.business-directions.index'), {
        search: search.value,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
// ------------------------------------

const { sort, onSort } = useServerSort('settings.business-directions.index', () => props.filters, () => ({ search: search.value }));

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const bulkDelete = () => {
    if (confirm(`Удалить выбранные направления (${selectedIds.value.length})?`)) {
        router.post(route('settings.business-directions.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('settings.business-directions.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `business_directions_export_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
    } catch (error) {
        console.error("Export failed", error);
        alert("Ошибка при экспорте данных");
    }
};
// ----------------------------------------

const directionColumns = [
    { key: 'name', label: 'Название', sortable: true },
    { key: 'branches', label: 'Локации' },
    { key: 'status', label: 'Статус', sortable: true, sortKey: 'is_active' },
];

const openModal = (direction = null) => {
    editingDirection.value = direction;
    if (direction) {
        form.name = direction.name;
        form.is_active = Boolean(direction.is_active);
        form.branch_ids = direction.branches ? direction.branches.map(b => b.id) : [];
    } else {
        form.reset();
        form.is_active = true;
        form.branch_ids = [];
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingDirection.value = null;
    form.reset();
};

const submit = () => {
    if (editingDirection.value) {
        form.put(route('settings.business-directions.update', editingDirection.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('settings.business-directions.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteDirection = (direction) => {
    if (confirm(`Удалить направление "${direction.name}"?`)) {
        form.delete(route('settings.business-directions.destroy', direction.id));
    }
};
</script>

<template>
    <Head title="Направления" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            
            <!-- Навигация по настройкам (Attex Tabs) -->
            <SettingsNav />

            <!-- Page Helper (Система подсказок) -->
            <PageHelper title="Для чего нужны Направления бизнеса?">
                <p><strong>Направления</strong> — это не про «где» (локация) и не про «от чьего имени» (юрлицо), а про «какая это работа»: логические блоки услуг (например: «Детейлинг», «Мойка», «Оклейка», «Шиномонтаж»). Направление — тег на самой услуге, независимый от локации и юрлица.</p>
                <p>Вы можете указать, в каких именно локациях доступно каждое направление — это поможет отсечь лишние услуги при создании заказ-нарядов в конкретной локации и сделает интерфейс удобнее для администраторов.</p>
                <p class="text-xs mt-2 opacity-80"><i class="ri-lightbulb-line align-middle"></i> Примечание: направление можно использовать и как способ объединить разные локации и разные юрлица по общему для вас признаку — например, «Автосалоны» или «Работа на выезде». Сами локации и организации при этом остаются полностью независимыми (у каждой свои реквизиты, склад, сотрудники), а направление просто даёт возможность увидеть их вместе там, где это удобно.</p>
            </PageHelper>

            <!-- Header Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Направления бизнеса</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Управление видами деятельности (например: Детейлинг, Оклейка, Мойка)
                    </p>
                </div>
            </div>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="направлений" 
                @export="bulkExport" 
                @delete="bulkDelete" 
            />

            <!-- Table Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="false"
                    placeholder="Поиск по названию..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Добавить направление
                        </button>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="directionColumns"
                        :rows="businessDirections.data"
                        selectable
                        v-model="selectedIds"
                        has-actions
                        empty-message="Направления не найдены."
                        :sort="sort"
                        @sort="onSort"
                    >
                        <template #cell-name="{ row: direction }">
                            <div class="flex items-center gap-2">
                                <i class="ri-node-tree text-primary"></i>
                                {{ direction.name }}
                            </div>
                        </template>
                        <template #cell-branches="{ row: direction }">
                            <div class="flex flex-wrap gap-1.5" v-if="direction.branches && direction.branches.length > 0">
                                <span v-for="b in direction.branches" :key="b.id" class="inline-flex items-center gap-1 py-0.5 px-2 rounded bg-gray-100 dark:bg-gray-700 text-xs font-medium text-gray-700 dark:text-gray-300">
                                    <i class="ri-store-2-line"></i> {{ b.name }}
                                </span>
                            </div>
                            <span v-else class="text-xs text-gray-400 dark:text-gray-500">Во всех локациях</span>
                        </template>
                        <template #cell-status="{ row: direction }">
                            <span
                                :class="[
                                    direction.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger',
                                    'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium'
                                ]"
                            >
                                {{ direction.is_active ? 'Активно' : 'Неактивно' }}
                            </span>
                        </template>
                        <template #actions="{ row: direction }">
                            <button
                                @click="openModal(direction)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                title="Редактировать"
                            >
                                <i class="ri-pencil-line"></i>
                            </button>
                            <button
                                @click="deleteDirection(direction)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                title="Удалить"
                            >
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="businessDirections" />
            </div>
        </div>

        <!-- Модальное окно (Attex Standard: 50% width) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl lg:max-w-3xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingDirection ? 'Редактирование направления' : 'Новое направление' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-5">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название направления <span class="text-danger">*</span></label>
                            <input 
                                v-model="form.name" 
                                type="text" 
                                required 
                                placeholder="Например: Детейлинг" 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                            />
                        </div>

                        <!-- Привязка к локациям -->
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-3">В каких локациях доступно это направление?</h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Если не выбрать ни одной, направление будет доступно во всех локациях по умолчанию.</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label v-for="branch in branches" :key="branch.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="branch.id" v-model="form.branch_ids" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-gray-100 transition-colors">{{ branch.name }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Toggle Switch (Attex Style) -->
                        <div class="flex items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Направление активно
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