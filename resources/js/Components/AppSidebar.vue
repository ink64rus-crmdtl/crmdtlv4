<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

const page = usePage();
const branches = computed(() => page.props.branches || []);
const currentBranchId = computed(() => page.props.current_branch_id ? Number(page.props.current_branch_id) : null);

// Локация, выбранная в переключателе (или null при "Все локации")
const selectedBranch = computed(() => branches.value.find((b) => b.id === currentBranchId.value) || null);

// Локация, чей логотип показываем одиночным (100×100): если доступна ровно
// одна локация — всегда она (переключателя нет, и даже устаревший
// current_branch_id в сессии не должен скрывать её загруженный логотип);
// иначе — выбранная в переключателе.
const logoBranch = computed(() => {
    if (branches.value.length === 1) return branches.value[0];
    if (currentBranchId.value) return selectedBranch.value;
    return null;
});

// Размер логотипа: 100×100 для одной локации; при ленте "Все локации"
// уменьшается пропорционально количеству лого (100 / N), но не менее 24px.
const logoSize = computed(() => {
    if (currentBranchId.value || branches.value.length <= 1) return 100;
    return Math.max(24, Math.floor(100 / branches.value.length));
});
// Модули сидируются в БД заранее (в т.ч. под ещё не реализованные фазы
// роадмапа — "Общение", "Документы"), поэтому пункт меню без соответствующего
// маршрута скрываем целиком, а не ведём на "#" (клик по нему ничего не делал —
// был неотличим для пользователя от сломанной ссылки).
const modules = computed(() => (page.props.modules || []).filter((module) => {
    const routeName = routeMap[module.key] || module.key;
    return route().has(routeName);
}));

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
    'hr': 'hr.employees.index',
    'crm': 'crm.clients.index',
    'sales': 'sales.deals.index',
    'operations': 'operations.work-orders.index',
    'communications': 'communications.index',
    'documents': 'documents.index',
    'warehouse': 'warehouse.products.index',
    'finance': 'finance.transactions.index', // ИСПРАВЛЕНО: Добавлен маппинг для Финансов
    'dictionaries': 'settings.dictionaries.index',
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

// Парсинг JSON-переводов из БД
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
</script>

<template>
    <aside class="flex flex-col w-64 h-screen px-4 py-6 bg-[#313a46] border-r border-gray-700/80 shrink-0">
        <div class="flex flex-col items-center justify-center mb-4 gap-2">
            <!-- Логотип локации: одиночный лого (100×100) для выбранной локации или,
                 если выбора нет, для единственной локации системы; клик переключает
                 на эту локацию. При "Все локации" и нескольких локациях — лента всех
                 лого, размер каждого пропорционален количеству (100 / N), клик выбирает
                 локацию; иначе — дефолтный логотип платформы. -->
            <template v-if="logoBranch?.logo_url">
                <Link
                    :href="route('branches.switch', logoBranch.id)"
                    method="post"
                    as="button"
                    :title="logoBranch.name"
                    class="block transition-transform hover:scale-105"
                >
                    <img
                        :src="logoBranch.logo_url"
                        :alt="logoBranch.name"
                        class="rounded-xl object-cover shadow-lg"
                        :style="{ width: logoSize + 'px', height: logoSize + 'px' }"
                    />
                </Link>
            </template>

            <div v-else-if="!currentBranchId && branches.length > 1" class="flex items-center justify-center gap-2">
                <Link
                    v-for="branch in branches"
                    :key="branch.id"
                    :href="route('branches.switch', branch.id)"
                    method="post"
                    as="button"
                    :title="branch.name"
                    class="block transition-transform hover:scale-110"
                >
                    <img
                        v-if="branch.logo_url"
                        :src="branch.logo_url"
                        :alt="branch.name"
                        class="rounded-lg object-cover"
                        :style="{ width: logoSize + 'px', height: logoSize + 'px' }"
                    />
                    <ApplicationLogo
                        v-else
                        class="text-primary fill-current"
                        :style="{ width: logoSize + 'px', height: logoSize + 'px' }"
                    />
                </Link>
            </div>

            <Link v-else :href="route('dashboard')" class="block" title="Главная">
                <ApplicationLogo
                    class="text-primary fill-current"
                    :style="{ width: logoSize + 'px', height: logoSize + 'px' }"
                />
            </Link>

            <Link v-if="!logoBranch?.logo_url" :href="route('dashboard')" class="mt-1">
                <span class="text-xl font-bold text-white tracking-wider">CRM<span class="text-primary">DTL</span></span>
            </Link>
        </div>

        <div class="flex flex-col justify-between flex-1 mt-2">
            <nav class="space-y-1">
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
                    <span>{{ getLocalizedLabel(module.label) }}</span>
                </Link>
            </nav>
        </div>
    </aside>
</template>