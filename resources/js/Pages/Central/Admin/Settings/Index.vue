<script setup>
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    keys: { type: Object, default: () => ({}) },
    values: { type: Object, default: () => ({}) },
});

// Значения не приходят с сервера как есть (секреты) — форма стартует пустой,
// пустое поле при сохранении значит "не менять" (см. PlatformSettingController).
const form = useForm({
    values: Object.fromEntries(Object.keys(props.keys).map((key) => [key, ''])),
});

const isSet = (key) => Boolean(props.values[key]);

const submit = () => {
    form.post(route('central.admin.settings.update'), {
        onSuccess: () => form.reset(),
    });
};
</script>

<template>
    <Head title="Настройки платформы — Admin" />

    <CentralAdminLayout>
        <template #header>Настройки платформы</template>

        <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 max-w-xl">
            <form @submit.prevent="submit" class="space-y-4">
                <div v-for="(label, key) in keys" :key="key">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                        {{ label }}
                        <span v-if="isSet(key)" class="ml-1 text-xs text-success">(задано)</span>
                        <span v-else class="ml-1 text-xs text-gray-400">(не задано)</span>
                    </label>
                    <input v-model="form.values[key]" type="text" :placeholder="isSet(key) ? 'Оставьте пустым, чтобы не менять' : ''" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                    <p v-if="form.errors[`values.${key}`]" class="text-danger text-xs mt-1">{{ form.errors[`values.${key}`] }}</p>
                </div>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
            </form>
        </div>
    </CentralAdminLayout>
</template>
