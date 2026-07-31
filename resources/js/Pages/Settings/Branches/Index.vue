<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    branchesList: Array,
    legalEntities: Array,
});

const isModalOpen = ref(false);
const editingBranch = ref(null);

const form = useForm({
    legal_entity_id: '',
    name: '',
    address: '',
    city: '',
    phone: '',
    timezone: 'Europe/Moscow',
    is_active: true,
});

const openModal = (branch = null) => {
    editingBranch.value = branch;
    if (branch) {
        form.legal_entity_id = branch.legal_entity_id || '';
        form.name = branch.name;
        form.address = branch.address || '';
        form.city = branch.city || '';
        form.phone = branch.phone || '';
        form.timezone = branch.timezone || 'Europe/Moscow';
        form.is_active = Boolean(branch.is_active);
    } else {
        form.reset();
        form.is_active = true;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingBranch.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (editingBranch.value) {
        form.put(route('settings.branches.update', editingBranch.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('settings.branches.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteBranch = (branch) => {
    if (confirm(`Удалить филиал "${branch.name}"?`)) {
        form.delete(route('settings.branches.destroy', branch.id));
    }
};
</script>

<template>
    <Head title="Филиалы" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6">
            
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
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Филиалы и Локации</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Управление физическими точками обслуживания клиентов
                    </p>
                </div>
                <button
                    @click="openModal()"
                    class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5"
                >
                    <i class="ri-add-line text-base"></i>
                    Добавить филиал
                </button>
            </div>

            <!-- Table Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Юрлицо</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Адрес</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Телефон</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статус</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="branch in branchesList" :key="branch.id" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50 font-semibold">
                                    <div class="flex items-center gap-2">
                                        <i class="ri-store-2-line text-primary"></i>
                                        {{ branch.name }}
                                    </div>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <span v-if="branch.legal_entity" class="inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">
                                        <i class="ri-bank-line"></i> {{ branch.legal_entity.name }}
                                    </span>
                                    <span v-else class="text-gray-400 dark:text-gray-500 text-xs">Не привязан</span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    {{ branch.city ? branch.city + ', ' : '' }}{{ branch.address || '—' }}
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ branch.phone || '—' }}</td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <span
                                        :class="[
                                            branch.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger',
                                            'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium'
                                        ]"
                                    >
                                        {{ branch.is_active ? 'Активно' : 'Неактивно' }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
                                    <button 
                                        @click="openModal(branch)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                        title="Редактировать"
                                    >
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button 
                                        @click="deleteBranch(branch)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                        title="Удалить"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="branchesList.length === 0">
                                <td colspan="6" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Филиалы еще не добавлены. Нажмите "Добавить филиал".
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
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingBranch ? 'Редактирование филиала' : 'Новый филиал' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название филиала <span class="text-danger">*</span></label>
                                <input 
                                    v-model="form.name" 
                                    type="text" 
                                    required 
                                    placeholder="Центральный детейлинг" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                                <span v-if="form.errors.name" class="text-xs text-danger mt-1">{{ form.errors.name }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Юридическое лицо</label>
                                <select 
                                    v-model="form.legal_entity_id" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="" class="bg-white dark:bg-gray-800">Общий филиал (Без привязки)</option>
                                    <option v-for="le in legalEntities" :key="le.id" :value="le.id" class="bg-white dark:bg-gray-800">{{ le.name }}</option>
                                </select>
                                <span v-if="form.errors.legal_entity_id" class="text-xs text-danger mt-1">{{ form.errors.legal_entity_id }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Город</label>
                                <input 
                                    v-model="form.city" 
                                    type="text" 
                                    placeholder="Москва" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Адрес</label>
                                <input 
                                    v-model="form.address" 
                                    type="text" 
                                    placeholder="ул. Ленина, 1" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Телефон филиала</label>
                                <input 
                                    v-model="form.phone" 
                                    type="text" 
                                    placeholder="+7 (999) 000-00-00" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Часовой пояс</label>
                                <select 
                                    v-model="form.timezone" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                >
                                    <option value="Europe/Moscow" class="bg-white dark:bg-gray-800">Europe/Moscow</option>
                                    <option value="Europe/Berlin" class="bg-white dark:bg-gray-800">Europe/Berlin</option>
                                    <option value="Asia/Almaty" class="bg-white dark:bg-gray-800">Asia/Almaty</option>
                                </select>
                            </div>
                        </div>

                        <!-- Toggle Switch (Attex Style) -->
                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Филиал активен
                            </label>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                            Отмена
                        </button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            Сохранить
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>