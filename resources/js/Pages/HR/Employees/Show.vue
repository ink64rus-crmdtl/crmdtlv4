<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CollapsiblePanel from '@/Components/CollapsiblePanel.vue';
import CalendarColorPicker from '@/Components/CalendarColorPicker.vue';
import ActivityTimeline from '@/Components/ActivityTimeline.vue';
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
    activities: { type: Array, default: () => [] },
    comments: { type: Array, default: () => [] },
    personalPayrollRules: { type: Array, default: () => [] },
    serviceCategories: { type: Array, default: () => [] },
    services: { type: Array, default: () => [] },
    payrollEntries: { type: Array, default: () => [] },
    payoutAccounts: { type: Array, default: () => [] },
    payrollBalance: { type: Object, default: () => ({ accrued_total: 0, paid_total: 0, deductions_total: 0, balance: 0 }) },
});

const activeTimelineTab = ref('history'); // 'history', 'comments'

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

const formatMoney = (cents) => {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format((cents || 0) / 100);
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
    secondary_position_id: '',
    salary_amount: '',
    self_employed_tax_percent: '',
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

const openModal = (tab = 'main') => {
    const emp = props.employee;
    activeTab.value = tab;
    
    form.first_name = emp.first_name;
    form.last_name = emp.last_name || '';
    form.middle_name = emp.middle_name || '';
    form.phone = emp.phone || '';
    form.personal_email = emp.personal_email || '';
    form.birth_date = emp.birth_date ? emp.birth_date.substring(0, 10) : '';
    
    form.branch_id = emp.branch_id;
    form.position_id = emp.position_id || '';
    form.secondary_position_id = emp.secondary_position_id || '';
    form.salary_amount = emp.salary_amount ? emp.salary_amount / 100 : '';
    form.self_employed_tax_percent = emp.self_employed_tax_percent ?? '';
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

// --- ПЕРСОНАЛЬНЫЕ СТАВКИ ЗП (Фаза 10.1) ---
const isRuleModalOpen = ref(false);
const editingRule = ref(null);

const ruleForm = useForm({
    employee_id: props.employee.id,
    target: 'category',
    service_id: '',
    service_category_id: '',
    branch_id: '',
    type: 'percentage',
    fixed_amount: 0,
    percentage_value: 0,
});

const filteredServices = computed(() => {
    if (!ruleForm.service_category_id) return props.services;
    return props.services.filter(s => s.service_category_id === Number(ruleForm.service_category_id));
});

const openRuleModal = (rule = null) => {
    editingRule.value = rule;
    if (rule) {
        ruleForm.target = rule.is_default_for_unlisted ? 'default' : (rule.service_id ? 'service' : 'category');
        ruleForm.service_id = rule.service_id ?? '';
        ruleForm.service_category_id = rule.service_category_id ?? '';
        ruleForm.branch_id = rule.branch_id ?? '';
        ruleForm.type = rule.type;
        ruleForm.fixed_amount = (rule.fixed_amount || 0) / 100;
        ruleForm.percentage_value = rule.percentage_value || 0;
    } else {
        ruleForm.reset();
        ruleForm.employee_id = props.employee.id;
        ruleForm.target = 'category';
        ruleForm.type = 'percentage';
    }
    isRuleModalOpen.value = true;
};

const closeRuleModal = () => {
    isRuleModalOpen.value = false;
    editingRule.value = null;
    ruleForm.clearErrors();
};

const submitRule = () => {
    if (editingRule.value) {
        ruleForm.put(route('settings.payroll.rules.update', editingRule.value.id), { onSuccess: closeRuleModal, preserveScroll: true });
    } else {
        ruleForm.post(route('settings.payroll.rules.store'), { onSuccess: closeRuleModal, preserveScroll: true });
    }
};

const deleteRule = (rule) => {
    if (confirm('Удалить эту персональную ставку?')) {
        useForm({}).delete(route('settings.payroll.rules.destroy', rule.id), { preserveScroll: true });
    }
};

const ruleTargetLabel = (rule) => {
    if (rule.is_default_for_unlisted) return 'По умолчанию (вне справочника)';
    if (rule.service) return `Услуга: ${getLocalizedLabel(rule.service.name)}`;
    if (rule.service_category) return `Категория: ${getLocalizedLabel(rule.service_category.name)}`;
    return '—';
};

const ruleValueLabel = (rule) => {
    return rule.type === 'fixed'
        ? `${(rule.fixed_amount / 100).toLocaleString('ru-RU')} ₽`
        : `${rule.percentage_value}%`;
};

// --- НАЧИСЛЕНИЯ И ВЫПЛАТЫ (Фаза 10.3) ---
const isAccrualModalOpen = ref(false);
const accrualForm = useForm({
    employee_id: props.employee.id,
    type: 'accrual',
    amount: '',
    comment: '',
});

const openAccrualModal = (type) => {
    accrualForm.reset();
    accrualForm.employee_id = props.employee.id;
    accrualForm.type = type;
    isAccrualModalOpen.value = true;
};

const closeAccrualModal = () => {
    isAccrualModalOpen.value = false;
    accrualForm.clearErrors();
};

const submitAccrual = () => {
    accrualForm.post(route('hr.payroll.store'), { onSuccess: closeAccrualModal, preserveScroll: true });
};

const isPayoutFormOpen = ref(false);
const payoutTarget = ref(null);
const payoutForm = useForm({ account_id: '' });

const openPayoutForm = (entry) => {
    payoutTarget.value = entry;
    payoutForm.reset();
    isPayoutFormOpen.value = true;
};

const closePayoutForm = () => {
    isPayoutFormOpen.value = false;
    payoutTarget.value = null;
    payoutForm.clearErrors();
};

const submitPayout = () => {
    payoutForm.post(route('hr.payroll.payout', payoutTarget.value.id), { onSuccess: closePayoutForm, preserveScroll: true });
};

const cancelPayrollEntry = (entry) => {
    if (confirm('Отменить эту запись?')) {
        useForm({}).delete(route('hr.payroll.cancel', entry.id), { preserveScroll: true });
    }
};

const payrollRoleLabels = {
    admin: 'Администратор (заказ)',
    worker: 'Исполнитель (заказ)',
    salary: 'Оклад',
    manual: 'Вручную',
};

const payrollStatusClasses = {
    pending: 'bg-warning/10 text-warning',
    paid: 'bg-success/10 text-success',
    canceled: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
};

const payrollStatusLabels = {
    pending: 'Ожидает',
    paid: 'Выплачено',
    canceled: 'Отменено',
};

const formatDate = (dateStr) => dateStr ? new Date(dateStr).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
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
                <button @click="openModal()" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">
                    <i class="ri-pencil-line mr-1.5"></i> Редактировать
                </button>
            </div>
        </template>

        <!-- TRI-STATE 2: Карточка (w-[99%] mx-auto для Fluid-дизайна) -->
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
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Основная локация</p>
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

            <!-- Центральная колонка: Лента активности (История / Комментарии) -->
            <div class="w-full lg:flex-1 lg:min-w-0 space-y-6">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col h-full min-h-[500px]">
                    <div class="flex space-x-6 border-b border-gray-200 dark:border-gray-700 px-6 bg-gray-50/50 dark:bg-gray-800/30">
                        <button @click="activeTimelineTab = 'history'" :class="[activeTimelineTab === 'history' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-history-line"></i> История
                        </button>
                        <button @click="activeTimelineTab = 'comments'" :class="[activeTimelineTab === 'comments' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-chat-3-line"></i> Комментарии
                            <span v-if="comments.length > 0" class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-primary/10 text-primary text-[10px] font-bold">{{ comments.length }}</span>
                        </button>
                    </div>

                    <div v-if="activeTimelineTab === 'history'" class="flex-1 flex flex-col min-h-0">
                        <ActivityTimeline :activities="activities" />
                    </div>
                    <div v-if="activeTimelineTab === 'comments'" class="flex-1 flex flex-col min-h-0">
                        <ActivityTimeline :activities="comments" :comment-url="route('hr.employees.comment', employee.id)" />
                    </div>
                </div>
            </div>

            <!-- Правая колонка: Связи (Associations / Scopes) -->
            <CollapsiblePanel storage-key="show-card-right" side="right">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Зарплата</h3>
                        <button @click="openModal('payroll')" class="text-primary hover:text-primary-600 transition-colors" title="Настроить"><i class="ri-settings-3-line"></i></button>
                    </div>
                    <div class="p-6 space-y-3">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Оклад:</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ employee.salary_amount ? formatMoney(employee.salary_amount) + ' / мес' : 'Не задан' }}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Совмещаемая должность:</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ employee.secondary_position ? getLocalizedLabel(employee.secondary_position.name) : '—' }}</span>
                        </div>
                        <div v-if="employee.type === 'self_employed'" class="flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Компенсация налога:</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ employee.self_employed_tax_percent !== null ? employee.self_employed_tax_percent + '%' : 'По умолчанию тенанта' }}</span>
                        </div>
                        <div class="pt-3 border-t border-gray-100 dark:border-gray-700/50 flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Персональных ставок по услугам:</span>
                            <span class="font-bold text-primary">{{ personalPayrollRules.length }}</span>
                        </div>
                        <button @click="openModal('payroll')" class="w-full mt-2 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white">
                            <i class="ri-team-line mr-1.5"></i> Настроить зарплату
                        </button>
                    </div>
                </div>

                <!-- Начисления и выплаты (Фаза 10.3) -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Начисления и выплаты</h3>
                        <div class="flex gap-1.5">
                            <button @click="openAccrualModal('accrual')" title="Начислить премию" class="text-success hover:text-success-600 transition-colors"><i class="ri-add-circle-line text-lg"></i></button>
                            <button @click="openAccrualModal('deduction')" title="Оформить штраф" class="text-danger hover:text-danger-600 transition-colors"><i class="ri-subtract-line text-lg"></i></button>
                        </div>
                    </div>
                    <div class="px-6 py-3 border-b border-gray-100 dark:border-gray-700/50 grid grid-cols-3 gap-2 text-center bg-gray-50/30 dark:bg-gray-800/20">
                        <div>
                            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Начислено</p>
                            <p class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(payrollBalance.accrued_total) }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Выплачено</p>
                            <p class="text-sm font-bold text-success">{{ formatMoney(payrollBalance.paid_total) }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wider">К выплате</p>
                            <p class="text-sm font-bold" :class="payrollBalance.balance > 0 ? 'text-primary' : 'text-gray-400'">{{ formatMoney(payrollBalance.balance) }}</p>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700/50 max-h-96 overflow-y-auto custom-scrollbar">
                        <div v-for="entry in payrollEntries" :key="entry.id" class="p-4">
                            <div class="flex justify-between items-start gap-2">
                                <div>
                                    <div class="flex items-center gap-1.5 flex-wrap">
                                        <span :class="[entry.type === 'deduction' ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success', 'inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase']">{{ entry.type === 'deduction' ? 'Штраф' : 'Начисление' }}</span>
                                        <span class="text-xs text-gray-400">{{ payrollRoleLabels[entry.role] || entry.role }}</span>
                                    </div>
                                    <p v-if="entry.comment" class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ entry.comment }}</p>
                                    <p class="text-[11px] text-gray-400 mt-1">{{ formatDate(entry.created_at) }}</p>
                                </div>
                                <div class="text-right shrink-0">
                                    <div :class="entry.type === 'deduction' ? 'text-danger' : 'text-gray-800 dark:text-gray-200'" class="text-sm font-bold">{{ entry.type === 'deduction' ? '−' : '' }}{{ formatMoney(entry.amount) }}</div>
                                    <span :class="[payrollStatusClasses[entry.status], 'inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium mt-1']">{{ payrollStatusLabels[entry.status] }}</span>
                                </div>
                            </div>
                            <div v-if="entry.status === 'pending'" class="flex gap-2 mt-2">
                                <button v-if="entry.type === 'accrual'" @click="openPayoutForm(entry)" class="text-xs font-medium text-primary hover:underline">Выплатить</button>
                                <button @click="cancelPayrollEntry(entry)" class="text-xs font-medium text-gray-400 hover:text-danger">Отменить</button>
                            </div>
                        </div>
                        <div v-if="payrollEntries.length === 0" class="p-6 text-center text-sm text-gray-400">Начислений пока нет.</div>
                    </div>
                </div>

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
                                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-2">Доступ к локациям</p>
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
                        <i class="ri-team-line"></i> Зарплата
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
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Основная локация <span class="text-danger">*</span></label>
                                <select
                                    v-model="form.branch_id"
                                    required
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите локацию...</option>
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
                                Внимание: Галочки, установленные здесь, перекрывают (дополняют) базовые права выбранной Роли. Используйте это для выдачи индивидуальных исключений (например, доступ к дополнительной локации).
                            </p>
                        </div>

                        <!-- Локации -->
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3 border-b border-gray-200 dark:border-gray-700 pb-1">Доступные Локации</h4>
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
                    <div v-show="activeTab === 'payroll'" class="p-6 space-y-5">
                        <div class="bg-info/10 border border-info/20 rounded-md p-4 flex gap-3 items-start text-sm text-gray-600 dark:text-gray-400">
                            <i class="ri-information-fill text-info text-xl shrink-0 mt-0.5"></i>
                            <div>
                                Персональные ставки ниже переопределяют
                                <a :href="route('settings.payroll.index')" target="_blank" class="text-primary hover:underline">общие ставки должности (Настройки → Зарплата)</a>
                                только для этого сотрудника. Разово изменить сумму/% на конкретной услуге можно прямо в заказ-наряде, кликнув на позицию.
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Совмещаемая должность</label>
                                <select v-model="form.secondary_position_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                    <option value="">Не совмещает</option>
                                    <option v-for="position in positions" :key="position.id" :value="position.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(position.name) }}</option>
                                </select>
                                <p class="text-xs text-gray-400 mt-1">Если сотрудник назначен исполнителем на услугу не по основной должности (например, администратор сам оклеил авто), ЗП за эту работу считается по ставке совмещаемой должности.</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Оклад</label>
                                <div class="flex items-center gap-2">
                                    <input v-model="form.salary_amount" type="number" step="0.01" min="0" placeholder="Не задан" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                    <span class="text-sm text-gray-600 dark:text-gray-400 shrink-0">₽ / мес</span>
                                </div>
                                <p class="text-xs text-gray-400 mt-1">Фиксированная периодическая выплата, независимо от заказов. Можно сочетать с % от услуг ниже.</p>
                            </div>
                        </div>

                        <div v-if="form.type === 'self_employed'" class="p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Личная ставка компенсации налога</label>
                            <div class="flex items-center gap-2 max-w-xs">
                                <input v-model="form.self_employed_tax_percent" type="number" step="0.01" min="0" max="100" placeholder="По умолчанию тенанта" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                <span class="text-sm text-gray-600 dark:text-gray-400 shrink-0">%</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Пусто — берётся общая ставка из Настройки → Зарплата.</p>
                        </div>

                        <div class="pt-4 border-t border-gray-200 dark:border-gray-700">
                            <div class="flex justify-between items-center mb-3">
                                <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200">Персональные ставки по услугам</h4>
                                <button type="button" @click="openRuleModal()" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white gap-1.5">
                                    <i class="ri-add-line"></i> Добавить ставку
                                </button>
                            </div>
                            <div class="overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-md">
                                <table class="min-w-full text-left">
                                    <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                        <tr>
                                            <th class="py-2.5 px-4 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Применяется к</th>
                                            <th class="py-2.5 px-4 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Локация</th>
                                            <th class="py-2.5 px-4 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider text-right">Ставка</th>
                                            <th class="py-2.5 px-4 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider text-right">Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                        <tr v-for="rule in personalPayrollRules" :key="rule.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40">
                                            <td class="py-2.5 px-4 text-sm text-gray-700 dark:text-gray-300">{{ ruleTargetLabel(rule) }}</td>
                                            <td class="py-2.5 px-4 text-sm text-gray-500 dark:text-gray-400">{{ rule.branch ? rule.branch.name : 'Все локации' }}</td>
                                            <td class="py-2.5 px-4 text-sm font-bold text-gray-800 dark:text-gray-200 text-right">{{ ruleValueLabel(rule) }}</td>
                                            <td class="py-2.5 px-4 text-right space-x-1.5">
                                                <button type="button" @click="openRuleModal(rule)" class="inline-flex items-center justify-center rounded px-2 py-1 text-xs bg-primary/10 text-primary hover:bg-primary hover:text-white transition-colors"><i class="ri-pencil-line"></i></button>
                                                <button type="button" @click="deleteRule(rule)" class="inline-flex items-center justify-center rounded px-2 py-1 text-xs bg-danger/10 text-danger hover:bg-danger hover:text-white transition-colors"><i class="ri-delete-bin-line"></i></button>
                                            </td>
                                        </tr>
                                        <tr v-if="personalPayrollRules.length === 0">
                                            <td colspan="4" class="py-6 px-4 text-center text-sm text-gray-400">Персональных ставок нет — действует общая ставка должности.</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
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

        <!-- Модалка добавления/редактирования персональной ставки ЗП -->
        <div v-if="isRuleModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingRule ? 'Редактирование ставки' : 'Новая персональная ставка' }}</h3>
                    <button @click="closeRuleModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>

                <form @submit.prevent="submitRule" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div v-if="ruleForm.errors.target || ruleForm.errors.type" class="p-3 rounded-md bg-danger/10 border border-danger/20 text-sm text-danger flex gap-2">
                            <i class="ri-error-warning-line shrink-0 mt-0.5"></i>
                            <span>{{ ruleForm.errors.target || ruleForm.errors.type }}</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Применяется к</label>
                            <div class="grid grid-cols-3 gap-2">
                                <label :class="[ruleForm.target === 'category' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex cursor-pointer rounded-md border p-2.5 text-center text-xs font-medium']">
                                    <input type="radio" v-model="ruleForm.target" value="category" class="sr-only" /> <span class="w-full">Группа услуг</span>
                                </label>
                                <label :class="[ruleForm.target === 'service' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex cursor-pointer rounded-md border p-2.5 text-center text-xs font-medium']">
                                    <input type="radio" v-model="ruleForm.target" value="service" class="sr-only" /> <span class="w-full">Конкретная услуга</span>
                                </label>
                                <label :class="[ruleForm.target === 'default' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex cursor-pointer rounded-md border p-2.5 text-center text-xs font-medium']">
                                    <input type="radio" v-model="ruleForm.target" value="default" class="sr-only" /> <span class="w-full">По умолчанию</span>
                                </label>
                            </div>
                        </div>

                        <div v-if="ruleForm.target === 'category'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Группа услуг <span class="text-danger">*</span></label>
                            <select v-model="ruleForm.service_category_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" disabled>Выберите группу</option>
                                <option v-for="c in serviceCategories" :key="c.id" :value="c.id">{{ getLocalizedLabel(c.name) }}</option>
                            </select>
                        </div>

                        <template v-if="ruleForm.target === 'service'">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Фильтр по группе (необязательно)</label>
                                <select v-model="ruleForm.service_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="">Все группы</option>
                                    <option v-for="c in serviceCategories" :key="c.id" :value="c.id">{{ getLocalizedLabel(c.name) }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Услуга <span class="text-danger">*</span></label>
                                <select v-model="ruleForm.service_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" disabled>Выберите услугу</option>
                                    <option v-for="s in filteredServices" :key="s.id" :value="s.id">{{ getLocalizedLabel(s.name) }}</option>
                                </select>
                            </div>
                        </template>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация</label>
                            <select v-model="ruleForm.branch_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="">Все локации</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип ставки <span class="text-danger">*</span></label>
                            <div class="grid grid-cols-2 gap-2">
                                <label :class="[ruleForm.type === 'percentage' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex cursor-pointer rounded-md border p-2.5 text-center text-xs font-medium']">
                                    <input type="radio" v-model="ruleForm.type" value="percentage" class="sr-only" /> <span class="w-full">% от базы</span>
                                </label>
                                <label :class="[ruleForm.type === 'fixed' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex cursor-pointer rounded-md border p-2.5 text-center text-xs font-medium']">
                                    <input type="radio" v-model="ruleForm.type" value="fixed" class="sr-only" /> <span class="w-full">Фикс. сумма</span>
                                </label>
                            </div>
                        </div>

                        <div v-if="ruleForm.type === 'percentage'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Процент <span class="text-danger">*</span></label>
                            <div class="flex items-center gap-2 max-w-xs">
                                <input v-model="ruleForm.percentage_value" type="number" step="0.01" min="0" max="100" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <span class="text-sm text-gray-600 dark:text-gray-400 shrink-0">%</span>
                            </div>
                        </div>
                        <div v-else>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма за услугу <span class="text-danger">*</span></label>
                            <div class="flex items-center gap-2 max-w-xs">
                                <input v-model="ruleForm.fixed_amount" type="number" step="0.01" min="0" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <span class="text-sm text-gray-600 dark:text-gray-400 shrink-0">₽</span>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeRuleModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="ruleForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Модалка ручного начисления/штрафа -->
        <div v-if="isAccrualModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ accrualForm.type === 'deduction' ? 'Оформить штраф' : 'Начислить премию' }}</h3>
                    <button @click="closeAccrualModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitAccrual" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма <span class="text-danger">*</span></label>
                            <div class="flex items-center gap-2">
                                <input v-model="accrualForm.amount" type="number" step="0.01" min="0.01" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <span class="text-sm text-gray-600 dark:text-gray-400 shrink-0">₽</span>
                            </div>
                            <p v-if="accrualForm.errors.amount" class="text-xs text-danger mt-1">{{ accrualForm.errors.amount }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Комментарий</label>
                            <textarea v-model="accrualForm.comment" rows="2" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeAccrualModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="accrualForm.processing" :class="accrualForm.type === 'deduction' ? 'bg-danger hover:bg-danger-600' : 'bg-success hover:bg-success-600'" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 text-white disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Модалка выплаты -->
        <div v-if="isPayoutFormOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Выплата</h3>
                    <button @click="closePayoutForm()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitPayout" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div class="p-3 rounded-md bg-gray-50 dark:bg-gray-800/50 flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">{{ payoutTarget ? (payrollRoleLabels[payoutTarget.role] || payoutTarget.role) : '' }}</span>
                            <span class="font-bold text-gray-800 dark:text-gray-200">{{ payoutTarget ? formatMoney(payoutTarget.amount) : '' }}</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Касса / Счёт <span class="text-danger">*</span></label>
                            <select v-model="payoutForm.account_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" disabled>Выберите счёт</option>
                                <option v-for="acc in payoutAccounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
                            </select>
                            <p v-if="payoutForm.errors.account_id" class="text-xs text-danger mt-1">{{ payoutForm.errors.account_id }}</p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closePayoutForm()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="payoutForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-success text-white hover:bg-success-600 disabled:opacity-50">Провести выплату</button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>