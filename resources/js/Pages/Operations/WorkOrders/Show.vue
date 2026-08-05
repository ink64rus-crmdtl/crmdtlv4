<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
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
});

const page = usePage();

const isModalOpen = ref(false);
const activeMainTab = ref('items'); // 'items', 'history'

const statuses = {
    'new': { label: 'Новый', class: 'bg-info/10 text-info' },
    'in_progress': { label: 'В работе', class: 'bg-warning/10 text-warning' },
    'ready': { label: 'Готов', class: 'bg-success/10 text-success' },
    'completed': { label: 'Выдан', class: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' },
    'canceled': { label: 'Отменен', class: 'bg-danger/10 text-danger' },
};

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

// Форма добавления позиции
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

// Автозаполнение названия и цены при выборе из справочника с учетом матрицы цен
watch(() => itemForm.itemable_id, (newId) => {
    if (!newId) return;
    if (itemForm.itemable_type === 'service') {
        const service = props.services.find(s => s.id === newId);
        if (service) {
            itemForm.name = getLocalizedLabel(service.name);
            
            let finalPrice = service.price; // Базовая цена в копейках
            
            // Проверяем динамическое ценообразование
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

// --- БЫСТРОЕ СОЗДАНИЕ УСЛУГИ / ТОВАРА ---
const isQuickServiceModalOpen = ref(false);
const quickServiceForm = useForm({
    service_category_id: '',
    business_direction_id: '',
    name: '',
    price: 0,
    duration_minutes: 60,
});

const openQuickServiceModal = () => {
    quickServiceForm.reset();
    isQuickServiceModalOpen.value = true;
};

const closeQuickServiceModal = () => {
    isQuickServiceModalOpen.value = false;
    quickServiceForm.reset();
    quickServiceForm.clearErrors();
};

const submitQuickService = () => {
    quickServiceForm.post(route('operations.work-orders.quick-service'), {
        onSuccess: () => {
            closeQuickServiceModal();
            // После успешного создания можно было бы автоматически выбрать новую услугу,
            // но для простоты просто закрываем модалку.
        },
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
// ----------------------------------------

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
                    <span class="font-semibold text-gray-800 dark:text-gray-200">Заказ #{{ String(workOrder.id).padStart(6, '0') }}</span>
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
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight mb-1">
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
                            <button v-if="workOrder.status !== 'completed'" @click="openItemModal" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 shadow-sm gap-1.5">
                                <i class="ri-add-line"></i> Добавить позицию
                            </button>
                        </div>
                        
                        <div class="overflow-x-auto w-full flex-1">
                            <table class="min-w-full text-left whitespace-nowrap">
                                <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                    <tr>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Наименование</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Тип</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Кол-во</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Цена</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Сумма</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right w-16"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="item in workOrder.items" :key="item.id" class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                        <td class="py-3 px-6 text-sm font-medium text-gray-800 dark:text-gray-200">{{ item.name }}</td>
                                        <td class="py-3 px-6 text-sm">
                                            <span v-if="item.itemable_type.includes('Service')" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400"><i class="ri-tools-line"></i> Услуга</span>
                                            <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400"><i class="ri-box-3-line"></i> Товар</span>
                                        </td>
                                        <td class="py-3 px-6 text-sm text-gray-800 dark:text-gray-200 text-right">{{ parseFloat(item.quantity) }}</td>
                                        <td class="py-3 px-6 text-sm text-gray-800 dark:text-gray-200 text-right">{{ formatMoney(item.price) }}</td>
                                        <td class="py-3 px-6 text-sm font-bold text-gray-800 dark:text-gray-200 text-right">{{ formatMoney(item.total) }}</td>
                                        <td class="py-3 px-6 text-sm text-right">
                                            <button v-if="workOrder.status !== 'completed'" @click="deleteItem(item)" class="text-danger hover:text-danger-600 transition-colors p-1"><i class="ri-delete-bin-line"></i></button>
                                        </td>
                                    </tr>
                                    <tr v-if="!workOrder.items || workOrder.items.length === 0">
                                        <td colspan="6" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                            В заказ-наряд еще не добавлены услуги или товары.
                                        </td>
                                    </tr>
                                </tbody>
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
                                    <span class="font-medium text-danger">- {{ formatMoney(workOrder.discount_amount) }}</span>
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
                            <span class="font-medium text-danger">- {{ formatMoney(workOrder.discount_amount) }}</span>
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
                <div v-if="workOrder.transactions && workOrder.transactions.length > 0" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">История оплат</h3>
                    </div>
                    <div class="p-6 space-y-3">
                        <div v-for="tx in workOrder.transactions" :key="tx.id" class="flex justify-between items-start border-b border-gray-100 dark:border-gray-700/50 pb-3 last:border-0 last:pb-0">
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ tx.account ? tx.account.name : 'Касса' }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ new Date(tx.created_at).toLocaleString('ru-RU', {day: 'numeric', month: 'short', hour: '2-digit', minute:'2-digit'}) }}</p>
                            </div>
                            <span class="text-sm font-bold text-success">+ {{ formatMoney(tx.amount) }}</span>
                        </div>
                    </div>
                </div>

            </div>

        </div>

        <!-- Модальное окно редактирования шапки -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl my-8 mx-auto flex flex-col">
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
                                        <select v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
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

        <!-- Модальное окно добавления позиции -->
        <div v-if="isItemModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
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
                                    <button v-if="itemForm.itemable_type === 'service'" type="button" @click="openQuickServiceModal" class="text-xs text-primary hover:underline font-medium">+ Создать новую</button>
                                    <button v-if="itemForm.itemable_type === 'product'" type="button" @click="openQuickProductModal" class="text-xs text-primary hover:underline font-medium">+ Создать новый</button>
                                </div>
                                <select v-model="itemForm.itemable_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
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
                            <input v-model="itemForm.name" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Количество <span class="text-danger">*</span></label>
                                <input v-model="itemForm.quantity" type="number" step="any" min="0.001" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цена за ед. (₽) <span class="text-danger">*</span></label>
                                <input v-model="itemForm.price" type="number" step="0.01" min="0" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
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

        <!-- Модальное окно быстрого создания Услуги -->
        <div v-if="isQuickServiceModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Быстрое добавление услуги
                    </h3>
                    <button @click="closeQuickServiceModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <form @submit.prevent="submitQuickService" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория</label>
                            <select v-model="quickServiceForm.service_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                <option value="" class="bg-white dark:bg-gray-800">Без категории</option>
                                <option v-for="cat in serviceCategories" :key="cat.id" :value="cat.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(cat.name) }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Направление</label>
                            <select v-model="quickServiceForm.business_direction_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                <option value="" class="bg-white dark:bg-gray-800">Без направления</option>
                                <option v-for="dir in businessDirections" :key="dir.id" :value="dir.id" class="bg-white dark:bg-gray-800">{{ dir.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название услуги <span class="text-danger">*</span></label>
                            <input v-model="quickServiceForm.name" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Базовая цена (₽) <span class="text-danger">*</span></label>
                                <input v-model="quickServiceForm.price" type="number" step="0.01" min="0" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Нормо-время (мин) <span class="text-danger">*</span></label>
                                <input v-model="quickServiceForm.duration_minutes" type="number" min="0" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeQuickServiceModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="quickServiceForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Модальное окно быстрого создания Товара -->
        <div v-if="isQuickProductModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Быстрое добавление товара
                    </h3>
                    <button @click="closeQuickProductModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <form @submit.prevent="submitQuickProduct" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория</label>
                            <select v-model="quickProductForm.product_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                <option value="" class="bg-white dark:bg-gray-800">Без категории</option>
                                <option v-for="cat in productCategories" :key="cat.id" :value="cat.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(cat.name) }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название товара <span class="text-danger">*</span></label>
                            <input v-model="quickProductForm.name" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ед. изм. <span class="text-danger">*</span></label>
                                <select v-model="quickProductForm.unit" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
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
                                <select v-model="quickProductForm.accounting_type" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
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

        <!-- Модальное окно скидки -->
        <div v-if="isDiscountModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
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

        <!-- Модальное окно оплаты -->
        <div v-if="isPaymentModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
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
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма к оплате (₽) <span class="text-danger">*</span></label>
                            <input v-model="paymentForm.amount" type="number" step="0.01" min="0.01" :max="remainingAmount / 100" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                            <p class="text-xs text-gray-500 mt-1">Остаток долга: {{ formatMoney(remainingAmount) }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Касса / Расчетный счет <span class="text-danger">*</span></label>
                            <select v-model="paymentForm.account_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                <option value="" disabled class="bg-white dark:bg-gray-800">Выберите счет...</option>
                                <option v-for="acc in accounts" :key="acc.id" :value="acc.id" class="bg-white dark:bg-gray-800">{{ acc.name }}</option>
                            </select>
                            <span v-if="paymentForm.errors.account_id" class="text-xs text-danger mt-1">{{ paymentForm.errors.account_id }}</span>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closePaymentModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="paymentForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-success text-white hover:bg-success-600 disabled:opacity-50">Провести оплату</button>
                    </div>
                </form>
            </div>
        </div>

    </AuthenticatedLayout>
</template>