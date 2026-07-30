<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';

const props = defineProps({
    warehouseMode: String,
});

const form = useForm({
    warehouse_mode: props.warehouseMode || 'per_branch',
});

const submit = () => {
    form.post(route('settings.warehouse.store'));
};
</script>

<template>
    <Head title="Настройки склада" />

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
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        
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
                                        У каждого филиала свой независимый склад. Остатки не пересекаются. Идеально для независимых студий.
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
                                        Единый центральный склад на всю компанию. Все филиалы списывают материалы из одного места.
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
                                        Комбинация. Расходники хранятся локально на филиалах, а дорогие материалы — на центральном складе.
                                    </span>
                                </span>
                            </span>
                            <i v-if="form.warehouse_mode === 'mixed'" class="ri-checkbox-circle-fill text-primary text-xl absolute top-4 right-4"></i>
                        </label>

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

        </div>
    </AuthenticatedLayout>
</template>