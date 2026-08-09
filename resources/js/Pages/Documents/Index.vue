<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

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
    }, { preserveState: true, preserveScroll: true });
};

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

const regenerateDocument = (doc) => {
    router.post(route('documents.regenerate', doc.id), {}, { preserveScroll: true });
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
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Номер</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Тип</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Связанная запись</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Юрлицо</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Дата</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="doc in documents.data" :key="doc.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50">
                                    {{ doc.number }}
                                    <i v-if="doc.is_stale" class="ri-error-warning-line text-warning ml-1" title="Данные заказа изменились с момента формирования — рекомендуем перегенерировать"></i>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ doc.title }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ entityLabel(doc.documentable_type) }}</td>
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50">
                                    <Link v-if="entityRoute(doc)" :href="entityRoute(doc)" class="text-primary hover:underline">{{ entityRecordLabel(doc) }}</Link>
                                    <span v-else class="text-gray-400">{{ entityRecordLabel(doc) }}</span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ doc.branch?.legal_entity?.name || '—' }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ formatDate(doc.created_at) }}</td>
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50 text-right space-x-1">
                                    <button v-if="doc.is_stale" @click="regenerateDocument(doc)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-warning/10 text-warning hover:bg-warning hover:text-white" title="Перегенерировать с текущими данными">
                                        <i class="ri-refresh-line"></i>
                                    </button>
                                    <a :href="route('documents.print', doc.id)" target="_blank" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Печать">
                                        <i class="ri-printer-line"></i>
                                    </a>
                                    <a :href="route('documents.download', doc.id)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Скачать PDF">
                                        <i class="ri-download-2-line"></i>
                                    </a>
                                    <button @click="deleteDocument(doc)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="documents.data.length === 0">
                                <td colspan="7" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">Документов пока нет.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="documents" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
