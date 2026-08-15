<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import FinanceNav from '@/Components/FinanceNav.vue';
import Pagination from '@/Components/Pagination.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';

const props = defineProps({
    snapshots: Object,
    accounts: Array,
    filters: Object,
});

const filtersForm = reactive({
    account_id: props.filters?.filters?.account_id || '',
});

const fetchFiltered = useDebounceFn(() => {
    router.get(route('finance.snapshots.index'), {
        filters: filtersForm,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(filtersForm, () => fetchFiltered(), { deep: true });

const { sort, onSort } = useServerSort('finance.snapshots.index', () => props.filters, () => ({ filters: filtersForm }));

const formatMoney = (amount) => {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format((amount || 0) / 100);
};

// account (связь) и transfers (составная ячейка in+out) — не сортируются простым orderBy.
const snapshotColumns = [
    { key: 'date', label: 'Дата', sortable: true, sortKey: 'snapshot_date' },
    { key: 'account', label: 'Счет' },
    { key: 'opening_balance', label: 'Остаток на начало', align: 'right', sortable: true },
    { key: 'income', label: 'Приход', align: 'right', sortable: true, sortKey: 'income_total' },
    { key: 'expense', label: 'Расход', align: 'right', sortable: true, sortKey: 'expense_total' },
    { key: 'transfers', label: 'Переводы вх./исх.', align: 'right' },
    { key: 'closing_balance', label: 'Остаток на конец', align: 'right', sortable: true },
];
</script>

<template>
    <Head title="Остатки по дням" />

    <AuthenticatedLayout>
        <template #header>
            Финансы и Кассы
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">

            <FinanceNav />

            <PageHelper title="Дневные снэпшоты остатков">
                <p>Каждую ночь в 00:00 система фиксирует по каждому счету остаток на начало и конец прошедших суток, а также обороты за день (приход, расход, переводы) — не нужно пересчитывать историю операций вручную для отчётности.</p>
                <p class="mt-2">Если в уже прошедший (но еще не закрытый) день внесли операцию задним числом, снэпшот этого дня и счета пересчитывается автоматически при сохранении операции.</p>
            </PageHelper>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-4">
                <div class="max-w-xs">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Счет</label>
                    <select v-model="filtersForm.account_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                        <option value="">Все счета</option>
                        <option v-for="acc in accounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
                    </select>
                </div>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <DataTable :columns="snapshotColumns" :rows="snapshots.data" :sort="sort" @sort="onSort">
                        <template #cell-date="{ row: s }">
                            {{ new Date(s.snapshot_date).toLocaleDateString('ru-RU', {day: 'numeric', month: 'short', year: 'numeric'}) }}
                        </template>
                        <template #cell-account="{ row: s }">
                            <span class="font-medium">{{ s.account ? s.account.name : '—' }}</span>
                        </template>
                        <template #cell-opening_balance="{ row: s }">{{ formatMoney(s.opening_balance) }}</template>
                        <template #cell-income="{ row: s }">
                            <span class="text-success">+{{ formatMoney(s.income_total) }}</span>
                        </template>
                        <template #cell-expense="{ row: s }">
                            <span class="text-danger">−{{ formatMoney(s.expense_total) }}</span>
                        </template>
                        <template #cell-transfers="{ row: s }">
                            <span class="text-gray-500">+{{ formatMoney(s.transfer_in_total) }} / −{{ formatMoney(s.transfer_out_total) }}</span>
                        </template>
                        <template #cell-closing_balance="{ row: s }">
                            <span class="font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(s.closing_balance) }}</span>
                        </template>
                        <template #empty>
                            Снэпшотов пока нет. Они появляются автоматически каждую ночь в 00:00, либо после ручного запуска <code>php artisan tenants:run snapshots:accounts</code>.
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="snapshots" />
            </div>

        </div>
    </AuthenticatedLayout>
</template>
