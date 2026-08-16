<script setup>
import Modal from '@/Components/Modal.vue';
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

// Возврат заказа с "Выдан" на доработку (CLAUDE.md «Закрытие заказ-наряда после
// выдачи») — обязательный комментарий (единственное место в проекте, где перед
// действием запрашивается текст, а не просто confirm()). Последствия
// (реверс склада/невыплаченной ЗП) объясняются ЗАРАНЕЕ, до нажатия кнопки —
// правило CLAUDE.md §6 «Объяснение блокировок в UI» распространено и на
// само действие, а не только на задизейбленное поле.
const props = defineProps({
    show: Boolean,
    workOrder: { type: Object, default: null },
    targetStatus: { type: String, default: '' },
    statuses: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const form = useForm({
    status: '',
    reopen_comment: '',
});

watch(() => props.show, (isOpen) => {
    if (isOpen) {
        form.status = props.targetStatus;
        form.reopen_comment = '';
        form.clearErrors();
    }
});

const targetStatusLabel = computed(() => props.statuses.find(s => s.value === props.targetStatus)?.label || props.targetStatus);

const close = () => emit('close');

const submit = () => {
    form.patch(route('operations.work-orders.status.update', props.workOrder.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => close(),
    });
};
</script>

<template>
    <Modal :show="show && !!workOrder" @close="close" max-width="md">
        <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
            <div>
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Вернуть заказ на доработку</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Новый статус — «{{ targetStatusLabel }}»</p>
            </div>
            <button @click="close" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
        </div>

        <form @submit.prevent="submit" class="flex flex-col">
            <div class="p-6 space-y-4">
                <div class="bg-warning/10 border border-warning/20 rounded-md p-3 text-xs text-gray-600 dark:text-gray-400 flex gap-2">
                    <i class="ri-error-warning-line text-warning shrink-0 mt-0.5"></i>
                    <div>Списание со склада по этому заказу будет отменено (остатки восстановятся), а ещё не выплаченная зарплата по нему — отменена. При повторном завершении заказ спишет склад и начислит ЗП заново, уже по актуальному составу.</div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Причина возврата на доработку <span class="text-danger">*</span></label>
                    <textarea v-model="form.reopen_comment" rows="3" required placeholder="Например: клиент обнаружил недочёт, нужно переделать полировку крыла" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 placeholder:text-gray-400"></textarea>
                    <p v-if="form.errors.reopen_comment" class="text-xs text-danger mt-1">{{ form.errors.reopen_comment }}</p>
                    <p v-if="form.errors.status" class="text-xs text-danger mt-1">{{ form.errors.status }}</p>
                </div>
            </div>
            <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                <button type="button" @click="close" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                <button type="submit" :disabled="form.processing || !form.reopen_comment.trim()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-warning text-white hover:opacity-90 disabled:opacity-50">Вернуть на доработку</button>
            </div>
        </form>
    </Modal>
</template>
