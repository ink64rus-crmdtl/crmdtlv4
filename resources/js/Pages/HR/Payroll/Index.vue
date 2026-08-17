<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import PageHelper from '@/Components/PageHelper.vue';
import HRNav from '@/Components/HRNav.vue';
import DataTable from '@/Components/DataTable.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import { useClientSort } from '@/Composables/useClientSort.js';
import axios from 'axios';

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

// --- Начисления подрядчика (модалка детализации) ---
const contractorModalOpen = ref(false);
const contractorPayroll = ref(null);
const contractorPayrollLoading = ref(false);
const payoutAccountId = ref('');
const selectedContractorIds = ref([]);

const payableContractorEntries = computed(() =>
    (contractorPayroll.value?.entries || []).filter(e => e.status === 'pending' && e.type === 'accrual')
);

const allContractorSelected = computed(() =>
    payableContractorEntries.value.length > 0
    && payableContractorEntries.value.every(e => selectedContractorIds.value.includes(e.id))
);

const selectedContractorTotal = computed(() =>
    (contractorPayroll.value?.entries || [])
        .filter(e => selectedContractorIds.value.includes(e.id))
        .reduce((sum, e) => sum + e.amount, 0)
);

const contractorStatusLabels = {
    pending: 'Ожидает',
    paid: 'Выплачено',
    canceled: 'Отменено',
};

const contractorStatusClasses = {
    pending: 'bg-warning/10 text-warning',
    paid: 'bg-success/10 text-success',
    canceled: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
};

const formatDate = (dateStr) => dateStr ? new Date(dateStr).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }) : '';

const openContractorPayroll = async (contractor) => {
    contractorModalOpen.value = true;
    contractorPayrollLoading.value = true;
    contractorPayroll.value = null;
    selectedContractorIds.value = [];
    try {
        const res = await axios.get(route('hr.payroll.contractor', contractor.id));
        contractorPayroll.value = res.data;
        payoutAccountId.value = res.data.accounts[0]?.id ?? '';
    } finally {
        contractorPayrollLoading.value = false;
    }
};

const closeContractorPayroll = () => {
    contractorModalOpen.value = false;
    contractorPayroll.value = null;
};

const reloadContractor = () => {
    const c = contractorPayroll.value?.contractor;
    if (c) openContractorPayroll(c);
};

const toggleSelectAllContractor = () => {
    const ids = payableContractorEntries.value.map(e => e.id);
    if (allContractorSelected.value) {
        selectedContractorIds.value = selectedContractorIds.value.filter(id => !ids.includes(id));
    } else {
        selectedContractorIds.value = [...new Set([...selectedContractorIds.value, ...ids])];
    }
};

const payContractorEntry = (entry) => {
    if (!payoutAccountId.value) return;
    useForm({ account_id: payoutAccountId.value }).post(route('hr.payroll.payout', entry.id), {
        preserveState: true, preserveScroll: true, onSuccess: reloadContractor,
    });
};

const reverseContractorEntry = (entry) => {
    if (confirm(`Откатить выплату на сумму ${formatMoney(entry.amount)}? Деньги вернутся в кассу, начисление снова станет «Ожидает» и его можно будет выплатить заново.`)) {
        useForm({}).post(route('hr.payroll.reverse-payout', entry.id), {
            preserveState: true, preserveScroll: true, onSuccess: reloadContractor,
        });
    }
};

const cancelContractorEntry = (entry) => {
    if (confirm('Отменить эту запись?')) {
        useForm({}).delete(route('hr.payroll.cancel', entry.id), {
            preserveState: true, preserveScroll: true, onSuccess: reloadContractor,
        });
    }
};

const bulkPayContractor = () => {
    if (selectedContractorIds.value.length === 0 || !payoutAccountId.value) return;
    useForm({ ids: selectedContractorIds.value, account_id: payoutAccountId.value }).post(route('hr.payroll.bulk-payout'), {
        preserveState: true, preserveScroll: true,
        onSuccess: () => { selectedContractorIds.value = []; reloadContractor(); },
    });
};
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
                        has-actions
                        @row-click="contractor => router.visit(route('crm.clients.show', contractor.id))"
                        :sort="contractorSort"
                        @sort="onContractorSort"
                    >
                        <template #actions="{ row: contractor }">
                            <button @click.stop="openContractorPayroll(contractor)" class="w-8 h-8 rounded-full bg-primary/10 text-primary hover:bg-primary hover:text-white transition-colors inline-flex items-center justify-center" title="Начисления и выплаты">
                                <i class="ri-wallet-3-line"></i>
                            </button>
                        </template>
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

        <Modal :show="contractorModalOpen" @close="closeContractorPayroll" max-width="3xl">
            <div v-if="contractorPayrollLoading || !contractorPayroll" class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">
                <i class="ri-loader-4-line animate-spin mr-2"></i> Загружаем начисления...
            </div>
            <div v-else class="flex flex-col max-h-[80vh]">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                        <i class="ri-wallet-3-line text-primary"></i> Начисления подрядчика: {{ contractorPayroll.contractor.name }}
                    </h3>
                    <button @click="closeContractorPayroll" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <div class="px-6 py-3 border-b border-gray-100 dark:border-gray-700/50 grid grid-cols-3 gap-2 text-center bg-gray-50/30 dark:bg-gray-800/20">
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Начислено</p>
                        <p class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(contractorPayroll.balance.accrued_total) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Выплачено</p>
                        <p class="text-sm font-bold text-success">{{ formatMoney(contractorPayroll.balance.paid_total) }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">К выплате</p>
                        <p class="text-sm font-bold" :class="contractorPayroll.balance.balance > 0 ? 'text-primary' : 'text-gray-400'">{{ formatMoney(contractorPayroll.balance.balance) }}</p>
                    </div>
                </div>

                <div v-if="payableContractorEntries.length > 0" class="px-6 py-2 border-b border-gray-100 dark:border-gray-700/50 flex items-center justify-between gap-3 bg-gray-50/30 dark:bg-gray-800/20">
                    <div class="flex items-center gap-3">
                        <label class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400 cursor-pointer select-none">
                            <input type="checkbox" :checked="allContractorSelected" @change="toggleSelectAllContractor" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                            Все ожидающие ({{ payableContractorEntries.length }})
                        </label>
                        <label class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-2">
                            Счёт:
                            <select v-model="payoutAccountId" class="rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1 px-2 text-xs text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option v-for="a in contractorPayroll.accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                            </select>
                        </label>
                    </div>
                    <button v-if="selectedContractorIds.length > 0" @click="bulkPayContractor" class="inline-flex items-center gap-1.5 rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-success text-white hover:bg-success-600 shrink-0">
                        <i class="ri-money-dollar-circle-line"></i> Выплатить выбранные ({{ selectedContractorIds.length }}) — {{ formatMoney(selectedContractorTotal) }}
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto custom-scrollbar divide-y divide-gray-100 dark:divide-gray-700/50">
                    <div v-for="entry in contractorPayroll.entries" :key="entry.id" class="p-4 flex items-start gap-3">
                        <input v-if="entry.status === 'pending' && entry.type === 'accrual'" type="checkbox" :value="entry.id" v-model="selectedContractorIds" class="h-4 w-4 mt-1 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer shrink-0" />
                        <div v-else class="w-4 shrink-0"></div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start gap-2">
                                <div>
                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-bold uppercase bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">Начисление</span>
                                    <p v-if="entry.comment" class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ entry.comment }}</p>
                                    <p class="text-xs text-gray-400 mt-1">{{ formatDate(entry.created_at) }}</p>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(entry.amount) }}</div>
                                    <span :class="[contractorStatusClasses[entry.status], 'inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium mt-1']">{{ contractorStatusLabels[entry.status] }}</span>
                                </div>
                            </div>
                            <div v-if="entry.status === 'pending'" class="flex gap-2 mt-2">
                                <button v-if="entry.type === 'accrual'" @click="payContractorEntry(entry)" class="text-xs font-medium text-primary hover:underline">Выплатить</button>
                                <button @click="cancelContractorEntry(entry)" class="text-xs font-medium text-gray-400 hover:text-danger">Отменить</button>
                            </div>
                            <div v-if="entry.status === 'paid'" class="flex gap-2 mt-2">
                                <button @click="reverseContractorEntry(entry)" class="text-xs font-medium text-warning hover:text-danger">Откатить выплату</button>
                            </div>
                        </div>
                    </div>
                    <div v-if="contractorPayroll.entries.length === 0" class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Начислений по этому подрядчику нет.</div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
