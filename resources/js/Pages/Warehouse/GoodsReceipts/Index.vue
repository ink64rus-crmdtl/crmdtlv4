<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import WarehouseNav from '@/Components/WarehouseNav.vue';
import DataTable from '@/Components/DataTable.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import Modal from '@/Components/Modal.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import CompanySuggestInput from '@/Components/CompanySuggestInput.vue';
import PointBadge from '@/Components/PointBadge.vue';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';

const props = defineProps({
    receipts: Object,
    debtSummary: { type: Object, default: () => ({ total_debt: 0, receipts_with_debt: 0 }) },
    filters: Object,
    suppliers: { type: Array, default: () => [] },
    supplierRoleId: { type: Number, default: null },
    tenantCountry: { type: String, default: 'RU' },
    warehouses: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    productCategories: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
});

const page = usePage();

const getLocalizedLabel = (label) => {
    if (!label) return '';
    if (typeof label === 'string') {
        try { label = JSON.parse(label); } catch (e) { return label; }
    }
    return label['ru'] || label['en'] || Object.values(label)[0] || '';
};

const formatMoney = (amount) => new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(amount / 100);

const statusMeta = {
    posted: { label: 'Оприходована', class: 'bg-success/10 text-success' },
    canceled: { label: 'Отменена', class: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' },
};

const paymentStatuses = {
    unpaid: { label: 'Не оплачено', class: 'bg-danger/10 text-danger' },
    partial: { label: 'Частично', class: 'bg-warning/10 text-warning' },
    paid: { label: 'Оплачено', class: 'bg-success/10 text-success' },
};

const paidAmount = (receipt) => Number(receipt.paid_total) || 0;
const remainingAmount = (receipt) => Math.max(0, Number(receipt.items_total) - paidAmount(receipt));

const supplierOptions = computed(() => props.suppliers.map(s => ({ value: s.id, label: s.name })));

const isPreviewOpen = ref(false);
const previewReceipt = ref(null);
const openPreview = (receipt) => {
    previewReceipt.value = receipt;
    isPreviewOpen.value = true;
};
const closePreview = () => {
    isPreviewOpen.value = false;
    previewReceipt.value = null;
};

const cancelReceipt = (receipt) => {
    if (confirm(`Отменить накладную №${String(receipt.id).padStart(6, '0')}? Оприходованный товар будет списан обратно.`)) {
        router.post(route('warehouse.goods-receipts.cancel', receipt.id), {}, {
            preserveScroll: true,
            onSuccess: () => {
                if (previewReceipt.value && previewReceipt.value.id === receipt.id) closePreview();
            },
        });
    }
};
// --- Приём оплаты иконкой действия в списке (тот же поток, что и на
// Карточке накладной — GoodsReceiptController::pay()) ---
const accountTypeLabels = {
    cash: 'Касса',
    bank: 'Расчетный счет',
    acquiring: 'Эквайринг',
};

const isPaymentModalOpen = ref(false);
const paymentReceipt = ref(null);
const paymentForm = useForm({
    account_id: '',
    amount: 0,
});

const openPaymentModal = (receipt) => {
    paymentReceipt.value = receipt;
    paymentForm.reset();
    paymentForm.amount = remainingAmount(receipt) / 100;
    if (props.accounts.length > 0) paymentForm.account_id = props.accounts[0].id;
    isPaymentModalOpen.value = true;
};

const closePaymentModal = () => {
    isPaymentModalOpen.value = false;
    paymentReceipt.value = null;
    paymentForm.reset();
    paymentForm.clearErrors();
};

const submitPayment = () => {
    const paidReceiptId = paymentReceipt.value.id;
    paymentForm.post(route('warehouse.goods-receipts.pay', paidReceiptId), {
        preserveScroll: true,
        onSuccess: () => {
            closePaymentModal();
            // previewReceipt — снимок из старого receipts.data, Inertia после
            // редиректа пересоздаёт props целиком, а не мутирует объект внутри
            // него — панель просмотра иначе продолжила бы показывать старый
            // остаток долга. Закрываем, как и cancelReceipt() при отмене.
            if (previewReceipt.value && previewReceipt.value.id === paidReceiptId) closePreview();
        },
    });
};

const warehouseOptions = computed(() => props.warehouses.map(w => ({ value: w.id, label: w.name })));
const branchOptions = computed(() => props.branches.map(b => ({ value: b.id, label: b.name })));
const productOptions = computed(() => props.products.map(p => ({
    value: p.id,
    label: `${getLocalizedLabel(p.name)} (${p.accounting_type === 'batch' ? 'Партионный' : 'Средневзвешенный'})`,
})));
const productById = (id) => props.products.find(p => p.id === id);

const today = () => new Date().toISOString().slice(0, 10);

// --- Создание накладной ---
const isModalOpen = ref(false);

const form = useForm({
    supplier_id: '',
    warehouse_id: '',
    branch_id: page.props.current_branch_id || (props.branches[0]?.id ?? ''),
    legal_entity_id: '',
    receipt_date: today(),
    supplier_document_number: '',
    comment: '',
    items: [{ product_id: '', quantity: 1, cost_price: 0, batch_number: '' }],
});

const legalEntitiesForSelectedBranch = computed(() => {
    const branch = props.branches.find(b => b.id === form.branch_id);
    return branch?.legal_entities || [];
});

const addItem = () => {
    form.items.push({ product_id: '', quantity: 1, cost_price: 0, batch_number: '' });
};

const removeItem = (index) => {
    if (form.items.length > 1) form.items.splice(index, 1);
};

// --- Быстрое добавление товара прямо из строки позиции (тот же приём и тот
// же эндпоинт, что уже есть в Operations/WorkOrders/Show.vue — отдельный
// "quick"-роут под склад заводить не нужно, товар и там, и здесь один и тот
// же справочник). quickProductTargetIndex — какую именно строку заполнить
// созданным товаром, раз строк с "+" может быть несколько одновременно. ---
const isQuickProductModalOpen = ref(false);
const quickProductTargetIndex = ref(null);
const quickProductForm = useForm({
    product_category_id: '',
    name: '',
    sku: '',
    unit: 'шт',
    accounting_type: 'average',
});

const openQuickProductModal = (index) => {
    quickProductTargetIndex.value = index;
    quickProductForm.reset();
    quickProductForm.unit = 'шт';
    quickProductForm.accounting_type = 'average';
    isQuickProductModalOpen.value = true;
};

const closeQuickProductModal = () => {
    isQuickProductModalOpen.value = false;
    quickProductTargetIndex.value = null;
    quickProductForm.reset();
    quickProductForm.clearErrors();
};

const submitQuickProduct = () => {
    const existingIds = new Set(props.products.map(p => p.id));
    quickProductForm.post(route('operations.work-orders.quick-product'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const created = props.products.find(p => !existingIds.has(p.id));
            if (created && quickProductTargetIndex.value !== null) {
                form.items[quickProductTargetIndex.value].product_id = created.id;
            }
            closeQuickProductModal();
        },
    });
};

const openModal = () => {
    form.reset();
    form.branch_id = page.props.current_branch_id || (props.branches[0]?.id ?? '');
    form.receipt_date = today();
    form.items = [{ product_id: '', quantity: 1, cost_price: 0, batch_number: '' }];
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    form.post(route('warehouse.goods-receipts.store'), {
        onSuccess: () => closeModal(),
    });
};

// --- Быстрое добавление поставщика (тот же приём, что "+ клиент" в
// Operations/WorkOrders/Index.vue) — preserveState обязателен, форма создания
// накладной открыта через <Modal> (нативный <dialog>), без него Inertia
// пересоздаст компонент страницы и родительская модалка перестанет отвечать
// на клики. ---
const isQuickSupplierModalOpen = ref(false);
const quickSupplierForm = useForm({
    branch_id: '',
    type: 'b2b',
    name: '',
    phone: '',
    phone_required: true,
    role_ids: [],
    requisites: {},
});

const openQuickSupplierModal = () => {
    quickSupplierForm.reset();
    quickSupplierForm.branch_id = form.branch_id || (props.branches[0]?.id ?? '');
    quickSupplierForm.type = 'b2b';
    quickSupplierForm.role_ids = props.supplierRoleId ? [props.supplierRoleId] : [];
    quickSupplierForm.requisites = {};
    isQuickSupplierModalOpen.value = true;
};

// Выбор варианта из подсказки DaData (см. CompanySuggestInput.vue) — тот же
// приём, что в CRM/Clients/Index.vue, но только name+ИНН: эта модалка
// сознательно минимальна (см. CLAUDE.md, "Пополняемые списки"), остальные
// реквизиты (КПП/ОГРН/адрес/подписанты) заводятся потом в карточке клиента.
const applyCompanySuggestion = (suggestion) => {
    quickSupplierForm.name = suggestion.name;
    if (suggestion.inn) quickSupplierForm.requisites.inn = suggestion.inn;
};

const closeQuickSupplierModal = () => {
    isQuickSupplierModalOpen.value = false;
    quickSupplierForm.reset();
    quickSupplierForm.clearErrors();
};

const submitQuickSupplier = () => {
    const existingIds = new Set(props.suppliers.map(s => s.id));
    quickSupplierForm.post(route('crm.clients.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const created = props.suppliers.find(s => !existingIds.has(s.id));
            if (created) form.supplier_id = created.id;
            closeQuickSupplierModal();
        },
    });
};

// --- Фильтры и поиск ---
const search = ref(props.filters?.search || '');
const filtersForm = ref({
    supplier_id: props.filters?.filters?.supplier_id || '',
    warehouse_id: props.filters?.filters?.warehouse_id || '',
    branch_id: props.filters?.filters?.branch_id || '',
    status: props.filters?.filters?.status || '',
    payment_status: props.filters?.filters?.payment_status || '',
});
const isFiltersOpen = ref(false);

// Та же логика, что в Warehouse/Movements/Index.vue: BranchScope уже
// фильтрует по локации из шапки, второй фильтр по локации имел бы смысл
// только при "Все локации" в шапке — иначе гарантированный 0-строк.
const hasSpecificBranchContext = computed(() => !!page.props.current_branch_id);

watch(hasSpecificBranchContext, (isSpecific) => {
    if (isSpecific) filtersForm.value.branch_id = '';
});

const fetchFiltered = useDebounceFn(() => {
    router.get(route('warehouse.goods-receipts.index'), {
        search: search.value,
        filters: filtersForm.value,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
watch(filtersForm, () => fetchFiltered(), { deep: true });

const { sort, onSort } = useServerSort('warehouse.goods-receipts.index', () => props.filters, () => ({ search: search.value, filters: filtersForm.value }));

const resetFilters = () => {
    filtersForm.value = { supplier_id: '', warehouse_id: '', branch_id: '', status: '', payment_status: '' };
};

const hasActiveFilters = computed(() => Object.values(filtersForm.value).some(v => v !== '' && v !== null));

// Сортировка — только реальные колонки goods_receipts (белый список зеркалит
// GoodsReceiptController::index()). supplier/warehouse_branch — связи,
// items_count — агрегат (count позиций), простым orderBy не сортируются.
const receiptColumns = [
    { key: 'receipt', label: 'Накладная', sortable: true, sortKey: 'receipt_date' },
    { key: 'supplier', label: 'Поставщик' },
    { key: 'warehouse_branch', label: 'Склад / Локация' },
    { key: 'items_count', label: 'Позиций', align: 'right' },
    { key: 'status', label: 'Статус', sortable: true },
    { key: 'payment', label: 'Оплата', sortable: true, sortKey: 'payment_status' },
];
</script>

<template>
    <Head title="Приходные накладные" />

    <AuthenticatedLayout>
        <template #header>Склад и Прайс</template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">

            <WarehouseNav />

            <PageHelper title="Приходные накладные">
                <p>Оприходование товара оформляется накладной от поставщика — так позиции одной поставки остаются связаны между собой, а не рассыпаются по журналу движений отдельными строками.</p>
                <p>Поставщик — клиент с ролью «Поставщик» (Настройки → Справочники → Роли клиентов, или кнопка «+» прямо в этой форме). Отменить накладную можно только пока товар с неё не был частично списан.</p>
            </PageHelper>

            <div v-if="page.props.errors.error" class="p-4 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-start gap-3">
                <i class="ri-error-warning-fill text-xl shrink-0"></i>
                <div><p class="font-bold mb-1">Ошибка:</p><p>{{ page.props.errors.error }}</p></div>
            </div>

            <!-- Сводка по долгу — с учётом текущих фильтров (см. GoodsReceiptController::index()) -->
            <div v-if="debtSummary.total_debt > 0" class="rounded-md bg-danger/5 border border-danger/20 p-4 flex items-center gap-4">
                <div class="w-11 h-11 rounded-full bg-danger/10 text-danger flex items-center justify-center shrink-0">
                    <i class="ri-error-warning-line text-xl"></i>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-bold text-gray-800 dark:text-gray-200">Задолженность поставщикам: {{ formatMoney(debtSummary.total_debt) }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">По {{ debtSummary.receipts_with_debt }} накладн{{ debtSummary.receipts_with_debt === 1 ? 'ой' : 'ым' }} с учётом текущих фильтров</p>
                </div>
                <Link :href="route('warehouse.suppliers-debt.index')" class="text-sm font-medium text-danger hover:underline whitespace-nowrap">
                    По поставщикам <i class="ri-arrow-right-s-line"></i>
                </Link>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="hasActiveFilters"
                    @open-filters="isFiltersOpen = true"
                    placeholder="Поиск по номеру накладной поставщика..."
                >
                    <template #actions>
                        <button @click="openModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                            <i class="ri-download-line text-base"></i> Оприходовать товар
                        </button>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="receiptColumns"
                        :rows="receipts.data"
                        has-actions
                        row-clickable
                        @row-click="openPreview"
                        empty-message="Накладных пока нет."
                        :sort="sort"
                        @sort="onSort"
                    >
                        <template #cell-receipt="{ row: receipt }">
                            <div class="font-bold text-gray-800 dark:text-gray-200 font-mono">№{{ String(receipt.id).padStart(6, '0') }}</div>
                            <div class="text-xs text-gray-500 mt-0.5">
                                {{ new Date(receipt.receipt_date).toLocaleDateString('ru-RU') }}
                                <span v-if="receipt.supplier_document_number"> · их №{{ receipt.supplier_document_number }}</span>
                            </div>
                        </template>
                        <template #cell-supplier="{ row: receipt }">{{ receipt.supplier?.name || '—' }}</template>
                        <template #cell-warehouse_branch="{ row: receipt }">
                            <div class="font-medium text-gray-800 dark:text-gray-200"><i class="ri-building-4-line text-gray-400"></i> {{ receipt.warehouse?.name || '—' }}</div>
                            <div class="text-xs text-gray-500 mt-0.5"><i class="ri-store-2-line"></i> {{ receipt.branch?.name || '—' }}</div>
                        </template>
                        <template #cell-status="{ row: receipt }">
                            <span :class="[statusMeta[receipt.status]?.class, 'inline-flex items-center py-0.5 px-2 rounded text-xs font-medium']">{{ statusMeta[receipt.status]?.label || receipt.status }}</span>
                        </template>
                        <template #cell-payment="{ row: receipt }">
                            <span :class="[paymentStatuses[receipt.payment_status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center py-0.5 px-2 rounded text-xs font-medium']">{{ paymentStatuses[receipt.payment_status]?.label || receipt.payment_status }}</span>
                            <div v-if="remainingAmount(receipt) > 0" class="text-[11px] text-danger mt-0.5">Остаток: {{ formatMoney(remainingAmount(receipt)) }}</div>
                        </template>
                        <template #actions="{ row: receipt }">
                            <Link :href="route('warehouse.goods-receipts.show', receipt.id)" @click.stop class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-info/10 text-info hover:bg-info hover:text-white transition-colors" title="Открыть накладную">
                                <i class="ri-folder-open-line"></i>
                            </Link>
                            <button v-if="remainingAmount(receipt) > 0" @click.stop="openPaymentModal(receipt)" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-success/10 text-success hover:bg-success hover:text-white transition-colors" title="Принять оплату">
                                <i class="ri-money-dollar-circle-line"></i>
                            </button>
                            <button v-if="receipt.status === 'posted'" @click.stop="cancelReceipt(receipt)" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-danger/10 text-danger hover:bg-danger hover:text-white transition-colors" title="Отменить накладную">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="receipts" />
            </div>
        </div>

        <!-- Форма создания накладной -->
        <Modal :show="isModalOpen" @close="closeModal" max-width="4xl">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Приходная накладная</h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-xl"></i></button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-6 max-h-[70vh] overflow-y-auto custom-scrollbar">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Поставщик <span class="text-danger">*</span></label>
                                <div class="flex gap-2 items-start">
                                    <div class="flex-1 min-w-0"><SearchableSelect v-model="form.supplier_id" :options="supplierOptions" placeholder="Выберите поставщика..." /></div>
                                    <button type="button" @click="openQuickSupplierModal" title="Добавить поставщика" class="shrink-0 inline-flex items-center justify-center rounded-md w-9 h-9 bg-primary/10 text-primary hover:bg-primary hover:text-white transition-colors"><i class="ri-add-line text-lg"></i></button>
                                </div>
                                <span v-if="form.errors.supplier_id" class="text-xs text-danger mt-1 block">{{ form.errors.supplier_id }}</span>
                                <p v-if="suppliers.length === 0" class="text-[11px] text-gray-400 mt-1">Поставщиков пока нет — добавьте кнопкой «+».</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата поступления <span class="text-danger">*</span></label>
                                <input v-model="form.receipt_date" type="date" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <span v-if="form.errors.receipt_date" class="text-xs text-danger mt-1 block">{{ form.errors.receipt_date }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Склад получатель <span class="text-danger">*</span></label>
                                <SearchableSelect v-model="form.warehouse_id" :options="warehouseOptions" placeholder="Выберите склад..." />
                                <span v-if="form.errors.warehouse_id" class="text-xs text-danger mt-1 block">{{ form.errors.warehouse_id }}</span>
                            </div>
                            <div v-if="branches.length > 1">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация <span class="text-danger">*</span></label>
                                <SearchableSelect v-model="form.branch_id" :options="branchOptions" placeholder="Выберите локацию..." />
                                <span v-if="form.errors.branch_id" class="text-xs text-danger mt-1 block">{{ form.errors.branch_id }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div v-if="legalEntitiesForSelectedBranch.length > 0">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Юрлицо</label>
                                <select v-model="form.legal_entity_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="">Не указано</option>
                                    <option v-for="le in legalEntitiesForSelectedBranch" :key="le.id" :value="le.id">{{ le.name }}</option>
                                </select>
                                <span v-if="form.errors.legal_entity_id" class="text-xs text-danger mt-1 block">{{ form.errors.legal_entity_id }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">№ накладной поставщика</label>
                                <input v-model="form.supplier_document_number" type="text" placeholder="Их номер документа" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <p class="text-[11px] text-gray-400 mt-1">Номер с их бумаги — не наша нумерация, просто для сверки.</p>
                            </div>
                        </div>

                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                            <div class="flex justify-between items-center mb-3">
                                <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200">Позиции к оприходованию</h4>
                                <button type="button" @click="addItem" class="text-sm text-primary hover:text-primary-600 font-medium flex items-center gap-1"><i class="ri-add-line"></i> Добавить строку</button>
                            </div>

                            <div class="space-y-3">
                                <div v-for="(item, index) in form.items" :key="index" class="bg-gray-50/50 dark:bg-gray-800/30 p-3 rounded-md border border-gray-200 dark:border-gray-700">
                                    <!-- flex-wrap: на узкой модалке партия (если есть) уходит на новую
                                         строку сама, на широкой — умещается рядом с товаром/ценой. -->
                                    <div class="flex gap-3 items-start flex-wrap">
                                        <div class="flex-1 min-w-[220px]">
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Товар <span class="text-danger">*</span></label>
                                            <div class="flex gap-2 items-start">
                                                <div class="flex-1 min-w-0"><SearchableSelect v-model="item.product_id" :options="productOptions" placeholder="Выберите товар..." /></div>
                                                <button type="button" @click="openQuickProductModal(index)" title="Добавить товар в справочник" class="shrink-0 inline-flex items-center justify-center rounded-md w-9 h-9 bg-primary/10 text-primary hover:bg-primary hover:text-white transition-colors"><i class="ri-add-line text-lg"></i></button>
                                            </div>
                                            <span v-if="form.errors[`items.${index}.product_id`]" class="text-xs text-danger mt-1 block">{{ form.errors[`items.${index}.product_id`] }}</span>
                                        </div>
                                        <div class="w-24">
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Кол-во <span class="text-danger">*</span></label>
                                            <input v-model="item.quantity" type="number" step="any" min="0.001" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46] py-1.5 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                            <span v-if="form.errors[`items.${index}.quantity`]" class="text-xs text-danger mt-1 block">{{ form.errors[`items.${index}.quantity`] }}</span>
                                        </div>
                                        <div class="w-28">
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Цена (₽) <span class="text-danger">*</span></label>
                                            <input v-model="item.cost_price" type="number" step="0.01" min="0" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46] py-1.5 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                            <span v-if="form.errors[`items.${index}.cost_price`]" class="text-xs text-danger mt-1 block">{{ form.errors[`items.${index}.cost_price`] }}</span>
                                        </div>
                                        <div v-if="productById(item.product_id)?.accounting_type === 'batch'" class="w-32">
                                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1" title="Номер партии/серии с накладной поставщика">№ партии</label>
                                            <input v-model="item.batch_number" type="text" placeholder="Необязательно" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46] py-1.5 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                        </div>
                                        <div class="pt-6">
                                            <button type="button" @click="removeItem(index)" :disabled="form.items.length === 1" class="text-danger hover:text-danger-600 disabled:opacity-30 p-1"><i class="ri-delete-bin-line text-lg"></i></button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Комментарий</label>
                            <textarea v-model="form.comment" rows="2" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            <i class="ri-download-line mr-2"></i> Оприходовать
                        </button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Быстрое добавление поставщика -->
        <Modal :show="isQuickSupplierModalOpen" @close="closeQuickSupplierModal" max-width="md">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Новый поставщик</h3>
                    <button @click="closeQuickSupplierModal()" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitQuickSupplier">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация <span class="text-danger">*</span></label>
                            <select v-model="quickSupplierForm.branch_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" disabled>Выберите локацию...</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                            <span v-if="quickSupplierForm.errors.branch_id" class="text-xs text-danger mt-1 block">{{ quickSupplierForm.errors.branch_id }}</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название организации <span class="text-danger">*</span></label>
                            <!-- Живой поиск DaData — только для РФ (та же оговорка, что и в
                                 Settings/LegalEntities/CRM/Clients: у DaData нет реестра других стран). -->
                            <CompanySuggestInput
                                v-if="tenantCountry === 'RU'"
                                v-model="quickSupplierForm.name"
                                required
                                placeholder="Начните вводить название или ИНН..."
                                @select="applyCompanySuggestion"
                            />
                            <input
                                v-else
                                v-model="quickSupplierForm.name"
                                type="text"
                                required
                                placeholder="ООО «Поставщик»"
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                            />
                            <span v-if="quickSupplierForm.errors.name" class="text-xs text-danger mt-1 block">{{ quickSupplierForm.errors.name }}</span>
                        </div>
                        <div v-if="tenantCountry === 'RU'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">ИНН</label>
                            <CompanySuggestInput
                                v-model="quickSupplierForm.requisites.inn"
                                placeholder="Или начните вводить ИНН здесь..."
                                @select="applyCompanySuggestion"
                            />
                            <span v-if="quickSupplierForm.errors['requisites.inn']" class="text-xs text-danger mt-1 block">{{ quickSupplierForm.errors['requisites.inn'] }}</span>
                            <p class="text-[11px] text-gray-400 mt-1">Необязательно — остальные реквизиты (КПП, ОГРН, адрес) можно дозаполнить потом в карточке клиента.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5 flex items-center justify-between">
                                <span>Телефон <span v-if="quickSupplierForm.phone_required" class="text-danger">*</span></span>
                                <label class="flex items-center gap-1.5 text-xs font-normal text-gray-500 cursor-pointer">
                                    <input type="checkbox" :checked="!quickSupplierForm.phone_required" @change="quickSupplierForm.phone_required = !$event.target.checked" class="h-3.5 w-3.5 rounded border-gray-300 text-primary focus:ring-primary" />
                                    Без номера
                                </label>
                            </label>
                            <input v-model="quickSupplierForm.phone" type="text" :required="quickSupplierForm.phone_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="quickSupplierForm.errors.phone" class="text-xs text-danger mt-1 block">{{ quickSupplierForm.errors.phone }}</span>
                            <p v-if="!quickSupplierForm.phone_required" class="text-[11px] text-warning bg-warning/5 border border-warning/20 rounded-md px-3 py-2 mt-2">Без номера телефона можно случайно завести дубль поставщика.</p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeQuickSupplierModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="quickSupplierForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Быстрое добавление товара (та же форма и тот же роут, что в
             Operations/WorkOrders/Show.vue) -->
        <Modal :show="isQuickProductModalOpen" @close="closeQuickProductModal" max-width="3xl">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Быстрое добавление товара в каталог</h3>
                    <button @click="closeQuickProductModal()" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitQuickProduct">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория</label>
                            <select v-model="quickProductForm.product_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="">Без категории</option>
                                <option v-for="cat in productCategories" :key="cat.id" :value="cat.id">{{ getLocalizedLabel(cat.name) }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название товара <span class="text-danger">*</span></label>
                            <input v-model="quickProductForm.name" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="quickProductForm.errors.name" class="text-xs text-danger mt-1 block">{{ quickProductForm.errors.name }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ед. изм. <span class="text-danger">*</span></label>
                                <select v-model="quickProductForm.unit" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="шт">Штуки (шт)</option>
                                    <option value="мл">Миллилитры (мл)</option>
                                    <option value="л">Литры (л)</option>
                                    <option value="гр">Граммы (гр)</option>
                                    <option value="кг">Килограммы (кг)</option>
                                    <option value="пог.м">Погонные метры (пог.м)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип учёта <span class="text-danger">*</span></label>
                                <select v-model="quickProductForm.accounting_type" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="average">Средневзвешенный</option>
                                    <option value="batch">Партионный (FIFO)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeQuickProductModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="quickProductForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Offcanvas Фильтры -->
        <Offcanvas :show="isFiltersOpen" @close="isFiltersOpen = false" maxWidth="sm">
            <div class="flex flex-col h-full">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Фильтры накладных</h3>
                    <button @click="isFiltersOpen = false" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <div class="flex-1 overflow-y-auto p-6 space-y-5 custom-scrollbar">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Поставщик</label>
                        <SearchableSelect v-model="filtersForm.supplier_id" :options="supplierOptions" placeholder="Все поставщики" clearable />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Склад</label>
                        <SearchableSelect v-model="filtersForm.warehouse_id" :options="warehouseOptions" placeholder="Все склады" clearable />
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация</label>
                        <SearchableSelect v-if="!hasSpecificBranchContext" v-model="filtersForm.branch_id" :options="branchOptions" placeholder="Все локации" clearable />
                        <p v-else class="text-xs text-gray-400">В шапке выбрана конкретная локация — накладные и так показаны только по ней.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус</label>
                        <select v-model="filtersForm.status" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все статусы</option>
                            <option value="posted">Оприходована</option>
                            <option value="canceled">Отменена</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус оплаты</label>
                        <select v-model="filtersForm.payment_status" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Любой</option>
                            <option value="unpaid">Не оплачено</option>
                            <option value="partial">Частично</option>
                            <option value="paid">Оплачено</option>
                        </select>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/80 flex gap-3">
                    <button @click="resetFilters" class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">Сбросить</button>
                    <button @click="isFiltersOpen = false" class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 shadow-sm">Применить</button>
                </div>
            </div>
        </Offcanvas>

        <!-- Offcanvas Панель просмотра -->
        <Offcanvas :show="isPreviewOpen" @close="closePreview" maxWidth="sm">
            <div v-if="previewReceipt" class="flex flex-col h-full">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-start bg-gray-50/50 dark:bg-gray-800/30">
                    <div class="flex items-center gap-3">
                        <div class="w-11 h-11 rounded-full bg-primary/10 text-primary flex items-center justify-center flex-shrink-0">
                            <i class="ri-truck-line text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200 font-mono">№{{ String(previewReceipt.id).padStart(6, '0') }}</h3>
                            <p class="text-xs text-gray-500 mt-0.5">{{ new Date(previewReceipt.receipt_date).toLocaleDateString('ru-RU') }}</p>
                        </div>
                    </div>
                    <button @click="closePreview" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <div class="flex-1 overflow-y-auto p-6 space-y-5 custom-scrollbar">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span :class="[statusMeta[previewReceipt.status]?.class, 'inline-flex items-center py-0.5 px-2 rounded text-xs font-medium']">{{ statusMeta[previewReceipt.status]?.label || previewReceipt.status }}</span>
                        <span :class="[paymentStatuses[previewReceipt.payment_status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center py-0.5 px-2 rounded text-xs font-medium']">{{ paymentStatuses[previewReceipt.payment_status]?.label || previewReceipt.payment_status }}</span>
                        <PointBadge :branch="previewReceipt.branch" />
                    </div>
                    <div class="bg-gray-50 dark:bg-gray-800/40 rounded-md p-4 space-y-3">
                        <div class="flex items-start gap-2 text-sm">
                            <i class="ri-building-2-line text-gray-400 mt-0.5"></i>
                            <div>
                                <div class="text-xs text-gray-500">Поставщик</div>
                                <div class="text-gray-800 dark:text-gray-200 font-medium">{{ previewReceipt.supplier?.name || '—' }}</div>
                            </div>
                        </div>
                        <div class="flex items-start gap-2 text-sm">
                            <i class="ri-building-4-line text-gray-400 mt-0.5"></i>
                            <div>
                                <div class="text-xs text-gray-500">Склад</div>
                                <div class="text-gray-800 dark:text-gray-200 font-medium">{{ previewReceipt.warehouse?.name || '—' }}</div>
                            </div>
                        </div>
                        <div class="flex items-start gap-2 text-sm">
                            <i class="ri-store-2-line text-gray-400 mt-0.5"></i>
                            <div>
                                <div class="text-xs text-gray-500">Локация</div>
                                <div class="text-gray-800 dark:text-gray-200 font-medium">{{ previewReceipt.branch?.name || '—' }}</div>
                            </div>
                        </div>
                        <div v-if="previewReceipt.supplier_document_number" class="flex items-start gap-2 text-sm">
                            <i class="ri-file-list-3-line text-gray-400 mt-0.5"></i>
                            <div>
                                <div class="text-xs text-gray-500">Номер накладной поставщика</div>
                                <div class="text-gray-800 dark:text-gray-200 font-medium">{{ previewReceipt.supplier_document_number }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-gray-50 dark:bg-gray-800/40 rounded-md p-4 text-center">
                            <div class="text-xs text-gray-500 mb-1">Позиций в накладной</div>
                            <div class="text-lg font-bold text-gray-800 dark:text-gray-200">{{ previewReceipt.items_count }}</div>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800/40 rounded-md p-4 text-center">
                            <div class="text-xs text-gray-500 mb-1">Сумма закупки</div>
                            <div class="text-lg font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(previewReceipt.items_total) }}</div>
                        </div>
                        <div v-if="remainingAmount(previewReceipt) > 0" class="col-span-2 bg-danger/5 border border-danger/20 rounded-md p-4 flex justify-between items-center">
                            <span class="text-xs text-gray-500 dark:text-gray-400">Остаток долга поставщику</span>
                            <span class="text-lg font-bold text-danger">{{ formatMoney(remainingAmount(previewReceipt)) }}</span>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex justify-between gap-3 bg-gray-50/50 dark:bg-gray-800/30">
                    <button v-if="previewReceipt.status === 'posted'" @click="cancelReceipt(previewReceipt)" class="inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-danger/10 text-danger hover:bg-danger hover:text-white">
                        <i class="ri-delete-bin-line mr-1.5"></i> Отменить
                    </button>
                    <button v-if="remainingAmount(previewReceipt) > 0" @click="openPaymentModal(previewReceipt)" class="inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-success text-white hover:bg-success-600 shadow-sm">
                        <i class="ri-money-dollar-circle-line mr-1.5"></i> Оплатить
                    </button>
                    <Link :href="route('warehouse.goods-receipts.show', previewReceipt.id)" class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 shadow-sm">
                        Открыть накладную →
                    </Link>
                </div>
            </div>
        </Offcanvas>

        <!-- Принять оплату (иконка действия / панель просмотра) -->
        <Modal :show="isPaymentModalOpen" @close="closePaymentModal" max-width="md">
            <div v-if="paymentReceipt" class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Оплата поставщику — накладная №{{ String(paymentReceipt.id).padStart(6, '0') }}
                    </h3>
                    <button @click="closePaymentModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <form @submit.prevent="submitPayment" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div class="rounded-md bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0">
                                    <i class="ri-building-2-line"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-gray-800 dark:text-gray-200 break-words">{{ paymentReceipt.supplier?.name || 'Поставщик не указан' }}</p>
                                    <p class="text-xs text-gray-500 break-words">{{ new Date(paymentReceipt.receipt_date).toLocaleDateString('ru-RU') }}<span v-if="paymentReceipt.supplier_document_number"> · их №{{ paymentReceipt.supplier_document_number }}</span></p>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-2 pt-3 border-t border-gray-200 dark:border-gray-700">
                                <div class="text-center">
                                    <p class="text-[10px] uppercase tracking-wide text-gray-400 mb-0.5">Сумма закупки</p>
                                    <p class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(paymentReceipt.items_total) }}</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[10px] uppercase tracking-wide text-gray-400 mb-0.5">Оплачено</p>
                                    <p class="text-sm font-bold text-success">{{ formatMoney(paidAmount(paymentReceipt)) }}</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[10px] uppercase tracking-wide text-gray-400 mb-0.5">Остаток</p>
                                    <p class="text-sm font-bold text-danger">{{ formatMoney(remainingAmount(paymentReceipt)) }}</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Касса / Счет <span class="text-danger">*</span></label>
                            <select v-model="paymentForm.account_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" disabled>Выберите счет...</option>
                                <option v-for="acc in accounts" :key="acc.id" :value="acc.id">{{ acc.name }} — {{ accountTypeLabels[acc.type] || acc.type }}</option>
                            </select>
                            <span v-if="paymentForm.errors.account_id" class="text-xs text-danger mt-1 block">{{ paymentForm.errors.account_id }}</span>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма к оплате (₽) <span class="text-danger">*</span></label>
                            <input v-model="paymentForm.amount" type="number" step="0.01" min="0.01" :max="remainingAmount(paymentReceipt) / 100" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <p class="text-xs text-gray-500 mt-1">Остаток долга: {{ formatMoney(remainingAmount(paymentReceipt)) }}</p>
                            <span v-if="paymentForm.errors.amount" class="text-xs text-danger mt-1 block">{{ paymentForm.errors.amount }}</span>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closePaymentModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="paymentForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-success text-white hover:bg-success-600 disabled:opacity-50">Провести оплату</button>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
