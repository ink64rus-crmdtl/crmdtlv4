<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import FinanceNav from '@/Components/FinanceNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import ColumnSettingsModal from '@/Components/ColumnSettingsModal.vue';
import DataTable from '@/Components/DataTable.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import Modal from '@/Components/Modal.vue';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import axios from 'axios';

const props = defineProps({
    transactions: Object,
    accounts: Array,
    branches: Array,
    categories: Array,
    clients: Array,
    employees: Array,
    clientRoles: { type: Array, default: () => [] },
    baseOrders: Array,
    positions: { type: Array, default: () => [] },
    tenantCountry: { type: String, default: 'RU' },
    filters: Object,
    closedThroughDate: { type: String, default: null },
    availableColumns: { type: Array, default: () => [] },
    listView: { type: Object, default: () => ({ visible_columns: [] }) },
});

const page = usePage();

const isModalOpen = ref(false);
const isColumnsModalOpen = ref(false);

const activeColumns = computed(() => {
    return props.listView.visible_columns
        .map(key => props.availableColumns.find(c => c.key === key))
        .filter(Boolean);
});
// Сортировка — только по реальным колонкам таблицы transactions (белый список
// зеркалит TransactionController::index()). branch/account/category — связи,
// не сортируются простым orderBy.
const SORT_KEY_MAP = { date: 'transaction_date', reconciled: 'is_reconciled' };
const SORTABLE_COLUMN_KEYS = ['date', 'type', 'amount', 'comment', 'reconciled'];
const dataTableColumns = computed(() => activeColumns.value.map(col => ({
    ...col,
    align: col.key === 'amount' ? 'right' : (col.key === 'reconciled' ? 'center' : undefined),
    cellClass: col.key === 'amount' ? 'font-bold' : undefined,
    sortable: SORTABLE_COLUMN_KEYS.includes(col.key),
    sortKey: SORT_KEY_MAP[col.key],
})));
const editingTransaction = ref(null);

const todayIso = () => new Date().toISOString().slice(0, 10);

const form = useForm({
    type: 'expense',
    account_id: '',
    from_account_id: '',
    to_account_id: '',
    branch_id: page.props.current_branch_id || (props.branches.length > 0 ? props.branches[0].id : ''),
    transaction_category_id: '',
    amount: 0,
    transaction_date: todayIso(),
    comment: '',
    counterparty: '',
    counterparty_type: '',
    counterparty_id: '',
    work_order_id: '',
});

// --- КОНТРАГЕНТ И ОСНОВАНИЕ (Фаза А) ---

const employeeFullName = (e) => [e.last_name, e.first_name, e.middle_name].filter(Boolean).join(' ') || 'Сотрудник #' + e.id;

// Значение — закодированная строка "client:5" / "employee:3": SearchableSelect
// эмитит только value, тип нужно переносить вместе с id.
const clientOptions = computed(() => (props.clients || []).map(c => ({
    value: `client:${c.id}`,
    label: `${c.name}${c.phone ? ' — ' + c.phone : ''}`,
    roles: (c.roles || []).map(r => r.value),
})));

const employeeOptions = computed(() => (props.employees || []).map(e => ({
    value: `employee:${e.id}`,
    label: `Сотрудник: ${employeeFullName(e)}`,
})));

// Фильтрация контрагентов по выбранной статье операции:
// «Выплата зарплаты» — только сотрудники (зарплата платится им, не контрагентам);
// «Оплата поставщику» — только клиенты с ролью «Поставщик»;
// «Возврат клиенту» — только клиенты с ролью «Клиент»;
// остальные статьи — полный список клиентов и сотрудников.
const selectedCategory = computed(() => (props.categories || []).find(c => c.id === form.transaction_category_id) || null);
const isOrderPayment = computed(() => selectedCategory.value?.value === 'order_payment');
const isPayrollPayment = computed(() => selectedCategory.value?.value === 'payroll_payment');
const isPurchasePayment = computed(() => selectedCategory.value?.value === 'purchase_payment');
const isRefund = computed(() => selectedCategory.value?.value === 'refund');

const counterpartyOptions = computed(() => {
    if (isPayrollPayment.value) {
        return employeeOptions.value;
    }
    if (isPurchasePayment.value) {
        return clientOptions.value.filter(c => c.roles.includes('supplier'));
    }
    if (isRefund.value) {
        return clientOptions.value.filter(c => c.roles.includes('client'));
    }
    return [...clientOptions.value, ...employeeOptions.value];
});

const baseOrderOptions = computed(() => (props.baseOrders || []).map(o => ({
    value: String(o.id),
    label: `#${String(o.id).padStart(6, '0')} — ${o.client?.name || 'без клиента'}`,
})));

const orderClientMap = computed(() => {
    const map = {};
    (props.baseOrders || []).forEach(o => { map[String(o.id)] = o.client_id; });
    return map;
});

// Раскодирование контрагента в пару (тип, id) для отправки на бэкенд
watch(() => form.counterparty, (value) => {
    if (!value) {
        form.counterparty_type = '';
        form.counterparty_id = '';
        return;
    }
    const [kind, id] = value.split(':');
    form.counterparty_type = kind === 'employee' ? 'App\\Models\\Employee' : 'App\\Models\\Client';
    form.counterparty_id = Number(id) || '';
});

// Выбор заказ-наряда как основания автоматически подставляет клиента заказа
// и статью «Оплата заказа» (если она ещё не выбрана вручную)
watch(() => form.work_order_id, (orderId) => {
    if (orderId) {
        const clientId = orderClientMap.value[String(orderId)];
        if (clientId) {
            form.counterparty = `client:${clientId}`;
        }
        const orderPayment = (props.categories || []).find(c => c.value === 'order_payment');
        if (orderPayment && !form.transaction_category_id) {
            form.transaction_category_id = orderPayment.id;
        }
    }
});

// Смена статьи: поле «Основание» существует только при статье «Оплата заказа» —
// при уходе с неё привязку к заказу сбрасываем, иначе она молча ушла бы в запрос
// со статьёй, для которой основание не осмысленно. Контрагент, не проходящий
// в новый фильтр списка (например, клиент при статье «Выплата зарплаты»),
// тоже сбрасывается, чтобы в форме не висело значение, которого нет в списке.
watch(() => form.transaction_category_id, () => {
    if (!isOrderPayment.value && form.work_order_id) {
        const autoClient = orderClientMap.value[String(form.work_order_id)];
        if (autoClient && form.counterparty === `client:${autoClient}`) {
            form.counterparty = '';
        }
        form.work_order_id = '';
    }
    if (form.counterparty && !counterpartyOptions.value.some(o => o.value === form.counterparty)) {
        form.counterparty = '';
    }
});

const openModal = () => {
    editingTransaction.value = null;
    form.reset();
    form.branch_id = page.props.current_branch_id || (props.branches.length > 0 ? props.branches[0].id : '');
    form.type = 'expense';
    form.transaction_date = todayIso();
    if (props.accounts.length > 0) {
        form.account_id = props.accounts[0].id;
    }
    isModalOpen.value = true;
};

const openEditModal = (transaction) => {
    editingTransaction.value = transaction;
    form.reset();
    form.clearErrors();
    form.type = transaction.type;
    form.account_id = transaction.account_id;
    form.branch_id = transaction.branch_id;
    form.transaction_category_id = transaction.transaction_category_id || '';
    form.amount = transaction.amount / 100;
    form.transaction_date = transaction.transaction_date;
    form.comment = transaction.comment || '';
    // Контрагент редактируется только у операций без основания
    if (transaction.counterparty_type && !transaction.payable_type) {
        const kind = transaction.counterparty_type === 'App\\Models\\Employee' ? 'employee' : 'client';
        form.counterparty = `${kind}:${transaction.counterparty_id}`;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingTransaction.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingTransaction.value) {
        // Бэкенд сам валидирует и берет только редактируемые поля (amount, transaction_date, comment,
        // transaction_category_id) — счет/тип/локация в форме нужны только для отображения и игнорируются.
        form.put(route('finance.transactions.update', editingTransaction.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('finance.transactions.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

// --- БЫСТРОЕ СОЗДАНИЕ НА ЛЕТУ (статья / контрагент) ---
// Паттерн «кнопка „+“ рядом с полем» — CLAUDE.md про пополняемые списки:
// компактная модалка на существующий store()-роут, preserveState — обязательно
// (иначе Inertia пересоздаст страницу и разорвёт открытую форму операции),
// новая запись находится диффом списков до/после и подставляется в форму.

const isQuickCategoryModalOpen = ref(false);
const isQuickCounterpartyModalOpen = ref(false);
const quickCounterpartyKind = ref('client');

const quickCategoryForm = useForm({
    name: '',
    type: 'expense',
});

const quickClientForm = useForm({
    branch_id: '',
    type: 'b2c',
    name: '',
    phone: '',
    phone_required: true,
    role_ids: [],
});

const quickEmployeeForm = useForm({
    branch_id: '',
    first_name: '',
    last_name: '',
    middle_name: '',
    phone: '',
    position_id: '',
    type: 'staff',
    has_crm_access: false,
});

// Отчество обязательно на бэкенде только для RU/BY/KZ (EmployeeController::store)
const needsMiddleName = computed(() => ['RU', 'BY', 'KZ'].includes(props.tenantCountry));

const positionOptions = computed(() => (props.positions || []).map(p => ({
    value: String(p.id),
    label: getLocalizedLabel(p.name),
})));

const quickClientRoleName = computed(() => {
    const role = (props.clientRoles || []).find(r => quickClientForm.role_ids.includes(r.id));
    return role ? getLocalizedLabel(role.label) : '';
});

const openQuickCategoryModal = () => {
    quickCategoryForm.reset();
    quickCategoryForm.clearErrors();
    quickCategoryForm.type = form.type === 'expense' ? 'expense' : 'income';
    isQuickCategoryModalOpen.value = true;
};

const closeQuickCategoryModal = () => {
    isQuickCategoryModalOpen.value = false;
    quickCategoryForm.reset();
    quickCategoryForm.clearErrors();
};

const submitQuickCategory = () => {
    const existingIds = new Set(props.categories.map(c => c.id));
    quickCategoryForm.post(route('finance.categories.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const created = props.categories.find(c => !existingIds.has(c.id));
            if (created) {
                form.transaction_category_id = created.id;
            }
            closeQuickCategoryModal();
        },
    });
};

const openQuickCounterpartyModal = () => {
    quickClientForm.reset();
    quickClientForm.clearErrors();
    quickEmployeeForm.reset();
    quickEmployeeForm.clearErrors();
    // При статье «Выплата зарплаты» создаём на лету сотрудника, иначе — клиента;
    // для «Оплаты поставщику» / «Возврата клиенту» роль назначается сразу,
    // иначе созданный клиент не попадёт в отфильтрованный по роли список.
    quickCounterpartyKind.value = isPayrollPayment.value ? 'employee' : 'client';
    const roleIdByValue = (v) => (props.clientRoles || []).find(r => r.value === v)?.id;
    if (isPurchasePayment.value) {
        const supplierRoleId = roleIdByValue('supplier');
        quickClientForm.role_ids = supplierRoleId ? [supplierRoleId] : [];
    } else if (isRefund.value) {
        const clientRoleId = roleIdByValue('client');
        quickClientForm.role_ids = clientRoleId ? [clientRoleId] : [];
    }
    const branchId = form.branch_id || (props.branches[0]?.id ?? '');
    quickClientForm.branch_id = branchId;
    quickEmployeeForm.branch_id = branchId;
    isQuickCounterpartyModalOpen.value = true;
};

const closeQuickCounterpartyModal = () => {
    isQuickCounterpartyModalOpen.value = false;
    quickClientForm.reset();
    quickClientForm.clearErrors();
    quickEmployeeForm.reset();
    quickEmployeeForm.clearErrors();
};

const submitQuickClient = () => {
    const existingIds = new Set(props.clients.map(c => c.id));
    quickClientForm.post(route('crm.clients.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const created = props.clients.find(c => !existingIds.has(c.id));
            if (created) {
                form.counterparty = `client:${created.id}`;
            }
            closeQuickCounterpartyModal();
        },
    });
};

const submitQuickEmployee = () => {
    const existingIds = new Set(props.employees.map(e => e.id));
    quickEmployeeForm.post(route('hr.employees.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const created = props.employees.find(e => !existingIds.has(e.id));
            if (created) {
                form.counterparty = `employee:${created.id}`;
            }
            closeQuickCounterpartyModal();
        },
    });
};

const deleteTransaction = (transaction) => {
    if (confirm(`Отменить транзакцию на сумму ${formatMoney(transaction.amount)}? Баланс счета будет восстановлен.`)) {
        router.delete(route('finance.transactions.destroy', transaction.id));
    }
};

const toggleReconciled = (transaction) => {
    router.patch(route('finance.transactions.reconcile', transaction.id), {}, { preserveScroll: true });
};

// Дата операции в уже закрытом периоде — сумму/дату такой транзакции менять нельзя.
const isDateClosed = (date) => {
    if (!props.closedThroughDate || !date) return false;
    return new Date(date) <= new Date(props.closedThroughDate);
};

// Почему поле недоступно для правки — используется в модалке редактирования, чтобы не гадать.
const lockReason = (field) => {
    if (!editingTransaction.value) return null;

    if (['account', 'type', 'branch'].includes(field)) {
        return 'Меняется только через отмену операции и создание новой.';
    }

    if (['amount', 'transaction_date'].includes(field)) {
        if (editingTransaction.value.is_reconciled) {
            return 'Операция сверена с банком — снимите отметку сверки, чтобы изменить.';
        }
        if (isDateClosed(editingTransaction.value.transaction_date)) {
            return 'Период закрыт — операция недоступна для правки.';
        }
        if (field === 'amount' && editingTransaction.value.type === 'transfer') {
            return 'Сумму перевода нельзя менять — отмените операцию и создайте новую.';
        }
    }

    return null;
};

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const filtersForm = reactive({
    account_id: props.filters?.filters?.account_id || '',
    branch_id: props.filters?.filters?.branch_id || '',
    type: props.filters?.filters?.type || '',
    transaction_category_id: props.filters?.filters?.transaction_category_id || '',
    is_reconciled: props.filters?.filters?.is_reconciled || '',
    counterparty: props.filters?.filters?.counterparty || '',
    work_order_id: props.filters?.filters?.work_order_id || '',
});

const isFiltersOpen = ref(false);

// Переход по ссылке "показать операцию" из карточки заказа — filters.id не входит в filtersForm,
// поэтому не сбрасывается автоматически другими фильтрами, но и не считается "активным фильтром" в тулбаре.
const deepLinkedTransactionId = computed(() => props.filters?.filters?.id || null);

const fetchFiltered = useDebounceFn(() => {
    router.get(route('finance.transactions.index'), {
        search: search.value,
        filters: filtersForm,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
watch(filtersForm, () => fetchFiltered(), { deep: true });

const { sort, onSort } = useServerSort('finance.transactions.index', () => props.filters, () => ({ search: search.value, filters: filtersForm }));

const resetFilters = () => {
    filtersForm.account_id = '';
    filtersForm.branch_id = '';
    filtersForm.type = '';
    filtersForm.transaction_category_id = '';
    filtersForm.is_reconciled = '';
    filtersForm.counterparty = '';
    filtersForm.work_order_id = '';
};
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const bulkExport = async () => {
    try {
        const response = await axios.post(route('finance.transactions.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `transactions_export_${new Date().toISOString().slice(0,10)}.csv`);
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

const formatMoney = (amount) => {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(amount / 100);
};

const transactionTypes = {
    'income': { label: 'Доход', class: 'bg-success/10 text-success', icon: 'ri-arrow-right-down-line' },
    'expense': { label: 'Расход', class: 'bg-danger/10 text-danger', icon: 'ri-arrow-right-up-line' },
    'transfer': { label: 'Перевод', class: 'bg-info/10 text-info', icon: 'ri-arrow-left-right-line' },
};

// Вычисляем общие балансы по счетам для дашборда.
// Счет "Бонусы" — виртуальный (не реальные деньги), поэтому не входит в общий баланс наличных/безнала.
const totalBalance = computed(() => {
    return props.accounts.filter(acc => acc.type !== 'bonus').reduce((sum, acc) => sum + acc.balance, 0);
});
</script>

<template>
    <Head title="История операций" />

    <AuthenticatedLayout>
        <template #header>
            Финансы и Кассы
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <FinanceNav />

            <PageHelper title="Как устроено редактирование операций">
                <p>После проведения операции можно менять сумму, дату, статью и комментарий — баланс счета пересчитывается на разницу. Тип операции, счет и локацию менять нельзя (для этого отмените операцию и проведите новую).</p>
                <p class="mt-2"><strong>Сверка с банком:</strong> отметьте операцию как сверенную после сопоставления с банковской выпиской — сумму и дату сверенной операции нельзя изменить, пока отметка не снята.</p>
                <p class="mt-2"><strong>Закрытие периода:</strong>
                    <template v-if="closedThroughDate">период закрыт по {{ closedThroughDate }} — операции с такой или более ранней датой создавать/менять/отменять нельзя.</template>
                    <template v-else>периоды пока не закрывались — см. раздел «Закрытие периода».</template>
                </p>
            </PageHelper>

            <!-- Блок ошибок -->
            <div v-if="page.props.errors.error" class="p-4 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-start gap-3">
                <i class="ri-error-warning-fill text-xl shrink-0"></i>
                <div>
                    <p class="font-bold mb-1">Ошибка:</p>
                    <p>{{ page.props.errors.error }}</p>
                </div>
            </div>

            <!-- Баннер "показана одна операция" при переходе по ссылке из карточки заказа -->
            <div v-if="deepLinkedTransactionId" class="p-3 bg-info/5 border border-info/20 rounded-md text-sm text-gray-600 dark:text-gray-400 flex items-center justify-between gap-3">
                <span><i class="ri-filter-3-line text-info mr-1"></i> Показана операция №{{ deepLinkedTransactionId }}</span>
                <Link :href="route('finance.transactions.index')" class="shrink-0 text-info hover:text-info-600 font-medium">Показать все</Link>
            </div>

            <!-- Dashboard Балансов -->
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-5">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Общий баланс</p>
                    <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(totalBalance) }}</p>
                </div>
                <div v-for="acc in accounts.slice(0, 3)" :key="acc.id" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-5">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 truncate" :title="acc.name">
                        {{ acc.name }}
                        <span v-if="acc.type === 'bonus'" class="normal-case font-medium">(не наличные)</span>
                    </p>
                    <p class="text-xl font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(acc.balance) }}</p>
                </div>
            </div>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length"
                noun="записей"
                @export="bulkExport"
                hide-delete
            />

            <!-- Table Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="Object.values(filtersForm).some(v => v !== '' && v !== null)"
                    @open-filters="isFiltersOpen = true"
                    @open-columns="isColumnsModalOpen = true"
                    placeholder="Поиск по комментарию..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Новая операция
                        </button>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="dataTableColumns"
                        :rows="transactions.data"
                        selectable
                        v-model="selectedIds"
                        has-actions
                        empty-message="Операции не найдены."
                        :sort="sort"
                        @sort="onSort"
                    >
                        <template #cell-date="{ row: tx }">
                            {{ new Date(tx.transaction_date).toLocaleDateString('ru-RU', {day: 'numeric', month: 'short', year: 'numeric'}) }}
                            <i v-if="tx.edited_at" class="ri-edit-2-line text-gray-400 ml-1" :title="`Отредактировано ${new Date(tx.edited_at).toLocaleString('ru-RU')}${tx.editor ? ' — ' + tx.editor.name : ''}`"></i>
                        </template>
                        <template #cell-type="{ row: tx }">
                            <span :class="[transactionTypes[tx.type]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                <i :class="transactionTypes[tx.type]?.icon"></i> {{ transactionTypes[tx.type]?.label || tx.type }}
                            </span>
                        </template>
                        <template #cell-branch="{ row: tx }">
                            <span class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300" :title="tx.branch && tx.branch.deleted_at ? 'Локация удалена из системы' : ''">
                                <i class="ri-store-2-line"></i> {{ tx.branch ? tx.branch.name : '—' }}<span v-if="tx.branch && tx.branch.deleted_at" class="opacity-70">(удалена)</span>
                            </span>
                        </template>
                        <template #cell-account="{ row: tx }">
                            <span class="font-medium">{{ tx.account ? tx.account.name : '—' }}</span>
                        </template>
                        <template #cell-category="{ row: tx }">
                            {{ tx.category ? getLocalizedLabel(tx.category.name) : '—' }}
                        </template>
                        <template #cell-counterparty="{ row: tx }">
                            <span v-if="tx.counterparty_label" class="inline-flex items-center gap-1.5">
                                <i :class="[tx.counterparty_kind === 'employee' ? 'bg-info/10 text-info ri-user-star-line' : 'bg-primary/10 text-primary ri-user-3-line', 'w-7 h-7 rounded-full flex items-center justify-center text-sm shrink-0']"></i>
                                <span class="font-medium truncate max-w-[180px]" :title="tx.counterparty_label">{{ tx.counterparty_label }}</span>
                            </span>
                            <span v-else class="text-gray-400">—</span>
                        </template>
                        <template #cell-base="{ row: tx }">
                            <template v-if="tx.payable_type === 'App\\Models\\WorkOrder'">
                                <Link :href="route('operations.work-orders.show', tx.payable_id)" class="text-primary hover:underline font-medium">
                                    Заказ-наряд #{{ String(tx.payable_id).padStart(6, '0') }}
                                </Link>
                            </template>
                            <template v-else-if="tx.payable_type === 'App\\Models\\GoodsReceipt'">
                                <span class="font-medium">Приходная накладная №{{ tx.payable_id }}</span>
                            </template>
                            <template v-else-if="tx.payable_type === 'App\\Models\\Payroll'">
                                <span class="font-medium">Выплата ЗП #{{ tx.payable_id }}</span>
                            </template>
                            <span v-else class="text-gray-400">—</span>
                        </template>
                        <template #cell-amount="{ row: tx }">
                            <span :class="tx.type === 'income' ? 'text-success' : (tx.type === 'expense' ? 'text-danger' : 'text-gray-800 dark:text-gray-200')">
                                {{ tx.type === 'income' ? '+' : (tx.type === 'expense' ? '-' : '') }}{{ formatMoney(tx.amount) }}
                            </span>
                        </template>
                        <template #cell-comment="{ row: tx }">
                            <div class="text-xs text-gray-500 truncate max-w-xs" :title="tx.comment">{{ tx.comment || '—' }}</div>
                        </template>
                        <template #cell-reconciled="{ row: tx }">
                            <button
                                @click="toggleReconciled(tx)"
                                :class="[tx.is_reconciled ? 'bg-success/10 text-success' : 'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500', 'inline-flex items-center justify-center h-7 w-7 rounded-full transition-colors hover:opacity-80']"
                                :title="tx.is_reconciled ? `Сверено ${tx.reconciled_at ? new Date(tx.reconciled_at).toLocaleString('ru-RU') : ''}${tx.reconciler ? ' — ' + tx.reconciler.name : ''} (клик — снять отметку)` : 'Отметить как сверенное с банковской выпиской'"
                            >
                                <i class="ri-bank-line"></i>
                            </button>
                        </template>
                        <template #actions="{ row: tx }">
                            <button @click="openEditModal(tx)" class="text-primary hover:text-primary-600 transition-colors p-1" title="Редактировать">
                                <i class="ri-pencil-line"></i>
                            </button>
                            <button @click="deleteTransaction(tx)" class="text-danger hover:text-danger-600 transition-colors p-1" title="Отменить транзакцию">
                                <i class="ri-arrow-go-back-line"></i>
                            </button>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="transactions" />
            </div>
        </div>

        <!-- Модальное окно Операции -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingTransaction ? 'Редактирование операции' : 'Новая финансовая операция' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-6">

                        <div v-if="Object.keys(form.errors).length" class="p-3 rounded-md bg-danger/10 border border-danger/20 text-sm text-danger">
                            <p v-for="(msg, key) in form.errors" :key="key" class="flex items-start gap-2">
                                <i class="ri-error-warning-fill shrink-0 mt-0.5"></i> {{ msg }}
                            </p>
                        </div>

                        <div v-if="editingTransaction" class="p-3 rounded-md bg-info/5 border border-info/20 text-xs text-gray-600 dark:text-gray-400">
                            <i class="ri-information-line text-info mr-1"></i>
                            Тип, счет и локацию операции менять нельзя — доступны сумма, дата, статья, комментарий и контрагент (если операция без основания). Если нужно перенести операцию на другой счет — отмените её и проведите заново.
                        </div>

                        <!-- Выбор типа операции -->
                        <div v-if="!editingTransaction" class="grid grid-cols-3 gap-3">
                            <label :class="[form.type === 'expense' ? 'border-danger bg-danger/5 ring-1 ring-danger' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-3 shadow-sm focus:outline-none transition-all items-center justify-center text-center']">
                                <input type="radio" v-model="form.type" value="expense" class="sr-only" />
                                <span class="text-sm font-semibold text-gray-900 dark:text-white flex flex-col items-center gap-1">
                                    <i class="ri-arrow-right-up-line text-danger text-xl"></i> Расход
                                </span>
                            </label>
                            <label :class="[form.type === 'income' ? 'border-success bg-success/5 ring-1 ring-success' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-3 shadow-sm focus:outline-none transition-all items-center justify-center text-center']">
                                <input type="radio" v-model="form.type" value="income" class="sr-only" />
                                <span class="text-sm font-semibold text-gray-900 dark:text-white flex flex-col items-center gap-1">
                                    <i class="ri-arrow-right-down-line text-success text-xl"></i> Доход
                                </span>
                            </label>
                            <label :class="[form.type === 'transfer' ? 'border-info bg-info/5 ring-1 ring-info' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-3 shadow-sm focus:outline-none transition-all items-center justify-center text-center']">
                                <input type="radio" v-model="form.type" value="transfer" class="sr-only" />
                                <span class="text-sm font-semibold text-gray-900 dark:text-white flex flex-col items-center gap-1">
                                    <i class="ri-arrow-left-right-line text-info text-xl"></i> Перевод
                                </span>
                            </label>
                        </div>
                        <div v-else class="flex items-center gap-2">
                            <span :class="[transactionTypes[form.type]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center gap-1.5 py-1 px-2.5 rounded text-xs font-medium']">
                                <i :class="transactionTypes[form.type]?.icon"></i> {{ transactionTypes[form.type]?.label || form.type }}
                            </span>
                        </div>

                        <!-- Форма для Дохода / Расхода: при редактировании счет заблокирован (виден для справки) -->
                        <template v-if="form.type !== 'transfer'">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Счет / Касса <span class="text-danger">*</span></label>
                                    <select v-model="form.account_id" required :disabled="!!editingTransaction" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:opacity-60">
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Выберите счет...</option>
                                        <option v-for="acc in accounts" :key="acc.id" :value="acc.id" class="bg-white dark:bg-gray-800">{{ acc.name }} ({{ formatMoney(acc.balance) }})</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статья <span class="text-danger">*</span></label>
                                    <div class="flex gap-2">
                                        <select v-model="form.transaction_category_id" required class="flex-1 block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                            <option value="" disabled class="bg-white dark:bg-gray-800">Выберите статью...</option>
                                            <option v-for="cat in categories.filter(c => c.type === form.type)" :key="cat.id" :value="cat.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(cat.name) }}</option>
                                        </select>
                                        <button type="button" @click="openQuickCategoryModal" class="shrink-0 inline-flex items-center justify-center rounded-md border border-primary/30 dark:border-primary/40 bg-primary/10 dark:bg-primary/15 px-3 hover:bg-primary/20 dark:hover:bg-primary/25 transition-colors" title="Добавить статью">
                                            <i class="ri-add-line text-primary"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Основание: привязка ручной оплаты к заказ-наряду. Показывается только
                                 для дохода со статьёй «Оплата заказа» — остальные статьи основания не имеют. -->
                            <div v-if="!editingTransaction && form.type === 'income' && isOrderPayment">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Основание (заказ-наряд)</label>
                                <SearchableSelect
                                    v-model="form.work_order_id"
                                    :options="baseOrderOptions"
                                    placeholder="Без основания — свободная операция"
                                    searchPlaceholder="Поиск по номеру заказа..."
                                    clearable
                                />
                                <p class="text-xs text-gray-400 mt-1">Свяжет операцию с заказом: контрагент и статья «Оплата заказа» подставятся автоматически, оплата зачтётся в остаток долга заказа.</p>
                            </div>

                            <!-- Контрагент: обязателен для свободного дохода/расхода, автозаполняется из основания.
                                 Список фильтруется по статье: зарплата — сотрудники, поставщику — клиенты
                                 с ролью «Поставщик», возврат клиенту — клиенты с ролью «Клиент». -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                    {{ isPayrollPayment ? 'Сотрудник' : 'Контрагент' }}
                                    <span v-if="!editingTransaction && form.type !== 'transfer' && !form.work_order_id" class="text-danger">*</span>
                                </label>
                                <template v-if="editingTransaction && editingTransaction.payable_type">
                                    <div class="px-3 py-2 rounded-md bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-sm text-gray-600 dark:text-gray-300">
                                        <i class="ri-lock-2-line mr-1.5 text-gray-400"></i>
                                        Контрагент определяется основанием: {{ editingTransaction.counterparty_label || '—' }}
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Операция привязана к документу — смените контрагента через отмену и повторное проведение операции.</p>
                                </template>
                                <template v-else>
                                    <div class="flex gap-2">
                                        <SearchableSelect
                                            v-model="form.counterparty"
                                            :options="counterpartyOptions"
                                            :placeholder="isPayrollPayment ? 'Выберите сотрудника...' : 'Клиент или сотрудник...'"
                                            searchPlaceholder="Поиск по имени или телефону..."
                                            :disabled="!!form.work_order_id"
                                            clearable
                                            class="flex-1"
                                        />
                                        <button v-if="!form.work_order_id" type="button" @click="openQuickCounterpartyModal" class="shrink-0 inline-flex items-center justify-center rounded-md border border-primary/30 dark:border-primary/40 bg-primary/10 dark:bg-primary/15 px-3 hover:bg-primary/20 dark:hover:bg-primary/25 transition-colors" :title="isPayrollPayment ? 'Добавить сотрудника' : 'Добавить клиента или сотрудника'">
                                            <i class="ri-add-line text-primary"></i>
                                        </button>
                                    </div>
                                    <p v-if="!!form.work_order_id" class="text-xs text-gray-400 mt-1">Контрагент подставлен автоматически из выбранного заказ-наряда.</p>
                                    <p v-else-if="isPurchasePayment && counterpartyOptions.length === 0" class="text-xs text-warning bg-warning/5 border border-warning/20 rounded-md px-3 py-2 mt-2 flex items-start gap-1.5">
                                        <i class="ri-error-warning-line mt-0.5"></i>
                                        <span>Нет клиентов с ролью «Поставщик» — создайте его кнопкой «+» (роль назначится автоматически) или в карточке клиента.</span>
                                    </p>
                                    <p v-else-if="isRefund && counterpartyOptions.length === 0" class="text-xs text-warning bg-warning/5 border border-warning/20 rounded-md px-3 py-2 mt-2 flex items-start gap-1.5">
                                        <i class="ri-error-warning-line mt-0.5"></i>
                                        <span>Нет клиентов с ролью «Клиент» — создайте его кнопкой «+» (роль назначится автоматически) или в карточке клиента.</span>
                                    </p>
                                </template>
                            </div>
                        </template>

                        <!-- Форма для Перевода: при создании — выбор пары счетов, при редактировании — только справочно один счет этой ноги -->
                        <template v-else-if="!editingTransaction">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Списать со счета <span class="text-danger">*</span></label>
                                    <select v-model="form.from_account_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Выберите счет...</option>
                                        <option v-for="acc in accounts" :key="acc.id" :value="acc.id" class="bg-white dark:bg-gray-800">{{ acc.name }} ({{ formatMoney(acc.balance) }})</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Зачислить на счет <span class="text-danger">*</span></label>
                                    <select v-model="form.to_account_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Выберите счет...</option>
                                        <option v-for="acc in accounts.filter(a => a.id !== form.from_account_id)" :key="acc.id" :value="acc.id" class="bg-white dark:bg-gray-800">{{ acc.name }}</option>
                                    </select>
                                </div>
                            </div>
                        </template>
                        <template v-else>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Счет (одна из двух ног перевода)</label>
                                <select disabled class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 opacity-60">
                                    <option>{{ accounts.find(a => a.id === form.account_id)?.name || '—' }}</option>
                                </select>
                            </div>
                        </template>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма (₽) <span class="text-danger">*</span></label>
                                <input v-model="form.amount" type="number" step="0.01" min="0.01" required :disabled="!!lockReason('amount')" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:opacity-60" />
                                <p v-if="lockReason('amount')" class="text-xs text-gray-500 mt-1">{{ lockReason('amount') }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата операции <span class="text-danger">*</span></label>
                                <input v-model="form.transaction_date" type="date" required :disabled="!!lockReason('transaction_date')" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:opacity-60" />
                                <p v-if="lockReason('transaction_date')" class="text-xs text-gray-500 mt-1">{{ lockReason('transaction_date') }}</p>
                            </div>
                        </div>

                        <div v-if="branches.length > 1">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация (Центр затрат) <span class="text-danger">*</span></label>
                            <select v-model="form.branch_id" required :disabled="!!editingTransaction" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:opacity-60">
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Комментарий</label>
                            <input v-model="form.comment" type="text" placeholder="Назначение платежа..." class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" />
                        </div>

                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            {{ editingTransaction ? 'Сохранить изменения' : 'Провести операцию' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Offcanvas Фильтры -->
        <Offcanvas :show="isFiltersOpen" @close="isFiltersOpen = false" maxWidth="sm">
            <div class="flex flex-col h-full">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Фильтры операций</h3>
                    <button @click="isFiltersOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-6 space-y-5 custom-scrollbar">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип операции</label>
                        <select v-model="filtersForm.type" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все операции</option>
                            <option v-for="(type, key) in transactionTypes" :key="key" :value="key">{{ type.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Счет / Касса</label>
                        <select v-model="filtersForm.account_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все счета</option>
                            <option v-for="acc in accounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статья</label>
                        <select v-model="filtersForm.transaction_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все статьи</option>
                            <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ getLocalizedLabel(cat.name) }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация</label>
                        <select v-model="filtersForm.branch_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все локации</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Контрагент</label>
                        <SearchableSelect
                            v-model="filtersForm.counterparty"
                            :options="counterpartyOptions"
                            placeholder="Все контрагенты"
                            searchPlaceholder="Поиск по имени или телефону..."
                            clearable
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Заказ-наряд (основание)</label>
                        <SearchableSelect
                            v-model="filtersForm.work_order_id"
                            :options="baseOrderOptions"
                            placeholder="Все операции"
                            searchPlaceholder="Поиск по номеру заказа..."
                            clearable
                        />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сверка с банком</label>
                        <select v-model="filtersForm.is_reconciled" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все</option>
                            <option value="1">Сверено</option>
                            <option value="0">Не сверено</option>
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

        <ColumnSettingsModal
            :show="isColumnsModalOpen"
            entity-type="transaction"
            :available-columns="availableColumns"
            :visible-columns="listView.visible_columns"
            @close="isColumnsModalOpen = false"
            @saved="isColumnsModalOpen = false"
        />

        <!-- Быстрое создание статьи на лету — обязательно через <Modal> (не голый div
        с z-index): форма операции открыта модалкой, а <Modal> рендерит нативный
        <dialog>.showModal() в браузерный top layer, который физически выше ЛЮБОГО
        обычного элемента независимо от z-index. Только второй <dialog> корректно
        ляжет поверх первого (top layer стекуется по порядку открытия).
        post() с preserveState обязателен — иначе Inertia пересоздаст страницу
        и разорвёт открытую форму операции (см. CLAUDE.md про пополняемые списки). -->
        <Modal :show="isQuickCategoryModalOpen" @close="closeQuickCategoryModal" maxWidth="md">
            <div class="bg-white dark:bg-[#313a46] rounded-md flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Новая статья</h3>
                    <button @click="closeQuickCategoryModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitQuickCategory" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                            <input v-model="quickCategoryForm.name" type="text" required placeholder="Например, Аренда бокса" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="quickCategoryForm.errors.name" class="text-xs text-danger mt-1">{{ quickCategoryForm.errors.name }}</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип</label>
                            <div class="px-3 py-2 rounded-md bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-sm text-gray-600 dark:text-gray-300">
                                {{ quickCategoryForm.type === 'income' ? 'Доход' : 'Расход' }}
                            </div>
                            <p class="text-xs text-gray-400 mt-1.5">Тип зафиксирован по текущей операции — статью другого типа можно завести в справочнике статей.</p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeQuickCategoryModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="quickCategoryForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Быстрое создание контрагента на лету (клиент или сотрудник), см. пояснение выше про <Modal> -->
        <Modal :show="isQuickCounterpartyModalOpen" @close="closeQuickCounterpartyModal" maxWidth="md">
            <div class="bg-white dark:bg-[#313a46] rounded-md flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Новый контрагент</h3>
                    <button @click="closeQuickCounterpartyModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-xl"></i></button>
                </div>
                <div class="flex border-b border-gray-200 dark:border-gray-700">
                    <button type="button" @click="quickCounterpartyKind = 'client'" class="flex-1 py-3 px-4 text-sm font-medium transition-colors border-b-2 -mb-px" :class="quickCounterpartyKind === 'client' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'">
                        <i class="ri-user-3-line mr-1.5"></i>Клиент
                    </button>
                    <button type="button" @click="quickCounterpartyKind = 'employee'" class="flex-1 py-3 px-4 text-sm font-medium transition-colors border-b-2 -mb-px" :class="quickCounterpartyKind === 'employee' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300'">
                        <i class="ri-team-line mr-1.5"></i>Сотрудник
                    </button>
                </div>

                <form v-if="quickCounterpartyKind === 'client'" @submit.prevent="submitQuickClient" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация <span class="text-danger">*</span></label>
                            <select v-model="quickClientForm.branch_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" disabled class="bg-white dark:bg-gray-800">Выберите локацию...</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                            </select>
                            <span v-if="quickClientForm.errors.branch_id" class="text-xs text-danger mt-1">{{ quickClientForm.errors.branch_id }}</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Имя <span class="text-danger">*</span></label>
                            <input v-model="quickClientForm.name" type="text" required placeholder="Иван Иванов" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="quickClientForm.errors.name" class="text-xs text-danger mt-1">{{ quickClientForm.errors.name }}</span>
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Телефон <span v-if="quickClientForm.phone_required" class="text-danger">*</span>
                                </label>
                                <label class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 cursor-pointer select-none">
                                    <input
                                        type="checkbox"
                                        :checked="!quickClientForm.phone_required"
                                        @change="quickClientForm.phone_required = !$event.target.checked"
                                        class="rounded border-gray-300 dark:border-gray-600 text-primary focus:ring-primary focus:ring-offset-0"
                                    />
                                    Без номера
                                </label>
                            </div>
                            <input
                                v-model="quickClientForm.phone"
                                type="text"
                                :required="quickClientForm.phone_required"
                                placeholder="+7 (999) 000-00-00"
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                            />
                            <span v-if="quickClientForm.errors.phone" class="text-xs text-danger mt-1 block">{{ quickClientForm.errors.phone }}</span>
                            <p v-if="!quickClientForm.phone_required" class="text-xs text-warning bg-warning/5 border border-warning/20 rounded-md px-3 py-2 mt-2 flex items-start gap-1.5">
                                <i class="ri-error-warning-line mt-0.5"></i>
                                <span>Без номера высок риск случайно создать дубль клиента — указывайте это только если контакта действительно нет.</span>
                            </p>
                        </div>
                        <p v-if="quickClientForm.role_ids.length > 0" class="text-xs text-info bg-info/5 border border-info/20 rounded-md px-3 py-2 flex items-start gap-1.5">
                                <i class="ri-flag-2-line mt-0.5 shrink-0"></i>
                                <span>Клиенту сразу будет назначена роль «{{ quickClientRoleName }}» — иначе он не появится в списке выбора для этой статьи операции.</span>
                            </p>
                        <p class="text-xs text-gray-400">Остальные данные клиента можно заполнить позже, в карточке клиента.</p>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeQuickCounterpartyModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="quickClientForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить</button>
                    </div>
                </form>

                <form v-else @submit.prevent="submitQuickEmployee" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация <span class="text-danger">*</span></label>
                            <select v-model="quickEmployeeForm.branch_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" disabled class="bg-white dark:bg-gray-800">Выберите локацию...</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                            </select>
                            <span v-if="quickEmployeeForm.errors.branch_id" class="text-xs text-danger mt-1">{{ quickEmployeeForm.errors.branch_id }}</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Должность <span class="text-danger">*</span></label>
                            <SearchableSelect
                                v-model="quickEmployeeForm.position_id"
                                :options="positionOptions"
                                placeholder="Выберите должность..."
                                searchPlaceholder="Поиск должности..."
                                :disabled="positionOptions.length === 0"
                            />
                            <span v-if="quickEmployeeForm.errors.position_id" class="text-xs text-danger mt-1">{{ quickEmployeeForm.errors.position_id }}</span>
                            <p v-if="positionOptions.length === 0" class="text-xs text-warning bg-warning/5 border border-warning/20 rounded-md px-3 py-2 mt-2 flex items-start gap-1.5">
                                <i class="ri-error-warning-line mt-0.5"></i>
                                <span>В системе нет ни одной должности — добавьте их в разделе «Сотрудники → Должности», затем вернитесь сюда.</span>
                            </p>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Фамилия <span class="text-danger">*</span></label>
                                <input v-model="quickEmployeeForm.last_name" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <span v-if="quickEmployeeForm.errors.last_name" class="text-xs text-danger mt-1">{{ quickEmployeeForm.errors.last_name }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Имя <span class="text-danger">*</span></label>
                                <input v-model="quickEmployeeForm.first_name" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <span v-if="quickEmployeeForm.errors.first_name" class="text-xs text-danger mt-1">{{ quickEmployeeForm.errors.first_name }}</span>
                            </div>
                        </div>
                        <div v-if="needsMiddleName">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Отчество <span class="text-danger">*</span></label>
                            <input v-model="quickEmployeeForm.middle_name" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="quickEmployeeForm.errors.middle_name" class="text-xs text-danger mt-1">{{ quickEmployeeForm.errors.middle_name }}</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Телефон <span class="text-danger">*</span></label>
                            <input v-model="quickEmployeeForm.phone" type="text" required placeholder="+7 (999) 000-00-00" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="quickEmployeeForm.errors.phone" class="text-xs text-danger mt-1 block">{{ quickEmployeeForm.errors.phone }}</span>
                        </div>
                        <p class="text-xs text-gray-400">Сотрудник будет создан как контрагент операции без доступа в систему — доступ настраивается в карточке сотрудника.</p>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeQuickCounterpartyModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="quickEmployeeForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить</button>
                    </div>
                </form>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>