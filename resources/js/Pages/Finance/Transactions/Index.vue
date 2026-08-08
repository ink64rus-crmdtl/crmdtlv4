<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import FinanceNav from '@/Components/FinanceNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import ColumnSettingsModal from '@/Components/ColumnSettingsModal.vue';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    transactions: Object,
    accounts: Array,
    branches: Array,
    categories: Array,
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
        // transaction_category_id) — счет/тип/филиал в форме нужны только для отображения и игнорируются.
        form.put(route('finance.transactions.update', editingTransaction.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('finance.transactions.store'), {
            onSuccess: () => closeModal(),
        });
    }
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
});

const isFiltersOpen = ref(false);

// Переход по ссылке "показать операцию" из карточки заказа — filters.id не входит в filtersForm,
// поэтому не сбрасывается автоматически другими фильтрами, но и не считается "активным фильтром" в тулбаре.
const deepLinkedTransactionId = computed(() => props.filters?.filters?.id || null);

const fetchFiltered = useDebounceFn(() => {
    router.get(route('finance.transactions.index'), {
        search: search.value,
        filters: filtersForm,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
watch(filtersForm, () => fetchFiltered(), { deep: true });

const resetFilters = () => {
    filtersForm.account_id = '';
    filtersForm.branch_id = '';
    filtersForm.type = '';
    filtersForm.transaction_category_id = '';
    filtersForm.is_reconciled = '';
};
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const selectAll = computed({
    get: () => props.transactions.data.length > 0 && selectedIds.value.length === props.transactions.data.length,
    set: (value) => {
        if (value) {
            selectedIds.value = props.transactions.data.map(t => t.id);
        } else {
            selectedIds.value = [];
        }
    }
});

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
                <p>После проведения операции можно менять сумму, дату, статью и комментарий — баланс счета пересчитывается на разницу. Тип операции, счет и филиал менять нельзя (для этого отмените операцию и проведите новую).</p>
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
                @delete="() => {}" 
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
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-4 w-10 border-b border-gray-200 dark:border-gray-700 text-center">
                                    <input type="checkbox" v-model="selectAll" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </th>
                                <th v-for="col in activeColumns" :key="col.key" :class="['py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700', col.key === 'amount' ? 'text-right' : (col.key === 'reconciled' ? 'text-center' : '')]">{{ col.label }}</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tx in transactions.data" :key="tx.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center">
                                    <input type="checkbox" :value="tx.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>
                                <td v-for="col in activeColumns" :key="col.key" :class="['py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50', col.key === 'amount' ? 'text-right font-bold' : (col.key === 'reconciled' ? 'text-center' : '')]">
                                    <template v-if="col.key === 'date'">
                                        {{ new Date(tx.transaction_date).toLocaleDateString('ru-RU', {day: 'numeric', month: 'short', year: 'numeric'}) }}
                                        <i v-if="tx.edited_at" class="ri-edit-2-line text-gray-400 ml-1" :title="`Отредактировано ${new Date(tx.edited_at).toLocaleString('ru-RU')}${tx.editor ? ' — ' + tx.editor.name : ''}`"></i>
                                    </template>
                                    <template v-else-if="col.key === 'type'">
                                        <span :class="[transactionTypes[tx.type]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                            <i :class="transactionTypes[tx.type]?.icon"></i> {{ transactionTypes[tx.type]?.label || tx.type }}
                                        </span>
                                    </template>
                                    <template v-else-if="col.key === 'account'">
                                        <span class="font-medium">{{ tx.account ? tx.account.name : '—' }}</span>
                                    </template>
                                    <template v-else-if="col.key === 'category'">
                                        {{ tx.category ? getLocalizedLabel(tx.category.name) : '—' }}
                                    </template>
                                    <template v-else-if="col.key === 'amount'">
                                        <span :class="tx.type === 'income' ? 'text-success' : (tx.type === 'expense' ? 'text-danger' : 'text-gray-800 dark:text-gray-200')">
                                            {{ tx.type === 'income' ? '+' : (tx.type === 'expense' ? '-' : '') }}{{ formatMoney(tx.amount) }}
                                        </span>
                                    </template>
                                    <template v-else-if="col.key === 'comment'">
                                        <div v-if="tx.payable_type === 'App\\Models\\WorkOrder'">
                                            <Link :href="route('operations.work-orders.show', tx.payable_id)" class="text-primary hover:underline font-medium block">
                                                Заказ-наряд #{{ String(tx.payable_id).padStart(6, '0') }}
                                            </Link>
                                        </div>
                                        <div class="text-xs text-gray-500 mt-0.5 truncate max-w-xs" :title="tx.comment">{{ tx.comment || '—' }}</div>
                                    </template>
                                    <template v-else-if="col.key === 'reconciled'">
                                        <button
                                            @click="toggleReconciled(tx)"
                                            :class="[tx.is_reconciled ? 'bg-success/10 text-success' : 'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500', 'inline-flex items-center justify-center h-7 w-7 rounded-full transition-colors hover:opacity-80']"
                                            :title="tx.is_reconciled ? `Сверено ${tx.reconciled_at ? new Date(tx.reconciled_at).toLocaleString('ru-RU') : ''}${tx.reconciler ? ' — ' + tx.reconciler.name : ''} (клик — снять отметку)` : 'Отметить как сверенное с банковской выпиской'"
                                        >
                                            <i class="ri-bank-line"></i>
                                        </button>
                                    </template>
                                </td>
                                <td class="py-4 px-6 text-sm text-right border-b border-gray-100 dark:border-gray-700/50 space-x-1">
                                    <button @click="openEditModal(tx)" class="text-primary hover:text-primary-600 transition-colors p-1" title="Редактировать">
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button @click="deleteTransaction(tx)" class="text-danger hover:text-danger-600 transition-colors p-1" title="Отменить транзакцию">
                                        <i class="ri-arrow-go-back-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="transactions.data.length === 0">
                                <td :colspan="activeColumns.length + 2" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Операции не найдены.
                                </td>
                            </tr>
                        </tbody>
                    </table>
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

                        <div v-if="editingTransaction" class="p-3 rounded-md bg-info/5 border border-info/20 text-xs text-gray-600 dark:text-gray-400">
                            <i class="ri-information-line text-info mr-1"></i>
                            Тип, счет и филиал операции менять нельзя — доступны сумма, дата, статья и комментарий. Если нужно перенести операцию на другой счет — отмените её и проведите заново.
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
                                    <select v-model="form.transaction_category_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Выберите статью...</option>
                                        <option v-for="cat in categories.filter(c => c.type === form.type)" :key="cat.id" :value="cat.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(cat.name) }}</option>
                                    </select>
                                </div>
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

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Филиал (Центр затрат) <span class="text-danger">*</span></label>
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
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Филиал</label>
                        <select v-model="filtersForm.branch_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все филиалы</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
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

    </AuthenticatedLayout>
</template>