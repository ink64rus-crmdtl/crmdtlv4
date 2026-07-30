<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref, onMounted } from 'vue';

const props = defineProps({
    roles: Array,
    modules: Array,
    entities: Array,
    existingFieldPermissions: Array,
    scopes: Object,
});

const activeMainTab = ref('modules'); // 'modules', 'fields', 'scopes'
const activeEntityTab = ref(props.entities.length > 0 ? props.entities[0].key : null);
const activeScopeTab = ref('branches'); // 'branches', 'legalEntities', 'businessDirections', 'warehouses', 'accounts'

// Состояние матриц
const moduleMatrix = ref({});
const fieldMatrix = ref({});
const scopeMatrix = ref({});

// Вспомогательная функция для локализации JSON-лейблов
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

// Инициализация матриц (выполняется синхронно)
// 1. Инициализация матрицы модулей
props.roles.forEach(role => {
    moduleMatrix.value[role.id] = {};
    const rolePermissions = role.permissions ? role.permissions.map(p => p.name) : [];

    props.modules.forEach(mod => {
        if (mod.required_permission) {
            moduleMatrix.value[role.id][mod.required_permission] = rolePermissions.includes(mod.required_permission);
        }
    });
});

// 2. Инициализация матрицы полей
props.entities.forEach(entity => {
    fieldMatrix.value[entity.key] = {};
    entity.fields.forEach(field => {
        fieldMatrix.value[entity.key][field.key] = {};
        props.roles.forEach(role => {
            let canView = true;
            let canEdit = true;

            const existing = props.existingFieldPermissions.find(
                p => p.entity_type === entity.key && p.field_key === field.key && p.role_id === role.id
            );

            if (existing) {
                canView = Boolean(existing.can_view);
                canEdit = Boolean(existing.can_edit);
            }

            fieldMatrix.value[entity.key][field.key][role.id] = {
                can_view: canView,
                can_edit: canEdit,
            };
        });
    });
});

// 3. Инициализация матрицы Scopes (Доступ к данным)
const scopeTypes = ['branches', 'legalEntities', 'businessDirections', 'warehouses', 'accounts'];
scopeTypes.forEach(type => {
    scopeMatrix.value[type] = {};
    if (props.scopes[type]) {
        props.scopes[type].forEach(item => {
            scopeMatrix.value[type][item.id] = {};
            props.roles.forEach(role => {
                // Проверяем, есть ли у роли связь с этим элементом
                const hasItem = role[type] && role[type].some(rItem => rItem.id === item.id);
                scopeMatrix.value[type][item.id][role.id] = hasItem;
            });
        });
    }
});

// Формы
const moduleForm = useForm({ role_permissions: {} });
const fieldForm = useForm({ permissions: [] });
const scopeForm = useForm({ role_scopes: {} });

const submitModules = () => {
    const payload = {};
    props.roles.forEach(role => {
        payload[role.id] = [];
        props.modules.forEach(mod => {
            if (mod.required_permission && moduleMatrix.value[role.id][mod.required_permission]) {
                payload[role.id].push(mod.required_permission);
            }
        });
    });

    moduleForm.role_permissions = payload;
    moduleForm.post(route('settings.roles-permissions.modules.store'));
};

const submitFields = () => {
    const payload = [];
    Object.keys(fieldMatrix.value).forEach(entityType => {
        Object.keys(fieldMatrix.value[entityType]).forEach(fieldKey => {
            Object.keys(fieldMatrix.value[entityType][fieldKey]).forEach(roleId => {
                payload.push({
                    role_id: parseInt(roleId),
                    entity_type: entityType,
                    field_key: fieldKey,
                    can_view: fieldMatrix.value[entityType][fieldKey][roleId].can_view,
                    can_edit: fieldMatrix.value[entityType][fieldKey][roleId].can_edit,
                });
            });
        });
    });

    fieldForm.permissions = payload;
    fieldForm.post(route('settings.roles-permissions.fields.store'));
};

const submitScopes = () => {
    const payload = {};
    const typeMap = {
        branches: 'branches',
        legalEntities: 'legal_entities',
        businessDirections: 'business_directions',
        warehouses: 'warehouses',
        accounts: 'accounts'
    };

    props.roles.forEach(role => {
        payload[role.id] = {
            branches: [],
            legal_entities: [],
            business_directions: [],
            warehouses: [],
            accounts: []
        };

        scopeTypes.forEach(type => {
            if (props.scopes[type]) {
                props.scopes[type].forEach(item => {
                    if (scopeMatrix.value[type][item.id][role.id]) {
                        payload[role.id][typeMap[type]].push(item.id);
                    }
                });
            }
        });
    });

    scopeForm.role_scopes = payload;
    scopeForm.post(route('settings.roles-permissions.scopes.store'));
};

// Логика полей: если снимаем галочку "Чтение", то "Запись" тоже должна сниматься
const handleViewChange = (entityType, fieldKey, roleId) => {
    if (!fieldMatrix.value[entityType][fieldKey][roleId].can_view) {
        fieldMatrix.value[entityType][fieldKey][roleId].can_edit = false;
    }
};

// Логика полей: если ставим галочку "Запись", то "Чтение" тоже должно ставиться
const handleEditChange = (entityType, fieldKey, roleId) => {
    if (fieldMatrix.value[entityType][fieldKey][roleId].can_edit) {
        fieldMatrix.value[entityType][fieldKey][roleId].can_view = true;
    }
};
</script>

<template>
    <Head title="Роли и Права" />

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
                    <Link
                        :href="route('settings.custom-fields.index')"
                        :class="[
                            route().current('settings.custom-fields.index')
                                ? 'border-primary text-primary'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600',
                            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors'
                        ]"
                    >
                        Кастомные поля
                    </Link>
                    <Link
                        :href="route('settings.roles-permissions.index')"
                        :class="[
                            route().current('settings.roles-permissions.index')
                                ? 'border-primary text-primary'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600',
                            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors'
                        ]"
                    >
                        Роли и Права
                    </Link>
                </nav>
            </div>

            <!-- Header Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Центр управления правами</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Настройте глобальные политики доступа к разделам, полям и данным для каждой должности (Роли).
                    </p>
                </div>
            </div>

            <!-- Content Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden flex flex-col">
                
                <!-- Главные вкладки -->
                <div class="flex space-x-6 border-b border-gray-200 dark:border-gray-700 px-6 bg-gray-50/50 dark:bg-gray-800/50">
                    <button
                        @click="activeMainTab = 'modules'"
                        :class="[
                            activeMainTab === 'modules' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2',
                            'py-3.5 px-2 text-sm transition-colors focus:outline-none'
                        ]"
                    >
                        Доступ к разделам (Меню)
                    </button>
                    <button
                        @click="activeMainTab = 'fields'"
                        :class="[
                            activeMainTab === 'fields' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2',
                            'py-3.5 px-2 text-sm transition-colors focus:outline-none'
                        ]"
                    >
                        Доступ к полям
                    </button>
                    <button
                        @click="activeMainTab = 'scopes'"
                        :class="[
                            activeMainTab === 'scopes' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2',
                            'py-3.5 px-2 text-sm transition-colors focus:outline-none'
                        ]"
                    >
                        Доступ к данным (Scopes)
                    </button>
                </div>

                <!-- Вкладка 1: Доступ к разделам -->
                <div v-if="activeMainTab === 'modules'" class="flex flex-col">
                    <div class="overflow-x-auto w-full p-0">
                        <table class="min-w-full text-left whitespace-nowrap">
                            <thead class="bg-white dark:bg-[#313a46]">
                                <tr>
                                    <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 w-1/3">
                                        Раздел меню
                                    </th>
                                    <th v-for="role in roles" :key="role.id" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-center border-l border-gray-200 dark:border-gray-700">
                                        {{ role.name }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                                <template v-for="module in modules" :key="module.id">
                                    <tr v-if="module.required_permission" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                        <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200">
                                            <div class="flex items-center gap-2">
                                                <i :class="[module.icon, 'text-primary text-lg']"></i>
                                                <span>{{ getLocalizedLabel(module.label) }}</span>
                                            </div>
                                        </td>
                                        
                                        <td v-for="role in roles" :key="role.id" class="py-4 px-6 text-sm text-center border-l border-gray-200 dark:border-gray-700">
                                            <div class="flex justify-center">
                                                <!-- Attex Toggle Switch -->
                                                <div @click="moduleMatrix[role.id][module.required_permission] = !moduleMatrix[role.id][module.required_permission]" :class="[moduleMatrix[role.id][module.required_permission] ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                                    <div :class="[moduleMatrix[role.id][module.required_permission] ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex justify-end p-6 border-t border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/20">
                        <button
                            @click="submitModules"
                            :disabled="moduleForm.processing"
                            class="inline-flex items-center justify-center rounded px-6 py-2.5 text-sm font-semibold transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50 tracking-wide gap-1.5"
                        >
                            <i class="ri-save-3-line text-base"></i>
                            <span v-if="moduleForm.processing">Сохранение...</span>
                            <span v-else>Сохранить права разделов</span>
                        </button>
                    </div>
                </div>

                <!-- Вкладка 2: Доступ к полям -->
                <div v-if="activeMainTab === 'fields'" class="flex flex-col">
                    <!-- Внутренние вкладки сущностей -->
                    <div class="flex space-x-6 border-b border-gray-200 dark:border-gray-700 px-6 bg-white dark:bg-[#313a46]">
                        <button
                            v-for="entity in entities"
                            :key="entity.key"
                            @click="activeEntityTab = entity.key"
                            :class="[
                                activeEntityTab === entity.key ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2',
                                'py-3 px-2 text-sm transition-colors focus:outline-none'
                            ]"
                        >
                            {{ entity.label }}
                        </button>
                    </div>

                    <div class="overflow-x-auto w-full p-0">
                        <table class="min-w-full text-left whitespace-nowrap">
                            <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                <tr>
                                    <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 w-1/3">
                                        Поле
                                    </th>
                                    <th v-for="role in roles" :key="role.id" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-center border-l border-gray-200 dark:border-gray-700">
                                        {{ role.name }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody v-if="fieldMatrix[activeEntityTab]" class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                                <tr v-for="field in entities.find(e => e.key === activeEntityTab).fields" :key="field.key" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                    <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200">
                                        <div class="flex items-center gap-2">
                                            <span>{{ field.label }}</span>
                                            <span v-if="field.is_custom" class="inline-flex items-center gap-1.5 py-0.5 px-1.5 rounded text-[10px] font-bold bg-primary/10 text-primary uppercase tracking-wider">Кастомное</span>
                                        </div>
                                        <div class="text-xs font-normal text-gray-400 dark:text-gray-500 mt-0.5">{{ field.key }}</div>
                                    </td>
                                    
                                    <td v-for="role in roles" :key="role.id" class="py-4 px-6 text-sm text-center border-l border-gray-200 dark:border-gray-700">
                                        <div class="flex items-center justify-center gap-6">
                                            <!-- Чтение -->
                                            <label class="flex items-center cursor-pointer group">
                                                <input 
                                                    type="checkbox" 
                                                    v-model="fieldMatrix[activeEntityTab][field.key][role.id].can_view"
                                                    @change="handleViewChange(activeEntityTab, field.key, role.id)"
                                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" 
                                                />
                                                <span class="ml-2 text-xs font-medium text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">Чтение</span>
                                            </label>

                                            <!-- Запись -->
                                            <label class="flex items-center cursor-pointer group">
                                                <input 
                                                    type="checkbox" 
                                                    v-model="fieldMatrix[activeEntityTab][field.key][role.id].can_edit"
                                                    @change="handleEditChange(activeEntityTab, field.key, role.id)"
                                                    class="h-4 w-4 rounded border-gray-300 text-success focus:ring-success cursor-pointer" 
                                                />
                                                <span class="ml-2 text-xs font-medium text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">Запись</span>
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex justify-end p-6 border-t border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/20">
                        <button
                            @click="submitFields"
                            :disabled="fieldForm.processing"
                            class="inline-flex items-center justify-center rounded px-6 py-2.5 text-sm font-semibold transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50 tracking-wide gap-1.5"
                        >
                            <i class="ri-save-3-line text-base"></i>
                            <span v-if="fieldForm.processing">Сохранение...</span>
                            <span v-else>Сохранить права полей</span>
                        </button>
                    </div>
                </div>

                <!-- Вкладка 3: Доступ к данным (Scopes) -->
                <div v-if="activeMainTab === 'scopes'" class="flex flex-col">
                    <!-- Внутренние вкладки Scopes -->
                    <div class="flex space-x-6 border-b border-gray-200 dark:border-gray-700 px-6 bg-white dark:bg-[#313a46]">
                        <button
                            @click="activeScopeTab = 'branches'"
                            :class="[activeScopeTab === 'branches' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3 px-2 text-sm transition-colors focus:outline-none']"
                        >
                            Филиалы
                        </button>
                        <button
                            @click="activeScopeTab = 'legalEntities'"
                            :class="[activeScopeTab === 'legalEntities' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3 px-2 text-sm transition-colors focus:outline-none']"
                        >
                            Юрлица
                        </button>
                        <button
                            @click="activeScopeTab = 'businessDirections'"
                            :class="[activeScopeTab === 'businessDirections' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3 px-2 text-sm transition-colors focus:outline-none']"
                        >
                            Направления
                        </button>
                        <button
                            @click="activeScopeTab = 'warehouses'"
                            :class="[activeScopeTab === 'warehouses' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3 px-2 text-sm transition-colors focus:outline-none']"
                        >
                            Склады
                        </button>
                        <button
                            @click="activeScopeTab = 'accounts'"
                            :class="[activeScopeTab === 'accounts' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3 px-2 text-sm transition-colors focus:outline-none']"
                        >
                            Счета
                        </button>
                    </div>

                    <div class="overflow-x-auto w-full p-0">
                        <table class="min-w-full text-left whitespace-nowrap">
                            <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                <tr>
                                    <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 w-1/3">
                                        Объект доступа
                                    </th>
                                    <th v-for="role in roles" :key="role.id" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-center border-l border-gray-200 dark:border-gray-700">
                                        {{ role.name }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody v-if="scopeMatrix[activeScopeTab]" class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                                <tr v-for="item in scopes[activeScopeTab]" :key="item.id" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                    <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200">
                                        {{ item.name }}
                                    </td>
                                    
                                    <td v-for="role in roles" :key="role.id" class="py-4 px-6 text-sm text-center border-l border-gray-200 dark:border-gray-700">
                                        <div class="flex justify-center">
                                            <!-- Attex Toggle Switch -->
                                            <div @click="scopeMatrix[activeScopeTab][item.id][role.id] = !scopeMatrix[activeScopeTab][item.id][role.id]" :class="[scopeMatrix[activeScopeTab][item.id][role.id] ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                                <div :class="[scopeMatrix[activeScopeTab][item.id][role.id] ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <tr v-if="!scopes[activeScopeTab] || scopes[activeScopeTab].length === 0">
                                    <td :colspan="roles.length + 1" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                        Нет данных для настройки.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="flex justify-end p-6 border-t border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/20">
                        <button
                            @click="submitScopes"
                            :disabled="scopeForm.processing"
                            class="inline-flex items-center justify-center rounded px-6 py-2.5 text-sm font-semibold transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50 tracking-wide gap-1.5"
                        >
                            <i class="ri-save-3-line text-base"></i>
                            <span v-if="scopeForm.processing">Сохранение...</span>
                            <span v-else>Сохранить доступы к данным</span>
                        </button>
                    </div>
                </div>

            </div>

        </div>
    </AuthenticatedLayout>
</template>