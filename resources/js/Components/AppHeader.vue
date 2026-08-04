<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { usePage, Link } from '@inertiajs/vue3';
import { computed, ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const page = usePage();
const user = computed(() => page.props.auth.user);

const legalEntities = computed(() => page.props.legal_entities || []);
const branches = computed(() => page.props.branches || []);

// Принудительно приводим ID к числу для строгого сравнения (===)
const currentLEId = computed(() => page.props.current_legal_entity_id ? Number(page.props.current_legal_entity_id) : null);
const currentBranchId = computed(() => page.props.current_branch_id ? Number(page.props.current_branch_id) : null);

const currentLEName = computed(() => {
    if (!currentLEId.value) return 'Все юрлица';
    const le = legalEntities.value.find(l => l.id === currentLEId.value);
    return le ? le.name : 'Все юрлица';
});

const currentBranchName = computed(() => {
    if (!currentBranchId.value) return 'Все филиалы';
    const branch = branches.value.find(b => b.id === currentBranchId.value);
    return branch ? branch.name : 'Все филиалы';
});

// Фильтруем список филиалов в дропдауне по выбранному юрлицу
const filteredBranches = computed(() => {
    if (!currentLEId.value) return branches.value;
    return branches.value.filter(b => b.legal_entity_id === currentLEId.value || b.legal_entity_id === null);
});

// --- Система уведомлений ---
const notifications = ref([]);
const unreadCount = computed(() => notifications.value.filter(n => !n.read_at).length);
let pollingInterval = null;

const fetchNotifications = async () => {
    try {
        const response = await axios.get(route('notifications.index'));
        notifications.value = response.data;
    } catch (error) {
        console.error('Failed to fetch notifications', error);
    }
};

const markAsRead = async (id) => {
    const notification = notifications.value.find(n => n.id === id);
    if (notification && !notification.read_at) {
        notification.read_at = new Date().toISOString();
        try {
            await axios.post(route('notifications.read', id));
        } catch (error) {
            console.error('Failed to mark notification as read', error);
        }
    }
};

const markAllAsRead = async () => {
    notifications.value.forEach(n => n.read_at = new Date().toISOString());
    try {
        await axios.post(route('notifications.read', 'all'));
    } catch (error) {
        console.error('Failed to mark all as read', error);
    }
};

onMounted(() => {
    fetchNotifications();
    pollingInterval = setInterval(fetchNotifications, 30000); // Опрашиваем каждые 30 секунд
});

onUnmounted(() => {
    if (pollingInterval) clearInterval(pollingInterval);
});
</script>

<template>
    <header class="bg-white dark:bg-[#313a46] shadow-sm border-b border-gray-200 dark:border-gray-700/80">
        <div class="flex items-center justify-between px-6 py-4">
            <div class="flex items-center">
                <!-- Заголовок страницы (Attex Theme) -->
                <h2 class="text-lg font-medium text-slate-900 dark:text-slate-200" v-if="$slots.header">
                    <slot name="header" />
                </h2>
            </div>

            <div class="flex items-center gap-5">
                
                <!-- Переключатель Юрлиц (Показываем только если их больше 1) -->
                <Dropdown align="right" width="48" v-if="legalEntities.length > 1">
                    <template #trigger>
                        <button class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-200 hover:text-primary transition-colors focus:outline-none bg-light dark:bg-gray-800/50 px-3 py-1.5 rounded-md border border-gray-200 dark:border-gray-700/50">
                            <i class="ri-bank-line text-primary"></i>
                            <span>{{ currentLEName }}</span>
                            <i class="ri-arrow-down-s-line text-secondary"></i>
                        </button>
                    </template>

                    <template #content>
                        <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-700/50">
                            Юридические лица
                        </div>
                        
                        <!-- Пункт "Все юрлица" -->
                        <DropdownLink 
                            :href="route('legal-entities.switch')" 
                            method="post" 
                            as="button"
                        >
                            <div class="flex items-center gap-2" :class="{'text-primary font-semibold': currentLEId === null}">
                                <i class="ri-global-line"></i> Все юрлица
                                <i v-if="currentLEId === null" class="ri-check-line ml-auto"></i>
                            </div>
                        </DropdownLink>

                        <DropdownLink 
                            v-for="le in legalEntities" 
                            :key="le.id" 
                            :href="route('legal-entities.switch', le.id)" 
                            method="post" 
                            as="button"
                        >
                            <div class="flex items-center gap-2" :class="{'text-primary font-semibold': le.id === currentLEId}">
                                <i class="ri-bank-line"></i> {{ le.name }}
                                <i v-if="le.id === currentLEId" class="ri-check-line ml-auto"></i>
                            </div>
                        </DropdownLink>
                    </template>
                </Dropdown>

                <!-- Переключатель Филиалов (Показываем только если их больше 1) -->
                <Dropdown align="right" width="48" v-if="filteredBranches.length > 1">
                    <template #trigger>
                        <button class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-200 hover:text-primary transition-colors focus:outline-none bg-light dark:bg-gray-800/50 px-3 py-1.5 rounded-md border border-gray-200 dark:border-gray-700/50">
                            <i class="ri-store-2-line text-primary"></i>
                            <span>{{ currentBranchName }}</span>
                            <i class="ri-arrow-down-s-line text-secondary"></i>
                        </button>
                    </template>

                    <template #content>
                        <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-700/50">
                            Доступные филиалы
                        </div>

                        <!-- Пункт "Все филиалы" -->
                        <DropdownLink 
                            :href="route('branches.switch')" 
                            method="post" 
                            as="button"
                        >
                            <div class="flex items-center gap-2" :class="{'text-primary font-semibold': currentBranchId === null}">
                                <i class="ri-layout-grid-line"></i> Все филиалы
                                <i v-if="currentBranchId === null" class="ri-check-line ml-auto"></i>
                            </div>
                        </DropdownLink>

                        <DropdownLink 
                            v-for="branch in filteredBranches" 
                            :key="branch.id" 
                            :href="route('branches.switch', branch.id)" 
                            method="post" 
                            as="button"
                        >
                            <div class="flex items-center gap-2" :class="{'text-primary font-semibold': branch.id === currentBranchId}">
                                <i class="ri-store-2-line"></i> {{ branch.name }}
                                <i v-if="branch.id === currentBranchId" class="ri-check-line ml-auto"></i>
                            </div>
                        </DropdownLink>
                    </template>
                </Dropdown>

                <!-- Уведомления -->
                <Dropdown align="right" width="80">
                    <template #trigger>
                        <button class="relative flex items-center justify-center w-8 h-8 text-gray-500 dark:text-gray-400 hover:text-primary transition-colors focus:outline-none">
                            <i class="ri-notification-3-line text-xl"></i>
                            <span v-if="unreadCount > 0" class="absolute top-0 right-0 flex h-4 w-4 items-center justify-center rounded-full bg-danger text-[10px] font-bold text-white ring-2 ring-white dark:ring-[#313a46]">
                                {{ unreadCount > 9 ? '9+' : unreadCount }}
                            </span>
                        </button>
                    </template>

                    <template #content>
                        <div class="px-4 py-2 text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-700/50 flex justify-between items-center">
                            <span>Уведомления</span>
                            <button v-if="unreadCount > 0" @click.stop="markAllAsRead" class="text-primary hover:underline lowercase text-[11px]">прочитать все</button>
                        </div>
                        <div class="max-h-80 overflow-y-auto custom-scrollbar">
                            <div v-if="notifications.length === 0" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                Нет новых уведомлений
                            </div>
                            <div v-for="notification in notifications" :key="notification.id" 
                                 @click="markAsRead(notification.id)"
                                 :class="['px-4 py-3 border-b border-gray-100 dark:border-gray-700/50 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors cursor-pointer', !notification.read_at ? 'bg-primary/5' : '']">
                                <p class="text-sm text-gray-800 dark:text-gray-200" :class="{'font-semibold': !notification.read_at}">
                                    {{ notification.data.message }}
                                </p>
                                <div class="mt-2 flex items-center justify-between">
                                    <span class="text-xs text-gray-500">{{ new Date(notification.created_at).toLocaleString('ru-RU', {day: 'numeric', month: 'short', hour: '2-digit', minute:'2-digit'}) }}</span>
                                    <a v-if="notification.data.file_name" 
                                       :href="route('exports.download', notification.data.file_name)" 
                                       @click.stop="markAsRead(notification.id)"
                                       class="inline-flex items-center gap-1 text-xs font-medium text-primary hover:text-primary-600 bg-primary/10 px-2 py-1 rounded">
                                        <i class="ri-download-line"></i> Скачать
                                    </a>
                                </div>
                            </div>
                        </div>
                    </template>
                </Dropdown>

                <!-- Профиль -->
                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button class="flex items-center gap-2 text-sm font-medium text-gray-800 dark:text-gray-200 hover:text-primary transition-colors focus:outline-none">
                            <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold">
                                {{ user.name.charAt(0) }}
                            </div>
                            <span>{{ user.name }}</span>
                            <i class="ri-arrow-down-s-line text-secondary"></i>
                        </button>
                    </template>

                    <template #content>
                        <DropdownLink :href="route('profile.edit')">
                            <div class="flex items-center gap-2"><i class="ri-user-settings-line"></i> Профиль</div>
                        </DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button">
                            <div class="flex items-center gap-2 text-danger"><i class="ri-logout-box-r-line"></i> Выйти</div>
                        </DropdownLink>
                    </template>
                </Dropdown>
            </div>
        </div>
    </header>
</template>