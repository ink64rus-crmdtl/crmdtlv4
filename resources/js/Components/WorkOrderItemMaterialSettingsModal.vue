<script setup>
import Modal from '@/Components/Modal.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

// Настройки материала на услуге (CLAUDE.md «Материалы на услугу») —
// выделенная модалка по смыслу как WorkOrderItemPayoutModal.vue, но
// обёрнута в <Modal> (нативный <dialog>), а не голым div — правило §7
// CLAUDE.md про top layer: она может открыться поверх формы заказа, если
// та тоже открыта через <Modal>.
const props = defineProps({
    show: Boolean,
    item: { type: Object, default: null },
    workOrder: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const page = usePage();
const warehouseEnabled = computed(() => page.props.warehouse_enabled !== false);
// Материал (linked_item_id задан) vs обычный товар на продажу — у обычного товара
// осмыслен только один тумблер (allow_negative_stock), остальные завязаны на
// привязку к услуге и сервер их для такой позиции не примет (CLAUDE.md
// «Отдельные тумблеры разрешения списания в минус для материалов и для товаров
// на продажу»).
const isMaterial = computed(() => Boolean(props.item?.linked_item_id));

const form = useForm({
    stock_deduction_disabled: false,
    allow_negative_stock: false,
    affects_payroll: true,
    payroll_uses_cost_only: false,
    is_billable: false,
});

watch(() => props.show, (isOpen) => {
    if (isOpen && props.item) {
        form.stock_deduction_disabled = Boolean(props.item.stock_deduction_disabled);
        form.allow_negative_stock = Boolean(props.item.allow_negative_stock);
        form.affects_payroll = Boolean(props.item.affects_payroll);
        form.payroll_uses_cost_only = Boolean(props.item.payroll_uses_cost_only);
        form.is_billable = Boolean(props.item.is_billable);
        form.clearErrors();
    }
});

const formatMoney = (cents) => new Intl.NumberFormat('ru-RU', {
    style: 'currency', currency: 'RUB', minimumFractionDigits: 2,
}).format((cents || 0) / 100);

// Наценка справочно — только если реально есть себестоимость и цена её превышает.
const hasMarkup = computed(() => props.item?.cost_price !== null && props.item?.cost_price !== undefined && props.item.price > props.item.cost_price);
const markupAmount = computed(() => hasMarkup.value ? props.item.price - props.item.cost_price : 0);
const markupPercent = computed(() => hasMarkup.value && props.item.cost_price > 0 ? (markupAmount.value / props.item.cost_price * 100) : 0);

const close = () => emit('close');

const submit = () => {
    form.put(route('operations.work-orders.items.material-settings', [props.workOrder.id, props.item.id]), {
        preserveScroll: true,
        onSuccess: () => close(),
    });
};
</script>

<template>
    <Modal :show="show && !!item" @close="close" max-width="2xl">
        <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
            <div>
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ isMaterial ? 'Настройки материала' : 'Настройки списания' }}</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ item?.name }}</p>
            </div>
            <button @click="close" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
        </div>

        <form v-if="item" @submit.prevent="submit" class="flex flex-col">
            <div class="p-6 space-y-5 max-h-[70vh] overflow-y-auto custom-scrollbar">
                <!-- Себестоимость / наценка — справочно, только у материала (у обычного товара cost_price не снэпшотится) -->
                <div v-if="isMaterial && hasMarkup" class="bg-info/10 border border-info/20 rounded-md p-3 flex flex-wrap items-center gap-x-6 gap-y-1 text-xs text-gray-600 dark:text-gray-400">
                    <span><span class="text-gray-400">Себестоимость:</span> <strong class="text-gray-800 dark:text-gray-200">{{ formatMoney(item.cost_price) }}</strong></span>
                    <span><span class="text-gray-400">Наценка:</span> <strong class="text-success">{{ formatMoney(markupAmount) }}</strong></span>
                    <span><span class="text-gray-400">Наценка, %:</span> <strong class="text-success">{{ markupPercent.toFixed(1) }}%</strong></span>
                </div>
                <div v-else-if="isMaterial && item.cost_price === null" class="bg-warning/10 border border-warning/20 rounded-md p-3 text-xs text-gray-600 dark:text-gray-400 flex gap-2">
                    <i class="ri-error-warning-line text-warning shrink-0 mt-0.5"></i>
                    <div>Себестоимость не определена (склад выключен или на складе нет остатка по этому материалу). Пока она не указана, вычет из ЗП по себестоимости недоступен.</div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div v-if="warehouseEnabled && isMaterial" class="flex items-start p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                        <div @click="form.stock_deduction_disabled = !form.stock_deduction_disabled" :class="[form.stock_deduction_disabled ? 'bg-success' : 'bg-gray-300 dark:bg-gray-600', 'flex items-center h-6 w-11 rounded-full cursor-pointer transition-all duration-200 relative shrink-0 mt-0.5']">
                            <div :class="[form.stock_deduction_disabled ? 'translate-x-6' : 'translate-x-1', 'h-4 w-4 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <div class="ml-3">
                            <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.stock_deduction_disabled = !form.stock_deduction_disabled">
                                Отключить списание со склада
                            </label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Исключает материал из складской накладной — расход не спишется с остатков.</p>
                        </div>
                    </div>

                    <div v-if="warehouseEnabled" class="flex items-start p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                        <div @click="form.allow_negative_stock = !form.allow_negative_stock" :class="[form.allow_negative_stock ? 'bg-success' : 'bg-gray-300 dark:bg-gray-600', 'flex items-center h-6 w-11 rounded-full cursor-pointer transition-all duration-200 relative shrink-0 mt-0.5']">
                            <div :class="[form.allow_negative_stock ? 'translate-x-6' : 'translate-x-1', 'h-4 w-4 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <div class="ml-3">
                            <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.allow_negative_stock = !form.allow_negative_stock">
                                Разрешить списание при нехватке
                            </label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Материал попадёт в накладную даже при недостатке на складе — остаток уйдёт в минус.</p>
                        </div>
                    </div>

                    <div v-if="isMaterial" class="flex items-start p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                        <div @click="form.affects_payroll = !form.affects_payroll" :class="[form.affects_payroll ? 'bg-success' : 'bg-gray-300 dark:bg-gray-600', 'flex items-center h-6 w-11 rounded-full cursor-pointer transition-all duration-200 relative shrink-0 mt-0.5']">
                            <div :class="[form.affects_payroll ? 'translate-x-6' : 'translate-x-1', 'h-4 w-4 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <div class="ml-3">
                            <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.affects_payroll = !form.affects_payroll">
                                Стоимость уменьшает базу ЗП
                            </label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Материал уменьшит базу расчёта ЗП исполнителя этой услуги на свою стоимость.</p>
                        </div>
                    </div>

                    <div v-if="isMaterial && hasMarkup" class="flex items-start p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                        <div @click="form.payroll_uses_cost_only = !form.payroll_uses_cost_only" :class="[form.payroll_uses_cost_only ? 'bg-success' : 'bg-gray-300 dark:bg-gray-600', 'flex items-center h-6 w-11 rounded-full cursor-pointer transition-all duration-200 relative shrink-0 mt-0.5']">
                            <div :class="[form.payroll_uses_cost_only ? 'translate-x-6' : 'translate-x-1', 'h-4 w-4 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <div class="ml-3">
                            <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.payroll_uses_cost_only = !form.payroll_uses_cost_only">
                                Уменьшать базу ЗП только на себестоимость
                            </label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Несмотря на цену материала, база для расчёта ЗП уменьшится на себестоимость, а не на цену.</p>
                        </div>
                    </div>

                    <div v-if="isMaterial" class="flex items-start p-4 border border-gray-200 dark:border-gray-700 rounded-md bg-gray-50/50 dark:bg-gray-800/30">
                        <div @click="form.is_billable = !form.is_billable" :class="[form.is_billable ? 'bg-success' : 'bg-gray-300 dark:bg-gray-600', 'flex items-center h-6 w-11 rounded-full cursor-pointer transition-all duration-200 relative shrink-0 mt-0.5']">
                            <div :class="[form.is_billable ? 'translate-x-6' : 'translate-x-1', 'h-4 w-4 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                        </div>
                        <div class="ml-3">
                            <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_billable = !form.is_billable">
                                Включать материал в стоимость заказа
                            </label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Включено — материал входит в сумму к оплате и печатается в документе. Выключено — клиент его не видит.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                <button type="button" @click="close" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
            </div>
        </form>
    </Modal>
</template>
