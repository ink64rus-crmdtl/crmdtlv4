<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Doughnut } from 'vue-chartjs';
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js';

ChartJS.register(ArcElement, Tooltip, Legend);

const props = defineProps({
    pipelines: { type: Array, default: () => [] },
    pipeline: { type: Object, default: null },
    funnel: { type: Array, default: () => [] },
    forecast: { type: Object, default: () => ({ count: 0, total: 0, weighted: 0 }) },
    closedStats: { type: Object, default: () => ({ won_count: 0, lost_count: 0, won_amount: 0, win_rate: null }) },
    bySource: { type: Array, default: () => [] },
});

const formatMoney = (cents) => new Intl.NumberFormat('ru-RU', {
    style: 'currency', currency: 'RUB', minimumFractionDigits: 0, maximumFractionDigits: 0,
}).format((cents || 0) / 100);

const switchPipeline = (id) => {
    router.get(route('dashboard'), { pipeline_id: id }, { preserveState: true, preserveScroll: true });
};

const stageColorClass = {
    gray: 'bg-gray-400',
    info: 'bg-info',
    warning: 'bg-warning',
    success: 'bg-success',
    danger: 'bg-danger',
    primary: 'bg-primary',
};

// Ширина полосы воронки — относительно ПЕРВОЙ стадии (не обязательно 100%,
// если часть сделок уже потеряна раньше неё) — так масштаб честно показывает
// именно текущую воронку, а не всегда упирается в правый край.
const maxReached = computed(() => Math.max(1, ...props.funnel.map(r => r.reached_percent)));

const sourcePalette = ['#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4', '#a855f7', '#84cc16', '#ec4899'];

const doughnutData = computed(() => ({
    labels: props.bySource.map(s => s.label),
    datasets: [{
        data: props.bySource.map(s => s.count),
        backgroundColor: props.bySource.map((_, i) => sourcePalette[i % sourcePalette.length]),
        borderWidth: 0,
    }],
}));

const doughnutOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, padding: 12, font: { size: 11 } } },
    },
};

const sourceTotal = computed(() => props.bySource.reduce((s, r) => s + r.count, 0));
</script>

<template>
    <Head title="Дашборд" />

    <AuthenticatedLayout>
        <template #header>Дашборд</template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">

            <PageHelper title="Что показывает дашборд">
                <p>Воронка — снэпшот того, где СЕЙЧАС находятся сделки: «дошли до стадии» значит «сейчас на ней или дальше по воронке». Это не история переходов во времени, а срез на данный момент.</p>
                <p>Взвешенный прогноз — сумма открытых сделок, умноженная на вероятность их стадии (настраивается в <Link :href="route('settings.pipelines.index')" class="text-primary hover:underline">Настройки → Воронки</Link>).</p>
                <p>Источники считаются только по сделкам с заполненным полем «Источник» в форме сделки — если у большинства «Не указан», стоит начать его заполнять.</p>
            </PageHelper>

            <!-- Заголовок + переключатель воронки -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex flex-wrap justify-between items-center gap-4">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ pipeline?.name || 'Аналитика продаж' }}</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Аналитика воронки продаж за последние 30 дней</p>
                </div>
                <select
                    v-if="pipelines.length > 1"
                    :value="pipeline?.id"
                    @change="e => switchPipeline(e.target.value)"
                    class="rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm font-medium text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                >
                    <option v-for="p in pipelines" :key="p.id" :value="p.id" class="bg-white dark:bg-gray-800">{{ p.name }}</option>
                </select>
            </div>

            <!-- Пустое состояние -->
            <div v-if="!pipeline" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-12 text-center">
                <div class="w-14 h-14 rounded-full bg-primary/10 text-primary flex items-center justify-center mx-auto mb-4">
                    <i class="ri-bar-chart-box-line text-2xl"></i>
                </div>
                <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-2">Воронка ещё не настроена</h2>
                <p class="text-sm text-gray-500 mb-5">Создайте воронку продаж — аналитика появится сразу, как только в ней будут сделки.</p>
                <Link :href="route('settings.pipelines.index')" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 gap-1.5">
                    <i class="ri-settings-3-line"></i> Настроить воронки
                </Link>
            </div>

            <template v-else>
                <!-- KPI -->
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-5 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0">
                            <i class="ri-funds-line text-xl"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-xs text-gray-500">Сделок в работе</div>
                            <div class="text-lg font-bold text-gray-800 dark:text-gray-200">{{ forecast.count }} · {{ formatMoney(forecast.total) }}</div>
                        </div>
                    </div>
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-5 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-info/10 text-info flex items-center justify-center shrink-0">
                            <i class="ri-line-chart-line text-xl"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-xs text-gray-500">Прогноз с учётом вероятности</div>
                            <div class="text-lg font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(forecast.weighted) }}</div>
                        </div>
                    </div>
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-5 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-success/10 text-success flex items-center justify-center shrink-0">
                            <i class="ri-trophy-line text-xl"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-xs text-gray-500">Выиграно за 30 дней</div>
                            <div class="text-lg font-bold text-gray-800 dark:text-gray-200">{{ closedStats.won_count }} · {{ formatMoney(closedStats.won_amount) }}</div>
                        </div>
                    </div>
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-5 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-warning/10 text-warning flex items-center justify-center shrink-0">
                            <i class="ri-percent-line text-xl"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="text-xs text-gray-500">Win rate за 30 дней</div>
                            <div class="text-lg font-bold text-gray-800 dark:text-gray-200">
                                {{ closedStats.win_rate !== null ? closedStats.win_rate + '%' : '—' }}
                                <span class="text-xs font-normal text-gray-400" v-if="closedStats.win_rate !== null">({{ closedStats.won_count }}/{{ closedStats.won_count + closedStats.lost_count }})</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
                    <!-- Воронка по стадиям -->
                    <div class="lg:col-span-2 bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                            <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Воронка по стадиям</h3>
                        </div>
                        <div class="p-6">
                            <div v-if="funnel.length === 0" class="text-sm text-gray-400 text-center py-8">В воронке нет активных открытых стадий.</div>
                            <div v-else class="space-y-3">
                                <div v-for="row in funnel" :key="row.stage.id">
                                    <div class="flex items-center justify-between gap-2 mb-1">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <span :class="[stageColorClass[row.stage.color] || 'bg-gray-400', 'w-2.5 h-2.5 rounded-full shrink-0']"></span>
                                            <span class="text-sm font-medium text-gray-800 dark:text-gray-200 truncate">{{ row.stage.name }}</span>
                                        </div>
                                        <div class="text-xs text-gray-500 shrink-0 tabular-nums">{{ row.count }} · {{ formatMoney(row.amount) }}</div>
                                    </div>
                                    <div class="h-3 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
                                        <div
                                            :class="[stageColorClass[row.stage.color] || 'bg-gray-400', 'h-full rounded-full transition-all']"
                                            :style="{ width: (row.reached_percent / maxReached * 100) + '%' }"
                                        ></div>
                                    </div>
                                    <div class="text-[11px] text-gray-400 mt-0.5">{{ row.reached_percent }}% от всех сделок воронки дошли досюда или дальше</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Источники -->
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                            <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Источники сделок</h3>
                        </div>
                        <div class="p-6">
                            <div v-if="sourceTotal === 0" class="text-sm text-gray-400 text-center py-8">Пока нет сделок с указанным источником.</div>
                            <div v-else>
                                <div class="h-52">
                                    <Doughnut :data="doughnutData" :options="doughnutOptions" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
