<script setup>
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head } from '@inertiajs/vue3';
import { useClientSort } from '@/Composables/useClientSort.js';

const props = defineProps({
    tenants: { type: Array, default: () => [] },
});

const formatDate = (dateStr) => dateStr ? new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';

// users/branches/clients/work_orders/last_work_order_at живут внутри row.stats
// (см. #row ниже — colspan-эскейп для несмигрированной БД тенанта, где stats
// вообще null), useClientSort сравнивает только верхнеуровневые ключи — не сортируем.
const columns = [
    { key: 'id', label: 'Тенант', sortable: true },
    { key: 'domain', label: 'Домен', sortable: true },
    { key: 'created_at', label: 'Создан', sortable: true },
    { key: 'users', label: 'Пользователи' },
    { key: 'branches', label: 'Локации' },
    { key: 'clients', label: 'Клиенты' },
    { key: 'work_orders', label: 'Заказ-наряды' },
    { key: 'last_work_order_at', label: 'Последняя активность' },
];

const { sort, onSort, sortedRows: sortedTenants } = useClientSort(() => props.tenants);
</script>

<template>
    <Head title="Тенанты — Admin" />

    <CentralAdminLayout>
        <template #header>Тенанты</template>

        <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
            <div class="overflow-x-auto w-full">
                <DataTable :columns="columns" :rows="sortedTenants" :sort="sort" @sort="onSort" empty-message="Тенантов пока нет.">
                    <!-- Пользователи/Локации/Клиенты/Заказ-наряды/Последняя активность у несмигрированной
                         БД тенанта схлопываются в одну colspan-ячейку — нерегулярная строка, эскейп-хетч #row. -->
                    <template #row="{ row, columns: cols }">
                        <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50">{{ row.id }}</td>
                        <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ row.domain || '—' }}</td>
                        <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ formatDate(row.created_at) }}</td>
                        <template v-if="row.stats">
                            <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ row.stats.users }}</td>
                            <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ row.stats.branches }}</td>
                            <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ row.stats.clients }}</td>
                            <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ row.stats.work_orders }}</td>
                            <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ formatDate(row.stats.last_work_order_at) }}</td>
                        </template>
                        <template v-else>
                            <td :colspan="cols.length - 3" class="py-4 px-6 text-sm text-danger border-b border-gray-100 dark:border-gray-700/50">Не удалось получить данные (БД не смигрирована?)</td>
                        </template>
                    </template>
                </DataTable>
            </div>
        </div>
    </CentralAdminLayout>
</template>
