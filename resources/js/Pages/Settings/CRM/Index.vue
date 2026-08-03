<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';

const props = defineProps({
    strictPlateValidation: Boolean,
});

const form = useForm({
    strict_plate_validation: props.strictPlateValidation,
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

            <PageHelper title="Строгая проверка госномеров">
                <p>Если настройка включена, система будет требовать ввода государственных номеров автомобилей в строгом соответствии с форматом вашей страны (например, А 000 АА 77 для РФ).</p>
                <p>Если отключена — администраторы смогут вводить любые символы или оставлять поле пустым (например, для автомобилей без номеров или спецтехники).</p>
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