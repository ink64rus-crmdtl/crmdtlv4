<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import PageHelper from '@/Components/PageHelper.vue';
import HRNav from '@/Components/HRNav.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';

const props = defineProps({
    employees: Object,
    filters: Object,
});

const search = ref(props.filters?.search || '');

const fetchFiltered = useDebounceFn(() => {
    router.get(route('hr.payroll.index'), {
        search: search.value,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());

const getLocalizedLabel = (label) => {
    if (!label) return '';
    if (typeof label === 'string') {
        try { label = JSON.parse(label); } catch (e) { return label; }
    }
    return label['ru'] || label['en'] || Object.values(label)[0] || '';
};

const formatMoney = (cents) => {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format((cents || 0) / 100);
};
</script>

<template>
    <Head title="Взаиморасчёты с сотрудниками" />

    <AuthenticatedLayout>
        <template #header>
            Сотрудники и HR
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">

            <HRNav />

            <PageHelper title="Как считается баланс">
                <p><strong>Начислено</strong> — сумма всех начислений (по заказам, вручную, оклад), кроме отменённых.</p>
                <p><strong>Выплачено</strong> — сколько из начисленного уже реально выплачено через кассу.</p>
                <p><strong>Штрафы</strong> — сумма ещё не отменённых удержаний; деньгами отдельно не выплачиваются, а сразу уменьшают баланс.</p>
                <p><strong>К выплате (баланс)</strong> = Начислено − Выплачено − Штрафы. Подробный список начислений и кнопка «Выплатить» — в карточке сотрудника, вкладка «Зарплата».</p>
            </PageHelper>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Взаиморасчёты с сотрудниками</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                        Баланс начислений и выплат по каждому активному сотруднику
                    </p>
                </div>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="false"
                    placeholder="Поиск по имени, телефону..."
                >
                    <template #actions>
                        <Link :href="route('settings.payroll.index')" class="hidden sm:inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                            Настройки зарплаты
                        </Link>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Сотрудник</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Должность</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Начислено</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Выплачено</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Штрафы</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">К выплате</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="employee in employees.data"
                                :key="employee.id"
                                @click="router.visit(route('hr.employees.show', employee.id))"
                                class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors cursor-pointer"
                            >
                                <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50">
                                    {{ employee.last_name }} {{ employee.first_name }}
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    {{ employee.position ? getLocalizedLabel(employee.position.name) : '—' }}
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50 text-right">{{ formatMoney(employee.accrued_total) }}</td>
                                <td class="py-4 px-6 text-sm text-success border-b border-gray-100 dark:border-gray-700/50 text-right">{{ formatMoney(employee.paid_total) }}</td>
                                <td class="py-4 px-6 text-sm text-danger border-b border-gray-100 dark:border-gray-700/50 text-right">{{ employee.deductions_total > 0 ? '− ' + formatMoney(employee.deductions_total) : '—' }}</td>
                                <td class="py-4 px-6 text-sm font-bold border-b border-gray-100 dark:border-gray-700/50 text-right" :class="employee.balance > 0 ? 'text-primary' : 'text-gray-400'">{{ formatMoney(employee.balance) }}</td>
                            </tr>
                            <tr v-if="employees.data.length === 0">
                                <td colspan="6" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Активных сотрудников не найдено.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="employees" />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
