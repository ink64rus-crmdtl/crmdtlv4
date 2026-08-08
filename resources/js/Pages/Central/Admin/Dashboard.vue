<script setup>
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    tenantsCount: { type: Number, default: 0 },
});
</script>

<template>
    <Head title="Дашборд — Admin" />

    <CentralAdminLayout>
        <template #header>Дашборд</template>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <Link :href="route('central.admin.tenants.index')" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 hover:border-primary/50 transition-colors">
                <i class="ri-building-2-line text-2xl text-primary"></i>
                <p class="text-2xl font-bold text-gray-800 dark:text-gray-200 mt-2">{{ tenantsCount }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Тенантов на платформе</p>
            </Link>
            <Link :href="route('central.admin.settings.index')" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 hover:border-primary/50 transition-colors">
                <i class="ri-settings-3-line text-2xl text-primary"></i>
                <p class="text-sm font-semibold text-gray-800 dark:text-gray-200 mt-2">Настройки платформы</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Общие секреты и параметры (например, токен Wappi.Pro)</p>
            </Link>
        </div>
    </CentralAdminLayout>
</template>
