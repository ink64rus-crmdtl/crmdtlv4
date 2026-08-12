<script setup>
import CentralAdminLayout from '@/Layouts/CentralAdminLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    tenants: { type: Array, default: () => [] },
});

const formatDate = (dateStr) => dateStr ? new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
</script>

<template>
    <Head title="Тенанты — Admin" />

    <CentralAdminLayout>
        <template #header>Тенанты</template>

        <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
            <div class="overflow-x-auto w-full">
                <table class="min-w-full text-left whitespace-nowrap">
                    <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                        <tr>
                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Тенант</th>
                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Домен</th>
                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Создан</th>
                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Пользователи</th>
                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Локации</th>
                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Клиенты</th>
                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Заказ-наряды</th>
                            <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Последняя активность</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="tenant in tenants" :key="tenant.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                            <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50">{{ tenant.id }}</td>
                            <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ tenant.domain || '—' }}</td>
                            <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ formatDate(tenant.created_at) }}</td>
                            <template v-if="tenant.stats">
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ tenant.stats.users }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ tenant.stats.branches }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ tenant.stats.clients }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ tenant.stats.work_orders }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ formatDate(tenant.stats.last_work_order_at) }}</td>
                            </template>
                            <template v-else>
                                <td colspan="5" class="py-4 px-6 text-sm text-danger border-b border-gray-100 dark:border-gray-700/50">Не удалось получить данные (БД не смигрирована?)</td>
                            </template>
                        </tr>
                        <tr v-if="tenants.length === 0">
                            <td colspan="8" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">Тенантов пока нет.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </CentralAdminLayout>
</template>
