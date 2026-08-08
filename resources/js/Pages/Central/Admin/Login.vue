<script setup>
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: { type: String, default: null },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('central.admin.login.store'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Вход — Admin" />

    <div class="min-h-screen flex items-center justify-center bg-gray-100 dark:bg-gray-900 font-sans px-4">
        <div class="w-full max-w-sm">
            <div class="text-center mb-6">
                <span class="text-2xl font-bold text-gray-800 dark:text-gray-100 tracking-wider">CRM<span class="text-primary">DTL</span> <span class="text-sm font-normal text-gray-400">Admin</span></span>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6">
                <div v-if="status" class="mb-4 text-sm font-medium text-success">{{ status }}</div>

                <form @submit.prevent="submit" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email</label>
                        <input v-model="form.email" type="email" required autofocus autocomplete="username" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        <p v-if="form.errors.email" class="text-danger text-xs mt-1">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Пароль</label>
                        <input v-model="form.password" type="password" required autocomplete="current-password" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        <p v-if="form.errors.password" class="text-danger text-xs mt-1">{{ form.errors.password }}</p>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
                        <input v-model="form.remember" type="checkbox" class="rounded border-gray-300 text-primary focus:ring-primary" /> Запомнить меня
                    </label>
                    <button type="submit" :disabled="form.processing" class="w-full inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Войти</button>
                </form>
            </div>
        </div>
    </div>
</template>
