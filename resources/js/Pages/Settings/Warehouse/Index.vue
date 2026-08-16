<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import Modal from '@/Components/Modal.vue';
import DataTable from '@/Components/DataTable.vue';
import { Head, useForm, Link, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const page = usePage();
import { useClientSort } from '@/Composables/useClientSort.js';

const props = defineProps({
    warehouseMode: String,
    warehouseEnabled: { type: Boolean, default: true },
    serviceMaterialAutoAddMode: { type: String, default: 'confirm' },
    warehouses: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
    coverageWarning: { type: String, default: null },
});

const form = useForm({
    warehouse_mode: props.warehouseMode || 'per_branch',
    warehouse_enabled: props.warehouseEnabled,
    service_material_auto_add_mode: props.serviceMaterialAutoAddMode || 'confirm',
});

const submit = () => {
    form.post(route('settings.warehouse.store'));
};

// --- Склады (CRUD) ---
const branchOptions = computed(() => props.branches.map(b => ({ value: b.id, label: b.name })));
const branchName = (id) => props.branches.find(b => b.id === id)?.name || '—';

const isWarehouseModalOpen = ref(false);
const editingWarehouse = ref(null);

const warehouseForm = useForm({
    name: '',
    owner_type: 'branch',
    owner_id: '',
    is_default: false,
    is_active: true,
});

const openWarehouseModal = (warehouse = null) => {
    editingWarehouse.value = warehouse;
    if (warehouse) {
        warehouseForm.name = warehouse.name;
        warehouseForm.owner_type = warehouse.owner_type;
        warehouseForm.owner_id = warehouse.owner_id || '';
        warehouseForm.is_default = Boolean(warehouse.is_default);
        warehouseForm.is_active = Boolean(warehouse.is_active);
    } else {
        warehouseForm.reset();
        // Смысловой дефолт по текущему режиму: в "Общий" резолвер вообще не
        // смотрит на склады точек (WarehouseResolver::resolveFor()), поэтому
        // предлагать "Склад точки" по умолчанию было бы вводящим в заблуждение —
        // в "Раздельном"/"Смешанном", наоборот, обычно нужен склад точки.
        warehouseForm.owner_type = props.warehouseMode === 'shared' ? 'company' : 'branch';
        warehouseForm.is_active = true;
    }
    isWarehouseModalOpen.value = true;
};

const warehouseModeLabels = {
    per_branch: 'Раздельный',
    shared: 'Общий',
    mixed: 'Смешанный',
};

// Тип склада, который резолвер СЕЙЧАС реально использует для текущего
// режима (см. WarehouseResolver::resolveFor()) — остальные типы можно
// создать (например, впрок перед сменой режима), но толку от них до
// переключения режима не будет, о чём и предупреждаем: в форме — заранее,
// в списке — для уже существующих складов, которые молча "не работают".
const isOwnerTypeUnusedInMode = (ownerType) => {
    if (props.warehouseMode === 'shared') return ownerType === 'branch';
    if (props.warehouseMode === 'per_branch') return ownerType === 'company';
    return false; // mixed — задействованы оба типа
};

const ownerTypeUnusedInCurrentMode = computed(() => isOwnerTypeUnusedInMode(warehouseForm.owner_type));

const closeWarehouseModal = () => {
    isWarehouseModalOpen.value = false;
    editingWarehouse.value = null;
    warehouseForm.reset();
    warehouseForm.clearErrors();
};

// Переключение типа владельца "на лету" чистит выбор точки — иначе при
// смене "Точка" → "Компания" отправился бы устаревший owner_id.
const setOwnerType = (type) => {
    warehouseForm.owner_type = type;
    if (type === 'company') {
        warehouseForm.owner_id = '';
    }
};

const submitWarehouse = () => {
    if (editingWarehouse.value) {
        warehouseForm.put(route('settings.warehouses.update', editingWarehouse.value.id), {
            onSuccess: () => closeWarehouseModal(),
        });
    } else {
        warehouseForm.post(route('settings.warehouses.store'), {
            onSuccess: () => closeWarehouseModal(),
        });
    }
};

const warehouseColumns = [
    { key: 'name', label: 'Название', sortable: true },
    { key: 'type', label: 'Тип', sortable: true, sortKey: 'owner_type' },
    { key: 'is_default', label: 'По умолчанию', sortable: true },
    { key: 'status', label: 'Статус', sortable: true, sortKey: 'is_active' },
];

const { sort, onSort, sortedRows: sortedWarehouses } = useClientSort(() => props.warehouses);

const deleteWarehouse = (warehouse) => {
    if (confirm(`Удалить склад "${warehouse.name}"?`)) {
        warehouseForm.delete(route('settings.warehouses.destroy', warehouse.id), {
            preserveScroll: true,
        });
    }
};
</script>

<template>
    <Head title="Настройки склада" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <!-- Навигация по настройкам (Attex Tabs) -->
            <SettingsNav />

            <!-- Page Helper (Система подсказок) -->
            <PageHelper title="Как работают режимы склада?">
                <p><strong>Раздельный:</strong> У каждой локации свой независимый склад. Остатки не пересекаются. Идеально для независимых студий.</p>
                <p><strong>Общий:</strong> Единый центральный склад на всю компанию. Все локации списывают материалы из одного места.</p>
                <p><strong>Смешанный:</strong> Комбинация. Расходники хранятся локально на локациях, а дорогие материалы — на центральном складе.</p>
            </PageHelper>

            <!-- Диагностика уже сохранённой конфигурации: локации/компания без склада,
                 который резолвер реально мог бы использовать в текущем режиме — не
                 блокирует ничего, просто вскрывает уже существующий разрыв заранее,
                 а не в момент завершения реального заказа. -->
            <div v-if="coverageWarning" class="bg-warning/10 border border-warning/30 rounded-md p-4 flex items-start gap-3">
                <i class="ri-error-warning-line text-warning text-lg shrink-0 mt-0.5"></i>
                <div>
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Текущая конфигурация склада неполна</p>
                    <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">{{ coverageWarning }}</p>
                </div>
            </div>

            <!-- Блок ошибок (удаление/деактивация склада, единственного покрывающего свою область) -->
            <div v-if="page.props.errors.error" class="p-4 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-start gap-3">
                <i class="ri-error-warning-fill text-xl shrink-0"></i>
                <div>
                    <p class="font-bold mb-1">Ошибка:</p>
                    <p>{{ page.props.errors.error }}</p>
                </div>
            </div>

            <!-- Header Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Режим работы склада</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Выберите архитектуру складского учета, подходящую для вашего бизнеса
                    </p>
                </div>
            </div>

            <!-- Content Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6">
                <form @submit.prevent="submit" class="space-y-6">

                    <!-- Тумблер: складской учёт целиком -->
                    <div class="flex items-start justify-between gap-4 p-4 rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <div>
                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">Складской учёт</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-xl">
                                Выключите, если бизнес не ведёт остатки физически (например, только услуги). Каталог товаров и их цены останутся доступны — их можно будет добавлять в заказ-наряды, — но приходные накладные, остатки, движения и списание материалов при завершении заказа вестись не будут.
                            </p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer shrink-0 mt-1">
                            <input type="checkbox" v-model="form.warehouse_enabled" class="sr-only peer" />
                            <div class="w-11 h-6 bg-gray-200 dark:bg-gray-700 rounded-full peer peer-checked:bg-primary transition-colors after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-5"></div>
                        </label>
                    </div>

                    <div
                        :class="['grid grid-cols-1 md:grid-cols-3 gap-6 transition-opacity', !form.warehouse_enabled ? 'opacity-50 pointer-events-none' : '']"
                        :title="!form.warehouse_enabled ? 'Складской учёт выключен — режим склада сейчас не используется' : undefined"
                    >
                        
                        <!-- Раздельный режим -->
                        <label 
                            :class="[
                                form.warehouse_mode === 'per_branch' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]',
                                'relative flex cursor-pointer rounded-lg border p-5 shadow-sm focus:outline-none transition-all'
                            ]"
                        >
                            <input type="radio" v-model="form.warehouse_mode" value="per_branch" class="sr-only" />
                            <span class="flex flex-1">
                                <span class="flex flex-col">
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white mb-1">
                                        <i class="ri-store-3-line text-primary mr-1"></i> Раздельный
                                    </span>
                                    <span class="mt-1 flex items-center text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                                        У каждой локации свой независимый склад. Остатки не пересекаются. Идеально для независимых студий.
                                    </span>
                                </span>
                            </span>
                            <i v-if="form.warehouse_mode === 'per_branch'" class="ri-checkbox-circle-fill text-primary text-xl absolute top-4 right-4"></i>
                        </label>

                        <!-- Общий режим -->
                        <label 
                            :class="[
                                form.warehouse_mode === 'shared' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]',
                                'relative flex cursor-pointer rounded-lg border p-5 shadow-sm focus:outline-none transition-all'
                            ]"
                        >
                            <input type="radio" v-model="form.warehouse_mode" value="shared" class="sr-only" />
                            <span class="flex flex-1">
                                <span class="flex flex-col">
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white mb-1">
                                        <i class="ri-building-4-line text-primary mr-1"></i> Общий
                                    </span>
                                    <span class="mt-1 flex items-center text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                                        Единый центральный склад на всю компанию. Все локации списывают материалы из одного места.
                                    </span>
                                </span>
                            </span>
                            <i v-if="form.warehouse_mode === 'shared'" class="ri-checkbox-circle-fill text-primary text-xl absolute top-4 right-4"></i>
                        </label>

                        <!-- Смешанный режим -->
                        <label 
                            :class="[
                                form.warehouse_mode === 'mixed' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]',
                                'relative flex cursor-pointer rounded-lg border p-5 shadow-sm focus:outline-none transition-all'
                            ]"
                        >
                            <input type="radio" v-model="form.warehouse_mode" value="mixed" class="sr-only" />
                            <span class="flex flex-1">
                                <span class="flex flex-col">
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white mb-1">
                                        <i class="ri-git-merge-line text-primary mr-1"></i> Смешанный
                                    </span>
                                    <span class="mt-1 flex items-center text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                                        Комбинация. Расходники хранятся локально на локациях, а дорогие материалы — на центральном складе.
                                    </span>
                                </span>
                            </span>
                            <i v-if="form.warehouse_mode === 'mixed'" class="ri-checkbox-circle-fill text-primary text-xl absolute top-4 right-4"></i>
                        </label>

                    </div>

                    <p v-if="form.errors.warehouse_mode" class="text-xs text-danger flex items-start gap-1.5 -mt-2">
                        <i class="ri-error-warning-line shrink-0 mt-0.5"></i> {{ form.errors.warehouse_mode }}
                    </p>

                    <!-- Автодобавление материала на услугу -->
                    <div class="pt-6 mt-6 border-t border-gray-200 dark:border-gray-700">
                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-1">Автодобавление материалов</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                            Если у услуги в каталоге заданы материалы по умолчанию (Настройки → Услуги), при её добавлении в заказ система может сама предложить или сразу добавить их.
                            Работает и без складского учёта — тогда себестоимость материала вводится вручную.
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <label :class="[form.service_material_auto_add_mode === 'off' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-4 shadow-sm transition-all']">
                                <input type="radio" v-model="form.service_material_auto_add_mode" value="off" class="sr-only" />
                                <span class="flex flex-col">
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white mb-1"><i class="ri-close-circle-line mr-1"></i> Выключено</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">Материалы добавляются только вручную.</span>
                                </span>
                                <i v-if="form.service_material_auto_add_mode === 'off'" class="ri-checkbox-circle-fill text-primary text-xl absolute top-4 right-4"></i>
                            </label>
                            <label :class="[form.service_material_auto_add_mode === 'confirm' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-4 shadow-sm transition-all']">
                                <input type="radio" v-model="form.service_material_auto_add_mode" value="confirm" class="sr-only" />
                                <span class="flex flex-col">
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white mb-1"><i class="ri-question-line mr-1"></i> Спрашивать</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">При добавлении услуги — список материалов на подтверждение.</span>
                                </span>
                                <i v-if="form.service_material_auto_add_mode === 'confirm'" class="ri-checkbox-circle-fill text-primary text-xl absolute top-4 right-4"></i>
                            </label>
                            <label :class="[form.service_material_auto_add_mode === 'silent' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-4 shadow-sm transition-all']">
                                <input type="radio" v-model="form.service_material_auto_add_mode" value="silent" class="sr-only" />
                                <span class="flex flex-col">
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white mb-1"><i class="ri-flashlight-line mr-1"></i> Автоматически</span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">Добавляются сразу, без вопроса. Нехватка на складе — пропускается, запись в Истории заказа.</span>
                                </span>
                                <i v-if="form.service_material_auto_add_mode === 'silent'" class="ri-checkbox-circle-fill text-primary text-xl absolute top-4 right-4"></i>
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
                        <button
                            type="submit"
                            :disabled="form.processing || !form.isDirty"
                            class="inline-flex items-center justify-center rounded px-6 py-2.5 text-sm font-semibold transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50 tracking-wide"
                        >
                            <span v-if="form.processing">Сохранение...</span>
                            <span v-else>Сохранить настройки</span>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Header Card: Склады -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Склады</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Список физических складов — выбираются при оприходовании товара и используются для списания по заказам
                    </p>
                </div>
                <button @click="openWarehouseModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                    <i class="ri-add-line"></i> Добавить склад
                </button>
            </div>

            <!-- Content Card: список складов -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="warehouseColumns"
                        :rows="sortedWarehouses"
                        has-actions
                        :sort="sort"
                        @sort="onSort"
                        empty-message="Складов пока нет. Пока не добавите хотя бы один, оприходовать товар будет не на что."
                    >
                        <template #cell-name="{ row: warehouse }">
                            <span class="font-bold text-gray-800 dark:text-gray-200">{{ warehouse.name }}</span>
                        </template>
                        <template #cell-type="{ row: warehouse }">
                            <span v-if="warehouse.owner_type === 'company'" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-info/10 text-info">
                                <i class="ri-building-4-line"></i> Компания
                            </span>
                            <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                <i class="ri-store-3-line"></i> {{ branchName(warehouse.owner_id) }}
                            </span>
                            <i
                                v-if="isOwnerTypeUnusedInMode(warehouse.owner_type)"
                                class="ri-error-warning-line text-warning ml-1"
                                :title="`Не используется в текущем режиме склада («${warehouseModeLabels[warehouseMode]}»)`"
                            ></i>
                        </template>
                        <template #cell-is_default="{ row: warehouse }">
                            <i v-if="warehouse.is_default" class="ri-star-fill text-warning" title="Склад по умолчанию в своей области"></i>
                            <span v-else class="text-gray-300 dark:text-gray-600">—</span>
                        </template>
                        <template #cell-status="{ row: warehouse }">
                            <span :class="[warehouse.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                {{ warehouse.is_active ? 'Активен' : 'Неактивен' }}
                            </span>
                        </template>
                        <template #actions="{ row: warehouse }">
                            <button @click="openWarehouseModal(warehouse)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-primary/10 text-primary hover:bg-primary hover:text-white"><i class="ri-pencil-line"></i></button>
                            <button @click="deleteWarehouse(warehouse)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-danger/10 text-danger hover:bg-danger hover:text-white"><i class="ri-delete-bin-line"></i></button>
                        </template>
                    </DataTable>
                </div>
            </div>

        </div>

        <Modal :show="isWarehouseModalOpen" @close="closeWarehouseModal">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingWarehouse ? 'Редактирование склада' : 'Новый склад' }}</h3>
                    <button @click="closeWarehouseModal()" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitWarehouse">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                            <input v-model="warehouseForm.name" type="text" required placeholder="Например: Основной склад" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <p v-if="warehouseForm.errors.name" class="text-xs text-danger mt-1">{{ warehouseForm.errors.name }}</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип склада <span class="text-danger">*</span></label>
                            <div class="grid grid-cols-2 gap-2">
                                <label :class="[warehouseForm.owner_type === 'branch' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex cursor-pointer rounded-md border p-2.5 text-center text-xs font-medium']">
                                    <input type="radio" :checked="warehouseForm.owner_type === 'branch'" @change="setOwnerType('branch')" class="sr-only" />
                                    <span class="w-full"><i class="ri-store-3-line mr-1"></i> Склад точки</span>
                                </label>
                                <label :class="[warehouseForm.owner_type === 'company' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex cursor-pointer rounded-md border p-2.5 text-center text-xs font-medium']">
                                    <input type="radio" :checked="warehouseForm.owner_type === 'company'" @change="setOwnerType('company')" class="sr-only" />
                                    <span class="w-full"><i class="ri-building-4-line mr-1"></i> Общий (компания)</span>
                                </label>
                            </div>
                            <p class="text-[11px] text-gray-400 mt-1.5">Склад точки резолвится для локации автоматически. Общий склад используется в режиме "Общий" и как центральный в режиме "Смешанный".</p>
                            <div v-if="ownerTypeUnusedInCurrentMode" class="mt-2 p-2.5 rounded-md bg-warning/10 border border-warning/20 text-xs text-gray-700 dark:text-gray-300 flex items-start gap-1.5">
                                <i class="ri-error-warning-line text-warning shrink-0 mt-0.5"></i>
                                <span>Сейчас выбран режим "{{ warehouseModeLabels[warehouseMode] }}" — склад такого типа списанием использоваться не будет, пока вы не смените режим (см. выше).</span>
                            </div>
                        </div>

                        <div v-if="warehouseForm.owner_type === 'branch'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Точка <span class="text-danger">*</span></label>
                            <SearchableSelect v-model="warehouseForm.owner_id" :options="branchOptions" placeholder="Выберите точку" />
                            <p v-if="warehouseForm.errors.owner_id" class="text-xs text-danger mt-1">{{ warehouseForm.errors.owner_id }}</p>
                        </div>

                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                            <div @click="warehouseForm.is_default = !warehouseForm.is_default" :class="[warehouseForm.is_default ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[warehouseForm.is_default ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="warehouseForm.is_default = !warehouseForm.is_default">
                                По умолчанию
                            </label>
                        </div>
                        <p class="text-[11px] text-gray-400 -mt-2">Единственный такой склад в своей области (для общего — на всю компанию, для точки — в пределах этой точки, если складов у неё несколько). Влияет на резолвинг в режиме "Общий".</p>

                        <div class="flex items-center pt-2">
                            <div @click="warehouseForm.is_active = !warehouseForm.is_active" :class="[warehouseForm.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[warehouseForm.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="warehouseForm.is_active = !warehouseForm.is_active">
                                Склад активен
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeWarehouseModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="warehouseForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>