<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="border-b border-gray-200 dark:border-gray-700 mb-6">
        <nav class="-mb-px flex space-x-8 overflow-x-auto custom-scrollbar pb-1">
            <Link :href="route('hr.employees.index')" :class="[route().current('hr.employees.index') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-team-line"></i> Сотрудники</Link>
            <Link :href="route('hr.positions.index')" :class="[route().current('hr.positions.index') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-briefcase-4-line"></i> Справочник должностей</Link>
            <Link :href="route('hr.payroll.index')" :class="[route().current('hr.payroll.index') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-hand-coin-line"></i> Взаиморасчёты</Link>
        </nav>
    </div>
</template>
