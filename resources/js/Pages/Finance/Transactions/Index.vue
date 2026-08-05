<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import FinanceNav from '@/Components/FinanceNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
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
});

const page = usePage();

const isModalOpen = ref(false);

const form = useForm({
    type: 'expense',
    account_id: '',
    from_account_id: '',
    to_account_id: '',
    branch_id: page.props.current_branch_id || (props.branches.length > 0 ? props.branches[0].id : ''),
    transaction_category_id: '',
    amount: 0,
    comment: '',
});

const openModal = () => {
    form.reset();
    form.branch_id = page.props.current_branch_id || (props.branches.length > 0 ? props.branches[0].id : '');
    form.type = 'expense';
    if (props.accounts.length > 0) {
        form.account_id = props.accounts[0].id;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    form.post(route('finance.transactions.store'), {
        onSuccess: () => closeModal(),
    });
};

const deleteTransaction = (transaction) => {
    if (confirm(`Отменить транзакцию на сумму ${formatMoney(transaction.amount)}? Баланс счета будет восстановлен.`)) {
        router.delete(route('finance.transactions.destroy', transaction.id));
    }
};

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const filtersForm = reactive({
    account_id: props.filters?.filters?.account_id || '',
    branch_id: props.filters?.filters?.branch_id || '',
    type: props.filters?.filters?.type || '',
    transaction_category_id: props.filters?.filters?.transaction_category_id || '',
});

const isFiltersOpen = ref(false);

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

// Вычисляем общие балансы по счетам для дашборда
const totalBalance = computed(() => {
    return props.accounts.reduce((sum, acc) => sum + acc.balance, 0);
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

            <!-- Блок ошибок -->
            <div v-if="page.props.errors.error" class="p-4 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-start gap-3">
                <i class="ri-error-warning-fill text-xl shrink-0"></i>
                <div>
                    <p class="font-bold mb-1">Ошибка:</p>
                    <p>{{ page.props.errors.error }}</p>
                </div>
            </div>

            <!-- Dashboard Балансов -->
            <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-4">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-5">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Общий баланс</p>
                    <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(totalBalance) }}</p>
                </div>
                <div v-for="acc in accounts.slice(0, 3)" :key="acc.id" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-5">
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1 truncate" :title="acc.name">{{ acc.name }}</p>
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
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Дата</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Тип</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Счет / Касса</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статья</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Сумма</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Основание / Комментарий</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="tx in transactions.data" :key="tx.id" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center">
                                    <input type="checkbox" :value="tx.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    {{ new Date(tx.created_at).toLocaleString('ru-RU', {day: 'numeric', month: 'short', hour: '2-digit', minute:'2-digit'}) }}
                                </td>
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50">
                                    <span :class="[transactionTypes[tx.type]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                        <i :class="transactionTypes[tx.type]?.icon"></i> {{ transactionTypes[tx.type]?.label || tx.type }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 font-medium">
                                    {{ tx.account ? tx.account.name : '—' }}
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    {{ tx.category ? getLocalizedLabel(tx.category.name) : '—' }}
                                </td>
                                <td class="py-4 px-6 text-sm font-bold border-b border-gray-100 dark:border-gray-700/50 text-right">
                                    <span :class="tx.type === 'income' ? 'text-success' : (tx.type === 'expense' ? 'text-danger' : 'text-gray-800 dark:text-gray-200')">
                                        {{ tx.type === 'income' ? '+' : (tx.type === 'expense' ? '-' : '') }}{{ formatMoney(tx.amount) }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <div v-if="tx.payable_type === 'App\\Models\\WorkOrder'">
                                        <Link :href="route('operations.work-orders.show', tx.payable_id)" class="text-primary hover:underline font-medium block">
                                            Заказ-наряд #{{ String(tx.payable_id).padStart(6, '0') }}
                                        </Link>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-0.5 truncate max-w-xs" :title="tx.comment">{{ tx.comment || '—' }}</div>
                                </td>
                                <td class="py-4 px-6 text-sm text-right border-b border-gray-100 dark:border-gray-700/50">
                                    <button @click="deleteTransaction(tx)" class="text-danger hover:text-danger-600 transition-colors p-1" title="Отменить транзакцию">
                                        <i class="ri-arrow-go-back-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="transactions.data.length === 0">
                                <td colspan="8" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
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
                        Новая финансовая операция
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-6">
                        
                        <!-- Выбор типа операции -->
                        <div class="grid grid-cols-3 gap-3">
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

                        <!-- Форма для Дохода / Расхода -->
                        <template v-if="form.type !== 'transfer'">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Счет / Касса <span class="text-danger">*</span></label>
                                    <select v-model="form.account_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
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

                        <!-- Форма для Перевода -->
                        <template v-else>
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

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма (₽) <span class="text-danger">*</span></label>
                                <input v-model="form.amount" type="number" step="0.01" min="0.01" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Филиал (Центр затрат) <span class="text-danger">*</span></label>
                                <select v-model="form.branch_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                    <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Комментарий</label>
                            <input v-model="form.comment" type="text" placeholder="Назначение платежа..." class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" />
                        </div>

                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            Провести операцию
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

    </AuthenticatedLayout>
</template>