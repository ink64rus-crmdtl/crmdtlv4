<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import GroupColorPicker, { groupColorMeta } from '@/Components/GroupColorPicker.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    bonusRubPerPoint: Number,
    clientGroups: Array,
});

const rateForm = useForm({
    bonus_rub_per_point: props.bonusRubPerPoint ?? 1,
});

const submitRate = () => {
    rateForm.post(route('settings.loyalty.store'));
};

// --- Группы (грейды) клиентов: кэшбек/скидка + правила автоподбора. Тот же
// CRUD, что и в модалке CRM/Clients/Index.vue (crm.client-groups.*) — здесь
// полная форма, там — облегчённая (без правил автоподбора).
const emptyGroupForm = () => ({
    name: '',
    color: 'gray',
    cashback_percent: 0,
    discount_percent: 0,
    min_turnover_amount: '',
    min_orders_count: '',
    auto_assign_period_days: 90,
    sort_order: 0,
});

const isAddFormOpen = ref(false);
const groupForm = useForm(emptyGroupForm());

const editingGroupId = ref(null);
const editGroupForm = useForm(emptyGroupForm());

const colorMeta = groupColorMeta;

const autoRuleParts = (group) => {
    const parts = [];
    if (group.min_turnover_amount) parts.push({ icon: 'ri-line-chart-line', text: `оборот от ${Math.round(group.min_turnover_amount / 100).toLocaleString('ru-RU')} ₽` });
    if (group.min_orders_count) parts.push({ icon: 'ri-shopping-bag-3-line', text: `заказов от ${group.min_orders_count}` });
    return parts;
};

const submitGroup = () => {
    groupForm.post(route('crm.client-groups.store'), {
        preserveScroll: true,
        onSuccess: () => {
            groupForm.reset();
            isAddFormOpen.value = false;
        },
    });
};

const startEditGroup = (group) => {
    editingGroupId.value = group.id;
    editGroupForm.clearErrors();
    editGroupForm.name = group.name;
    editGroupForm.color = group.color;
    editGroupForm.cashback_percent = group.cashback_percent;
    editGroupForm.discount_percent = group.discount_percent;
    editGroupForm.min_turnover_amount = group.min_turnover_amount ? group.min_turnover_amount / 100 : '';
    editGroupForm.min_orders_count = group.min_orders_count ?? '';
    editGroupForm.auto_assign_period_days = group.auto_assign_period_days ?? 90;
    editGroupForm.sort_order = group.sort_order ?? 0;
};

const cancelEditGroup = () => {
    editingGroupId.value = null;
};

const submitEditGroup = (group) => {
    editGroupForm.put(route('crm.client-groups.update', group.id), {
        preserveScroll: true,
        onSuccess: () => {
            editingGroupId.value = null;
        },
    });
};

const deleteGroup = (group) => {
    if (!confirm(`Удалить группу «${group.name}»? У клиентов этой группы группа будет сброшена на «Без группы».`)) return;
    router.delete(route('crm.client-groups.destroy', group.id), { preserveScroll: true });
};
</script>

<template>
    <Head title="Система лояльности" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">

            <SettingsNav />

            <PageHelper title="Как работает система лояльности">
                <p><i class="ri-coin-line text-primary align-middle mr-1"></i><strong>Курс баллов</strong> — сколько рублей стоит один бонусный балл. Используется в обе стороны: при <em>начислении</em> кэшбека клиенту (сколько баллов дать за рубль оплаты) и при <em>списании</em> (сколько баллов клиент отдаёт за рубль скидки на оплату заказа).</p>
                <p><i class="ri-vip-crown-2-line text-primary align-middle mr-1"></i><strong>Грейды клиентов</strong> — это группы клиентов (VIP, Постоянный и т.п.), у каждой свой процент кэшбека в баллы и/или прямой скидки на сумму заказа. Скидка применяется автоматически к каждому новому заказу клиента — своя скидка клиента (карточка клиента → вкладка «Настройки и поля») всегда важнее групповой, если задана.</p>
                <p><i class="ri-magic-line text-primary align-middle mr-1"></i><strong>Автоподбор грейда</strong> — необязательная функция: если задать минимальный оборот и/или минимальное число завершённых заказов за период, клиент будет переводиться в такую группу автоматически при каждой оплате заказа, как только наберёт нужную историю. Если оба условия заданы — нужно выполнить оба сразу. Группы проверяются по «Приоритету» от меньшего к большему, первая подошедшая побеждает — значит именно приоритет определяет, какая группа «старше».</p>
                <p><i class="ri-lock-line text-primary align-middle mr-1"></i>Если менеджер выбрал группу клиенту вручную — автоподбор для этого клиента отключается (значок замка в карточке клиента), пока не нажать «Вернуть на автоподбор».</p>
            </PageHelper>

            <!-- Курс балла — отдельная небольшая карточка с крупной цветной иконкой -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6">
                <div class="flex flex-wrap items-center gap-6">
                    <div class="w-14 h-14 rounded-full bg-primary/10 text-primary flex items-center justify-center text-2xl shrink-0">
                        <i class="ri-coin-line"></i>
                    </div>
                    <div class="flex-1 min-w-[200px]">
                        <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Курс бонусного балла</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Общий для всей программы лояльности — используется и при начислении кэшбека, и при списании баллов клиентом</p>
                    </div>
                    <form @submit.prevent="submitRate" class="flex items-end gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1.5">1 балл стоит</label>
                            <div class="flex items-center gap-2">
                                <input
                                    v-model="rateForm.bonus_rub_per_point"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    required
                                    class="block w-28 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2.5 px-3 text-base font-semibold text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                                />
                                <span class="text-lg text-gray-500 dark:text-gray-400 font-semibold shrink-0">₽</span>
                            </div>
                            <span v-if="rateForm.errors.bonus_rub_per_point" class="text-xs text-danger mt-1 block">{{ rateForm.errors.bonus_rub_per_point }}</span>
                        </div>
                        <button
                            type="submit"
                            :disabled="rateForm.processing || !rateForm.isDirty"
                            class="inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2.5 text-sm font-semibold transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50"
                        >
                            <i class="ri-save-line"></i>
                            <span v-if="rateForm.processing">Сохранение...</span>
                            <span v-else>Сохранить</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Грейды — карточки-плитки -->
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-base font-semibold text-gray-800 dark:text-gray-200 flex items-center gap-2">
                        <i class="ri-vip-crown-2-line text-primary"></i> Грейды клиентов
                    </h2>
                    <button
                        v-if="!isAddFormOpen"
                        type="button"
                        @click="isAddFormOpen = true"
                        class="inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 transition-all duration-300 shadow-sm"
                    >
                        <i class="ri-add-line"></i> Новый грейд
                    </button>
                </div>

                <p v-if="clientGroups.length === 0 && !isAddFormOpen" class="text-sm text-gray-400 text-center py-10 bg-white dark:bg-[#313a46] border border-dashed border-gray-200 dark:border-gray-700 rounded-md">
                    Грейдов ещё нет — нажмите «Новый грейд», чтобы завести первый
                </p>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
                    <div
                        v-for="group in clientGroups"
                        :key="group.id"
                        class="bg-white border border-gray-200/80 rounded-lg shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden"
                    >
                        <template v-if="editingGroupId !== group.id">
                            <div class="p-5 space-y-4">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-center gap-3 min-w-0">
                                        <div :class="[colorMeta(group.color).icon, 'w-12 h-12 rounded-full flex items-center justify-center text-xl shrink-0']">
                                            <i class="ri-vip-crown-2-line"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <h3 class="text-base font-bold text-gray-800 dark:text-gray-200 truncate">{{ group.name }}</h3>
                                            <span class="text-xs text-gray-400 dark:text-gray-500 flex items-center gap-1"><i class="ri-stack-line"></i> Приоритет {{ group.sort_order }}</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-1 shrink-0">
                                        <button type="button" @click="startEditGroup(group)" class="text-gray-400 hover:text-primary p-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-800" title="Редактировать"><i class="ri-pencil-line"></i></button>
                                        <button type="button" @click="deleteGroup(group)" class="text-gray-400 hover:text-danger p-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-800" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3">
                                    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-md p-3 flex items-center gap-2.5">
                                        <i class="ri-coins-line text-lg text-warning shrink-0"></i>
                                        <div>
                                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Кэшбек</p>
                                            <p class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ group.cashback_percent }}%</p>
                                        </div>
                                    </div>
                                    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-md p-3 flex items-center gap-2.5">
                                        <i class="ri-price-tag-3-line text-lg text-success shrink-0"></i>
                                        <div>
                                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Скидка</p>
                                            <p class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ group.discount_percent }}%</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="pt-3 border-t border-gray-100 dark:border-gray-700/50">
                                    <p v-if="autoRuleParts(group).length" class="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap items-center gap-x-3 gap-y-1">
                                        <i class="ri-magic-line text-info"></i>
                                        <span v-for="(part, i) in autoRuleParts(group)" :key="i" class="flex items-center gap-1">
                                            <i :class="part.icon"></i>{{ part.text }}
                                        </span>
                                        <span class="flex items-center gap-1"><i class="ri-calendar-2-line"></i>за {{ group.auto_assign_period_days }} дн.</span>
                                    </p>
                                    <p v-else class="text-xs text-gray-400 dark:text-gray-500 flex items-center gap-1.5">
                                        <i class="ri-hand-coin-line"></i> Присваивается только вручную
                                    </p>
                                </div>
                            </div>
                        </template>

                        <!-- Редактирование — та же карточка, разворачивается в полную форму -->
                        <div v-else class="p-5 space-y-5">
                            <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2"><i class="ri-pencil-line text-primary"></i> Редактирование грейда</h4>

                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Название грейда</label>
                                <input v-model="editGroupForm.name" type="text" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Цвет бейджа</label>
                                <GroupColorPicker v-model="editGroupForm.color" />
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5"><i class="ri-coins-line text-warning"></i> Кэшбек, %</label>
                                    <input v-model="editGroupForm.cashback_percent" type="number" step="0.01" min="0" max="100" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    <p class="text-[11px] text-gray-400 mt-1">Доля от суммы оплаты, начисляемая баллами</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5"><i class="ri-price-tag-3-line text-success"></i> Скидка, %</label>
                                    <input v-model="editGroupForm.discount_percent" type="number" step="0.01" min="0" max="100" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    <p class="text-[11px] text-gray-400 mt-1">Скидка на сумму каждого заказа клиента</p>
                                </div>
                            </div>

                            <div class="pt-3 border-t border-gray-100 dark:border-gray-700/50 space-y-3">
                                <p class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-1.5"><i class="ri-magic-line text-info"></i> Правила автоподбора</p>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Мин. оборот, ₽</label>
                                        <input v-model="editGroupForm.min_turnover_amount" type="number" step="1" min="0" placeholder="Без условия" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Мин. заказов</label>
                                        <input v-model="editGroupForm.min_orders_count" type="number" step="1" min="0" placeholder="Без условия" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    </div>
                                </div>
                                <p class="text-[11px] text-gray-400">Оба условия проверяются одновременно, если заданы оба — оставьте пустым, если условие не нужно</p>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Период, дней</label>
                                        <input v-model="editGroupForm.auto_assign_period_days" type="number" step="1" min="1" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Приоритет</label>
                                        <input v-model="editGroupForm.sort_order" type="number" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                        <p class="text-[11px] text-gray-400 mt-1">Меньше — выше, проверяется первым</p>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end gap-2 pt-2">
                                <button type="button" @click="cancelEditGroup()" class="inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">
                                    Отмена
                                </button>
                                <button type="button" @click="submitEditGroup(group)" :disabled="editGroupForm.processing" class="inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                                    <i class="ri-save-line"></i> Сохранить
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Новый грейд — карточка-плитка с пунктирной рамкой, разворачивается в форму -->
                    <div v-if="isAddFormOpen" class="bg-white border-2 border-dashed border-primary/40 rounded-lg dark:bg-[#313a46] p-5 space-y-5 md:col-span-2 xl:col-span-3">
                        <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 flex items-center gap-2"><i class="ri-add-circle-line text-primary"></i> Новый грейд</h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Название грейда <span class="text-danger">*</span></label>
                                    <input v-model="groupForm.name" type="text" required placeholder="Например, VIP" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    <span v-if="groupForm.errors.name" class="text-xs text-danger mt-1 block">{{ groupForm.errors.name }}</span>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Цвет бейджа</label>
                                    <GroupColorPicker v-model="groupForm.color" />
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5"><i class="ri-coins-line text-warning"></i> Кэшбек, %</label>
                                        <input v-model="groupForm.cashback_percent" type="number" step="0.01" min="0" max="100" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                        <p class="text-[11px] text-gray-400 mt-1">Доля от оплаты баллами</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5"><i class="ri-price-tag-3-line text-success"></i> Скидка, %</label>
                                        <input v-model="groupForm.discount_percent" type="number" step="0.01" min="0" max="100" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                        <p class="text-[11px] text-gray-400 mt-1">Скидка на сумму заказа</p>
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-4">
                                <p class="text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider flex items-center gap-1.5"><i class="ri-magic-line text-info"></i> Правила автоподбора (необязательно)</p>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Мин. оборот, ₽</label>
                                        <input v-model="groupForm.min_turnover_amount" type="number" step="1" min="0" placeholder="Без условия" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Мин. заказов</label>
                                        <input v-model="groupForm.min_orders_count" type="number" step="1" min="0" placeholder="Без условия" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    </div>
                                </div>
                                <p class="text-[11px] text-gray-400">Заполните хотя бы одно, чтобы грейд присваивался автоматически — иначе только вручную</p>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Период, дней</label>
                                        <input v-model="groupForm.auto_assign_period_days" type="number" step="1" min="1" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1.5">Приоритет</label>
                                        <input v-model="groupForm.sort_order" type="number" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                        <p class="text-[11px] text-gray-400 mt-1">Меньше — выше, проверяется первым</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-2 pt-2 border-t border-gray-100 dark:border-gray-700/50">
                            <button type="button" @click="isAddFormOpen = false; groupForm.reset()" class="inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700">
                                Отмена
                            </button>
                            <button type="button" @click="submitGroup" :disabled="groupForm.processing" class="inline-flex items-center justify-center gap-1.5 rounded-md px-5 py-2 text-sm font-semibold bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                                <i class="ri-add-line"></i> Добавить грейд
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </AuthenticatedLayout>
</template>
