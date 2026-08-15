<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useServerSort } from '@/Composables/useServerSort.js';

const props = defineProps({
    documents: { type: Object, required: true },
    templates: { type: Array, default: () => [] },
    entityTypes: { type: Object, default: () => ({}) },
    filters: { type: Object, default: () => ({}) },
});

const search = ref(props.filters.search || '');
const documentTemplateId = ref(props.filters.document_template_id || '');
const entityType = ref(props.filters.entity_type || '');
const dateFrom = ref(props.filters.date_from || '');
const dateTo = ref(props.filters.date_to || '');

const applyFilters = () => {
    router.get(route('documents.index'), {
        search: search.value || undefined,
        document_template_id: documentTemplateId.value || undefined,
        entity_type: entityType.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
};

// entity_type/связанная запись/юрлицо — производные от documentable_type/связей,
// не сортируются простым orderBy.
const documentColumns = [
    { key: 'number', label: 'Номер', sortable: true },
    { key: 'title', label: 'Название', sortable: true },
    { key: 'entity_type', label: 'Тип' },
    { key: 'entity_record', label: 'Связанная запись' },
    { key: 'legal_entity', label: 'Юрлицо' },
    { key: 'created_at', label: 'Дата', sortable: true },
];

const { sort, onSort } = useServerSort('documents.index', () => props.filters, () => ({
    search: search.value || undefined,
    document_template_id: documentTemplateId.value || undefined,
    entity_type: entityType.value || undefined,
    date_from: dateFrom.value || undefined,
    date_to: dateTo.value || undefined,
}));

const formatDate = (dateStr) => new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const entityLabel = (documentableType) => {
    const shortKey = (documentableType || '').split('\\').pop();
    const map = { WorkOrder: 'work_order', Transaction: 'transaction', Client: 'client' };
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
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6">
                <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
                    <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Поиск по номеру/названию..." class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                    <select v-model="documentTemplateId" @change="applyFilters" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                        <option value="">Все шаблоны</option>
                        <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <select v-model="entityType" @change="applyFilters" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                        <option value="">Все сущности</option>
                        <option v-for="(label, key) in entityTypes" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <input v-model="dateFrom" @change="applyFilters" type="date" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                    <input v-model="dateTo" @change="applyFilters" type="date" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                </div>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="documentColumns"
                        :rows="documents.data"
                        has-actions
                        :sort="sort"
                        @sort="onSort"
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
                            <span :class="doc.superseded_by_document_id ? 'opacity-50' : ''">{{ doc.branch?.legal_entity?.name || '—' }}</span>
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
    </AuthenticatedLayout>
</template>
