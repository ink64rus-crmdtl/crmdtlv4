<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ActivityTimeline from '@/Components/ActivityTimeline.vue';
import PointBadge from '@/Components/PointBadge.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    deal: { type: Object, required: true },
    isRotting: { type: Boolean, default: false },
    stages: { type: Array, default: () => [] },
    pipelines: { type: Array, default: () => [] },
    owners: { type: Array, default: () => [] },
    lossReasons: { type: Array, default: () => [] },
    activities: { type: Array, default: () => [] },
    comments: { type: Array, default: () => [] },
});

const formatMoney = (cents) => new Intl.NumberFormat('ru-RU', {
    style: 'currency', currency: 'RUB', minimumFractionDigits: 2,
}).format((cents || 0) / 100);

const activeTab = ref('items');

const stageColorClass = {
    gray: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
    info: 'bg-info/10 text-info',
    warning: 'bg-warning/10 text-warning',
    success: 'bg-success/10 text-success',
    danger: 'bg-danger/10 text-danger',
    primary: 'bg-primary/10 text-primary',
};

const currentStage = computed(() => props.deal.stage);
const isClosed = computed(() => ['won', 'lost'].includes(currentStage.value?.type));

// Сумма предварительной сметы — то, из чего вырастет заказ-наряд.
const itemsTotal = computed(() =>
    (props.deal.items || []).reduce((sum, i) => sum + Math.round(i.quantity * i.price), 0)
);

// --- СМЕНА СТАДИИ ---
const pendingStageId = ref(null);
const lossForm = useForm({ pipeline_stage_id: null, loss_reason_lookup_id: '' });

const changeStage = (stageId) => {
    const stage = props.stages.find(s => s.id === Number(stageId));
    if (!stage || stage.id === currentStage.value?.id) return;

    if (stage.type === 'lost') {
        pendingStageId.value = stage.id;
        lossForm.pipeline_stage_id = stage.id;
        lossForm.loss_reason_lookup_id = '';
        return;
    }

    router.patch(route('sales.deals.stage.update', props.deal.id), {
        pipeline_stage_id: stage.id,
    }, { preserveScroll: true });
};

const submitLoss = () => {
    if (!lossForm.loss_reason_lookup_id) return;
    router.patch(route('sales.deals.stage.update', props.deal.id), {
        pipeline_stage_id: pendingStageId.value,
        loss_reason_lookup_id: lossForm.loss_reason_lookup_id,
    }, {
        preserveScroll: true,
        onSuccess: () => { pendingStageId.value = null; lossForm.reset(); },
    });
};

// --- КОНВЕРТАЦИЯ ---
const convert = () => {
    if (!confirm('Оформить заказ-наряд по этой сделке? Клиент, автомобиль и предварительные позиции будут перенесены.')) return;
    router.post(route('sales.deals.convert', props.deal.id));
};

const destroyDeal = () => {
    if (!confirm(`Удалить сделку «${props.deal.title}»?`)) return;
    router.delete(route('sales.deals.destroy', props.deal.id));
};

// --- РЕДАКТИРОВАНИЕ ---
const isEditOpen = ref(false);
const editForm = useForm({
    branch_id: props.deal.branch_id,
    legal_entity_id: props.deal.legal_entity_id || '',
    client_id: props.deal.client_id,
    vehicle_id: props.deal.vehicle_id || '',
    owner_user_id: props.deal.owner_user_id || '',
    title: props.deal.title,
    amount: (props.deal.amount || 0) / 100,
    expected_close_date: props.deal.expected_close_date
        ? String(props.deal.expected_close_date).slice(0, 10)
        : '',
    source_lookup_id: props.deal.source_lookup_id || '',
});

const submitEdit = () => {
    editForm.put(route('sales.deals.update', props.deal.id), {
        preserveScroll: true,
        onSuccess: () => { isEditOpen.value = false; },
    });
};
</script>

<template>
    <Head :title="`Сделка: ${deal.title}`" />

    <AuthenticatedLayout>
        <template #header>Продажи</template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">

            <!-- Хлебные крошки и действия -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2 text-sm">
                    <Link :href="route('sales.deals.index')" class="text-gray-500 hover:text-primary transition-colors">
                        <i class="ri-arrow-left-line"></i> Воронка
                    </Link>
                    <span class="text-gray-300 dark:text-gray-600">/</span>
                    <span class="font-semibold text-gray-800 dark:text-gray-200">{{ deal.title }}</span>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        v-if="!deal.work_order_id"
                        @click="convert"
                        class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-success text-white hover:bg-success-600 gap-1.5 shadow-sm"
                    >
                        <i class="ri-briefcase-line"></i> Оформить заказ
                    </button>
                    <Link
                        v-else
                        :href="route('operations.work-orders.show', deal.work_order_id)"
                        class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-info/10 text-info hover:bg-info hover:text-white gap-1.5"
                    >
                        <i class="ri-briefcase-line"></i> Заказ №{{ deal.work_order_id }}
                    </Link>
                    <button @click="isEditOpen = true" class="inline-flex items-center justify-center rounded px-3 py-2 text-sm font-medium bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать сделку">
                        <i class="ri-pencil-line"></i>
                    </button>
                    <button @click="destroyDeal" class="inline-flex items-center justify-center rounded px-3 py-2 text-sm font-medium bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить сделку">
                        <i class="ri-delete-bin-line"></i>
                    </button>
                </div>
            </div>

            <!-- Предупреждение о зависании -->
            <div v-if="isRotting" class="bg-warning/10 border border-warning/30 rounded-md p-4 flex items-start gap-3">
                <i class="ri-alarm-warning-line text-warning text-lg mt-0.5"></i>
                <div class="text-sm">
                    <span class="font-semibold text-warning">Сделка зависла.</span>
                    <span class="text-gray-600 dark:text-gray-400">
                        Она находится на стадии «{{ currentStage?.name }}» дольше норматива
                        ({{ currentStage?.rotting_days }} дн.). Свяжитесь с клиентом или переведите её дальше.
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

                <!-- ЛЕВАЯ КОЛОНКА -->
                <div class="lg:col-span-3 space-y-6">
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 text-center">
                        <div class="w-14 h-14 rounded-full bg-primary/10 text-primary flex items-center justify-center mx-auto mb-3">
                            <i class="ri-funds-line text-2xl"></i>
                        </div>
                        <h2 class="text-base font-bold text-gray-800 dark:text-gray-200 leading-snug">{{ deal.title }}</h2>
                        <div class="text-xl font-bold text-primary mt-2">{{ formatMoney(deal.amount) }}</div>

                        <div class="mt-4">
                            <span :class="[stageColorClass[currentStage?.color] || stageColorClass.gray, 'inline-flex items-center px-3 py-1 rounded-md text-xs font-bold tracking-wide uppercase']">
                                {{ currentStage?.name }}
                            </span>
                        </div>

                        <div class="mt-4 flex justify-center">
                            <PointBadge :branch="deal.branch" :legal-entity="deal.legal_entity" />
                        </div>

                        <!-- Смена стадии — ходит в тот же эндпоинт, что и drag-n-drop -->
                        <div class="mt-5 text-left">
                            <label class="block text-xs font-medium text-gray-500 mb-1.5">Стадия</label>
                            <select
                                :value="currentStage?.id"
                                @change="e => changeStage(e.target.value)"
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                            >
                                <option v-for="s in stages" :key="s.id" :value="s.id" class="bg-white dark:bg-gray-800">{{ s.name }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Клиент -->
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                            <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Клиент</h3>
                            <Link v-if="deal.client" :href="route('crm.clients.show', deal.client.id)" class="text-xs text-primary hover:underline">Перейти →</Link>
                        </div>
                        <div class="p-6">
                            <div v-if="deal.client">
                                <div class="font-semibold text-gray-800 dark:text-gray-200">{{ deal.client.name }}</div>
                                <div v-if="deal.client.phone" class="text-sm text-gray-500 mt-0.5">{{ deal.client.phone }}</div>
                                <span v-if="deal.client.is_lead" class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-warning/10 text-warning mt-2">Лид</span>
                            </div>
                            <div v-else class="text-sm text-gray-400">Не указан</div>
                        </div>
                    </div>

                    <!-- Автомобиль -->
                    <div v-if="deal.vehicle" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                            <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Автомобиль</h3>
                            <Link :href="route('crm.vehicles.show', deal.vehicle.id)" class="text-xs text-primary hover:underline">Перейти →</Link>
                        </div>
                        <div class="p-6 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-info/10 text-info flex items-center justify-center shrink-0">
                                <i class="ri-car-line"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="font-semibold text-gray-800 dark:text-gray-200 truncate">
                                    {{ deal.vehicle.make?.name }} {{ deal.vehicle.vehicle_model?.name }}
                                </div>
                                <div class="text-xs text-gray-500">{{ deal.vehicle.plate_number }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- СРЕДНЯЯ КОЛОНКА -->
                <div class="lg:col-span-6">
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                        <div class="border-b border-gray-200 dark:border-gray-700 px-6 pt-4">
                            <nav class="-mb-px flex gap-6 overflow-x-auto">
                                <button
                                    v-for="tab in [
                                        { key: 'items', label: 'Смета', icon: 'ri-list-check-2' },
                                        { key: 'comments', label: 'Комментарии', icon: 'ri-chat-1-line' },
                                        { key: 'history', label: 'История', icon: 'ri-time-line' },
                                    ]"
                                    :key="tab.key"
                                    @click="activeTab = tab.key"
                                    :class="[
                                        activeTab === tab.key ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300',
                                        'inline-flex items-center gap-1.5 whitespace-nowrap py-3 px-1 border-b-2 font-medium text-sm transition-colors'
                                    ]"
                                >
                                    <i :class="tab.icon"></i> {{ tab.label }}
                                </button>
                            </nav>
                        </div>

                        <!-- Смета -->
                        <div v-show="activeTab === 'items'" class="p-6">
                            <div v-if="(deal.items || []).length === 0" class="text-center py-10">
                                <div class="w-12 h-12 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-400 flex items-center justify-center mx-auto mb-3">
                                    <i class="ri-list-check-2 text-xl"></i>
                                </div>
                                <p class="text-sm text-gray-500">Предварительная смета пуста.</p>
                                <p class="text-xs text-gray-400 mt-1">
                                    Смета нужна для коммерческого предложения. Склад и финансы она не трогает —
                                    они начинаются с заказ-наряда.
                                </p>
                            </div>
                            <div v-else>
                                <div class="overflow-x-auto">
                                    <table class="w-full">
                                        <thead>
                                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                                <th class="text-left py-2 px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Наименование</th>
                                                <th class="text-right py-2 px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Кол-во</th>
                                                <th class="text-right py-2 px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Цена</th>
                                                <th class="text-right py-2 px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Сумма</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="item in deal.items" :key="item.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30">
                                                <td class="py-3 px-3 text-sm text-gray-800 dark:text-gray-200">{{ item.name }}</td>
                                                <td class="py-3 px-3 text-sm text-right">{{ parseFloat(item.quantity) }}</td>
                                                <td class="py-3 px-3 text-sm text-right whitespace-nowrap">{{ formatMoney(item.price) }}</td>
                                                <td class="py-3 px-3 text-sm font-bold text-right whitespace-nowrap">{{ formatMoney(Math.round(item.quantity * item.price)) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="flex justify-end pt-4 mt-4 border-t border-gray-200 dark:border-gray-700">
                                    <div class="flex items-center gap-6">
                                        <span class="text-sm font-bold text-gray-800 dark:text-gray-200">Итого по смете:</span>
                                        <span class="text-lg font-bold text-primary">{{ formatMoney(itemsTotal) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Комментарии -->
                        <div v-show="activeTab === 'comments'" class="p-6">
                            <ActivityTimeline :activities="comments" :comment-url="route('sales.deals.comment', deal.id)" />
                        </div>

                        <!-- История -->
                        <div v-show="activeTab === 'history'" class="p-6">
                            <ActivityTimeline :activities="activities" />
                        </div>
                    </div>
                </div>

                <!-- ПРАВАЯ КОЛОНКА -->
                <div class="lg:col-span-3 space-y-6">
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                            <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Параметры сделки</h3>
                        </div>
                        <div class="p-6 space-y-4 text-sm">
                            <div class="flex justify-between items-center gap-3">
                                <span class="text-gray-500">Воронка:</span>
                                <span class="font-medium text-gray-800 dark:text-gray-200 text-right">{{ deal.pipeline?.name }}</span>
                            </div>
                            <div v-if="deal.pipeline?.business_direction" class="flex justify-between items-center gap-3">
                                <span class="text-gray-500">Направление:</span>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-info/10 text-info">
                                    {{ deal.pipeline.business_direction.name }}
                                </span>
                            </div>
                            <div class="flex justify-between items-center gap-3">
                                <span class="text-gray-500">Вероятность:</span>
                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ currentStage?.probability ?? 0 }}%</span>
                            </div>
                            <div class="flex justify-between items-center gap-3">
                                <span class="text-gray-500">Ответственный:</span>
                                <span class="font-medium text-gray-800 dark:text-gray-200 text-right">{{ deal.owner?.name || '—' }}</span>
                            </div>
                            <div class="flex justify-between items-center gap-3">
                                <span class="text-gray-500">Ожидаемое закрытие:</span>
                                <span class="font-medium text-gray-800 dark:text-gray-200">
                                    {{ deal.expected_close_date ? new Date(deal.expected_close_date).toLocaleDateString('ru-RU') : '—' }}
                                </span>
                            </div>
                            <div v-if="deal.source" class="flex justify-between items-center gap-3">
                                <span class="text-gray-500">Источник:</span>
                                <span class="font-medium text-gray-800 dark:text-gray-200 text-right">{{ deal.source.label }}</span>
                            </div>
                            <div v-if="deal.loss_reason" class="flex justify-between items-center gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                                <span class="text-gray-500">Причина проигрыша:</span>
                                <span class="font-medium text-danger text-right">{{ deal.loss_reason.label }}</span>
                            </div>
                            <div v-if="deal.closed_at" class="flex justify-between items-center gap-3">
                                <span class="text-gray-500">Закрыта:</span>
                                <span class="font-medium text-gray-800 dark:text-gray-200">{{ new Date(deal.closed_at).toLocaleDateString('ru-RU') }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Связанные записи -->
                    <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                            <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Связанные записи</h3>
                        </div>
                        <div class="p-6 space-y-3">
                            <Link
                                v-if="deal.work_order_id"
                                :href="route('operations.work-orders.show', deal.work_order_id)"
                                class="flex items-center gap-3 p-3 rounded-md border border-gray-200 dark:border-gray-700 hover:border-primary transition-colors"
                            >
                                <div class="w-9 h-9 rounded-full bg-info/10 text-info flex items-center justify-center shrink-0"><i class="ri-briefcase-line"></i></div>
                                <div class="text-sm font-medium text-gray-800 dark:text-gray-200">Заказ-наряд №{{ deal.work_order_id }}</div>
                            </Link>
                            <div v-if="!deal.work_order_id && !deal.appointment_id" class="text-sm text-gray-400 text-center py-2">
                                Пока ничего не связано
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Причина проигрыша -->
        <Modal :show="pendingStageId !== null" @close="pendingStageId = null" max-width="md">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Причина проигрыша</h3>
                <button @click="pendingStageId = null" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
            </div>
            <form @submit.prevent="submitLoss" class="flex flex-col">
                <div class="p-6 space-y-4">
                    <p class="text-sm text-gray-500">Укажите причину — без неё через квартал будет невозможно понять, почему теряются сделки.</p>
                    <select v-model="lossForm.loss_reason_lookup_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                        <option value="" class="bg-white dark:bg-gray-800">Выберите причину</option>
                        <option v-for="r in lossReasons" :key="r.id" :value="r.id" class="bg-white dark:bg-gray-800">{{ r.label }}</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                    <button type="button" @click="pendingStageId = null" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                    <button type="submit" :disabled="!lossForm.loss_reason_lookup_id" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-danger text-white hover:bg-danger-600 disabled:opacity-50 disabled:cursor-not-allowed">Перевести в проигрыш</button>
                </div>
            </form>
        </Modal>

        <!-- Редактирование -->
        <Modal :show="isEditOpen" @close="isEditOpen = false" max-width="xl">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Редактирование сделки</h3>
                <button @click="isEditOpen = false" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
            </div>
            <form @submit.prevent="submitEdit" class="flex flex-col">
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                        <input v-model="editForm.title" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        <p v-if="editForm.errors.title" class="text-xs text-danger mt-1">{{ editForm.errors.title }}</p>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма, ₽</label>
                            <input v-model="editForm.amount" type="number" step="0.01" min="0" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ожидаемое закрытие</label>
                            <input v-model="editForm.expected_close_date" type="date" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ответственный</label>
                            <select v-model="editForm.owner_user_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" class="bg-white dark:bg-gray-800">Не назначен</option>
                                <option v-for="o in owners" :key="o.id" :value="o.id" class="bg-white dark:bg-gray-800">{{ o.name }}</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                    <button type="button" @click="isEditOpen = false" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                    <button type="submit" :disabled="editForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
