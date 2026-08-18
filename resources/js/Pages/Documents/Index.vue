<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import DataTable from '@/Components/DataTable.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import ColumnSettingsModal from '@/Components/ColumnSettingsModal.vue';
import TableFitToggle from '@/Components/TableFitToggle.vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { ref, reactive, computed, watch } from 'vue';
import { useServerSort } from '@/Composables/useServerSort.js';
import { useDebounceFn } from '@vueuse/core';

const props = defineProps({
    documents: { type: Object, required: true },
    templates: { type: Array, default: () => [] },
    entityTypes: { type: Object, default: () => ({}) },
    legalEntities: { type: Array, default: () => [] },
    availableColumns: { type: Array, default: () => [] },
    listView: { type: Object, default: () => ({ visible_columns: [] }) },
    filters: { type: Object, default: () => ({}) },
});

// --- ПОИСК И ФИЛЬТРЫ (серверные, по образцу остальных Index-страниц) ---
const search = ref(props.filters.search || '');

const initialFilters = {
    document_template_id: props.filters.document_template_id || '',
    entity_type: props.filters.entity_type || '',
    legal_entity_id: props.filters.legal_entity_id || '',
    date_from: props.filters.date_from || '',
    date_to: props.filters.date_to || '',
};

const filtersForm = reactive(initialFilters);
const isFiltersOpen = ref(false);
const isColumnsModalOpen = ref(false);
const fitColumns = ref(localStorage.getItem('documents.fit-columns') === '1');

const fetchFiltered = useDebounceFn(() => {
    router.get(route('documents.index'), {
        search: search.value || undefined,
        document_template_id: filtersForm.document_template_id || undefined,
        entity_type: filtersForm.entity_type || undefined,
        legal_entity_id: filtersForm.legal_entity_id || undefined,
        date_from: filtersForm.date_from || undefined,
        date_to: filtersForm.date_to || undefined,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
watch(filtersForm, () => fetchFiltered(), { deep: true });

const resetFilters = () => {
    Object.keys(filtersForm).forEach(key => {
        filtersForm[key] = '';
    });
};

// --- ДИНАМИЧЕСКИЕ КОЛОНКИ (ListView + list-views.store) ---
const activeColumns = computed(() => {
    const visibleKeys = props.listView?.visible_columns || [];
    return visibleKeys.map(key => props.availableColumns.find(c => c.key === key)).filter(Boolean);
});

// entity_type/связанная запись/юрлицо — производные от documentable_type/связей,
// не сортируются простым orderBy (см. DocumentController::index()).
const SORTABLE_COLUMN_KEYS = ['number', 'title', 'created_at'];
const dataTableColumns = computed(() => activeColumns.value.map(col => ({
    ...col,
    sortable: SORTABLE_COLUMN_KEYS.includes(col.key),
})));

const { sort, onSort } = useServerSort('documents.index', () => props.filters, () => ({
    search: search.value || undefined,
    document_template_id: filtersForm.document_template_id || undefined,
    entity_type: filtersForm.entity_type || undefined,
    legal_entity_id: filtersForm.legal_entity_id || undefined,
    date_from: filtersForm.date_from || undefined,
    date_to: filtersForm.date_to || undefined,
}));

const openColumnsModal = () => {
    isColumnsModalOpen.value = true;
};

const formatDate = (dateStr) => new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const entityLabel = (documentableType) => {
    const shortKey = (documentableType || '').split('\\').pop();
    const map = { WorkOrder: 'work_order', Transaction: 'transaction', Client: 'client', GoodsReceipt: 'goods_receipt', Employee: 'employee' };
    return props.entityTypes[map[shortKey]] || shortKey || '—';
};

const entityRoute = (doc) => {
    const shortKey = (doc.documentable_type || '').split('\\').pop();
    if (!doc.documentable) return null;
    if (shortKey === 'WorkOrder') return route('operations.work-orders.show', doc.documentable.id);
    if (shortKey === 'Client') return route('crm.clients.show', doc.documentable.id);
    return null;
};

const deleteDocument = (doc) => {
    if (confirm(`Удалить документ №${doc.number}? Если это последний выданный номер — следующий документ получит тот же номер.`)) {
        router.delete(route('documents.destroy', doc.id), { preserveScroll: true });
    }
};

const regenerateAsNew = (doc) => {
    router.post(route('documents.regenerate-as-new', doc.id), {}, { preserveScroll: true });
};

const replaceDocument = (doc) => {
    if (confirm(`Заменить документ №${doc.number} актуальными данными? Номер останется прежним, содержимое и дата формирования обновятся.`)) {
        router.post(route('documents.replace', doc.id), {}, { preserveScroll: true });
    }
};

const entityRecordLabel = (doc) => {
    const shortKey = (doc.documentable_type || '').split('\\').pop();
    if (!doc.documentable) return '—';
    if (shortKey === 'WorkOrder') return 'Заказ №' + doc.documentable.id;
    if (shortKey === 'Transaction') return 'Транзакция №' + doc.documentable.id;
    if (shortKey === 'Client') return doc.documentable.name;
    if (shortKey === 'GoodsReceipt') return 'Накладная №' + doc.documentable.id;
    return '#' + doc.documentable.id;
};
</script>

<template>
    <Head title="Документы" />

    <AuthenticatedLayout>
        <template #header>
            Документы
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="Object.values(filtersForm).some(v => v !== '' && v !== null)"
                    @open-filters="isFiltersOpen = true"
                    @open-columns="openColumnsModal"
                    placeholder="Поиск по номеру/названию..."
                >
                    <template #actions>
                        <TableFitToggle v-model="fitColumns" storage-key="documents.fit-columns" />
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="dataTableColumns"
                        :rows="documents.data"
                        has-actions
                        :sort="sort"
                        @sort="onSort"
                        :fit-columns="fitColumns"
                        empty-message="Документов пока нет."
                        row-key="id"
                    >
                        <template #cell-number="{ row: doc }">
                            <span :class="doc.superseded_by_document_id ? 'opacity-50' : ''">
                                {{ doc.number }}
                                <span v-if="doc.superseded_by_document_id" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 ml-1" :title="`Заменён документом №${doc.superseded_by?.number ?? ''}`">заменён</span>
                                <i v-else-if="doc.is_stale" class="ri-error-warning-line text-warning ml-1" title="Данные заказа изменились с момента формирования — рекомендуем обновить документ"></i>
                            </span>
                        </template>

                        <template #cell-title="{ row: doc }">
                            <span :class="doc.superseded_by_document_id ? 'opacity-50' : ''">{{ doc.title }}</span>
                        </template>

                        <template #cell-entity_type="{ row: doc }">
                            <span :class="doc.superseded_by_document_id ? 'opacity-50' : ''">{{ entityLabel(doc.documentable_type) }}</span>
                        </template>

                        <template #cell-entity_record="{ row: doc }">
                            <span :class="doc.superseded_by_document_id ? 'opacity-50' : ''">
                                <Link v-if="entityRoute(doc)" :href="entityRoute(doc)" class="text-primary hover:underline">{{ entityRecordLabel(doc) }}</Link>
                                <span v-else class="text-gray-400">{{ entityRecordLabel(doc) }}</span>
                            </span>
                        </template>

                        <template #cell-legal_entity="{ row: doc }">
                            <span :class="doc.superseded_by_document_id ? 'opacity-50' : ''">{{ doc.legal_entity_name || '—' }}</span>
                        </template>

                        <template #cell-created_at="{ row: doc }">
                            <span :class="doc.superseded_by_document_id ? 'opacity-50' : ''">{{ formatDate(doc.created_at) }}</span>
                        </template>

                        <template #actions="{ row: doc }">
                            <template v-if="doc.is_stale && !doc.superseded_by_document_id">
                                <button @click="regenerateAsNew(doc)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-warning/10 text-warning hover:bg-warning hover:text-white" title="Сформировать новый документ (этот сохранится в истории)">
                                    <i class="ri-file-add-line"></i>
                                </button>
                                <button @click="replaceDocument(doc)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-warning/10 text-warning hover:bg-warning hover:text-white" title="Заменить этот документ актуальными данными (номер тот же)">
                                    <i class="ri-refresh-line"></i>
                                </button>
                            </template>
                            <a :href="route('documents.print', doc.id)" target="_blank" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Печать">
                                <i class="ri-printer-line"></i>
                            </a>
                            <a :href="route('documents.download', doc.id)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Скачать PDF">
                                <i class="ri-download-2-line"></i>
                            </a>
                            <button @click="deleteDocument(doc)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="documents" />
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
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Шаблон</label>
                        <select v-model="filtersForm.document_template_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все шаблоны</option>
                            <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип записи</label>
                        <select v-model="filtersForm.entity_type" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все сущности</option>
                            <option v-for="(label, key) in entityTypes" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Юрлицо</label>
                        <select v-model="filtersForm.legal_entity_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все юрлица</option>
                            <option v-for="le in legalEntities" :key="le.id" :value="le.id">{{ le.name }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата с</label>
                            <input v-model="filtersForm.date_from" type="date" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата по</label>
                            <input v-model="filtersForm.date_to" type="date" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                        </div>
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

        <!-- Настройка столбцов -->
        <ColumnSettingsModal
            :show="isColumnsModalOpen"
            entity-type="document"
            :available-columns="availableColumns"
            :visible-columns="listView.visible_columns || []"
            @close="isColumnsModalOpen = false"
        />
    </AuthenticatedLayout>
</template>