<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import PageHelper from '@/Components/PageHelper.vue';
import OperationsNav from '@/Components/OperationsNav.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import BulkActions from '@/Components/BulkActions.vue';
import Modal from '@/Components/Modal.vue';
import StatusBadgeSelect from '@/Components/StatusBadgeSelect.vue';
import PointBadge from '@/Components/PointBadge.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import DataTable from '@/Components/DataTable.vue';
import WorkOrderReopenModal from '@/Components/WorkOrderReopenModal.vue';
import draggable from 'vuedraggable';
import { Head, useForm, usePage, Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, reactive, onMounted } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import { useServerSort } from '@/Composables/useServerSort.js';
import { buildTimeSlots, withCurrentValue } from '@/Composables/useWorkingHoursSlots.js';
import axios from 'axios';

const props = defineProps({
    workOrders: Object,
    filters: Object,
    branches: Array,
    clients: Array,
    vehicles: Array,
    makes: { type: Array, default: () => [] },
    models: { type: Array, default: () => [] },
    customFieldDefs: Array,
    availableColumns: Array,
    listView: Object,
    workOrderStatuses: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    bonusRubPerPoint: { type: Number, default: 1 },
    defaultWorkingHours: { type: Array, default: () => null },
});

const page = usePage();

const isModalOpen = ref(false);
const isOffcanvasOpen = ref(false);
const isColumnsModalOpen = ref(false);
const previewOrder = ref(null);
const editingOrder = ref(null);

const statusColorClasses = {
    info: 'bg-info/10 text-info',
    warning: 'bg-warning/10 text-warning',
    success: 'bg-success/10 text-success',
    danger: 'bg-danger/10 text-danger',
    primary: 'bg-primary/10 text-primary',
    gray: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
};

const statuses = computed(() => {
    const map = {};
    props.workOrderStatuses.forEach(s => {
        map[s.value] = { label: s.label || s.value, class: statusColorClasses[s.color] || statusColorClasses.gray };
    });
    return map;
});

const paymentStatuses = {
    'unpaid': { label: 'Не оплачен', class: 'bg-danger/10 text-danger' },
    'partial': { label: 'Частично', class: 'bg-warning/10 text-warning' },
    'paid': { label: 'Оплачен', class: 'bg-success/10 text-success' },
};

// Подпись "Локация" в панели быстрого просмотра рисуем, только если под ней
// реально что-то покажет PointBadge — иначе получится подпись без бейджа.
// Условие зеркалит его собственную логику видимости (branches/legal_entities).
const previewHasLocationBadge = computed(() => {
    const order = previewOrder.value;
    if (!order) return false;
    const showBranch = (page.props.branches || []).length > 1 && !!order.branch;
    const showLegalEntity = (page.props.legal_entities || []).length > 1 && !!order.legal_entity;
    return showBranch || showLegalEntity;
});

// --- СЕРВЕРНАЯ ФИЛЬТРАЦИЯ И ПОИСК ---
const search = ref(props.filters?.search || '');

const initialFilters = {
    status: props.filters?.filters?.status || '',
    payment_status: props.filters?.filters?.payment_status || '',
    branch_id: props.filters?.filters?.branch_id || '',
    client_id: props.filters?.filters?.client_id || '',
};
props.customFieldDefs.filter(f => f.is_filterable).forEach(def => {
    initialFilters['cf_' + def.key] = props.filters?.filters?.['cf_' + def.key] || '';
});

const filtersForm = reactive(initialFilters);
const isFiltersOpen = ref(false);

const fetchFiltered = useDebounceFn(() => {
    router.get(route('operations.work-orders.index'), {
        search: search.value,
        filters: filtersForm,
        sort_by: sort.value.map(s => s.key),
        sort_dir: sort.value.map(s => s.dir),
    }, { preserveState: true, preserveScroll: true });
}, 300);

watch(search, () => fetchFiltered());
watch(filtersForm, () => fetchFiltered(), { deep: true });

const resetFilters = () => {
    Object.keys(filtersForm).forEach(key => {
        filtersForm[key] = '';
    });
};

// --- МАССОВЫЕ ОПЕРАЦИИ (BULK ACTIONS) ---
// select-all/сброс выбора теперь считает сам DataTable (v-model="selectedIds").
const selectedIds = ref([]);

const bulkDelete = () => {
    if (confirm(`Удалить выбранные заказ-наряды (${selectedIds.value.length})? Это действие необратимо.`)) {
        router.post(route('operations.work-orders.bulk-destroy'), { ids: selectedIds.value }, {
            onSuccess: () => {
                selectedIds.value = [];
                if (previewOrder.value && !props.workOrders.data.find(o => o.id === previewOrder.value.id)) {
                    closePreview();
                }
            }
        });
    }
};

const bulkExport = async () => {
    try {
        const response = await axios.post(route('operations.work-orders.bulk-export'), { ids: selectedIds.value }, { responseType: 'blob' });
        const url = window.URL.createObjectURL(new Blob([response.data]));
        const link = document.createElement('a');
        link.href = url;
        link.setAttribute('download', `work_orders_export_${new Date().toISOString().slice(0,10)}.csv`);
        document.body.appendChild(link);
        link.click();
        link.remove();
    } catch (error) {
        console.error("Export failed", error);
        alert("Ошибка при экспорте данных");
    }
};

// --- ДИНАМИЧЕСКИЕ КОЛОНКИ ---
const activeColumns = computed(() => {
    const visibleKeys = props.listView?.visible_columns || [];
    return visibleKeys.map(key => props.availableColumns.find(c => c.key === key)).filter(Boolean);
});

// client/vehicle/branch (связи) и кастомные поля (EAV, считаются в PHP после
// пагинации) — не сортируются простым orderBy.
const SORTABLE_COLUMN_KEYS = ['id', 'created_at', 'status', 'payment_status', 'final_amount', 'mileage'];
const dataTableColumns = computed(() => activeColumns.value.map(col => ({
    ...col,
    sortable: SORTABLE_COLUMN_KEYS.includes(col.key),
})));

const { sort, onSort } = useServerSort('operations.work-orders.index', () => props.filters, () => ({ search: search.value, filters: filtersForm }));

const columnsForm = useForm({
    entity_type: 'work_order',
    visible_columns: [],
});

const openColumnsModal = () => {
    columnsForm.visible_columns = [...(props.listView?.visible_columns || [])];
    isColumnsModalOpen.value = true;
};

const closeColumnsModal = () => {
    isColumnsModalOpen.value = false;
};

const saveColumns = () => {
    columnsForm.post(route('list-views.store'), {
        onSuccess: () => closeColumnsModal(),
    });
};

const toggleColumn = (key) => {
    const index = columnsForm.visible_columns.indexOf(key);
    if (index > -1) {
        columnsForm.visible_columns.splice(index, 1);
    } else {
        columnsForm.visible_columns.push(key);
    }
};


const form = useForm({
    branch_id: '',
    legal_entity_id: '',
    client_id: '',
    vehicle_id: '',
    status: 'new',
    mileage: '',
    order_date: '',
    ready_at: '',
    custom_fields: {},
});

// Пока клиент не выбран — показываем ВСЕ автомобили (можно найти клиента по
// госномеру машины, а не только по имени, если так проще опознать посетителя),
// как только клиент выбран — сужаем список до его машин, как и раньше.
const filteredVehicles = computed(() => {
    if (!form.client_id) return props.vehicles;
    return props.vehicles.filter(v => v.client_id === form.client_id);
});

const clientOptions = computed(() => props.clients.map(c => ({ value: c.id, label: `${c.name}${c.phone ? ` (${c.phone})` : ''}` })));

const vehicleLabel = (v) => {
    const car = `${v.make ? v.make.name : ''} ${v.vehicle_model ? v.vehicle_model.name : ''}${v.plate_number ? ` [${v.plate_number}]` : ''}`.replace(/\s+/g, ' ').trim();
    if (form.client_id) return car;
    const owner = props.clients.find(c => c.id === v.client_id);
    return owner ? `${car} — ${owner.name}` : car;
};

const vehicleOptions = computed(() => filteredVehicles.value.map(v => ({ value: v.id, label: vehicleLabel(v) })));

// Выбор авто ДО выбора клиента (поиск "от машины") — подставляем владельца
// автоматически; выбор авто ПОСЛЕ смены клиента такого эффекта не даёт,
// т.к. список уже сужен только до машин этого клиента.
const onVehicleSelected = (vehicleId) => {
    if (!vehicleId) return;
    const vehicle = props.vehicles.find(v => v.id === vehicleId);
    if (vehicle && vehicle.client_id && form.client_id !== vehicle.client_id) {
        form.client_id = vehicle.client_id;
    }
};

// --- Быстрое добавление клиента/автомобиля прямо из формы заказа (тот же
// приём, что и quick-product в WorkOrders/Show.vue: обычный Inertia-POST на
// существующий crm.clients.store/crm.vehicles.store, без нового бэкенда).
// preserveState ОБЯЗАТЕЛЕН: без него Inertia полностью пересоздаёт компонент
// страницы после redirect()->back(), из-за чего родительская модалка заказа
// (открытая через нативный <dialog>) рвётся посреди работы, а её выпадающие
// списки перестают реагировать на клики. Новая запись находится диффом
// props.clients/props.vehicles до/после отправки и подставляется в форму
// автоматически. ---
const isQuickClientModalOpen = ref(false);
const quickClientForm = useForm({
    branch_id: '',
    type: 'b2c',
    name: '',
    phone: '',
    phone_required: true,
});

const openQuickClientModal = () => {
    quickClientForm.reset();
    quickClientForm.branch_id = form.branch_id || (props.branches[0]?.id ?? '');
    quickClientForm.type = 'b2c';
    isQuickClientModalOpen.value = true;
};

const closeQuickClientModal = () => {
    isQuickClientModalOpen.value = false;
    quickClientForm.reset();
    quickClientForm.clearErrors();
};

const submitQuickClient = () => {
    const existingIds = new Set(props.clients.map((c) => c.id));
    quickClientForm.post(route('crm.clients.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const created = props.clients.find((c) => !existingIds.has(c.id));
            if (created) {
                form.client_id = created.id;
                // Клиента могли завести "с нуля" прямо из формы автомобиля
                // (нет клиента → добавляем авто → тут же добавляем владельца) —
                // подставляем его и туда, чтобы не искать вручную второй раз.
                if (isQuickVehicleModalOpen.value) {
                    quickVehicleForm.client_id = created.id;
                }
            }
            closeQuickClientModal();
        },
    });
};

const isQuickVehicleModalOpen = ref(false);
const quickVehicleForm = useForm({
    client_id: '',
    vehicle_make_id: '',
    vehicle_model_id: '',
    plate_number: '',
});

const quickVehicleModels = computed(() => props.models.filter(m => m.vehicle_make_id === quickVehicleForm.vehicle_make_id));

const openQuickVehicleModal = () => {
    quickVehicleForm.reset();
    quickVehicleForm.client_id = form.client_id;
    isQuickVehicleModalOpen.value = true;
};

const closeQuickVehicleModal = () => {
    isQuickVehicleModalOpen.value = false;
    quickVehicleForm.reset();
    quickVehicleForm.clearErrors();
};

const submitQuickVehicle = () => {
    const existingIds = new Set(props.vehicles.map((v) => v.id));
    quickVehicleForm.post(route('crm.vehicles.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            const created = props.vehicles.find((v) => !existingIds.has(v.id));
            if (created) {
                form.vehicle_id = created.id;
                onVehicleSelected(created.id);
            }
            closeQuickVehicleModal();
        },
    });
};

// --- Юрлицо заказа (локация теперь может иметь несколько) ---
// Если в сайдбаре выбрано конкретное юрлицо — поле блокируется на нём:
// сменить юрлицо для НОВЫХ заказов можно только переключив сайдбар, чтобы
// не путаться, от какого юрлица реально выставляются документы.
const currentSidebarLegalEntityId = computed(() => page.props.current_legal_entity_id ? Number(page.props.current_legal_entity_id) : null);

const legalEntitiesForSelectedBranch = computed(() => {
    const branch = props.branches.find(b => b.id === form.branch_id);
    return branch?.legal_entities || [];
});

const isLegalEntityLocked = computed(() => {
    return currentSidebarLegalEntityId.value !== null
        && legalEntitiesForSelectedBranch.value.some(le => le.id === currentSidebarLegalEntityId.value);
});

// Пересчёт дефолта юрлица под выбранную локацию — либо фиксируем на том, что
// выбрано в сайдбаре (если оно доступно у этой локации), либо, если у локации
// ровно одно юрлицо, подставляем его, иначе оставляем на выбор пользователя.
// НЕ watch() — вызывается явно (при открытии формы и при ручной смене локации
// самим пользователем внутри формы), иначе при редактировании существующего
// заказа реактивный watch перезаписал бы уже сохранённое order.legal_entity_id
// сразу же после того, как openModal() его туда положил.
const defaultLegalEntityIdFor = (branchId) => {
    const options = props.branches.find(b => b.id === branchId)?.legal_entities || [];
    if (currentSidebarLegalEntityId.value !== null && options.some(le => le.id === currentSidebarLegalEntityId.value)) {
        return currentSidebarLegalEntityId.value;
    }
    return options.length === 1 ? options[0].id : '';
};

const onBranchChangedInForm = () => {
    form.legal_entity_id = defaultLegalEntityIdFor(form.branch_id);
};

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

const formatMoney = (amount) => {
    return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(amount / 100);
};

const openPreview = (order) => {
    previewOrder.value = order;
    isOffcanvasOpen.value = true;
};

const closePreview = () => {
    isOffcanvasOpen.value = false;
    setTimeout(() => {
        previewOrder.value = null;
    }, 300);
};

// Дефолт "Дата/время создания" = текущий момент, "Дата/время готовности" = +1 час
// (CLAUDE.md "Дата/время создания и готовности заказ-наряда") — берём локальное
// время браузера, как и остальные UI-дефолты дат в проекте (например todayIso()
// в Finance/Transactions/Index.vue), пользователь может тут же поправить.
const toLocalDateTimeString = (date) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

const openModal = (order = null) => {
    if (isOffcanvasOpen.value) {
        isOffcanvasOpen.value = false;
    }

    editingOrder.value = order;
    
    if (order) {
        form.branch_id = order.branch_id;
        form.legal_entity_id = order.legal_entity_id || '';
        form.client_id = order.client_id;
        form.vehicle_id = order.vehicle_id || '';
        form.status = order.status;
        form.mileage = order.mileage || '';
        form.order_date = order.order_date_local || '';
        form.ready_at = order.ready_at_local || '';

        const cf = {};
        props.customFieldDefs.forEach(def => {
            cf[def.key] = order.custom_fields && order.custom_fields[def.key] !== undefined
                ? order.custom_fields[def.key]
                : (def.type === 'checkbox' ? false : '');
        });
        form.custom_fields = cf;
    } else {
        form.reset();
        form.branch_id = page.props.current_branch_id || (props.branches.length > 0 ? props.branches[0].id : '');
        form.legal_entity_id = defaultLegalEntityIdFor(form.branch_id);
        // Активный фильтр по клиенту (например, со ссылки «Новый заказ» из карточки
        // клиента) сразу подставляется в форму создания
        form.client_id = filtersForm.client_id || '';
        form.status = 'new';
        const now = new Date();
        form.order_date = toLocalDateTimeString(now);
        form.ready_at = toLocalDateTimeString(new Date(now.getTime() + 60 * 60 * 1000));

        const cf = {};
        props.customFieldDefs.forEach(def => {
            cf[def.key] = def.type === 'checkbox' ? false : '';
        });
        form.custom_fields = cf;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingOrder.value = null;
    form.reset();
    form.clearErrors();
};

// Ссылка «Новый заказ» (из карточки клиента) ведёт на эту страницу с ?create=1 —
// открываем форму создания сразу и убираем параметр из URL, чтобы он не срабатывал
// повторно при навигации назад/обновлении.
onMounted(() => {
    // Ziggy v2: Router extends String, у него queryParams (а не query).
    // Inertia v2: router.replace(url, opts) не существует (был в v1) — замена
    // записи истории делается опцией replace: true у visit/get.
    if (route().queryParams.create === '1') {
        openModal();
        const q = { ...route().queryParams };
        delete q.create;
        // В Inertia v2 сигнатура get(url, data, options) — опции третьим аргументом
        router.get(route('operations.work-orders.index', q), {}, {
            replace: true,
            preserveState: true,
            preserveScroll: true,
        });
    }
});

// --- Выбор времени слотами в пределах рабочих часов выбранной в форме локации
// (CLAUDE.md "Дата/время создания и готовности заказ-наряда") — переиспользует
// composable, вынесенный из Operations/Appointments/Index.vue.
const effectiveWorkingHours = computed(() => {
    const branch = props.branches.find(b => b.id === form.branch_id);
    return (branch && branch.working_hours) || props.defaultWorkingHours || null;
});

const orderDate = computed({
    get: () => (form.order_date ? form.order_date.slice(0, 10) : ''),
    set: (val) => {
        const time = form.order_date ? form.order_date.slice(11, 16) : '';
        form.order_date = val ? `${val}T${time}` : '';
    },
});
const orderTime = computed({
    get: () => (form.order_date ? form.order_date.slice(11, 16) : ''),
    set: (val) => {
        const date = orderDate.value || toLocalDateTimeString(new Date()).slice(0, 10);
        form.order_date = val ? `${date}T${val}` : '';
    },
});
const readyDate = computed({
    get: () => (form.ready_at ? form.ready_at.slice(0, 10) : ''),
    set: (val) => {
        const time = form.ready_at ? form.ready_at.slice(11, 16) : '';
        form.ready_at = val ? `${val}T${time}` : '';
    },
});
const readyTime = computed({
    get: () => (form.ready_at ? form.ready_at.slice(11, 16) : ''),
    set: (val) => {
        const date = readyDate.value || orderDate.value || toLocalDateTimeString(new Date()).slice(0, 10);
        form.ready_at = val ? `${date}T${val}` : '';
    },
});

const orderTimeSlots = computed(() => withCurrentValue(
    buildTimeSlots(orderDate.value, effectiveWorkingHours.value, props.defaultWorkingHours),
    orderTime.value
));
const readyTimeSlots = computed(() => withCurrentValue(
    buildTimeSlots(readyDate.value, effectiveWorkingHours.value, props.defaultWorkingHours),
    readyTime.value
));

const submit = () => {
    if (editingOrder.value) {
        form.put(route('operations.work-orders.update', editingOrder.value.id), {
            onSuccess: () => closeModal(),
        });
    } else {
        form.post(route('operations.work-orders.store'), {
            onSuccess: () => closeModal(),
        });
    }
};

// "Завершён" — особый случай: у него есть побочные эффекты (списание
// склада, расчёт ЗП), которые делает только operations.work-orders.complete
// (см. WorkOrderController::completeOrder()) — обычный PATCH статуса их не
// выполняет. Уход СО статуса "Выдан" (completed) — тоже особый случай: сервер
// требует обязательный комментарий, поэтому вместо прямого PATCH открываем
// модалку. Тот же принцип, что и в Operations/WorkOrders/Show.vue::changeStatus().
const isReopenModalOpen = ref(false);
const reopenOrder = ref(null);
const reopenTargetStatus = ref('');

const changeStatus = (order, status) => {
    if (status === 'completed') {
        if (confirm(`Завершить заказ #${String(order.id).padStart(6, '0')}? Это действие спишет все материалы со склада и зафиксирует статус.`)) {
            router.post(route('operations.work-orders.complete', order.id), {}, { preserveScroll: true, preserveState: true });
        }
        return;
    }
    if (order.status === 'completed') {
        reopenOrder.value = order;
        reopenTargetStatus.value = status;
        isReopenModalOpen.value = true;
        return;
    }
    router.patch(route('operations.work-orders.status.update', order.id), { status }, {
        preserveScroll: true,
        preserveState: true,
    });
};

const closeReopenModal = () => {
    isReopenModalOpen.value = false;
    reopenOrder.value = null;
    reopenTargetStatus.value = '';
};

// --- Смена статуса иконкой действия (см. CLAUDE.md — эталон иконок списка) ---
const isStatusModalOpen = ref(false);
const statusChangeOrder = ref(null);

const openStatusModal = (order) => {
    statusChangeOrder.value = order;
    isStatusModalOpen.value = true;
};

const closeStatusModal = () => {
    isStatusModalOpen.value = false;
    statusChangeOrder.value = null;
};

const applyStatusChange = (status) => {
    changeStatus(statusChangeOrder.value, status);
    closeStatusModal();
};

// --- Приём оплаты иконкой действия (тот же поток, что и Show.vue::submitPayment) ---
const isPaymentModalOpen = ref(false);
const paymentOrder = ref(null);
const paymentForm = useForm({
    account_id: '',
    amount: 0,
});

const accountTypeLabels = {
    cash: 'Касса',
    bank: 'Расчетный счет',
    acquiring: 'Эквайринг',
    bonus: 'Бонусы клиента',
};

const paidAmount = (order) => order.paid_amount ?? 0;
const remainingAmount = (order) => Math.max(0, order.final_amount - paidAmount(order));

const selectedPaymentAccount = computed(() => props.accounts.find(a => a.id === paymentForm.account_id));

const paymentClientBonusRub = computed(() => {
    const points = paymentOrder.value?.client?.bonus_points || 0;
    return Math.floor(points * props.bonusRubPerPoint * 100) / 100;
});

const paymentAcquiringCommissionPreview = computed(() => {
    if (selectedPaymentAccount.value?.type !== 'acquiring') return null;
    const commissionPercent = Number(selectedPaymentAccount.value.commission_percent) || 0;
    const amount = Number(paymentForm.amount) || 0;
    const commission = Math.round(amount * commissionPercent) / 100;
    return { commission, net: Math.max(0, amount - commission) };
});

const paymentMaxPayableRub = computed(() => {
    if (!paymentOrder.value) return 0;
    const remaining = remainingAmount(paymentOrder.value) / 100;
    return selectedPaymentAccount.value?.type === 'bonus' ? Math.min(remaining, paymentClientBonusRub.value) : remaining;
});

const openPaymentModal = (order) => {
    paymentOrder.value = order;
    paymentForm.reset();
    paymentForm.amount = remainingAmount(order) / 100;
    if (props.accounts.length > 0) paymentForm.account_id = props.accounts[0].id;
    isPaymentModalOpen.value = true;
};

const closePaymentModal = () => {
    isPaymentModalOpen.value = false;
    paymentOrder.value = null;
    paymentForm.reset();
    paymentForm.clearErrors();
};

const submitPayment = () => {
    paymentForm.post(route('operations.work-orders.payment.store', paymentOrder.value.id), {
        preserveScroll: true,
        onSuccess: () => closePaymentModal(),
    });
};

const deleteOrder = (order) => {
    if (confirm(`Удалить заказ-наряд #${String(order.id).padStart(6, '0')}?`)) {
        form.delete(route('operations.work-orders.destroy', order.id));
        if (previewOrder.value && previewOrder.value.id === order.id) {
            closePreview();
        }
    }
};
</script>

<template>
    <Head title="Заказ-наряды" />

    <AuthenticatedLayout>
        <template #header>
            Операции и Заказы
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <!-- Навигация по разделу "Заказы" -->
            <OperationsNav />

            <PageHelper title="Заказ-наряды (Work Orders)">
                <p>Это центральный документ системы. В заказ-наряде фиксируются оказанные услуги, использованные материалы, исполнители и расчеты с клиентом.</p>
            </PageHelper>

            <!-- Action Bar (Bulk Actions) -->
            <BulkActions 
                v-if="selectedIds.length > 0" 
                :selectedCount="selectedIds.length" 
                noun="заказов" 
                @export="bulkExport" 
                @delete="bulkDelete" 
            />

            <!-- Table Card -->
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <DataTableToolbar
                    v-model="search"
                    :has-filters="Object.values(filtersForm).some(v => v !== '' && v !== null)"
                    @open-filters="isFiltersOpen = true"
                    @open-columns="openColumnsModal"
                    placeholder="Поиск по номеру заказа, ФИО клиента, телефону, номеру авто..."
                >
                    <template #actions>
                        <button
                            @click="openModal()"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                        >
                            <i class="ri-add-line text-base"></i>
                            Создать заказ
                        </button>
                    </template>
                </DataTableToolbar>
                <div class="overflow-x-auto w-full">
                    <DataTable
                        :columns="dataTableColumns"
                        :rows="workOrders.data"
                        selectable
                        v-model="selectedIds"
                        has-actions
                        row-clickable
                        @row-click="openPreview"
                        :sort="sort"
                        @sort="onSort"
                        empty-message="Заказ-наряды не найдены."
                    >
                        <template #cell-id="{ row: order }">
                            <span class="font-mono font-semibold text-gray-800 dark:text-gray-200 group-hover:text-primary transition-colors">
                                #{{ String(order.id).padStart(6, '0') }}
                            </span>
                        </template>

                        <template #cell-created_at="{ row: order }">
                            {{ new Date(order.created_at).toLocaleDateString('ru-RU') }}
                        </template>

                        <template #cell-client="{ row: order }">
                            <Link v-if="order.client" :href="route('crm.clients.show', order.client.id)" class="inline-flex items-center gap-1.5 hover:text-primary transition-colors font-medium" @click.stop>
                                <i class="ri-user-line text-gray-400"></i> {{ order.client.name }}
                            </Link>
                            <span v-else class="text-gray-400">—</span>
                        </template>

                        <template #cell-vehicle="{ row: order }">
                            <Link v-if="order.vehicle" :href="route('crm.vehicles.show', order.vehicle.id)" class="inline-flex items-center gap-1.5 hover:text-primary transition-colors font-medium" @click.stop>
                                <i class="ri-car-line text-gray-400"></i> {{ order.vehicle.make ? order.vehicle.make.name : '' }} {{ order.vehicle.vehicle_model ? order.vehicle.vehicle_model.name : '' }}
                            </Link>
                            <span v-else class="text-gray-400">—</span>
                        </template>

                        <template #cell-status="{ row: order }">
                            <StatusBadgeSelect
                                :model-value="order.status"
                                :options="workOrderStatuses"
                                @click.stop
                                @update:model-value="v => changeStatus(order, v)"
                            />
                        </template>

                        <template #cell-payment_status="{ row: order }">
                            <span :class="[paymentStatuses[order.payment_status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium']">
                                {{ paymentStatuses[order.payment_status]?.label || order.payment_status }}
                            </span>
                        </template>

                        <template #cell-final_amount="{ row: order }">
                            <span class="font-bold">{{ formatMoney(order.final_amount) }}</span>
                        </template>

                        <template #cell-branch="{ row: order }">
                            <span class="inline-flex items-center gap-1.5 bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded text-xs font-medium text-gray-700 dark:text-gray-300">
                                <i class="ri-store-2-line"></i> {{ order.branch ? order.branch.name : '—' }}
                            </span>
                        </template>

                        <template #cell-mileage="{ row: order }">
                            {{ order.mileage ? order.mileage + ' км' : '—' }}
                        </template>

                        <template v-for="def in customFieldDefs" :key="def.key" #[`cell-${def.key}`]="{ row: order }">
                            {{ order.custom_fields && order.custom_fields[def.key] !== undefined && order.custom_fields[def.key] !== null && order.custom_fields[def.key] !== '' ? order.custom_fields[def.key] : '—' }}
                        </template>

                        <template #actions="{ row: order }">
                            <Link
                                :href="route('operations.work-orders.show', order.id)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-info/10 text-info hover:bg-info hover:text-white"
                                title="Открыть заказ"
                                @click.stop
                            >
                                <i class="ri-folder-open-line"></i>
                            </Link>
                            <button
                                v-if="remainingAmount(order) > 0"
                                @click.stop="openPaymentModal(order)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-success/10 text-success hover:bg-success hover:text-white"
                                title="Принять оплату"
                            >
                                <i class="ri-money-dollar-circle-line"></i>
                            </button>
                            <button
                                @click.stop="openStatusModal(order)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-warning/10 text-warning hover:bg-warning hover:text-white"
                                :title="order.status === 'completed' ? 'Вернуть на доработку' : 'Сменить статус'"
                            >
                                <i class="ri-flag-2-line"></i>
                            </button>
                            <button
                                @click.stop="openModal(order)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white"
                                title="Редактировать форму"
                            >
                                <i class="ri-pencil-line"></i>
                            </button>
                            <button
                                @click.stop="deleteOrder(order)"
                                class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                                title="Удалить"
                            >
                                <i class="ri-delete-bin-line"></i>
                            </button>
                        </template>
                    </DataTable>
                </div>
                <Pagination :meta="workOrders" />
            </div>
        </div>

        <!-- Offcanvas Панель просмотра -->
        <Offcanvas :show="isOffcanvasOpen" @close="closePreview" maxWidth="md">
            <div class="flex flex-col h-full" v-if="previewOrder">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-start bg-gray-50/50 dark:bg-gray-800/30">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded bg-primary/10 flex items-center justify-center text-primary font-bold text-xl shrink-0">
                            <i class="ri-briefcase-line"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight font-mono">
                                Заказ #{{ String(previewOrder.id).padStart(6, '0') }}
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mt-0.5">
                                от {{ new Date(previewOrder.created_at).toLocaleDateString('ru-RU') }}
                            </p>
                        </div>
                    </div>
                    <button @click="closePreview" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-lg"></i></button>
                </div>

                <div class="flex-1 overflow-y-auto p-6 space-y-6">
                    <div class="flex flex-wrap gap-4">
                        <div>
                            <p class="text-[11px] text-gray-400 uppercase tracking-wider mb-1">Статус заказа</p>
                            <span :class="[statuses[previewOrder.status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold tracking-wide uppercase']">
                                {{ statuses[previewOrder.status]?.label || previewOrder.status }}
                            </span>
                        </div>
                        <div>
                            <p class="text-[11px] text-gray-400 uppercase tracking-wider mb-1">Статус оплаты</p>
                            <span :class="[paymentStatuses[previewOrder.payment_status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center px-2.5 py-1 rounded-md text-xs font-bold tracking-wide uppercase']">
                                {{ paymentStatuses[previewOrder.payment_status]?.label || previewOrder.payment_status }}
                            </span>
                        </div>
                        <div v-if="previewHasLocationBadge">
                            <p class="text-[11px] text-gray-400 uppercase tracking-wider mb-1">Локация</p>
                            <PointBadge :branch="previewOrder.branch" :legal-entity="previewOrder.legal_entity" />
                        </div>
                    </div>

                    <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 border border-gray-100 dark:border-gray-700/50 space-y-4">
                        <div v-if="previewOrder.client">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Клиент</p>
                            <Link :href="route('crm.clients.show', previewOrder.client.id)" class="text-sm font-bold text-primary hover:text-primary-600 flex items-center gap-2">
                                <i class="ri-user-line"></i> {{ previewOrder.client.name }}
                            </Link>
                        </div>
                        <div v-if="previewOrder.vehicle">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Автомобиль</p>
                            <Link :href="route('crm.vehicles.show', previewOrder.vehicle.id)" class="text-sm font-bold text-primary hover:text-primary-600 flex items-center gap-2">
                                <i class="ri-car-line"></i> {{ previewOrder.vehicle.make ? previewOrder.vehicle.make.name : '' }} {{ previewOrder.vehicle.vehicle_model ? previewOrder.vehicle.vehicle_model.name : '' }}
                            </Link>
                            <p class="text-xs text-gray-500 mt-1 ml-6">{{ previewOrder.vehicle.plate_number || 'Госномер не указан' }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3 border border-gray-100 dark:border-gray-700/50">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Сумма заказа</p>
                            <p class="text-lg font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(previewOrder.total_amount) }}</p>
                        </div>
                        <div class="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-3 border border-gray-100 dark:border-gray-700/50">
                            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">К оплате</p>
                            <p class="text-lg font-bold text-success">{{ formatMoney(previewOrder.final_amount) }}</p>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/80 flex justify-between gap-3">
                    <button @click="openModal(previewOrder)" class="flex-1 rounded-md px-4 py-2 text-sm font-medium bg-white border border-gray-300 text-gray-700 hover:bg-gray-50">
                        <i class="ri-pencil-line mr-2"></i> Форма
                    </button>
                    <Link :href="route('operations.work-orders.show', previewOrder.id)" class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium bg-primary text-white hover:bg-primary-600">
                        Открыть заказ <i class="ri-arrow-right-line ml-2"></i>
                    </Link>
                </div>
            </div>
        </Offcanvas>

        <!-- Offcanvas Фильтры -->
        <Offcanvas :show="isFiltersOpen" @close="isFiltersOpen = false" maxWidth="sm">
            <div class="flex flex-col h-full">
                <div class="px-6 py-5 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Фильтры</h3>
                    <button @click="isFiltersOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-6 space-y-5 custom-scrollbar">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус</label>
                        <select v-model="filtersForm.status" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все статусы</option>
                            <option v-for="s in workOrderStatuses" :key="s.value" :value="s.value">{{ s.label || s.value }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Оплата</label>
                        <select v-model="filtersForm.payment_status" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Любая</option>
                            <option v-for="(ps, key) in paymentStatuses" :key="key" :value="key">{{ ps.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация</label>
                        <select v-model="filtersForm.branch_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все локации</option>
                            <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Клиент</label>
                        <select v-model="filtersForm.client_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                            <option value="">Все клиенты</option>
                            <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
                        </select>
                    </div>

                    <!-- Кастомные фильтры -->
                    <template v-for="def in customFieldDefs.filter(f => f.is_filterable)" :key="def.id">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">{{ getLocalizedLabel(def.label) }}</label>
                            <template v-if="def.type === 'select'">
                                <select v-model="filtersForm['cf_' + def.key]" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                    <option value="">Все</option>
                                    <option v-for="opt in def.options" :key="opt" :value="opt">{{ opt }}</option>
                                </select>
                            </template>
                            <template v-else-if="def.type === 'checkbox'">
                                <select v-model="filtersForm['cf_' + def.key]" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                    <option value="">Все</option>
                                    <option value="1">Да</option>
                                    <option value="0">Нет</option>
                                </select>
                            </template>
                            <template v-else>
                                <input :type="def.type === 'number' ? 'number' : (def.type === 'date' ? 'date' : 'text')" v-model="filtersForm['cf_' + def.key]" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                            </template>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/80 flex gap-3">
                    <button @click="resetFilters" class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">
                        Сбросить
                    </button>
                    <button @click="isFiltersOpen = false" class="flex-1 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 shadow-sm">
                        Применить
                    </button>
                </div>
            </div>
        </Offcanvas>

        <!-- Модальное окно настройки столбцов -->
        <div v-if="isColumnsModalOpen" class="fixed inset-0 z-[60] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Настройка столбцов
                    </h3>
                    <button @click="closeColumnsModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <div class="p-6 space-y-4 max-h-[60vh] overflow-y-auto custom-scrollbar">
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Выберите столбцы для отображения и настройте их порядок.</p>

                    <div class="space-y-2 mb-6">
                        <h4 class="text-xs font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider mb-2">Отображаемые столбцы</h4>
                        <draggable v-model="columnsForm.visible_columns" :item-key="(key) => key" class="space-y-2" handle=".col-drag-handle">
                            <template #item="{ element: key }">
                                <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-800/50 border border-gray-200 dark:border-gray-700 rounded px-3 py-2">
                                    <div class="flex items-center gap-2">
                                        <i class="ri-draggable col-drag-handle text-gray-400 cursor-grab active:cursor-grabbing"></i>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                            {{ availableColumns.find(c => c.key === key)?.label || key }}
                                        </span>
                                    </div>
                                    <button type="button" @click="toggleColumn(key)" class="text-danger hover:text-danger/80"><i class="ri-close-circle-line text-lg"></i></button>
                                </div>
                            </template>
                        </draggable>
                    </div>

                    <div class="space-y-2">
                        <h4 class="text-xs font-bold text-gray-800 dark:text-gray-200 uppercase tracking-wider mb-2">Доступные столбцы</h4>
                        <div class="grid grid-cols-1 gap-2">
                            <label v-for="col in availableColumns.filter(c => !columnsForm.visible_columns.includes(c.key))" :key="col.key" class="flex items-center cursor-pointer group p-2 hover:bg-gray-50 dark:hover:bg-gray-800/50 rounded border border-transparent hover:border-gray-200 dark:hover:border-gray-700 transition-colors">
                                <input type="checkbox" :checked="false" @change="toggleColumn(col.key)" class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary cursor-pointer" />
                                <span class="ml-2 text-sm text-gray-600 dark:text-gray-400 group-hover:text-gray-900 dark:group-hover:text-gray-200 transition-colors">{{ col.label }}</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                    <button type="button" @click="closeColumnsModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">
                        Отмена
                    </button>
                    <button type="button" @click="saveColumns()" :disabled="columnsForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">
                        Сохранить вид
                    </button>
                </div>
            </div>
        </div>

        <!-- Модальное окно (Форма) -->
        <Modal :show="isModalOpen" @close="closeModal" maxWidth="3xl">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        {{ editingOrder ? 'Редактирование формы заказа' : 'Создание заказ-наряда' }}
                    </h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div v-if="branches.length > 1">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация <span class="text-danger">*</span></label>
                                <select v-model="form.branch_id" @change="onBranchChangedInForm" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите локацию...</option>
                                    <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус <span class="text-danger">*</span></label>
                                <select v-model="form.status" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option v-for="(status, key) in statuses" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ status.label }}</option>
                                </select>
                            </div>
                        </div>

                        <div v-if="legalEntitiesForSelectedBranch.length > 0">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Юрлицо</label>
                            <select
                                v-model="form.legal_entity_id"
                                :disabled="isLegalEntityLocked"
                                :class="[isLegalEntityLocked ? 'bg-gray-50 dark:bg-gray-800/60 cursor-not-allowed text-gray-500 dark:text-gray-400' : 'bg-transparent text-gray-800 dark:text-gray-200', 'block w-full rounded-md border border-gray-200 dark:border-gray-700 py-2 px-3 text-sm focus:border-primary focus:ring-0']"
                            >
                                <option value="" class="bg-white dark:bg-gray-800">Не указано (без реквизитов в документах)</option>
                                <option v-for="le in legalEntitiesForSelectedBranch" :key="le.id" :value="le.id" class="bg-white dark:bg-gray-800">{{ le.name }}</option>
                            </select>
                            <p v-if="isLegalEntityLocked" class="text-xs text-gray-500 dark:text-gray-400 mt-1.5">
                                Зафиксировано юрлицом, выбранным в шапке. Чтобы выставить документ от другого — переключите юрлицо в шапке сайта.
                            </p>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Клиент <span class="text-danger">*</span></label>
                                <div class="flex gap-2">
                                    <SearchableSelect
                                        v-model="form.client_id"
                                        :options="clientOptions"
                                        placeholder="Выберите клиента..."
                                        searchPlaceholder="Поиск клиента..."
                                        @update:model-value="form.vehicle_id = ''"
                                        class="flex-1"
                                    />
                                    <button type="button" @click="openQuickClientModal" class="shrink-0 inline-flex items-center justify-center rounded-md border border-primary/30 dark:border-primary/40 bg-primary/10 dark:bg-primary/15 px-3 hover:bg-primary/20 dark:hover:bg-primary/25 transition-colors" title="Добавить клиента">
                                        <i class="ri-add-line text-primary"></i>
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Автомобиль</label>
                                <div class="flex gap-2">
                                    <SearchableSelect
                                        v-model="form.vehicle_id"
                                        :options="vehicleOptions"
                                        placeholder="Без автомобиля — можно найти и по госномеру"
                                        searchPlaceholder="Поиск по марке, модели, госномеру..."
                                        clearable
                                        class="flex-1"
                                        @update:model-value="onVehicleSelected"
                                    />
                                    <button type="button" @click="openQuickVehicleModal" class="shrink-0 inline-flex items-center justify-center rounded-md border border-primary/30 dark:border-primary/40 bg-primary/10 dark:bg-primary/15 px-3 hover:bg-primary/20 dark:hover:bg-primary/25 transition-colors" title="Добавить автомобиль">
                                        <i class="ri-add-line text-primary"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Пробег (км)</label>
                            <input v-model="form.mileage" type="number" min="0" placeholder="Например: 150000" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата и время создания</label>
                                <div class="flex gap-2">
                                    <input v-model="orderDate" type="date" class="block w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    <select v-model="orderTime" class="block w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Время</option>
                                        <option v-for="slot in orderTimeSlots" :key="slot" :value="slot" class="bg-white dark:bg-gray-800">{{ slot }}</option>
                                    </select>
                                </div>
                                <p v-if="form.errors.order_date" class="mt-1 text-xs text-danger">{{ form.errors.order_date }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дата и время готовности</label>
                                <div class="flex gap-2">
                                    <input v-model="readyDate" type="date" class="block w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    <select v-model="readyTime" class="block w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Время</option>
                                        <option v-for="slot in readyTimeSlots" :key="slot" :value="slot" class="bg-white dark:bg-gray-800">{{ slot }}</option>
                                    </select>
                                </div>
                                <p class="mt-1 text-[11px] text-gray-400">На карточке заказа появится бейдж "Выполнить до …". Можно оставить пустым, если дедлайна нет.</p>
                                <p v-if="form.errors.ready_at" class="mt-1 text-xs text-danger">{{ form.errors.ready_at }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50">
                        <button type="button" @click="closeModal()" class="px-4 py-2 text-sm font-medium bg-secondary/10 text-secondary rounded">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="px-4 py-2 text-sm font-medium bg-primary text-white rounded">Сохранить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Быстрое добавление клиента — обязательно через <Modal> (не голый div с
        z-index): родительская форма заказа сама открыта через <Modal>, а тот
        рендерится нативным <dialog>.showModal() в браузерный top layer, который
        физически выше ЛЮБОГО обычного элемента независимо от z-index. Только
        второй <dialog> корректно ляжет поверх первого (top layer стекуется по
        порядку открытия). См. CLAUDE.md про пополняемые списки. -->
        <Modal :show="isQuickClientModalOpen" @close="closeQuickClientModal" maxWidth="md">
            <div class="bg-white dark:bg-[#313a46] rounded-md flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Новый клиент</h3>
                    <button @click="closeQuickClientModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitQuickClient" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация <span class="text-danger">*</span></label>
                            <select v-model="quickClientForm.branch_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" disabled class="bg-white dark:bg-gray-800">Выберите локацию...</option>
                                <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                            </select>
                            <span v-if="quickClientForm.errors.branch_id" class="text-xs text-danger mt-1">{{ quickClientForm.errors.branch_id }}</span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Имя <span class="text-danger">*</span></label>
                            <input v-model="quickClientForm.name" type="text" required placeholder="Иван Иванов" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="quickClientForm.errors.name" class="text-xs text-danger mt-1">{{ quickClientForm.errors.name }}</span>
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Телефон <span v-if="quickClientForm.phone_required" class="text-danger">*</span>
                                </label>
                                <label class="inline-flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 cursor-pointer select-none">
                                    <input
                                        type="checkbox"
                                        :checked="!quickClientForm.phone_required"
                                        @change="quickClientForm.phone_required = !$event.target.checked"
                                        class="rounded border-gray-300 dark:border-gray-600 text-primary focus:ring-primary focus:ring-offset-0"
                                    />
                                    Без номера
                                </label>
                            </div>
                            <input
                                v-model="quickClientForm.phone"
                                type="text"
                                :required="quickClientForm.phone_required"
                                placeholder="+7 (999) 000-00-00"
                                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                            />
                            <span v-if="quickClientForm.errors.phone" class="text-xs text-danger mt-1 block">{{ quickClientForm.errors.phone }}</span>
                            <p v-if="!quickClientForm.phone_required" class="text-[11px] text-warning bg-warning/5 border border-warning/20 rounded-md px-3 py-2 mt-2 flex items-start gap-1.5">
                                <i class="ri-error-warning-line mt-0.5"></i>
                                <span>Без номера высок риск случайно создать дубль клиента — указывайте это только если контакта действительно нет.</span>
                            </p>
                        </div>
                        <p class="text-xs text-gray-400">Остальные данные клиента можно заполнить позже, в карточке клиента.</p>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeQuickClientModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="quickClientForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Быстрое добавление автомобиля (см. пояснение выше про <Modal>) -->
        <Modal :show="isQuickVehicleModalOpen" @close="closeQuickVehicleModal" maxWidth="md">
            <div class="bg-white dark:bg-[#313a46] rounded-md flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Новый автомобиль</h3>
                    <button @click="closeQuickVehicleModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitQuickVehicle" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Клиент <span class="text-danger">*</span></label>
                            <div class="flex gap-2">
                                <SearchableSelect
                                    v-model="quickVehicleForm.client_id"
                                    :options="clientOptions"
                                    placeholder="Выберите клиента..."
                                    searchPlaceholder="Поиск клиента..."
                                    class="flex-1"
                                />
                                <button type="button" @click="openQuickClientModal" class="shrink-0 inline-flex items-center justify-center rounded-md border border-primary/30 dark:border-primary/40 bg-primary/10 dark:bg-primary/15 px-3 hover:bg-primary/20 dark:hover:bg-primary/25 transition-colors" title="Добавить клиента">
                                    <i class="ri-add-line text-primary"></i>
                                </button>
                            </div>
                            <span v-if="quickVehicleForm.errors.client_id" class="text-xs text-danger mt-1">{{ quickVehicleForm.errors.client_id }}</span>
                            <p class="text-[11px] text-gray-400 mt-1">Нет клиента? Добавьте его кнопкой «+», не закрывая эту форму.</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Марка <span class="text-danger">*</span></label>
                                <select v-model="quickVehicleForm.vehicle_make_id" @change="quickVehicleForm.vehicle_model_id = ''" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите марку...</option>
                                    <option v-for="make in makes" :key="make.id" :value="make.id" class="bg-white dark:bg-gray-800">{{ make.name }}</option>
                                </select>
                                <span v-if="quickVehicleForm.errors.vehicle_make_id" class="text-xs text-danger mt-1">{{ quickVehicleForm.errors.vehicle_make_id }}</span>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Модель <span class="text-danger">*</span></label>
                                <select v-model="quickVehicleForm.vehicle_model_id" :disabled="!quickVehicleForm.vehicle_make_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 disabled:opacity-50">
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите модель...</option>
                                    <option v-for="model in quickVehicleModels" :key="model.id" :value="model.id" class="bg-white dark:bg-gray-800">{{ model.name }}</option>
                                </select>
                                <span v-if="quickVehicleForm.errors.vehicle_model_id" class="text-xs text-danger mt-1">{{ quickVehicleForm.errors.vehicle_model_id }}</span>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Госномер</label>
                            <input v-model="quickVehicleForm.plate_number" type="text" placeholder="А 000 АА 00" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <span v-if="quickVehicleForm.errors.plate_number" class="text-xs text-danger mt-1">{{ quickVehicleForm.errors.plate_number }}</span>
                        </div>
                        <p class="text-xs text-gray-400">VIN, год и остальные данные можно заполнить позже, в карточке автомобиля.</p>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeQuickVehicleModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="quickVehicleForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Принять оплату (иконка действия в списке, тот же поток, что и Show.vue) -->
        <Modal :show="isPaymentModalOpen" @close="closePaymentModal" max-width="md">
            <div v-if="paymentOrder" class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Приём оплаты — заказ #{{ String(paymentOrder.id).padStart(6, '0') }}
                    </h3>
                    <button @click="closePaymentModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <form @submit.prevent="submitPayment" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <!-- Краткая карточка заказа: клиент/авто + KPI остатка -->
                        <div class="rounded-md bg-gray-50 dark:bg-gray-800/40 border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0">
                                    <i class="ri-user-3-line"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-gray-800 dark:text-gray-200 break-words">{{ paymentOrder.client?.name || 'Без клиента' }}</p>
                                    <p v-if="paymentOrder.vehicle" class="text-xs text-gray-500 break-words">{{ paymentOrder.vehicle.plate_number || '' }}</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-2 pt-3 border-t border-gray-200 dark:border-gray-700">
                                <div class="text-center">
                                    <p class="text-[10px] uppercase tracking-wide text-gray-400 mb-0.5">Сумма заказа</p>
                                    <p class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(paymentOrder.final_amount) }}</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[10px] uppercase tracking-wide text-gray-400 mb-0.5">Оплачено</p>
                                    <p class="text-sm font-bold text-success">{{ formatMoney(paidAmount(paymentOrder)) }}</p>
                                </div>
                                <div class="text-center">
                                    <p class="text-[10px] uppercase tracking-wide text-gray-400 mb-0.5">Остаток</p>
                                    <p class="text-sm font-bold text-danger">{{ formatMoney(remainingAmount(paymentOrder)) }}</p>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Касса / Счет <span class="text-danger">*</span></label>
                            <select v-model="paymentForm.account_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" disabled>Выберите счет...</option>
                                <option v-for="acc in accounts" :key="acc.id" :value="acc.id">{{ acc.name }} — {{ accountTypeLabels[acc.type] || acc.type }}</option>
                            </select>
                            <span v-if="paymentForm.errors.account_id" class="text-xs text-danger mt-1 block">{{ paymentForm.errors.account_id }}</span>
                        </div>

                        <div v-if="selectedPaymentAccount?.type === 'bonus'" class="p-3 rounded-md bg-info/5 border border-info/20 text-xs text-gray-600 dark:text-gray-400">
                            Доступно бонусами: <span class="font-bold text-info">{{ formatMoney(paymentClientBonusRub * 100) }}</span>
                            ({{ paymentOrder.client?.bonus_points || 0 }} баллов)
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма к оплате (₽) <span class="text-danger">*</span></label>
                            <input v-model="paymentForm.amount" type="number" step="0.01" min="0.01" :max="paymentMaxPayableRub" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            <p class="text-xs text-gray-500 mt-1">Остаток долга: {{ formatMoney(remainingAmount(paymentOrder)) }}</p>
                            <span v-if="paymentForm.errors.amount" class="text-xs text-danger mt-1 block">{{ paymentForm.errors.amount }}</span>
                        </div>

                        <div v-if="paymentAcquiringCommissionPreview" class="p-3 rounded-md bg-warning/5 border border-warning/20 text-xs text-gray-600 dark:text-gray-400 space-y-1">
                            <div class="flex justify-between"><span>Комиссия банка ({{ selectedPaymentAccount.commission_percent }}%):</span> <span class="font-bold text-warning">− {{ formatMoney(paymentAcquiringCommissionPreview.commission * 100) }}</span></div>
                            <div class="flex justify-between"><span>Зачислится на счет:</span> <span class="font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(paymentAcquiringCommissionPreview.net * 100) }}</span></div>
                            <p class="text-[11px] text-gray-400 pt-1">Заказ будет учтен как оплаченный на полную сумму — комиссия проводится отдельным расходом.</p>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closePaymentModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="paymentForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-success text-white hover:bg-success-600 disabled:opacity-50">Провести оплату</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Сменить статус (иконка действия в списке) -->
        <Modal :show="isStatusModalOpen" @close="closeStatusModal" max-width="sm">
            <div v-if="statusChangeOrder" class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Статус заказа #{{ String(statusChangeOrder.id).padStart(6, '0') }}
                    </h3>
                    <button @click="closeStatusModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <div class="p-6 space-y-2">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Текущий статус: <span :class="[statuses[statusChangeOrder.status]?.class, 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ml-1']">{{ statuses[statusChangeOrder.status]?.label || statusChangeOrder.status }}</span></p>
                    <button
                        v-for="option in workOrderStatuses.filter(s => s.is_active !== false)"
                        :key="option.value"
                        type="button"
                        @click="applyStatusChange(option.value)"
                        :disabled="option.value === statusChangeOrder.status"
                        class="w-full flex items-center justify-between px-4 py-2.5 rounded-md text-sm transition-colors border border-transparent hover:border-gray-200 dark:hover:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 disabled:opacity-40 disabled:cursor-default"
                    >
                        <span :class="[statusColorClasses[option.color] || statusColorClasses.gray, 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium']">{{ option.label || option.value }}</span>
                        <i v-if="option.value === statusChangeOrder.status" class="ri-check-line text-primary"></i>
                    </button>
                </div>
            </div>
        </Modal>

        <WorkOrderReopenModal
            :show="isReopenModalOpen"
            :work-order="reopenOrder"
            :target-status="reopenTargetStatus"
            :statuses="workOrderStatuses"
            @close="closeReopenModal"
        />

    </AuthenticatedLayout>
</template>