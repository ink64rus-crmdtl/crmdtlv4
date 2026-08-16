<script setup>
import Modal from '@/Components/Modal.vue';
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

// Подтверждение автодобавления материалов по правилу услуги (CLAUDE.md
// «Материалы на услугу», Setting service_material_auto_add_mode='confirm').
// Нехватку остатка на складе проверяет и решает СЕРВЕР при сабмите (см.
// WorkOrderController::autoAddMaterials()) — здесь только выбор, что
// добавлять, без отдельного запроса за остатками.
const props = defineProps({
    show: Boolean,
    serviceItem: { type: Object, default: null },
    workOrder: { type: Object, default: null },
    defaultMaterials: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const selections = ref([]);

watch(() => props.show, (isOpen) => {
    if (isOpen) {
        selections.value = props.defaultMaterials.map(m => ({
            product_id: m.product_id,
            name: m.product?.name || '',
            quantity: Number(m.quantity),
            checked: true,
        }));
    }
});

const form = useForm({});

const close = () => emit('close');

const submit = () => {
    const materials = selections.value.filter(s => s.checked).map(s => ({ product_id: s.product_id, quantity: s.quantity }));

    if (materials.length === 0) {
        close();
        return;
    }

    form.transform(() => ({ materials })).post(
        route('operations.work-orders.items.materials.auto-add', [props.workOrder.id, props.serviceItem.id]),
        { preserveScroll: true, onFinish: () => close() }
    );
};
</script>

<template>
    <Modal :show="show && !!serviceItem" @close="close" max-width="md">
        <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
            <div>
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Добавить материалы?</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">По умолчанию для услуги «{{ serviceItem?.name }}»</p>
            </div>
            <button @click="close" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
        </div>

        <form @submit.prevent="submit" class="flex flex-col">
            <div class="p-6 space-y-2">
                <label v-for="(item, index) in selections" :key="item.product_id" class="flex items-center gap-3 p-2.5 rounded-md border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-primary/40 transition-colors">
                    <input type="checkbox" v-model="item.checked" class="rounded border-gray-300 dark:border-gray-600 text-primary focus:ring-primary" />
                    <span class="text-sm text-gray-800 dark:text-gray-200 flex-1">{{ item.name }}</span>
                    <input
                        v-model.number="selections[index].quantity"
                        type="number" step="any" min="0.001"
                        :disabled="!item.checked"
                        class="w-20 rounded border-gray-200 dark:border-gray-700 bg-transparent py-1 px-2 text-sm text-center text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 disabled:opacity-40"
                        @click.stop
                    />
                </label>
                <p class="text-[11px] text-gray-400 pt-1">Материалы скрыты от клиента и уменьшают базу ЗП по умолчанию. Нехватка на складе — позиция будет пропущена, запись появится в «Истории» заказа.</p>
            </div>
            <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                <button type="button" @click="close" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Не добавлять</button>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить выбранное</button>
            </div>
        </form>
    </Modal>
</template>
