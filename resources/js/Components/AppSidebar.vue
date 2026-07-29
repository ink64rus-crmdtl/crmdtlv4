<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const page = usePage();
const modules = computed(() => page.props.modules || []);

// Маппинг старых иконок из сидера на новые Remix Icons (Attex Theme)
const iconMap = {
    'HomeIcon': 'ri-home-4-line',
    'UsersIcon': 'ri-group-line',
    'BriefcaseIcon': 'ri-briefcase-line',
    'ArchiveBoxIcon': 'ri-archive-line',
    'BanknotesIcon': 'ri-money-dollar-circle-line',
    'UserGroupIcon': 'ri-team-line',
    'ChatBubbleLeftRightIcon': 'ri-chat-3-line',
    'DocumentTextIcon': 'ri-file-text-line',
    'Cog6ToothIcon': 'ri-settings-3-line',
};

const resolveIcon = (iconName) => {
    // Если иконка уже передана в формате Remix (начинается с ri-), используем её напрямую
    if (iconName && iconName.startsWith('ri-')) {
        return iconName;
    }
    return iconMap[iconName] || 'ri-grid-line';
};

// Маппинг ключей модулей на реальные имена маршрутов Laravel
const routeMap = {
    'dashboard': 'dashboard',
    'settings': 'settings',
    'system': 'system.index',
};

// Защита от ошибок роутинга
const getRoute = (key) => {
    const routeName = routeMap[key] || key;
    return route().has(routeName) ? route(routeName) : '#';
};

// Проверка активности пункта меню
const isRouteActive = (key) => {
    const routeName = routeMap[key] || key;
    // Проверяем точное совпадение или вхождение (например, system.index -> system)
    return route().current(routeName) || route().current(key + '.*');
};
</script>

<template>
    <aside class="flex flex-col w-64 h-screen px-4 py-6 bg-[#313a46] border-r border-gray-700/80 shrink-0">
        <div class="flex items-center justify-center mb-8">
            <Link :href="route('dashboard')" class="flex items-center gap-2">
                <ApplicationLogo class="w-auto h-8 text-primary fill-current" />
                <span class="text-xl font-bold text-white tracking-wider">CRM<span class="text-primary">DTL</span></span>
            </Link>
        </div>

        <div class="flex flex-col justify-between flex-1 mt-2">
            <nav class="space-y-1.5">
                <Link
                    v-for="module in modules"
                    :key="module.id"
                    :href="getRoute(module.key)"
                    :class="[
                        isRouteActive(module.key) 
                            ? 'bg-primary/10 text-primary' 
                            : 'text-[#aab8c5] hover:bg-gray-800 hover:text-white',
                        'flex items-center px-4 py-2.5 transition-all duration-300 rounded-md text-sm font-medium'
                    ]"
                >
                    <i :class="['text-lg mr-3', resolveIcon(module.icon)]"></i>
                    <span>{{ module.label }}</span>
                </Link>
            </nav>
        </div>
    </aside>
</template>