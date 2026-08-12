<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    generalSettings: Object,
    positionRules: { type: Array, default: () => [] },
    personalRulesCount: { type: Number, default: 0 },
    positions: { type: Array, default: () => [] },
    serviceCategories: { type: Array, default: () => [] },
    services: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
});

const getLocalizedLabel = (label) => {
    if (!label) return '';
    if (typeof label === 'string') {
        try { label = JSON.parse(label); } catch (e) { return label; }
    }
    return label['ru'] || label['en'] || Object.values(label)[0] || '';
};

// --- ОБЩИЕ НАСТРОЙКИ ---
const generalForm = useForm({
    apply_discount_to_base: props.generalSettings.apply_discount_to_base,
    worker_base_excludes_materials: props.generalSettings.worker_base_excludes_materials,
    worker_base_excludes_admin_share: props.generalSettings.worker_base_excludes_admin_share,
    default_self_employed_tax_percent: props.generalSettings.default_self_employed_tax_percent,
    salary_accrual_day: props.generalSettings.salary_accrual_day,
});

const submitGeneral = () => {
    generalForm.post(route('settings.payroll.general.store'), { preserveScroll: true });
};

// --- СТАВКИ ПО ДОЛЖНОСТЯМ ---
const isRuleModalOpen = ref(false);
const editingRule = ref(null);

const ruleForm = useForm({
    position_id: '',
    target: 'category',
    service_id: '',
    service_category_id: '',
    branch_id: '',
    type: 'percentage',
    fixed_amount: 0,
    percentage_value: 0,
});

const selectedPosition = computed(() => props.positions.find(p => p.id === Number(ruleForm.position_id)));
const isAdminPosition = computed(() => selectedPosition.value?.payroll_role === 'admin');

const filteredServices = computed(() => {
    if (!ruleForm.service_category_id) return props.services;
    return props.services.filter(s => s.service_category_id === Number(ruleForm.service_category_id));
});

const openRuleModal = (rule = null) => {
    editingRule.value = rule;
    if (rule) {
        ruleForm.position_id = rule.position_id ?? '';
        ruleForm.target = rule.is_default_for_unlisted ? 'default' : (rule.service_id ? 'service' : 'category');
        ruleForm.service_id = rule.service_id ?? '';
        ruleForm.service_category_id = rule.service_category_id ?? '';
        ruleForm.branch_id = rule.branch_id ?? '';
        ruleForm.type = rule.type;
        ruleForm.fixed_amount = (rule.fixed_amount || 0) / 100;
        ruleForm.percentage_value = rule.percentage_value || 0;
    } else {
        ruleForm.reset();
        ruleForm.target = 'category';
        ruleForm.type = 'percentage';
    }
    isRuleModalOpen.value = true;
};

const closeRuleModal = () => {
    isRuleModalOpen.value = false;
    editingRule.value = null;
    ruleForm.reset();
    ruleForm.clearErrors();
};

const submitRule = () => {
    if (isAdminPosition.value) {
        ruleForm.type = 'percentage';
    }

    const action = editingRule.value
        ? ruleForm.put(route('settings.payroll.rules.update', editingRule.value.id), { onSuccess: closeRuleModal, preserveScroll: true })
        : ruleForm.post(route('settings.payroll.rules.store'), { onSuccess: closeRuleModal, preserveScroll: true });
};

const deleteRule = (rule) => {
    if (confirm('Удалить эту ставку?')) {
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
</script>

<template>
    <Head title="Настройки зарплаты" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            <SettingsNav />

            <PageHelper title="Как настроить зарплату — три уровня">
                <p><strong>1. Общие ставки должностей</strong> — здесь, на этой странице: базовая ставка (% или фикс. сумма) для должности, по услуге, группе услуг или «по умолчанию» для услуг вне справочника.</p>
                <p><strong>2. Персональные ставки сотрудника</strong> — переопределяют общую ставку должности для конкретного человека. Настраиваются в карточке сотрудника, вкладка «Зарплата» (раздел HR → Сотрудники). Сейчас персональных ставок настроено: <strong>{{ personalRulesCount }}</strong>.</p>
                <p><strong>3. Разовая правка на конкретной услуге</strong> — сумма/% и доля работ бригады можно точечно поменять прямо в заказ-наряде, кликнув на позицию услуги. Это переопределяет всё остальное только для этой одной позиции.</p>
                <p class="text-xs text-gray-400 mt-2">Роль должности (администратор / исполнитель) задаётся в <Link :href="route('hr.positions.index')" class="text-primary hover:underline">Справочнике должностей</Link> — от неё зависит, по какой формуле считается ЗП.</p>
            </PageHelper>

            <!-- Header -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Настройки зарплаты</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Правила расчёта ЗП: общие тумблеры и ставки по должностям
                    </p>
                </div>
            </div>

            <!-- Общие настройки -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6">
                <form @submit.prevent="submitGeneral" class="space-y-4">
                    <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-2">Общие правила расчёта базы</h2>

                    <div class="flex items-center p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                        <div @click="generalForm.apply_discount_to_base = !generalForm.apply_discount_to_base" :class="[generalForm.apply_discount_to_base ? 'bg-success' : 'bg-gray-300 dark:bg-gray-600', 'flex items-center h-6 w-11 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                            <div :class="[generalForm.apply_discount_to_base ? 'translate-x-6' : 'translate-x-1', 'h-4 w-4 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <div class="ml-4">
                            <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 cursor-pointer" @click="generalForm.apply_discount_to_base = !generalForm.apply_discount_to_base">
                                Учитывать скидку позиции при расчёте ЗП
                            </label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Если выключено, база берётся из полной цены услуги без учёта предоставленной клиенту скидки.</p>
                        </div>
                    </div>

                    <div class="flex items-center p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                        <div @click="generalForm.worker_base_excludes_materials = !generalForm.worker_base_excludes_materials" :class="[generalForm.worker_base_excludes_materials ? 'bg-success' : 'bg-gray-300 dark:bg-gray-600', 'flex items-center h-6 w-11 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                            <div :class="[generalForm.worker_base_excludes_materials ? 'translate-x-6' : 'translate-x-1', 'h-4 w-4 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <div class="ml-4">
                            <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 cursor-pointer" @click="generalForm.worker_base_excludes_materials = !generalForm.worker_base_excludes_materials">
                                Вычитать стоимость материалов из базы исполнителей
                            </label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Материалы, привязанные к услуге прямо в заказе, уменьшают базу расчёта ЗП исполнителей этой услуги.</p>
                        </div>
                    </div>

                    <div class="flex items-center p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                        <div @click="generalForm.worker_base_excludes_admin_share = !generalForm.worker_base_excludes_admin_share" :class="[generalForm.worker_base_excludes_admin_share ? 'bg-success' : 'bg-gray-300 dark:bg-gray-600', 'flex items-center h-6 w-11 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                            <div :class="[generalForm.worker_base_excludes_admin_share ? 'translate-x-6' : 'translate-x-1', 'h-4 w-4 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <div class="ml-4">
                            <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 cursor-pointer" @click="generalForm.worker_base_excludes_admin_share = !generalForm.worker_base_excludes_admin_share">
                                Вычитать ЗП администратора из базы исполнителей
                            </label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Сумма, начисленная администратору по услуге, дополнительно уменьшает базу для расчёта ЗП бригады исполнителей.</p>
                        </div>
                    </div>

                    <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                        <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 mb-1">Компенсация налога самозанятым по умолчанию</label>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">К начисленной сумме сотрудника с типом «Самозанятый» добавляется этот процент — компенсация налога, который он платит государству. Можно переопределить лично для сотрудника в его карточке.</p>
                        <div class="flex items-center gap-2 max-w-xs">
                            <input v-model="generalForm.default_self_employed_tax_percent" type="number" step="0.01" min="0" max="100" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span class="text-sm text-gray-600 dark:text-gray-400 shrink-0">%</span>
                        </div>
                    </div>

                    <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                        <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 mb-1">День начисления оклада</label>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Число месяца, в которое системa автоматически создаёт черновик начисления оклада (ожидает выплаты в карточке сотрудника) для всех активных сотрудников с заданным окладом. Учитывается часовой пояс локации.</p>
                        <input v-model="generalForm.salary_accrual_day" type="number" step="1" min="1" max="28" required class="block w-full max-w-[100px] rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit" :disabled="generalForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            Сохранить общие настройки
                        </button>
                    </div>
                </form>
            </div>

            <!-- Ставки по должностям -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <div>
                        <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200">Ставки по должностям</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Действуют на всех сотрудников должности, если у конкретного человека не задана личная ставка.</p>
                    </div>
                    <button @click="openRuleModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                        <i class="ri-add-line text-base"></i> Добавить ставку
                    </button>
                </div>
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Должность</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Применяется к</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Локация</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Ставка</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="rule in positionRules" :key="rule.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50">
                                    <div class="font-semibold text-gray-800 dark:text-gray-200">{{ rule.position ? getLocalizedLabel(rule.position.name) : '—' }}</div>
                                    <span v-if="rule.position" :class="[rule.position.payroll_role === 'admin' ? 'bg-primary/10 text-primary' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', 'inline-flex mt-1 items-center gap-1 py-0.5 px-1.5 rounded text-[10px] font-medium']">
                                        {{ rule.position.payroll_role === 'admin' ? 'Администратор' : 'Исполнитель' }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ ruleTargetLabel(rule) }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ rule.branch ? rule.branch.name : 'Все локации' }}</td>
                                <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50 text-right">{{ ruleValueLabel(rule) }}</td>
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
                                    <button @click="openRuleModal(rule)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать"><i class="ri-pencil-line"></i></button>
                                    <button @click="deleteRule(rule)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                </td>
                            </tr>
                            <tr v-if="positionRules.length === 0">
                                <td colspan="5" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Ставки ещё не настроены. Нажмите "Добавить ставку".
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Модалка добавления/редактирования ставки -->
        <div v-if="isRuleModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-xl lg:max-w-2xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingRule ? 'Редактирование ставки' : 'Новая ставка' }}</h3>
                    <button @click="closeRuleModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>

                <form @submit.prevent="submitRule" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div v-if="ruleForm.errors.target || ruleForm.errors.type" class="p-3 rounded-md bg-danger/10 border border-danger/20 text-sm text-danger flex gap-2">
                            <i class="ri-error-warning-line shrink-0 mt-0.5"></i>
                            <span>{{ ruleForm.errors.target || ruleForm.errors.type }}</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Должность <span class="text-danger">*</span></label>
                            <select v-model="ruleForm.position_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" disabled>Выберите должность</option>
                                <option v-for="p in positions" :key="p.id" :value="p.id">{{ getLocalizedLabel(p.name) }} ({{ p.payroll_role === 'admin' ? 'Администратор' : 'Исполнитель' }})</option>
                            </select>
                            <p v-if="ruleForm.errors.position_id" class="text-xs text-danger mt-1">{{ ruleForm.errors.position_id }}</p>
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
                            <p v-if="ruleForm.target === 'default'" class="text-xs text-gray-400 mt-1.5">Используется для услуг, добавленных в заказ вручную, вне справочника.</p>
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
                                <label :class="[ruleForm.type === 'fixed' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', isAdminPosition ? 'opacity-40 cursor-not-allowed' : '', 'relative flex cursor-pointer rounded-md border p-2.5 text-center text-xs font-medium']">
                                    <input type="radio" v-model="ruleForm.type" value="fixed" :disabled="isAdminPosition" class="sr-only" /> <span class="w-full">Фикс. сумма</span>
                                </label>
                            </div>
                            <p v-if="isAdminPosition" class="text-xs text-gray-400 mt-1.5">Должности с ролью «Администратор» доступен только процент.</p>
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
    </AuthenticatedLayout>
</template>
