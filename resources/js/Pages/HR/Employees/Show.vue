<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CollapsiblePanel from '@/Components/CollapsiblePanel.vue';
import CalendarColorPicker from '@/Components/CalendarColorPicker.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    employee: Object,
    resolvedScopes: Object,
    branches: Array,
    positions: Array,
    roles: Array,
    scopes: Object,
    userScopes: Object,
    tenantCountry: String,
    customFieldDefs: Array,
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

const isModalOpen = ref(false);
const activeTab = ref('main'); // 'main', 'work', 'crm', 'scopes', 'documents', 'payroll'

const needsMiddleName = computed(() => {
    return ['RU', 'BY', 'KZ'].includes(props.tenantCountry);
});

const form = useForm({
    first_name: '',
    last_name: '',
    middle_name: '',
    phone: '',
    personal_email: '',
    birth_date: '',
    
    branch_id: '',
    position_id: '',
    type: 'staff',
    hire_date: '',
    termination_date: '',
    is_active: true,
    calendar_color: null,

    passport_data: {
        series: '',
        number: '',
        issued_by: '',
        issue_date: '',
        department_code: '',
        registration_address: ''
    },

    // CRM Access
    has_crm_access: false,
    email: '',
    password: '',
    role_id: '',

    // Scopes (Индивидуальные доступы)
    scopes: {
        branches: [],
        legal_entities: [],
        business_directions: [],
        warehouses: [],
        accounts: []
    }
});

const openModal = () => {
    const emp = props.employee;
    activeTab.value = 'main';
    
    form.first_name = emp.first_name;
    form.last_name = emp.last_name || '';
    form.middle_name = emp.middle_name || '';
    form.phone = emp.phone || '';
    form.personal_email = emp.personal_email || '';
    form.birth_date = emp.birth_date ? emp.birth_date.substring(0, 10) : '';
    
    form.branch_id = emp.branch_id;
    form.position_id = emp.position_id || '';
    form.type = emp.type;
    form.hire_date = emp.hire_date ? emp.hire_date.substring(0, 10) : '';
    form.termination_date = emp.termination_date ? emp.termination_date.substring(0, 10) : '';
    form.is_active = Boolean(emp.is_active);
    form.calendar_color = emp.calendar_color || null;

    form.passport_data = emp.passport_data || {
        series: '', number: '', issued_by: '', issue_date: '', department_code: '', registration_address: ''
    };

    if (emp.user_id && emp.user) {
        form.has_crm_access = true;
        form.email = emp.user.email;
        form.password = ''; // Не заполняем пароль при редактировании
        form.role_id = emp.user.roles && emp.user.roles.length > 0 ? emp.user.roles[0].id : '';
        
        // Загружаем индивидуальные доступы
        const uScopes = props.userScopes[emp.user_id] || {};
        form.scopes.branches = uScopes.branches || [];
        form.scopes.legal_entities = uScopes.legal_entities || [];
        form.scopes.business_directions = uScopes.business_directions || [];
        form.scopes.warehouses = uScopes.warehouses || [];
        form.scopes.accounts = uScopes.accounts || [];
    } else {
        form.has_crm_access = false;
        form.email = '';
        form.password = '';
        form.role_id = '';
        form.scopes = { branches: [], legal_entities: [], business_directions: [], warehouses: [], accounts: [] };
    }
    
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    form.put(route('hr.employees.update', props.employee.id), {
        onSuccess: () => closeModal(),
    });
};
</script>

<template>
    <Head :title="`Сотрудник: ${employee.last_name} ${employee.first_name}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between w-full">
                <div class="flex items-center gap-2">
                    <Link :href="route('hr.employees.index')" class="text-gray-500 hover:text-primary transition-colors">
                        <i class="ri-arrow-left-line"></i> Сотрудники
                    </Link>
                    <span class="text-gray-400">/</span>
                    <span class="font-semibold text-gray-800 dark:text-gray-200">{{ employee.last_name }} {{ employee.first_name }} {{ employee.middle_name || '' }}</span>
                </div>
                <button @click="openModal" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">
                    <i class="ri-pencil-line mr-1.5"></i> Редактировать
                </button>
            </div>
        </template>

        <!-- TRI-STATE 2: Полная карточка (w-[99%] mx-auto для Fluid-дизайна) -->
        <div class="w-[99%] mx-auto flex flex-col lg:flex-row gap-6 font-sans text-slate-600">
            
            <!-- Левая колонка: About (Свойства сущности) -->
            <CollapsiblePanel storage-key="show-card-left" side="left">

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
            </CollapsiblePanel>

            <!-- Центральная колонка: Таймлайн (Activity) -->
            <div class="w-full lg:flex-1 lg:min-w-0 space-y-6">
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
            <CollapsiblePanel storage-key="show-card-right" side="right">
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
            </CollapsiblePanel>

        </div>

        <!-- Модальное окно редактирования (Focused Modal) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-4xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Редактирование данных: {{ form.last_name }} {{ form.first_name }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <!-- Вкладки внутри модалки -->
                <div class="flex overflow-x-auto border-b border-gray-200 dark:border-gray-700 px-6 bg-white dark:bg-[#313a46] custom-scrollbar">
                    <button
                        type="button"
                        @click="activeTab = 'main'"
                        :class="[activeTab === 'main' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-user-line"></i> Основные данные
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'work'"
                        :class="[activeTab === 'work' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-briefcase-line"></i> Работа
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'crm'"
                        :class="[activeTab === 'crm' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-shield-keyhole-line"></i> Доступ в CRM
                    </button>
                    <button
                        v-if="form.has_crm_access"
                        type="button"
                        @click="activeTab = 'scopes'"
                        :class="[activeTab === 'scopes' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-database-2-line"></i> Индивидуальные доступы
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'documents'"
                        :class="[activeTab === 'documents' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-profile-line"></i> Документы
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'payroll'"
                        :class="[activeTab === 'payroll' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-money-dollar-circle-line"></i> Зарплата
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    
                    <!-- Вкладка 1: Основные данные -->
                    <div v-show="activeTab === 'main'" class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Фамилия <span class="text-danger">*</span></label>
                                <input 
                                    v-model="form.last_name" 
                                    type="text" 
                                    required 
                                    placeholder="Иванов" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                                <span v-if="form.errors.last_name" class="text-xs text-danger mt-1">{{ form.errors.last_name }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Имя <span class="text-danger">*</span></label>
                                <input 
                                    v-model="form.first_name" 
                                    type="text" 
                                    required 
                                    placeholder="Иван" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                                <span v-if="form.errors.first_name" class="text-xs text-danger mt-1">{{ form.errors.first_name }}</span>
                            </div>
                            <div v-if="needsMiddleName">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Отчество <span class="text-danger">*</span></label>
                                <input 
                                    v-model="form.middle_name" 
                                    type="text" 
                                    required
                                    placeholder="Иванович" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                                <span v-if="form.errors.middle_name" class="text-xs text-danger mt-1">{{ form.errors.middle_name }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Телефон <span class="text-danger">*</span></label>
                                <input 
                                    v-model="form.phone" 
                                    type="text" 
                                    required
                                    placeholder="+7 (999) 000-00-00" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                                <span v-if="form.errors.phone" class="text-xs text-danger mt-1 block leading-tight">{{ form.errors.phone }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Личный Email</label>
                                <input 
                                    v-model="form.personal_email" 
                                    type="email" 
                                    placeholder="ivanov@mail.ru" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата рождения</label>
                                <input 
                                    v-model="form.birth_date" 
                                    type="date" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Вкладка 2: Работа -->
                    <div v-show="activeTab === 'work'" class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Основной филиал <span class="text-danger">*</span></label>
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
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Должность <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.position_id" 
                                    required
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите должность...</option>
                                    <option v-for="position in positions" :key="position.id" :value="position.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(position.name) }}</option>
                                </select>
                                <span v-if="form.errors.position_id" class="text-xs text-danger mt-1">{{ form.errors.position_id }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип оформления <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.type" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option v-for="(label, key) in employeeTypes" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ label }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата приема на работу</label>
                                <input 
                                    v-model="form.hire_date" 
                                    type="date" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата увольнения</label>
                                <input 
                                    v-model="form.termination_date" 
                                    type="date" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                        </div>

                        <div class="pt-2 border-t border-gray-200 dark:border-gray-700 mt-4">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цвет в календаре записей</label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Используется, если в настройках CRM источник цвета записи — «Исполнитель».</p>
                            <CalendarColorPicker v-model="form.calendar_color" />
                        </div>

                        <!-- Toggle Switch -->
                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-4">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Сотрудник активен (работает)
                            </label>
                        </div>
                    </div>

                    <!-- Вкладка 3: Доступ в CRM -->
                    <div v-show="activeTab === 'crm'" class="p-6 space-y-4">
                        
                        <div class="bg-info/10 border border-info/20 rounded-md p-4 mb-4">
                            <div class="flex items-center">
                                <div @click="form.has_crm_access = !form.has_crm_access" :class="[form.has_crm_access ? 'bg-success' : 'bg-gray-300 dark:bg-gray-600', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                    <div :class="[form.has_crm_access ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-3 block text-sm font-bold text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.has_crm_access = !form.has_crm_access">
                                    Предоставить доступ в CRM
                                </label>
                            </div>
                            <p class="text-xs text-info mt-2 ml-12">
                                Если включено, для сотрудника будет создан аккаунт (логин и пароль) для входа в систему.
                            </p>
                        </div>

                        <div v-if="form.has_crm_access" class="space-y-4 animate-fade-in">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email (Логин) <span class="text-danger">*</span></label>
                                    <input 
                                        v-model="form.email" 
                                        type="email" 
                                        :required="form.has_crm_access"
                                        placeholder="employee@company.com" 
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                    />
                                    <span v-if="form.errors.email" class="text-xs text-danger mt-1">{{ form.errors.email }}</span>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Пароль <span v-if="!employee?.user_id" class="text-danger">*</span></label>
                                    <input 
                                        v-model="form.password" 
                                        type="password" 
                                        :required="form.has_crm_access && !employee?.user_id"
                                        :placeholder="employee?.user_id ? 'Оставьте пустым, чтобы не менять' : 'Минимум 8 символов'" 
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                    />
                                    <span v-if="form.errors.password" class="text-xs text-danger mt-1">{{ form.errors.password }}</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Системная Роль (Права доступа) <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.role_id" 
                                    :required="form.has_crm_access"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите роль...</option>
                                    <option v-for="role in roles" :key="role.id" :value="role.id" class="bg-white dark:bg-gray-800">{{ role.name }}</option>
                                </select>
                                <span v-if="form.errors.role_id" class="text-xs text-danger mt-1">{{ form.errors.role_id }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Вкладка 4: Индивидуальные доступы (Scopes) -->
                    <div v-show="activeTab === 'scopes'" class="p-6 space-y-6">
                        <div class="bg-warning/10 border border-warning/20 rounded-md p-4 mb-2">
                            <p class="text-sm text-warning font-medium">
                                <i class="ri-information-line mr-1"></i>
                                Внимание: Галочки, установленные здесь, перекрывают (дополняют) базовые права выбранной Роли. Используйте это для выдачи индивидуальных исключений (например, доступ к дополнительному филиалу).
                            </p>
                        </div>

                        <!-- Филиалы -->
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-1">Доступные Филиалы</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label v-for="branch in scopes.branches" :key="branch.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="branch.id" v-model="form.scopes.branches" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ branch.name }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Юрлица -->
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-1">Доступные Юрлица</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label v-for="entity in scopes.legalEntities" :key="entity.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="entity.id" v-model="form.scopes.legal_entities" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ entity.name }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Направления -->
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-1">Доступные Направления</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label v-for="dir in scopes.businessDirections" :key="dir.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="dir.id" v-model="form.scopes.business_directions" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ dir.name }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Склады -->
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-1">Доступные Склады</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label v-for="wh in scopes.warehouses" :key="wh.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="wh.id" v-model="form.scopes.warehouses" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ wh.name }}</span>
                                </label>
                            </div>
                        </div>

                        <!-- Счета -->
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-1">Доступные Расчетные счета (Кассы)</h4>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                <label v-for="acc in scopes.accounts" :key="acc.id" class="flex items-center cursor-pointer group">
                                    <input type="checkbox" :value="acc.id" v-model="form.scopes.accounts" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ acc.name }}</span>
                                </label>
                            </div>
                        </div>

                    </div>

                    <!-- Вкладка 5: Документы (Паспорт) -->
                    <div v-show="activeTab === 'documents'" class="p-6 space-y-5">
                        <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-200 dark:border-gray-700 pb-2">Паспортные данные</h4>
                        
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Серия</label>
                                <input 
                                    v-model="form.passport_data.series" 
                                    type="text" 
                                    placeholder="1234" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Номер</label>
                                <input 
                                    v-model="form.passport_data.number" 
                                    type="text" 
                                    placeholder="567890" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Код подразделения</label>
                                <input 
                                    v-model="form.passport_data.department_code" 
                                    type="text" 
                                    placeholder="123-456" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Кем выдан</label>
                                <input 
                                    v-model="form.passport_data.issued_by" 
                                    type="text" 
                                    placeholder="ГУ МВД России..." 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата выдачи</label>
                                <input 
                                    v-model="form.passport_data.issue_date" 
                                    type="date" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                />
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Адрес регистрации (прописка)</label>
                                <textarea 
                                    v-model="form.passport_data.registration_address" 
                                    rows="2"
                                    placeholder="г. Москва, ул. Ленина..." 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                                ></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Вкладка 6: Зарплата -->
                    <div v-show="activeTab === 'payroll'" class="p-6 flex flex-col items-center justify-center text-center py-16">
                        <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4">
                            <i class="ri-money-dollar-circle-line text-3xl text-gray-400 dark:text-gray-500"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-2">Настройки мотивации</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md">
                            Вкладка зарезервирована для модуля расчета заработной платы (оклады, проценты от услуг, KPI). Данный функционал будет доступен в следующих обновлениях.
                        </p>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                            Отмена
                        </button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            Сохранить
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>