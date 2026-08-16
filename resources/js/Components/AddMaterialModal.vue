<script setup>
import Modal from '@/Components/Modal.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

// Добавление материала к услуге (CLAUDE.md «Материалы на услугу») —
// минимальная форма: продукт + количество (+ себестоимость вручную, если
// склад выключен). Цена для внутреннего учёта/будущего выставления клиенту
// правится потом прямо в карточке позиции в корзине (то же поле "Цена за
// ед.", что и у любой другой позиции) — здесь по умолчанию 0, т.к. материал
// скрыт от клиента и цена ему сразу не нужна.
const props = defineProps({
    show: Boolean,
    serviceItem: { type: Object, default: null },
    workOrder: { type: Object, default: null },
    productOptions: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const page = usePage();
const warehouseEnabled = computed(() => page.props.warehouse_enabled !== false);

const form = useForm({
    itemable_type: 'product',
    itemable_id: '',
    linked_item_id: null,
    name: '',
    quantity: 1,
    price: 0,
    cost_price: '',
});

watch(() => props.show, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.linked_item_id = props.serviceItem?.id ?? null;
        form.clearErrors();
    }
});

watch(() => form.itemable_id, (id) => {
    const product = props.productOptions.find(p => p.value === id);
    form.name = product?.label || '';
});

const close = () => emit('close');

const submit = () => {
    form.post(route('operations.work-orders.items.store', props.workOrder.id), {
        preserveScroll: true,
        onSuccess: () => close(),
    });
};
</script>

<template>
    <Modal :show="show && !!serviceItem" @close="close" max-width="md">
        <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
            <div>
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Добавить материал</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">К услуге «{{ serviceItem?.name }}»</p>
            </div>
            <button @click="close" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
        </div>

        <form @submit.prevent="submit" class="flex flex-col">
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Материал <span class="text-danger">*</span></label>
                    <SearchableSelect v-model="form.itemable_id" :options="productOptions" placeholder="Выберите товар из каталога" />
                    <p v-if="form.errors.itemable_id" class="text-xs text-danger mt-1">{{ form.errors.itemable_id }}</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Количество <span class="text-danger">*</span></label>
                    <input v-model.number="form.quantity" type="number" step="any" min="0.001" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                    <p v-if="form.errors.quantity" class="text-xs text-danger mt-1">{{ form.errors.quantity }}</p>
                </div>

                <div v-if="!warehouseEnabled">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Себестоимость за единицу, ₽</label>
                    <input v-model.number="form.cost_price" type="number" step="0.01" min="0" placeholder="0.00" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                    <p class="text-[11px] text-gray-400 mt-1">Складской учёт выключен — себестоимость для базы ЗП вводится вручную. Можно оставить пустым и заполнить позже.</p>
                </div>
                <p v-else class="text-[11px] text-gray-400">Себестоимость подставится автоматически с текущего остатка на складе.</p>

                <p class="text-[11px] text-gray-400">Материал по умолчанию скрыт от клиента — не входит в сумму к оплате и в печатную форму. Это и остальные настройки можно изменить в настройках материала после добавления.</p>
            </div>
            <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                <button type="button" @click="close" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                <button type="submit" :disabled="form.processing || !form.itemable_id" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить</button>
            </div>
        </form>
    </Modal>
</template>
