<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    employee: Object,
    resolvedScopes: Object,
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

const employeeTypes = {
    'staff': 'В штате',
    'self_employed': 'Самозанятый',
    'outsource': 'Аутсорс / Подрядчик'
};
</script>

<template>
    <Head :title="`Сотрудник: ${employee.last_name} ${employee.first_name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-2">
                <Link :href="route('hr.employees.index')" class="text-gray-500 hover:text-primary transition-colors">
                    <i class="ri-arrow-left-line"></i> Сотрудники
                </Link>
                <span class="text-gray-400">/</span>
                <span>{{ employee.last_name }} {{ employee.first_name }} {{ employee.middle_name || '' }}</span>
            </div>
        </template>

        <!-- TRI-STATE 2: Полная карточка (w-[99%] mx-auto для Fluid-дизайна) -->
        <div class="w-[99%] mx-auto flex flex-col lg:flex-row gap-6 font-sans text-slate-600">
            
            <!-- Левая колонка: About (Свойства сущности) -->
            <div class="w-full lg:w-1/4 space-y-6 flex-shrink-0">
                
                <!-- Аватар и статус -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex flex-col items-center text-center">
                    <div class="w-24 h-24 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold text-4xl mb-4">
                        {{ employee.first_name.charAt(0) }}
                    </div>
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight mb-1">
                        {{ employee.last_name }} {{ employee.first_name }}
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mb-4">
                        {{ employee.position ? getLocalizedLabel(employee.position.name) : 'Без должности' }}
                    </p>
                    <span :class="[employee.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-1 px-3 rounded-full text-xs font-bold tracking-wide uppercase']">
                        {{ employee.is_active ? 'Активен' : 'Уволен' }}
                    </span>
                </div>

                <!-- Основная информация -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Основная информация</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Телефон</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ employee.phone || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Личный Email</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ employee.personal_email || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Дата рождения</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ employee.birth_date || '—' }}</p>
                        </div>
                    </div>
                </div>

                <!-- Рабочая информация -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Работа</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Основной филиал</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                <i class="ri-store-2-line text-primary mr-1"></i> {{ employee.branch ? employee.branch.name : '—' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Тип оформления</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ employeeTypes[employee.type] }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Дата приема</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ employee.hire_date || '—' }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Дата увольнения</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ employee.termination_date || '—' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Паспортные данные -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80" v-if="employee.passport_data">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Документы</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Паспорт</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ employee.passport_data.series }} {{ employee.passport_data.number }}</p>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Код</p>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ employee.passport_data.department_code || '—' }}</p>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Кем выдан</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ employee.passport_data.issued_by || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Адрес регистрации</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ employee.passport_data.registration_address || '—' }}</p>
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
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-2">История действий</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-sm">
                            Здесь будет отображаться история изменения статусов, начисление зарплаты и связанные заказ-наряды (ожидает подключения пакета активности).
                        </p>
                    </div>
                </div>
            </div>

            <!-- Правая колонка: Связи (Associations / Scopes) -->
            <div class="w-full lg:w-1/4 space-y-6 flex-shrink-0">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Системный доступ</h3>
                    </div>
                    <div class="p-6">
                        <div v-if="employee.user_id" class="space-y-6">
                            <!-- Статус аккаунта -->
                            <div>
                                <span class="inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium bg-success/10 text-success mb-2">
                                    <i class="ri-shield-keyhole-line"></i> Аккаунт активен
                                </span>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ employee.user?.email }}</p>
                                <p class="text-xs text-gray-500 mt-1">Роль: {{ employee.user?.roles && employee.user.roles.length > 0 ? employee.user.roles[0].name : 'Нет роли' }}</p>
                            </div>

                            <!-- Списки доступов (ABAC) -->
                            <div class="space-y-4 pt-4 border-t border-gray-100 dark:border-gray-700/50">
                                
                                <div v-if="resolvedScopes.branches && resolvedScopes.branches.length > 0">
                                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Доступ к филиалам</p>
                                    <div class="flex flex-wrap gap-2">
                                        <span v-for="b in resolvedScopes.branches" :key="b.id" class="px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded text-xs">
                                            {{ b.name }}
                                        </span>
                                    </div>
                                </div>

                                <div v-if="resolvedScopes.legal_entities && resolvedScopes.legal_entities.length > 0">
                                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Доступ к Юрлицам</p>
                                    <div class="flex flex-wrap gap-2">
                                        <span v-for="l in resolvedScopes.legal_entities" :key="l.id" class="px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded text-xs">
                                            {{ l.name }}
                                        </span>
                                    </div>
                                </div>

                                <div v-if="resolvedScopes.warehouses && resolvedScopes.warehouses.length > 0">
                                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Доступ к Складам</p>
                                    <div class="flex flex-wrap gap-2">
                                        <span v-for="w in resolvedScopes.warehouses" :key="w.id" class="px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded text-xs">
                                            {{ w.name }}
                                        </span>
                                    </div>
                                </div>

                            </div>
                        </div>
                        <div v-else class="text-center py-4">
                            <i class="ri-shield-keyhole-line text-3xl text-gray-300 dark:text-gray-600 mb-2 block"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Доступ в систему не предоставлялся</p>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AuthenticatedLayout>
</template>