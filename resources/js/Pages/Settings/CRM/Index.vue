<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';

const props = defineProps({
    strictPlateValidation: Boolean,
    pricingBasis: String,
    calendarColorSource: { type: String, default: 'status' },
});

const form = useForm({
    strict_plate_validation: props.strictPlateValidation,
    pricing_basis: props.pricingBasis || 'none',
    calendar_color_source: props.calendarColorSource || 'status',
});

const submit = () => {
    form.post(route('settings.crm.store'));
};
</script>

<template>
    <Head title="Настройки CRM" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            
            <!-- Навигация по настройкам (Attex Tabs) -->
            <SettingsNav />

            <PageHelper title="Настройки CRM и Ценообразования">
                <p><strong>Строгая проверка госномеров:</strong> Если включена, система будет требовать ввода номеров автомобилей в строгом соответствии с форматом вашей страны.</p>
                <p class="mt-2"><strong>База ценообразования услуг:</strong> Вы можете настроить прайс-лист так, чтобы цена услуги зависела от Типа кузова (Седан, Кроссовер) или от Класса автомобиля (Класс 1, Класс 2). Если выбрана зависимость, в карточке услуги появится матрица цен.</p>
            </PageHelper>

            <!-- Header Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Настройки CRM</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Базовые правила работы с клиентской базой и автомобилями
                    </p>
                </div>
            </div>

            <!-- Content Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6">
                <form @submit.prevent="submit" class="space-y-6">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex items-center p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                            <div @click="form.strict_plate_validation = !form.strict_plate_validation" :class="[form.strict_plate_validation ? 'bg-success' : 'bg-gray-300 dark:bg-gray-600', 'flex items-center h-6 w-11 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                                <div :class="[form.strict_plate_validation ? 'translate-x-6' : 'translate-x-1', 'h-4 w-4 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <div class="ml-4">
                                <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.strict_plate_validation = !form.strict_plate_validation">
                                    Включить строгую проверку госномеров
                                </label>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Ввод только по маске страны (латиница/кириллица).</p>
                            </div>
                        </div>

                        <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                            <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 mb-1">Цвет записи в календаре</label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Чем определяется цвет плашки записи в календаре. Свой цвет задаётся в карточке сотрудника или поста — если не задан, используется цвет по статусу.</p>
                            <select
                                v-model="form.calendar_color_source"
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                            >
                                <option value="status" class="bg-white dark:bg-gray-800">По статусу записи</option>
                                <option value="employee" class="bg-white dark:bg-gray-800">По исполнителю (мастеру)</option>
                                <option value="post" class="bg-white dark:bg-gray-800">По посту</option>
                            </select>
                        </div>
                    </div>

                    <div class="p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                        <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 mb-3">База ценообразования услуг</label>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <label :class="[form.pricing_basis === 'none' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-4 shadow-sm focus:outline-none transition-all items-center gap-3']">
                                <input type="radio" v-model="form.pricing_basis" value="none" class="sr-only" />
                                <i class="ri-price-tag-3-line text-2xl text-gray-400"></i>
                                <div>
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">Единая цена</span>
                                    <span class="block text-xs text-gray-500 mt-0.5">Цена не зависит от авто</span>
                                </div>
                            </label>
                            <label :class="[form.pricing_basis === 'vehicle_body' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-4 shadow-sm focus:outline-none transition-all items-center gap-3']">
                                <input type="radio" v-model="form.pricing_basis" value="vehicle_body" class="sr-only" />
                                <i class="ri-car-line text-2xl text-gray-400"></i>
                                <div>
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">По типу кузова</span>
                                    <span class="block text-xs text-gray-500 mt-0.5">Седан, Кроссовер и т.д.</span>
                                </div>
                            </label>
                            <label :class="[form.pricing_basis === 'vehicle_class' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700 bg-white dark:bg-[#2d333c]', 'relative flex cursor-pointer rounded-lg border p-4 shadow-sm focus:outline-none transition-all items-center gap-3']">
                                <input type="radio" v-model="form.pricing_basis" value="vehicle_class" class="sr-only" />
                                <i class="ri-vip-crown-line text-2xl text-gray-400"></i>
                                <div>
                                    <span class="block text-sm font-semibold text-gray-900 dark:text-white">По классу авто</span>
                                    <span class="block text-xs text-gray-500 mt-0.5">Класс 1, Класс 2 и т.д.</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
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

        </div>
    </AuthenticatedLayout>
</template>