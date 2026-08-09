<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="border-b border-gray-200 dark:border-gray-700 mb-6">
        <nav class="-mb-px flex space-x-8 overflow-x-auto custom-scrollbar pb-1">
            <Link :href="route('finance.transactions.index')" :class="[route().current('finance.transactions.*') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-exchange-line"></i> История операций</Link>
            <Link :href="route('finance.categories.index')" :class="[route().current('finance.categories.*') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-price-tag-3-line"></i> Статьи доходов и расходов</Link>
            <Link :href="route('finance.snapshots.index')" :class="[route().current('finance.snapshots.*') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-line-chart-line"></i> Остатки по дням</Link>
            <Link :href="route('finance.period-closure.index')" :class="[route().current('finance.period-closure.*') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-lock-2-line"></i> Закрытие периода</Link>
        </nav>
    </div>
</template>
