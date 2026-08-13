<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import WarehouseNav from '@/Components/WarehouseNav.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    suppliers: { type: Array, default: () => [] },
});

const formatMoney = (cents) => new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format((cents || 0) / 100);

const totalBalance = computed(() => props.suppliers.reduce((sum, s) => sum + s.balance, 0));
const suppliersInDebt = computed(() => props.suppliers.filter(s => s.balance > 0).length);
</script>

<template>
    <Head title="Задолженность поставщикам" />

    <AuthenticatedLayout>
        <template #header>Склад и Прайс</template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">

            <WarehouseNav />

            <PageHelper title="Как считается остаток">
                <p><strong>Оприходовано</strong> — сумма закупки по всем непогашенным (не отменённым) приходным накладным этого поставщика.</p>
                <p><strong>Оплачено</strong> — сколько из оприходованного уже реально выплачено через кассу (см. вкладка «Приходные накладные», иконка «Принять оплату»).</p>
                <p><strong>Остаток</strong> = Оприходовано − Оплачено. Список включает только поставщиков, у которых реально есть накладные — не разрастается вместе со всей клиентской базой.</p>
            </PageHelper>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-danger/10 text-danger flex items-center justify-center shrink-0">
                        <i class="ri-error-warning-line text-xl"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Общая задолженность</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(totalBalance) }}</p>
                    </div>
                </div>
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-warning/10 text-warning flex items-center justify-center shrink-0">
                        <i class="ri-building-2-line text-xl"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Поставщиков с долгом</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-gray-200">{{ suppliersInDebt }}</p>
                    </div>
                </div>
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-info/10 text-info flex items-center justify-center shrink-0">
                        <i class="ri-file-list-3-line text-xl"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Всего поставщиков в расчётах</p>
                        <p class="text-xl font-bold text-gray-800 dark:text-gray-200">{{ suppliers.length }}</p>
                    </div>
                </div>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                    <div>
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Задолженность по поставщикам</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Клиенты с ролью «Поставщик», по которым есть хотя бы одна приходная накладная</p>
                    </div>
                    <Link :href="route('warehouse.goods-receipts.index')" class="text-sm font-medium text-primary hover:underline whitespace-nowrap">
                        Все накладные <i class="ri-arrow-right-s-line"></i>
                    </Link>
                </div>
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Поставщик</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Телефон</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Накладных</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Оприходовано</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Оплачено</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Остаток</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="supplier in suppliers"
                                :key="supplier.id"
                                @click="router.visit(route('crm.clients.show', supplier.id))"
                                class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors cursor-pointer"
                            >
                                <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50">
                                    {{ supplier.name }}
                                    <span v-if="supplier.is_deleted" class="ml-1 text-xs font-normal text-gray-400">(удалён)</span>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ supplier.phone || '—' }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right">{{ supplier.receipts_count }}</td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50 text-right">{{ formatMoney(supplier.accrued_total) }}</td>
                                <td class="py-4 px-6 text-sm text-success border-b border-gray-100 dark:border-gray-700/50 text-right">{{ formatMoney(supplier.paid_total) }}</td>
                                <td class="py-4 px-6 text-sm font-bold border-b border-gray-100 dark:border-gray-700/50 text-right" :class="supplier.balance > 0 ? 'text-danger' : 'text-gray-400'">{{ formatMoney(supplier.balance) }}</td>
                            </tr>
                            <tr v-if="suppliers.length === 0">
                                <td colspan="6" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">Пока нет ни одной приходной накладной.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
