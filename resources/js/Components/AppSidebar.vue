<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import * as HeroIcons from '@heroicons/vue/24/outline';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const page = usePage();
const modules = computed(() => page.props.modules || []);

// Динамическая подгрузка иконок
const resolveIcon = (iconName) => {
    return HeroIcons[iconName] || HeroIcons['Squares2X2Icon'];
};

// Защита от ошибок роутинга (пока мы не создали все контроллеры)
const getRoute = (key) => {
    return route().has(key) ? route(key) : '#';
};
</script>

<template>
    <aside class="flex flex-col w-64 h-screen px-4 py-8 overflow-y-auto bg-midnight-900 border-r border-midnight-800">
        <div class="flex items-center justify-center mb-8">
            <Link :href="route('dashboard')" class="flex items-center gap-2">
                <ApplicationLogo class="w-auto h-8 text-indigo-500 fill-current" />
                <span class="text-xl font-bold text-white tracking-wider">CRM<span class="text-indigo-500">DTL</span></span>
            </Link>
        </div>

        <div class="flex flex-col justify-between flex-1 mt-6">
            <nav class="space-y-2">
                <Link
                    v-for="module in modules"
                    :key="module.id"
                    :href="getRoute(module.key)"
                    :class="[
                        route().current(module.key) 
                            ? 'bg-midnight-800 text-indigo-400 border-l-4 border-indigo-500' 
                            : 'text-gray-400 hover:bg-midnight-800 hover:text-white border-l-4 border-transparent',
                        'flex items-center px-4 py-3 transition-colors duration-200 transform rounded-r-lg'
                    ]"
                >
                    <component :is="resolveIcon(module.icon)" class="w-5 h-5" />
                    <span class="mx-4 font-medium">{{ module.label }}</span>
                </Link>
            </nav>
        </div>
    </aside>
</template>