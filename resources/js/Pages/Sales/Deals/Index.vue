<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import SalesNav from '@/Components/SalesNav.vue';
import PageHelper from '@/Components/PageHelper.vue';
import Modal from '@/Components/Modal.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import draggable from 'vuedraggable';
import axios from 'axios';

const props = defineProps({
    pipelines: { type: Array, default: () => [] },
    pipeline: { type: Object, default: null },
    columns: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    owners: { type: Array, default: () => [] },
    leadsWithoutDeals: { type: Number, default: 0 },
    lossReasons: { type: Array, default: () => [] },
    sources: { type: Array, default: () => [] },
});

const page = usePage();

const formatMoney = (cents) => new Intl.NumberFormat('ru-RU', {
    style: 'currency', currency: 'RUB', minimumFractionDigits: 0, maximumFractionDigits: 0,
}).format((cents || 0) / 100);

// Локальная копия колонок: vuedraggable мутирует массивы при перетаскивании,
// а пропсы Inertia неизменяемы. Пересинхронизируется при любом обновлении с сервера.
const board = ref(props.columns.map(c => ({ ...c, deals: [...c.deals] })));
watch(() => props.columns, (next) => {
    board.value = next.map(c => ({ ...c, deals: [...c.deals] }));
});

const stageColorClass = {
    gray: 'bg-gray-400',
    info: 'bg-info',
    warning: 'bg-warning',
    success: 'bg-success',
    danger: 'bg-danger',
    primary: 'bg-primary',
};

// --- ФИЛЬТРЫ ---
const search = ref(props.filters?.search || '');
const ownerFilter = ref(props.filters?.owner_user_id || '');
const onlyRotting = ref(Boolean(props.filters?.only_rotting));

const reload = () => {
    router.get(route('sales.deals.index'), {
        pipeline_id: props.pipeline?.id,
        search: search.value || undefined,
        owner_user_id: ownerFilter.value || undefined,
        only_rotting: onlyRotting.value ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true });
};

watch(search, useDebounceFn(reload, 300));
watch([ownerFilter, onlyRotting], reload);

const switchPipeline = (id) => {
    router.get(route('sales.deals.index'), { pipeline_id: id }, { preserveState: true, preserveScroll: true });
};

// --- ИТОГИ ПО ДОСКЕ ---
// Считаем по агрегатам колонок (они пришли из SQL по ВСЕЙ стадии),
// а не по загруженным карточкам — иначе цифры врали бы при подгрузке по страницам.
const openColumns = computed(() => board.value.filter(c => c.stage.type === 'open'));
const boardTotal = computed(() => openColumns.value.reduce((s, c) => s + c.amount_sum, 0));
const boardWeighted = computed(() => openColumns.value.reduce((s, c) => s + c.weighted_sum, 0));
const boardCount = computed(() => openColumns.value.reduce((s, c) => s + c.deals_count, 0));

// --- DRAG-N-DROP МЕЖДУ КОЛОНКАМИ ---
const pendingLossDeal = ref(null);
const pendingLossStageId = ref(null);

const lossForm = useForm({ pipeline_stage_id: null, loss_reason_lookup_id: '' });

const onDragChange = (event, column) => {
    // Интересует только карточка, ПРИШЕДШАЯ в эту колонку из другой.
    const added = event.added;
    if (!added) return;

    const deal = added.element;

    // Проигрыш требует причины — открываем модалку и ждём выбора,
    // сервер всё равно отклонит переход без неё (см. DealController::moveStage).
    if (column.stage.type === 'lost') {
        pendingLossDeal.value = deal;
        pendingLossStageId.value = column.stage.id;
        lossForm.pipeline_stage_id = column.stage.id;
        lossForm.loss_reason_lookup_id = '';
        return;
    }

    moveDeal(deal.id, column.stage.id);
};

const moveDeal = (dealId, stageId, lossReasonId = null) => {
    router.patch(route('sales.deals.stage.update', dealId), {
        pipeline_stage_id: stageId,
        loss_reason_lookup_id: lossReasonId,
    }, {
        preserveScroll: true,
        preserveState: false, // перезабираем доску: агрегаты колонок изменились
    });
};

const submitLoss = () => {
    if (!lossForm.loss_reason_lookup_id) return;
    moveDeal(pendingLossDeal.value.id, pendingLossStageId.value, lossForm.loss_reason_lookup_id);
    closeLossModal();
};

const closeLossModal = () => {
    // Отмена — возвращаем доску в серверное состояние, чтобы карточка
    // не осталась визуально в колонке «Проигрыш» без сохранённого перехода.
    if (pendingLossDeal.value) {
        board.value = props.columns.map(c => ({ ...c, deals: [...c.deals] }));
    }
    pendingLossDeal.value = null;
    pendingLossStageId.value = null;
    lossForm.reset();
};

// --- ПОДГРУЗКА КОЛОНКИ ---
const loadingStage = ref(null);

const loadMore = async (column) => {
    loadingStage.value = column.stage.id;
    try {
        const { data } = await axios.get(route('sales.stages.deals', column.stage.id), {
            params: {
                page: column.current_page + 1,
                search: search.value || undefined,
                owner_user_id: ownerFilter.value || undefined,
                only_rotting: onlyRotting.value ? 1 : undefined,
            },
        });
        column.deals.push(...data.deals);
        column.current_page = data.current_page;
        column.has_more = data.has_more;
    } finally {
        loadingStage.value = null;
    }
};

// --- СОЗДАНИЕ СДЕЛКИ ---
const isDealModalOpen = ref(false);
const clients = ref([]);
const vehicles = ref([]);
const loadingRefs = ref(false);

const dealForm = useForm({
    branch_id: '',
    legal_entity_id: '',
    client_id: '',
    vehicle_id: '',
    pipeline_id: '',
    owner_user_id: '',
    title: '',
    amount: 0,
    expected_close_date: '',
    source_lookup_id: '',
});

const branches = computed(() => page.props.branches || []);
const legalEntities = computed(() => page.props.legal_entities || []);

const openDealModal = async () => {
    dealForm.reset();
    dealForm.clearErrors();
    dealForm.pipeline_id = props.pipeline?.id || '';
    dealForm.branch_id = page.props.current_branch_id || branches.value[0]?.id || '';
    dealForm.legal_entity_id = page.props.current_legal_entity_id || '';
    dealForm.owner_user_id = page.props.auth?.user?.id || '';
    isDealModalOpen.value = true;

    if (clients.value.length === 0) {
        loadingRefs.value = true;
        try {
            const { data } = await axios.get(route('crm.clients.index'), { params: { per_page: 500 } });
            clients.value = (data.data || data).map(c => ({ value: c.id, label: `${c.name}${c.phone ? ' · ' + c.phone : ''}` }));
        } finally {
            loadingRefs.value = false;
        }
    }
};

// Автомобили — только выбранного клиента (сервер это же и проверяет).
watch(() => dealForm.client_id, async (clientId) => {
    dealForm.vehicle_id = '';
    vehicles.value = [];
    if (!clientId) return;
    const { data } = await axios.get(route('crm.vehicles.index'), { params: { client_id: clientId, per_page: 200 } });
    vehicles.value = (data.data || data)
        .filter(v => v.client_id === clientId)
        .map(v => ({ value: v.id, label: `${v.make?.name || ''} ${v.plate_number || ''}`.trim() }));
});

const submitDeal = () => {
    dealForm.post(route('sales.deals.store'), { preserveScroll: true });
};

const branchLegalEntities = computed(() => {
    const branch = branches.value.find(b => b.id === dealForm.branch_id);
    if (!branch) return [];
    const ids = (branch.legal_entities || []).map(le => le.id);
    return legalEntities.value.filter(le => ids.includes(le.id));
});
</script>

<template>
    <Head title="Воронка продаж" />

    <AuthenticatedLayout>
        <template #header>Продажи</template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">

            <SalesNav />

            <PageHelper title="Как устроена воронка">
                <p>Сделка — это переговоры <strong>до того</strong>, как клиент согласился: обращение, замер, КП, согласование. Склад и финансы она не трогает — они начинаются с заказ-наряда.</p>
                <p>Карточку можно перетаскивать между стадиями мышью. Когда договорились — кнопка «Оформить заказ» на карточке сделки переносит клиента, авто и предварительные позиции в заказ-наряд.</p>
                <p>Стадии, вероятности и нормативы «зависания» настраиваются в <Link :href="route('settings.pipelines.index')" class="text-primary hover:underline">Настройки → Воронки</Link>.</p>
            </PageHelper>

            <!-- Заголовок + итоги -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6">
                <div class="flex flex-wrap justify-between items-start gap-4">
                    <div class="min-w-0">
                        <div class="flex items-center gap-3 flex-wrap">
                            <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                                {{ pipeline?.name || 'Воронка продаж' }}
                            </h1>
                            <span v-if="pipeline?.business_direction" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-info/10 text-info">
                                <i class="ri-focus-3-line"></i> {{ pipeline.business_direction.name }}
                            </span>
                            <!-- Переключатель воронок — только если их реально больше одной -->
                            <select
                                v-if="pipelines.length > 1"
                                :value="pipeline?.id"
                                @change="e => switchPipeline(e.target.value)"
                                class="rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1 px-2 text-xs font-medium text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                            >
                                <option v-for="p in pipelines" :key="p.id" :value="p.id" class="bg-white dark:bg-gray-800">{{ p.name }}</option>
                            </select>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Сделок в работе: <span class="font-semibold text-gray-700 dark:text-gray-300">{{ boardCount }}</span>
                            · на сумму <span class="font-semibold text-gray-700 dark:text-gray-300">{{ formatMoney(boardTotal) }}</span>
                            · прогноз с учётом вероятности <span class="font-semibold text-success">{{ formatMoney(boardWeighted) }}</span>
                        </p>
                    </div>

                    <button @click="openDealModal" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm shrink-0">
                        <i class="ri-add-line text-base"></i> Новая сделка
                    </button>
                </div>

                <!-- Фильтры -->
                <div class="flex flex-wrap items-center gap-3 mt-5 pt-5 border-t border-gray-200 dark:border-gray-700">
                    <div class="relative flex-1 min-w-[220px]">
                        <i class="ri-search-line absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <input v-model="search" type="text" placeholder="Поиск по названию, клиенту, телефону..." class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 pl-9 pr-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                    </div>
                    <select v-model="ownerFilter" class="rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                        <option value="" class="bg-white dark:bg-gray-800">Все ответственные</option>
                        <option v-for="o in owners" :key="o.id" :value="o.id" class="bg-white dark:bg-gray-800">{{ o.name }}</option>
                    </select>
                    <button
                        @click="onlyRotting = !onlyRotting"
                        :class="[onlyRotting ? 'bg-warning text-white border-warning' : 'bg-transparent text-gray-500 border-gray-200 dark:border-gray-700 hover:border-warning hover:text-warning', 'inline-flex items-center gap-1.5 rounded-md border px-3 py-2 text-sm font-medium transition-colors']"
                        title="Сделки, которые лежат в стадии дольше норматива"
                    >
                        <i class="ri-alarm-warning-line"></i> Зависшие
                    </button>
                    <Link
                        v-if="leadsWithoutDeals > 0"
                        :href="route('crm.clients.index', { filters: { is_lead: 1 } })"
                        class="inline-flex items-center gap-1.5 rounded-md border border-dashed border-gray-300 dark:border-gray-600 px-3 py-2 text-sm text-gray-500 hover:text-primary hover:border-primary transition-colors"
                        title="Лиды, по которым ещё не заведена ни одна сделка"
                    >
                        <i class="ri-user-search-line"></i> Лидов без сделки: {{ leadsWithoutDeals }}
                    </Link>
                </div>
            </div>

            <!-- Пустое состояние: нет воронок -->
            <div v-if="!pipeline" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-12 text-center">
                <div class="w-14 h-14 rounded-full bg-primary/10 text-primary flex items-center justify-center mx-auto mb-4">
                    <i class="ri-funds-line text-2xl"></i>
                </div>
                <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 mb-2">Воронка ещё не настроена</h2>
                <p class="text-sm text-gray-500 mb-5">Создайте воронку и её стадии — после этого здесь появится доска сделок.</p>
                <Link :href="route('settings.pipelines.index')" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 gap-1.5">
                    <i class="ri-settings-3-line"></i> Настроить воронки
                </Link>
            </div>

            <!-- ДОСКА -->
            <div v-else class="overflow-x-auto pb-4">
                <div class="flex gap-4 items-start min-w-min">
                    <div
                        v-for="column in board"
                        :key="column.stage.id"
                        class="w-[300px] shrink-0 bg-gray-100/70 dark:bg-gray-800/40 rounded-lg border border-gray-200/80 dark:border-gray-700/60 flex flex-col max-h-[calc(100vh-20rem)]"
                    >
                        <!-- Шапка колонки -->
                        <div class="p-3 border-b border-gray-200 dark:border-gray-700 shrink-0">
                            <div class="flex items-center justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span :class="[stageColorClass[column.stage.color] || 'bg-gray-400', 'w-2.5 h-2.5 rounded-full shrink-0']"></span>
                                    <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200 truncate">{{ column.stage.name }}</h3>
                                </div>
                                <span class="text-xs font-semibold text-gray-500 bg-white dark:bg-gray-700 rounded-full px-2 py-0.5 shrink-0">{{ column.deals_count }}</span>
                            </div>
                            <div class="mt-1.5 flex items-baseline justify-between gap-2">
                                <span class="text-sm font-bold text-gray-700 dark:text-gray-300">{{ formatMoney(column.amount_sum) }}</span>
                                <span
                                    v-if="column.stage.type === 'open' && column.stage.probability > 0"
                                    class="text-[11px] text-gray-400"
                                    :title="`Прогноз с учётом вероятности стадии (${column.stage.probability}%)`"
                                >
                                    ≈ {{ formatMoney(column.weighted_sum) }}
                                </span>
                            </div>
                        </div>

                        <!-- Карточки -->
                        <draggable
                            :list="column.deals"
                            group="deals"
                            item-key="id"
                            class="p-2 space-y-2 overflow-y-auto flex-1 min-h-[80px]"
                            ghost-class="opacity-40"
                            @change="e => onDragChange(e, column)"
                        >
                            <template #item="{ element: deal }">
                                <Link
                                    :href="route('sales.deals.show', deal.id)"
                                    :class="[
                                        deal.is_rotting ? 'border-warning/60 bg-warning/5' : 'border-gray-200/80 dark:border-gray-700/80 bg-white dark:bg-[#313a46]',
                                        'block rounded-md border p-3 shadow-sm hover:shadow-md hover:border-primary/50 transition-all cursor-grab active:cursor-grabbing'
                                    ]"
                                >
                                    <div class="flex items-start justify-between gap-2">
                                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-200 leading-snug">{{ deal.title }}</span>
                                        <i v-if="deal.is_rotting" class="ri-alarm-warning-line text-warning shrink-0" title="Сделка зависла в стадии дольше норматива"></i>
                                    </div>

                                    <div v-if="deal.is_abandoned" class="inline-flex items-center gap-1 mt-1.5 px-1.5 py-0.5 rounded text-[11px] font-medium bg-danger/10 text-danger" title="Нет ни одной запланированной задачи по сделке — про неё легко забыть">
                                        <i class="ri-alarm-line"></i> Брошена
                                    </div>

                                    <div class="text-sm font-bold text-primary mt-1.5">{{ formatMoney(deal.amount) }}</div>

                                    <div v-if="deal.client" class="text-xs text-gray-500 mt-2 flex items-center gap-1.5 truncate">
                                        <i class="ri-user-3-line shrink-0"></i>
                                        <span class="truncate">{{ deal.client.name }}</span>
                                    </div>
                                    <div v-if="deal.vehicle" class="text-xs text-gray-400 mt-1 flex items-center gap-1.5 truncate">
                                        <i class="ri-car-line shrink-0"></i>
                                        <span class="truncate">{{ deal.vehicle.label }}</span>
                                    </div>

                                    <div class="flex items-center justify-between gap-2 mt-2.5 pt-2.5 border-t border-gray-100 dark:border-gray-700/50">
                                        <span v-if="deal.owner" class="text-[11px] text-gray-500 truncate">{{ deal.owner.name }}</span>
                                        <span v-else class="text-[11px] text-gray-300 dark:text-gray-600">без ответственного</span>
                                        <span v-if="deal.expected_close_date" class="text-[11px] text-gray-400 shrink-0">
                                            <i class="ri-calendar-line"></i> {{ new Date(deal.expected_close_date).toLocaleDateString('ru-RU') }}
                                        </span>
                                    </div>
                                </Link>
                            </template>
                        </draggable>

                        <!-- Подгрузка: доска намеренно не тянет все сделки разом -->
                        <button
                            v-if="column.has_more"
                            @click="loadMore(column)"
                            :disabled="loadingStage === column.stage.id"
                            class="m-2 mt-0 shrink-0 rounded-md border border-dashed border-gray-300 dark:border-gray-600 py-2 text-xs font-medium text-gray-500 hover:text-primary hover:border-primary transition-colors disabled:opacity-50"
                        >
                            {{ loadingStage === column.stage.id ? 'Загрузка...' : 'Показать ещё' }}
                        </button>

                        <div v-if="column.deals.length === 0" class="p-6 text-center text-xs text-gray-400">
                            Пусто
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Причина проигрыша: обязательна, иначе аналитика «почему теряем» невозможна -->
        <Modal :show="pendingLossDeal !== null" @close="closeLossModal" max-width="md">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Причина проигрыша</h3>
                <button @click="closeLossModal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-xl"></i></button>
            </div>
            <form @submit.prevent="submitLoss" class="flex flex-col">
                <div class="p-6 space-y-4">
                    <p class="text-sm text-gray-500">
                        Сделка «<span class="font-semibold text-gray-700 dark:text-gray-300">{{ pendingLossDeal?.title }}</span>» переводится в проигрыш.
                        Укажите причину — без неё через квартал будет невозможно понять, почему теряются сделки.
                    </p>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Причина <span class="text-danger">*</span></label>
                        <select v-model="lossForm.loss_reason_lookup_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                            <option value="" class="bg-white dark:bg-gray-800">Выберите причину</option>
                            <option v-for="r in lossReasons" :key="r.id" :value="r.id" class="bg-white dark:bg-gray-800">{{ r.label }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                    <button type="button" @click="closeLossModal" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                    <button type="submit" :disabled="!lossForm.loss_reason_lookup_id" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-danger text-white hover:bg-danger-600 disabled:opacity-50 disabled:cursor-not-allowed">Перевести в проигрыш</button>
                </div>
            </form>
        </Modal>

        <!-- Новая сделка -->
        <Modal :show="isDealModalOpen" @close="isDealModalOpen = false" max-width="2xl">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Новая сделка</h3>
                <button @click="isDealModalOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-xl"></i></button>
            </div>
            <form @submit.prevent="submitDeal" class="flex flex-col">
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название сделки <span class="text-danger">*</span></label>
                        <input v-model="dealForm.title" type="text" required placeholder="Например: Оклейка плёнкой Audi Q5" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        <p v-if="dealForm.errors.title" class="text-xs text-danger mt-1">{{ dealForm.errors.title }}</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Клиент <span class="text-danger">*</span></label>
                            <SearchableSelect v-model="dealForm.client_id" :options="clients" placeholder="Выберите клиента" />
                            <p class="text-[11px] text-gray-400 mt-1">Лид тоже заводится как клиент — так работает поиск дублей по телефону.</p>
                            <p v-if="dealForm.errors.client_id" class="text-xs text-danger mt-1">{{ dealForm.errors.client_id }}</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Автомобиль</label>
                            <SearchableSelect v-model="dealForm.vehicle_id" :options="vehicles" :disabled="!dealForm.client_id" placeholder="Не выбран" />
                            <p v-if="dealForm.errors.vehicle_id" class="text-xs text-danger mt-1">{{ dealForm.errors.vehicle_id }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма, ₽</label>
                            <input v-model="dealForm.amount" type="number" step="0.01" min="0" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ожидаемое закрытие</label>
                            <input v-model="dealForm.expected_close_date" type="date" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ответственный</label>
                            <select v-model="dealForm.owner_user_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" class="bg-white dark:bg-gray-800">Не назначен</option>
                                <option v-for="o in owners" :key="o.id" :value="o.id" class="bg-white dark:bg-gray-800">{{ o.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Источник</label>
                        <select v-model="dealForm.source_lookup_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                            <option value="" class="bg-white dark:bg-gray-800">Не указан</option>
                            <option v-for="s in sources" :key="s.id" :value="s.id" class="bg-white dark:bg-gray-800">{{ s.label }}</option>
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1">Для отчёта по источникам на дашборде — откуда реально приходят сделки.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div v-if="branches.length > 1">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация <span class="text-danger">*</span></label>
                            <select v-model="dealForm.branch_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option v-for="b in branches" :key="b.id" :value="b.id" class="bg-white dark:bg-gray-800">{{ b.name }}</option>
                            </select>
                        </div>
                        <div v-if="branchLegalEntities.length > 0">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Юрлицо</label>
                            <select v-model="dealForm.legal_entity_id" :disabled="branchLegalEntities.length === 1 || !!$page.props.current_legal_entity_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 disabled:opacity-60" :title="$page.props.current_legal_entity_id ? 'Юрлицо выбрано в шапке — смените его там' : ''">
                                <option value="" class="bg-white dark:bg-gray-800">Не указано</option>
                                <option v-for="le in branchLegalEntities" :key="le.id" :value="le.id" class="bg-white dark:bg-gray-800">{{ le.name }}</option>
                            </select>
                            <p v-if="dealForm.errors.legal_entity_id" class="text-xs text-danger mt-1">{{ dealForm.errors.legal_entity_id }}</p>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                    <button type="button" @click="isDealModalOpen = false" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                    <button type="submit" :disabled="dealForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Создать сделку</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
