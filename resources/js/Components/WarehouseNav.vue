<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Вкладки складского учёта (кроме каталога) скрываются, если тумблер
// «Склад отключён» (Настройки → Склад) выключен — CLAUDE.md: EnsureWarehouseEnabled
// на бэкенде всё равно блокирует прямые переходы по URL, здесь просто не
// предлагаем ссылки на недоступное.
const page = usePage();
const warehouseEnabled = computed(() => page.props.warehouse_enabled !== false);
</script>

<template>
    <div class="border-b border-gray-200 dark:border-gray-700 mb-6">
        <nav class="-mb-px flex space-x-8 overflow-x-auto custom-scrollbar pb-1">
            <Link :href="route('warehouse.products.index')" :class="[route().current('warehouse.products.*') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-box-3-line"></i> Товары и Материалы</Link>
            <template v-if="warehouseEnabled">
                <Link :href="route('warehouse.balances.index')" :class="[route().current('warehouse.balances.*') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-database-2-line"></i> Остатки на складах</Link>
                <Link :href="route('warehouse.goods-receipts.index')" :class="[route().current('warehouse.goods-receipts.*') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-file-list-3-line"></i> Приходные накладные</Link>
                <Link :href="route('warehouse.suppliers-debt.index')" :class="[route().current('warehouse.suppliers-debt.*') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-wallet-3-line"></i> Задолженность поставщикам</Link>
                <Link :href="route('warehouse.movements.index')" :class="[route().current('warehouse.movements.*') ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600', 'inline-flex items-center gap-1.5 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors']"><i class="ri-truck-line"></i> Движения</Link>
            </template>
        </nav>
    </div>
</template>
