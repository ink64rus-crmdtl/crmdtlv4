<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import Modal from '@/Components/Modal.vue';
import EmployeeMultiSelect from '@/Components/EmployeeMultiSelect.vue';
import draggable from 'vuedraggable';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';

const props = defineProps({
    workOrder: Object,
    customFieldsData: Array,
    branches: Array,
    clients: Array,
    vehicles: Array,
    services: Array,
    products: Array,
    accounts: Array,
    customFieldDefs: Array,
    pricingBasis: String,
    lookups: Object,
    businessDirections: Array,
    serviceCategories: Array,
    productCategories: Array,
    employees: Array,
    workOrderStatuses: { type: Array, default: () => [] },
    bonusRubPerPoint: { type: Number, default: 1 },
});

const page = usePage();

const isModalOpen = ref(false);
const activeMainTab = ref('items'); // 'items', 'history'

const statusColorClasses = {
    info: 'bg-info/10 text-info',
    warning: 'bg-warning/10 text-warning',
    success: 'bg-success/10 text-success',
    danger: 'bg-danger/10 text-danger',
    primary: 'bg-primary/10 text-primary',
    gray: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
};

const statuses = computed(() => {
    const map = {};
    props.workOrderStatuses.forEach(s => {
        map[s.value] = { label: s.label || s.value, class: statusColorClasses[s.color] || statusColorClasses.gray };
    });
    return map;
});

const paymentStatuses = {
    'unpaid': { label: 'Не оплачен', class: 'bg-danger/10 text-danger' },
    'partial': { label: 'Частично', class: 'bg-warning/10 text-warning' },
    'paid': { label: 'Оплачен', class: 'bg-success/10 text-success' },
};

// Форма редактирования шапки
const form = useForm({
    branch_id: '',
    client_id: '',
    vehicle_id: '',
    status: '',
    mileage: '',
    custom_fields: {},
});

const openModal = () => {
    form.branch_id = props.workOrder.branch_id;
    form.client_id = props.workOrder.client_id;
    form.vehicle_id = props.workOrder.vehicle_id || '';
    form.status = props.workOrder.status;
    form.mileage = props.workOrder.mileage || '';
    
    const cf = {};
    props.customFieldDefs.forEach(def => {
        const existingVal = props.customFieldsData.find(d => d.definition.id === def.id)?.value;
        cf[def.key] = existingVal !== undefined && existingVal !== null 
            ? existingVal 
            : (def.type === 'checkbox' ? false : '');
    });
    form.custom_fields = cf;
    
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    form.put(route('operations.work-orders.update', props.workOrder.id), {
        onSuccess: () => closeModal(),
    });
};

// --- ФУНКЦИОНАЛ ПАКЕТНОЙ ДВУХКОЛОНОЧНОЙ КОРЗИНЫ (DRAWER) ---
const isBatchDrawerOpen = ref(false);
const drawerSearch = ref('');
const drawerTab = ref('services'); // 'services' | 'products'
const selectedDirectionId = ref('');
const addedToastMessage = ref('');
const showToast = ref(false);

const triggerToast = (msg) => {
    addedToastMessage.value = msg;
    showToast.value = true;
    setTimeout(() => {
        showToast.value = false;
    }, 2000);
};

// Подсчет количества добавленных единиц конкретного товара/услуги
const getItemCountInOrder = (type, itemId) => {
    if (!props.workOrder.items) return 0;
    const targetClass = type === 'service' ? 'Service' : 'Product';
    return props.workOrder.items
        .filter(item => item.itemable_type.includes(targetClass) && item.itemable_id === itemId)
        .reduce((sum, item) => sum + parseFloat(item.quantity), 0);
};

// Фильтрация услуг в слайдере по поиску и направлению бизнеса
const filteredDrawerServices = computed(() => {
    let list = props.services;
    if (selectedDirectionId.value) {
        list = list.filter(s => s.business_direction_id === selectedDirectionId.value);
    }
    if (drawerSearch.value) {
        const query = drawerSearch.value.toLowerCase();
        list = list.filter(s => getLocalizedLabel(s.name).toLowerCase().includes(query));
    }
    return list;
});

// Фильтрация товаров в слайдере по поиску
const filteredDrawerProducts = computed(() => {
    let list = props.products;
    if (drawerSearch.value) {
        const query = drawerSearch.value.toLowerCase();
        list = list.filter(p => getLocalizedLabel(p.name).toLowerCase().includes(query) || (p.sku && p.sku.toLowerCase().includes(query)));
    }
    return list;
});

// Группировка услуг по категориям для красивого вывода
const groupedDrawerServices = computed(() => {
    const groups = {};
    filteredDrawerServices.value.forEach(service => {
        const catId = service.service_category_id || 0;
        if (!groups[catId]) {
            const cat = props.serviceCategories.find(c => c.id === catId);
            groups[catId] = {
                id: catId,
                name: cat ? getLocalizedLabel(cat.name) : 'Без категории',
                items: []
            };
        }
        groups[catId].items.push(service);
    });
    return Object.values(groups);
});

// Группировка товаров по категориям для красивого вывода
const groupedDrawerProducts = computed(() => {
    const groups = {};
    filteredDrawerProducts.value.forEach(product => {
        const catId = product.product_category_id || 0;
        if (!groups[catId]) {
            const cat = props.productCategories.find(c => c.id === catId);
            groups[catId] = {
                id: catId,
                name: cat ? getLocalizedLabel(cat.name) : 'Без категории',
                items: []
            };
        }
        groups[catId].items.push(product);
    });
    return Object.values(groups);
});

// Добавление позиции напрямую из слайдера без закрытия
const addItemDirect = (type, item) => {
    let finalPrice = 0;
    let name = getLocalizedLabel(item.name);
    
    if (type === 'service') {
        finalPrice = item.price / 100; // Базовая цена
        
        // Расчет цены на основе матрицы
        if (props.pricingBasis === 'vehicle_body' && props.workOrder.vehicle?.vehicle_model?.body_type) {
            const bodyType = props.workOrder.vehicle.vehicle_model.body_type;
            if (item.prices && item.prices[bodyType]) {
                finalPrice = item.prices[bodyType] / 100;
            }
        } else if (props.pricingBasis === 'vehicle_class' && props.workOrder.vehicle?.vehicle_model?.category) {
            const vClass = props.workOrder.vehicle.vehicle_model.category;
            if (item.prices && item.prices[vClass]) {
                finalPrice = item.prices[vClass] / 100;
            }
        }
    }

    router.post(route('operations.work-orders.items.store', props.workOrder.id), {
        itemable_type: type,
        itemable_id: item.id,
        name: name,
        quantity: 1,
        price: finalPrice,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            triggerToast(`"${name}" добавлено в заказ!`);
        }
    });
};

// Обновление деталей позиции прямо из слайдера или таблицы
const updateItemDetails = (item, fields) => {
    router.put(route('operations.work-orders.items.update', [props.workOrder.id, item.id]), fields, {
        preserveScroll: true,
    });
};

const updateItemEmployees = (item, employeeIds) => {
    updateItemDetails(item, { employee_ids: employeeIds });
};

// Скидка на отдельную позицию (сумма ₽ или %)
const itemDiscountMode = ref({}); // item.id -> 'amount' | 'percent'

const getItemDiscountMode = (item) => itemDiscountMode.value[item.id] || 'amount';

const setItemDiscountMode = (item, mode) => {
    itemDiscountMode.value = { ...itemDiscountMode.value, [item.id]: mode };
};

const itemBaseRub = (item) => (parseFloat(item.quantity) * item.price) / 100;

const itemDiscountPercent = (item) => {
    const base = itemBaseRub(item);
    if (base <= 0) return 0;
    return Math.round(((item.discount_amount || 0) / 100 / base) * 1000) / 10;
};

const itemDiscountDisplayValue = (item) => {
    const amountRub = (item.discount_amount || 0) / 100;
    if (getItemDiscountMode(item) === 'amount') return amountRub;
    const base = itemBaseRub(item);
    return base > 0 ? Math.round((amountRub / base) * 10000) / 100 : 0;
};

const applyItemDiscount = (item, rawValue) => {
    const raw = Number(rawValue) || 0;
    const base = itemBaseRub(item);
    const amountRub = getItemDiscountMode(item) === 'amount' ? raw : Math.round(base * raw) / 100;
    const clamped = Math.max(0, Math.min(amountRub, base));
    updateItemDetails(item, { discount_amount: clamped });
};

// --- ФОРМА ОДИНОЧНОГО ДОБАВЛЕНИЯ (Fallback) ---
const isItemModalOpen = ref(false);
const itemForm = useForm({
    itemable_type: 'service',
    itemable_id: '',
    name: '',
    quantity: 1,
    price: 0,
});

const openItemModal = () => {
    itemForm.reset();
    itemForm.itemable_type = 'service';
    itemForm.quantity = 1;
    itemForm.price = 0;
    isItemModalOpen.value = true;
};

const closeItemModal = () => {
    isItemModalOpen.value = false;
    itemForm.reset();
    itemForm.clearErrors();
};

const submitItem = () => {
    itemForm.post(route('operations.work-orders.items.store', props.workOrder.id), {
        onSuccess: () => closeItemModal(),
    });
};

const deleteItem = (item) => {
    if (confirm(`Удалить позицию "${item.name}"?`)) {
        router.delete(route('operations.work-orders.items.destroy', [props.workOrder.id, item.id]));
    }
};

// --- ПОРЯДОК ПОЗИЦИЙ (drag-and-drop + автосортировка "услуги сверху, товары снизу") ---
const sortedItems = ref([...(props.workOrder.items || [])]);
watch(() => props.workOrder.items, (items) => {
    sortedItems.value = [...(items || [])];
});

const onItemsReordered = () => {
    router.post(route('operations.work-orders.items.reorder', props.workOrder.id), {
        ids: sortedItems.value.map(i => i.id),
    }, { preserveScroll: true, preserveState: true });
};

const autoSortItems = () => {
    router.post(route('operations.work-orders.items.auto-sort', props.workOrder.id), {}, { preserveScroll: true });
};

watch(() => itemForm.itemable_id, (newId) => {
    if (!newId) return;
    if (itemForm.itemable_type === 'service') {
        const service = props.services.find(s => s.id === newId);
        if (service) {
            itemForm.name = getLocalizedLabel(service.name);
            
            let finalPrice = service.price;
            if (props.pricingBasis === 'vehicle_body' && props.workOrder.vehicle?.vehicle_model?.body_type) {
                const bodyType = props.workOrder.vehicle.vehicle_model.body_type;
                if (service.prices && service.prices[bodyType]) {
                    finalPrice = service.prices[bodyType];
                }
            } else if (props.pricingBasis === 'vehicle_class' && props.workOrder.vehicle?.vehicle_model?.category) {
                const vClass = props.workOrder.vehicle.vehicle_model.category;
                if (service.prices && service.prices[vClass]) {
                    finalPrice = service.prices[vClass];
                }
            }
            itemForm.price = finalPrice / 100;
        }
    } else {
        const product = props.products.find(p => p.id === newId);
        if (product) {
            itemForm.name = getLocalizedLabel(product.name);
            itemForm.price = 0;
        }
    }
});

watch(() => itemForm.itemable_type, () => {
    itemForm.itemable_id = '';
    itemForm.name = '';
    itemForm.price = 0;
});

// --- СВОЯ ПОЗИЦИЯ-УСЛУГА БЕЗ КАРТОЧКИ В КАТАЛОГЕ (добавляется в заказ сразу, каталог — по желанию) ---
const isQuickServiceModalOpen = ref(false);
const quickServiceForm = useForm({
    service_category_id: '',
    business_direction_id: '',
    name: '',
    price: 0,
    duration_minutes: 60,
    save_to_catalog: true,
});

const openQuickServiceModal = () => {
    quickServiceForm.reset();
    quickServiceForm.save_to_catalog = true;
    isQuickServiceModalOpen.value = true;
};

const closeQuickServiceModal = () => {
    isQuickServiceModalOpen.value = false;
    quickServiceForm.reset();
    quickServiceForm.clearErrors();
};

const submitQuickService = () => {
    quickServiceForm.transform((data) => ({
        itemable_type: 'service',
        is_custom: true,
        save_to_catalog: data.save_to_catalog,
        service_category_id: data.service_category_id || null,
        business_direction_id: data.business_direction_id || null,
        name: data.name,
        price: data.price,
        duration_minutes: data.duration_minutes,
        quantity: 1,
    })).post(route('operations.work-orders.items.store', props.workOrder.id), {
        onSuccess: () => closeQuickServiceModal(),
    });
};

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
    quickProductForm.post(route('operations.work-orders.quick-product'), {
        onSuccess: () => closeQuickProductModal(),
    });
};

// Форма скидки
const isDiscountModalOpen = ref(false);
const discountForm = useForm({
    discount_amount: 0,
});

const openDiscountModal = () => {
    discountForm.discount_amount = props.workOrder.discount_amount / 100;
    isDiscountModalOpen.value = true;
};

const closeDiscountModal = () => {
    isDiscountModalOpen.value = false;
    discountForm.reset();
    discountForm.clearErrors();
};

const submitDiscount = () => {
    discountForm.post(route('operations.work-orders.discount.update', props.workOrder.id), {
        onSuccess: () => closeDiscountModal(),
    });
};

const orderDiscountPercent = computed(() => {
    if (!props.workOrder.total_amount) return 0;
    return Math.round((props.workOrder.discount_amount / props.workOrder.total_amount) * 1000) / 10;
});

// Инлайн-скидка в корзине пакетного добавления (сумма ₽ или %)
const drawerDiscountMode = ref('amount'); // 'amount' | 'percent'
const drawerDiscountValue = ref(0);

const drawerTotalRub = computed(() => props.workOrder.total_amount / 100);

const syncDrawerDiscountFromOrder = () => {
    const amountRub = props.workOrder.discount_amount / 100;
    if (drawerDiscountMode.value === 'amount') {
        drawerDiscountValue.value = amountRub;
    } else {
        drawerDiscountValue.value = drawerTotalRub.value > 0
            ? Math.round((amountRub / drawerTotalRub.value) * 10000) / 100
            : 0;
    }
};

const setDrawerDiscountMode = (mode) => {
    if (mode === drawerDiscountMode.value) return;
    drawerDiscountMode.value = mode;
    syncDrawerDiscountFromOrder();
};

const drawerDiscountAmountRub = computed(() => {
    const value = Number(drawerDiscountValue.value) || 0;
    if (drawerDiscountMode.value === 'amount') {
        return value;
    }
    return Math.round(drawerTotalRub.value * value) / 100;
});

const applyDrawerDiscount = () => {
    const amount = Math.max(0, Math.min(drawerDiscountAmountRub.value, drawerTotalRub.value));
    router.post(route('operations.work-orders.discount.update', props.workOrder.id), {
        discount_amount: amount,
    }, { preserveScroll: true });
};

watch(isBatchDrawerOpen, (open) => {
    if (open) syncDrawerDiscountFromOrder();
});
watch(() => props.workOrder.discount_amount, syncDrawerDiscountFromOrder);

// Форма оплаты
const isPaymentModalOpen = ref(false);
const paymentForm = useForm({
    account_id: '',
    amount: 0,
});

const remainingAmount = computed(() => {
    const paid = props.workOrder.transactions?.filter(t => t.type === 'income').reduce((sum, t) => sum + t.amount, 0) || 0;
    return Math.max(0, props.workOrder.final_amount - paid);
});

// Только поступления (без служебных расходов вроде комиссии эквайринга)
const paymentTransactions = computed(() => props.workOrder.transactions?.filter(t => t.type === 'income') || []);

const transactionLink = (tx) => route('finance.transactions.index', { filters: { id: tx.id } });

const accountTypeLabels = {
    cash: 'Касса',
    bank: 'Расчетный счет',
    acquiring: 'Эквайринг',
    bonus: 'Бонусы клиента',
};

const selectedAccount = computed(() => props.accounts.find(a => a.id === paymentForm.account_id));

// Сколько клиент может оплатить бонусами, в рублях
const clientBonusRub = computed(() => {
    const points = props.workOrder.client?.bonus_points || 0;
    return Math.floor(points * props.bonusRubPerPoint * 100) / 100;
});

// Превью комиссии эквайринга — чисто информационно, реальный расчет всегда на бэкенде
const acquiringCommissionPreview = computed(() => {
    if (selectedAccount.value?.type !== 'acquiring') return null;
    const commissionPercent = Number(selectedAccount.value.commission_percent) || 0;
    const amount = Number(paymentForm.amount) || 0;
    const commission = Math.round(amount * commissionPercent) / 100;
    return { commission, net: Math.max(0, amount - commission) };
});

const maxPayableRub = computed(() => {
    const remaining = remainingAmount.value / 100;
    return selectedAccount.value?.type === 'bonus' ? Math.min(remaining, clientBonusRub.value) : remaining;
});

const openPaymentModal = () => {
    paymentForm.reset();
    paymentForm.amount = remainingAmount.value / 100;
    if (props.accounts && props.accounts.length > 0) {
        paymentForm.account_id = props.accounts[0].id;
    }
    isPaymentModalOpen.value = true;
};

const closePaymentModal = () => {
    isPaymentModalOpen.value = false;
    paymentForm.reset();
    paymentForm.clearErrors();
};

const submitPayment = () => {
    paymentForm.post(route('operations.work-orders.payment.store', props.workOrder.id), {
        onSuccess: () => closePaymentModal(),
    });
};

// Завершение заказа (Списание склада)
const completeOrderForm = useForm({});
const completeOrder = () => {
    if (confirm('Вы уверены, что хотите завершить заказ? Это действие спишет все материалы со склада и зафиксирует статус.')) {
        completeOrderForm.post(route('operations.work-orders.complete', props.workOrder.id));
    }
};

// Вспомогательные функции
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
</script>

<template>
    <Head :title="`Заказ-наряд #${String(workOrder.id).padStart(6, '0')}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between w-full">
                <div class="flex items-center gap-2">
                    <Link :href="route('operations.work-orders.index')" class="text-gray-500 hover:text-primary transition-colors">
                        <i class="ri-arrow-left-line"></i> Заказ-наряды
                    </Link>
                    <span class="text-gray-400">/</span>
                    <span class="font-semibold text-gray-800 dark:text-gray-200 font-mono">Заказ #{{ String(workOrder.id).padStart(6, '0') }}</span>
                </div>
                <div class="flex gap-2">
                    <button v-if="workOrder.status !== 'completed'" @click="completeOrder" :disabled="completeOrderForm.processing" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-success text-white hover:bg-success-600 shadow-sm disabled:opacity-50">
                        <i class="ri-check-double-line mr-1.5"></i> Завершить заказ
                    </button>
                    <button @click="openModal" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">
                        <i class="ri-pencil-line mr-1.5"></i> Редактировать шапку
                    </button>
                </div>
            </div>
        </template>

        <!-- Блок ошибок (например, нехватка на складе) -->
        <div v-if="page.props.errors.error" class="w-[99%] mx-auto mb-4 p-4 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-start gap-3">
            <i class="ri-error-warning-fill text-xl shrink-0"></i>
            <div>
                <p class="font-bold mb-1">Ошибка выполнения операции:</p>
                <p>{{ page.props.errors.error }}</p>
            </div>
        </div>

        <!-- TRI-STATE 2: Полная карточка (w-[99%] mx-auto для Fluid-дизайна) -->
        <div class="w-[99%] mx-auto flex flex-col lg:flex-row gap-6 font-sans text-slate-600">
            
            <!-- Левая колонка: About (Свойства сущности) -->
            <div class="w-full lg:w-1/4 space-y-6 flex-shrink-0">
                
                <!-- Статус и Базовая инфа -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex flex-col items-center text-center">
                    <div class="w-20 h-20 rounded bg-primary/10 flex items-center justify-center text-primary font-bold text-3xl mb-4">
                        <i class="ri-briefcase-line"></i>
                    </div>
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight mb-1 font-mono">
                        Заказ #{{ String(workOrder.id).padStart(6, '0') }}
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mb-4">
                        от {{ new Date(workOrder.created_at).toLocaleDateString('ru-RU') }}
                    </p>
                    <div class="flex flex-wrap justify-center gap-2">
                        <span :class="[statuses[workOrder.status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center px-3 py-1 rounded-md text-xs font-bold tracking-wide uppercase']">
                            {{ statuses[workOrder.status]?.label || workOrder.status }}
                        </span>
                    </div>
                </div>

                <!-- Клиент -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Клиент</h3>
                        <Link v-if="workOrder.client" :href="route('crm.clients.show', workOrder.client.id)" class="text-primary hover:text-primary-600 transition-colors text-sm font-medium">
                            Перейти <i class="ri-arrow-right-s-line"></i>
                        </Link>
                    </div>
                    <div class="p-6 space-y-4" v-if="workOrder.client">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold shrink-0">
                                {{ workOrder.client.name.charAt(0) }}
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-800 dark:text-gray-200 leading-tight">{{ workOrder.client.name }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ workOrder.client.phone || 'Нет телефона' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Автомобиль -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Автомобиль</h3>
                        <Link v-if="workOrder.vehicle" :href="route('crm.vehicles.show', workOrder.vehicle.id)" class="text-primary hover:text-primary-600 transition-colors text-sm font-medium">
                            Перейти <i class="ri-arrow-right-s-line"></i>
                        </Link>
                    </div>
                    <div class="p-6 space-y-4" v-if="workOrder.vehicle">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-gray-500 dark:text-gray-400 shrink-0">
                                <i class="ri-car-line text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-800 dark:text-gray-200 leading-tight">{{ workOrder.vehicle.make ? workOrder.vehicle.make.name : '' }} {{ workOrder.vehicle.vehicle_model ? workOrder.vehicle.vehicle_model.name : '' }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ workOrder.vehicle.plate_number || 'Госномер не указан' }}</p>
                            </div>
                        </div>
                        <div v-if="workOrder.mileage" class="pt-3 border-t border-gray-100 dark:border-gray-700/50">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Пробег при заезде</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ workOrder.mileage }} км</p>
                        </div>
                    </div>
                    <div v-else class="p-6 text-sm text-gray-500 text-center">
                        Автомобиль не привязан
                    </div>
                </div>

                <!-- Кастомные поля (EAV) -->
                <div v-if="customFieldsData && customFieldsData.length > 0" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Дополнительная информация</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div v-for="field in customFieldsData" :key="field.definition.id">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ getLocalizedLabel(field.definition.label) }}</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                <template v-if="field.definition.type === 'checkbox'">
                                    {{ field.value == '1' ? 'Да' : 'Нет' }}
                                </template>
                                <template v-else>
                                    {{ field.value || '—' }}
                                </template>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Центральная колонка: Работы и Таймлайн -->
            <div class="w-full lg:w-2/4 space-y-6">
                
                <!-- Вкладки (Работы / История) -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col h-full min-h-[600px]">
                    <div class="flex space-x-6 border-b border-gray-200 dark:border-gray-700 px-6 bg-gray-50/50 dark:bg-gray-800/50">
                        <button @click="activeMainTab = 'items'" :class="[activeMainTab === 'items' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-tools-line"></i> Работы и Запчасти
                        </button>
                        <button @click="activeMainTab = 'history'" :class="[activeMainTab === 'history' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-history-line"></i> История
                        </button>
                    </div>
                    
                    <div v-if="activeMainTab === 'items'" class="flex-1 flex flex-col">
                        <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-white dark:bg-[#313a46]">
                            <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Позиции заказа</h3>
                            <div class="flex gap-2">
                                <button v-if="workOrder.status !== 'completed'" @click="isBatchDrawerOpen = true" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                                    <i class="ri-add-line"></i> Добавить позицию
                                </button>
                                <button v-if="workOrder.status !== 'completed'" @click="openItemModal" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm gap-1.5">
                                    <i class="ri-search-line"></i> Быстрый поиск
                                </button>
                                <button
                                    v-if="workOrder.status !== 'completed' && workOrder.items && workOrder.items.length > 1"
                                    @click="autoSortItems"
                                    title="Услуги сверху, товары снизу"
                                    class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm gap-1.5"
                                >
                                    <i class="ri-sort-desc"></i> Упорядочить
                                </button>
                            </div>
                        </div>
                        
                        <div class="overflow-x-auto w-full flex-1">
                            <table class="min-w-full text-left">
                                <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                    <tr>
                                        <th class="py-3 px-2 border-b border-gray-200 dark:border-gray-700 w-8"></th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Наименование</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Исполнитель</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Кол-во</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Цена</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Скидка</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Сумма</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right w-16"></th>
                                    </tr>
                                </thead>
                                <tbody v-if="!workOrder.items || workOrder.items.length === 0" class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr>
                                        <td colspan="8" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                            В заказ-наряд еще не добавлены услуги или товары. Нажмите "Добавить позицию" или "Быстрый поиск".
                                        </td>
                                    </tr>
                                </tbody>
                                <draggable
                                    v-else
                                    v-model="sortedItems"
                                    tag="tbody"
                                    item-key="id"
                                    class="divide-y divide-gray-200 dark:divide-gray-700"
                                    handle=".item-drag-handle"
                                    :disabled="workOrder.status === 'completed'"
                                    @end="onItemsReordered"
                                >
                                    <template #item="{ element: item }">
                                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                            <td class="py-3 px-2 text-center">
                                                <i v-if="workOrder.status !== 'completed'" class="ri-draggable item-drag-handle text-gray-400 cursor-grab active:cursor-grabbing" title="Перетащить для сортировки"></i>
                                            </td>
                                            <td class="py-3 px-6 text-sm font-medium text-gray-800 dark:text-gray-200">
                                                <div>{{ item.name }}</div>
                                                <div class="mt-1">
                                                    <span v-if="item.itemable_type.includes('Service')" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 uppercase tracking-wider"><i class="ri-tools-line"></i> Услуга</span>
                                                    <span v-else class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 uppercase tracking-wider"><i class="ri-box-3-line"></i> Товар</span>
                                                </div>
                                            </td>
                                            <td class="py-3 px-6 text-sm">
                                                <!-- Выбор Исполнителей на позицию, можно нескольких (Только для услуг) -->
                                                <EmployeeMultiSelect
                                                    v-if="item.itemable_type.includes('Service')"
                                                    class="max-w-[240px]"
                                                    :model-value="(item.employees || []).map(e => e.id)"
                                                    :options="employees"
                                                    :disabled="workOrder.status === 'completed'"
                                                    @update:model-value="ids => updateItemEmployees(item, ids)"
                                                />
                                                <span v-else class="text-xs text-gray-400 font-medium">Складское списание</span>
                                            </td>
                                            <td class="py-3 px-6 text-sm text-gray-800 dark:text-gray-200 text-right">{{ parseFloat(item.quantity) }}</td>
                                            <td class="py-3 px-6 text-sm text-gray-800 dark:text-gray-200 text-right">{{ formatMoney(item.price) }}</td>
                                            <td class="py-3 px-6 text-sm text-right">
                                                <span v-if="item.discount_amount > 0" class="text-danger font-medium">- {{ formatMoney(item.discount_amount) }} <span class="text-gray-400 font-normal">({{ itemDiscountPercent(item) }}%)</span></span>
                                                <span v-else class="text-gray-400">—</span>
                                            </td>
                                            <td class="py-3 px-6 text-sm font-bold text-gray-800 dark:text-gray-200 text-right">{{ formatMoney(item.total) }}</td>
                                            <td class="py-3 px-6 text-sm text-right">
                                                <button v-if="workOrder.status !== 'completed'" @click="deleteItem(item)" class="text-danger hover:text-danger-600 transition-colors p-1"><i class="ri-delete-bin-line"></i></button>
                                            </td>
                                        </tr>
                                    </template>
                                </draggable>
                            </table>
                        </div>
                        
                        <!-- Итоги -->
                        <div class="bg-gray-50/50 dark:bg-gray-800/30 p-6 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                            <div class="w-full sm:w-1/2 lg:w-1/3 space-y-3">
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">Сумма позиций:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ formatMoney(workOrder.total_amount) }}</span>
                                </div>
                                <div class="flex justify-between items-center text-sm group">
                                    <span class="text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                        Скидка:
                                        <button v-if="workOrder.status !== 'completed'" @click="openDiscountModal" class="text-primary hover:text-primary-600 opacity-0 group-hover:opacity-100 transition-opacity"><i class="ri-pencil-line"></i></button>
                                    </span>
                                    <span class="font-medium text-danger">- {{ formatMoney(workOrder.discount_amount) }} <span class="text-gray-400 font-normal">({{ orderDiscountPercent }}%)</span></span>
                                </div>
                                <div class="pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                                    <span class="font-bold text-gray-800 dark:text-gray-200 text-base">Итого к оплате:</span>
                                    <span class="text-xl font-bold text-success">{{ formatMoney(workOrder.final_amount) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="activeMainTab === 'history'" class="flex-1 p-6 flex flex-col items-center justify-center text-center">
                        <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4">
                            <i class="ri-history-line text-3xl text-gray-400 dark:text-gray-500"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-2">История действий</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-sm">
                            Здесь будет отображаться история изменения статусов и комментарии менеджеров (ожидает подключения пакета активности).
                        </p>
                    </div>
                </div>
            </div>

            <!-- Правая колонка: Финансы -->
            <div class="w-full lg:w-1/4 space-y-6 flex-shrink-0">
                
                <!-- Финансовый блок -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Финансы</h3>
                        <span :class="[paymentStatuses[workOrder.payment_status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold tracking-wide uppercase']">
                            {{ paymentStatuses[workOrder.payment_status]?.label || workOrder.payment_status }}
                        </span>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Сумма по прайсу:</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ formatMoney(workOrder.total_amount) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm group">
                            <span class="text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                Скидка:
                                <button v-if="workOrder.status !== 'completed'" @click="openDiscountModal" class="text-primary hover:text-primary-600 opacity-0 group-hover:opacity-100 transition-opacity"><i class="ri-pencil-line"></i></button>
                            </span>
                            <span class="font-medium text-danger">- {{ formatMoney(workOrder.discount_amount) }} <span class="text-gray-400 font-normal">({{ orderDiscountPercent }}%)</span></span>
                        </div>
                        <div class="pt-4 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                            <span class="font-bold text-gray-800 dark:text-gray-200">К оплате:</span>
                            <span class="text-xl font-bold text-success">{{ formatMoney(workOrder.final_amount) }}</span>
                        </div>
                        
                        <div class="pt-4 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                            <span class="text-sm text-gray-500 dark:text-gray-400">Оплачено:</span>
                            <span class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(workOrder.final_amount - remainingAmount) }}</span>
                        </div>

                        <button v-if="remainingAmount > 0" @click="openPaymentModal" class="w-full mt-4 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-semibold transition-all duration-300 bg-success text-white hover:bg-success-600 shadow-sm">
                            <i class="ri-bank-card-line mr-2"></i> Принять оплату
                        </button>
                    </div>
                </div>

                <!-- История транзакций -->
                <div v-if="paymentTransactions.length > 0" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">История оплат</h3>
                    </div>
                    <div class="p-6 space-y-3">
                        <Link
                            v-for="tx in paymentTransactions"
                            :key="tx.id"
                            :href="transactionLink(tx)"
                            class="flex justify-between items-start border-b border-gray-100 dark:border-gray-700/50 pb-3 last:border-0 last:pb-0 group hover:bg-gray-50/50 dark:hover:bg-gray-800/30 -mx-2 px-2 rounded transition-colors"
                        >
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 group-hover:text-primary transition-colors">{{ tx.account ? tx.account.name : 'Касса' }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ new Date(tx.created_at).toLocaleString('ru-RU', {day: 'numeric', month: 'short', hour: '2-digit', minute:'2-digit'}) }}</p>
                            </div>
                            <span class="text-sm font-bold text-success">+ {{ formatMoney(tx.amount) }}</span>
                        </Link>
                    </div>
                </div>

            </div>

        </div>

        <!-- TRI-STATE 1: Двухколоночный слайдер пакетного выбора (Drawer Offcanvas maxWidth="75vw") -->
        <Offcanvas :show="isBatchDrawerOpen" @close="isBatchDrawerOpen = false" maxWidth="75vw">
            <div class="flex flex-col h-full bg-white dark:bg-[#313a46]">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <div class="flex items-center gap-2.5">
                        <i class="ri-shopping-basket-2-line text-primary text-xl"></i>
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Пакетный набор услуг и товаров</h3>
                    </div>
                    <button @click="isBatchDrawerOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-2xl"></i></button>
                </div>

                <!-- Быстрый поиск и Фильтры -->
                <div class="p-4 border-b border-gray-100 dark:border-gray-700/80 bg-white dark:bg-gray-800 flex gap-2">
                    <div class="relative flex-1">
                        <input v-model="drawerSearch" type="text" placeholder="Поиск по названию услуги или товара..." class="block w-full pl-9 pr-4 py-1.5 border border-gray-200 dark:border-gray-700 rounded-md text-sm leading-5 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary transition-colors" />
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="ri-search-line text-gray-400"></i>
                        </div>
                    </div>
                    <div v-if="drawerTab === 'services'" class="w-48 shrink-0">
                        <select v-model="selectedDirectionId" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                            <option value="">Все направления</option>
                            <option v-for="dir in businessDirections" :key="dir.id" :value="dir.id">{{ dir.name }}</option>
                        </select>
                    </div>
                </div>

                <!-- Вкладки Услуги / Товары -->
                <div class="flex space-x-6 border-b border-gray-200 dark:border-gray-700 px-6 bg-gray-50/30 dark:bg-gray-800/10">
                    <button @click="drawerTab = 'services'" :class="[drawerTab === 'services' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3 px-1 text-sm transition-colors focus:outline-none']">
                        <i class="ri-tools-line mr-1"></i> Услуги (Прайс-лист)
                    </button>
                    <button @click="drawerTab = 'products'" :class="[drawerTab === 'products' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3 px-1 text-sm transition-colors focus:outline-none']">
                        <i class="ri-box-3-line mr-1"></i> Товары и Расходники
                    </button>
                </div>

                <!-- Toast уведомление прямо внутри слайдера при добавлении -->
                <div v-if="showToast" class="m-4 p-3 bg-success/10 border border-success/20 rounded text-success text-xs font-semibold flex items-center gap-1.5 animate-fade-in shadow-sm">
                    <i class="ri-checkbox-circle-fill"></i> {{ addedToastMessage }}
                </div>

                <!-- Двухколоночный сплит внутри слайдера -->
                <div class="flex-1 overflow-hidden grid grid-cols-12 gap-0">
                    
                    <!-- Левая часть (4 столбца / 1/3): Каталог с подсвечиванием добавленных -->
                    <div class="col-span-12 lg:col-span-4 border-r border-gray-200 dark:border-gray-700 overflow-y-auto p-4 space-y-5 custom-scrollbar">
                        
                        <!-- Рендеринг Услуг -->
                        <template v-if="drawerTab === 'services'">
                            <div v-for="group in groupedDrawerServices" :key="'g_srv_' + group.id" class="space-y-2">
                                <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2 flex items-center gap-1">
                                    <i class="ri-folder-open-line"></i> {{ group.name }}
                                </h4>
                                <div class="space-y-1.5">
                                    <div v-for="srv in group.items" :key="srv.id" class="flex justify-between items-center p-3 border border-gray-100 dark:border-gray-700/50 rounded-md bg-gray-50/50 dark:bg-gray-800/10 hover:border-primary/20 transition-all group">
                                        <div class="pr-2">
                                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(srv.name) }}</p>
                                            <p class="text-xs text-gray-500 font-medium mt-0.5 flex items-center gap-2">
                                                <span><i class="ri-time-line"></i> {{ srv.duration_minutes }} мин</span>
                                                <span>•</span>
                                                <span class="text-primary font-semibold">
                                                    <template v-if="pricingBasis === 'vehicle_body' && workOrder.vehicle?.vehicle_model?.body_type && srv.prices?.[workOrder.vehicle.vehicle_model.body_type]">
                                                        {{ formatMoney(srv.prices[workOrder.vehicle.vehicle_model.body_type]) }}
                                                    </template>
                                                    <template v-else-if="pricingBasis === 'vehicle_class' && workOrder.vehicle?.vehicle_model?.category && srv.prices?.[workOrder.vehicle.vehicle_model.category]">
                                                        {{ formatMoney(srv.prices[workOrder.vehicle.vehicle_model.category]) }}
                                                    </template>
                                                    <template v-else>
                                                        {{ formatMoney(srv.price) }}
                                                    </template>
                                                </span>
                                            </p>
                                        </div>
                                        <button 
                                            @click="addItemDirect('service', srv)" 
                                            :class="[
                                                getItemCountInOrder('service', srv.id) > 0 
                                                    ? 'bg-success text-white hover:bg-success-600' 
                                                    : 'bg-primary/10 text-primary hover:bg-primary hover:text-white',
                                                'inline-flex items-center justify-center rounded px-2.5 py-1.5 text-xs font-bold transition-all shadow-sm gap-1 shrink-0'
                                            ]"
                                        >
                                            <i class="ri-add-line"></i>
                                            <span v-if="getItemCountInOrder('service', srv.id) > 0">В заказе ({{ getItemCountInOrder('service', srv.id) }}) +</span>
                                            <span v-else>Добавить</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div v-if="groupedDrawerServices.length === 0" class="text-center py-12 text-sm text-gray-500">Услуги не найдены.</div>
                        </template>

                        <!-- Рендеринг Товаров -->
                        <template v-else>
                            <div v-for="group in groupedDrawerProducts" :key="'g_prd_' + group.id" class="space-y-2">
                                <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2 flex items-center gap-1">
                                    <i class="ri-folder-open-line"></i> {{ group.name }}
                                </h4>
                                <div class="space-y-1.5">
                                    <div v-for="prd in group.items" :key="prd.id" class="flex justify-between items-center p-3 border border-gray-100 dark:border-gray-700/50 rounded-md bg-gray-50/50 dark:bg-gray-800/10 hover:border-primary/20 transition-all group">
                                        <div class="pr-2">
                                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(prd.name) }}</p>
                                            <p class="text-xs text-gray-500 font-medium mt-0.5 flex items-center gap-1.5">
                                                <span class="font-mono">{{ prd.sku || 'Без артикула' }}</span>
                                                <span>•</span>
                                                <span>Ед: {{ prd.unit }}</span>
                                            </p>
                                        </div>
                                        <button 
                                            @click="addItemDirect('product', prd)" 
                                            :class="[
                                                getItemCountInOrder('product', prd.id) > 0 
                                                    ? 'bg-success text-white hover:bg-success-600' 
                                                    : 'bg-primary/10 text-primary hover:bg-primary hover:text-white',
                                                'inline-flex items-center justify-center rounded px-2.5 py-1.5 text-xs font-bold transition-all shadow-sm gap-1 shrink-0'
                                            ]"
                                        >
                                            <i class="ri-add-line"></i>
                                            <span v-if="getItemCountInOrder('product', prd.id) > 0">В заказе ({{ getItemCountInOrder('product', prd.id) }}) +</span>
                                            <span v-else>Добавить</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div v-if="groupedDrawerProducts.length === 0" class="text-center py-12 text-sm text-gray-500">Товары не найдены.</div>
                        </template>

                    </div>

                    <!-- Правая часть (8 столбцов / 2/3): Живая корзина состава заказа -->
                    <div class="col-span-12 lg:col-span-8 bg-gray-50/50 dark:bg-gray-800/30 flex flex-col h-full overflow-hidden">
                        <div class="p-3 border-b border-gray-200 dark:border-gray-700 bg-gray-100/50 dark:bg-gray-800/50 flex justify-between items-center">
                            <span class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Состав заказа ({{ workOrder.items?.length || 0 }})</span>
                            <span class="text-xs font-bold text-primary">{{ formatMoney(workOrder.final_amount) }}</span>
                        </div>

                        <!-- Список добавленных позиций с прямым редактированием -->
                        <div class="flex-1 overflow-y-auto p-3 space-y-2.5 custom-scrollbar">
                            <div v-for="item in workOrder.items" :key="item.id" class="p-3 bg-white dark:bg-[#313a46] border border-gray-200 dark:border-gray-700 rounded-md shadow-sm space-y-2.5">
                                <!-- Наименование, количество и сумма — в одну строку -->
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-gray-800 dark:text-gray-200 leading-tight truncate" :title="item.name">{{ item.name }}</p>
                                        <span v-if="item.itemable_type.includes('Service')" class="text-[11px] text-blue-600 font-semibold uppercase">Услуга</span>
                                        <span v-else class="text-[11px] text-orange-600 font-semibold uppercase">Товар</span>
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <input
                                            type="number"
                                            step="any"
                                            min="0.001"
                                            :value="parseFloat(item.quantity)"
                                            @change="e => updateItemDetails(item, { quantity: e.target.value })"
                                            title="Количество"
                                            class="w-14 rounded border-gray-200 dark:border-gray-700 bg-transparent py-1 px-1.5 text-sm font-bold text-gray-800 dark:text-gray-200 text-center focus:border-primary focus:ring-0"
                                        />
                                        <span class="text-gray-400">×</span>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            :value="item.price / 100"
                                            @change="e => updateItemDetails(item, { price: e.target.value })"
                                            title="Цена за ед."
                                            class="w-20 rounded border-gray-200 dark:border-gray-700 bg-transparent py-1 px-1.5 text-sm font-bold text-gray-800 dark:text-gray-200 text-right focus:border-primary focus:ring-0"
                                        />
                                        <span class="text-gray-400">=</span>
                                        <span class="text-sm font-bold text-primary w-20 text-right">{{ formatMoney(item.total) }}</span>
                                        <button @click="deleteItem(item)" class="text-danger hover:text-danger-600 p-1 shrink-0" title="Удалить"><i class="ri-delete-bin-line text-base"></i></button>
                                    </div>
                                </div>

                                <!-- Исполнители (можно несколько) и скидка на позицию -->
                                <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-gray-100 dark:border-gray-700/50">
                                    <EmployeeMultiSelect
                                        v-if="item.itemable_type.includes('Service')"
                                        :model-value="(item.employees || []).map(e => e.id)"
                                        :options="employees"
                                        @update:model-value="ids => updateItemEmployees(item, ids)"
                                    />

                                    <div class="flex items-center gap-1.5 ml-auto">
                                        <span class="text-[11px] text-gray-400 font-semibold">Скидка:</span>
                                        <div class="inline-flex rounded border border-gray-200 dark:border-gray-700 overflow-hidden shrink-0">
                                            <button type="button" @click="setItemDiscountMode(item, 'amount')" :class="[getItemDiscountMode(item) === 'amount' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-700', 'px-1.5 py-1 text-[11px] font-bold transition-colors']">₽</button>
                                            <button type="button" @click="setItemDiscountMode(item, 'percent')" :class="[getItemDiscountMode(item) === 'percent' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-700', 'px-1.5 py-1 text-[11px] font-bold transition-colors border-l border-gray-200 dark:border-gray-700']">%</button>
                                        </div>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            :max="getItemDiscountMode(item) === 'percent' ? 100 : itemBaseRub(item)"
                                            :value="itemDiscountDisplayValue(item)"
                                            @change="e => applyItemDiscount(item, e.target.value)"
                                            class="w-16 rounded border-gray-200 dark:border-gray-700 bg-transparent py-1 px-1.5 text-xs font-bold text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                                        />
                                    </div>
                                </div>
                            </div>

                            <div v-if="!workOrder.items || workOrder.items.length === 0" class="text-center py-8 text-xs text-gray-400">
                                Корзина заказа пуста.<br>Нажмите "+ Добавить" слева.
                            </div>
                        </div>

                        <!-- Итоговый расчет и скидка внизу корзины -->
                        <div class="p-4 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46] space-y-2">
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-gray-500">Сумма услуг:</span>
                                <span class="font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(workOrder.total_amount) }}</span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-gray-500">Скидка:</span>
                                <span class="font-bold text-danger">- {{ formatMoney(workOrder.discount_amount) }} <span class="text-gray-400 font-normal">({{ orderDiscountPercent }}%)</span></span>
                            </div>

                            <!-- Инлайн-редактирование скидки: сумма (₽) или процент (%) -->
                            <div class="flex items-center gap-1.5">
                                <div class="inline-flex rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden shrink-0">
                                    <button type="button" @click="setDrawerDiscountMode('amount')" :class="[drawerDiscountMode === 'amount' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-700', 'px-2 py-1 text-[11px] font-bold transition-colors']">₽</button>
                                    <button type="button" @click="setDrawerDiscountMode('percent')" :class="[drawerDiscountMode === 'percent' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-700', 'px-2 py-1 text-[11px] font-bold transition-colors border-l border-gray-200 dark:border-gray-700']">%</button>
                                </div>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :max="drawerDiscountMode === 'percent' ? 100 : drawerTotalRub"
                                    v-model="drawerDiscountValue"
                                    @keyup.enter="applyDrawerDiscount"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1 px-2 text-xs font-bold text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                                />
                                <button type="button" @click="applyDrawerDiscount" class="shrink-0 inline-flex items-center justify-center rounded px-2.5 py-1 text-[11px] font-bold bg-primary/10 text-primary hover:bg-primary hover:text-white transition-colors">Применить</button>
                            </div>

                            <div class="pt-2 border-t border-gray-100 dark:border-gray-700 flex justify-between items-center">
                                <span class="text-sm font-bold text-gray-800 dark:text-gray-200">Итого:</span>
                                <span class="text-lg font-bold text-success">{{ formatMoney(workOrder.final_amount) }}</span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Footer Drawer -->
                <div class="px-6 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/80 flex gap-2.5">
                    <button v-if="drawerTab === 'services'" @click="openQuickServiceModal" title="Добавить услугу, которой нет в каталоге" class="flex-1 inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold transition-all bg-primary text-white hover:bg-primary-600 gap-1"><i class="ri-add-line"></i> Своей услуги нет в списке</button>
                    <button v-else @click="openQuickProductModal" class="flex-1 inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold transition-all bg-primary text-white hover:bg-primary-600 gap-1"><i class="ri-add-line"></i> Быстрый товар</button>
                    <button @click="isBatchDrawerOpen = false" class="flex-1 inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold transition-all bg-white border border-gray-300 text-gray-700 hover:bg-gray-50">Сохранить и закрыть</button>
                </div>
            </div>
        </Offcanvas>

        <!-- Модальное окно редактирования шапки (Ширина 3xl - увеличено в 1.5 раза) -->
        <Teleport to="body">
            <div v-if="isModalOpen" class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-3xl my-8 mx-auto flex flex-col">
                    <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                            Редактирование шапки заказа
                        </h3>
                        <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                            <i class="ri-close-line text-xl"></i>
                        </button>
                    </div>
                    <form @submit.prevent="submit" class="flex flex-col">
                        <div class="p-6 space-y-4">
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Филиал <span class="text-danger">*</span></label>
                                    <select 
                                        v-model="form.branch_id" 
                                        required
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                    >
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Выберите филиал...</option>
                                        <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                                    </select>
                                    <span v-if="form.errors.branch_id" class="text-xs text-danger mt-1">{{ form.errors.branch_id }}</span>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус <span class="text-danger">*</span></label>
                                    <select 
                                        v-model="form.status" 
                                        required
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                    >
                                        <option v-for="(status, key) in statuses" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ status.label }}</option>
                                    </select>
                                    <span v-if="form.errors.status" class="text-xs text-danger mt-1">{{ form.errors.status }}</span>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Клиент <span class="text-danger">*</span></label>
                                    <select 
                                        v-model="form.client_id" 
                                        @change="form.vehicle_id = ''"
                                        required
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                    >
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Выберите клиента...</option>
                                        <option v-for="client in clients" :key="client.id" :value="client.id" class="bg-white dark:bg-gray-800">
                                            {{ client.name }} {{ client.phone ? `(${client.phone})` : '' }}
                                        </option>
                                    </select>
                                    <span v-if="form.errors.client_id" class="text-xs text-danger mt-1">{{ form.errors.client_id }}</span>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Автомобиль</label>
                                    <select 
                                        v-model="form.vehicle_id" 
                                        :disabled="!form.client_id"
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:bg-gray-100 dark:disabled:bg-gray-800 disabled:text-gray-400"
                                    >
                                        <option value="" class="bg-white dark:bg-gray-800">Без автомобиля</option>
                                        <option v-for="vehicle in vehicles.filter(v => v.client_id === form.client_id)" :key="vehicle.id" :value="vehicle.id" class="bg-white dark:bg-gray-800">
                                            {{ vehicle.make ? vehicle.make.name : '' }} {{ vehicle.vehicleModel ? vehicle.vehicleModel.name : '' }} {{ vehicle.plate_number ? `[${vehicle.plate_number}]` : '' }}
                                        </option>
                                    </select>
                                    <span v-if="form.errors.vehicle_id" class="text-xs text-danger mt-1">{{ form.errors.vehicle_id }}</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Пробег (км)</label>
                                <input 
                                    v-model="form.mileage" 
                                    type="number" 
                                    min="0"
                                    placeholder="Например: 150000" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>

                            <!-- Кастомные поля (EAV) -->
                            <div v-if="customFieldDefs.length > 0" class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-4 space-y-4">
                                <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-2">Дополнительные поля</h4>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div v-for="def in customFieldDefs" :key="def.id">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                            {{ getLocalizedLabel(def.label) }} <span v-if="def.is_required" class="text-danger">*</span>
                                        </label>
                                        
                                        <template v-if="def.type === 'text'">
                                            <input type="text" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                        </template>
                                        
                                        <template v-else-if="def.type === 'number'">
                                            <input type="number" step="any" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                        </template>
                                        
                                        <template v-else-if="def.type === 'date'">
                                            <input type="date" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                        </template>
                                        
                                        <template v-else-if="def.type === 'select'">
                                            <select v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                                <option value="" disabled class="bg-white dark:bg-gray-800">Выберите...</option>
                                                <option v-for="opt in def.options" :key="opt" :value="opt" class="bg-white dark:bg-gray-800">{{ opt }}</option>
                                            </select>
                                        </template>
                                        
                                        <template v-else-if="def.type === 'checkbox'">
                                            <div class="flex items-center pt-2">
                                                <div @click="form.custom_fields[def.key] = !form.custom_fields[def.key]" :class="[form.custom_fields[def.key] ? 'bg-primary' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                                    <div :class="[form.custom_fields[def.key] ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>

                        </div>
                        <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                            <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                            <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Модальное окно быстрого поиска одной позиции -->
        <Teleport to="body">
            <div v-if="isItemModalOpen" class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-xl my-8 mx-auto flex flex-col">
                    <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                            Добавление позиции
                        </h3>
                        <button @click="closeItemModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                            <i class="ri-close-line text-xl"></i>
                        </button>
                    </div>
                    <form @submit.prevent="submitItem" class="flex flex-col">
                        <div class="p-6 space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип <span class="text-danger">*</span></label>
                                    <select v-model="itemForm.itemable_type" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                        <option value="service" class="bg-white dark:bg-gray-800">Услуга</option>
                                        <option value="product" class="bg-white dark:bg-gray-800">Товар / Материал</option>
                                    </select>
                                </div>
                                <div>
                                    <div class="flex justify-between items-center mb-1.5">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Выбор из справочника <span class="text-danger">*</span></label>
                                        <button v-if="itemForm.itemable_type === 'service'" type="button" @click="openQuickServiceModal" title="Добавить услугу, которой нет в каталоге" class="text-xs text-primary hover:underline font-medium">+ Нет в списке?</button>
                                        <button v-if="itemForm.itemable_type === 'product'" type="button" @click="openQuickProductModal" class="text-xs text-primary hover:underline font-medium">+ Создать новый</button>
                                    </div>
                                    <select v-model="itemForm.itemable_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Выберите...</option>
                                        <template v-if="itemForm.itemable_type === 'service'">
                                            <option v-for="s in services" :key="s.id" :value="s.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(s.name) }}</option>
                                        </template>
                                        <template v-else>
                                            <option v-for="p in products" :key="p.id" :value="p.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(p.name) }}</option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Наименование в чеке <span class="text-danger">*</span></label>
                                <input v-model="itemForm.name" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Количество <span class="text-danger">*</span></label>
                                    <input v-model="itemForm.quantity" type="number" step="any" min="0.001" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цена за ед. (₽) <span class="text-danger">*</span></label>
                                    <input v-model="itemForm.price" type="number" step="0.01" min="0" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                </div>
                            </div>
                            
                            <div class="bg-gray-50 dark:bg-gray-800/50 p-3 rounded-md border border-gray-200 dark:border-gray-700 flex justify-between items-center mt-2">
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Итого по позиции:</span>
                                <span class="text-lg font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(itemForm.quantity * itemForm.price * 100) }}</span>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                            <button type="button" @click="closeItemModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                            <button type="submit" :disabled="itemForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Модальное окно быстрого создания Услуги (Ширина 3xl - увеличено в 1.5 раза) -->
        <Teleport to="body">
            <div v-if="isQuickServiceModalOpen" class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-3xl my-8 mx-auto flex flex-col animate-fade-in">
                    <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                            Добавить услугу, которой нет в каталоге
                        </h3>
                        <button @click="closeQuickServiceModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                    </div>
                    <form @submit.prevent="submitQuickService" class="flex flex-col">
                        <div class="p-6 space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название услуги <span class="text-danger">*</span></label>
                                <input v-model="quickServiceForm.name" type="text" required placeholder="Например: разовая работа по просьбе клиента" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цена (₽) <span class="text-danger">*</span></label>
                                <input v-model="quickServiceForm.price" type="number" step="0.01" min="0" required class="block w-full sm:w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>

                            <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                                <div @click="quickServiceForm.save_to_catalog = !quickServiceForm.save_to_catalog" :class="[quickServiceForm.save_to_catalog ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                    <div :class="[quickServiceForm.save_to_catalog ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="quickServiceForm.save_to_catalog = !quickServiceForm.save_to_catalog">
                                    Сохранить в каталог услуг на будущее
                                </label>
                            </div>
                            <p v-if="!quickServiceForm.save_to_catalog" class="text-xs text-gray-500">Позиция добавится только в этот заказ и не появится в общем прайс-листе.</p>

                            <div v-if="quickServiceForm.save_to_catalog" class="space-y-4 pt-2">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория</label>
                                        <select v-model="quickServiceForm.service_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                            <option value="" class="bg-white dark:bg-gray-800">Без категории</option>
                                            <option v-for="cat in serviceCategories" :key="cat.id" :value="cat.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(cat.name) }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Направление</label>
                                        <select v-model="quickServiceForm.business_direction_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                            <option value="" class="bg-white dark:bg-gray-800">Без направления</option>
                                            <option v-for="dir in businessDirections" :key="dir.id" :value="dir.id" class="bg-white dark:bg-gray-800">{{ dir.name }}</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Нормо-время (мин) <span class="text-danger">*</span></label>
                                    <input v-model="quickServiceForm.duration_minutes" type="number" min="0" :required="quickServiceForm.save_to_catalog" class="block w-full sm:w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                            <button type="button" @click="closeQuickServiceModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                            <button type="submit" :disabled="quickServiceForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить в заказ</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Модальное окно быстрого создания Товара (Ширина 3xl - увеличено в 1.5 раза) -->
        <Teleport to="body">
            <div v-if="isQuickProductModalOpen" class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-3xl my-8 mx-auto flex flex-col animate-fade-in">
                    <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                            Быстрое добавление товара в каталог
                        </h3>
                        <button @click="closeQuickProductModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none">
                            <i class="ri-close-line text-xl"></i>
                        </button>
                    </div>
                    <form @submit.prevent="submitQuickProduct" class="flex flex-col">
                        <div class="p-6 space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория</label>
                                <select v-model="quickProductForm.product_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" class="bg-white dark:bg-gray-800">Без категории</option>
                                    <option v-for="cat in productCategories" :key="cat.id" :value="cat.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(cat.name) }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название товара <span class="text-danger">*</span></label>
                                <input v-model="quickProductForm.name" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ед. изм. <span class="text-danger">*</span></label>
                                    <select v-model="quickProductForm.unit" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                        <option value="шт" class="bg-white dark:bg-gray-800">Штуки (шт)</option>
                                        <option value="мл" class="bg-white dark:bg-gray-800">Миллилитры (мл)</option>
                                        <option value="л" class="bg-white dark:bg-gray-800">Литры (л)</option>
                                        <option value="гр" class="bg-white dark:bg-gray-800">Граммы (гр)</option>
                                        <option value="кг" class="bg-white dark:bg-gray-800">Килограммы (кг)</option>
                                        <option value="пог.м" class="bg-white dark:bg-gray-800">Погонные метры (пог.м)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип учета <span class="text-danger">*</span></label>
                                    <select v-model="quickProductForm.accounting_type" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                        <option value="average" class="bg-white dark:bg-gray-800">Средневзвешенный</option>
                                        <option value="batch" class="bg-white dark:bg-gray-800">Партионный (FIFO)</option>
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
            </div>
        </Teleport>

        <!-- Модальное окно скидки -->
        <Teleport to="body">
            <div v-if="isDiscountModalOpen" class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                    <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                            Скидка на заказ
                        </h3>
                        <button @click="closeDiscountModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                            <i class="ri-close-line text-xl"></i>
                        </button>
                    </div>
                    <form @submit.prevent="submitDiscount" class="flex flex-col">
                        <div class="p-6 space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма скидки (₽) <span class="text-danger">*</span></label>
                                <input v-model="discountForm.discount_amount" type="number" step="0.01" min="0" :max="workOrder.total_amount / 100" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                <p class="text-xs text-gray-500 mt-1">Максимальная скидка: {{ formatMoney(workOrder.total_amount) }}</p>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                            <button type="button" @click="closeDiscountModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                            <button type="submit" :disabled="discountForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Применить</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Модальное окно оплаты -->
        <Teleport to="body">
            <div v-if="isPaymentModalOpen" class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                    <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                            Прием оплаты
                        </h3>
                        <button @click="closePaymentModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                            <i class="ri-close-line text-xl"></i>
                        </button>
                    </div>
                    <form @submit.prevent="submitPayment" class="flex flex-col">
                        <div class="p-6 space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Касса / Счет <span class="text-danger">*</span></label>
                                <select v-model="paymentForm.account_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите счет...</option>
                                    <option v-for="acc in accounts" :key="acc.id" :value="acc.id" class="bg-white dark:bg-gray-800">{{ acc.name }} — {{ accountTypeLabels[acc.type] || acc.type }}</option>
                                </select>
                                <span v-if="paymentForm.errors.account_id" class="text-xs text-danger mt-1">{{ paymentForm.errors.account_id }}</span>
                            </div>

                            <div v-if="selectedAccount?.type === 'bonus'" class="p-3 rounded-md bg-info/5 border border-info/20 text-xs text-gray-600 dark:text-gray-400">
                                Доступно бонусами: <span class="font-bold text-info">{{ formatMoney(clientBonusRub * 100) }}</span>
                                ({{ workOrder.client?.bonus_points || 0 }} баллов)
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма к оплате (₽) <span class="text-danger">*</span></label>
                                <input v-model="paymentForm.amount" type="number" step="0.01" min="0.01" :max="maxPayableRub" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <p class="text-xs text-gray-500 mt-1">Остаток долга: {{ formatMoney(remainingAmount) }}</p>
                            </div>

                            <div v-if="acquiringCommissionPreview" class="p-3 rounded-md bg-warning/5 border border-warning/20 text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <div class="flex justify-between"><span>Комиссия банка ({{ selectedAccount.commission_percent }}%):</span> <span class="font-bold text-warning">− {{ formatMoney(acquiringCommissionPreview.commission * 100) }}</span></div>
                                <div class="flex justify-between"><span>Зачислится на счет:</span> <span class="font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(acquiringCommissionPreview.net * 100) }}</span></div>
                                <p class="text-[11px] text-gray-400 pt-1">Заказ будет учтен как оплаченный на полную сумму — комиссия проводится отдельным расходом.</p>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                            <button type="button" @click="closePaymentModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                            <button type="submit" :disabled="paymentForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-success text-white hover:bg-success-600 disabled:opacity-50">Провести оплату</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

    </AuthenticatedLayout>
</template>