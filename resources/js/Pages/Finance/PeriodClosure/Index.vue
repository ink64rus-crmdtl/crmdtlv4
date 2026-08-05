<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import FinanceNav from '@/Components/FinanceNav.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
    closures: Array,
    closedThroughDate: { type: String, default: null },
    suggestedPeriodEndDate: { type: String, default: null },
    canClose: Boolean,
});

const page = usePage();

const form = useForm({
    period_end_date: props.suggestedPeriodEndDate || '',
    note: '',
});

const submit = () => {
    if (!confirm(`Закрыть период по ${form.period_end_date}? Операции с этой и более ранней датой станет нельзя создавать, менять и отменять.`)) {
        return;
    }
    form.post(route('finance.period-closure.store'), {
        onSuccess: () => form.reset('note'),
    });
};
</script>

<template>
    <Head title="Закрытие периода" />

    <AuthenticatedLayout>
        <template #header>
            Финансы и Кассы
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">

            <FinanceNav />

            <PageHelper title="Зачем закрывать период">
                <p>После закрытия периода операции с датой не позже границы закрытия становится нельзя создавать, редактировать или отменять — ни напрямую, ни через приём оплаты/переводы. Это защищает уже сданную отчётность от случайных задним-числом правок.</p>
                <p class="mt-2">Границу можно только отодвигать вперёд — закрыть period раньше уже закрытой даты нельзя. Закрывать период может только администратор.</p>
            </PageHelper>

            <div v-if="page.props.errors.error" class="p-4 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-start gap-3">
                <i class="ri-error-warning-fill text-xl shrink-0"></i>
                <p>{{ page.props.errors.error }}</p>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex items-center justify-between">
                <div>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Текущая граница закрытия</p>
                    <p class="text-2xl font-bold text-gray-800 dark:text-gray-200">{{ closedThroughDate || 'Периоды не закрывались' }}</p>
                </div>
                <i class="ri-lock-2-line text-4xl text-gray-300 dark:text-gray-600"></i>
            </div>

            <div v-if="canClose" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Закрыть период</h3>
                </div>
                <form @submit.prevent="submit" class="p-6 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Закрыть по дату (включительно) <span class="text-danger">*</span></label>
                            <input v-model="form.period_end_date" type="date" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                            <p v-if="suggestedPeriodEndDate" class="text-xs text-gray-500 mt-1">Предложено: конец последнего завершившегося квартала ({{ suggestedPeriodEndDate }}). Можно выбрать другую дату.</p>
                            <span v-if="form.errors.period_end_date" class="text-xs text-danger mt-1 block">{{ form.errors.period_end_date }}</span>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Примечание</label>
                        <input v-model="form.note" type="text" placeholder="Например: Q2 2026 сдан в налоговую" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" />
                    </div>
                    <div class="flex justify-end pt-2 border-t border-gray-200 dark:border-gray-700">
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                            Закрыть период
                        </button>
                    </div>
                </form>
            </div>
            <div v-else class="p-4 bg-warning/10 border border-warning/20 rounded-md text-sm text-warning font-medium">
                Закрытие периода доступно только администратору.
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">История закрытий</h3>
                </div>
                <div class="p-6 space-y-3">
                    <div v-for="c in closures" :key="c.id" class="flex justify-between items-start border-b border-gray-100 dark:border-gray-700/50 pb-3 last:border-0 last:pb-0">
                        <div>
                            <p class="text-sm font-bold text-gray-800 dark:text-gray-200">Закрыто по {{ c.period_end_date }}</p>
                            <p class="text-xs text-gray-500 mt-0.5">
                                {{ new Date(c.closed_at).toLocaleString('ru-RU') }}{{ c.closer ? ' — ' + c.closer.name : '' }}
                            </p>
                            <p v-if="c.note" class="text-xs text-gray-500 mt-0.5 italic">{{ c.note }}</p>
                        </div>
                    </div>
                    <p v-if="closures.length === 0" class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">Периоды еще не закрывались.</p>
                </div>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
