<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import BulkActions from '@/Components/BulkActions.vue';
import { Head, useForm, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

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

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const selectAll = computed({
    get: () => props.customFields.length > 0 && selectedIds.value.length === props.customFields.length,
    set: (value) => {
        if (value) {
            selectedIds.value = props.customFields.map(f => f.id);
        } else {
            selectedIds.value = [];
        }
    }
});

const bulkDelete = () => {
    if (confirm(`Удалить выбранные поля (${selectedIds.value.length})? Это действие скроет их из интерфейса.`)) {
        router.post(route('settings.custom-fields.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('settings.custom-fields.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `custom_fields_export_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
    } catch (error) {
        console.error("Export failed", error);
        alert("Ошибка при экспорте данных");
    }
};
// ----------------------------------------

const entityTypes = {
    'client': 'Клиент',
    'vehicle': 'Автомобиль',
    'work_order': 'Заказ-наряд',
    'employee': 'Сотрудник',
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

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            
            <!-- Навигация по настройкам (Attex Tabs) -->
            <SettingsNav />

            <!-- Page Helper (Система подсказок) -->
            <PageHelper title="Для чего нужны кастомные поля?">
                <p>Кастомные поля позволяют вам расширять стандартные карточки (Клиентов, Автомобилей, Заказов) под специфику вашего бизнеса без привлечения программистов.</p>
                <p>Если вы отметите поле как <strong>«Использовать в фильтрах»</strong>, оно появится в панели поиска, и вы сможете легко находить нужные записи (например, отфильтровать всех клиентов по полю «Источник рекламы»).</p>
            </PageHelper>

            <!-- Header Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Конструктор полей</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Добавляйте собственные поля в карточки клиентов, автомобилей и заказов
                    </p>
                </div>
                <button
                    @click="openModal()"
                    class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5"
                >
                    <i class="ri-add-line text-base"></i>
                    Добавить поле
                </button>
            </div>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="полей" 
                @export="bulkExport" 
                @delete="bulkDelete" 
            />

            <!-- Table Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-4 w-10 border-b border-gray-200 dark:border-gray-700 text-center">
                                    <input type="checkbox" v-model="selectAll" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Раздел</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название поля</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Тип</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Настройки</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-gray-600 dark:text-gray-300">
                            <tr v-for="field in customFields" :key="field.id" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center">
                                    <input type="checkbox" :value="field.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>
                                <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200">
                                    {{ entityTypes[field.entity_type] || field.entity_type }}
                                </td>
                                <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200">
                                    {{ getLabel(field.label) }}
                                    <div class="text-xs font-normal text-gray-500 mt-0.5">{{ field.key }}</div>
                                </td>
                                <td class="py-4 px-6 text-sm">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600">
                                        {{ fieldTypes[field.type] || field.type }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-sm space-x-2">
                                    <span v-if="field.is_required" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-danger/10 text-danger border border-danger/20" title="Обязательное">Обяз.</span>
                                    <span v-if="field.is_filterable" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-info/10 text-info border border-info/20" title="Можно фильтровать">Фильтр</span>
                                    <span v-if="field.is_visible_in_list" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-success/10 text-success border border-success/20" title="Видно в таблице">В списке</span>
                                </td>
                                <td class="py-4 px-6 text-sm text-right space-x-2">
                                    <button 
                                        @click="openModal(field)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                        title="Редактировать"
                                    >
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button 
                                        @click="deleteField(field)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                        title="Удалить"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="customFields.length === 0">
                                <td colspan="6" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
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
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl lg:max-w-3xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingField ? 'Редактирование поля' : 'Новое кастомное поле' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-5">
                        
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Раздел (Сущность) <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.entity_type" 
                                    :disabled="!!editingField"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:bg-gray-100 dark:disabled:bg-gray-800 disabled:text-gray-400"
                                >
                                    <option v-for="(name, key) in entityTypes" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип поля <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.type" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option v-for="(name, key) in fieldTypes" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ name }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название поля (Лейбл) <span class="text-danger">*</span></label>
                                <input 
                                    v-model="form.label" 
                                    type="text" 
                                    required 
                                    placeholder="Например: Источник рекламы" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Системный ключ (опционально)</label>
                                <input 
                                    v-model="form.key" 
                                    type="text" 
                                    :disabled="!!editingField"
                                    placeholder="ad_source" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500 disabled:bg-gray-100 dark:disabled:bg-gray-800 disabled:text-gray-400" 
                                />
                                <p class="text-xs text-gray-500 mt-1">Оставьте пустым для автогенерации</p>
                            </div>
                        </div>

                        <div v-if="form.type === 'select'">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Варианты выбора <span class="text-danger">*</span></label>
                            <textarea 
                                v-model="form.options" 
                                required
                                rows="2"
                                placeholder="Instagram, 2GIS, Рекомендация, Проезжал мимо" 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
                            ></textarea>
                            <p class="text-xs text-gray-500 mt-1">Введите варианты через запятую</p>
                        </div>

                        <div class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-4 space-y-3">
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">Настройки отображения</h4>
                            
                            <div class="flex items-center pt-2">
                                <div @click="form.is_required = !form.is_required" :class="[form.is_required ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                    <div :class="[form.is_required ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_required = !form.is_required">
                                    Обязательное поле (нельзя сохранить карточку без него)
                                </label>
                            </div>

                            <div class="flex items-center pt-2">
                                <div @click="form.is_filterable = !form.is_filterable" :class="[form.is_filterable ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                    <div :class="[form.is_filterable ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_filterable = !form.is_filterable">
                                    Использовать в фильтрах (поиск по этому полю)
                                </label>
                            </div>

                            <div class="flex items-center pt-2">
                                <div @click="form.is_visible_in_list = !form.is_visible_in_list" :class="[form.is_visible_in_list ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                    <div :class="[form.is_visible_in_list ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                </div>
                                <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_visible_in_list = !form.is_visible_in_list">
                                    Показывать колонку в общей таблице списка
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                            Отмена
                        </button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            Сохранить поле
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>