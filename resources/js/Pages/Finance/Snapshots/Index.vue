<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import FinanceNav from '@/Components/FinanceNav.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';

const props = defineProps({
    snapshots: Object,
    accounts: Array,
    filters: Object,
});

const filtersForm = reactive({
    account_id: props.filters?.filters?.account_id || '',
});

const fetchFiltered = useDebounceFn(() => {
    router.get(route('finance.snapshots.index'), {
        filters: filtersForm,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(filtersForm, () => fetchFiltered(), { deep: true });

const formatMoney = (amount) => {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format((amount || 0) / 100);
};
</script>

<template>
    <Head title="Остатки по дням" />

    <AuthenticatedLayout>
        <template #header>
            Финансы и Кассы
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">

            <FinanceNav />

            <PageHelper title="Дневные снэпшоты остатков">
                <p>Каждую ночь в 00:00 система фиксирует по каждому счету остаток на начало и конец прошедших суток, а также обороты за день (приход, расход, переводы) — не нужно пересчитывать историю операций вручную для отчётности.</p>
                <p class="mt-2">Если в уже прошедший (но еще не закрытый) день внесли операцию задним числом, снэпшот этого дня и счета пересчитывается автоматически при сохранении операции.</p>
            </PageHelper>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-4">
                <div class="max-w-xs">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Счет</label>
                    <select v-model="filtersForm.account_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                        <option value="">Все счета</option>
                        <option v-for="acc in accounts" :key="acc.id" :value="acc.id">{{ acc.name }}</option>
                    </select>
                </div>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Дата</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Счет</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Остаток на начало</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Приход</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Расход</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Переводы вх./исх.</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Остаток на конец</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="s in snapshots.data" :key="s.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    {{ new Date(s.snapshot_date).toLocaleDateString('ru-RU', {day: 'numeric', month: 'short', year: 'numeric'}) }}
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 font-medium">
                                    {{ s.account ? s.account.name : '—' }}
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right">{{ formatMoney(s.opening_balance) }}</td>
                                <td class="py-4 px-6 text-sm text-success border-b border-gray-100 dark:border-gray-700/50 text-right">+{{ formatMoney(s.income_total) }}</td>
                                <td class="py-4 px-6 text-sm text-danger border-b border-gray-100 dark:border-gray-700/50 text-right">−{{ formatMoney(s.expense_total) }}</td>
                                <td class="py-4 px-6 text-sm text-gray-500 border-b border-gray-100 dark:border-gray-700/50 text-right">
                                    +{{ formatMoney(s.transfer_in_total) }} / −{{ formatMoney(s.transfer_out_total) }}
                                </td>
                                <td class="py-4 px-6 text-sm font-bold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50 text-right">{{ formatMoney(s.closing_balance) }}</td>
                            </tr>
                            <tr v-if="snapshots.data.length === 0">
                                <td colspan="7" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Снэпшотов пока нет. Они появляются автоматически каждую ночь в 00:00, либо после ручного запуска <code>php artisan tenants:run snapshots:accounts</code>.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="snapshots" />
            </div>

        </div>
    </AuthenticatedLayout>
</template>
