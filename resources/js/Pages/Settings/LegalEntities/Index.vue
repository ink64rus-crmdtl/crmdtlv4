<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
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
            <!-- Header Card (Admindek Card Style) -->
            <div class="flex justify-between items-center bg-white p-6 rounded-xl shadow-xs border border-slate-200">
                <div>
                    <h1 class="text-xl font-bold text-slate-800 tracking-tight">Юридические лица и Реквизиты</h1>
                    <p class="text-xs text-slate-500 mt-1">
                        Управление юридическими лицами компании и их банковскими счетами
                    </p>
                </div>
                <button
                    @click="openModal()"
                    class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-600/90 transition-colors shadow-xs"
                >
                    <span class="text-sm font-bold">+</span>
                    Добавить юрлицо
                </button>
            </div>

            <!-- Table Card (Admindek Table Style) -->
            <div class="bg-white rounded-xl shadow-xs border border-slate-200 overflow-hidden">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="bg-slate-50 text-slate-500 font-bold uppercase text-xs tracking-wider">
                        <tr>
                            <th class="px-6 py-4">Название</th>
                            <th class="px-6 py-4">Юрисдикция</th>
                            <th class="px-6 py-4">Налоговый номер</th>
                            <th class="px-6 py-4">Расчетные счета</th>
                            <th class="px-6 py-4">Статус</th>
                            <th class="px-6 py-4 text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-slate-600">
                        <tr v-for="entity in legalEntities" :key="entity.id" class="hover:bg-slate-50/80 transition-colors">
                            <td class="px-6 py-4 font-bold text-slate-800">{{ entity.name }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium bg-slate-100 text-slate-700 border border-slate-200">
                                    {{ countryConfig?.name || tenantCountry }}
                                </span>
                            </td>
                            <!-- Цифры строго в Nunito Sans -->
                            <td class="px-6 py-4 text-sm text-slate-600">{{ entity.tax_id || '—' }}</td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center gap-1.5 font-medium text-xs text-slate-600">
                                    💳 {{ entity.accounts?.length || 0 }} сч.
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div
                                    :class="[
                                        entity.is_active ? 'border-transparent bg-emerald-600 text-white' : 'border-transparent bg-rose-500 text-white',
                                        'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium transition-colors shadow-xs'
                                    ]"
                                >
                                    {{ entity.is_active ? 'Активно' : 'Неактивно' }}
                                </div>
                            </td>
                            <td class="px-6 py-4 text-right space-x-2">
                                <button 
                                    @click="openModal(entity)" 
                                    class="rounded px-2.5 py-1 text-xs font-medium capitalize transition-colors bg-indigo-600 text-white hover:bg-indigo-600/90"
                                >
                                    Редактировать
                                </button>
                                <button 
                                    @click="deleteEntity(entity)" 
                                    class="rounded px-2.5 py-1 text-xs font-medium capitalize transition-colors bg-rose-600 text-white hover:bg-rose-600/90"
                                >
                                    Удалить
                                </button>
                            </td>
                        </tr>
                        <tr v-if="legalEntities.length === 0">
                            <td colspan="6" class="px-6 py-12 text-center text-slate-400 text-xs">
                                Юридические лица еще не добавлены. Нажмите "Добавить юрлицо".
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Модальное окно Юрлица (50% ширины на десктопе, max-w-2xl/3xl) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/75 backdrop-blur-sm flex items-center justify-center p-4 sm:p-6">
            <div class="bg-white rounded-xl w-full sm:max-w-2xl lg:max-w-3xl shadow-2xl border border-slate-200 overflow-hidden transition-all my-8 mx-auto">
                
                <!-- Модальная Шапка и Вкладки (Midnight Slate Header) -->
                <div class="bg-slate-900 text-white pt-6 pb-0">
                    <div class="flex justify-between items-center mb-5 px-6">
                        <h3 class="text-base font-bold tracking-tight">
                            {{ editingEntity ? 'Редактирование юридического лица' : 'Новое юридическое лицо' }}
                        </h3>
                        <button @click="closeModal()" class="text-slate-400 hover:text-white text-base font-bold transition-colors px-2">✕</button>
                    </div>

                    <!-- Стандартизированные Вкладки Admindek -->
                    <div class="flex space-x-6 border-b border-slate-800 px-6" v-if="editingEntity">
                        <button
                            type="button"
                            @click="activeTab = 'main'"
                            :class="[
                                activeTab === 'main' ? 'border-indigo-500 text-indigo-400 font-bold border-b-2' : 'border-transparent text-slate-400 hover:text-slate-200 font-medium border-b-2',
                                'py-3 px-2 text-xs transition-colors focus:outline-none'
                            ]"
                        >
                            Основная информация
                        </button>
                        <button
                            type="button"
                            @click="activeTab = 'accounts'"
                            :class="[
                                activeTab === 'accounts' ? 'border-indigo-500 text-indigo-400 font-bold border-b-2' : 'border-transparent text-slate-400 hover:text-slate-200 font-medium border-b-2',
                                'py-3 px-2 text-xs transition-colors flex items-center gap-2 focus:outline-none'
                            ]"
                        >
                            <span>Расчетные счета</span>
                            <span class="bg-slate-800 text-slate-200 text-xs px-2 py-0.5 rounded-full font-semibold">
                                {{ editingEntity.accounts?.length || 0 }}
                            </span>
                        </button>
                    </div>
                </div>

                <!-- Содержимое Вкладки 1: Основная информация -->
                <form v-if="activeTab === 'main'" @submit.prevent="submitEntity" class="p-6 space-y-5">
                    
                    <div class="flex items-center justify-between bg-slate-50 p-3.5 rounded-lg border border-slate-200">
                        <span class="text-xs font-bold text-slate-500 uppercase tracking-wider">Страна юрисдикции:</span>
                        <span class="text-xs font-bold text-slate-800">{{ countryConfig?.name }} ({{ tenantCountry }})</span>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Официальное название (ИП / ООО)</label>
                        <input 
                            v-model="form.name" 
                            type="text" 
                            required 
                            placeholder="ООО Детейлинг Групп" 
                            class="block w-full rounded-md border-slate-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-xs py-2 px-3 text-slate-700 bg-white placeholder-slate-400" 
                        />
                    </div>

                    <!-- Динамические реквизиты под юрисдикцию тенанта -->
                    <div class="border-t border-slate-200 pt-5 mt-4">
                        <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-4">
                            Налоговые реквизиты ({{ countryConfig?.name }})
                        </h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div v-for="field in currentCountrySchema" :key="field.key" :class="field.type === 'textarea' ? 'sm:col-span-2' : ''">
                                <label class="block text-xs font-semibold text-slate-600 mb-1.5">
                                    {{ field.label }} <span v-if="field.required" class="text-rose-500">*</span>
                                </label>

                                <textarea
                                    v-if="field.type === 'textarea'"
                                    v-model="form.requisites[field.key]"
                                    :required="field.required"
                                    :placeholder="field.placeholder"
                                    rows="2"
                                    class="block w-full rounded-md border-slate-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-xs py-2 px-3 text-slate-700 bg-white placeholder-slate-400"
                                ></textarea>

                                <input
                                    v-else
                                    v-model="form.requisites[field.key]"
                                    :type="field.type"
                                    :required="field.required"
                                    :placeholder="field.placeholder"
                                    class="block w-full rounded-md border-slate-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-xs py-2 px-3 text-slate-700 bg-white placeholder-slate-400"
                                />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center pt-2">
                        <input id="is_active" v-model="form.is_active" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                        <label for="is_active" class="ml-2.5 block text-xs font-semibold text-slate-700">Активно для работы</label>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4 mt-6">
                        <button 
                            type="button" 
                            @click="closeModal()" 
                            class="rounded px-3 py-1.5 text-xs font-medium transition-colors bg-slate-100 text-slate-700 hover:bg-slate-200"
                        >
                            Отмена
                        </button>
                        <button 
                            type="submit" 
                            :disabled="form.processing" 
                            class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-600/90 transition-colors shadow-xs disabled:opacity-50"
                        >
                            Сохранить
                        </button>
                    </div>
                </form>

                <!-- Содержимое Вкладки 2: Расчетные счета -->
                <div v-if="activeTab === 'accounts'" class="p-6 space-y-4">
                    <div class="flex justify-between items-center mb-4">
                        <p class="text-xs text-slate-500 font-medium">Банковские счета компании</p>
                        <button
                            type="button"
                            @click="openAccountModal()"
                            class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-indigo-600/90 transition-colors shadow-xs"
                        >
                            <span class="text-sm font-bold">+</span>
                            Добавить счет
                        </button>
                    </div>

                    <div class="space-y-3 max-h-80 overflow-y-auto pr-1">
                        <div
                            v-for="account in editingEntity?.accounts"
                            :key="account.id"
                            class="p-3.5 border rounded-lg bg-slate-50/50 border-slate-200 flex justify-between items-center shadow-2xs"
                        >
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-slate-800 text-xs">{{ account.name }}</span>
                                    <div v-if="account.is_default_for_invoicing" class="inline-flex items-center rounded-md border border-transparent px-2 py-0.5 text-xs font-medium bg-indigo-100 text-indigo-800">
                                        Основной для документов
                                    </div>
                                </div>
                                <p class="text-xs text-slate-500 mt-1">
                                    <strong class="text-slate-700 font-medium">{{ account.bank_name || 'Банк не указан' }}</strong> |
                                    {{ bankLabels.bik }}: {{ account.bik || '—' }} |
                                    Счет: <span class="text-xs text-slate-700 font-semibold">{{ account.account_number || '—' }}</span>
                                </p>
                            </div>
                            <div class="flex gap-2">
                                <button 
                                    type="button" 
                                    @click="openAccountModal(account)" 
                                    class="rounded px-2.5 py-1 text-xs font-medium capitalize transition-colors bg-indigo-600 text-white hover:bg-indigo-600/90"
                                >
                                    Правка
                                </button>
                                <button 
                                    type="button" 
                                    @click="deleteAccount(account)" 
                                    class="rounded px-2.5 py-1 text-xs font-medium capitalize transition-colors bg-rose-600 text-white hover:bg-rose-600/90"
                                >
                                    Удалить
                                </button>
                            </div>
                        </div>

                        <div v-if="!editingEntity?.accounts || editingEntity?.accounts.length === 0" class="text-center py-10 text-slate-400 text-xs">
                            Счета еще не добавлены. Нажмите "Добавить счет".
                        </div>
                    </div>

                    <div class="flex justify-end border-t border-slate-200 pt-4 mt-6">
                        <button 
                            type="button" 
                            @click="closeModal()" 
                            class="rounded px-3 py-1.5 text-xs font-medium transition-colors bg-slate-100 text-slate-700 hover:bg-slate-200"
                        >
                            Закрыть
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <!-- Под-модальное окно создания / редактирования Счета (50% ширины) -->
        <div v-if="isAccountModalOpen" class="fixed inset-0 z-50 overflow-y-auto bg-slate-950/80 backdrop-blur-xs flex items-center justify-center p-4 sm:p-6">
            <div class="bg-white rounded-xl w-full sm:max-w-xl lg:max-w-2xl shadow-2xl border border-slate-200 overflow-hidden transition-all my-8 mx-auto">
                <div class="px-6 py-4 bg-slate-900 text-white flex justify-between items-center">
                    <h3 class="text-sm font-bold tracking-tight">
                        {{ editingAccount ? 'Редактирование расчетного счета' : 'Добавление расчетного счета' }}
                    </h3>
                    <button type="button" @click="closeAccountModal()" class="text-slate-400 hover:text-white text-sm font-bold transition-colors px-2">✕</button>
                </div>

                <form @submit.prevent="submitAccount" class="p-6 space-y-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Название счета (для внутренних документов)</label>
                        <input 
                            v-model="accountForm.name" 
                            type="text" 
                            required 
                            placeholder="Основной р/с в Сбербанке" 
                            class="block w-full rounded-md border-slate-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-xs py-2 px-3 text-slate-700 bg-white placeholder-slate-400" 
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">Наименование банка</label>
                        <input 
                            v-model="accountForm.bank_name" 
                            type="text" 
                            placeholder="ПАО Сбербанк" 
                            class="block w-full rounded-md border-slate-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-xs py-2 px-3 text-slate-700 bg-white placeholder-slate-400" 
                        />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">{{ bankLabels.bik }}</label>
                            <input 
                                v-model="accountForm.bik" 
                                type="text" 
                                placeholder="044525225" 
                                class="block w-full rounded-md border-slate-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-xs py-2 px-3 text-slate-700 bg-white placeholder-slate-400" 
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 mb-1.5">{{ bankLabels.corr_account }}</label>
                            <input 
                                v-model="accountForm.corr_account" 
                                type="text" 
                                placeholder="30101810400000000225" 
                                class="block w-full rounded-md border-slate-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-xs py-2 px-3 text-slate-700 bg-white placeholder-slate-400" 
                            />
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-600 mb-1.5">{{ bankLabels.account_number }}</label>
                        <input 
                            v-model="accountForm.account_number" 
                            type="text" 
                            placeholder="40702810938000001234" 
                            class="block w-full rounded-md border-slate-300 shadow-xs focus:border-indigo-500 focus:ring-indigo-500 text-xs py-2 px-3 text-slate-700 bg-white placeholder-slate-400" 
                        />
                    </div>

                    <div class="pt-2">
                        <div class="flex items-center">
                            <input id="is_default_for_invoicing" v-model="accountForm.is_default_for_invoicing" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                            <label for="is_default_for_invoicing" class="ml-2.5 block text-xs font-semibold text-slate-700">
                                Основной для документов
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-slate-200 pt-4 mt-6">
                        <button 
                            type="button" 
                            @click="closeAccountModal()" 
                            class="rounded px-3 py-1.5 text-xs font-medium transition-colors bg-slate-100 text-slate-700 hover:bg-slate-200"
                        >
                            Отмена
                        </button>
                        <button 
                            type="submit" 
                            :disabled="accountForm.processing" 
                            class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-3.5 py-1.5 text-xs font-medium text-white hover:bg-indigo-600/90 transition-colors shadow-xs disabled:opacity-50"
                        >
                            Сохранить счет
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>