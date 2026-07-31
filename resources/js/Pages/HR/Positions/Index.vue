<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    positions: Array,
});

const isModalOpen = ref(false);
const editingPosition = ref(null);

const form = useForm({
    name: '',
    is_active: true,
});

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

const openModal = (position = null) => {
    editingPosition.value = position;
    if (position) {
        form.name = getLocalizedLabel(position.name);
        form.is_active = Boolean(position.is_active);
    } else {
        form.reset();
        form.is_active = true;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingPosition.value = null;
    form.reset();
};

const submit = () => {
    if (editingPosition.value) {
        form.put(route('hr.positions.update', editingPosition.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('hr.positions.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deletePosition = (position) => {
    if (confirm(`Удалить должность "${getLocalizedLabel(position.name)}"?`)) {
        form.delete(route('hr.positions.destroy', position.id));
    }
};
</script>

<template>
    <Head title="Справочник должностей" />

    <AuthenticatedLayout>
        <template #header>
            Сотрудники и HR
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <!-- Header Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Справочник должностей</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Управление списком должностей для сотрудников компании.
                    </p>
                </div>
                <div class="flex gap-3">
                    <a :href="route('hr.employees.index')" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                        К списку сотрудников
                    </a>
                    <button
                        @click="openModal()"
                        class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5"
                    >
                        <i class="ri-add-line text-base"></i>
                        Добавить должность
                    </button>
                </div>
            </div>

            <!-- Table Card (Attex Theme) -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статус</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="position in positions" :key="position.id" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50 font-semibold">
                                    <div class="flex items-center gap-2">
                                        <i class="ri-medal-line text-primary"></i>
                                        {{ getLocalizedLabel(position.name) }}
                                    </div>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <span
                                        :class="[
                                            position.is_active ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger',
                                            'inline-flex items-center gap-1.5 py-0.5 px-2 rounded text-xs font-medium'
                                        ]"
                                    >
                                        {{ position.is_active ? 'Активно' : 'Неактивно' }}
                                    </span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
                                    <button 
                                        @click="openModal(position)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                        title="Редактировать"
                                    >
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button 
                                        @click="deletePosition(position)" 
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                        title="Удалить"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="positions.length === 0">
                                <td colspan="3" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Должности еще не добавлены. Нажмите "Добавить должность".
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Модальное окно (Attex Standard: 50% width) -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-xl lg:max-w-2xl my-8 mx-auto flex flex-col">
                
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingPosition ? 'Редактирование должности' : 'Новая должность' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название должности <span class="text-danger">*</span></label>
                            <input 
                                v-model="form.name" 
                                type="text" 
                                required 
                                placeholder="Например: Менеджер по работе с клиентами" 
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                            />
                        </div>

                        <!-- Toggle Switch (Attex Style) -->
                        <div class="flex items-center pt-2">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">
                                Должность активна
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