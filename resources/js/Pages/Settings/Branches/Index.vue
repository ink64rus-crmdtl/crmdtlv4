<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import DataTable from '@/Components/DataTable.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import WorkingHoursEditor from '@/Components/WorkingHoursEditor.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, useForm, Link, router } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import axios from 'axios';

const props = defineProps({
    branchesList: Object,
    filters: Object,
    timezones: { type: Array, default: () => [] },
    tenantTimezone: { type: String, default: 'UTC' },
    defaultWorkingHours: { type: Array, default: () => null },
});

// Режим работы считается настроенным, когда задано глобальное расписание
// по умолчанию (массив из 7 дней). Пока его нет — показываем напоминание.
const hasWorkingHours = computed(() => Array.isArray(props.defaultWorkingHours) && props.defaultWorkingHours.length > 0);

const isModalOpen = ref(false);
const editingBranch = ref(null);

const form = useForm({
    name: '',
    address: '',
    city: '',
    phone: '',
    timezone: '',
    is_active: true,
    working_hours: null,
    logo: null,
    remove_logo: false,
});

// --- Логотип локации (jpg/png, автоматически обрезается сервером до 300×300) ---
const logoInput = ref(null);
const logoPreviewUrl = ref(null);

const pickLogo = () => logoInput.value?.click();

const onLogoSelected = (e) => {
    const file = e.target.files[0];
    if (!file) return;

    if (!['image/jpeg', 'image/png'].includes(file.type)) {
        alert('Логотип принимается только в формате JPG или PNG.');
        e.target.value = '';
        return;
    }

    form.logo = file;
    form.remove_logo = false;
    logoPreviewUrl.value = URL.createObjectURL(file);
};

const removeLogo = () => {
    form.logo = null;
    form.remove_logo = true;
    logoPreviewUrl.value = null;
    if (logoInput.value) logoInput.value.value = '';
};

// Свои часы работы для локации (иначе действует расписание по умолчанию всего детейлинг-центра)
const useCustomHours = ref(false);

// Глобальные часы работы по умолчанию (наследуются всеми локациями без своего расписания)
const defaultHoursForm = useForm({
    default_working_hours: props.defaultWorkingHours || null,
});

const saveDefaultWorkingHours = () => {
    defaultHoursForm.post(route('settings.branches.default-working-hours'), {
        preserveScroll: true,
    });
};

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const fetchFiltered = useDebounceFn(() => {
    router.get(route('settings.branches.index'), {
        search: search.value,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());

const { sort, onSort } = useServerSort('settings.branches.index', () => props.filters, () => ({ search: search.value }));
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
// select-all/сброс выбора теперь считает сам DataTable (v-model="selectedIds").
const selectedIds = ref([]);

// legal_entities (связь) — не сортируется простым orderBy.
const branchColumns = [
    { key: 'name', label: 'Название', sortable: true },
    { key: 'legal_entities', label: 'Юрлицо' },
    { key: 'address', label: 'Адрес', sortable: true },
    { key: 'phone', label: 'Телефон', sortable: true },
    { key: 'is_active', label: 'Статус', sortable: true },
];

const bulkDelete = () => {
    if (confirm(`Удалить выбранные локации (${selectedIds.value.length})?`)) {
        router.post(route('settings.branches.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('settings.branches.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `branches_export_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
    } catch (error) {
        console.error("Export failed", error);
        alert("Ошибка при экспорте данных");
    }
};
// ----------------------------------------

const openModal = (branch = null) => {
    editingBranch.value = branch;
    if (branch) {
        form.name = branch.name;
        form.address = branch.address || '';
        form.city = branch.city || '';
        form.phone = branch.phone || '';
        form.timezone = branch.timezone || props.tenantTimezone;
        form.is_active = Boolean(branch.is_active);
        form.working_hours = branch.working_hours || null;
        useCustomHours.value = !!branch.working_hours;
        form.logo = null;
        form.remove_logo = false;
        logoPreviewUrl.value = branch.logo_url || null;
    } else {
        form.reset();
        form.is_active = true;
        form.working_hours = null;
        // Новый тенант: если часовой пояс ещё не выбран — подставляем пояс
        // тенанта (обязателен при регистрации), а не оставляем пустым.
        form.timezone = props.tenantTimezone;
        useCustomHours.value = false;
        logoPreviewUrl.value = null;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingBranch.value = null;
    form.reset();
    form.clearErrors();
    // Сбрасываем transform, который submit() навешивает на PUT (_method-spoofing
    // для загрузки файла) — иначе он молча "прилипает" и следующее создание
    // новой локации (POST) уедет с чужим _method=put.
    form.transform(data => data);
    useCustomHours.value = false;
    logoPreviewUrl.value = null;
    if (logoInput.value) logoInput.value.value = '';
};

const buildDefaultSchedule = () => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'].map(day => ({
    day,
    is_open: day !== 'sun',
    open: '09:00',
    close: '20:00',
}));

watch(useCustomHours, (value) => {
    if (!value) {
        form.working_hours = null;
    } else if (!form.working_hours) {
        form.working_hours = buildDefaultSchedule();
    }
});

const submit = () => {
    if (editingBranch.value) {
        form.transform(data => ({ ...data, _method: 'put' })).post(route('settings.branches.update', editingBranch.value.id), {
            forceFormData: true,
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('settings.branches.store'), {
            forceFormData: true,
            onSuccess: () => closeModal(),
        });
    }
};

const deleteBranch = (branch) => {
    if (confirm(`Удалить локацию "${branch.name}"?`)) {
        form.delete(route('settings.branches.destroy', branch.id));
    }
};
</script>

<template>
    <Head title="Локации" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <!-- Навигация по настройкам (Attex Tabs) -->
            <SettingsNav />

            <!-- Page Helper (Система подсказок) -->
            <PageHelper title="Что такое Локация?">
                <p><strong>Локация</strong> — это физический адрес или подразделение, оказывающее услуги: главная, основная единица системы. Именно к локации привязываются сотрудники, локальные склады, расписание записей, заказы и автомобили в работе.</p>
                <p>Юридические лица (реквизиты для документов) привязываются к локации, а не наоборот: одна локация может выставлять документы от нескольких юрлиц сразу (например, часть заказов — от ИП, часть — от ООО). Привязка настраивается со стороны юрлица — см. Настройки → Юридические лица.</p>
            </PageHelper>

            <!-- Header Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Локации</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Управление физическими локациями обслуживания клиентов
                    </p>
                </div>
            </div>

            <!-- Напоминание: режим работы обязателен (настройка ниже, у якоря #working-hours) -->
            <div v-if="!hasWorkingHours" class="flex items-start gap-3 bg-info/5 border border-info/20 rounded-md p-4">
                <i class="ri-time-line text-info text-lg mt-0.5"></i>
                <p class="text-sm text-gray-700 dark:text-gray-300">
                    Обязательно настройте режим работы — без него записи и расписание локаций будут некорректны.
                    <a href="#working-hours" class="text-primary font-medium hover:underline inline-flex items-center gap-1">
                        Перейти к настройке времени
                        <i class="ri-arrow-down-line"></i>
                    </a>
                </p>
            </div>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions
                v-if="selectedIds.length > 0"
                :selectedCount="selectedIds.length"
                noun="локаций"
                @export="bulkExport"
                @delete="bulkDelete"
            />

            <!-- Table Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="false"
                    placeholder="Поиск по названию, городу, телефону..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Добавить локацию
                        </button>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="branchColumns"
                        :rows="branchesList.data"
                        selectable
                        v-model="selectedIds"
                        has-actions
                        :sort="sort"
                        @sort="onSort"
                        empty-message="Локации не найдены."
                    >
                        <template #cell-name="{ row: branch }">
                            <div class="flex items-center gap-2">
                                <img v-if="branch.logo_url" :src="branch.logo_url" alt="" class="w-6 h-6 rounded object-cover shrink-0" />
                                <i v-else class="ri-store-2-line text-primary"></i>
                                {{ branch.name }}
                            </div>
                        </template>
                        <template #cell-legal_entities="{ row: branch }">
                            <div v-if="branch.legal_entities && branch.legal_entities.length > 0" class="flex flex-wrap gap-1">
                                <span v-for="le in branch.legal_entities" :key="le.id" class="inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                    <i class="ri-bank-line"></i> {{ le.name }}
                                </span>
                            </div>
                            <span v-else class="text-gray-400 dark:text-gray-500 text-xs" title="Настраивается со стороны юрлица — см. Настройки → Юридические лица">Без юрлица</span>
                        </template>
                        <template #cell-address="{ row: branch }">{{ branch.city ? branch.city + ', ' : '' }}{{ branch.address || '—' }}</template>
                        <template #cell-phone="{ row: branch }">{{ branch.phone || '—' }}</template>
                        <template #cell-is_active="{ row: branch }">
                            <span
                                :class="[
                                    branch.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger',
                                    'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium'
                                ]"
                            >
                                {{ branch.is_active ? 'Активно' : 'Неактивно' }}
                            </span>
                        </template>
                        <template #actions="{ row: branch }">
                            <button
                                @click="openModal(branch)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                title="Редактировать"
                            >
                                <i class="ri-pencil-line"></i>
                            </button>
                            <button
                                @click="deleteBranch(branch)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                title="Удалить"
                            >
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="branchesList" />
            </div>

            <!-- Глобальные часы работы (по умолчанию для всех локаций) — якорь #working-hours -->
            <div id="working-hours" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 scroll-mt-20">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-800 dark:text-gray-200">Часы работы по умолчанию</h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                            По умолчанию это время наследуется всеми локациями. Для конкретной локации его можно изменить индивидуально — при редактировании локации включите «Свои часы работы».
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="saveDefaultWorkingHours"
                        :disabled="defaultHoursForm.processing"
                        class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50 shrink-0"
                    >
                        <span v-if="defaultHoursForm.processing">Сохранение...</span>
                        <span v-else>Сохранить</span>
                    </button>
                </div>
                <WorkingHoursEditor v-model="defaultHoursForm.default_working_hours" />
            </div>
        </div>

        <!-- Модальное окно (Attex Standard: 50% width) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl lg:max-w-3xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingBranch ? 'Редактирование локации' : 'Новая локация' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div v-if="editingBranch" class="p-3 rounded-md bg-info/5 border border-info/20 text-xs text-gray-600 dark:text-gray-400">
                            <i class="ri-information-line text-info mr-1"></i>
                            Юрлица этой локации:
                            <span v-if="editingBranch.legal_entities?.length">{{ editingBranch.legal_entities.map(le => le.name).join(', ') }}</span>
                            <span v-else>ни одного</span>
                            — настраивается со стороны юрлица, см. Настройки → Юридические лица.
                        </div>

                        <div class="flex items-center gap-4">
                            <div class="w-20 h-20 rounded-md border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex items-center justify-center overflow-hidden shrink-0">
                                <img v-if="logoPreviewUrl" :src="logoPreviewUrl" alt="Логотип локации" class="w-full h-full object-cover" />
                                <i v-else class="ri-store-2-line text-3xl text-gray-300 dark:text-gray-600"></i>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Логотип локации</label>
                                <input ref="logoInput" type="file" accept="image/jpeg,image/png" class="hidden" @change="onLogoSelected" />
                                <div class="flex gap-2">
                                    <button type="button" @click="pickLogo" class="inline-flex items-center justify-center rounded-md px-3 py-1.5 text-xs font-medium bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <i class="ri-upload-2-line mr-1"></i> {{ logoPreviewUrl ? 'Заменить' : 'Загрузить' }}
                                    </button>
                                    <button v-if="logoPreviewUrl" type="button" @click="removeLogo" class="inline-flex items-center justify-center rounded-md px-3 py-1.5 text-xs font-medium bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-danger hover:bg-danger/5">
                                        Удалить
                                    </button>
                                </div>
                                <p class="text-xs text-gray-400 mt-1">JPG или PNG, автоматически обрезается до 300×300</p>
                                <span v-if="form.errors.logo" class="text-xs text-danger mt-1 block">{{ form.errors.logo }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название локации <span class="text-danger">*</span></label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    required
                                    placeholder="Центральный детейлинг"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                />
                                <span v-if="form.errors.name" class="text-xs text-danger mt-1">{{ form.errors.name }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Город</label>
                                <input
                                    v-model="form.city"
                                    type="text"
                                    placeholder="Москва"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Адрес</label>
                                <input
                                    v-model="form.address"
                                    type="text"
                                    placeholder="ул. Ленина, 1"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Телефон локации</label>
                                <input
                                    v-model="form.phone"
                                    type="text"
                                    placeholder="+7 (999) 000-00-00"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                                />
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Часовой пояс</label>
                            <SearchableSelect
                                v-model="form.timezone"
                                :options="timezones"
                                placeholder="Выберите часовой пояс..."
                                search-placeholder="Поиск пояса..."
                                clearable
                            />
                        </div>

                        <!-- Часы работы -->
                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                            <div class="flex items-center mb-3">
                                <div @click="useCustomHours = !useCustomHours" :class="[useCustomHours ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                    <div :class="[useCustomHours ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-2.5 block text-sm font-semibold text-gray-800 dark:text-gray-200 cursor-pointer" @click="useCustomHours = !useCustomHours">
                                    Свои часы работы для этой локации
                                </label>
                            </div>
                            <p v-if="!useCustomHours" class="text-xs text-gray-500 dark:text-gray-400 mb-2">Действует расписание по умолчанию всего детейлинг-центра (см. выше на этой странице).</p>
                            <WorkingHoursEditor v-if="useCustomHours" v-model="form.working_hours" />
                        </div>

                        <!-- Toggle Switch (Attex Style) -->
                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Локация активна
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
            </div>
        </div>
    </AuthenticatedLayout>
</template>