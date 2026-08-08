<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

const props = defineProps({
    show: Boolean,
    item: { type: Object, default: null },
    workOrder: { type: Object, default: null },
    employees: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const form = useForm({
    admin_override: 'inherit',
    admin_employee_id: '',
    assignments: [],
});

const adminEligibleEmployees = computed(() => props.employees.filter(e => e.position?.payroll_role === 'admin'));

const inheritedAdminName = computed(() => {
    const admin = props.workOrder?.default_admin_employee;
    return admin ? `${admin.last_name} ${admin.first_name}` : 'не назначен на заказе';
});

watch(() => props.show, (isOpen) => {
    if (isOpen && props.item) {
        form.admin_override = props.item.admin_override || 'inherit';
        form.admin_employee_id = props.item.admin_employee_id || '';
        form.assignments = (props.item.employees || []).map(e => ({
            employee_id: e.id,
            name: `${e.last_name} ${e.first_name}`,
            type: e.type,
            share_percent: e.pivot?.share_percent !== null && e.pivot?.share_percent !== undefined ? Number(e.pivot.share_percent) : null,
            manual_amount_override: e.pivot?.manual_amount_override !== null && e.pivot?.manual_amount_override !== undefined ? e.pivot.manual_amount_override / 100 : null,
            manual_percent_override: e.pivot?.manual_percent_override !== null && e.pivot?.manual_percent_override !== undefined ? Number(e.pivot.manual_percent_override) : null,
        }));
        form.clearErrors();
    }
});

const equalShare = computed(() => {
    const count = form.assignments.filter(a => a.type !== 'outsource').length;
    return count > 0 ? Math.round((100 / count) * 100) / 100 : 0;
});

const displayShare = (assignment) => assignment.share_percent === null || assignment.share_percent === undefined
    ? equalShare.value
    : assignment.share_percent;

const totalShare = computed(() => {
    return form.assignments
        .filter(a => a.type !== 'outsource')
        .reduce((sum, a) => sum + (Number(displayShare(a)) || 0), 0);
});

const shareIsValid = computed(() => totalShare.value <= 100.001);

// "Сумма к оплате" и "% от услуги" взаимоисключающие — как в исходной системе:
// ввод одного поля сбрасывает другое.
const onAmountInput = (assignment) => {
    if (assignment.manual_amount_override !== null && assignment.manual_amount_override !== '') {
        assignment.manual_percent_override = null;
    }
};

const onPercentInput = (assignment) => {
    if (assignment.manual_percent_override !== null && assignment.manual_percent_override !== '') {
        assignment.manual_amount_override = null;
    }
};

const resetAssignment = (assignment) => {
    assignment.share_percent = null;
    assignment.manual_amount_override = null;
    assignment.manual_percent_override = null;
};

const close = () => emit('close');

const submit = () => {
    if (!shareIsValid.value) return;

    form.put(route('operations.work-orders.items.payout', [props.workOrder.id, props.item.id]), {
        preserveScroll: true,
        onSuccess: () => close(),
    });
};
</script>

<template>
    <div v-if="show && item" class="fixed inset-0 z-[210] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
        <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl my-8 mx-auto flex flex-col">
            <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                <div>
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Настройка выплат</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ item.name }}</p>
                </div>
                <button @click="close" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
            </div>

            <form @submit.prevent="submit" class="flex flex-col">
                <div class="p-6 space-y-5 max-h-[70vh] overflow-y-auto custom-scrollbar">
                    <div class="bg-info/10 border border-info/20 rounded-md p-3 text-xs text-gray-600 dark:text-gray-400 flex gap-2">
                        <i class="ri-information-fill text-info shrink-0 mt-0.5"></i>
                        <div>
                            Значения ниже переопределяют ставку только для этой позиции. Постоянные ставки настраиваются в
                            <a :href="route('settings.payroll.index')" target="_blank" class="text-primary hover:underline">Настройки → Зарплата</a>
                            или в карточке сотрудника.
                        </div>
                    </div>

                    <!-- Администратор позиции -->
                    <div>
                        <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 mb-2">Администратор этой услуги</label>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <label :class="[form.admin_override === 'inherit' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex flex-col cursor-pointer rounded-md border p-2.5 text-xs']">
                                <input type="radio" v-model="form.admin_override" value="inherit" class="sr-only" />
                                <span class="font-medium">Как на заказе</span>
                                <span class="text-gray-400 mt-0.5">{{ inheritedAdminName }}</span>
                            </label>
                            <label :class="[form.admin_override === 'custom' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex flex-col cursor-pointer rounded-md border p-2.5 text-xs']">
                                <input type="radio" v-model="form.admin_override" value="custom" class="sr-only" />
                                <span class="font-medium">Другой администратор</span>
                            </label>
                            <label :class="[form.admin_override === 'none' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-gray-200 dark:border-gray-700', 'relative flex flex-col cursor-pointer rounded-md border p-2.5 text-xs']">
                                <input type="radio" v-model="form.admin_override" value="none" class="sr-only" />
                                <span class="font-medium">Без администратора</span>
                            </label>
                        </div>
                        <select v-if="form.admin_override === 'custom'" v-model="form.admin_employee_id" class="mt-2 block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                            <option value="" disabled>Выберите администратора</option>
                            <option v-for="e in adminEligibleEmployees" :key="e.id" :value="e.id">{{ e.last_name }} {{ e.first_name }}</option>
                        </select>
                    </div>

                    <!-- Бригада исполнителей -->
                    <div v-if="form.assignments.length > 0" class="pt-2 border-t border-gray-200 dark:border-gray-700">
                        <label class="block text-sm font-bold text-gray-800 dark:text-gray-200 mb-2">Исполнители</label>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-md overflow-hidden">
                            <table class="min-w-full text-left">
                                <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                    <tr>
                                        <th class="py-2 px-3 text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase">Исполнитель</th>
                                        <th class="py-2 px-3 text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase w-24">Объём, %</th>
                                        <th class="py-2 px-3 text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase w-28">Сумма, ₽</th>
                                        <th class="py-2 px-3 text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase w-24">% от услуги</th>
                                        <th class="py-2 px-3 w-8"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                    <tr v-for="assignment in form.assignments" :key="assignment.employee_id" :class="assignment.type === 'outsource' ? 'bg-purple-50/40 dark:bg-purple-900/10' : 'odd:bg-gray-100/80 dark:odd:bg-gray-800/40'">
                                        <td class="py-2 px-3 text-sm text-gray-800 dark:text-gray-200">
                                            {{ assignment.name }}
                                            <span v-if="assignment.type === 'outsource'" class="block text-[10px] font-bold text-purple-600 dark:text-purple-400 uppercase">Аутсорс</span>
                                            <span v-else-if="assignment.type === 'self_employed'" class="block text-[10px] font-bold text-amber-600 dark:text-amber-400 uppercase">Самозанятый</span>
                                        </td>
                                        <td class="py-2 px-3">
                                            <input
                                                v-if="assignment.type !== 'outsource'"
                                                v-model.number="assignment.share_percent"
                                                type="number" min="0" max="100" step="0.01"
                                                :placeholder="String(equalShare)"
                                                class="w-full rounded border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-2 text-sm text-center text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                                            />
                                            <span v-else class="text-xs text-gray-400 text-center block">—</span>
                                        </td>
                                        <td class="py-2 px-3">
                                            <input
                                                v-model.number="assignment.manual_amount_override"
                                                @input="onAmountInput(assignment)"
                                                type="number" min="0" step="0.01"
                                                placeholder="По ставке"
                                                class="w-full rounded border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-2 text-sm text-center text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                                            />
                                        </td>
                                        <td class="py-2 px-3">
                                            <input
                                                v-if="assignment.type !== 'outsource'"
                                                v-model.number="assignment.manual_percent_override"
                                                @input="onPercentInput(assignment)"
                                                type="number" min="0" max="100" step="0.01"
                                                placeholder="По ставке"
                                                class="w-full rounded border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-2 text-sm text-center text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                                            />
                                            <span v-else class="text-xs text-gray-400 text-center block">—</span>
                                        </td>
                                        <td class="py-2 px-3 text-right">
                                            <button type="button" @click="resetAssignment(assignment)" title="Сбросить" class="text-gray-400 hover:text-danger transition-colors"><i class="ri-refresh-line"></i></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="!shareIsValid" class="text-xs text-danger mt-2 flex items-center gap-1">
                            <i class="ri-error-warning-line"></i> Суммарный объём работ ({{ totalShare.toFixed(2) }}%) превышает 100%.
                        </p>
                        <p v-else class="text-xs text-success mt-2 flex items-center gap-1">
                            <i class="ri-check-line"></i> Распределение объёма корректно ({{ totalShare.toFixed(2) }}%). Пустые поля объёма считаются поровну.
                        </p>
                        <p v-if="form.assignments.some(a => a.type === 'outsource')" class="text-xs text-gray-400 mt-1">Для подрядчиков (аутсорс) укажите фиксированную сумму — процент и доля к ним не применяются.</p>
                    </div>
                    <p v-else class="text-sm text-gray-400 text-center py-4">На эту позицию пока не назначены исполнители.</p>
                </div>

                <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                    <button type="button" @click="close" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                    <button type="submit" :disabled="form.processing || !shareIsValid" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</template>
