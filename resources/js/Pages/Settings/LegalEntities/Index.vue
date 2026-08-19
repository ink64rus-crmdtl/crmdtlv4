<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import CompanySuggestInput from '@/Components/CompanySuggestInput.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, useForm, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import axios from 'axios';

const props = defineProps({
    legalEntities: Object,
    filters: Object,
    tenantCountry: String,
    countryConfig: Object,
    branches: Array,
});

const isModalOpen = ref(false);
const editingEntityId = ref(null);
const activeTab = ref('main'); // 'main' or 'accounts'

// Реактивное вычисление текущего юрлица для мгновенного обновления счетов
const editingEntity = computed(() => {
    if (!editingEntityId.value) return null;
    return props.legalEntities.data.find(e => e.id === editingEntityId.value) || null;
});

// Состояние под-модалки счетов
const isAccountModalOpen = ref(false);
const editingAccount = ref(null);

const form = useForm({
    name: '',
    tax_id: '',
    requisites: {},
    is_active: true,
    branch_ids: [],
    vat_payer: false,
    vat_rate: null,
    vat_calculation_method: 'inclusive',
});

const accountForm = useForm({
    legal_entity_id: null,
    name: '',
    type: 'cash',
    commission_percent: 0,
    bank_name: '',
    bik: '',
    account_number: '',
    corr_account: '',
    is_default_for_invoicing: false,
    is_active: true,
});

// --- Автоподстановка банка по БИК (DaData) ---
// bank_name/corr_account намеренно нередактируемы вручную (см. форму ниже) —
// заполняются только отсюда, чтобы название банка не могло разойтись с БИК
// в готовом документе. bikLookupState: 'idle' | 'loading' | 'found' | 'not_found' | 'not_configured'.
const bikLookupState = ref('idle');

const lookupBik = useDebounceFn(async () => {
    const bik = accountForm.bik.trim();

    if (bik.length < 5) {
        bikLookupState.value = 'idle';
        accountForm.bank_name = '';
        accountForm.corr_account = '';
        return;
    }

    bikLookupState.value = 'loading';

    try {
        const { data } = await axios.get(route('settings.accounts.bik-lookup'), { params: { bik } });

        if (data.found) {
            accountForm.bank_name = data.bank_name;
            accountForm.corr_account = data.corr_account;
            bikLookupState.value = 'found';
        } else {
            accountForm.bank_name = '';
            accountForm.corr_account = '';
            bikLookupState.value = data.configured ? 'not_found' : 'not_configured';
        }
    } catch {
        accountForm.bank_name = '';
        accountForm.corr_account = '';
        bikLookupState.value = 'not_found';
    }
}, 500);

watch(() => accountForm.bik, () => {
    if (accountForm.type === 'bank') lookupBik();
});

const accountTypeLabels = {
    cash: 'Касса',
    bank: 'Расчетный счет',
    acquiring: 'Эквайринг',
};

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const fetchFiltered = useDebounceFn(() => {
    router.get(route('settings.legal-entities.index'), {
        search: search.value,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());

const { sort, onSort } = useServerSort('settings.legal-entities.index', () => props.filters, () => ({ search: search.value }));
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

// jurisdiction (единая для тенанта, не своя колонка), branches/accounts (связи) — не сортируются.
const legalEntityColumns = [
    { key: 'name', label: 'Название', sortable: true },
    { key: 'jurisdiction', label: 'Юрисдикция' },
    { key: 'tax_id', label: 'Налоговый номер', sortable: true },
    { key: 'branches', label: 'Локации' },
    { key: 'accounts', label: 'Расчетные счета' },
    { key: 'status', label: 'Статус', sortable: true, sortKey: 'is_active' },
];

const bulkDelete = () => {
    if (confirm(`Удалить выбранные юридические лица (${selectedIds.value.length})?`)) {
        router.post(route('settings.legal-entities.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('settings.legal-entities.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `legal_entities_export_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
    } catch (error) {
        console.error("Export failed", error);
        alert("Ошибка при экспорте данных");
    }
};
// ----------------------------------------

const currentCountrySchema = computed(() => {
    return props.countryConfig?.requisite_schema || [];
});

const signatorySchema = computed(() => {
    return props.countryConfig?.signatory_schema || [];
});

const vatConfig = computed(() => {
    return props.countryConfig?.vat || { applicable: false, rates: [], default_rate: null, label: 'НДС' };
});

const bankLabels = computed(() => {
    return props.countryConfig?.bank_labels || {
        bik: 'БИК / SWIFT',
        account_number: 'Расчетный счет / IBAN',
        corr_account: 'Корр. счет',
    };
});

// Выбор варианта из подсказки DaData (см. CompanySuggestInput.vue) — заполняет
// разом название, налоговые реквизиты (что нашлось в текущей requisite_schema)
// и подписанта (director_name/position, вкладка "Подписи"), если это юрлицо
// (для ИП DaData этих полей не отдаёт — сервис их и не проставляет).
const applyCompanySuggestion = (suggestion) => {
    form.name = suggestion.name;
    if (suggestion.inn) form.requisites.inn = suggestion.inn;
    if (suggestion.kpp) form.requisites.kpp = suggestion.kpp;
    if (suggestion.ogrn) form.requisites.ogrn = suggestion.ogrn;
    if (suggestion.legal_address) form.requisites.legal_address = suggestion.legal_address;
    if (suggestion.director_name) form.requisites.director_name = suggestion.director_name;
    if (suggestion.director_position) form.requisites.director_position = suggestion.director_position;
};

const openModal = (entity = null) => {
    if (entity) {
        editingEntityId.value = entity.id;
        form.name = entity.name;
        form.tax_id = entity.tax_id || '';
        form.requisites = entity.requisites || {};
        form.is_active = Boolean(entity.is_active);
        form.branch_ids = (entity.branches || []).map(b => b.id);
        form.vat_payer = Boolean(entity.vat_payer);
        form.vat_rate = entity.vat_rate ?? vatConfig.value.default_rate;
        form.vat_calculation_method = entity.vat_calculation_method || 'inclusive';
    } else {
        editingEntityId.value = null;
        form.reset();
        form.requisites = {};
        form.is_active = true;
        form.branch_ids = [];
        form.vat_payer = false;
        form.vat_rate = vatConfig.value.default_rate;
        form.vat_calculation_method = 'inclusive';
    }
    activeTab.value = 'main';
    isModalOpen.value = true;
};

// Включение чекбокса "Плательщик НДС" без готового значения ставки —
// подставляем дефолт страны, чтобы select не остался пустым.
watch(() => form.vat_payer, (isPayer) => {
    if (isPayer && !form.vat_rate) {
        form.vat_rate = vatConfig.value.default_rate;
    }
});

const closeModal = () => {
    isModalOpen.value = false;
    editingEntityId.value = null;
    form.reset();
    form.clearErrors();
};

const submitEntity = () => {
    if (editingEntityId.value) {
        form.put(route('settings.legal-entities.update', editingEntityId.value), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('settings.legal-entities.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteEntity = (entity) => {
    if (confirm(`Удалить юрлицо "${entity.name}"?`)) {
        form.delete(route('settings.legal-entities.destroy', entity.id));
    }
};

// Работа со счетами
const openAccountModal = (account = null) => {
    editingAccount.value = account;
    accountForm.legal_entity_id = editingEntityId.value;

    if (account) {
        accountForm.name = account.name;
        accountForm.type = account.type;
        accountForm.commission_percent = account.commission_percent || 0;
        accountForm.bank_name = account.bank_name || '';
        accountForm.bik = account.bik || '';
        accountForm.account_number = account.account_number || '';
        accountForm.corr_account = account.corr_account || '';
        accountForm.is_default_for_invoicing = Boolean(account.is_default_for_invoicing);
        accountForm.is_active = Boolean(account.is_active);
        // Уже сохранённый счёт — считаем БИК/банк ранее подтверждёнными, не
        // дёргаем DaData заново, пока пользователь сам не тронет поле БИК.
        bikLookupState.value = account.bank_name ? 'found' : 'idle';
    } else {
        bikLookupState.value = 'idle';
        accountForm.reset();
        accountForm.legal_entity_id = editingEntityId.value;
        accountForm.type = 'cash';
        accountForm.commission_percent = 0;
        accountForm.is_default_for_invoicing = !editingEntity.value?.accounts?.length;
        accountForm.is_active = true;
    }

    isAccountModalOpen.value = true;
};

const closeAccountModal = () => {
    isAccountModalOpen.value = false;
    editingAccount.value = null;
    accountForm.reset();
};

const submitAccount = () => {
    if (editingAccount.value) {
        accountForm.put(route('settings.accounts.update', editingAccount.value.id), {
            onSuccess: () => closeAccountModal(),
        });
    } else {
        accountForm.post(route('settings.accounts.store'), {
            onSuccess: () => closeAccountModal(),
        });
    }
};

const deleteAccount = (account) => {
    if (confirm(`Удалить счет "${account.name}"?`)) {
        accountForm.delete(route('settings.accounts.destroy', account.id));
    }
};
</script>

<template>
    <Head title="Юридические лица" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            
            <!-- Навигация по настройкам (Attex Tabs) -->
            <SettingsNav />

            <!-- Page Helper (Система подсказок) -->
            <PageHelper title="Как устроена структура компании?">
                <p>В нашей системе <strong>Юридическое лицо</strong> — это исключительно финансовый профиль: реквизиты, банковские счета и подписанты для печатных документов (Акты, Счета, Заказ-наряды). Вся реальная работа (записи клиентов, склады, сотрудники, заказы) привязывается к <strong>Локации</strong> — она первична, юрлицо только "подключается" к ней.</p>
                <p>Одна локация может выставлять документы сразу от нескольких юрлиц (например, часть заказов — от ИП, часть — от ООО), и одно юрлицо может обслуживать несколько локаций — привязка настраивается прямо здесь, при создании/редактировании юрлица.</p>
                <p class="mt-2 text-xs text-amber-600 dark:text-amber-400"><i class="ri-alert-line align-middle mr-1"></i> Не забудьте настроить <strong>Локацию</strong> (Настройки → Локации) — без неё нельзя будет заводить сотрудников, склады и заказы, а юрлицу некуда будет привязываться.</p>
                <p class="text-xs mt-2 opacity-80"><i class="ri-error-warning-line align-middle"></i> Примечание: Если у вас несколько юрлиц с абсолютно разными базами клиентов, разными складами и независимыми сотрудниками, мы настоятельно рекомендуем создать для них отдельные аккаунты (Тенанты) во избежание путаницы.</p>
            </PageHelper>

            <!-- Header Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Юридические лица и Реквизиты</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Управление юридическими лицами компании и их банковскими счетами
                    </p>
                </div>
            </div>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="юрлиц" 
                @export="bulkExport" 
                @delete="bulkDelete" 
            />

            <!-- Table Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="false"
                    placeholder="Поиск по названию или ИНН..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Добавить юрлицо
                        </button>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="legalEntityColumns"
                        :rows="legalEntities.data"
                        selectable
                        v-model="selectedIds"
                        has-actions
                        :sort="sort"
                        @sort="onSort"
                        empty-message='Юридические лица еще не добавлены. Нажмите "Добавить юрлицо".'
                    >
                        <template #cell-name="{ row: entity }">
                            <div class="flex items-center gap-2">
                                <i class="ri-bank-line text-primary"></i>
                                {{ entity.name }}
                            </div>
                        </template>
                        <template #cell-jurisdiction>
                            <span class="inline-flex items-center gap-1.5 py-0.5 px-2 rounded bg-gray-100 dark:bg-gray-700 text-xs font-medium text-gray-700 dark:text-gray-300">
                                {{ countryConfig?.name || tenantCountry }}
                            </span>
                        </template>
                        <template #cell-tax_id="{ row: entity }">{{ entity.tax_id || '—' }}</template>
                        <template #cell-branches="{ row: entity }">
                            <div class="flex flex-wrap gap-1" v-if="entity.branches && entity.branches.length > 0">
                                <span v-for="b in entity.branches" :key="b.id" class="inline-flex items-center gap-1 py-0.5 px-2 rounded bg-gray-100 dark:bg-gray-700 text-xs font-medium text-gray-700 dark:text-gray-300">
                                    <i class="ri-store-2-line"></i> {{ b.name }}
                                </span>
                            </div>
                            <span v-else class="text-xs text-gray-400 dark:text-gray-500">—</span>
                        </template>
                        <template #cell-accounts="{ row: entity }">
                            <span class="inline-flex items-center gap-1.5 font-medium text-xs text-gray-600 dark:text-gray-400">
                                <i class="ri-wallet-3-line"></i> {{ entity.accounts?.length || 0 }} сч.
                            </span>
                        </template>
                        <template #cell-status="{ row: entity }">
                            <span
                                :class="[
                                    entity.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger',
                                    'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium'
                                ]"
                            >
                                {{ entity.is_active ? 'Активно' : 'Неактивно' }}
                            </span>
                        </template>
                        <template #actions="{ row: entity }">
                            <button
                                @click="openModal(entity)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                title="Редактировать"
                            >
                                <i class="ri-pencil-line"></i>
                            </button>
                            <button
                                @click="deleteEntity(entity)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                title="Удалить"
                            >
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="legalEntities" />
            </div>
        </div>

        <!-- Модальное окно Юрлица (Attex Standard: 50% width) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl lg:max-w-3xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingEntity ? 'Редактирование юридического лица' : 'Новое юридическое лицо' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <!-- Вкладки внутри модалки -->
                <div class="flex space-x-6 border-b border-gray-200 dark:border-gray-700 px-6 bg-gray-50/50 dark:bg-gray-800/50">
                    <button
                        type="button"
                        @click="activeTab = 'main'"
                        :class="[
                            activeTab === 'main' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2',
                            'py-3.5 px-2 text-sm transition-colors focus:outline-none'
                        ]"
                    >
                        Основная информация
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'signatures'"
                        :class="[
                            activeTab === 'signatures' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2',
                            'py-3.5 px-2 text-sm transition-colors focus:outline-none'
                        ]"
                    >
                        Подписи
                    </button>
                    <button
                        v-if="vatConfig.applicable"
                        type="button"
                        @click="activeTab = 'vat'"
                        :class="[
                            activeTab === 'vat' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2',
                            'py-3.5 px-2 text-sm transition-colors focus:outline-none'
                        ]"
                    >
                        {{ vatConfig.label || 'НДС' }}
                    </button>
                    <button
                        v-if="editingEntity"
                        type="button"
                        @click="activeTab = 'accounts'"
                        :class="[
                            activeTab === 'accounts' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2',
                            'py-3.5 px-2 text-sm transition-colors flex items-center gap-2 focus:outline-none'
                        ]"
                    >
                        <span>Счета предприятия</span>
                        <span class="bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-xs px-2 py-0.5 rounded-full font-semibold">
                            {{ editingEntity.accounts?.length || 0 }}
                        </span>
                    </button>
                </div>

                <!-- Содержимое Вкладок 1-2: Основная информация / Подписи (общая форма — оба относятся к одному submitEntity) -->
                <form v-show="activeTab === 'main' || activeTab === 'signatures' || activeTab === 'vat'" @submit.prevent="submitEntity" class="flex flex-col">
                    <div class="p-6 space-y-5">

                        <div v-show="activeTab === 'main'" class="space-y-5">
                            <div v-if="form.errors.tax_id" class="p-3 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-center gap-2">
                                <i class="ri-error-warning-line text-lg"></i> {{ form.errors.tax_id }}
                            </div>

                            <!-- Инфо блок юрисдикции -->
                            <div class="flex items-center justify-between bg-info/10 p-3.5 rounded-md border border-info/20">
                                <span class="text-xs font-bold text-info uppercase tracking-wider">Страна юрисдикции:</span>
                                <span class="text-sm font-bold text-info">{{ countryConfig?.name }} ({{ tenantCountry }})</span>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Официальное название (ИП / ООО) <span class="text-danger">*</span></label>
                                <!-- Живой поиск DaData — только для РФ: у DaData нет реестра организаций
                                     других стран, для остальных юрисдикций остаётся обычный ручной ввод. -->
                                <CompanySuggestInput
                                    v-if="tenantCountry === 'RU'"
                                    v-model="form.name"
                                    required
                                    placeholder="Начните вводить название или ИНН..."
                                    @select="applyCompanySuggestion"
                                />
                                <input
                                    v-else
                                    v-model="form.name"
                                    type="text"
                                    required
                                    placeholder="ООО Детейлинг Групп"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                />
                            </div>

                            <!-- Привязка к локациям: юрлицо без локации существовать не может (кроме
                                 самой первой локации в системе — та создастся автоматически). Одна
                                 локация может иметь несколько юрлиц и наоборот — многие-ко-многим. -->
                            <div v-if="branches.length > 0" class="border-t border-gray-200 dark:border-gray-700 pt-4">
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-1">
                                    От каких локаций это юрлицо может выставлять документы? <span class="text-danger">*</span>
                                </h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Выберите хотя бы одну. Локация может быть отмечена сразу у нескольких юрлиц.</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <label v-for="branch in branches" :key="branch.id" class="flex items-center cursor-pointer group">
                                        <input type="checkbox" :value="branch.id" v-model="form.branch_ids" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                        <span class="ml-2 text-sm text-gray-700 dark:text-gray-300 group-hover:text-gray-900 dark:group-hover:text-gray-100 transition-colors">{{ branch.name }}</span>
                                    </label>
                                </div>
                                <span v-if="form.errors.branch_ids" class="text-xs text-danger mt-1 block">{{ form.errors.branch_ids }}</span>
                            </div>
                            <div v-else class="p-3 rounded-md bg-info/5 border border-info/20 text-xs text-gray-600 dark:text-gray-400">
                                <i class="ri-information-line text-info mr-1"></i>
                                В системе пока нет ни одной локации — она будет создана автоматически («Основная») и сразу привязана к этому юрлицу.
                            </div>

                            <!-- Динамические реквизиты под юрисдикцию тенанта -->
                            <div class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-2">
                                <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-4">
                                    Налоговые реквизиты
                                </h4>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div v-for="field in currentCountrySchema" :key="field.key" :class="field.type === 'textarea' ? 'sm:col-span-2' : ''">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                            {{ field.label }} <span v-if="field.required" class="text-danger">*</span>
                                        </label>

                                        <textarea
                                            v-if="field.type === 'textarea'"
                                            v-model="form.requisites[field.key]"
                                            :required="field.required"
                                            :placeholder="field.placeholder"
                                            rows="2"
                                            class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                        ></textarea>

                                        <!-- Живой поиск и по ИНН тоже — тот же DaData-эндпоинт, что и у
                                             названия, сам распознаёт цифровой запрос как ИНН. -->
                                        <CompanySuggestInput
                                            v-else-if="field.key === 'inn' && tenantCountry === 'RU'"
                                            v-model="form.requisites[field.key]"
                                            :required="field.required"
                                            :placeholder="field.placeholder"
                                            @select="applyCompanySuggestion"
                                        />

                                        <input
                                            v-else
                                            v-model="form.requisites[field.key]"
                                            :type="field.type"
                                            :required="field.required"
                                            :placeholder="field.placeholder"
                                            class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                        />
                                    </div>
                                </div>
                            </div>

                            <!-- Toggle Switch (Attex Style) -->
                            <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                                <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                    <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                    Юридическое лицо активно
                                </label>
                            </div>
                        </div>

                        <!-- Подписанты (для печатных документов — Акт/Счёт/Договор) -->
                        <div v-show="activeTab === 'signatures'">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-1">
                                Подписи в документах
                            </h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Должность и ФИО для блока подписи в печатных формах (счета, акты, договоры). Необязательно.</p>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div v-for="field in signatorySchema" :key="field.key">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                        {{ field.label }}
                                    </label>
                                    <input
                                        v-model="form.requisites[field.key]"
                                        type="text"
                                        :placeholder="field.placeholder"
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- НДС (Фаза 1, поддержка НДС) — только для стран, где применимо
                             (vatConfig.applicable, см. CountryConfigService). Если плательщик
                             НДС выключен — поля ставки/принципа расчёта скрыты целиком, а не
                             просто задизейблены, как и требуется CLAUDE.md-принципом "нет
                             НДС — нет полей". -->
                        <div v-show="activeTab === 'vat'">
                            <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-1">
                                {{ vatConfig.label || 'НДС' }}
                            </h4>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Определяет, появятся ли поля НДС в заказах и приходных накладных от лица этого юрлица, и как считается сумма.</p>

                            <label class="inline-flex items-center gap-2 mb-4 cursor-pointer">
                                <input v-model="form.vat_payer" type="checkbox" class="rounded border-gray-300 dark:border-gray-600 text-primary focus:ring-primary" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">Плательщик {{ (vatConfig.label || 'НДС').toLowerCase() }}</span>
                            </label>

                            <div v-if="form.vat_payer" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ставка</label>
                                    <select v-model.number="form.vat_rate" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                        <option v-for="rate in vatConfig.rates" :key="rate" :value="rate">{{ rate }}%</option>
                                    </select>
                                    <span v-if="form.errors.vat_rate" class="text-xs text-danger mt-1 block">{{ form.errors.vat_rate }}</span>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Принцип расчёта</label>
                                    <select v-model="form.vat_calculation_method" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                        <option value="inclusive">Цена уже включает {{ (vatConfig.label || 'НДС').toLowerCase() }}</option>
                                        <option value="exclusive">{{ vatConfig.label || 'НДС' }} начисляется сверху</option>
                                    </select>
                                    <p class="text-[11px] text-gray-400 mt-1">«Включает» — итог заказа не меняется, налог просто выделяется отдельной строкой. «Сверху» — к сумме позиций добавляется налог.</p>
                                </div>
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

                <!-- Содержимое Вкладки 2: Расчетные счета -->
                <div v-if="activeTab === 'accounts'" class="p-6 space-y-4 flex flex-col">
                    <div class="flex justify-between items-center mb-2">
                        <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Кассы, расчетные счета и эквайринг компании</p>
                        <button
                            type="button"
                            @click="openAccountModal()"
                            class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5"
                        >
                            <i class="ri-add-line"></i>
                            Добавить счет
                        </button>
                    </div>

                    <div class="space-y-3 max-h-80 overflow-y-auto pr-1">
                        <div
                            v-for="account in editingEntity?.accounts"
                            :key="account.id"
                            class="p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center"
                        >
                            <div>
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="font-semibold text-gray-800 dark:text-gray-200 text-sm">{{ account.name }}</span>
                                    <span class="inline-flex items-center rounded bg-gray-200 dark:bg-gray-700 px-2 py-0.5 text-xs font-medium text-gray-700 dark:text-gray-300">
                                        {{ accountTypeLabels[account.type] || account.type }}
                                    </span>
                                    <div v-if="account.is_default_for_invoicing" class="inline-flex items-center rounded bg-info/10 px-2 py-0.5 text-xs font-medium text-info">
                                        Основной для документов
                                    </div>
                                </div>
                                <p v-if="account.type === 'bank'" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    <strong class="text-gray-700 dark:text-gray-300 font-medium">{{ account.bank_name || 'Банк не указан' }}</strong> |
                                    {{ bankLabels.bik }}: {{ account.bik || '—' }} |
                                    Счет: <span class="text-gray-700 dark:text-gray-300 font-semibold">{{ account.account_number || '—' }}</span>
                                </p>
                                <p v-else-if="account.type === 'acquiring'" class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                    Комиссия банка: <span class="text-gray-700 dark:text-gray-300 font-semibold">{{ account.commission_percent || 0 }}%</span>
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <button 
                                    type="button" 
                                    @click="openAccountModal(account)" 
                                    class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                >
                                    <i class="ri-pencil-line"></i>
                                </button>
                                <button 
                                    type="button" 
                                    @click="deleteAccount(account)" 
                                    class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                >
                                    <i class="ri-delete-bin-line"></i>
                                </button>
                            </div>
                        </div>

                        <div v-if="!editingEntity?.accounts || editingEntity?.accounts.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400 text-sm">
                            Счета еще не добавлены. Нажмите "Добавить счет".
                        </div>
                    </div>

                    <div class="bg-info/5 border border-info/20 rounded-md p-3 flex items-center justify-between gap-3">
                        <p class="text-xs text-gray-600 dark:text-gray-400">
                            <i class="ri-arrow-left-right-line text-info mr-1"></i>
                            Переводы между счетами и полная история операций — в разделе «Финансы».
                        </p>
                        <Link :href="route('finance.transactions.index')" class="shrink-0 inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-info/10 text-info hover:bg-info hover:text-white">
                            Перейти <i class="ri-arrow-right-line ml-1"></i>
                        </Link>
                    </div>

                    <div class="flex justify-end border-t border-gray-200 dark:border-gray-700 pt-4 mt-4">
                        <button 
                            type="button" 
                            @click="closeModal()" 
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white"
                        >
                            Закрыть
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- Под-модальное окно создания / редактирования Счета -->
        <div v-if="isAccountModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-xl lg:max-w-2xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingAccount ? 'Редактирование счета' : 'Добавление счета' }}
                    </h3>
                    <button type="button" @click="closeAccountModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submitAccount" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <!-- Тип счета -->
                        <div class="grid grid-cols-3 gap-3">
                            <label :class="[accountForm.type === 'cash' ? 'border-success bg-success/5 ring-1 ring-success' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-3 shadow-sm focus:outline-none transition-all items-center justify-center text-center']">
                                <input type="radio" v-model="accountForm.type" value="cash" class="sr-only" />
                                <span class="text-sm font-semibold text-gray-900 dark:text-white flex flex-col items-center gap-1">
                                    <i class="ri-wallet-3-line text-success text-xl"></i> Касса
                                </span>
                            </label>
                            <label :class="[accountForm.type === 'bank' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-3 shadow-sm focus:outline-none transition-all items-center justify-center text-center']">
                                <input type="radio" v-model="accountForm.type" value="bank" class="sr-only" />
                                <span class="text-sm font-semibold text-gray-900 dark:text-white flex flex-col items-center gap-1">
                                    <i class="ri-bank-line text-primary text-xl"></i> Расчетный счет
                                </span>
                            </label>
                            <label :class="[accountForm.type === 'acquiring' ? 'border-info bg-info/5 ring-1 ring-info' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-3 shadow-sm focus:outline-none transition-all items-center justify-center text-center']">
                                <input type="radio" v-model="accountForm.type" value="acquiring" class="sr-only" />
                                <span class="text-sm font-semibold text-gray-900 dark:text-white flex flex-col items-center gap-1">
                                    <i class="ri-bank-card-line text-info text-xl"></i> Эквайринг
                                </span>
                            </label>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название счета (для внутренних документов) <span class="text-danger">*</span></label>
                            <input
                                v-model="accountForm.name"
                                type="text"
                                required
                                :placeholder="accountForm.type === 'cash' ? 'Касса администратора' : (accountForm.type === 'acquiring' ? 'Терминал Сбербанк' : 'Основной р/с в Сбербанке')"
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                            />
                        </div>

                        <template v-if="accountForm.type === 'bank'">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">{{ bankLabels.bik }}</label>
                                <input
                                    v-model="accountForm.bik"
                                    type="text"
                                    placeholder="044525225"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                />
                                <p v-if="bikLookupState === 'loading'" class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 flex items-center gap-1">
                                    <i class="ri-loader-4-line animate-spin"></i> Ищем банк по БИК...
                                </p>
                                <p v-else-if="bikLookupState === 'found'" class="text-xs text-success mt-1.5 flex items-center gap-1">
                                    <i class="ri-checkbox-circle-line"></i> Банк найден и подставлен автоматически
                                </p>
                                <p v-else-if="bikLookupState === 'not_found'" class="text-xs text-danger mt-1.5 flex items-center gap-1">
                                    <i class="ri-error-warning-line"></i> БИК не найден — проверьте номер
                                </p>
                                <p v-else-if="bikLookupState === 'not_configured'" class="text-xs text-warning mt-1.5 flex items-center gap-1">
                                    <i class="ri-error-warning-line"></i> Автозаполнение недоступно — администратор платформы не подключил DaData, заполните банк и корсчёт вручную
                                </p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                    Наименование банка
                                    <span v-if="bikLookupState !== 'not_configured'" class="text-gray-400 font-normal">(автоматически по БИК)</span>
                                </label>
                                <input
                                    v-model="accountForm.bank_name"
                                    type="text"
                                    :readonly="bikLookupState !== 'not_configured'"
                                    placeholder="Заполнится автоматически по БИК"
                                    :class="[bikLookupState !== 'not_configured' ? 'bg-gray-50 dark:bg-gray-800/60 cursor-not-allowed text-gray-500 dark:text-gray-400' : 'bg-transparent text-gray-800 dark:text-gray-200', 'block w-full rounded-md border border-gray-200 dark:border-gray-700 py-2 px-3 text-sm focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500']"
                                />
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                        {{ bankLabels.corr_account }}
                                        <span v-if="bikLookupState !== 'not_configured'" class="text-gray-400 font-normal">(авто)</span>
                                    </label>
                                    <input
                                        v-model="accountForm.corr_account"
                                        type="text"
                                        :readonly="bikLookupState !== 'not_configured'"
                                        placeholder="30101810400000000225"
                                        :class="[bikLookupState !== 'not_configured' ? 'bg-gray-50 dark:bg-gray-800/60 cursor-not-allowed text-gray-500 dark:text-gray-400' : 'bg-transparent text-gray-800 dark:text-gray-200', 'block w-full rounded-md border border-gray-200 dark:border-gray-700 py-2 px-3 text-sm focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500']"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">{{ bankLabels.account_number }}</label>
                                    <input
                                        v-model="accountForm.account_number"
                                        type="text"
                                        placeholder="40702810938000001234"
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                    />
                                </div>
                            </div>
                        </template>

                        <div v-else-if="accountForm.type === 'acquiring'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Комиссия банка за прием карт (%) <span class="text-danger">*</span></label>
                            <input
                                v-model="accountForm.commission_percent"
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                required
                                placeholder="2.5"
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                            />
                            <p class="text-xs text-gray-500 mt-1">При каждой оплате картой с этого счета будет автоматически списываться расход на сумму комиссии.</p>
                        </div>

                        <!-- Toggle Switch (Attex Style) -->
                        <div class="flex items-center pt-2">
                            <div @click="accountForm.is_default_for_invoicing = !accountForm.is_default_for_invoicing" :class="[accountForm.is_default_for_invoicing ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[accountForm.is_default_for_invoicing ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="accountForm.is_default_for_invoicing = !accountForm.is_default_for_invoicing">
                                Основной для документов
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeAccountModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                            Отмена
                        </button>
                        <button type="submit" :disabled="accountForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            Сохранить счет
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>