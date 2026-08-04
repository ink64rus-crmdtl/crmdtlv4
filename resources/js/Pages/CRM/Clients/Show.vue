<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CreatableSelect from '@/Components/CreatableSelect.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    client: Object,
    customFieldsData: Array,
    tenantCountry: String,
    countryConfig: Object,
    branches: Array,
    clientGroups: Array,
    lookups: Object,
    customFieldDefs: Array,
});

const isClientModalOpen = ref(false);
const activeClientTab = ref('main');

const clientForm = useForm({
    branch_id: '',
    client_group_id: '',
    is_lead: false,
    type: 'b2c',
    role: '',
    name: '',
    alias: '',
    phone: '',
    phone_2: '',
    email: '',
    source: '',
    birth_date: '',
    comment: '',
    discount_percent: 0,
    requisites: {},
    custom_fields: {},
});

const openClientModal = () => {
    const client = props.client;
    clientForm.branch_id = client.branch_id;
    clientForm.client_group_id = client.client_group_id || '';
    clientForm.is_lead = Boolean(client.is_lead);
    clientForm.type = client.type;
    clientForm.role = client.role || '';
    clientForm.name = client.name;
    clientForm.alias = client.alias || '';
    clientForm.phone = client.phone || '';
    clientForm.phone_2 = client.phone_2 || '';
    clientForm.email = client.email || '';
    clientForm.source = client.source || '';
    clientForm.birth_date = client.birth_date ? client.birth_date.substring(0, 10) : '';
    clientForm.comment = client.comment || '';
    clientForm.discount_percent = client.discount_percent || 0;
    clientForm.requisites = client.requisites || {};
    
    const cf = {};
    props.customFieldDefs.forEach(def => {
        const existingVal = props.customFieldsData.find(d => d.definition.id === def.id)?.value;
        cf[def.key] = existingVal !== undefined && existingVal !== null 
            ? existingVal 
            : (def.type === 'checkbox' ? false : '');
    });
    clientForm.custom_fields = cf;

    activeClientTab.value = 'main';
    isClientModalOpen.value = true;
};

const closeClientModal = () => {
    isClientModalOpen.value = false;
    clientForm.reset();
    clientForm.clearErrors();
};

const submitClient = () => {
    clientForm.put(route('crm.clients.update', props.client.id), {
        onSuccess: () => closeClientModal(),
    });
};

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

const currentCountrySchema = computed(() => {
    return props.countryConfig?.requisite_schema || [];
});
</script>

<template>
    <Head :title="`Клиент: ${client.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between w-full">
                <div class="flex items-center gap-2">
                    <Link :href="route('crm.clients.index')" class="text-gray-500 hover:text-primary transition-colors">
                        <i class="ri-arrow-left-line"></i> Клиенты
                    </Link>
                    <span class="text-gray-400">/</span>
                    <span class="font-semibold text-gray-800 dark:text-gray-200">{{ client.name }}</span>
                </div>
                <button @click="openClientModal" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">
                    <i class="ri-pencil-line mr-1.5"></i> Редактировать
                </button>
            </div>
        </template>

        <!-- TRI-STATE 2: Полная карточка (w-[99%] mx-auto для Fluid-дизайна) -->
        <div class="w-[99%] mx-auto flex flex-col lg:flex-row gap-6 font-sans text-slate-600">
            
            <!-- Левая колонка: About (Свойства сущности) -->
            <div class="w-full lg:w-1/4 space-y-6 flex-shrink-0">
                
                <!-- Аватар и статус -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex flex-col items-center text-center relative overflow-hidden">
                    <!-- Декоративный фон для группы -->
                    <div v-if="client.group" :class="[`bg-${client.group.color}-500`, 'absolute top-0 left-0 w-full h-2 opacity-20']"></div>
                    
                    <div class="w-24 h-24 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-4xl mb-4 mt-2">
                        {{ client.name.charAt(0) }}
                    </div>
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight mb-1 flex items-center justify-center gap-2">
                        {{ client.name }}
                    </h2>
                    <p v-if="client.alias" class="text-sm text-gray-500 dark:text-gray-400 font-medium mb-3">
                        «{{ client.alias }}»
                    </p>
                    
                    <div class="flex flex-wrap justify-center gap-2 mb-4">
                        <span class="inline-flex items-center gap-1.5 py-1 px-3 rounded-full text-[10px] font-bold tracking-wide uppercase bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                            <i :class="client.type === 'b2b' ? 'ri-building-line' : 'ri-user-line'"></i>
                            {{ client.type === 'b2b' ? 'Юрлицо' : 'Физлицо' }}
                        </span>
                        <span v-if="client.group" :class="[`bg-${client.group.color}-100 text-${client.group.color}-700 dark:bg-${client.group.color}-900/30 dark:text-${client.group.color}-400`, 'inline-flex items-center gap-1.5 py-1 px-3 rounded-full text-[10px] font-bold tracking-wide uppercase']">
                            {{ client.group.name }}
                        </span>
                    </div>

                    <div class="flex flex-wrap justify-center gap-2 w-full pt-4 border-t border-gray-100 dark:border-gray-700/50">
                        <span :class="[client.is_lead ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success', 'inline-flex items-center gap-1.5 py-1 px-3 rounded-md text-xs font-bold tracking-wide uppercase']">
                            {{ client.is_lead ? 'Лид' : 'Постоянный клиент' }}
                        </span>
                        <span v-if="client.discount_percent > 0" class="inline-flex items-center gap-1.5 py-1 px-3 rounded-md text-xs font-bold tracking-wide uppercase bg-info/10 text-info">
                            <i class="ri-percent-line"></i> Скидка {{ client.discount_percent }}%
                        </span>
                    </div>
                </div>

                <!-- Основная информация -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Контакты</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Основной телефон</p>
                            <div class="flex items-center justify-between group">
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ client.phone || '—' }}</p>
                                <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <a v-if="client.phone" :href="'https://wa.me/' + client.phone.replace(/[^0-9]/g, '')" target="_blank" class="w-7 h-7 rounded bg-green-100 text-green-600 flex items-center justify-center hover:bg-green-200 transition-colors" title="WhatsApp">
                                        <i class="ri-whatsapp-line"></i>
                                    </a>
                                    <a v-if="client.phone" :href="'tel:' + client.phone" class="w-7 h-7 rounded bg-primary/10 text-primary flex items-center justify-center hover:bg-primary/20 transition-colors" title="Позвонить">
                                        <i class="ri-phone-line"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div v-if="client.phone_2">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Доп. телефон</p>
                            <div class="flex items-center justify-between group">
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ client.phone_2 }}</p>
                                <a :href="'tel:' + client.phone_2" class="text-gray-400 hover:text-primary opacity-0 group-hover:opacity-100 transition-opacity">
                                    <i class="ri-phone-line"></i>
                                </a>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Email</p>
                            <div class="flex items-center justify-between group">
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ client.email || '—' }}</p>
                                <a v-if="client.email" :href="'mailto:' + client.email" class="text-gray-400 hover:text-primary opacity-0 group-hover:opacity-100 transition-opacity">
                                    <i class="ri-mail-line"></i>
                                </a>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4 pt-2">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Источник</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ client.source || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Филиал</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 flex items-center gap-1">
                                    <i class="ri-store-2-line text-gray-400"></i> {{ client.branch ? client.branch.name : '—' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Реквизиты -->
                <div v-if="client.requisites && Object.keys(client.requisites).length > 0" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Реквизиты</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <template v-if="client.type === 'b2c'">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Паспорт</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ client.requisites.passport_series }} {{ client.requisites.passport_number }}</p>
                                </div>
                                <div>
                                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Код</p>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ client.requisites.passport_code || '—' }}</p>
                                </div>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Кем выдан</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ client.requisites.passport_issued_by || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Адрес регистрации</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ client.requisites.passport_address || '—' }}</p>
                            </div>
                        </template>
                        <template v-else>
                            <div v-for="field in currentCountrySchema" :key="field.key">
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ field.label }}</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ client.requisites[field.key] || '—' }}</p>
                            </div>
                        </template>
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

            <!-- Центральная колонка: KPI и Таймлайн (Activity) -->
            <div class="w-full lg:w-2/4 space-y-6">
                
                <!-- KPI Dashboard -->
                <div class="grid grid-cols-2 xl:grid-cols-3 gap-4">
                    <!-- LTV -->
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-4 flex items-center gap-4">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg shrink-0 dark:bg-emerald-900/30 dark:text-emerald-400">
                            <i class="ri-vip-diamond-line"></i>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">LTV (Прибыль)</p>
                            <p class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight">0 ₽</p>
                        </div>
                    </div>

                    <!-- Оборот -->
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-4 flex items-center gap-4">
                        <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-lg shrink-0 dark:bg-blue-900/30 dark:text-blue-400">
                            <i class="ri-shopping-cart-2-line"></i>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Оборот</p>
                            <p class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight">0 ₽</p>
                        </div>
                    </div>

                    <!-- Баланс -->
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-4 flex items-center gap-4" title="Положительный баланс - это свободный депозит. Отрицательный - это долг клиента.">
                        <div class="w-10 h-10 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-lg shrink-0 dark:bg-purple-900/30 dark:text-purple-400">
                            <i class="ri-wallet-3-line"></i>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Баланс</p>
                            <p :class="[client.balance < 0 ? 'text-danger' : 'text-success', 'text-lg font-bold leading-tight']">
                                {{ formatMoney(client.balance) }}
                            </p>
                        </div>
                    </div>

                    <!-- Бонусы -->
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-4 flex items-center gap-4">
                        <div class="w-10 h-10 rounded-full bg-orange-100 text-orange-500 flex items-center justify-center text-lg shrink-0 dark:bg-orange-900/30 dark:text-orange-400">
                            <i class="ri-star-smile-line"></i>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Бонусы</p>
                            <p class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight">{{ client.bonus_points }}</p>
                        </div>
                    </div>

                    <!-- Надежность -->
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-4 flex items-center gap-4" title="Расчет: Принято / (Принято + Штрафные баллы)">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg shrink-0 dark:bg-emerald-900/30 dark:text-emerald-400">
                            <i class="ri-shield-check-line"></i>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Надежность</p>
                            <p class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight">100%</p>
                        </div>
                    </div>
                </div>

                <!-- Комментарий менеджера (если есть) -->
                <div v-if="client.comment" class="bg-warning/10 border border-warning/20 rounded-md p-5 flex gap-4 items-start">
                    <i class="ri-pushpin-2-fill text-warning text-2xl shrink-0"></i>
                    <div>
                        <h4 class="text-sm font-bold text-warning uppercase tracking-wider mb-1">Заметка менеджера</h4>
                        <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap leading-relaxed">{{ client.comment }}</p>
                    </div>
                </div>

                <!-- Таймлайн -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col h-full min-h-[400px]">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Лента активности</h3>
                    </div>
                    <div class="flex-1 p-6 flex flex-col items-center justify-center text-center">
                        <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4">
                            <i class="ri-history-line text-3xl text-gray-400 dark:text-gray-500"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-2">История взаимодействия</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-sm">
                            Здесь будет отображаться история звонков, сообщений в мессенджерах, изменения статусов и комментарии менеджеров (ожидает подключения пакета активности).
                        </p>
                    </div>
                </div>
            </div>

            <!-- Правая колонка: Связи (Associations) -->
            <div class="w-full lg:w-1/4 space-y-6 flex-shrink-0">
                
                <!-- Автомобили клиента -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Автомобили ({{ client.vehicles?.length || 0 }})</h3>
                        <button class="text-primary hover:text-primary-600 transition-colors text-sm font-medium flex items-center gap-1">
                            <i class="ri-add-line"></i> Добавить
                        </button>
                    </div>
                    <div class="p-6">
                        <div v-if="client.vehicles && client.vehicles.length > 0" class="space-y-3">
                            <Link :href="route('crm.vehicles.show', vehicle.id)" v-for="vehicle in client.vehicles" :key="vehicle.id" class="block p-3 border border-gray-100 dark:border-gray-700/50 rounded-md bg-gray-50/50 dark:bg-gray-800/30 hover:border-primary/30 transition-colors cursor-pointer group">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-gray-500 dark:text-gray-400 group-hover:text-primary transition-colors shrink-0">
                                        <i class="ri-car-line text-xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-gray-800 dark:text-gray-200 group-hover:text-primary transition-colors">{{ vehicle.make ? vehicle.make.name : '' }} {{ vehicle.vehicle_model ? vehicle.vehicle_model.name : '' }}</p>
                                        <p class="text-xs text-gray-500 font-medium mt-0.5">{{ vehicle.plate_number || 'Госномер не указан' }}</p>
                                    </div>
                                </div>
                            </Link>
                        </div>
                        <div v-else class="text-center py-6">
                            <i class="ri-car-line text-3xl text-gray-300 dark:text-gray-600 mb-2 block"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400">У клиента пока нет добавленных автомобилей</p>
                        </div>
                    </div>
                </div>

                <!-- Заказ-наряды (Заглушка) -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Заказ-наряды (0)</h3>
                        <button class="text-primary hover:text-primary-600 transition-colors text-sm font-medium flex items-center gap-1">
                            <i class="ri-add-line"></i> Создать
                        </button>
                    </div>
                    <div class="p-6 text-center py-8">
                        <i class="ri-briefcase-line text-3xl text-gray-300 dark:text-gray-600 mb-2 block"></i>
                        <p class="text-sm text-gray-500 dark:text-gray-400">История заказов пуста</p>
                    </div>
                </div>

            </div>

        </div>

        <!-- Модальное окно редактирования (Focused Modal) -->
        <div v-if="isClientModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-3xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Редактирование клиента: {{ clientForm.name }}
                    </h3>
                    <button @click="closeClientModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <!-- Вкладки внутри модалки -->
                <div class="flex overflow-x-auto border-b border-gray-200 dark:border-gray-700 px-6 bg-white dark:bg-[#313a46] custom-scrollbar">
                    <button
                        type="button"
                        @click="activeClientTab = 'main'"
                        :class="[activeClientTab === 'main' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-user-line"></i> Основные данные
                    </button>
                    <button
                        type="button"
                        @click="activeClientTab = 'contacts'"
                        :class="[activeClientTab === 'contacts' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-contacts-book-2-line"></i> Контакты
                    </button>
                    <button
                        type="button"
                        @click="activeClientTab = 'requisites'"
                        :class="[activeClientTab === 'requisites' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-file-list-3-line"></i> Реквизиты
                    </button>
                    <button
                        type="button"
                        @click="activeClientTab = 'settings'"
                        :class="[activeClientTab === 'settings' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-settings-3-line"></i> Настройки и Поля
                    </button>
                </div>

                <form @submit.prevent="submitClient" class="flex flex-col">
                    
                    <!-- Вкладка 1: Основные данные -->
                    <div v-show="activeClientTab === 'main'" class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип клиента <span class="text-danger">*</span></label>
                                <select 
                                    v-model="clientForm.type" 
                                    required
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="b2c" class="bg-white dark:bg-gray-800">Физическое лицо</option>
                                    <option value="b2b" class="bg-white dark:bg-gray-800">Юридическое лицо (B2B)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Роль клиента</label>
                                <CreatableSelect 
                                    v-model="clientForm.role" 
                                    :options="lookups.client_role?.map(l => l.value) || []" 
                                    lookupType="client_role" 
                                    placeholder="Выберите роль..." 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Группа</label>
                                <select 
                                    v-model="clientForm.client_group_id" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" class="bg-white dark:bg-gray-800">Без группы</option>
                                    <option v-for="group in clientGroups" :key="group.id" :value="group.id" class="bg-white dark:bg-gray-800">{{ group.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Имя / Название <span class="text-danger">*</span></label>
                                <input 
                                    v-model="clientForm.name" 
                                    type="text" 
                                    required 
                                    placeholder="Иван Иванов" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                                <span v-if="clientForm.errors.name" class="text-xs text-danger mt-1">{{ clientForm.errors.name }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Псевдоним (Кратко)</label>
                                <input 
                                    v-model="clientForm.alias" 
                                    type="text" 
                                    placeholder="Иван BMW" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата рождения / основания</label>
                            <input 
                                v-model="clientForm.birth_date" 
                                type="date" 
                                class="block w-full sm:w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                            />
                        </div>
                    </div>

                    <!-- Вкладка 2: Контакты -->
                    <div v-show="activeClientTab === 'contacts'" class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Основной телефон</label>
                                <input 
                                    v-model="clientForm.phone" 
                                    type="text" 
                                    placeholder="+7 (999) 000-00-00" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дополнительный телефон</label>
                                <input 
                                    v-model="clientForm.phone_2" 
                                    type="text" 
                                    placeholder="+7 (999) 111-11-11" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email</label>
                                <input 
                                    v-model="clientForm.email" 
                                    type="email" 
                                    placeholder="client@mail.ru" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Источник привлечения</label>
                                <CreatableSelect 
                                    v-model="clientForm.source" 
                                    :options="lookups.client_source?.map(l => l.value) || []" 
                                    lookupType="client_source" 
                                    placeholder="Авито, 2GIS, Рекомендация..." 
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Филиал регистрации <span class="text-danger">*</span></label>
                            <select 
                                v-model="clientForm.branch_id" 
                                required
                                class="block w-full sm:w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                            >
                                <option value="" disabled class="bg-white dark:bg-gray-800">Выберите филиал...</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Вкладка 3: Реквизиты -->
                    <div v-show="activeClientTab === 'requisites'" class="p-6 space-y-5">
                        
                        <!-- Для B2C (Физлицо) - Паспорт -->
                        <template v-if="clientForm.type === 'b2c'">
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Паспортные данные</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Серия</label>
                                    <input v-model="clientForm.requisites.passport_series" type="text" placeholder="1234" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Номер</label>
                                    <input v-model="clientForm.requisites.passport_number" type="text" placeholder="567890" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Код подразделения</label>
                                    <input v-model="clientForm.requisites.passport_code" type="text" placeholder="123-456" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Кем выдан</label>
                                    <input v-model="clientForm.requisites.passport_issued_by" type="text" placeholder="ГУ МВД России..." class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата выдачи</label>
                                    <input v-model="clientForm.requisites.passport_date" type="date" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Адрес регистрации (прописка)</label>
                                    <textarea v-model="clientForm.requisites.passport_address" rows="2" placeholder="г. Москва, ул. Ленина..." class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"></textarea>
                                </div>
                            </div>
                        </template>

                        <!-- Для B2B (Юрлицо) - Динамическая схема -->
                        <template v-else>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Реквизиты компании</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div v-for="field in currentCountrySchema" :key="field.key" :class="field.type === 'textarea' ? 'sm:col-span-2' : ''">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                        {{ field.label }}
                                    </label>
                                    <textarea
                                        v-if="field.type === 'textarea'"
                                        v-model="clientForm.requisites[field.key]"
                                        :placeholder="field.placeholder"
                                        rows="2"
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                    ></textarea>
                                    <input
                                        v-else
                                        v-model="clientForm.requisites[field.key]"
                                        :type="field.type"
                                        :placeholder="field.placeholder"
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                    />
                                </div>
                            </div>
                        </template>
                    </div>

                    <!-- Вкладка 4: Настройки и Поля -->
                    <div v-show="activeClientTab === 'settings'" class="p-6 space-y-5">
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Персональная скидка (%)</label>
                                <input 
                                    v-model="clientForm.discount_percent" 
                                    type="number" 
                                    min="0" max="100"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Начальный баланс (₽)</label>
                                <input 
                                    v-model="clientForm.balance" 
                                    type="number" 
                                    step="0.01"
                                    placeholder="0.00"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                                <p class="text-xs text-gray-500 mt-1">Отрицательный — долг, положительный — депозит.</p>
                            </div>
                        </div>

                        <div class="flex items-center pt-2">
                            <div @click="clientForm.is_lead = !clientForm.is_lead" :class="[clientForm.is_lead ? 'bg-warning' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[clientForm.is_lead ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="clientForm.is_lead = !clientForm.is_lead">
                                Это Лид (потенциальный клиент)
                            </label>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Комментарий менеджера</label>
                            <textarea 
                                v-model="clientForm.comment" 
                                rows="3"
                                placeholder="Особые приметы, договоренности..." 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                            ></textarea>
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
                                        <input type="text" v-model="clientForm.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                    </template>
                                    
                                    <template v-else-if="def.type === 'number'">
                                        <input type="number" step="any" v-model="clientForm.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                    </template>
                                    
                                    <template v-else-if="def.type === 'date'">
                                        <input type="date" v-model="clientForm.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                    </template>
                                    
                                    <template v-else-if="def.type === 'select'">
                                        <select v-model="clientForm.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                            <option value="" disabled class="bg-white dark:bg-gray-800">Выберите...</option>
                                            <option v-for="opt in def.options" :key="opt" :value="opt" class="bg-white dark:bg-gray-800">{{ opt }}</option>
                                        </select>
                                    </template>
                                    
                                    <template v-else-if="def.type === 'checkbox'">
                                        <div class="flex items-center pt-2">
                                            <div @click="clientForm.custom_fields[def.key] = !clientForm.custom_fields[def.key]" :class="[clientForm.custom_fields[def.key] ? 'bg-primary' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                                <div :class="[clientForm.custom_fields[def.key] ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeClientModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                            Отмена
                        </button>
                        <button type="submit" :disabled="clientForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            Сохранить
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </AuthenticatedLayout>
</template>