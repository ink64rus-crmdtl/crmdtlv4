<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import CreatableSelect from '@/Components/CreatableSelect.vue';
import Modal from '@/Components/Modal.vue';
import GroupColorPicker, { groupColorMeta } from '@/Components/GroupColorPicker.vue';
import draggable from 'vuedraggable';
import { Head, useForm, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';

const props = defineProps({
    makes: { type: Array, default: () => [] },
    lookups: { type: Object, default: () => ({}) },
    serviceCategories: { type: Array, default: () => [] },
    productCategories: { type: Array, default: () => [] },
    businessDirections: { type: Array, default: () => [] },
    pricingBasis: { type: String, default: 'none' },
});

const activeTab = ref('makes'); // 'makes', 'models', 'service_categories', 'product_categories', 'import', or lookup key

const lookupTypes = {
    'client_source': 'Источники клиентов',
    'vehicle_body': 'Типы кузова',
    'vehicle_class': 'Классы автомобилей',
    'client_role': 'Роли клиентов',
    'work_order_status': 'Статусы заказ-нарядов',
};

const menuGroups = [
    {
        title: 'Транспорт',
        items: [
            { key: 'makes', label: 'Марки автомобилей', icon: 'ri-car-line' },
            { key: 'models', label: 'Модели автомобилей', icon: 'ri-car-washing-line' },
            { key: 'vehicle_body', label: 'Типы кузова', icon: 'ri-layout-2-line' },
            { key: 'vehicle_class', label: 'Классы автомобилей', icon: 'ri-vip-crown-line' },
        ]
    },
    {
        title: 'Каталог и Номенклатура',
        items: [
            { key: 'service_categories', label: 'Категории услуг', icon: 'ri-folder-3-line' },
            { key: 'product_categories', label: 'Категории товаров', icon: 'ri-folder-4-line' },
        ]
    },
    {
        title: 'CRM и Клиенты',
        items: [
            { key: 'client_source', label: 'Источники клиентов', icon: 'ri-share-line' },
            { key: 'client_role', label: 'Роли клиентов', icon: 'ri-user-star-line' },
        ]
    },
    {
        title: 'Заказ-наряды',
        items: [
            { key: 'work_order_status', label: 'Статусы заказ-нарядов', icon: 'ri-flag-2-line' },
        ]
    },
    {
        title: 'Инструменты',
        items: [
            { key: 'import', label: 'Импорт марок/моделей', icon: 'ri-upload-cloud-2-line' },
        ]
    }
];

const isMakeModalOpen = ref(false);
const editingMake = ref(null);
const makeForm = useForm({
    name: '',
    is_active: true,
});

const isModelModalOpen = ref(false);
const editingModel = ref(null);
const modelForm = useForm({
    vehicle_make_id: '',
    name: '',
    body_type: '',
    category: '',
    is_active: true,
});

const importForm = useForm({
    file: null,
});

const isLookupModalOpen = ref(false);
const editingLookup = ref(null);
const lookupForm = useForm({
    type: '',
    value: '',
    color: 'gray',
    is_active: true,
});

const isCategoryModalOpen = ref(false);
const editingCategory = ref(null);
const categoryType = ref('service'); // 'service' or 'product'
const categoryForm = useForm({
    name: '',
    business_direction_id: '',
});

// Статусы заказ-наряда красятся токенами темы (info/warning/success/danger/
// primary), а не именованной Tailwind-палитрой GROUP_COLORS — это осознанно
// другой, более узкий набор (workflow-статус, не произвольный цвет бейджа).
const statusColorOptions = [
    { value: 'gray', label: 'Серый', class: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300', swatch: 'bg-gray-400' },
    { value: 'info', label: 'Синий (Инфо)', class: 'bg-info/10 text-info', swatch: 'bg-info' },
    { value: 'warning', label: 'Желтый (Внимание)', class: 'bg-warning/10 text-warning', swatch: 'bg-warning' },
    { value: 'success', label: 'Зеленый (Успех)', class: 'bg-success/10 text-success', swatch: 'bg-success' },
    { value: 'danger', label: 'Красный (Опасность)', class: 'bg-danger/10 text-danger', swatch: 'bg-danger' },
    { value: 'primary', label: 'Фирменный', class: 'bg-primary/10 text-primary', swatch: 'bg-primary' },
];

const statusColorClass = (color) => statusColorOptions.find(c => c.value === color)?.class || statusColorOptions[0].class;

const sortLookupsByOrder = (list) => [...(list || [])].sort((a, b) => a.sort_order - b.sort_order);

const workOrderStatusesList = ref(sortLookupsByOrder(props.lookups['work_order_status']));
watch(() => props.lookups['work_order_status'], (list) => {
    workOrderStatusesList.value = sortLookupsByOrder(list);
});

const onStatusesReordered = () => {
    router.post(route('settings.lookups.reorder'), {
        type: 'work_order_status',
        ids: workOrderStatusesList.value.map(s => s.id),
    }, { preserveScroll: true, preserveState: true });
};

// Подсказки берутся из справочника (lookups), а не из уже введённых значений моделей —
// иначе значение, добавленное в Справочниках, но ещё не использованное ни в одной модели,
// не появлялось бы в подсказках при заполнении формы модели.
const existingBodyTypes = computed(() => (props.lookups['vehicle_body'] || []).map(l => l.value).sort());

const existingCategories = computed(() => (props.lookups['vehicle_class'] || []).map(l => l.value).sort());

const pricingBasisLabels = {
    vehicle_body: 'Типы кузова',
    vehicle_class: 'Классы автомобилей',
};

const pricingImpactFor = (tabKey) => {
    if (tabKey !== 'vehicle_body' && tabKey !== 'vehicle_class') return null;
    return props.pricingBasis === tabKey;
};

const openMakeModal = (make = null) => {
    editingMake.value = make;
    if (make) {
        makeForm.name = make.name;
        makeForm.is_active = Boolean(make.is_active);
    } else {
        makeForm.reset();
        makeForm.is_active = true;
    }
    isMakeModalOpen.value = true;
};

const closeMakeModal = () => {
    isMakeModalOpen.value = false;
    editingMake.value = null;
    makeForm.reset();
    makeForm.clearErrors();
};

const submitMake = () => {
    if (editingMake.value) {
        makeForm.put(route('settings.dictionaries.makes.update', editingMake.value.id), {
            onSuccess: () => closeMakeModal(),
        });
    } else {
        makeForm.post(route('settings.dictionaries.makes.store'), {
            onSuccess: () => closeMakeModal(),
        });
    }
};

const deleteMake = (make) => {
    if (confirm(`Удалить марку "${make.name}" и все её модели?`)) {
        makeForm.delete(route('settings.dictionaries.makes.destroy', make.id));
    }
};

const openModelModal = (model = null, makeId = '') => {
    editingModel.value = model;
    if (model) {
        modelForm.vehicle_make_id = model.vehicle_make_id;
        modelForm.name = model.name;
        modelForm.body_type = model.body_type || '';
        modelForm.category = model.category || '';
        modelForm.is_active = Boolean(model.is_active);
    } else {
        modelForm.reset();
        modelForm.vehicle_make_id = makeId;
        modelForm.is_active = true;
    }
    isModelModalOpen.value = true;
};

const closeModelModal = () => {
    isModelModalOpen.value = false;
    editingModel.value = null;
    modelForm.reset();
    modelForm.clearErrors();
};

const submitModel = () => {
    if (editingModel.value) {
        modelForm.put(route('settings.dictionaries.models.update', editingModel.value.id), {
            onSuccess: () => closeModelModal(),
        });
    } else {
        modelForm.post(route('settings.dictionaries.models.store'), {
            onSuccess: () => closeModelModal(),
        });
    }
};

const deleteModel = (model) => {
    if (confirm(`Удалить модель "${model.name}"?`)) {
        modelForm.delete(route('settings.dictionaries.models.destroy', model.id));
    }
};

const openLookupModal = (lookup = null) => {
    editingLookup.value = lookup;
    if (lookup) {
        lookupForm.type = lookup.type;
        lookupForm.value = lookup.value;
        lookupForm.color = lookup.color || 'gray';
        lookupForm.is_active = Boolean(lookup.is_active);
    } else {
        lookupForm.reset();
        lookupForm.type = activeTab.value;
        lookupForm.is_active = true;
    }
    isLookupModalOpen.value = true;
};

const closeLookupModal = () => {
    isLookupModalOpen.value = false;
    editingLookup.value = null;
    lookupForm.reset();
    lookupForm.clearErrors();
};

const submitLookup = () => {
    if (editingLookup.value) {
        lookupForm.put(route('settings.lookups.update', editingLookup.value.id), {
            onSuccess: () => closeLookupModal(),
        });
    } else {
        lookupForm.post(route('settings.lookups.store'), {
            onSuccess: () => closeLookupModal(),
        });
    }
};

const deleteLookup = (lookup) => {
    if (confirm(`Удалить запись "${lookup.value}"?`)) {
        lookupForm.delete(route('settings.lookups.destroy', lookup.id));
    }
};

const openCategoryModal = (type, category = null) => {
    categoryType.value = type;
    editingCategory.value = category;
    if (category) {
        categoryForm.name = getLocalizedLabel(category.name);
        categoryForm.business_direction_id = category.business_direction_id || '';
    } else {
        categoryForm.reset();
    }
    isCategoryModalOpen.value = true;
};

const closeCategoryModal = () => {
    isCategoryModalOpen.value = false;
    editingCategory.value = null;
    categoryForm.reset();
    categoryForm.clearErrors();
};

const submitCategory = () => {
    const routePrefix = categoryType.value === 'service' ? 'service-categories' : 'product-categories';
    if (editingCategory.value) {
        categoryForm.put(route(`settings.dictionaries.${routePrefix}.update`, editingCategory.value.id), {
            onSuccess: () => closeCategoryModal(),
        });
    } else {
        categoryForm.post(route(`settings.dictionaries.${routePrefix}.store`), {
            onSuccess: () => closeCategoryModal(),
        });
    }
};

const deleteCategory = (type, category) => {
    const routePrefix = type === 'service' ? 'service-categories' : 'product-categories';
    if (confirm(`Удалить категорию "${getLocalizedLabel(category.name)}"?`)) {
        categoryForm.delete(route(`settings.dictionaries.${routePrefix}.destroy`, category.id));
    }
};

const submitImport = () => {
    importForm.post(route('settings.dictionaries.import'), {
        onSuccess: () => {
            importForm.reset();
            activeTab.value = 'makes';
        },
    });
};

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
</script>

<template>
    <Head title="Справочники" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            
            <SettingsNav />

            <PageHelper title="Единый центр справочников">
                <p>Все списки и категории системы собраны в удобной двухколоночной структуре. Выберите справочник в меню слева для просмотра и редактирования данных.</p>
            </PageHelper>

            <!-- Двухколоночный макет (HubSpot / Attex Style) -->
            <div class="grid grid-cols-12 gap-6">
                
                <!-- Левая колонка: Меню справочников (25% / 3 столбца) -->
                <div class="col-span-12 lg:col-span-3 space-y-4">
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-4 space-y-4">
                        <div v-for="(group, gIdx) in menuGroups" :key="gIdx" class="space-y-1">
                            <h4 class="text-[11px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider px-2 mb-1.5">{{ group.title }}</h4>
                            <button
                                v-for="item in group.items"
                                :key="item.key"
                                @click="activeTab = item.key"
                                :class="[
                                    activeTab === item.key 
                                        ? 'bg-primary/10 text-primary font-bold' 
                                        : 'text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/50',
                                    'w-full flex items-center justify-between px-3 py-2 text-sm rounded-md transition-all text-left'
                                ]"
                            >
                                <span class="flex items-center gap-2">
                                    <i :class="[item.icon, 'text-base']"></i>
                                    {{ item.label }}
                                </span>
                                <i v-if="activeTab === item.key" class="ri-arrow-right-s-line text-primary"></i>
                            </button>
                        </div>

                        <!-- Прямые переходы в операционные каталоги -->
                        <div class="border-t border-gray-100 dark:border-gray-700 pt-3 mt-3 space-y-1">
                            <h4 class="text-[11px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider px-2 mb-1.5">Операционные прайсы</h4>
                            <Link :href="route('operations.services.index')" class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/50 rounded-md transition-all">
                                <span class="flex items-center gap-2 text-primary">
                                    <i class="ri-tools-line"></i> Прайс-лист услуг
                                </span>
                                <i class="ri-external-link-line text-xs"></i>
                            </Link>
                            <Link :href="route('warehouse.products.index')" class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/50 rounded-md transition-all">
                                <span class="flex items-center gap-2 text-primary">
                                    <i class="ri-box-3-line"></i> Каталог товаров
                                </span>
                                <i class="ri-external-link-line text-xs"></i>
                            </Link>
                            <!-- Должности — не Lookup-справочник, а полноценная сущность с
                                 payroll_role/правами (раздел HR), поэтому не отдельная вкладка
                                 activeTab, а прямой переход, как к прайс-листам выше. -->
                            <Link :href="route('hr.positions.index')" class="w-full flex items-center justify-between px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700/50 rounded-md transition-all">
                                <span class="flex items-center gap-2 text-primary">
                                    <i class="ri-briefcase-4-line"></i> Должности
                                </span>
                                <i class="ri-external-link-line text-xs"></i>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Правая колонка: Тело выбранного справочника (75% / 9 столбцов) -->
                <div class="col-span-12 lg:col-span-9">
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden flex flex-col">
                        
                        <!-- Марки -->
                        <div v-show="activeTab === 'makes'" class="flex flex-col">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                                <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Марки автомобилей</h3>
                                <button @click="openMakeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                                    <i class="ri-add-line"></i> Добавить марку
                                </button>
                            </div>
                            <div class="overflow-x-auto w-full">
                                <table class="min-w-full text-left whitespace-nowrap">
                                    <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                        <tr>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Марка</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Кол-во моделей</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статус</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                                        <tr v-for="make in makes" :key="make.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                            <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200">{{ make.name }}</td>
                                            <td class="py-4 px-6 text-sm">{{ make.models?.length || 0 }} шт.</td>
                                            <td class="py-4 px-6 text-sm">
                                                <span :class="[make.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                                    {{ make.is_active ? 'Активно' : 'Неактивно' }}
                                                </span>
                                            </td>
                                            <td class="py-4 px-6 text-sm text-right space-x-2">
                                                <button @click="openMakeModal(make)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-primary/10 text-primary hover:bg-primary hover:text-white"><i class="ri-pencil-line"></i></button>
                                                <button @click="deleteMake(make)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-danger/10 text-danger hover:bg-danger hover:text-white"><i class="ri-delete-bin-line"></i></button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Модели -->
                        <div v-show="activeTab === 'models'" class="flex flex-col">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                                <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Модели автомобилей</h3>
                                <button @click="openModelModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                                    <i class="ri-add-line"></i> Добавить модель
                                </button>
                            </div>
                            <div class="overflow-x-auto w-full">
                                <table class="min-w-full text-left whitespace-nowrap">
                                    <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                        <tr>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Марка</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Модель</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Тип кузова</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Категория / Класс</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статус</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                                        <template v-for="make in makes" :key="'m_'+make.id">
                                            <tr v-for="model in make.models" :key="model.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                                <td class="py-3 px-6 text-sm font-semibold text-gray-700 dark:text-gray-300">{{ make.name }}</td>
                                                <td class="py-3 px-6 text-sm font-bold text-gray-800 dark:text-gray-200">{{ model.name }}</td>
                                                <td class="py-3 px-6 text-sm">{{ model.body_type || '—' }}</td>
                                                <td class="py-3 px-6 text-sm">{{ model.category || '—' }}</td>
                                                <td class="py-3 px-6 text-sm">
                                                    <span :class="[model.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                                        {{ model.is_active ? 'Активно' : 'Неактивно' }}
                                                    </span>
                                                </td>
                                                <td class="py-3 px-6 text-sm text-right space-x-2">
                                                    <button @click="openModelModal(model, make.id)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-primary/10 text-primary hover:bg-primary hover:text-white"><i class="ri-pencil-line"></i></button>
                                                    <button @click="deleteModel(model)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-danger/10 text-danger hover:bg-danger hover:text-white"><i class="ri-delete-bin-line"></i></button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Категории услуг -->
                        <div v-show="activeTab === 'service_categories'" class="flex flex-col">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                                <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Категории услуг</h3>
                                <button @click="openCategoryModal('service')" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                                    <i class="ri-add-line"></i> Добавить категорию
                                </button>
                            </div>
                            <div class="overflow-x-auto w-full">
                                <table class="min-w-full text-left whitespace-nowrap">
                                    <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                        <tr>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Направление бизнеса</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                                        <tr v-for="cat in serviceCategories" :key="cat.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                            <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(cat.name) }}</td>
                                            <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300">
                                                <span v-if="cat.business_direction" class="inline-flex items-center gap-1.5 bg-info/10 text-info px-2.5 py-1 rounded text-xs font-medium">
                                                    <i class="ri-node-tree"></i> {{ cat.business_direction.name }}
                                                </span>
                                                <span v-else class="text-xs text-gray-400 font-medium">Общая категория</span>
                                            </td>
                                            <td class="py-4 px-6 text-sm text-right space-x-2">
                                                <button @click="openCategoryModal('service', cat)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-primary/10 text-primary hover:bg-primary hover:text-white"><i class="ri-pencil-line"></i></button>
                                                <button @click="deleteCategory('service', cat)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-danger/10 text-danger hover:bg-danger hover:text-white"><i class="ri-delete-bin-line"></i></button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Категории товаров -->
                        <div v-show="activeTab === 'product_categories'" class="flex flex-col">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                                <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Категории товаров</h3>
                                <button @click="openCategoryModal('product')" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                                    <i class="ri-add-line"></i> Добавить категорию
                                </button>
                            </div>
                            <div class="overflow-x-auto w-full">
                                <table class="min-w-full text-left whitespace-nowrap">
                                    <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                        <tr>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                                        <tr v-for="cat in productCategories" :key="cat.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                            <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(cat.name) }}</td>
                                            <td class="py-4 px-6 text-sm text-right space-x-2">
                                                <button @click="openCategoryModal('product', cat)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-primary/10 text-primary hover:bg-primary hover:text-white"><i class="ri-pencil-line"></i></button>
                                                <button @click="deleteCategory('product', cat)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-danger/10 text-danger hover:bg-danger hover:text-white"><i class="ri-delete-bin-line"></i></button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Универсальные справочники -->
                        <div v-show="Object.keys(lookupTypes).includes(activeTab) && activeTab !== 'work_order_status'" class="flex flex-col">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                                <div class="flex items-center gap-3">
                                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ lookupTypes[activeTab] }}</h3>
                                    <Link
                                        v-if="pricingImpactFor(activeTab) !== null"
                                        :href="route('settings.crm.index')"
                                        :class="[
                                            pricingImpactFor(activeTab) ? 'bg-success/10 text-success' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
                                            'inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-medium hover:opacity-80 transition-opacity'
                                        ]"
                                        :title="pricingImpactFor(activeTab) ? 'Настроить в Настройки → CRM' : 'Включить в Настройки → CRM'"
                                    >
                                        <i :class="pricingImpactFor(activeTab) ? 'ri-price-tag-3-fill' : 'ri-price-tag-3-line'"></i>
                                        {{ pricingImpactFor(activeTab) ? 'Влияет на цену' : 'Не используется для цены' }}
                                    </Link>
                                </div>
                                <button @click="openLookupModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                                    <i class="ri-add-line"></i> Добавить запись
                                </button>
                            </div>
                            <div v-if="pricingImpactFor(activeTab) !== null" class="px-6 py-3 bg-info/5 border-b border-gray-200 dark:border-gray-700 text-xs text-gray-600 dark:text-gray-400">
                                <i class="ri-information-line text-info mr-1"></i>
                                <template v-if="pricingImpactFor(activeTab)">
                                    Этот список используется для ценообразования: в прайс-листе услуг для каждого значения можно задать отдельную цену, она подставится в заказ-наряд по автомобилю клиента.
                                </template>
                                <template v-else-if="pricingBasis === 'none'">
                                    Этот список можно использовать для ценообразования услуг (отдельная цена на каждое значение). Сейчас ценообразование по кузову/классу выключено — включить можно в
                                </template>
                                <template v-else>
                                    Этот список можно использовать для ценообразования услуг (отдельная цена на каждое значение). Сейчас за это отвечает список «{{ pricingBasisLabels[pricingBasis] }}» — влиять может только один список одновременно, переключить можно в
                                </template>
                                <Link v-if="!pricingImpactFor(activeTab)" :href="route('settings.crm.index')" class="text-primary hover:underline font-medium">Настройки → CRM</Link>.
                            </div>
                            <div class="overflow-x-auto w-full">
                                <table class="min-w-full text-left whitespace-nowrap">
                                    <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                        <tr>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Значение</th>
                                            <th v-if="activeTab === 'client_role'" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Цвет</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статус</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                                        <tr v-for="lookup in (lookups[activeTab] || [])" :key="lookup.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                            <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200">{{ lookup.value }}</td>
                                            <td v-if="activeTab === 'client_role'" class="py-4 px-6 text-sm">
                                                <span :class="[groupColorMeta(lookup.color).badge, 'inline-flex items-center px-2.5 py-1 rounded text-xs font-bold uppercase']">
                                                    {{ lookup.value }}
                                                </span>
                                            </td>
                                            <td class="py-4 px-6 text-sm">
                                                <span :class="[lookup.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                                    {{ lookup.is_active ? 'Активно' : 'Неактивно' }}
                                                </span>
                                            </td>
                                            <td class="py-4 px-6 text-sm text-right space-x-2">
                                                <button @click="openLookupModal(lookup)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-primary/10 text-primary hover:bg-primary hover:text-white"><i class="ri-pencil-line"></i></button>
                                                <button @click="deleteLookup(lookup)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-danger/10 text-danger hover:bg-danger hover:text-white"><i class="ri-delete-bin-line"></i></button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Статусы заказ-нарядов: сортировка стрелками + защита системных записей -->
                        <div v-show="activeTab === 'work_order_status'" class="flex flex-col">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                                <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Статусы заказ-нарядов</h3>
                                <button @click="openLookupModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                                    <i class="ri-add-line"></i> Добавить статус
                                </button>
                            </div>
                            <div class="overflow-x-auto w-full">
                                <table class="min-w-full text-left whitespace-nowrap">
                                    <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                        <tr>
                                            <th class="py-3 px-4 w-20 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Порядок</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статус</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Активность</th>
                                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                                        </tr>
                                    </thead>
                                    <draggable
                                        v-model="workOrderStatusesList"
                                        tag="tbody"
                                        item-key="id"
                                        class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300"
                                        handle=".status-drag-handle"
                                        @end="onStatusesReordered"
                                    >
                                        <template #item="{ element: lookup }">
                                            <tr class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                                <td class="py-4 px-4">
                                                    <i class="ri-draggable status-drag-handle text-gray-400 cursor-grab active:cursor-grabbing" title="Перетащить для сортировки"></i>
                                                </td>
                                                <td class="py-4 px-6 text-sm">
                                                    <span :class="[statusColorClass(lookup.color), 'inline-flex items-center px-2.5 py-1 rounded text-xs font-bold uppercase']">
                                                        {{ lookup.label || lookup.value }}
                                                    </span>
                                                    <i v-if="lookup.is_system" class="ri-lock-line text-gray-400 ml-2" title="Системный статус"></i>
                                                </td>
                                                <td class="py-4 px-6 text-sm">
                                                    <span :class="[lookup.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                                        {{ lookup.is_active ? 'Активно' : 'Неактивно' }}
                                                    </span>
                                                </td>
                                                <td class="py-4 px-6 text-sm text-right space-x-2">
                                                    <template v-if="!lookup.is_system">
                                                        <button @click="openLookupModal(lookup)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-primary/10 text-primary hover:bg-primary hover:text-white"><i class="ri-pencil-line"></i></button>
                                                        <button @click="deleteLookup(lookup)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all bg-danger/10 text-danger hover:bg-danger hover:text-white"><i class="ri-delete-bin-line"></i></button>
                                                    </template>
                                                    <span v-else class="text-xs text-gray-400">Системный</span>
                                                </td>
                                            </tr>
                                        </template>
                                    </draggable>
                                </table>
                            </div>
                        </div>

                        <!-- Импорт -->
                        <div v-show="activeTab === 'import'" class="p-6 space-y-6">
                            <div class="bg-warning/10 border border-warning/20 rounded-md p-4">
                                <h4 class="text-sm font-bold text-warning mb-2">Формат CSV файла</h4>
                                <p class="text-sm text-gray-700 dark:text-gray-300 mb-2">Файл должен содержать данные, разделенные точкой с запятой (<code>;</code>).</p>
                                <pre class="bg-white dark:bg-gray-900 p-3 rounded text-xs font-mono text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700">Марка;Модель;Тип кузова;Категория
BMW;X5;Кроссовер;Класс 3
Audi;A3;Седан;Класс 1</pre>
                            </div>

                            <form @submit.prevent="submitImport" class="flex items-end gap-4">
                                <div class="flex-1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Выберите CSV файл</label>
                                    <input type="file" accept=".csv,.txt" @input="importForm.file = $event.target.files[0]" class="block w-full text-sm text-gray-500 border border-gray-200 dark:border-gray-700 rounded-md" />
                                </div>
                                <button type="submit" :disabled="importForm.processing || !importForm.file" class="inline-flex items-center justify-center rounded px-6 py-2.5 text-sm font-semibold bg-primary text-white hover:bg-primary-600 disabled:opacity-50 h-[42px]">
                                    <i class="ri-upload-cloud-2-line mr-2"></i> Импортировать
                                </button>
                            </form>
                        </div>

                    </div>
                </div>

            </div>
        </div>

        <!-- Модалки -->
        <Modal :show="isMakeModalOpen" @close="closeMakeModal">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingMake ? 'Редактирование марки' : 'Новая марка' }}</h3>
                    <button @click="closeMakeModal" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitMake">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                            <input v-model="makeForm.name" type="text" required placeholder="BMW" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200" />
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50">
                        <button type="button" @click="closeMakeModal" class="px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary rounded">Отмена</button>
                        <button type="submit" :disabled="makeForm.processing" class="px-4 py-2 text-sm font-medium bg-primary text-white rounded">Сохранить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <Modal :show="isModelModalOpen" @close="closeModelModal">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingModel ? 'Редактирование модели' : 'Новая модель' }}</h3>
                    <button @click="closeModelModal" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitModel">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Марка <span class="text-danger">*</span></label>
                            <select v-model="modelForm.vehicle_make_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200">
                                <option v-for="make in makes" :key="make.id" :value="make.id" class="bg-white dark:bg-gray-800">{{ make.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название модели <span class="text-danger">*</span></label>
                            <input v-model="modelForm.name" type="text" required placeholder="X5" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип кузова</label>
                                <CreatableSelect v-model="modelForm.body_type" :options="existingBodyTypes" lookupType="vehicle_body" placeholder="Кроссовер" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория / Класс</label>
                                <CreatableSelect v-model="modelForm.category" :options="existingCategories" lookupType="vehicle_class" placeholder="Класс 3" />
                            </div>
                        </div>
                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                            <div @click="modelForm.is_active = !modelForm.is_active" :class="[modelForm.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[modelForm.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="modelForm.is_active = !modelForm.is_active">
                                Активно
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50">
                        <button type="button" @click="closeModelModal" class="px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary rounded">Отмена</button>
                        <button type="submit" :disabled="modelForm.processing" class="px-4 py-2 text-sm font-medium bg-primary text-white rounded">Сохранить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <Modal :show="isLookupModalOpen" @close="closeLookupModal">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingLookup ? 'Редактирование записи' : 'Новая запись' }}</h3>
                    <button @click="closeLookupModal()" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitLookup">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Значение <span class="text-danger">*</span></label>
                            <input v-model="lookupForm.value" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200" />
                        </div>
                        <div v-if="lookupForm.type === 'client_role'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цвет бейджа</label>
                            <GroupColorPicker v-model="lookupForm.color" />
                        </div>
                        <div v-if="lookupForm.type === 'work_order_status'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цвет бейджа</label>
                            <div class="flex flex-wrap items-center gap-2.5">
                                <button
                                    v-for="color in statusColorOptions"
                                    :key="color.value"
                                    type="button"
                                    @click="lookupForm.color = color.value"
                                    :class="[color.swatch, lookupForm.color === color.value ? 'ring-2 ring-offset-2 ring-gray-400 dark:ring-offset-gray-800 scale-110' : 'hover:scale-110']"
                                    class="h-8 w-8 rounded-full shadow-sm transition-transform flex items-center justify-center"
                                    :title="color.label"
                                >
                                    <i v-if="lookupForm.color === color.value" class="ri-check-line text-white text-base"></i>
                                </button>
                            </div>
                        </div>
                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                            <div @click="lookupForm.is_active = !lookupForm.is_active" :class="[lookupForm.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[lookupForm.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="lookupForm.is_active = !lookupForm.is_active">
                                Активно
                            </label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50">
                        <button type="button" @click="closeLookupModal()" class="px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary rounded">Отмена</button>
                        <button type="submit" :disabled="lookupForm.processing" class="px-4 py-2 text-sm font-medium bg-primary text-white rounded">Сохранить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <Modal :show="isCategoryModalOpen" @close="closeCategoryModal">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingCategory ? 'Редактирование категории' : 'Новая категория' }}</h3>
                    <button @click="closeCategoryModal" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitCategory">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                            <input v-model="categoryForm.name" type="text" required placeholder="Например: Мойка" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200" />
                        </div>
                        <div v-if="categoryType === 'service'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Направление бизнеса</label>
                            <select v-model="categoryForm.business_direction_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" class="bg-white dark:bg-gray-800">Общая категория (Без направления)</option>
                                <option v-for="dir in businessDirections" :key="dir.id" :value="dir.id" class="bg-white dark:bg-gray-800">{{ dir.name }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50">
                        <button type="button" @click="closeCategoryModal" class="px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary rounded">Отмена</button>
                        <button type="submit" :disabled="categoryForm.processing" class="px-4 py-2 text-sm font-medium bg-primary text-white rounded">Сохранить</button>
                    </div>
                </form>
            </div>
        </Modal>

    </AuthenticatedLayout>
</template>