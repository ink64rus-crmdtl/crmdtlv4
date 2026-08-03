<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    vehicle: Object,
    customFieldsData: Array,
    clients: Array,
    makes: Array,
    models: Array,
    strictPlateValidation: Boolean,
    tenantCountry: String,
    customFieldDefs: Array,
});

const isModalOpen = ref(false);

const form = useForm({
    client_id: '',
    vehicle_make_id: '',
    vehicle_model_id: '',
    plate_number: '',
    vin: '',
    year: '',
    custom_fields: {},
});

// Фильтрация моделей по выбранной марке
const filteredModels = computed(() => {
    if (!form.vehicle_make_id) return [];
    return props.models.filter(m => m.vehicle_make_id === form.vehicle_make_id);
});

const openModal = () => {
    form.client_id = props.vehicle.client_id;
    form.vehicle_make_id = props.vehicle.vehicle_make_id || '';
    form.vehicle_model_id = props.vehicle.vehicle_model_id || '';
    form.plate_number = props.vehicle.plate_number || '';
    form.vin = props.vehicle.vin || '';
    form.year = props.vehicle.year || '';
    
    const cf = {};
    props.customFieldDefs.forEach(def => {
        const existingVal = props.customFieldsData.find(d => d.definition.id === def.id)?.value;
        cf[def.key] = existingVal !== undefined && existingVal !== null 
            ? existingVal 
            : (def.type === 'checkbox' ? false : '');
    });
    form.custom_fields = cf;
    
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    form.put(route('crm.vehicles.update', props.vehicle.id), {
        onSuccess: () => closeModal(),
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
    <Head :title="`Автомобиль: ${vehicle.make ? vehicle.make.name : ''} ${vehicle.vehicle_model ? vehicle.vehicle_model.name : ''}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between w-full">
                <div class="flex items-center gap-2">
                    <Link :href="route('crm.vehicles.index')" class="text-gray-500 hover:text-primary transition-colors">
                        <i class="ri-arrow-left-line"></i> Автомобили
                    </Link>
                    <span class="text-gray-400">/</span>
                    <span class="font-semibold text-gray-800 dark:text-gray-200">{{ vehicle.make ? vehicle.make.name : '' }} {{ vehicle.vehicle_model ? vehicle.vehicle_model.name : '' }}</span>
                </div>
                <button @click="openModal" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">
                    <i class="ri-pencil-line mr-1.5"></i> Редактировать
                </button>
            </div>
        </template>

        <!-- TRI-STATE 2: Полная карточка (w-[99%] mx-auto для Fluid-дизайна) -->
        <div class="w-[99%] mx-auto flex flex-col lg:flex-row gap-6 font-sans text-slate-600">
            
            <!-- Левая колонка: About (Свойства сущности) -->
            <div class="w-full lg:w-1/4 space-y-6 flex-shrink-0">
                
                <!-- Аватар и статус -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex flex-col items-center text-center">
                    <div class="w-24 h-24 rounded bg-primary/10 flex items-center justify-center text-primary font-bold text-4xl mb-4">
                        <i class="ri-car-line"></i>
                    </div>
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight mb-1">
                        {{ vehicle.make ? vehicle.make.name : '' }} {{ vehicle.vehicle_model ? vehicle.vehicle_model.name : '' }}
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mb-4">
                        {{ vehicle.year ? vehicle.year + ' г.в.' : 'Год не указан' }}
                    </p>
                    <div class="flex flex-wrap justify-center gap-2">
                        <span class="inline-flex items-center gap-1.5 py-1 px-3 rounded-md text-xs font-bold tracking-wide uppercase bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300 border border-gray-200 dark:border-gray-600">
                            {{ vehicle.plate_number || 'Госномер не указан' }}
                        </span>
                    </div>
                </div>

                <!-- Основная информация -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Идентификация</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">VIN код</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200 font-mono">{{ vehicle.vin || '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Марка</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ vehicle.make ? vehicle.make.name : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Модель</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ vehicle.vehicle_model ? vehicle.vehicle_model.name : '—' }}</p>
                        </div>
                        <div v-if="vehicle.vehicle_model?.body_type">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Тип кузова</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ vehicle.vehicle_model.body_type }}</p>
                        </div>
                        <div v-if="vehicle.vehicle_model?.category">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Категория</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ vehicle.vehicle_model.category }}</p>
                        </div>
                    </div>
                </div>

                <!-- Кастомные поля (EAV) -->
                <div v-if="customFieldsData && customFieldsData.length > 0" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Дополнительная информация</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div v-for="field in customFieldsData" :key="field.definition.id">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ getLocalizedLabel(field.definition.label) }}</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                <template v-if="field.definition.type === 'checkbox'">
                                    {{ field.value == '1' ? 'Да' : 'Нет' }}
                                </template>
                                <template v-else>
                                    {{ field.value || '—' }}
                                </template>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Центральная колонка: KPI и Таймлайн (Activity) -->
            <div class="w-full lg:w-2/4 space-y-6">
                
                <!-- KPI Dashboard -->
                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-4 flex items-center gap-4">
                        <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center text-lg shrink-0 dark:bg-blue-900/30 dark:text-blue-400">
                            <i class="ri-tools-line"></i>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Визитов в сервис</p>
                            <p class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight">0</p>
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-4 flex items-center gap-4">
                        <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center text-lg shrink-0 dark:bg-emerald-900/30 dark:text-emerald-400">
                            <i class="ri-money-dollar-circle-line"></i>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-0.5">Сумма чеков</p>
                            <p class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight">0 ₽</p>
                        </div>
                    </div>
                </div>

                <!-- Таймлайн -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col h-full min-h-[400px]">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">История обслуживания</h3>
                    </div>
                    <div class="flex-1 p-6 flex flex-col items-center justify-center text-center">
                        <div class="w-16 h-16 bg-gray-100 dark:bg-gray-800 rounded-full flex items-center justify-center mb-4">
                            <i class="ri-history-line text-3xl text-gray-400 dark:text-gray-500"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-2">История пуста</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 max-w-sm">
                            Здесь будет отображаться история всех заказ-нарядов, рекомендаций мастеров и выполненных работ по этому автомобилю.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Правая колонка: Владелец и Связи -->
            <div class="w-full lg:w-1/4 space-y-6 flex-shrink-0">
                
                <!-- Карточка владельца -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Владелец</h3>
                        <Link v-if="vehicle.client" :href="route('crm.clients.show', vehicle.client.id)" class="text-primary hover:text-primary-600 transition-colors text-sm font-medium flex items-center gap-1">
                            Перейти <i class="ri-arrow-right-s-line"></i>
                        </Link>
                    </div>
                    <div class="p-6">
                        <div v-if="vehicle.client" class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold shrink-0">
                                {{ vehicle.client.name.charAt(0) }}
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-800 dark:text-gray-200 leading-tight">{{ vehicle.client.name }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    {{ vehicle.client.type === 'b2b' ? 'Юридическое лицо' : 'Физическое лицо' }}
                                </p>
                            </div>
                        </div>
                        <div v-if="vehicle.client" class="space-y-3 pt-4 border-t border-gray-100 dark:border-gray-700/50">
                            <a v-if="vehicle.client.phone" :href="'tel:' + vehicle.client.phone" class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 hover:text-primary transition-colors">
                                <i class="ri-phone-line text-gray-400"></i> {{ vehicle.client.phone }}
                            </a>
                            <a v-if="vehicle.client.email" :href="'mailto:' + vehicle.client.email" class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 hover:text-primary transition-colors">
                                <i class="ri-mail-line text-gray-400"></i> {{ vehicle.client.email }}
                            </a>
                        </div>
                        <div v-else class="text-sm text-gray-500">Владелец не указан</div>
                    </div>
                </div>

                <!-- Активные Заказ-наряды (Заглушка) -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Заказ-наряды (0)</h3>
                        <button class="text-primary hover:text-primary-600 transition-colors text-sm font-medium flex items-center gap-1">
                            <i class="ri-add-line"></i> Создать
                        </button>
                    </div>
                    <div class="p-6 text-center py-8">
                        <i class="ri-briefcase-line text-3xl text-gray-300 dark:text-gray-600 mb-2 block"></i>
                        <p class="text-sm text-gray-500 dark:text-gray-400">История заказов пуста</p>
                    </div>
                </div>

            </div>

        </div>

        <!-- Модальное окно редактирования (Focused Modal) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Редактирование автомобиля
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div v-if="form.errors.plate_number" class="p-3 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-center gap-2">
                            <i class="ri-error-warning-line text-lg"></i> {{ form.errors.plate_number }}
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Владелец (Клиент) <span class="text-danger">*</span></label>
                            <select 
                                v-model="form.client_id" 
                                required
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                            >
                                <option value="" disabled class="bg-white dark:bg-gray-800">Выберите клиента...</option>
                                <option v-for="client in clients" :key="client.id" :value="client.id" class="bg-white dark:bg-gray-800">
                                    {{ client.name }} {{ client.phone ? `(${client.phone})` : '' }}
                                </option>
                            </select>
                            <span v-if="form.errors.client_id" class="text-xs text-danger mt-1">{{ form.errors.client_id }}</span>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Марка <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.vehicle_make_id" 
                                    @change="form.vehicle_model_id = ''"
                                    required
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите марку...</option>
                                    <option v-for="make in makes" :key="make.id" :value="make.id" class="bg-white dark:bg-gray-800">{{ make.name }}</option>
                                </select>
                                <span v-if="form.errors.vehicle_make_id" class="text-xs text-danger mt-1">{{ form.errors.vehicle_make_id }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Модель <span class="text-danger">*</span></label>
                                <select 
                                    v-model="form.vehicle_model_id" 
                                    required
                                    :disabled="!form.vehicle_make_id"
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:bg-gray-100 dark:disabled:bg-gray-800 disabled:text-gray-400"
                                >
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите модель...</option>
                                    <option v-for="model in filteredModels" :key="model.id" :value="model.id" class="bg-white dark:bg-gray-800">{{ model.name }}</option>
                                </select>
                                <span v-if="form.errors.vehicle_model_id" class="text-xs text-danger mt-1">{{ form.errors.vehicle_model_id }}</span>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Госномер</label>
                            <input 
                                v-model="form.plate_number" 
                                type="text" 
                                :placeholder="strictPlateValidation ? (tenantCountry === 'RU' ? 'А 000 АА 77' : '0000 AA-7') : 'Любой формат'" 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500 uppercase" 
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">VIN код</label>
                            <input 
                                v-model="form.vin" 
                                type="text" 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 font-mono uppercase" 
                            />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Год выпуска</label>
                            <input 
                                v-model="form.year" 
                                type="number" 
                                min="1900" 
                                :max="new Date().getFullYear() + 1"
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" 
                            />
                        </div>

                        <!-- Кастомные поля (EAV) -->
                        <div v-if="customFieldDefs.length > 0" class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-4 space-y-4">
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-2">Дополнительные поля</h4>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div v-for="def in customFieldDefs" :key="def.id">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                        {{ getLocalizedLabel(def.label) }} <span v-if="def.is_required" class="text-danger">*</span>
                                    </label>
                                    
                                    <template v-if="def.type === 'text'">
                                        <input type="text" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                    </template>
                                    
                                    <template v-else-if="def.type === 'number'">
                                        <input type="number" step="any" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                    </template>
                                    
                                    <template v-else-if="def.type === 'date'">
                                        <input type="date" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                    </template>
                                    
                                    <template v-else-if="def.type === 'select'">
                                        <select v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                            <option value="" disabled class="bg-white dark:bg-gray-800">Выберите...</option>
                                            <option v-for="opt in def.options" :key="opt" :value="opt" class="bg-white dark:bg-gray-800">{{ opt }}</option>
                                        </select>
                                    </template>
                                    
                                    <template v-else-if="def.type === 'checkbox'">
                                        <div class="flex items-center pt-2">
                                            <div @click="form.custom_fields[def.key] = !form.custom_fields[def.key]" :class="[form.custom_fields[def.key] ? 'bg-primary' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                                <div :class="[form.custom_fields[def.key] ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>

                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

    </AuthenticatedLayout>
</template>