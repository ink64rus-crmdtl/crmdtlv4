<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import Modal from '@/Components/Modal.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import draggable from 'vuedraggable';

const props = defineProps({
    pipelines: { type: Array, default: () => [] },
    businessDirections: { type: Array, default: () => [] },
    stageTypes: { type: Object, default: () => ({}) },
    automationActions: { type: Object, default: () => ({}) },
    messageTemplates: { type: Array, default: () => [] },
});

const stageColors = [
    { key: 'gray', label: 'Серый', dot: 'bg-gray-400' },
    { key: 'info', label: 'Голубой', dot: 'bg-info' },
    { key: 'primary', label: 'Синий', dot: 'bg-primary' },
    { key: 'warning', label: 'Жёлтый', dot: 'bg-warning' },
    { key: 'success', label: 'Зелёный', dot: 'bg-success' },
    { key: 'danger', label: 'Красный', dot: 'bg-danger' },
];

const dotClass = (color) => stageColors.find(c => c.key === color)?.dot || 'bg-gray-400';

// Локальные копии списков стадий — vuedraggable мутирует массив при перетаскивании.
const stageLists = ref({});
const syncStages = () => {
    const next = {};
    props.pipelines.forEach(p => { next[p.id] = [...(p.stages || [])]; });
    stageLists.value = next;
};
syncStages();
watch(() => props.pipelines, syncStages, { deep: true });

const persistOrder = (pipelineId) => {
    router.post(route('settings.pipelines.stages.reorder', pipelineId), {
        ids: stageLists.value[pipelineId].map(s => s.id),
    }, { preserveScroll: true, preserveState: true });
};

// --- ВОРОНКА ---
const isPipelineModalOpen = ref(false);
const editingPipeline = ref(null);

const pipelineForm = useForm({
    name: '',
    business_direction_id: '',
    is_default: false,
    is_active: true,
});

const openPipelineModal = (pipeline = null) => {
    editingPipeline.value = pipeline;
    if (pipeline) {
        pipelineForm.name = pipeline.name;
        pipelineForm.business_direction_id = pipeline.business_direction_id || '';
        pipelineForm.is_default = Boolean(pipeline.is_default);
        pipelineForm.is_active = Boolean(pipeline.is_active);
    } else {
        pipelineForm.reset();
        pipelineForm.is_active = true;
    }
    pipelineForm.clearErrors();
    isPipelineModalOpen.value = true;
};

const submitPipeline = () => {
    const opts = { preserveScroll: true, onSuccess: () => { isPipelineModalOpen.value = false; } };
    if (editingPipeline.value) {
        pipelineForm.put(route('settings.pipelines.update', editingPipeline.value.id), opts);
    } else {
        pipelineForm.post(route('settings.pipelines.store'), opts);
    }
};

const destroyPipeline = (pipeline) => {
    if (!confirm(`Удалить воронку «${pipeline.name}»? Действие необратимо.`)) return;
    router.delete(route('settings.pipelines.destroy', pipeline.id), { preserveScroll: true });
};

// --- СТАДИЯ ---
const isStageModalOpen = ref(false);
const editingStage = ref(null);
const stagePipelineId = ref(null);

const stageForm = useForm({
    name: '',
    color: 'gray',
    type: 'open',
    probability: 0,
    rotting_days: null,
    is_active: true,
});

const openStageModal = (pipelineId, stage = null) => {
    stagePipelineId.value = pipelineId;
    editingStage.value = stage;
    if (stage) {
        stageForm.name = stage.name;
        stageForm.color = stage.color;
        stageForm.type = stage.type;
        stageForm.probability = stage.probability;
        stageForm.rotting_days = stage.rotting_days;
        stageForm.is_active = Boolean(stage.is_active);
    } else {
        stageForm.reset();
        stageForm.color = 'gray';
        stageForm.type = 'open';
        stageForm.is_active = true;
    }
    stageForm.clearErrors();
    isStageModalOpen.value = true;
};

const submitStage = () => {
    const opts = { preserveScroll: true, onSuccess: () => { isStageModalOpen.value = false; } };
    if (editingStage.value) {
        stageForm.put(route('settings.pipelines.stages.update', editingStage.value.id), opts);
    } else {
        stageForm.post(route('settings.pipelines.stages.store', stagePipelineId.value), opts);
    }
};

const destroyStage = (stage) => {
    if (!confirm(`Удалить стадию «${stage.name}»?`)) return;
    router.delete(route('settings.pipelines.stages.destroy', stage.id), { preserveScroll: true });
};

const isClosingStage = (stage) => ['won', 'lost'].includes(stage.type);

// --- АВТОМАТИЗАЦИИ СТАДИИ (этап 3) ---
const isAutomationsModalOpen = ref(false);
const automationsStage = ref(null);

// Локальный поиск актуального списка стадии по id — после любого action
// Inertia обновляет props.pipelines целиком, а automationsStage — снэпшот
// на момент открытия модалки.
const currentAutomations = computed(() => {
    if (!automationsStage.value) return [];
    for (const p of props.pipelines) {
        const s = (p.stages || []).find(s => s.id === automationsStage.value.id);
        if (s) return s.automations || [];
    }
    return [];
});

const openAutomationsModal = (stage) => {
    automationsStage.value = stage;
    automationForm.reset();
    automationForm.clearErrors();
    isAutomationsModalOpen.value = true;
};

const automationForm = useForm({
    action: 'send_message',
    message_template_id: '',
    task_title: '',
    task_due_offset_days: '',
    is_active: true,
});

const submitAutomation = () => {
    automationForm.post(route('settings.pipelines.stages.automations.store', automationsStage.value.id), {
        preserveScroll: true,
        onSuccess: () => { automationForm.reset(); automationForm.is_active = true; },
    });
};

const toggleAutomation = (automation) => {
    router.put(route('settings.pipelines.stages.automations.update', automation.id), {
        action: automation.action,
        message_template_id: automation.message_template_id,
        task_title: automation.task_title,
        task_due_offset_days: automation.task_due_offset_days,
        is_active: !automation.is_active,
    }, { preserveScroll: true });
};

const destroyAutomation = (automation) => {
    if (!confirm('Удалить эту автоматизацию?')) return;
    router.delete(route('settings.pipelines.stages.automations.destroy', automation.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Воронки продаж" />

    <AuthenticatedLayout>
        <template #header>Настройки компании</template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            <SettingsNav />

            <PageHelper title="Как настроить воронку">
                <p>Воронка — это набор стадий, по которым сделка движется от первого обращения до денег. Стадии перетаскиваются мышью — порядок на доске будет такой же.</p>
                <p><strong>Вероятность</strong> нужна для прогноза выручки: система умножает сумму сделки на вероятность её стадии и показывает реалистичный прогноз, а не сумму пожеланий.</p>
                <p><strong>Норматив зависания</strong> — через сколько дней без движения карточка на доске подсветится. Оставьте пустым, если следить не нужно.</p>
                <p>Стадии «Успех» и «Проигрыш» удалить нельзя: на них завязан автоматический перевод сделки при оплате заказа и отчёты по воронке.</p>
            </PageHelper>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex flex-wrap justify-between items-center gap-4">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Воронки продаж</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Несколько воронок — для разных направлений: услуги, продажа плёнки, опт</p>
                </div>
                <button @click="openPipelineModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                    <i class="ri-add-line text-base"></i> Добавить воронку
                </button>
            </div>

            <div v-if="pipelines.length === 0" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-12 text-center">
                <div class="w-14 h-14 rounded-full bg-primary/10 text-primary flex items-center justify-center mx-auto mb-4">
                    <i class="ri-funds-line text-2xl"></i>
                </div>
                <p class="text-sm text-gray-500">Воронок пока нет. Создайте первую — стадии по умолчанию добавятся автоматически.</p>
            </div>

            <!-- Воронки -->
            <div v-for="pipeline in pipelines" :key="pipeline.id" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex flex-wrap justify-between items-center gap-3">
                    <div class="flex items-center gap-2.5 flex-wrap min-w-0">
                        <div class="w-9 h-9 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0">
                            <i class="ri-funds-line"></i>
                        </div>
                        <h2 class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ pipeline.name }}</h2>
                        <span v-if="pipeline.is_default" class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-success/10 text-success">По умолчанию</span>
                        <span v-if="!pipeline.is_active" class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Выключена</span>
                        <span v-if="pipeline.business_direction" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium bg-info/10 text-info">
                            <i class="ri-focus-3-line"></i> {{ pipeline.business_direction.name }}
                        </span>
                        <span class="text-xs text-gray-400">сделок: {{ pipeline.deals_count }}</span>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button @click="openStageModal(pipeline.id)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium bg-primary/10 text-primary hover:bg-primary hover:text-white gap-1">
                            <i class="ri-add-line"></i> Стадия
                        </button>
                        <button @click="openPipelineModal(pipeline)" class="w-8 h-8 rounded-full bg-primary/10 text-primary hover:bg-primary hover:text-white inline-flex items-center justify-center transition-colors" title="Редактировать воронку">
                            <i class="ri-pencil-line"></i>
                        </button>
                        <button
                            @click="destroyPipeline(pipeline)"
                            :disabled="pipeline.deals_count > 0"
                            :title="pipeline.deals_count > 0 ? 'В воронке есть сделки — сначала перенесите или удалите их' : 'Удалить воронку'"
                            class="w-8 h-8 rounded-full bg-danger/10 text-danger hover:bg-danger hover:text-white inline-flex items-center justify-center transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-danger/10 disabled:hover:text-danger"
                        >
                            <i class="ri-delete-bin-line"></i>
                        </button>
                    </div>
                </div>

                <!-- Стадии: голая вёрстка таблицы, т.к. DataTable не поддерживает drag-n-drop строк -->
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="w-10"></th>
                                <th class="text-left py-3 px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Стадия</th>
                                <th class="text-left py-3 px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Тип</th>
                                <th class="text-right py-3 px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Вероятность</th>
                                <th class="text-right py-3 px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Зависание</th>
                                <th class="text-right py-3 px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500">Действия</th>
                            </tr>
                        </thead>
                        <draggable
                            v-model="stageLists[pipeline.id]"
                            tag="tbody"
                            item-key="id"
                            handle=".drag-handle"
                            @end="persistOrder(pipeline.id)"
                        >
                            <template #item="{ element: stage }">
                                <tr class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 border-b border-gray-100 dark:border-gray-700/50 last:border-0">
                                    <td class="py-3 pl-4 text-gray-300 dark:text-gray-600">
                                        <i class="ri-draggable text-lg cursor-grab active:cursor-grabbing drag-handle" title="Перетащите, чтобы изменить порядок"></i>
                                    </td>
                                    <td class="py-3 px-3">
                                        <div class="flex items-center gap-2">
                                            <span :class="[dotClass(stage.color), 'w-2.5 h-2.5 rounded-full shrink-0']"></span>
                                            <span class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ stage.name }}</span>
                                            <span v-if="!stage.is_active" class="text-[11px] text-gray-400">(скрыта)</span>
                                        </div>
                                    </td>
                                    <td class="py-3 px-3">
                                        <span :class="[
                                            stage.type === 'won' ? 'bg-success/10 text-success' : stage.type === 'lost' ? 'bg-danger/10 text-danger' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                                            'inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium'
                                        ]">{{ stageTypes[stage.type] || stage.type }}</span>
                                    </td>
                                    <td class="py-3 px-3 text-sm text-right tabular-nums text-gray-700 dark:text-gray-300">{{ stage.probability }}%</td>
                                    <td class="py-3 px-3 text-sm text-right tabular-nums text-gray-700 dark:text-gray-300">
                                        {{ stage.rotting_days ? stage.rotting_days + ' дн.' : '—' }}
                                    </td>
                                    <td class="py-3 px-3">
                                        <div class="flex items-center justify-end gap-2">
                                            <button @click="openAutomationsModal(stage)" class="relative w-8 h-8 rounded-full bg-warning/10 text-warning hover:bg-warning hover:text-white inline-flex items-center justify-center transition-colors" title="Автоматизации при входе в стадию">
                                                <i class="ri-flashlight-line"></i>
                                                <span v-if="(stage.automations || []).filter(a => a.is_active).length > 0" class="absolute -top-1 -right-1 w-4 h-4 rounded-full bg-warning text-white text-[10px] font-bold flex items-center justify-center">{{ (stage.automations || []).filter(a => a.is_active).length }}</span>
                                            </button>
                                            <button @click="openStageModal(pipeline.id, stage)" class="w-8 h-8 rounded-full bg-primary/10 text-primary hover:bg-primary hover:text-white inline-flex items-center justify-center transition-colors" title="Редактировать стадию">
                                                <i class="ri-pencil-line"></i>
                                            </button>
                                            <button
                                                @click="destroyStage(stage)"
                                                :disabled="isClosingStage(stage)"
                                                :title="isClosingStage(stage) ? 'Стадии «Успех» и «Проигрыш» удалить нельзя — на них завязаны отчёты и автопереход при оплате' : 'Удалить стадию'"
                                                class="w-8 h-8 rounded-full bg-danger/10 text-danger hover:bg-danger hover:text-white inline-flex items-center justify-center transition-colors disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-danger/10 disabled:hover:text-danger"
                                            >
                                                <i class="ri-delete-bin-line"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </draggable>
                    </table>
                </div>
            </div>
        </div>

        <!-- Форма воронки -->
        <Modal :show="isPipelineModalOpen" @close="isPipelineModalOpen = false" max-width="lg">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingPipeline ? 'Редактирование воронки' : 'Новая воронка' }}</h3>
                <button @click="isPipelineModalOpen = false" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
            </div>
            <form @submit.prevent="submitPipeline" class="flex flex-col">
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                        <input v-model="pipelineForm.name" type="text" required placeholder="Например: Продажа плёнки" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        <p v-if="pipelineForm.errors.name" class="text-xs text-danger mt-1">{{ pipelineForm.errors.name }}</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Направление деятельности</label>
                        <select v-model="pipelineForm.business_direction_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                            <option value="" class="bg-white dark:bg-gray-800">Не привязано</option>
                            <option v-for="d in businessDirections" :key="d.id" :value="d.id" class="bg-white dark:bg-gray-800">{{ d.name }}</option>
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1">Так продажи можно вести отдельно от услуг, не заводя отдельный раздел.</p>
                    </div>
                    <div class="flex items-center pt-2">
                        <div @click="pipelineForm.is_default = !pipelineForm.is_default" :class="[pipelineForm.is_default ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                            <div :class="[pipelineForm.is_default ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="pipelineForm.is_default = !pipelineForm.is_default">
                            Открывать по умолчанию
                        </label>
                    </div>
                    <div class="flex items-center pt-1">
                        <div @click="pipelineForm.is_active = !pipelineForm.is_active" :class="[pipelineForm.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                            <div :class="[pipelineForm.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="pipelineForm.is_active = !pipelineForm.is_active">
                            Воронка активна
                        </label>
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                    <button type="button" @click="isPipelineModalOpen = false" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                    <button type="submit" :disabled="pipelineForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                </div>
            </form>
        </Modal>

        <!-- Форма стадии -->
        <Modal :show="isStageModalOpen" @close="isStageModalOpen = false" max-width="lg">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingStage ? 'Редактирование стадии' : 'Новая стадия' }}</h3>
                <button @click="isStageModalOpen = false" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
            </div>
            <form @submit.prevent="submitStage" class="flex flex-col">
                <div class="p-6 space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                        <input v-model="stageForm.name" type="text" required placeholder="Например: КП отправлено" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        <p v-if="stageForm.errors.name" class="text-xs text-danger mt-1">{{ stageForm.errors.name }}</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Цвет</label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="c in stageColors"
                                :key="c.key"
                                type="button"
                                @click="stageForm.color = c.key"
                                :title="c.label"
                                :class="[
                                    c.dot,
                                    stageForm.color === c.key ? 'ring-2 ring-offset-2 ring-primary dark:ring-offset-[#313a46]' : '',
                                    'w-8 h-8 rounded-full transition-all inline-flex items-center justify-center text-white'
                                ]"
                            >
                                <i v-if="stageForm.color === c.key" class="ri-check-line"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип стадии <span class="text-danger">*</span></label>
                        <select
                            v-model="stageForm.type"
                            :disabled="editingStage && isClosingStage(editingStage)"
                            :title="editingStage && isClosingStage(editingStage) ? 'У стадий «Успех» и «Проигрыш» тип менять нельзя — на них завязаны отчёты и автопереход при оплате' : ''"
                            class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 disabled:opacity-60 disabled:cursor-not-allowed"
                        >
                            <option v-for="(label, key) in stageTypes" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ label }}</option>
                        </select>
                        <p v-if="editingStage && isClosingStage(editingStage)" class="text-[11px] text-gray-400 mt-1">
                            Тип закрывающей стадии изменить нельзя: на неё завязан автоматический перевод сделки при оплате заказа.
                        </p>
                        <p v-if="stageForm.errors.type" class="text-xs text-danger mt-1">{{ stageForm.errors.type }}</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Вероятность, %</label>
                            <input v-model.number="stageForm.probability" type="number" min="0" max="100" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <p class="text-[11px] text-gray-400 mt-1">Для прогноза выручки: сумма × вероятность</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Норматив зависания, дней</label>
                            <input v-model.number="stageForm.rotting_days" type="number" min="1" max="365" placeholder="не следить" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <p class="text-[11px] text-gray-400 mt-1">Пусто — не подсвечивать зависшие</p>
                        </div>
                    </div>

                    <div class="flex items-center pt-1">
                        <div @click="stageForm.is_active = !stageForm.is_active" :class="[stageForm.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative shrink-0']">
                            <div :class="[stageForm.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="stageForm.is_active = !stageForm.is_active">
                            Показывать стадию на доске
                        </label>
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                    <button type="button" @click="isStageModalOpen = false" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                    <button type="submit" :disabled="stageForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                </div>
            </form>
        </Modal>

        <!-- Автоматизации стадии (этап 3) -->
        <Modal :show="isAutomationsModalOpen" @close="isAutomationsModalOpen = false" max-width="2xl">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Автоматизации стадии «{{ automationsStage?.name }}»</h3>
                <button @click="isAutomationsModalOpen = false" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
            </div>
            <div class="p-6 space-y-5 max-h-[70vh] overflow-y-auto">
                <p class="text-sm text-gray-500">Срабатывают автоматически, как только сделка попадает на эту стадию — при создании, перетаскивании на доске или автопереходе в «Успех» после оплаты заказа.</p>

                <div v-if="currentAutomations.length === 0" class="text-sm text-gray-400 text-center py-4">Автоматизаций пока нет.</div>

                <div v-else class="space-y-2">
                    <div v-for="automation in currentAutomations" :key="automation.id" :class="[automation.is_active ? 'border-gray-200 dark:border-gray-700' : 'border-gray-100 dark:border-gray-800 opacity-50', 'rounded-md border p-3 flex items-center justify-between gap-3']">
                        <div class="min-w-0">
                            <div class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ automationActions[automation.action] }}</div>
                            <div class="text-xs text-gray-500 mt-0.5 truncate">
                                <span v-if="automation.action === 'send_message'">Шаблон: {{ automation.message_template?.name || '—' }}</span>
                                <span v-else>{{ automation.task_title || '(текст по умолчанию)' }}<span v-if="automation.task_due_offset_days !== null"> · срок через {{ automation.task_due_offset_days }} дн.</span></span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button @click="toggleAutomation(automation)" :class="[automation.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full transition-all duration-200 relative shrink-0']" :title="automation.is_active ? 'Выключить' : 'Включить'">
                                <div :class="[automation.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </button>
                            <button @click="destroyAutomation(automation)" class="w-8 h-8 rounded-full bg-danger/10 text-danger hover:bg-danger hover:text-white inline-flex items-center justify-center transition-colors" title="Удалить">
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <form @submit.prevent="submitAutomation" class="border-t border-gray-200 dark:border-gray-700 pt-5 space-y-3">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Добавить действие</label>
                    <select v-model="automationForm.action" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                        <option v-for="(label, key) in automationActions" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ label }}</option>
                    </select>

                    <div v-if="automationForm.action === 'send_message'">
                        <select v-model="automationForm.message_template_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                            <option value="" class="bg-white dark:bg-gray-800">Выберите шаблон</option>
                            <option v-for="t in messageTemplates" :key="t.id" :value="t.id" class="bg-white dark:bg-gray-800">{{ t.name }}</option>
                        </select>
                        <p v-if="automationForm.errors.message_template_id" class="text-xs text-danger mt-1">{{ automationForm.errors.message_template_id }}</p>
                        <p v-if="messageTemplates.length === 0" class="text-[11px] text-gray-400 mt-1">Шаблонов сообщений пока нет — заведите их в Настройки → Шаблоны сообщений.</p>
                    </div>

                    <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <input v-model="automationForm.task_title" type="text" :placeholder="automationForm.action === 'create_appointment' ? 'Запланировать визит по сделке «...»' : 'Задача по сделке «...»'" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <p class="text-[11px] text-gray-400 mt-1">Пусто — подставится текст по умолчанию</p>
                        </div>
                        <div>
                            <input v-model.number="automationForm.task_due_offset_days" type="number" min="0" max="365" placeholder="Срок, дней от входа в стадию" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" :disabled="automationForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50 gap-1.5">
                            <i class="ri-add-line"></i> Добавить
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
