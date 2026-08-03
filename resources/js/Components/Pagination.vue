<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    meta: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div v-if="meta.links && meta.links.length > 3" class="flex items-center justify-between px-6 py-3 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46]">
        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-700 dark:text-gray-400">
                    Показано с
                    <span class="font-medium text-gray-900 dark:text-gray-200">{{ meta.from }}</span>
                    по
                    <span class="font-medium text-gray-900 dark:text-gray-200">{{ meta.to }}</span>
                    из
                    <span class="font-medium text-gray-900 dark:text-gray-200">{{ meta.total }}</span>
                    записей
                </p>
            </div>
            <div>
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <template v-for="(link, index) in meta.links" :key="index">
                        <div
                            v-if="link.url === null"
                            class="relative inline-flex items-center px-4 py-2 border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-sm font-medium text-gray-400 dark:text-gray-500 cursor-not-allowed"
                            :class="{
                                'rounded-l-md': index === 0,
                                'rounded-r-md': index === meta.links.length - 1
                            }"
                            v-html="link.label"
                        ></div>
                        <Link
                            v-else
                            :href="link.url"
                            class="relative inline-flex items-center px-4 py-2 border text-sm font-medium transition-colors"
                            :class="[
                                link.active 
                                    ? 'z-10 bg-primary/10 border-primary text-primary dark:bg-primary/20 dark:border-primary/50 dark:text-primary-400' 
                                    : 'bg-white dark:bg-[#313a46] border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700',
                                {
                                    'rounded-l-md': index === 0,
                                    'rounded-r-md': index === meta.links.length - 1
                                }
                            ]"
                            v-html="link.label"
                        ></Link>
                    </template>
                </nav>
            </div>
        </div>
        
        <!-- Мобильная версия пагинации -->
        <div class="flex items-center justify-between w-full sm:hidden">
            <Link
                v-if="meta.links[0].url"
                :href="meta.links[0].url"
                class="relative inline-flex items-center px-4 py-2 border border-gray-200 dark:border-gray-700 text-sm font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-[#313a46] hover:bg-gray-50 dark:hover:bg-gray-700"
            >
                Назад
            </Link>
            <span v-else class="relative inline-flex items-center px-4 py-2 border border-gray-200 dark:border-gray-700 text-sm font-medium rounded-md text-gray-400 bg-gray-50 dark:bg-gray-800 cursor-not-allowed">
                Назад
            </span>

            <Link
                v-if="meta.links[meta.links.length - 1].url"
                :href="meta.links[meta.links.length - 1].url"
                class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-200 dark:border-gray-700 text-sm font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-[#313a46] hover:bg-gray-50 dark:hover:bg-gray-700"
            >
                Вперед
            </Link>
            <span v-else class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-200 dark:border-gray-700 text-sm font-medium rounded-md text-gray-400 bg-gray-50 dark:bg-gray-800 cursor-not-allowed">
                Вперед
            </span>
        </div>
    </div>
</template>