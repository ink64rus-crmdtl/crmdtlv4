<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import PageHelper from '@/Components/PageHelper.vue';
import HRNav from '@/Components/HRNav.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import { useClientSort } from '@/Composables/useClientSort.js';

const props = defineProps({
    employees: Object,
    contractors: { type: Array, default: () => [] },
    filters: Object,
});

const search = ref(props.filters?.search || '');

const fetchFiltered = useDebounceFn(() => {
    router.get(route('hr.payroll.index'), {
        search: search.value,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());

const { sort, onSort } = useServerSort('hr.payroll.index', () => props.filters, () => ({ search: search.value }));

const { sort: contractorSort, onSort: onContractorSort, sortedRows: sortedContractors } = useClientSort(() => props.contractors);

const getLocalizedLabel = (label) => {
    if (!label) return '';
    if (typeof label === 'string') {
        try { label = JSON.parse(label); } catch (e) { return label; }
    }
    return label['ru'] || label['en'] || Object.values(label)[0] || '';
};

const formatMoney = (cents) => {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format((cents || 0) / 100);
};

// accrued/paid/deductions/balance — агрегаты, считаются в PHP уже ПОСЛЕ
// серверной пагинации/сортировки Employee, сортировать их через orderBy
// нельзя (сортировка отразила бы только текущую страницу).
const employeeColumns = [
    { key: 'name', label: 'Сотрудник', sortable: true, sortKey: 'last_name' },
    { key: 'position', label: 'Должность' },
    { key: 'accrued', label: 'Начислено', align: 'right' },
    { key: 'paid', label: 'Выплачено', align: 'right' },
    { key: 'deductions', label: 'Штрафы', align: 'right' },
    { key: 'balance', label: 'К выплате', align: 'right' },
];

// Подрядчики — не пагинированный PHP-массив (см. contractorSettlements()),
// поэтому сортировка полностью на клиенте и доступна по любой колонке.
const contractorColumns = [
    { key: 'name', label: 'Подрядчик', sortable: true },
    { key: 'phone', label: 'Телефон', sortable: true },
    { key: 'accrued', label: 'Начислено', align: 'right', sortable: true, sortKey: 'accrued_total' },
    { key: 'paid', label: 'Выплачено', align: 'right', sortable: true, sortKey: 'paid_total' },
    { key: 'balance', label: 'К выплате', align: 'right', sortable: true },
];
</script>

<template>
    <Head title="Взаиморасчёты с сотрудниками" />

    <AuthenticatedLayout>
        <template #header>
            Сотрудники и HR
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">

            <HRNav />

            <PageHelper title="Как считается баланс">
                <p><strong>Начислено</strong> — сумма всех начислений (по заказам, вручную, оклад), кроме отменённых.</p>
                <p><strong>Выплачено</strong> — сколько из начисленного уже реально выплачено через кассу.</p>
                <p><strong>Штрафы</strong> — сумма ещё не отменённых удержаний; деньгами отдельно не выплачиваются, а сразу уменьшают баланс.</p>
                <p><strong>К выплате (баланс)</strong> = Начислено − Выплачено − Штрафы. Подробный список начислений и кнопка «Выплатить» — в карточке сотрудника, вкладка «Зарплата».</p>
            </PageHelper>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Взаиморасчёты с сотрудниками</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Баланс начислений и выплат по каждому активному сотруднику
                    </p>
                </div>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="false"
                    placeholder="Поиск по имени, телефону..."
                >
                    <template #actions>
                        <Link :href="route('settings.payroll.index')" class="hidden sm:inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                            Настройки зарплаты
                        </Link>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="employeeColumns"
                        :rows="employees.data"
                        row-clickable
                        @row-click="employee => router.visit(route('hr.employees.show', employee.id))"
                        :sort="sort"
                        @sort="onSort"
                        empty-message="Активных сотрудников не найдено."
                    >
                        <template #cell-name="{ row: employee }">
                            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ employee.last_name }} {{ employee.first_name }}</span>
                        </template>
                        <template #cell-position="{ row: employee }">
                            {{ employee.position ? getLocalizedLabel(employee.position.name) : '—' }}
                        </template>
                        <template #cell-accrued="{ row: employee }">{{ formatMoney(employee.accrued_total) }}</template>
                        <template #cell-paid="{ row: employee }">
                            <span class="text-success">{{ formatMoney(employee.paid_total) }}</span>
                        </template>
                        <template #cell-deductions="{ row: employee }">
                            <span class="text-danger">{{ employee.deductions_total > 0 ? '− ' + formatMoney(employee.deductions_total) : '—' }}</span>
                        </template>
                        <template #cell-balance="{ row: employee }">
                            <span class="font-bold" :class="employee.balance > 0 ? 'text-primary' : 'text-gray-400'">{{ formatMoney(employee.balance) }}</span>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="employees" />
            </div>

            <!-- Взаиморасчёты с подрядчиками — отдельным блоком: это клиенты
                 (с ролью «Подрядчик»), а не сотрудники, у них своя карточка и
                 нет должности/штрафов. Показываем только тех, с кем реально
                 есть расчёты, поэтому список не нуждается в пагинации. -->
            <div v-if="contractors.length > 0" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                        <i class="ri-briefcase-line text-purple-600 dark:text-purple-400"></i> Взаиморасчёты с подрядчиками
                    </h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Клиенты с ролью «Подрядчик», привлечённые как исполнители услуг.</p>
                </div>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="contractorColumns"
                        :rows="sortedContractors"
                        row-clickable
                        @row-click="contractor => router.visit(route('crm.clients.show', contractor.id))"
                        :sort="contractorSort"
                        @sort="onContractorSort"
                    >
                        <template #cell-name="{ row: contractor }">
                            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ contractor.name }}</span>
                            <span v-if="contractor.is_deleted" class="ml-1 text-xs font-normal text-gray-400">(удалён)</span>
                        </template>
                        <template #cell-phone="{ row: contractor }">{{ contractor.phone || '—' }}</template>
                        <template #cell-accrued="{ row: contractor }">{{ formatMoney(contractor.accrued_total) }}</template>
                        <template #cell-paid="{ row: contractor }">
                            <span class="text-success">{{ formatMoney(contractor.paid_total) }}</span>
                        </template>
                        <template #cell-balance="{ row: contractor }">
                            <span class="font-bold" :class="contractor.balance > 0 ? 'text-primary' : 'text-gray-400'">{{ formatMoney(contractor.balance) }}</span>
                        </template>
                    </DataTable>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
