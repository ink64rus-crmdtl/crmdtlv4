<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CollapsiblePanel from '@/Components/CollapsiblePanel.vue';
import ActivityTimeline from '@/Components/ActivityTimeline.vue';
import PointBadge from '@/Components/PointBadge.vue';
import Modal from '@/Components/Modal.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, router, usePage, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    receipt: Object,
    activities: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    productCategories: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    documentTemplates: { type: Array, default: () => [] },
});

const page = usePage();

const activeMainTab = ref('items');

const statusMeta = {
    posted: { label: 'Оприходована', class: 'bg-success/10 text-success' },
    canceled: { label: 'Отменена', class: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' },
};

const paymentStatuses = {
    unpaid: { label: 'Не оплачено', class: 'bg-danger/10 text-danger' },
    partial: { label: 'Частично оплачено', class: 'bg-warning/10 text-warning' },
    paid: { label: 'Оплачено', class: 'bg-success/10 text-success' },
};

const getLocalizedLabel = (label) => {
    if (!label) return '';
    if (typeof label === 'string') {
        try { label = JSON.parse(label); } catch (e) { return label; }
    }
    return label['ru'] || label['en'] || Object.values(label)[0] || '';
};

const formatMoney = (amount) => new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(amount / 100);

const cancelReceipt = () => {
    if (!confirm(`Отменить накладную №${String(props.receipt.id).padStart(6, '0')}? Все её движения будут реверсированы. Возможно, только если товар с неё ещё не был списан.`)) return;
    router.post(route('warehouse.goods-receipts.cancel', props.receipt.id), {}, { preserveScroll: true });
};

// --- Оплата поставщику (expense-транзакция, payable=GoodsReceipt) ---
const accountTypeLabels = {
    cash: 'Касса',
    bank: 'Расчетный счет',
    acquiring: 'Эквайринг',
};

const paidTransactions = computed(() => props.receipt.transactions?.filter(t => t.type === 'expense') || []);
const paidAmount = computed(() => paidTransactions.value.reduce((sum, t) => sum + t.amount, 0));
const remainingAmount = computed(() => Math.max(0, props.receipt.total_value - paidAmount.value));

const isPaymentModalOpen = ref(false);
const paymentForm = useForm({
    account_id: '',
    amount: 0,
});

const openPaymentModal = () => {
    paymentForm.reset();
    paymentForm.amount = remainingAmount.value / 100;
    if (props.accounts.length > 0) paymentForm.account_id = props.accounts[0].id;
    isPaymentModalOpen.value = true;
};

const closePaymentModal = () => {
    isPaymentModalOpen.value = false;
    paymentForm.reset();
    paymentForm.clearErrors();
};

const submitPayment = () => {
    paymentForm.post(route('warehouse.goods-receipts.pay', props.receipt.id), {
        preserveScroll: true,
        onSuccess: () => closePaymentModal(),
    });
};

// --- Документы (Фаза 12) ---
const selectedDocumentTemplateId = ref(props.documentTemplates[0]?.id ?? '');
const generatingDocument = ref(false);

const generateDocument = () => {
    if (!selectedDocumentTemplateId.value) return;
    generatingDocument.value = true;
    router.post(route('documents.generate'), {
        document_template_id: selectedDocumentTemplateId.value,
        entity_type: 'goods_receipt',
        entity_id: props.receipt.id,
    }, {
        preserveScroll: true,
        onFinish: () => { generatingDocument.value = false; },
    });
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

const productOptions = computed(() => props.products.map(p => ({
    value: p.id,
    label: `${getLocalizedLabel(p.name)} (${p.accounting_type === 'batch' ? 'Партионный' : 'Средневзвешенный'})`,
})));
const productById = (id) => props.products.find(p => p.id === id);

// --- Добавление/редактирование позиции (StockService::addReceiptItem/
// updateReceiptItem — правка честно реверсирует старое движение и
// оприходует заново новыми значениями, см. CLAUDE.md). Обе операции
// заблокированы сервером, если товар с позиции уже частично списан —
// ошибка придёт с сервера и покажется баннером наверху страницы. ---
const isItemModalOpen = ref(false);
const editingItem = ref(null); // null — добавление новой позиции

const itemForm = useForm({
    product_id: '',
    quantity: 1,
    cost_price: 0,
    batch_number: '',
});

const openAddItemModal = () => {
    editingItem.value = null;
    itemForm.reset();
    itemForm.quantity = 1;
    itemForm.cost_price = 0;
    isItemModalOpen.value = true;
};

const openEditItemModal = (item) => {
    editingItem.value = item;
    itemForm.product_id = item.product_id;
    itemForm.quantity = parseFloat(item.quantity);
    itemForm.cost_price = item.cost_price / 100; // копейки → рубли для формы
    itemForm.batch_number = item.batch_number || '';
    isItemModalOpen.value = true;
};

const closeItemModal = () => {
    isItemModalOpen.value = false;
    editingItem.value = null;
    itemForm.reset();
    itemForm.clearErrors();
};

const submitItem = () => {
    if (editingItem.value) {
        itemForm.put(route('warehouse.goods-receipts.items.update', [props.receipt.id, editingItem.value.id]), {
            preserveScroll: true,
            onSuccess: () => closeItemModal(),
        });
    } else {
        itemForm.post(route('warehouse.goods-receipts.items.store', props.receipt.id), {
            preserveScroll: true,
            onSuccess: () => closeItemModal(),
        });
    }
};

const deleteItem = (item) => {
    if (!confirm(`Удалить позицию «${getLocalizedLabel(item.product?.name)}» из накладной? Возможно, только если товар ещё не был списан.`)) return;
    router.delete(route('warehouse.goods-receipts.items.destroy', [props.receipt.id, item.id]), { preserveScroll: true });
};

// --- Быстрое добавление товара в справочник (тот же приём и роут, что в
// Warehouse/GoodsReceipts/Index.vue и Operations/WorkOrders/Show.vue) ---
const isQuickProductModalOpen = ref(false);
const quickProductForm = useForm({
    product_category_id: '',
    name: '',
    sku: '',
    unit: 'шт',
    accounting_type: 'average',
});

const openQuickProductModal = () => {
    quickProductForm.reset();
    quickProductForm.unit = 'шт';
    quickProductForm.accounting_type = 'average';
    isQuickProductModalOpen.value = true;
};

const closeQuickProductModal = () => {
    isQuickProductModalOpen.value = false;
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
            if (created) itemForm.product_id = created.id;
            closeQuickProductModal();
        },
    });
};
</script>

<template>
    <Head :title="`Накладная №${String(receipt.id).padStart(6, '0')}`" />

    <AuthenticatedLayout>
        <template #header>
            <Link :href="route('warehouse.goods-receipts.index')" class="text-gray-400 hover:text-primary transition-colors mr-2"><i class="ri-arrow-left-line"></i></Link>
            Приходная накладная №{{ String(receipt.id).padStart(6, '0') }}
        </template>

        <div v-if="page.props.errors.error" class="w-[99%] mx-auto mb-4 p-4 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-start gap-3">
            <i class="ri-error-warning-fill text-xl shrink-0"></i>
            <div><p class="font-bold mb-1">Ошибка:</p><p>{{ page.props.errors.error }}</p></div>
        </div>

        <!-- TRI-STATE 2: Карточка (w-[99%] mx-auto, трёхколоночная) -->
        <div class="w-[99%] mx-auto flex flex-col lg:flex-row gap-6 font-sans text-slate-600">

            <CollapsiblePanel storage-key="goods-receipt-card-left" side="left">

                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex flex-col items-center text-center">
                    <div class="w-20 h-20 rounded bg-primary/10 flex items-center justify-center text-primary font-bold text-3xl mb-4">
                        <i class="ri-truck-line"></i>
                    </div>
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight mb-1 font-mono">
                        Накладная №{{ String(receipt.id).padStart(6, '0') }}
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mb-1">
                        от {{ new Date(receipt.receipt_date).toLocaleDateString('ru-RU') }}
                    </p>
                    <p v-if="receipt.supplier_document_number" class="text-xs text-gray-400 mb-4">их №{{ receipt.supplier_document_number }}</p>
                    <div class="flex flex-wrap justify-center gap-2 mt-1">
                        <span :class="[statusMeta[receipt.status]?.class, 'inline-flex items-center py-0.5 px-2 rounded text-xs font-bold uppercase tracking-wide']">{{ statusMeta[receipt.status]?.label || receipt.status }}</span>
                        <PointBadge :branch="receipt.branch" :legal-entity="receipt.legal_entity" />
                    </div>
                </div>

                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Поставщик</h3>
                        <Link v-if="receipt.supplier" :href="route('crm.clients.show', receipt.supplier.id)" class="text-primary hover:text-primary-600 transition-colors text-sm font-medium">
                            Перейти <i class="ri-arrow-right-s-line"></i>
                        </Link>
                    </div>
                    <div class="p-6" v-if="receipt.supplier">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold shrink-0">
                                {{ receipt.supplier.name.charAt(0) }}
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-800 dark:text-gray-200 leading-tight">{{ receipt.supplier.name }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ receipt.supplier.phone || 'Нет телефона' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Склад</h3>
                    </div>
                    <div class="p-6 space-y-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-gray-500 dark:text-gray-400 shrink-0">
                                <i class="ri-building-4-line text-xl"></i>
                            </div>
                            <p class="text-sm font-bold text-gray-800 dark:text-gray-200 leading-tight">{{ receipt.warehouse?.name || '—' }}</p>
                        </div>
                        <div v-if="receipt.comment" class="pt-3 border-t border-gray-100 dark:border-gray-700/50">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Комментарий</p>
                            <p class="text-sm text-gray-700 dark:text-gray-300">{{ receipt.comment }}</p>
                        </div>
                    </div>
                </div>
            </CollapsiblePanel>

            <!-- Центральная колонка -->
            <div class="w-full lg:flex-1 lg:min-w-0 space-y-6">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col min-h-[400px]">
                    <div class="flex flex-wrap gap-x-6 gap-y-1 border-b border-gray-200 dark:border-gray-700 px-6 bg-gray-50/50 dark:bg-gray-800/50">
                        <button @click="activeMainTab = 'items'" :class="[activeMainTab === 'items' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-box-3-line"></i> Позиции
                        </button>
                        <button @click="activeMainTab = 'history'" :class="[activeMainTab === 'history' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-history-line"></i> История
                        </button>
                        <button @click="activeMainTab = 'documents'" :class="[activeMainTab === 'documents' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-file-text-line"></i> Документы
                        </button>
                    </div>

                    <div v-if="activeMainTab === 'items'" class="flex-1 flex flex-col">
                        <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-white dark:bg-[#313a46]">
                            <p v-if="receipt.status !== 'posted'" class="text-xs text-gray-400">Накладная отменена — позиции доступны только для просмотра.</p>
                            <span v-else></span>
                            <button v-if="receipt.status === 'posted'" @click="openAddItemModal" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold transition-all bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                                <i class="ri-add-line"></i> Добавить позицию
                            </button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left whitespace-nowrap">
                                <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                    <tr>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Товар</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Кол-во</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Цена</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Сумма</th>
                                        <th v-if="receipt.status === 'posted'" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="item in receipt.items" :key="item.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40">
                                        <td class="py-3 px-6 text-sm">
                                            <div class="font-bold text-gray-800 dark:text-gray-200">{{ item.product ? getLocalizedLabel(item.product.name) : '—' }}</div>
                                            <div v-if="item.batch_number" class="text-xs text-gray-500 mt-0.5">Партия: {{ item.batch_number }}</div>
                                        </td>
                                        <td class="py-3 px-6 text-sm text-gray-800 dark:text-gray-200 text-right">{{ parseFloat(item.quantity) }} {{ item.product?.unit }}</td>
                                        <td class="py-3 px-6 text-sm text-gray-800 dark:text-gray-200 text-right">{{ formatMoney(item.cost_price) }}</td>
                                        <td class="py-3 px-6 text-sm font-bold text-gray-800 dark:text-gray-200 text-right">{{ formatMoney(Math.round(item.quantity * item.cost_price)) }}</td>
                                        <td v-if="receipt.status === 'posted'" class="py-3 px-6 text-sm text-right space-x-1 whitespace-nowrap">
                                            <button @click="openEditItemModal(item)" class="inline-flex items-center justify-center rounded px-2.5 py-1.5 text-xs font-medium transition-all bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать"><i class="ri-pencil-line"></i></button>
                                            <button @click="deleteItem(item)" class="inline-flex items-center justify-center rounded px-2.5 py-1.5 text-xs font-medium transition-all bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                        </td>
                                    </tr>
                                    <tr v-if="receipt.items.length === 0">
                                        <td :colspan="receipt.status === 'posted' ? 5 : 4" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">Позиций пока нет.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="activeMainTab === 'history'" class="flex-1 flex flex-col min-h-0">
                        <ActivityTimeline :activities="activities" />
                    </div>

                    <div v-if="activeMainTab === 'documents'" class="flex-1 flex flex-col min-h-0">
                        <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46] flex items-center gap-2">
                            <select v-if="documentTemplates.length > 0" v-model="selectedDocumentTemplateId" class="block w-64 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option v-for="t in documentTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                            <button v-if="documentTemplates.length > 0" @click="generateDocument" :disabled="generatingDocument" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm disabled:opacity-50">
                                <i class="ri-file-add-line"></i> Сформировать документ
                            </button>
                            <p v-else class="text-sm text-gray-400">Нет активных шаблонов документов для накладных — настройте их в Настройках → Шаблоны документов.</p>
                        </div>
                        <div class="flex-1 overflow-auto custom-scrollbar">
                            <table v-if="receipt.documents && receipt.documents.length > 0" class="min-w-full text-left">
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="doc in receipt.documents" :key="doc.id" :class="[doc.superseded_by_document_id ? 'opacity-50' : '', 'odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors']">
                                        <td class="py-3 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200">
                                            {{ doc.number }}
                                            <span v-if="doc.superseded_by_document_id" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 ml-1" :title="`Заменён документом №${doc.superseded_by?.number ?? ''}`">заменён</span>
                                            <i v-else-if="doc.is_stale" class="ri-error-warning-line text-warning ml-1" title="Данные накладной изменились с момента формирования — рекомендуем обновить документ"></i>
                                        </td>
                                        <td class="py-3 px-6 text-sm text-gray-600 dark:text-gray-300">{{ doc.title }}</td>
                                        <td class="py-3 px-6 text-sm text-gray-400">{{ new Date(doc.created_at).toLocaleDateString('ru-RU') }}</td>
                                        <td class="py-3 px-6 text-sm text-right space-x-1 whitespace-nowrap">
                                            <template v-if="doc.is_stale && !doc.superseded_by_document_id">
                                                <button @click="regenerateAsNew(doc)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-warning/10 text-warning hover:bg-warning hover:text-white" title="Сформировать новый документ (этот сохранится в истории)"><i class="ri-file-add-line"></i></button>
                                                <button @click="replaceDocument(doc)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-warning/10 text-warning hover:bg-warning hover:text-white" title="Заменить этот документ актуальными данными (номер тот же)"><i class="ri-refresh-line"></i></button>
                                            </template>
                                            <a :href="route('documents.print', doc.id)" target="_blank" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Печать"><i class="ri-printer-line"></i></a>
                                            <a :href="route('documents.download', doc.id)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Скачать PDF"><i class="ri-download-2-line"></i></a>
                                            <button @click="deleteDocument(doc)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-else class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Документов по этой накладной ещё нет.</div>
                        </div>
                    </div>
                </div>
            </div>

            <CollapsiblePanel storage-key="goods-receipt-card-right" side="right">

                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Итоги</h3>
                        <span :class="[paymentStatuses[receipt.payment_status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold tracking-wide uppercase']">
                            {{ paymentStatuses[receipt.payment_status]?.label || receipt.payment_status }}
                        </span>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Позиций:</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ receipt.items.length }}</span>
                        </div>
                        <div class="pt-4 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                            <span class="font-bold text-gray-800 dark:text-gray-200">Сумма закупки:</span>
                            <span class="text-xl font-bold text-success">{{ formatMoney(receipt.total_value) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Оплачено поставщику:</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ formatMoney(paidAmount) }}</span>
                        </div>
                        <div v-if="remainingAmount > 0" class="flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Остаток долга:</span>
                            <span class="font-bold text-danger">{{ formatMoney(remainingAmount) }}</span>
                        </div>
                        <button
                            v-if="receipt.status === 'posted' && remainingAmount > 0"
                            @click="openPaymentModal"
                            class="w-full inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-semibold transition-all duration-300 bg-success text-white hover:bg-success-600 shadow-sm"
                        >
                            <i class="ri-money-dollar-circle-line"></i> Оплатить поставщику
                        </button>
                    </div>
                </div>

                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Действия</h3>
                    </div>
                    <div class="p-6">
                        <button
                            v-if="receipt.status === 'posted'"
                            @click="cancelReceipt"
                            class="w-full inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-semibold transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                        >
                            <i class="ri-close-circle-line"></i> Отменить накладную
                        </button>
                        <p v-if="receipt.status === 'posted'" class="text-[11px] text-gray-400 mt-2">Реверсирует движения и остатки. Недоступно, если товар с этой накладной уже частично списан — тогда система объяснит это в ошибке.</p>
                        <p v-else class="text-sm text-gray-400 text-center">Накладная отменена, действия недоступны.</p>
                    </div>
                </div>
            </CollapsiblePanel>
        </div>

        <!-- Добавление/редактирование позиции -->
        <Modal :show="isItemModalOpen" @close="closeItemModal" max-width="lg">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingItem ? 'Редактирование позиции' : 'Новая позиция' }}</h3>
                    <button @click="closeItemModal()" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitItem">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Товар <span class="text-danger">*</span></label>
                            <div class="flex gap-2 items-start">
                                <div class="flex-1 min-w-0"><SearchableSelect v-model="itemForm.product_id" :options="productOptions" placeholder="Выберите товар..." /></div>
                                <button type="button" @click="openQuickProductModal" title="Добавить товар в справочник" class="shrink-0 inline-flex items-center justify-center rounded-md w-9 h-9 bg-primary/10 text-primary hover:bg-primary hover:text-white transition-colors"><i class="ri-add-line text-lg"></i></button>
                            </div>
                            <span v-if="itemForm.errors.product_id" class="text-xs text-danger mt-1 block">{{ itemForm.errors.product_id }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Кол-во <span class="text-danger">*</span></label>
                                <input v-model="itemForm.quantity" type="number" step="any" min="0.001" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <span v-if="itemForm.errors.quantity" class="text-xs text-danger mt-1 block">{{ itemForm.errors.quantity }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цена закупки (₽) <span class="text-danger">*</span></label>
                                <input v-model="itemForm.cost_price" type="number" step="0.01" min="0" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <span v-if="itemForm.errors.cost_price" class="text-xs text-danger mt-1 block">{{ itemForm.errors.cost_price }}</span>
                            </div>
                        </div>
                        <div v-if="productById(itemForm.product_id)?.accounting_type === 'batch'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Номер партии/серии</label>
                            <input v-model="itemForm.batch_number" type="text" placeholder="Необязательно" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>
                        <p v-if="editingItem" class="text-[11px] text-gray-400">Правка честно реверсирует старое движение по складу и оприходует заново новыми значениями — недоступно, если товар с этой позиции уже частично списан.</p>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeItemModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="itemForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Быстрое добавление товара -->
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

        <!-- Оплата поставщику -->
        <Modal :show="isPaymentModalOpen" @close="closePaymentModal" max-width="md">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Оплата поставщику</h3>
                    <button @click="closePaymentModal()" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitPayment">
                    <div class="p-6 space-y-4">
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
                            <input v-model="paymentForm.amount" type="number" step="0.01" min="0.01" :max="remainingAmount / 100" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <p class="text-xs text-gray-500 mt-1">Остаток долга: {{ formatMoney(remainingAmount) }}</p>
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
