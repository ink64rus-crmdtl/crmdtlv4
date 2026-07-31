<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    customFields: Array,
});

const isModalOpen = ref(false);
const editingField = ref(null);

const form = useForm({
    entity_type: 'client',
    label: '',
    key: '',
    type: 'text',
    options: '',
    is_required: false,
    is_filterable: false,
    is_visible_in_list: true,
});

const entityTypes = {
    'client': 'Клиент',
    'vehicle': 'Автомобиль',
    'work_order': 'Заказ-наряд',
};

const fieldTypes = {
    'text': 'Текст',
    'number': 'Число',
    'date': 'Дата',
    'select': 'Выпадающий список',
    'checkbox': 'Галочка (Да/Нет)',
};

const getLabel = (labelObj) => {
    if (!labelObj) return '—';
    return labelObj['ru'] || Object.values(labelObj)[0] || '—';
};

const openModal = (field = null) => {
    editingField.value = field;
    if (field) {
        form.entity_type = field.entity_type;
        form.label = getLabel(field.label);
        form.key = field.key;
        form.type = field.type;
        form.options = field.options ? field.options.join(', ') : '';
        form.is_required = Boolean(field.is_required);
        form.is_filterable = Boolean(field.is_filterable);
        form.is_visible_in_list = Boolean(field.is_visible_in_list);
    } else {
        form.reset();
        form.entity_type = 'client';
        form.type = 'text';
        form.is_required = false;
        form.is_filterable = false;
        form.is_visible_in_list = true;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingField.value = null;
    form.reset();
};

const submit = () => {
    if (editingField.value) {
        form.put(route('settings.custom-fields.update', editingField.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('settings.custom-fields.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteField = (field) => {
    if (confirm(`Удалить поле "${getLabel(field.label)}"? Это действие не удалит данные из БД, но скроет поле из интерфейса.`)) {
        form.delete(route('settings.custom-fields.destroy', field.id));
    }
};
</script>

<template>
    <Head title="Кастомные поля" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
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
            <div class="bg-white border border-gray-200/80 rounded-xl shadow-xs dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-xl font-bold text-slate-800 dark:text-gray-200 tracking-tight">Конструктор полей</h1>
                    <p class="text-sm text-slate-500 dark:text-gray-400 mt-1">
                        Добавляйте собственные поля в карточки клиентов, автомобилей и заказов
                    </p>
                </div>
                <button
                    @click="openModal()"
                    class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-600/90 transition-colors shadow-xs"
                >
                    <i class="ri-add-line"></i>
                    Добавить поле
                </button>
            </div>

            <!-- Table Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-xl shadow-xs dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-slate-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-slate-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Раздел</th>
                                <th class="py-3 px-6 text-xs font-bold text-slate-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название поля</th>
                                <th class="py-3 px-6 text-xs font-bold text-slate-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Тип</th>
                                <th class="py-3 px-6 text-xs font-bold text-slate-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Настройки</th>
                                <th class="py-3 px-6 text-xs font-bold text-slate-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-gray-700 text-slate-600 dark:text-gray-300">
                            <tr v-for="field in customFields" :key="field.id" class="hover:bg-slate-50/80 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-6 text-sm font-semibold text-slate-800 dark:text-gray-200">
                                    {{ entityTypes[field.entity_type] || field.entity_type }}
                                </td>
                                <td class="py-4 px-6 text-sm font-bold text-slate-800 dark:text-gray-200">
                                    {{ getLabel(field.label) }}
                                    <div class="text-xs font-normal text-slate-400 mt-0.5">{{ field.key }}</div>
                                </td>
                                <td class="py-4 px-6 text-sm">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium bg-slate-100 text-slate-700 dark:bg-gray-700 dark:text-gray-300 border border-slate-200 dark:border-gray-600">
                                        {{ fieldTypes[field.type] || field.type }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-sm space-x-2">
                                    <span v-if="field.is_required" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-rose-50 text-rose-700 border border-rose-200" title="Обязательное">Обзяз.</span>
                                    <span v-if="field.is_filterable" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-200" title="Можно фильтровать">Фильтр</span>
                                    <span v-if="field.is_visible_in_list" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200" title="Видно в таблице">В списке</span>
                                </td>
                                <td class="py-4 px-6 text-sm text-right space-x-2">
                                    <button 
                                        @click="openModal(field)" 
                                        class="rounded px-2.5 py-1 text-xs font-medium capitalize transition-colors bg-indigo-600 text-white hover:bg-indigo-600/90"
                                    >
                                        Редактировать
                                    </button>
                                    <button 
                                        @click="deleteField(field)" 
                                        class="rounded px-2.5 py-1 text-xs font-medium capitalize transition-colors bg-rose-600 text-white hover:bg-rose-600/90"
                                    >
                                        Удалить
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="customFields.length === 0">
                                <td colspan="5" class="py-12 px-6 text-center text-sm text-slate-400 dark:text-gray-500">
                                    Кастомные поля еще не созданы. Нажмите "Добавить поле".
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Модальное окно (Attex Standard: 50% width) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-xl shadow-2xl dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl lg:max-w-3xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-4 px-6 flex justify-between items-center bg-slate-900 text-white">
                    <h3 class="text-base font-bold tracking-tight">
                        {{ editingField ? 'Редактирование поля' : 'Новое кастомное поле' }}
                    </h3>
                    <button @click="closeModal()" class="text-slate-400 hover:text-white transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-5">
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-gray-300 mb-1.5">Раздел (Сущность) <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.entity_type" 
                                    :disabled="!!editingField"
                                    class="block w-full rounded-md border border-slate-300 dark:border-gray-700 bg-transparent py-2.5 px-3 text-sm text-slate-800 dark:text-gray-200 focus:border-indigo-500 focus:ring-indigo-500 disabled:bg-slate-100 disabled:text-slate-500"
                                >
                                    <option v-for="(name, key) in entityTypes" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-gray-300 mb-1.5">Тип поля <span class="text-rose-500">*</span></label>
                                <select 
                                    v-model="form.type" 
                                    class="block w-full rounded-md border border-slate-300 dark:border-gray-700 bg-transparent py-2.5 px-3 text-sm text-slate-800 dark:text-gray-200 focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option v-for="(name, key) in fieldTypes" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ name }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-gray-300 mb-1.5">Название поля (Лейбл) <span class="text-rose-500">*</span></label>
                                <input 
                                    v-model="form.label" 
                                    type="text" 
                                    required 
                                    placeholder="Например: Источник рекламы" 
                                    class="block w-full rounded-md border border-slate-300 dark:border-gray-700 bg-transparent py-2.5 px-3 text-sm text-slate-800 dark:text-gray-200 focus:border-indigo-500 focus:ring-indigo-500 placeholder-slate-400" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-gray-300 mb-1.5">Системный ключ (опционально)</label>
                                <input 
                                    v-model="form.key" 
                                    type="text" 
                                    :disabled="!!editingField"
                                    placeholder="ad_source" 
                                    class="block w-full rounded-md border border-slate-300 dark:border-gray-700 bg-transparent py-2.5 px-3 text-sm text-slate-800 dark:text-gray-200 focus:border-indigo-500 focus:ring-indigo-500 placeholder-slate-400 disabled:bg-slate-100 disabled:text-slate-500" 
                                />
                                <p class="text-xs text-slate-500 mt-1">Оставьте пустым для автогенерации</p>
                            </div>
                        </div>

                        <div v-if="form.type === 'select'">
                            <label class="block text-sm font-semibold text-slate-700 dark:text-gray-300 mb-1.5">Варианты выбора <span class="text-rose-500">*</span></label>
                            <textarea 
                                v-model="form.options" 
                                required
                                rows="2"
                                placeholder="Instagram, 2GIS, Рекомендация, Проезжал мимо" 
                                class="block w-full rounded-md border border-slate-300 dark:border-gray-700 bg-transparent py-2.5 px-3 text-sm text-slate-800 dark:text-gray-200 focus:border-indigo-500 focus:ring-indigo-500 placeholder-slate-400"
                            ></textarea>
                            <p class="text-xs text-slate-500 mt-1">Введите варианты через запятую</p>
                        </div>

                        <div class="border-t border-slate-200 dark:border-gray-700 pt-4 space-y-3">
                            <h4 class="text-sm font-bold text-slate-800 dark:text-gray-200 mb-3">Настройки отображения</h4>
                            
                            <div class="flex items-center">
                                <div @click="form.is_required = !form.is_required" :class="[form.is_required ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                    <div :class="[form.is_required ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                clip-path</div>
                                <label class="ml-3 block text-sm font-medium text-slate-700 dark:text-gray-300 cursor-pointer" @click="form.is_required = !form.is_required">
                                    Обязательное поле (нельзя сохранить карточку без него)
                                </label>
                            </div>

                            <div class="flex items-center">
                                <div @click="form.is_filterable = !form.is_filterable" :class="[form.is_filterable ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                    <div :class="[form.is_filterable ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-3 block text-sm font-medium text-slate-700 dark:text-gray-300 cursor-pointer" @click="form.is_filterable = !form.is_filterable">
                                    Использовать в фильтрах (поиск по этому полю)
                                </label>
                            </div>

                            <div class="flex items-center">
                                <div @click="form.is_visible_in_list = !form.is_visible_in_list" :class="[form.is_visible_in_list ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                    <div :class="[form.is_visible_in_list ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-3 block text-sm font-medium text-slate-700 dark:text-gray-300 cursor-pointer" @click="form.is_visible_in_list = !form.is_visible_in_list">
                                    Показывать колонку в общей таблице списка
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-slate-200 dark:border-gray-700 py-4 px-6 bg-slate-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="rounded px-4 py-2 text-sm font-medium transition-colors bg-slate-100 text-slate-700 hover:bg-slate-200">
                            Отмена
                        </button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-1.5 rounded-md bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-600/90 transition-colors shadow-xs disabled:opacity-50">
                            Сохранить поле
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>