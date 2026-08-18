<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import SearchableMultiSelect from '@/Components/SearchableMultiSelect.vue';
import DataTable from '@/Components/DataTable.vue';

const props = defineProps({
    generalSettings: Object,
    positionRules: { type: Array, default: () => [] },
    personalRulesCount: { type: Number, default: 0 },
    positions: { type: Array, default: () => [] },
    contractors: { type: Array, default: () => [] },
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
    // Ставка адресуется либо должности, либо конкретному подрядчику —
    // заполнено ровно одно поле (см. PayrollSettingsController::storeRule()).
    client_id: '',
    target: 'category',
    // Одиночные поля — используются при РЕДАКТИРОВАНИИ существующей ставки
    // (та всегда ровно одна строка) и как фильтр по группе при выборе услуг.
    service_id: '',
    service_category_id: '',
    branch_id: '',
    // Множественные — используются при СОЗДАНИИ: одна ставка на каждую
    // выбранную комбинацию (категория/услуга × локация), чтобы не заводить
    // вручную кучу однотипных записей с одним и тем же %. См. комментарий
    // к PayrollSettingsController::storeRule().
    service_ids: [],
    service_category_ids: [],
    branch_ids: [],
    type: 'percentage',
    fixed_amount: 0,
    percentage_value: 0,
});

const selectedPosition = computed(() => props.positions.find(p => p.id === Number(ruleForm.position_id)));
const isAdminPosition = computed(() => selectedPosition.value?.payroll_role === 'admin');

// Кому адресована ставка: должности (штатные сотрудники) или подрядчику.
// Переключение чистит «чужое» поле, чтобы на сервер не ушли оба сразу.
const ruleTargetKind = ref('position');

const setRuleTargetKind = (kind) => {
    ruleTargetKind.value = kind;
    if (kind === 'position') {
        ruleForm.client_id = '';
    } else {
        ruleForm.position_id = '';
        // У подрядчика нет должности, а значит и ограничения «администратору
        // только процент» — тип ставки остаётся свободным.
    }
};

const filteredServices = computed(() => {
    if (!ruleForm.service_category_id) return props.services;
    return props.services.filter(s => s.service_category_id === Number(ruleForm.service_category_id));
});

const serviceCategoryOptions = computed(() => props.serviceCategories.map(c => ({ id: c.id, label: getLocalizedLabel(c.name) })));
const branchOptions = computed(() => props.branches.map(b => ({ id: b.id, label: b.name })));

const selectAllCategories = () => {
    ruleForm.service_category_ids = props.serviceCategories.map(c => c.id);
};

const clearCategories = () => {
    ruleForm.service_category_ids = [];
};

const selectAllBranches = () => {
    ruleForm.branch_ids = props.branches.map(b => b.id);
};

const clearBranches = () => {
    ruleForm.branch_ids = [];
};

const openRuleModal = (rule = null) => {
    editingRule.value = rule;
    if (rule) {
        ruleForm.position_id = rule.position_id ?? '';
        ruleForm.client_id = rule.client_id ?? '';
        ruleTargetKind.value = rule.client_id ? 'contractor' : 'position';
        ruleForm.target = rule.is_default_for_unlisted ? 'default' : (rule.service_id ? 'service' : 'category');
        ruleForm.service_id = rule.service_id ?? '';
        ruleForm.service_category_id = rule.service_category_id ?? '';
        ruleForm.branch_id = rule.branch_id ?? '';
        ruleForm.service_ids = [];
        ruleForm.service_category_ids = [];
        ruleForm.branch_ids = [];
        ruleForm.type = rule.type;
        ruleForm.fixed_amount = (rule.fixed_amount || 0) / 100;
        ruleForm.percentage_value = rule.percentage_value || 0;
    } else {
        ruleForm.reset();
        ruleForm.target = 'category';
        ruleForm.type = 'percentage';
        ruleTargetKind.value = 'position';
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

// --- ФИЛЬТРЫ ТАБЛИЦЫ СТАВОК (клиентские — список и так весь загружен, без
// пагинации, отдельного запроса на сервер не требуется) ---
const filterPositionIds = ref([]);
const filterBranchIds = ref([]);
const filterCategoryIds = ref([]);

const hasActiveFilters = computed(() => filterPositionIds.value.length > 0 || filterBranchIds.value.length > 0 || filterCategoryIds.value.length > 0);

const resetTableFilters = () => {
    filterPositionIds.value = [];
    filterBranchIds.value = [];
    filterCategoryIds.value = [];
};

const filteredPositionRules = computed(() => {
    return props.positionRules.filter(rule => {
        if (filterPositionIds.value.length && !filterPositionIds.value.includes(rule.position_id)) {
            return false;
        }
        // branch_id=null у правила значит "действует на все локации" — такое
        // правило реально применяется и на выбранной в фильтре локации тоже,
        // поэтому не скрываем его, а не наоборот "показываем только точное совпадение".
        if (filterBranchIds.value.length && rule.branch_id !== null && !filterBranchIds.value.includes(rule.branch_id)) {
            return false;
        }
        if (filterCategoryIds.value.length) {
            const categoryId = rule.service_category_id ?? rule.service?.service_category_id ?? null;
            if (!categoryId || !filterCategoryIds.value.includes(categoryId)) {
                return false;
            }
        }
        return true;
    });
});

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

// Все 4 колонки — производные (relation-lookup или составной рендер из
// нескольких полей правила), ни одна не мапится 1:1 на скалярную колонку
// таблицы payroll_rules — сортировка сознательно не добавлена, как и у
// матричного режима Services/Index.vue.
const ruleColumns = [
    { key: 'target_entity', label: 'Должность / Подрядчик' },
    { key: 'applies_to', label: 'Применяется к' },
    { key: 'branch', label: 'Локация' },
    { key: 'rate', label: 'Ставка', align: 'right' },
];
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

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
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
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Действуют на всех сотрудников должности, если у конкретного человека не задана личная ставка.
                            Список должностей и их роль (администратор/исполнитель) — в <Link :href="route('hr.positions.index')" class="text-primary hover:underline">Справочнике должностей</Link>.
                        </p>
                    </div>
                    <button @click="openRuleModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                        <i class="ri-add-line text-base"></i> Добавить ставку
                    </button>
                </div>

                <!-- Фильтры (клиентские) — на больших списках должностей/локаций/групп искать нужную ставку в общем списке неудобно -->
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-primary/5 dark:bg-primary/10 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 items-start">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5">Должность</label>
                        <SearchableMultiSelect
                            v-model="filterPositionIds"
                            :options="positions.map(p => ({ id: p.id, label: getLocalizedLabel(p.name) }))"
                            placeholder="Все должности"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5">Локация</label>
                        <SearchableMultiSelect
                            v-model="filterBranchIds"
                            :options="branches.map(b => ({ id: b.id, label: b.name }))"
                            placeholder="Все локации"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5">Группа услуг</label>
                        <SearchableMultiSelect
                            v-model="filterCategoryIds"
                            :options="serviceCategories.map(c => ({ id: c.id, label: getLocalizedLabel(c.name) }))"
                            placeholder="Все группы"
                        />
                    </div>
                    <div class="flex items-end">
                        <button v-if="hasActiveFilters" @click="resetTableFilters" class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 hover:text-danger transition-colors py-2">
                            <i class="ri-close-circle-line"></i> Сбросить фильтры
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="ruleColumns"
                        :rows="filteredPositionRules"
                        has-actions
                    >
                        <template #cell-target_entity="{ row: rule }">
                            <template v-if="rule.client">
                                <div class="font-semibold text-gray-800 dark:text-gray-200">{{ rule.client.name }}</div>
                                <span class="inline-flex mt-1 items-center gap-1 py-0.5 px-1.5 rounded text-[10px] font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400">
                                    <i class="ri-briefcase-line"></i> Подрядчик
                                </span>
                            </template>
                            <template v-else>
                                <div class="font-semibold text-gray-800 dark:text-gray-200">{{ rule.position ? getLocalizedLabel(rule.position.name) : '—' }}</div>
                                <span v-if="rule.position" :class="[rule.position.payroll_role === 'admin' ? 'bg-primary/10 text-primary' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300', 'inline-flex mt-1 items-center gap-1 py-0.5 px-1.5 rounded text-[10px] font-medium']">
                                    {{ rule.position.payroll_role === 'admin' ? 'Администратор' : 'Исполнитель' }}
                                </span>
                            </template>
                        </template>
                        <template #cell-applies_to="{ row: rule }">{{ ruleTargetLabel(rule) }}</template>
                        <template #cell-branch="{ row: rule }">{{ rule.branch ? rule.branch.name : 'Все локации' }}</template>
                        <template #cell-rate="{ row: rule }">
                            <span class="font-bold text-gray-800 dark:text-gray-200">{{ ruleValueLabel(rule) }}</span>
                        </template>
                        <template #actions="{ row: rule }">
                            <button @click="openRuleModal(rule)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать"><i class="ri-pencil-line"></i></button>
                            <button @click="deleteRule(rule)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                        </template>
                        <template #empty>
                            <template v-if="positionRules.length === 0">
                                Ставки ещё не настроены. Нажмите "Добавить ставку".
                            </template>
                            <template v-else>
                                Ничего не найдено по выбранным фильтрам. <button @click="resetTableFilters" class="text-primary hover:underline">Сбросить фильтры</button>
                            </template>
                        </template>
                    </DataTable>
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
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Кому назначается ставка <span class="text-danger">*</span></label>
                            <div class="grid grid-cols-2 gap-2 mb-2">
                                <label :class="[ruleTargetKind === 'position' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex cursor-pointer rounded-md border p-2.5 text-center text-xs font-medium']">
                                    <input type="radio" :checked="ruleTargetKind === 'position'" @change="setRuleTargetKind('position')" class="sr-only" />
                                    <span class="w-full"><i class="ri-user-line mr-1"></i> Должности</span>
                                </label>
                                <label :class="[ruleTargetKind === 'contractor' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex cursor-pointer rounded-md border p-2.5 text-center text-xs font-medium']">
                                    <input type="radio" :checked="ruleTargetKind === 'contractor'" @change="setRuleTargetKind('contractor')" class="sr-only" />
                                    <span class="w-full"><i class="ri-briefcase-line mr-1"></i> Подрядчику</span>
                                </label>
                            </div>

                            <select v-if="ruleTargetKind === 'position'" v-model="ruleForm.position_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" disabled>Выберите должность</option>
                                <option v-for="p in positions" :key="p.id" :value="p.id">{{ getLocalizedLabel(p.name) }} ({{ p.payroll_role === 'admin' ? 'Администратор' : 'Исполнитель' }})</option>
                            </select>
                            <template v-else>
                                <select v-model="ruleForm.client_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" disabled>Выберите подрядчика</option>
                                    <option v-for="c in contractors" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                                <p v-if="contractors.length === 0" class="text-xs text-gray-400 mt-1">
                                    Подрядчиков пока нет. Подрядчик — это клиент с ролью «Подрядчик» в его карточке.
                                </p>
                                <p v-else class="text-xs text-gray-400 mt-1">
                                    Ставка действует только на этого подрядчика: у подрядчиков нет должностей, поэтому общей ставки «на всех» не существует.
                                </p>
                            </template>
                            <p v-if="ruleForm.errors.position_id" class="text-xs text-danger mt-1">{{ ruleForm.errors.position_id }}</p>
                            <p v-if="ruleForm.errors.client_id" class="text-xs text-danger mt-1">{{ ruleForm.errors.client_id }}</p>
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
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                Группа услуг <span class="text-danger">*</span>
                                <span v-if="!editingRule" class="text-gray-400 font-normal">— можно выбрать несколько, на каждую создастся своя ставка</span>
                            </label>
                            <template v-if="!editingRule">
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-xs text-gray-400">Выбрано: {{ ruleForm.service_category_ids.length }}</span>
                                    <div class="flex items-center gap-3">
                                        <button type="button" @click="selectAllCategories" class="text-xs text-primary hover:underline">Выбрать все</button>
                                        <button v-if="ruleForm.service_category_ids.length > 0" type="button" @click="clearCategories" class="text-xs text-gray-400 hover:text-danger hover:underline">Очистить</button>
                                    </div>
                                </div>
                                <SearchableMultiSelect
                                    v-model="ruleForm.service_category_ids"
                                    :options="serviceCategoryOptions"
                                    placeholder="Выберите группы услуг..."
                                />
                            </template>
                            <select v-else v-model="ruleForm.service_category_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
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
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                    Услуга <span class="text-danger">*</span>
                                    <span v-if="!editingRule" class="text-gray-400 font-normal">— можно выбрать несколько</span>
                                </label>
                                <SearchableMultiSelect
                                    v-if="!editingRule"
                                    v-model="ruleForm.service_ids"
                                    :options="filteredServices.map(s => ({ id: s.id, label: getLocalizedLabel(s.name) }))"
                                    placeholder="Выберите услуги..."
                                />
                                <select v-else v-model="ruleForm.service_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" disabled>Выберите услугу</option>
                                    <option v-for="s in filteredServices" :key="s.id" :value="s.id">{{ getLocalizedLabel(s.name) }}</option>
                                </select>
                            </div>
                        </template>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                Локация
                                <span v-if="!editingRule" class="text-gray-400 font-normal">— можно выбрать несколько, пусто = все локации</span>
                            </label>
                            <template v-if="!editingRule">
                                <div class="flex items-center justify-between mb-1.5">
                                    <span class="text-xs text-gray-400">Выбрано: {{ ruleForm.branch_ids.length }}</span>
                                    <div class="flex items-center gap-3">
                                        <button type="button" @click="selectAllBranches" class="text-xs text-primary hover:underline">Выбрать все</button>
                                        <button v-if="ruleForm.branch_ids.length > 0" type="button" @click="clearBranches" class="text-xs text-gray-400 hover:text-danger hover:underline">Очистить</button>
                                    </div>
                                </div>
                                <SearchableMultiSelect
                                    v-model="ruleForm.branch_ids"
                                    :options="branchOptions"
                                    placeholder="Выберите локации..."
                                />
                            </template>
                            <select v-else v-model="ruleForm.branch_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
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
