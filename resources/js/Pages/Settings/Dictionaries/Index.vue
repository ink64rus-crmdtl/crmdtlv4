<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import CreatableSelect from '@/Components/CreatableSelect.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    makes: Array,
    lookups: Object,
    serviceCategories: Array,
    productCategories: Array,
});

const activeTab = ref('makes'); // 'makes', 'models', 'service_categories', 'product_categories', 'import', or lookup key

const lookupTypes = {
    'client_source': 'Источники клиентов',
    'vehicle_body': 'Типы кузова',
    'vehicle_class': 'Классы автомобилей',
    'client_role': 'Роли клиентов',
};

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
});

const groupColors = [
    { value: 'gray', label: 'Серый', class: 'bg-gray-100 text-gray-700' },
    { value: 'blue', label: 'Синий', class: 'bg-blue-100 text-blue-700' },
    { value: 'green', label: 'Зеленый', class: 'bg-green-100 text-green-700' },
    { value: 'red', label: 'Красный', class: 'bg-red-100 text-red-700' },
    { value: 'yellow', label: 'Желтый', class: 'bg-yellow-100 text-yellow-700' },
    { value: 'purple', label: 'Фиолетовый', class: 'bg-purple-100 text-purple-700' },
];

// --- Динамические списки для Datalist ---
const existingBodyTypes = computed(() => {
    const types = new Set();
    props.makes.forEach(make => {
        if (make.models) {
            make.models.forEach(model => {
                if (model.body_type) types.add(model.body_type);
            });
        }
    });
    return Array.from(types).sort();
});

const existingCategories = computed(() => {
    const categories = new Set();
    props.makes.forEach(make => {
        if (make.models) {
            make.models.forEach(model => {
                if (model.category) categories.add(model.category);
            });
        }
    });
    return Array.from(categories).sort();
});

// --- Управление Марками ---
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

// --- Управление Моделями ---
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

// --- Управление Универсальными Справочниками (Lookups) ---
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

// --- Управление Категориями (Услуг и Товаров) ---
const openCategoryModal = (type, category = null) => {
    categoryType.value = type;
    editingCategory.value = category;
    if (category) {
        categoryForm.name = getLocalizedLabel(category.name);
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

// --- Импорт ---
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
            
            <!-- Навигация по настройкам (Attex Tabs) -->
            <SettingsNav />

            <PageHelper title="Для чего нужны Справочники?">
                <p>Справочники позволяют стандартизировать ввод данных в системе. Например, вместо того чтобы администраторы вводили марку автомобиля вручную (что приводит к дублям вроде "BMW", "БМВ", "bmw"), они будут выбирать её из строго заданного списка.</p>
                <p>Вы можете добавлять марки и модели вручную или загрузить их массово через CSV-файл на вкладке «Импорт».</p>
            </PageHelper>

            <!-- Content Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden flex flex-col">
                
                <!-- Вкладки -->
                <div class="flex space-x-6 border-b border-gray-200 dark:border-gray-700 px-6 bg-gray-50/50 dark:bg-gray-800/50 overflow-x-auto custom-scrollbar">
                    <button @click="activeTab = 'makes'" :class="[activeTab === 'makes' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none whitespace-nowrap']">
                        Марки автомобилей
                    </button>
                    <button @click="activeTab = 'models'" :class="[activeTab === 'models' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none whitespace-nowrap']">
                        Модели автомобилей
                    </button>
                    <button @click="activeTab = 'service_categories'" :class="[activeTab === 'service_categories' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none whitespace-nowrap']">
                        Категории услуг
                    </button>
                    <button @click="activeTab = 'product_categories'" :class="[activeTab === 'product_categories' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none whitespace-nowrap']">
                        Категории товаров
                    </button>
                    <button v-for="(label, key) in lookupTypes" :key="key" @click="activeTab = key" :class="[activeTab === key ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none whitespace-nowrap']">
                        {{ label }}
                    </button>
                    <button @click="activeTab = 'import'" :class="[activeTab === 'import' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none whitespace-nowrap']">
                        Импорт из CSV
                    </button>
                </div>

                <!-- Вкладка: Марки -->
                <div v-show="activeTab === 'makes'" class="flex flex-col">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Список марок</h3>
                        <button @click="openMakeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5">
                            <i class="ri-add-line"></i> Добавить марку
                        </button>
                    </div>
                    <div class="overflow-x-auto w-full p-0">
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
                                <tr v-for="make in makes" :key="make.id" class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                    <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200">{{ make.name }}</td>
                                    <td class="py-4 px-6 text-sm">{{ make.models?.length || 0 }} шт.</td>
                                    <td class="py-4 px-6 text-sm">
                                        <span :class="[make.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                            {{ make.is_active ? 'Активно' : 'Неактивно' }}
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-sm text-right space-x-2">
                                        <button @click="openMakeModal(make)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать"><i class="ri-pencil-line"></i></button>
                                        <button @click="deleteMake(make)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="makes.length === 0">
                                    <td colspan="4" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">Справочник пуст.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Вкладка: Модели -->
                <div v-show="activeTab === 'models'" class="flex flex-col">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Список моделей</h3>
                        <button @click="openModelModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5">
                            <i class="ri-add-line"></i> Добавить модель
                        </button>
                    </div>
                    <div class="overflow-x-auto w-full p-0">
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
                                    <tr v-for="model in make.models" :key="model.id" class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
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
                                            <button @click="openModelModal(model, make.id)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать"><i class="ri-pencil-line"></i></button>
                                            <button @click="deleteModel(model)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="makes.length === 0 || makes.every(m => m.models.length === 0)">
                                    <td colspan="6" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">Модели еще не добавлены.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Вкладка: Категории услуг -->
                <div v-show="activeTab === 'service_categories'" class="flex flex-col">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Категории услуг</h3>
                        <button @click="openCategoryModal('service')" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5">
                            <i class="ri-add-line"></i> Добавить категорию
                        </button>
                    </div>
                    <div class="overflow-x-auto w-full p-0">
                        <table class="min-w-full text-left whitespace-nowrap">
                            <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                <tr>
                                    <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                    <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                                <tr v-for="cat in serviceCategories" :key="cat.id" class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                    <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(cat.name) }}</td>
                                    <td class="py-4 px-6 text-sm text-right space-x-2">
                                        <button @click="openCategoryModal('service', cat)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать"><i class="ri-pencil-line"></i></button>
                                        <button @click="deleteCategory('service', cat)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="serviceCategories.length === 0">
                                    <td colspan="2" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">Категории не найдены.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Вкладка: Категории товаров -->
                <div v-show="activeTab === 'product_categories'" class="flex flex-col">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Категории товаров</h3>
                        <button @click="openCategoryModal('product')" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5">
                            <i class="ri-add-line"></i> Добавить категорию
                        </button>
                    </div>
                    <div class="overflow-x-auto w-full p-0">
                        <table class="min-w-full text-left whitespace-nowrap">
                            <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                <tr>
                                    <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                    <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                                <tr v-for="cat in productCategories" :key="cat.id" class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                    <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(cat.name) }}</td>
                                    <td class="py-4 px-6 text-sm text-right space-x-2">
                                        <button @click="openCategoryModal('product', cat)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать"><i class="ri-pencil-line"></i></button>
                                        <button @click="deleteCategory('product', cat)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="productCategories.length === 0">
                                    <td colspan="2" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">Категории не найдены.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Вкладка: Универсальные справочники -->
                <div v-show="Object.keys(lookupTypes).includes(activeTab)" class="flex flex-col">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ lookupTypes[activeTab] }}</h3>
                        <button @click="openLookupModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5">
                            <i class="ri-add-line"></i> Добавить запись
                        </button>
                    </div>
                    <div class="overflow-x-auto w-full p-0">
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
                                <tr v-for="lookup in (lookups[activeTab] || [])" :key="lookup.id" class="hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                    <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200">{{ lookup.value }}</td>
                                    <td v-if="activeTab === 'client_role'" class="py-4 px-6 text-sm">
                                        <span :class="[`bg-${lookup.color || 'gray'}-100 text-${lookup.color || 'gray'}-700 dark:bg-${lookup.color || 'gray'}-900/30 dark:text-${lookup.color || 'gray'}-400`, 'inline-flex items-center px-2.5 py-1 rounded text-xs font-bold uppercase tracking-wider']">
                                            {{ lookup.value }}
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-sm">
                                        <span :class="[lookup.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger', 'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium']">
                                            {{ lookup.is_active ? 'Активно' : 'Неактивно' }}
                                        </span>
                                    </td>
                                    <td class="py-4 px-6 text-sm text-right space-x-2">
                                        <button @click="openLookupModal(lookup)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать"><i class="ri-pencil-line"></i></button>
                                        <button @click="deleteLookup(lookup)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                    </td>
                                </tr>
                                <tr v-if="!(lookups[activeTab] && lookups[activeTab].length > 0)">
                                    <td :colspan="activeTab === 'client_role' ? 4 : 3" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">Справочник пуст.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Вкладка: Импорт -->
                <div v-show="activeTab === 'import'" class="p-6 space-y-6">
                    <div class="bg-warning/10 border border-warning/20 rounded-md p-4">
                        <h4 class="text-sm font-bold text-warning mb-2">Формат CSV файла</h4>
                        <p class="text-sm text-gray-700 dark:text-gray-300 mb-2">Файл должен содержать данные, разделенные точкой с запятой (<code>;</code>). Первая строка (заголовок) игнорируется, если начинается со слова "Марка".</p>
                        <pre class="bg-white dark:bg-gray-900 p-3 rounded text-xs font-mono text-gray-600 dark:text-gray-400 border border-gray-200 dark:border-gray-700">Марка;Модель;Тип кузова;Категория
BMW;X5;Кроссовер;Класс 3
Audi;A3;Седан;Класс 1</pre>
                    </div>

                    <form @submit.prevent="submitImport" class="flex items-end gap-4">
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Выберите CSV файл</label>
                            <input 
                                type="file" 
                                accept=".csv,.txt"
                                @input="importForm.file = $event.target.files[0]"
                                class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary hover:file:text-white transition-colors cursor-pointer border border-gray-200 dark:border-gray-700 rounded-md"
                            />
                        </div>
                        <button 
                            type="submit" 
                            :disabled="importForm.processing || !importForm.file" 
                            class="inline-flex items-center justify-center rounded px-6 py-2.5 text-sm font-semibold transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50 h-[42px]"
                        >
                            <span v-if="importForm.processing">Загрузка...</span>
                            <span v-else><i class="ri-upload-cloud-2-line mr-2"></i> Импортировать</span>
                        </button>
                    </form>
                    <progress v-if="importForm.progress" :value="importForm.progress.percentage" max="100" class="w-full h-2 rounded overflow-hidden">
                        {{ importForm.progress.percentage }}%
                    </progress>
                </div>
            </div>
        </div>

        <!-- Модалка Марки -->
        <div v-if="isMakeModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingMake ? 'Редактирование марки' : 'Новая марка' }}</h3>
                    <button @click="closeMakeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitMake" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                            <input v-model="makeForm.name" type="text" required placeholder="BMW" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="makeForm.errors.name" class="text-xs text-danger mt-1">{{ makeForm.errors.name }}</span>
                        </div>
                        <div class="flex items-center pt-2">
                            <div @click="makeForm.is_active = !makeForm.is_active" :class="[makeForm.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[makeForm.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="makeForm.is_active = !makeForm.is_active">Активно</label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeMakeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="makeForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Модалка Модели -->
        <div v-if="isModelModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingModel ? 'Редактирование модели' : 'Новая модель' }}</h3>
                    <button @click="closeModelModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitModel" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Марка <span class="text-danger">*</span></label>
                            <select v-model="modelForm.vehicle_make_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" disabled class="bg-white dark:bg-gray-800">Выберите марку...</option>
                                <option v-for="make in makes" :key="make.id" :value="make.id" class="bg-white dark:bg-gray-800">{{ make.name }}</option>
                            </select>
                            <span v-if="modelForm.errors.vehicle_make_id" class="text-xs text-danger mt-1">{{ modelForm.errors.vehicle_make_id }}</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название модели <span class="text-danger">*</span></label>
                            <input v-model="modelForm.name" type="text" required placeholder="X5" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="modelForm.errors.name" class="text-xs text-danger mt-1">{{ modelForm.errors.name }}</span>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип кузова</label>
                                <CreatableSelect v-model="modelForm.body_type" :options="existingBodyTypes" lookupType="vehicle_body" placeholder="Кроссовер" />
                                <p class="text-xs text-gray-500 mt-1">Пользователь может привязать категорию или тип кузова к прайсу</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория / Класс</label>
                                <CreatableSelect v-model="modelForm.category" :options="existingCategories" lookupType="vehicle_class" placeholder="Класс 3" />
                                <p class="text-xs text-gray-500 mt-1">Пользователь может привязать категорию или тип кузова к прайсу</p>
                            </div>
                        </div>
                        <div class="flex items-center pt-2">
                            <div @click="modelForm.is_active = !modelForm.is_active" :class="[modelForm.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[modelForm.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="modelForm.is_active = !modelForm.is_active">Активно</label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModelModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="modelForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Модалка Категории (Услуг/Товаров) -->
        <div v-if="isCategoryModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingCategory ? 'Редактирование категории' : 'Новая категория' }}</h3>
                    <button @click="closeCategoryModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitCategory" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                            <input v-model="categoryForm.name" type="text" required placeholder="Например: Мойка" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="categoryForm.errors.name" class="text-xs text-danger mt-1">{{ categoryForm.errors.name }}</span>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeCategoryModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="categoryForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Модалка Универсального Справочника -->
        <div v-if="isLookupModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingLookup ? 'Редактирование записи' : 'Новая запись' }}</h3>
                    <button @click="closeLookupModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitLookup" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Значение <span class="text-danger">*</span></label>
                            <input v-model="lookupForm.value" type="text" required placeholder="Введите значение" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="lookupForm.errors.value" class="text-xs text-danger mt-1">{{ lookupForm.errors.value }}</span>
                        </div>
                        
                        <div v-if="activeTab === 'client_role'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цвет метки</label>
                            <div class="flex flex-wrap gap-2">
                                <label v-for="color in groupColors" :key="color.value" class="cursor-pointer">
                                    <input type="radio" v-model="lookupForm.color" :value="color.value" class="sr-only" />
                                    <span :class="[color.class, lookupForm.color === color.value ? 'ring-2 ring-offset-1 ring-gray-400 dark:ring-gray-500' : '', 'inline-flex items-center px-2.5 py-1 rounded text-xs font-bold uppercase tracking-wider transition-all']">
                                        {{ color.label }}
                                    </span>
                                </label>
                            </div>
                        </div>

                        <div class="flex items-center pt-2">
                            <div @click="lookupForm.is_active = !lookupForm.is_active" :class="[lookupForm.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[lookupForm.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="lookupForm.is_active = !lookupForm.is_active">Активно</label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeLookupModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="lookupForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

    </AuthenticatedLayout>
</template>