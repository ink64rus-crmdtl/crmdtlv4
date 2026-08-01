<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    client: Object,
    customFieldsData: Array,
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
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex flex-col items-center text-center">
                    <div class="w-24 h-24 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-4xl mb-4">
                        {{ client.name.charAt(0) }}
                    </div>
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight mb-1">
                        {{ client.name }}
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mb-4">
                        <i :class="client.type === 'b2b' ? 'ri-building-line' : 'ri-user-line'"></i>
                        {{ client.type === 'b2b' ? 'Юридическое лицо' : 'Физическое лицо' }}
                    </p>
                    <div class="flex flex-wrap justify-center gap-2">
                        <span :class="[client.is_lead ? 'bg-warning/10 text-warning' : 'bg-success/10 text-success', 'inline-flex items-center gap-1.5 py-1 px-3 rounded-full text-xs font-bold tracking-wide uppercase']">
                            {{ client.is_lead ? 'Лид' : 'Постоянный клиент' }}
                        </span>
                        <span v-if="client.discount_percent > 0" class="inline-flex items-center gap-1.5 py-1 px-3 rounded-full text-xs font-bold tracking-wide uppercase bg-info/10 text-info">
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
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Телефон</p>
                            <div class="flex items-center justify-between group">
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ client.phone || '—' }}</p>
                                <a v-if="client.phone" :href="'tel:' + client.phone" class="text-gray-400 hover:text-primary opacity-0 group-hover:opacity-100 transition-opacity">
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
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Филиал регистрации</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                <i class="ri-store-2-line text-primary"></i> {{ client.branch ? client.branch.name : '—' }}
                            </p>
                        </div>
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

            <!-- Центральная колонка: Таймлайн (Activity) -->
            <div class="w-full lg:w-2/4 space-y-6">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col h-full min-h-[500px]">
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