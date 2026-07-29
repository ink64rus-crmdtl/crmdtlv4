<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    legalEntities: Array,
    tenantCountry: String,
    countryConfig: Object,
});

const isModalOpen = ref(false);
const editingEntityId = ref(null);
const activeTab = ref('main'); // 'main' or 'accounts'

// Реактивное вычисление текущего юрлица для мгновенного обновления счетов
const editingEntity = computed(() => {
    if (!editingEntityId.value) return null;
    return props.legalEntities.find(e => e.id === editingEntityId.value) || null;
});

// Состояние под-модалки счетов
const isAccountModalOpen = ref(false);
const editingAccount = ref(null);

const form = useForm({
    name: '',
    tax_id: '',
    requisites: {},
    is_active: true,
});

const accountForm = useForm({
    legal_entity_id: null,
    name: '',
    type: 'bank',
    bank_name: '',
    bik: '',
    account_number: '',
    corr_account: '',
    is_default_for_invoicing: false,
    is_active: true,
});

const currentCountrySchema = computed(() => {
    return props.countryConfig?.requisite_schema || [];
});

const bankLabels = computed(() => {
    return props.countryConfig?.bank_labels || {
        bik: 'БИК / SWIFT',
        account_number: 'Расчетный счет / IBAN',
        corr_account: 'Корр. счет',
    };
});

const openModal = (entity = null) => {
    if (entity) {
        editingEntityId.value = entity.id;
        form.name = entity.name;
        form.tax_id = entity.tax_id || '';
        form.requisites = entity.requisites || {};
        form.is_active = Boolean(entity.is_active);
    } else {
        editingEntityId.value = null;
        form.reset();
        form.requisites = {};
        form.is_active = true;
    }
    activeTab.value = 'main';
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingEntityId.value = null;
    form.reset();
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
        accountForm.bank_name = account.bank_name || '';
        accountForm.bik = account.bik || '';
        accountForm.account_number = account.account_number || '';
        accountForm.corr_account = account.corr_account || '';
        accountForm.is_default_for_invoicing = Boolean(account.is_default_for_invoicing);
        accountForm.is_active = Boolean(account.is_active);
    } else {
        accountForm.reset();
        accountForm.legal_entity_id = editingEntityId.value;
        accountForm.type = 'bank';
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

        <div class="max-w-7xl mx-auto space-y-6 font-sans text-slate-600">
            
            <!-- Навигация по настройкам (Attex Tabs) -->
            <div class="border-b border-gray-200 dark:border-gray-700 mb-6">
                <nav class="-mb-px flex space-x-8 overflow-x-auto">
                    <Link
                        :href="route('settings.legal-entities.index')"
                        :class="[
                            route().current('settings.legal-entities.index')
                                ? 'border-primary text-primary'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600',
                            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors'
                        ]"
                    >
                        Юридические лица
                    </Link>
                    <Link
                        :href="route('settings.branches.index')"
                        :class="[
                            route().current('settings.branches.index')
                                ? 'border-primary text-primary'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600',
                            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors'
                        ]"
                    >
                        Филиалы
                    </Link>
                    <Link
                        :href="route('settings.business-directions.index')"
                        :class="[
                            route().current('settings.business-directions.index')
                                ? 'border-primary text-primary'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600',
                            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors'
                        ]"
                    >
                        Направления
                    </Link>
                    <Link
                        :href="route('settings.warehouse.index')"
                        :class="[
                            route().current('settings.warehouse.index')
                                ? 'border-primary text-primary'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600',
                            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors'
                        ]"
                    >
                        Склад
                    </Link>
                </nav>
            </div>

            <!-- Header Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Юридические лица и Реквизиты</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Управление юридическими лицами компании и их банковскими счетами
                    </p>
                </div>
                <button
                    @click="openModal()"
                    class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5"
                >
                    <i class="ri-add-line text-base"></i>
                    Добавить юрлицо
                </button>
            </div>

            <!-- Table Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Юрисдикция</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Налоговый номер</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Счета</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статус</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="entity in legalEntities" :key="entity.id" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50 font-semibold">{{ entity.name }}</td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <span class="inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                        {{ countryConfig?.name || tenantCountry }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ entity.tax_id || '—' }}</td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <span class="inline-flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-400">
                                        <i class="ri-bank-card-line text-sm"></i> {{ entity.accounts?.length || 0 }} сч.
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <span
                                        :class="[
                                            entity.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger',
                                            'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium'
                                        ]"
                                    >
                                        {{ entity.is_active ? 'Активно' : 'Неактивно' }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
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
                                </td>
                            </tr>
                            <tr v-if="legalEntities.length === 0">
                                <td colspan="6" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Юридические лица еще не добавлены. Нажмите "Добавить юрлицо".
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Модальное окно Юрлица (Attex Standard: 50% width, Card Styling) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl lg:max-w-3xl my-8 mx-auto flex flex-col">
                
                <!-- Модальная Шапка (Attex Theme) -->
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingEntity ? 'Редактирование юридического лица' : 'Новое юридическое лицо' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <!-- Вкладки -->
                <div class="flex space-x-6 border-b border-gray-200 dark:border-gray-700 px-6" v-if="editingEntity">
                    <button
                        type="button"
                        @click="activeTab = 'main'"
                        :class="[
                            activeTab === 'main' ? 'border-primary text-primary' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300',
                            'py-3 border-b-2 font-medium text-sm transition-colors focus:outline-none'
                        ]"
                    >
                        Основная информация
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'accounts'"
                        :class="[
                            activeTab === 'accounts' ? 'border-primary text-primary' : 'border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300',
                            'py-3 border-b-2 font-medium text-sm transition-colors flex items-center gap-2 focus:outline-none'
                        ]"
                    >
                        <span>Расчетные счета</span>
                        <span class="bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300 text-xs px-2 py-0.5 rounded-full font-medium">
                            {{ editingEntity.accounts?.length || 0 }}
                        </span>
                    </button>
                </div>

                <!-- Содержимое Вкладки 1: Основная информация -->
                <form v-if="activeTab === 'main'" @submit.prevent="submitEntity" class="flex flex-col">
                    <div class="p-6 space-y-5">
                        
                        <!-- Инфо блок юрисдикции -->
                        <div class="flex items-center justify-between bg-light dark:bg-gray-800/50 p-3 rounded-md border border-gray-200 dark:border-gray-700/50">
                            <span class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Страна юрисдикции:</span>
                            <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ countryConfig?.name }} ({{ tenantCountry }})</span>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Официальное название (ИП / ООО)</label>
                            <input 
                                v-model="form.name" 
                                type="text" 
                                required 
                                placeholder="ООО Детейлинг Групп" 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                            />
                        </div>

                        <!-- Динамические реквизиты под юрисдикцию тенанта -->
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-5 mt-2">
                            <h4 class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-4">
                                Налоговые реквизиты ({{ countryConfig?.name }})
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
                        <div class="flex items-center pt-2">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Активно для работы
                            </label>
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
                <div v-if="activeTab === 'accounts'" class="flex flex-col h-full">
                    <div class="p-6 space-y-4 flex-1">
                        <div class="flex justify-between items-center mb-2">
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Банковские счета компании</p>
                            <button
                                type="button"
                                @click="openAccountModal()"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-sm font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white gap-1.5"
                            >
                                <i class="ri-add-line"></i> Добавить счет
                            </button>
                        </div>

                        <div class="space-y-3 max-h-80 overflow-y-auto pr-1">
                            <div
                                v-for="account in editingEntity?.accounts"
                                :key="account.id"
                                class="p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-light dark:bg-[#2d333c] flex justify-between items-center transition-colors"
                            >
                                <div>
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-semibold text-gray-800 dark:text-gray-200 text-sm">{{ account.name }}</span>
                                        <span v-if="account.is_default_for_invoicing" class="inline-flex items-center gap-1.5 py-0.5 px-1.5 rounded text-xs font-medium bg-info/10 text-info">
                                            Основной для документов
                                        </span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        <strong class="text-gray-700 dark:text-gray-300 font-medium">{{ account.bank_name || 'Банк не указан' }}</strong> |
                                        {{ bankLabels.bik }}: {{ account.bik || '—' }} |
                                        Счет: <span class="text-gray-700 dark:text-gray-300">{{ account.account_number || '—' }}</span>
                                    </p>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <button type="button" @click="openAccountModal(account)" class="inline-flex items-center justify-center rounded px-2 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать">
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button type="button" @click="deleteAccount(account)" class="inline-flex items-center justify-center rounded px-2 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </div>
                            </div>

                            <div v-if="!editingEntity?.accounts || editingEntity?.accounts.length === 0" class="text-center py-10 text-gray-400 dark:text-gray-500 text-sm">
                                Счета еще не добавлены. Нажмите "Добавить счет".
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                            Закрыть
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- Под-модальное окно создания / редактирования Счета (Attex Standard: 50% width) -->
        <div v-if="isAccountModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl lg:max-w-3xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingAccount ? 'Редактирование расчетного счета' : 'Добавление расчетного счета' }}
                    </h3>
                    <button type="button" @click="closeAccountModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submitAccount" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название счета (для внутренних документов)</label>
                            <input 
                                v-model="accountForm.name" 
                                type="text" 
                                required 
                                placeholder="Основной р/с в Сбербанке" 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                            />
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Наименование банка</label>
                            <input 
                                v-model="accountForm.bank_name" 
                                type="text" 
                                placeholder="ПАО Сбербанк" 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                            />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">{{ bankLabels.bik }}</label>
                                <input 
                                    v-model="accountForm.bik" 
                                    type="text" 
                                    placeholder="044525225" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">{{ bankLabels.corr_account }}</label>
                                <input 
                                    v-model="accountForm.corr_account" 
                                    type="text" 
                                    placeholder="30101810400000000225" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
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

                        <div class="pt-2 flex items-center">
                            <div @click="accountForm.is_default_for_invoicing = !accountForm.is_default_for_invoicing" :class="[accountForm.is_default_for_invoicing ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[accountForm.is_default_for_invoicing ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="accountForm.is_default_for_invoicing = !accountForm.is_default_for_invoicing">
                                Основной для документов
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 mt-2 bg-gray-50/50 dark:bg-transparent">
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