<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    client: Object,
    customFieldsData: Array,
    tenantCountry: String,
    countryConfig: Object,
});

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
            <div class="flex items-center gap-2">
                <Link :href="route('crm.clients.index')" class="text-gray-500 hover:text-primary transition-colors">
                    <i class="ri-arrow-left-line"></i> Клиенты
                </Link>
                <span class="text-gray-400">/</span>
                <span class="font-semibold text-gray-800 dark:text-gray-200">{{ client.name }}</span>
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
                            <div v-for="vehicle in client.vehicles" :key="vehicle.id" class="p-3 border border-gray-100 dark:border-gray-700/50 rounded-md bg-gray-50/50 dark:bg-gray-800/30 hover:border-primary/30 transition-colors cursor-pointer group">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-gray-500 dark:text-gray-400 group-hover:text-primary transition-colors shrink-0">
                                        <i class="ri-car-line text-xl"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-gray-800 dark:text-gray-200 group-hover:text-primary transition-colors">{{ vehicle.make }} {{ vehicle.model }}</p>
                                        <p class="text-xs text-gray-500 font-medium mt-0.5">{{ vehicle.plate_number || 'Госномер не указан' }}</p>
                                    </div>
                                </div>
                            </div>
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
    </AuthenticatedLayout>
</template>