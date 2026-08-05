<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import OperationsNav from '@/Components/OperationsNav.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import BulkActions from '@/Components/BulkActions.vue';
import ColumnSettingsModal from '@/Components/ColumnSettingsModal.vue';
import StatusBadgeSelect from '@/Components/StatusBadgeSelect.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { useDebounceFn } from '@vueuse/core';

const props = defineProps({
    appointments: Object,
    filters: Object,
    branches: Array,
    clients: Array,
    vehicles: Array,
    employees: Array,
    services: Array,
    products: Array,
    availableColumns: { type: Array, default: () => [] },
    listView: { type: Object, default: () => ({ visible_columns: [] }) },
    appointmentStatuses: { type: Array, default: () => [] },
});

const isModalOpen = ref(false);
const editingAppointment = ref(null);
const isColumnsModalOpen = ref(false);

const activeColumns = computed(() => {
    return props.listView.visible_columns
        .map(key => props.availableColumns.find(c => c.key === key))
        .filter(Boolean);
});

// Статусы, которые пользователь может выбрать вручную. "converted" выставляется
// только конвертацией записи в заказ-наряд (Фаза 9.4), поэтому скрыт из ручного выбора.
const selectableStatuses = computed(() => props.appointmentStatuses.filter(s => s.value !== 'converted'));

const getLocalizedLabel = (label) => {
    if (!label) return '';
    if (typeof label === 'string') {
        try {
            label = JSON.parse(label);
        } catch (e) {
            return label;
        }
    }
    return label['ru'] || label['en'] || Object.values(label)[0] || '';
};

const form = useForm({
    branch_id: '',
    client_id: '',
    vehicle_id: '',
    employee_id: '',
    start_at: '',
    end_at: '',
    status: 'scheduled',
    comment: '',
    items: [],
});

const filteredVehicles = computed(() => {
    if (!form.client_id) return [];
    return props.vehicles.filter(v => v.client_id === form.client_id);
});

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const fetchFiltered = useDebounceFn(() => {
    router.get(route('operations.appointments.index'), {
        search: search.value,
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
// ------------------------------------

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
const selectedIds = ref([]);

const selectAll = computed({
    get: () => props.appointments.data.length > 0 && selectedIds.value.length === props.appointments.data.length,
    set: (value) => {
        selectedIds.value = value ? props.appointments.data.map(a => a.id) : [];
    }
});

const bulkDelete = () => {
    if (confirm(`Удалить выбранные записи (${selectedIds.value.length})?`)) {
        router.post(route('operations.appointments.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => { selectedIds.value = []; }
        });
    }
};
// ----------------------------------------

// --- ПОЗИЦИИ СМЕТЫ (AppointmentItem) ---
const newItem = ref({ itemable_type: 'service', itemable_id: '', name: '', quantity: 1, price: 0 });

const onNewItemSelect = () => {
    const list = newItem.value.itemable_type === 'service' ? props.services : props.products;
    const found = list.find(i => i.id === newItem.value.itemable_id);
    if (found) {
        newItem.value.name = getLocalizedLabel(found.name);
        newItem.value.price = found.price ? found.price / 100 : 0;
    }
};

const addItemRow = () => {
    if (!newItem.value.itemable_id || !newItem.value.name) return;
    form.items.push({ ...newItem.value });
    newItem.value = { itemable_type: 'service', itemable_id: '', name: '', quantity: 1, price: 0 };
};

const removeItemRow = (index) => {
    form.items.splice(index, 1);
};

const itemsTotal = computed(() => {
    return form.items.reduce((sum, i) => sum + (Number(i.quantity) * Number(i.price)), 0);
});
// ----------------------------------------

const openModal = (appointment = null) => {
    editingAppointment.value = appointment;
    if (appointment) {
        form.branch_id = appointment.branch_id;
        form.client_id = appointment.client_id;
        form.vehicle_id = appointment.vehicle_id || '';
        form.employee_id = appointment.employee_id || '';
        form.start_at = appointment.start_at_local;
        form.end_at = appointment.end_at_local;
        form.status = appointment.status;
        form.comment = appointment.comment || '';
        form.items = (appointment.items || []).map(i => ({
            itemable_type: i.itemable_type.includes('Service') ? 'service' : 'product',
            itemable_id: i.itemable_id,
            name: i.name,
            quantity: Number(i.quantity),
            price: i.price / 100,
        }));
    } else {
        form.reset();
        form.status = 'scheduled';
        form.items = [];
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingAppointment.value = null;
    form.reset();
    form.clearErrors();
    newItem.value = { itemable_type: 'service', itemable_id: '', name: '', quantity: 1, price: 0 };
};

const submit = () => {
    if (editingAppointment.value) {
        form.put(route('operations.appointments.update', editingAppointment.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('operations.appointments.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

const deleteAppointment = (appointment) => {
    if (confirm(`Удалить запись клиента "${appointment.client?.name}"?`)) {
        router.delete(route('operations.appointments.destroy', appointment.id));
    }
};

const changeStatus = (appointment, status) => {
    router.patch(route('operations.appointments.status.update', appointment.id), { status });
};
</script>

<template>
    <Head title="Записи" />

    <AuthenticatedLayout>
        <template #header>
            Операции
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">

            <OperationsNav />

            <PageHelper title="Записи">
                <p>Здесь фиксируются брони клиентов на конкретное время — намерение приехать, а не факт оказания услуг.</p>
                <p>Склад и финансы не затрагиваются, пока запись не конвертирована в заказ-наряд по факту приезда клиента.</p>
            </PageHelper>

            <BulkActions
                v-if="selectedIds.length > 0"
                :selectedCount="selectedIds.length"
                noun="записей"
                @delete="bulkDelete"
            />

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="false"
                    @open-columns="isColumnsModalOpen = true"
                    placeholder="Поиск по комментарию..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Новая запись
                        </button>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-4 w-10 border-b border-gray-200 dark:border-gray-700 text-center">
                                    <input type="checkbox" v-model="selectAll" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </th>
                                <th v-for="col in activeColumns" :key="col.key" class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">{{ col.label }}</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="appointment in appointments.data" :key="appointment.id" class="odd:bg-gray-50/30 dark:odd:bg-gray-800/10 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-4 border-b border-gray-100 dark:border-gray-700/50 text-center">
                                    <input type="checkbox" :value="appointment.id" v-model="selectedIds" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                </td>
                                <td v-for="col in activeColumns" :key="col.key" class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">
                                    <template v-if="col.key === 'start_at'">
                                        <span class="font-medium">{{ appointment.start_at_display }}</span>
                                        <span class="text-gray-400"> — {{ appointment.end_at_display?.split(' ')[1] }}</span>
                                    </template>
                                    <template v-else-if="col.key === 'client'">
                                        {{ appointment.client?.name || '—' }}
                                        <div v-if="appointment.client?.phone" class="text-xs text-gray-400">{{ appointment.client.phone }}</div>
                                    </template>
                                    <template v-else-if="col.key === 'vehicle'">
                                        <span v-if="appointment.vehicle">{{ appointment.vehicle.make?.name }} {{ appointment.vehicle.vehicle_model?.name }} <span v-if="appointment.vehicle.plate_number" class="text-gray-400">[{{ appointment.vehicle.plate_number }}]</span></span>
                                        <span v-else class="text-gray-400">—</span>
                                    </template>
                                    <template v-else-if="col.key === 'branch'">
                                        {{ appointment.branch?.name || '—' }}
                                    </template>
                                    <template v-else-if="col.key === 'employee'">
                                        <span v-if="appointment.employee">{{ appointment.employee.first_name }} {{ appointment.employee.last_name }}</span>
                                        <span v-else class="text-gray-400">Не назначен</span>
                                    </template>
                                    <template v-else-if="col.key === 'status'">
                                        <StatusBadgeSelect
                                            :model-value="appointment.status"
                                            :options="appointment.status === 'converted' ? appointmentStatuses : selectableStatuses"
                                            :disabled="appointment.status === 'converted'"
                                            @update:model-value="v => changeStatus(appointment, v)"
                                        />
                                    </template>
                                    <template v-else-if="col.key === 'comment'">
                                        <span class="text-gray-500 dark:text-gray-400">{{ appointment.comment || '—' }}</span>
                                    </template>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-800 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
                                    <button
                                        @click="openModal(appointment)"
                                        :disabled="appointment.status === 'converted'"
                                        :title="appointment.status === 'converted' ? 'Запись оформлена в заказ-наряд — недоступна для правки' : 'Редактировать'"
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white disabled:opacity-40 disabled:hover:bg-primary/10 disabled:hover:text-primary disabled:cursor-not-allowed"
                                    >
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button
                                        @click="deleteAppointment(appointment)"
                                        :disabled="appointment.status === 'converted'"
                                        :title="appointment.status === 'converted' ? 'Запись оформлена в заказ-наряд — недоступна для удаления' : 'Удалить'"
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white disabled:opacity-40 disabled:hover:bg-danger/10 disabled:hover:text-danger disabled:cursor-not-allowed"
                                    >
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="appointments.data.length === 0">
                                <td :colspan="activeColumns.length + 2" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                    Записи не найдены.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <Pagination :meta="appointments" />
            </div>
        </div>

        <!-- Модальное окно создания/редактирования -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-2xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingAppointment ? 'Редактирование записи' : 'Новая запись' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Филиал <span class="text-danger">*</span></label>
                                <select v-model="form.branch_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите филиал...</option>
                                    <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                                </select>
                                <p v-if="form.errors.branch_id" class="mt-1 text-xs text-danger">{{ form.errors.branch_id }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Мастер</label>
                                <select v-model="form.employee_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" class="bg-white dark:bg-gray-800">Не назначен</option>
                                    <option v-for="e in employees" :key="e.id" :value="e.id" class="bg-white dark:bg-gray-800">{{ e.first_name }} {{ e.last_name }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Клиент <span class="text-danger">*</span></label>
                                <select v-model="form.client_id" @change="form.vehicle_id = ''" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите клиента...</option>
                                    <option v-for="client in clients" :key="client.id" :value="client.id" class="bg-white dark:bg-gray-800">{{ client.name }} {{ client.phone ? `(${client.phone})` : '' }}</option>
                                </select>
                                <p v-if="form.errors.client_id" class="mt-1 text-xs text-danger">{{ form.errors.client_id }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Автомобиль</label>
                                <select v-model="form.vehicle_id" :disabled="!form.client_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 disabled:bg-gray-100 dark:disabled:bg-gray-800">
                                    <option value="" class="bg-white dark:bg-gray-800">Без автомобиля</option>
                                    <option v-for="vehicle in filteredVehicles" :key="vehicle.id" :value="vehicle.id" class="bg-white dark:bg-gray-800">{{ vehicle.make ? vehicle.make.name : '' }} {{ vehicle.vehicle_model ? vehicle.vehicle_model.name : '' }} {{ vehicle.plate_number ? `[${vehicle.plate_number}]` : '' }}</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Начало <span class="text-danger">*</span></label>
                                <input v-model="form.start_at" type="datetime-local" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <p v-if="form.errors.start_at" class="mt-1 text-xs text-danger">{{ form.errors.start_at }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Окончание <span class="text-danger">*</span></label>
                                <input v-model="form.end_at" type="datetime-local" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <p v-if="form.errors.end_at" class="mt-1 text-xs text-danger">{{ form.errors.end_at }}</p>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус <span class="text-danger">*</span></label>
                            <select v-model="form.status" required :disabled="editingAppointment?.status === 'converted'" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 disabled:bg-gray-100 dark:disabled:bg-gray-800">
                                <option v-for="s in selectableStatuses" :key="s.value" :value="s.value" class="bg-white dark:bg-gray-800">{{ s.label || s.value }}</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Комментарий</label>
                            <textarea v-model="form.comment" rows="2" placeholder="Например: клиент просил перезвонить за час" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"></textarea>
                        </div>

                        <!-- Ориентировочная смета: не резервирует остатки, не создает финансовых операций -->
                        <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Ориентировочная смета</p>

                            <div v-if="form.items.length > 0" class="space-y-1.5 mb-3">
                                <div v-for="(item, index) in form.items" :key="index" class="flex items-center justify-between gap-2 py-1.5 px-3 rounded bg-gray-50/50 dark:bg-gray-800/30 text-sm">
                                    <span class="flex-1 truncate">{{ item.name }}</span>
                                    <span class="text-gray-400">{{ item.quantity }} × {{ item.price }} ₽</span>
                                    <button type="button" @click="removeItemRow(index)" class="text-danger hover:opacity-70">
                                        <i class="ri-close-line"></i>
                                    </button>
                                </div>
                                <div class="text-right text-sm font-semibold text-gray-700 dark:text-gray-300 pr-3">Итого: {{ itemsTotal.toFixed(2) }} ₽</div>
                            </div>

                            <div class="flex flex-wrap items-end gap-2">
                                <select v-model="newItem.itemable_type" class="rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-2 text-xs text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="service" class="bg-white dark:bg-gray-800">Услуга</option>
                                    <option value="product" class="bg-white dark:bg-gray-800">Товар</option>
                                </select>
                                <select v-model="newItem.itemable_id" @change="onNewItemSelect" class="flex-1 min-w-[140px] rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-2 text-xs text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите...</option>
                                    <template v-if="newItem.itemable_type === 'service'">
                                        <option v-for="s in services" :key="s.id" :value="s.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(s.name) }}</option>
                                    </template>
                                    <template v-else>
                                        <option v-for="p in products" :key="p.id" :value="p.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(p.name) }}</option>
                                    </template>
                                </select>
                                <input v-model.number="newItem.quantity" type="number" min="0.001" step="0.001" placeholder="Кол-во" class="w-20 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-2 text-xs text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <input v-model.number="newItem.price" type="number" min="0" step="0.01" placeholder="Цена" class="w-24 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-2 text-xs text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <button type="button" @click="addItemRow" class="inline-flex items-center justify-center rounded px-3 py-2 text-xs font-medium bg-primary/10 text-primary hover:bg-primary hover:text-white transition-all">
                                    <i class="ri-add-line"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

        <ColumnSettingsModal
            :show="isColumnsModalOpen"
            entity-type="appointment"
            :available-columns="availableColumns"
            :visible-columns="listView.visible_columns"
            @close="isColumnsModalOpen = false"
            @saved="isColumnsModalOpen = false"
        />
    </AuthenticatedLayout>
</template>
