<script setup>
import { Link, router } from '@inertiajs/vue3';

const logout = () => {
    router.post(route('central.admin.logout'));
};
</script>

<template>
    <div class="min-h-screen flex bg-gray-100 dark:bg-gray-900 font-sans">
        <aside class="flex flex-col w-64 h-screen px-4 py-6 bg-[#1a2233] border-r border-gray-700/80 shrink-0 sticky top-0">
            <div class="flex items-center justify-center mb-8">
                <span class="text-xl font-bold text-white tracking-wider">CRM<span class="text-primary">DTL</span> <span class="text-xs font-normal text-gray-400">Admin</span></span>
            </div>

            <nav class="space-y-1.5 flex-1">
                <Link :href="route('central.admin.dashboard')" :class="[route().current('central.admin.dashboard') ? 'bg-primary/10 text-primary' : 'text-[#aab8c5] hover:bg-gray-800 hover:text-white', 'flex items-center px-4 py-2.5 transition-all duration-300 rounded-md text-sm font-medium']">
                    <i class="ri-dashboard-line text-lg mr-3"></i> Дашборд
                </Link>
                <Link :href="route('central.admin.tenants.index')" :class="[route().current('central.admin.tenants.*') ? 'bg-primary/10 text-primary' : 'text-[#aab8c5] hover:bg-gray-800 hover:text-white', 'flex items-center px-4 py-2.5 transition-all duration-300 rounded-md text-sm font-medium']">
                    <i class="ri-building-2-line text-lg mr-3"></i> Тенанты
                </Link>
                <Link :href="route('central.admin.document-templates.index')" :class="[route().current('central.admin.document-templates.*') ? 'bg-primary/10 text-primary' : 'text-[#aab8c5] hover:bg-gray-800 hover:text-white', 'flex items-center px-4 py-2.5 transition-all duration-300 rounded-md text-sm font-medium']">
                    <i class="ri-file-text-line text-lg mr-3"></i> Шаблоны документов
                </Link>
                <Link :href="route('central.admin.settings.index')" :class="[route().current('central.admin.settings.*') ? 'bg-primary/10 text-primary' : 'text-[#aab8c5] hover:bg-gray-800 hover:text-white', 'flex items-center px-4 py-2.5 transition-all duration-300 rounded-md text-sm font-medium']">
                    <i class="ri-settings-3-line text-lg mr-3"></i> Настройки платформы
                </Link>
            </nav>

            <button @click="logout" class="flex items-center px-4 py-2.5 rounded-md text-sm font-medium text-[#aab8c5] hover:bg-gray-800 hover:text-white transition-all duration-300">
                <i class="ri-logout-box-line text-lg mr-3"></i> Выйти
            </button>
        </aside>

        <div class="flex-1 flex flex-col min-w-0">
            <header class="bg-white dark:bg-[#313a46] border-b border-gray-200/80 dark:border-gray-700/80 px-6 py-4">
                <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200"><slot name="header" /></h1>
            </header>
            <main class="flex-1 p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
