<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import Offcanvas from '@/Components/Offcanvas.vue';
import Modal from '@/Components/Modal.vue';
import EmployeeMultiSelect from '@/Components/EmployeeMultiSelect.vue';
import AssigneeMultiSelect from '@/Components/AssigneeMultiSelect.vue';
import CollapsiblePanel from '@/Components/CollapsiblePanel.vue';
import ActivityTimeline from '@/Components/ActivityTimeline.vue';
import WorkOrderItemPayoutModal from '@/Components/WorkOrderItemPayoutModal.vue';
import WorkOrderItemMaterialSettingsModal from '@/Components/WorkOrderItemMaterialSettingsModal.vue';
import AddMaterialModal from '@/Components/AddMaterialModal.vue';
import ServiceMaterialAutoAddModal from '@/Components/ServiceMaterialAutoAddModal.vue';
import WorkOrderReopenModal from '@/Components/WorkOrderReopenModal.vue';
import StatusBadgeSelect from '@/Components/StatusBadgeSelect.vue';
import PointBadge from '@/Components/PointBadge.vue';
import Dropdown from '@/Components/Dropdown.vue';
import draggable from 'vuedraggable';
import { Head, Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    workOrder: Object,
    customFieldsData: Array,
    branches: Array,
    clients: Array,
    vehicles: Array,
    services: Array,
    products: Array,
    warehouses: { type: Array, default: () => [] },
    accounts: Array,
    customFieldDefs: Array,
    pricingBasis: String,
    lookups: Object,
    businessDirections: Array,
    serviceCategories: Array,
    productCategories: Array,
    serviceMaterialAutoAddMode: { type: String, default: 'confirm' },
    employees: Array,
    contractors: { type: Array, default: () => [] },
    workOrderStatuses: { type: Array, default: () => [] },
    bonusRubPerPoint: { type: Number, default: 1 },
    linkedAppointment: { type: Object, default: () => null },
    candidateAppointment: { type: Object, default: () => null },
    activities: { type: Array, default: () => [] },
    comments: { type: Array, default: () => [] },
    documentTemplates: { type: Array, default: () => [] },
    wasReopenedAfterCompletion: { type: Boolean, default: false },
});

const page = usePage();

const isModalOpen = ref(false);

const linkCandidateAppointment = () => {
    if (!props.candidateAppointment) return;
    router.post(route('operations.appointments.link-work-order', props.candidateAppointment.id), {
        work_order_id: props.workOrder.id,
    });
};

const openAppointment = (appointmentId) => {
    router.visit(route('operations.appointments.index', { appointment: appointmentId }));
};
const activeMainTab = ref('items'); // 'items', 'payroll', 'comments', 'history', 'documents'

// --- Документы (Фаза 12) ---
const selectedDocumentTemplateId = ref(props.documentTemplates[0]?.id ?? '');
const generatingDocument = ref(false);

const generateDocument = () => {
    if (!selectedDocumentTemplateId.value) return;
    generatingDocument.value = true;
    router.post(route('documents.generate'), {
        document_template_id: selectedDocumentTemplateId.value,
        entity_type: 'work_order',
        entity_id: props.workOrder.id,
    }, {
        preserveScroll: true,
        onFinish: () => { generatingDocument.value = false; },
    });
};

const deleteDocument = (doc) => {
    if (confirm(`Удалить документ №${doc.number}? Если это последний выданный номер — следующий документ получит тот же номер.`)) {
        router.delete(route('documents.destroy', doc.id), { preserveScroll: true });
    }
};

const regenerateAsNew = (doc) => {
    router.post(route('documents.regenerate-as-new', doc.id), {}, { preserveScroll: true });
};

const replaceDocument = (doc) => {
    if (confirm(`Заменить документ №${doc.number} актуальными данными? Номер останется прежним, содержимое и дата формирования обновятся.`)) {
        router.post(route('documents.replace', doc.id), {}, { preserveScroll: true });
    }
};

// --- ЗАРПЛАТА (Фаза 10.1): администратор заказа + распределение выплат по позиции ---
const adminEligibleEmployees = computed(() => props.employees.filter(e => e.position?.payroll_role === 'admin'));

const payrollPreview = ref(null);
const payrollPreviewLoading = ref(false);

const fetchPayrollPreview = () => {
    payrollPreviewLoading.value = true;
    axios.get(route('operations.work-orders.payroll-preview', props.workOrder.id))
        .then(res => { payrollPreview.value = res.data; })
        .finally(() => { payrollPreviewLoading.value = false; });
};

const openPayrollTab = () => {
    activeMainTab.value = 'payroll';
    fetchPayrollPreview();
};

// Расчёт на вкладке "Зарплата" — предварительный (см. её баннер), и меняется
// от того, кто назначен администратором заказа/позиции и как распределена
// бригада. Пересчитываем сразу после любого такого изменения, а не только при
// следующем открытии вкладки — иначе после смены администратора цифры на уже
// открытой вкладке показывали бы устаревший расчёт до перезагрузки страницы.
const refreshPayrollPreviewIfLoaded = () => {
    if (payrollPreview.value !== null) {
        fetchPayrollPreview();
    }
};

const updateOrderAdmins = (employeeIds) => {
    router.patch(route('operations.work-orders.admin.update', props.workOrder.id), {
        employee_ids: employeeIds,
    }, {
        preserveScroll: true,
        onSuccess: refreshPayrollPreviewIfLoaded,
    });
};

const isPayoutModalOpen = ref(false);
const payoutItem = ref(null);

const openPayoutModal = (item) => {
    payoutItem.value = item;
    isPayoutModalOpen.value = true;
};

const closePayoutModal = () => {
    isPayoutModalOpen.value = false;
    payoutItem.value = null;
    refreshPayrollPreviewIfLoaded();
};

// --- Материалы на услугу (CLAUDE.md «Материалы на услугу») ---
const isMaterialSettingsModalOpen = ref(false);
const materialSettingsItem = ref(null);

const openMaterialSettingsModal = (item) => {
    materialSettingsItem.value = item;
    isMaterialSettingsModalOpen.value = true;
};

const closeMaterialSettingsModal = () => {
    isMaterialSettingsModalOpen.value = false;
    materialSettingsItem.value = null;
};

const isAddMaterialModalOpen = ref(false);
const addMaterialServiceItem = ref(null);

const openAddMaterialModal = (serviceItem) => {
    addMaterialServiceItem.value = serviceItem;
    isAddMaterialModalOpen.value = true;
};

const closeAddMaterialModal = () => {
    isAddMaterialModalOpen.value = false;
    addMaterialServiceItem.value = null;
};

const isAutoAddModalOpen = ref(false);
const autoAddServiceItem = ref(null);
const autoAddDefaultMaterials = ref([]);

const closeAutoAddModal = () => {
    isAutoAddModalOpen.value = false;
    autoAddServiceItem.value = null;
    autoAddDefaultMaterials.value = [];
};

// Вызывается ПОСЛЕ успешного добавления услуги в заказ (см. addItemDirect()
// и submitItem() — оба места, где услуга реально может быть добавлена,
// см. CLAUDE.md о живом баге "цена в двух местах" с тем же классом риска).
// Новая позиция ищется диффом: та из workOrder.items с этим itemable_id,
// у которой максимальный id (только что созданная).
const maybeTriggerMaterialAutoAdd = (catalogServiceId) => {
    if (props.serviceMaterialAutoAddMode === 'off' || !catalogServiceId) return;

    const service = props.services.find(s => s.id === catalogServiceId);
    const defaultMaterials = service?.default_materials || [];
    if (defaultMaterials.length === 0) return;

    const candidates = (props.workOrder.items || []).filter(
        i => i.itemable_type.includes('Service') && i.itemable_id === catalogServiceId
    );
    const newItem = candidates.reduce((max, i) => (!max || i.id > max.id ? i : max), null);
    if (!newItem) return;

    if (props.serviceMaterialAutoAddMode === 'confirm') {
        autoAddServiceItem.value = newItem;
        autoAddDefaultMaterials.value = defaultMaterials;
        isAutoAddModalOpen.value = true;
    } else {
        // silent — без диалога, но нехватка на складе всё равно не блокирует
        // добавление услуги: сервер молча пропустит недостающий материал и
        // запишет это в «Историю» заказа (см. autoAddMaterials()).
        router.post(route('operations.work-orders.items.materials.auto-add', [props.workOrder.id, newItem.id]), {
            materials: defaultMaterials.map(m => ({ product_id: m.product_id, quantity: Number(m.quantity) })),
        }, { preserveScroll: true });
    }
};

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

// Форма (Tri-State Record Pattern) — редактирование основных полей заказа
const form = useForm({
    branch_id: '',
    legal_entity_id: '',
    client_id: '',
    vehicle_id: '',
    status: '',
    mileage: '',
    custom_fields: {},
});

// Локация может иметь несколько юрлиц (branch_legal_entity) — если на самом
// заказе юрлицо не выбрано явно, а у локации таких вариантов 2+,
// DocumentPlaceholderService::resolveLegalEntity() не может угадать, какое
// использовать (см. CLAUDE.md, п.4). Документ всё равно сформируется, но
// с пустыми реквизитами и временным номером "БН-<дата>-<рандом>" вместо
// сквозной нумерации — предупреждаем ДО формирования, а не когда документ
// уже вышел с пустыми полями.
const legalEntityAmbiguousForDocuments = computed(() => {
    if (props.workOrder.legal_entity_id) return false;
    const branch = props.branches.find(b => b.id === props.workOrder.branch_id);
    return (branch?.legal_entities?.length || 0) > 1;
});

// --- Юрлицо заказа (см. Operations/WorkOrders/Index.vue — тот же паттерн) ---
const currentSidebarLegalEntityId = computed(() => page.props.current_legal_entity_id ? Number(page.props.current_legal_entity_id) : null);

const legalEntitiesForSelectedBranch = computed(() => {
    const branch = props.branches.find(b => b.id === form.branch_id);
    return branch?.legal_entities || [];
});

const isLegalEntityLocked = computed(() => {
    return currentSidebarLegalEntityId.value !== null
        && legalEntitiesForSelectedBranch.value.some(le => le.id === currentSidebarLegalEntityId.value);
});

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

const openModal = () => {
    form.branch_id = props.workOrder.branch_id;
    form.legal_entity_id = props.workOrder.legal_entity_id || '';
    form.client_id = props.workOrder.client_id;
    form.vehicle_id = props.workOrder.vehicle_id || '';
    form.status = props.workOrder.status;
    form.mileage = props.workOrder.mileage || '';

    const cf = {};
    props.customFieldDefs.forEach(def => {
        const existingVal = props.customFieldsData.find(d => d.definition.id === def.id)?.value;
        cf[def.key] = existingVal !== undefined && existingVal !== null 
            ? existingVal 
            : (def.type === 'checkbox' ? false : '');
    });
    form.custom_fields = cf;
    
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    form.put(route('operations.work-orders.update', props.workOrder.id), {
        onSuccess: () => closeModal(),
    });
};

// --- ФУНКЦИОНАЛ ПАКЕТНОЙ ДВУХКОЛОНОЧНОЙ КОРЗИНЫ (DRAWER) ---
const isBatchDrawerOpen = ref(false);
const drawerSearch = ref('');
const drawerTab = ref('services'); // 'services' | 'products'
const selectedDirectionId = ref('');
const addedToastMessage = ref('');
const showToast = ref(false);

const triggerToast = (msg) => {
    addedToastMessage.value = msg;
    showToast.value = true;
    setTimeout(() => {
        showToast.value = false;
    }, 2000);
};

// Подсчет количества добавленных единиц конкретного товара/услуги
const getItemCountInOrder = (type, itemId) => {
    if (!props.workOrder.items) return 0;
    const targetClass = type === 'service' ? 'Service' : 'Product';
    return props.workOrder.items
        .filter(item => item.itemable_type.includes(targetClass) && item.itemable_id === itemId)
        .reduce((sum, item) => sum + parseFloat(item.quantity), 0);
};

const warehouseEnabled = computed(() => page.props.warehouse_enabled !== false);

// Фильтр каталога товаров/материалов по остаткам на складе — при включённом
// складском учёте в списке добавления должны быть видны только позиции,
// реально присутствующие на складе (не пустой каталог из карточек без остатка).
// catalogWarehouseFilter = '' значит "любой склад" (суммарный остаток по всем
// складам > 0); при нескольких активных складах в системе можно сузить до
// остатка на конкретном складе — тогда используется только его баланс.
const catalogWarehouseFilter = ref('');
const productStockQuantity = (product, warehouseId) => {
    const balances = product.stock_balances || [];
    if (warehouseId) {
        const match = balances.find(b => b.warehouse_id === Number(warehouseId));
        return match ? parseFloat(match.quantity) : 0;
    }
    return balances.reduce((sum, b) => sum + parseFloat(b.quantity), 0);
};
const stockFilteredProducts = computed(() => {
    if (!warehouseEnabled.value) return props.products || [];
    return (props.products || []).filter(p => productStockQuantity(p, catalogWarehouseFilter.value) > 0);
});

// Фильтрация услуг в слайдере по поиску и направлению бизнеса
const filteredDrawerServices = computed(() => {
    let list = props.services;
    if (selectedDirectionId.value) {
        list = list.filter(s => s.business_direction_id === selectedDirectionId.value);
    }
    if (drawerSearch.value) {
        const query = drawerSearch.value.toLowerCase();
        list = list.filter(s => getLocalizedLabel(s.name).toLowerCase().includes(query));
    }
    return list;
});

// Фильтрация товаров в слайдере по поиску (база — уже отфильтрованные по остатку на складе)
const filteredDrawerProducts = computed(() => {
    let list = stockFilteredProducts.value;
    if (drawerSearch.value) {
        const query = drawerSearch.value.toLowerCase();
        list = list.filter(p => getLocalizedLabel(p.name).toLowerCase().includes(query) || (p.sku && p.sku.toLowerCase().includes(query)));
    }
    return list;
});

// Группировка услуг по категориям для красивого вывода
const groupedDrawerServices = computed(() => {
    const groups = {};
    filteredDrawerServices.value.forEach(service => {
        const catId = service.service_category_id || 0;
        if (!groups[catId]) {
            const cat = props.serviceCategories.find(c => c.id === catId);
            groups[catId] = {
                id: catId,
                name: cat ? getLocalizedLabel(cat.name) : 'Без категории',
                items: []
            };
        }
        groups[catId].items.push(service);
    });
    return Object.values(groups);
});

// Группировка товаров по категориям для красивого вывода
const groupedDrawerProducts = computed(() => {
    const groups = {};
    filteredDrawerProducts.value.forEach(product => {
        const catId = product.product_category_id || 0;
        if (!groups[catId]) {
            const cat = props.productCategories.find(c => c.id === catId);
            groups[catId] = {
                id: catId,
                name: cat ? getLocalizedLabel(cat.name) : 'Без категории',
                items: []
            };
        }
        groups[catId].items.push(product);
    });
    return Object.values(groups);
});

// Добавление позиции напрямую из слайдера без закрытия
const addItemDirect = (type, item) => {
    let finalPrice = 0;
    let name = getLocalizedLabel(item.name);
    
    if (type === 'service') {
        finalPrice = item.price / 100; // Базовая цена

        // Расчет цены на основе матрицы
        if (props.pricingBasis === 'vehicle_body' && props.workOrder.vehicle?.vehicle_model?.body_type) {
            const bodyType = props.workOrder.vehicle.vehicle_model.body_type;
            if (item.prices && item.prices[bodyType]) {
                finalPrice = item.prices[bodyType] / 100;
            }
        } else if (props.pricingBasis === 'vehicle_class' && props.workOrder.vehicle?.vehicle_model?.category) {
            const vClass = props.workOrder.vehicle.vehicle_model.category;
            if (item.prices && item.prices[vClass]) {
                finalPrice = item.prices[vClass] / 100;
            }
        }
    } else {
        // Товар — цена продажи (Warehouse/Products) за вычетом собственной скидки
        // товара; остаётся редактируемой вручную в таблице позиций после добавления.
        const discount = Number(item.discount_percent) || 0;
        finalPrice = item.base_price ? (item.base_price * (1 - discount / 100)) / 100 : 0;
    }

    router.post(route('operations.work-orders.items.store', props.workOrder.id), {
        itemable_type: type,
        itemable_id: item.id,
        name: name,
        quantity: 1,
        price: finalPrice,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            triggerToast(`"${name}" добавлено в заказ!`);
            if (type === 'service') {
                maybeTriggerMaterialAutoAdd(item.id);
            }
        }
    });
};

// Обновление деталей позиции прямо из слайдера или таблицы
const updateItemDetails = (item, fields) => {
    router.put(route('operations.work-orders.items.update', [props.workOrder.id, item.id]), fields, {
        preserveScroll: true,
    });
};

// Исполнителем позиции может быть штатный сотрудник или подрядчик — тип
// приходит из AssigneeMultiSelect вместе со списком id, потому что сервер
// синхронизирует только связь соответствующего типа (см. WorkOrderController).
const updateItemAssignees = (item, { type, ids }) => {
    updateItemDetails(item, { employee_ids: ids, assignee_type: type });
};

// Скидка на отдельную позицию (сумма ₽ или %)
const itemDiscountMode = ref({}); // item.id -> 'amount' | 'percent'

const getItemDiscountMode = (item) => itemDiscountMode.value[item.id] || 'amount';

const setItemDiscountMode = (item, mode) => {
    itemDiscountMode.value = { ...itemDiscountMode.value, [item.id]: mode };
};

const itemBaseRub = (item) => (parseFloat(item.quantity) * item.price) / 100;

const itemDiscountPercent = (item) => {
    const base = itemBaseRub(item);
    if (base <= 0) return 0;
    return Math.round(((item.discount_amount || 0) / 100 / base) * 1000) / 10;
};

const itemDiscountDisplayValue = (item) => {
    const amountRub = (item.discount_amount || 0) / 100;
    if (getItemDiscountMode(item) === 'amount') return amountRub;
    const base = itemBaseRub(item);
    return base > 0 ? Math.round((amountRub / base) * 10000) / 100 : 0;
};

const applyItemDiscount = (item, rawValue) => {
    const raw = Number(rawValue) || 0;
    const base = itemBaseRub(item);
    const amountRub = getItemDiscountMode(item) === 'amount' ? raw : Math.round(base * raw) / 100;
    const clamped = Math.max(0, Math.min(amountRub, base));
    updateItemDetails(item, { discount_amount: clamped });
};

// --- ФОРМА ОДИНОЧНОГО ДОБАВЛЕНИЯ (Fallback) ---
const isItemModalOpen = ref(false);
const itemForm = useForm({
    itemable_type: 'service',
    itemable_id: '',
    name: '',
    quantity: 1,
    price: 0,
});

const openItemModal = () => {
    itemForm.reset();
    itemForm.itemable_type = 'service';
    itemForm.quantity = 1;
    itemForm.price = 0;
    isItemModalOpen.value = true;
};

const closeItemModal = () => {
    isItemModalOpen.value = false;
    itemForm.reset();
    itemForm.clearErrors();
};

const submitItem = () => {
    const wasService = itemForm.itemable_type === 'service';
    const catalogServiceId = itemForm.itemable_id;

    itemForm.post(route('operations.work-orders.items.store', props.workOrder.id), {
        onSuccess: () => {
            closeItemModal();
            if (wasService) {
                maybeTriggerMaterialAutoAdd(catalogServiceId);
            }
        },
    });
};

const deleteItem = (item) => {
    if (confirm(`Удалить позицию "${item.name}"?`)) {
        router.delete(route('operations.work-orders.items.destroy', [props.workOrder.id, item.id]));
    }
};

// --- ПОРЯДОК ПОЗИЦИЙ (drag-and-drop + автосортировка "услуги сверху, товары снизу") ---
const sortedItems = ref([...(props.workOrder.items || [])]);
watch(() => props.workOrder.items, (items) => {
    sortedItems.value = [...(items || [])];
});

const onItemsReordered = () => {
    router.post(route('operations.work-orders.items.reorder', props.workOrder.id), {
        ids: sortedItems.value.map(i => i.id),
    }, { preserveScroll: true, preserveState: true });
};

const autoSortItems = (mode) => {
    router.post(route('operations.work-orders.items.auto-sort', props.workOrder.id), { mode }, { preserveScroll: true });
};

// Название услуги, к которой привязан материал — материалы после сортировки "сначала услуги, затем
// материалы" оказываются далеко от своей услуги, и без явной подписи непонятно, к чему они относятся.
const parentItemName = (item) => {
    if (!item.linked_item_id) return null;
    const parent = (props.workOrder.items || []).find(i => i.id === item.linked_item_id);
    return parent ? parent.name : null;
};

// Иконка "Настройки списания" — у материала всегда (там ещё 4 тумблера про ЗП/видимость
// клиенту), у обычного товара только если включён склад (иначе там нечего показывать,
// единственный релевантный тумблер allow_negative_stock скрыт при выключенном складе).
const canConfigureItemStock = (item) => {
    if (item.linked_item_id) return true;
    return !item.itemable_type.includes('Service') && warehouseEnabled.value;
};

// Фильтр корзины в дровере "Пакетный набор услуг и товаров" — при большом составе заказа
// сложно разобраться, где услуги, а где товары/материалы вперемешку.
const cartFilter = ref('all'); // 'all' | 'service' | 'product'
const cartServiceCount = computed(() => (props.workOrder.items || []).filter(i => i.itemable_type.includes('Service')).length);
const cartProductCount = computed(() => (props.workOrder.items || []).filter(i => !i.itemable_type.includes('Service')).length);
const filteredCartItems = computed(() => {
    const items = props.workOrder.items || [];
    if (cartFilter.value === 'service') return items.filter(i => i.itemable_type.includes('Service'));
    if (cartFilter.value === 'product') return items.filter(i => !i.itemable_type.includes('Service'));
    return items;
});

watch(() => itemForm.itemable_id, (newId) => {
    if (!newId) return;
    if (itemForm.itemable_type === 'service') {
        const service = props.services.find(s => s.id === newId);
        if (service) {
            itemForm.name = getLocalizedLabel(service.name);
            
            let finalPrice = service.price;
            if (props.pricingBasis === 'vehicle_body' && props.workOrder.vehicle?.vehicle_model?.body_type) {
                const bodyType = props.workOrder.vehicle.vehicle_model.body_type;
                if (service.prices && service.prices[bodyType]) {
                    finalPrice = service.prices[bodyType];
                }
            } else if (props.pricingBasis === 'vehicle_class' && props.workOrder.vehicle?.vehicle_model?.category) {
                const vClass = props.workOrder.vehicle.vehicle_model.category;
                if (service.prices && service.prices[vClass]) {
                    finalPrice = service.prices[vClass];
                }
            }
            itemForm.price = finalPrice / 100;
        }
    } else {
        const product = props.products.find(p => p.id === newId);
        if (product) {
            itemForm.name = getLocalizedLabel(product.name);
            // Цена по умолчанию — цена продажи товара за вычетом его собственной
            // скидки (Warehouse/Products); остаётся редактируемой вручную, как и
            // у услуг выше.
            const discount = Number(product.discount_percent) || 0;
            itemForm.price = product.base_price ? (product.base_price * (1 - discount / 100)) / 100 : 0;
        }
    }
});

watch(() => itemForm.itemable_type, () => {
    itemForm.itemable_id = '';
    itemForm.name = '';
    itemForm.price = 0;
});

// --- СВОЯ ПОЗИЦИЯ-УСЛУГА БЕЗ КАРТОЧКИ В КАТАЛОГЕ (добавляется в заказ сразу, каталог — по желанию) ---
const isQuickServiceModalOpen = ref(false);
const quickServiceForm = useForm({
    service_category_id: '',
    business_direction_id: '',
    name: '',
    price: 0,
    duration_minutes: 60,
    save_to_catalog: true,
});

const openQuickServiceModal = () => {
    quickServiceForm.reset();
    quickServiceForm.save_to_catalog = true;
    isQuickServiceModalOpen.value = true;
};

const closeQuickServiceModal = () => {
    isQuickServiceModalOpen.value = false;
    quickServiceForm.reset();
    quickServiceForm.clearErrors();
};

const submitQuickService = () => {
    quickServiceForm.transform((data) => ({
        itemable_type: 'service',
        is_custom: true,
        save_to_catalog: data.save_to_catalog,
        service_category_id: data.service_category_id || null,
        business_direction_id: data.business_direction_id || null,
        name: data.name,
        price: data.price,
        duration_minutes: data.duration_minutes,
        quantity: 1,
    })).post(route('operations.work-orders.items.store', props.workOrder.id), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => closeQuickServiceModal(),
    });
};

const isQuickProductModalOpen = ref(false);
const quickProductForm = useForm({
    product_category_id: '',
    name: '',
    sku: '',
    unit: 'шт',
    accounting_type: 'average',
});

const openQuickProductModal = () => {
    quickProductForm.reset();
    quickProductForm.unit = 'шт';
    quickProductForm.accounting_type = 'average';
    isQuickProductModalOpen.value = true;
};

const closeQuickProductModal = () => {
    isQuickProductModalOpen.value = false;
    quickProductForm.reset();
    quickProductForm.clearErrors();
};

const submitQuickProduct = () => {
    quickProductForm.post(route('operations.work-orders.quick-product'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => closeQuickProductModal(),
    });
};

// Форма скидки
const isDiscountModalOpen = ref(false);
const discountForm = useForm({
    discount_amount: 0,
});

const openDiscountModal = () => {
    discountForm.discount_amount = props.workOrder.discount_amount / 100;
    isDiscountModalOpen.value = true;
};

const closeDiscountModal = () => {
    isDiscountModalOpen.value = false;
    discountForm.reset();
    discountForm.clearErrors();
};

const submitDiscount = () => {
    discountForm.post(route('operations.work-orders.discount.update', props.workOrder.id), {
        onSuccess: () => closeDiscountModal(),
    });
};

const resetDiscountAuto = () => {
    router.post(route('operations.work-orders.discount.update', props.workOrder.id), { auto: true }, {
        preserveScroll: true,
        onSuccess: () => closeDiscountModal(),
    });
};

const orderDiscountPercent = computed(() => {
    if (!props.workOrder.total_amount) return 0;
    return Math.round((props.workOrder.discount_amount / props.workOrder.total_amount) * 1000) / 10;
});

// Скидка на позицию и скидка на заказ взаимоисключающие (см. WorkOrderController) —
// эти два вычисляемых свойства управляют блокировкой полей друг друга в UI.
const itemsDiscountTotal = computed(() => (props.workOrder.items || []).reduce((sum, item) => sum + (item.discount_amount || 0), 0));
const hasItemDiscounts = computed(() => itemsDiscountTotal.value > 0);
const hasOrderDiscount = computed(() => props.workOrder.discount_is_manual && props.workOrder.discount_amount > 0);

// Инлайн-скидка в корзине пакетного добавления (сумма ₽ или %)
const drawerDiscountMode = ref('amount'); // 'amount' | 'percent'
const drawerDiscountValue = ref(0);

const drawerTotalRub = computed(() => props.workOrder.total_amount / 100);

const syncDrawerDiscountFromOrder = () => {
    const amountRub = props.workOrder.discount_amount / 100;
    if (drawerDiscountMode.value === 'amount') {
        drawerDiscountValue.value = amountRub;
    } else {
        drawerDiscountValue.value = drawerTotalRub.value > 0
            ? Math.round((amountRub / drawerTotalRub.value) * 10000) / 100
            : 0;
    }
};

const setDrawerDiscountMode = (mode) => {
    if (mode === drawerDiscountMode.value) return;
    drawerDiscountMode.value = mode;
    syncDrawerDiscountFromOrder();
};

const drawerDiscountAmountRub = computed(() => {
    const value = Number(drawerDiscountValue.value) || 0;
    if (drawerDiscountMode.value === 'amount') {
        return value;
    }
    return Math.round(drawerTotalRub.value * value) / 100;
});

const applyDrawerDiscount = () => {
    const amount = Math.max(0, Math.min(drawerDiscountAmountRub.value, drawerTotalRub.value));
    router.post(route('operations.work-orders.discount.update', props.workOrder.id), {
        discount_amount: amount,
    }, { preserveScroll: true });
};

watch(isBatchDrawerOpen, (open) => {
    if (open) syncDrawerDiscountFromOrder();
});
watch(() => props.workOrder.discount_amount, syncDrawerDiscountFromOrder);

// Форма оплаты
const isPaymentModalOpen = ref(false);
const paymentForm = useForm({
    account_id: '',
    amount: 0,
});

const remainingAmount = computed(() => {
    const paid = props.workOrder.transactions?.filter(t => t.type === 'income').reduce((sum, t) => sum + t.amount, 0) || 0;
    return Math.max(0, props.workOrder.final_amount - paid);
});

// Только поступления (без служебных расходов вроде комиссии эквайринга)
const paymentTransactions = computed(() => props.workOrder.transactions?.filter(t => t.type === 'income') || []);

const transactionLink = (tx) => route('finance.transactions.index', { filters: { id: tx.id } });

const accountTypeLabels = {
    cash: 'Касса',
    bank: 'Расчетный счет',
    acquiring: 'Эквайринг',
    bonus: 'Бонусы клиента',
};

const selectedAccount = computed(() => props.accounts.find(a => a.id === paymentForm.account_id));

// Сколько клиент может оплатить бонусами, в рублях
const clientBonusRub = computed(() => {
    const points = props.workOrder.client?.bonus_points || 0;
    return Math.floor(points * props.bonusRubPerPoint * 100) / 100;
});

// Превью комиссии эквайринга — чисто информационно, реальный расчет всегда на бэкенде
const acquiringCommissionPreview = computed(() => {
    if (selectedAccount.value?.type !== 'acquiring') return null;
    const commissionPercent = Number(selectedAccount.value.commission_percent) || 0;
    const amount = Number(paymentForm.amount) || 0;
    const commission = Math.round(amount * commissionPercent) / 100;
    return { commission, net: Math.max(0, amount - commission) };
});

const maxPayableRub = computed(() => {
    const remaining = remainingAmount.value / 100;
    return selectedAccount.value?.type === 'bonus' ? Math.min(remaining, clientBonusRub.value) : remaining;
});

const openPaymentModal = () => {
    paymentForm.reset();
    paymentForm.amount = remainingAmount.value / 100;
    if (props.accounts && props.accounts.length > 0) {
        paymentForm.account_id = props.accounts[0].id;
    }
    isPaymentModalOpen.value = true;
};

const closePaymentModal = () => {
    isPaymentModalOpen.value = false;
    paymentForm.reset();
    paymentForm.clearErrors();
};

const submitPayment = () => {
    paymentForm.post(route('operations.work-orders.payment.store', props.workOrder.id), {
        onSuccess: () => closePaymentModal(),
    });
};

// Завершение заказа (Списание склада)
const completeOrderForm = useForm({});
const completeOrder = () => {
    if (confirm('Вы уверены, что хотите завершить заказ? Это действие спишет все материалы со склада и зафиксирует статус.')) {
        completeOrderForm.post(route('operations.work-orders.complete', props.workOrder.id));
    }
};

// Смена статуса прямо из карточки. "Завершён" — особый случай: у него есть
// побочные эффекты (списание склада, расчёт ЗП), которые делает только
// completeOrder() — обычный PATCH их не выполняет, поэтому выбор этого пункта
// в списке перенаправляется на тот же поток, что и кнопка "Завершить заказ".
// Уход СО статуса "Выдан" (completed) — тоже особый случай: сервер требует
// обязательный комментарий, поэтому вместо прямого PATCH открываем модалку
// (CLAUDE.md «Закрытие заказ-наряда после выдачи»).
const isReopenModalOpen = ref(false);
const reopenTargetStatus = ref('');

const changeStatus = (status) => {
    if (status === 'completed') {
        completeOrder();
        return;
    }
    if (props.workOrder.status === 'completed') {
        reopenTargetStatus.value = status;
        isReopenModalOpen.value = true;
        return;
    }
    router.patch(route('operations.work-orders.status.update', props.workOrder.id), { status }, {
        preserveScroll: true,
    });
};

const closeReopenModal = () => {
    isReopenModalOpen.value = false;
    reopenTargetStatus.value = '';
};

// Вспомогательные функции
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
</script>

<template>
    <Head :title="`Заказ-наряд #${String(workOrder.id).padStart(6, '0')}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between w-full">
                <div class="flex items-center gap-2">
                    <Link :href="route('operations.work-orders.index')" class="text-gray-500 hover:text-primary transition-colors">
                        <i class="ri-arrow-left-line"></i> Заказ-наряды
                    </Link>
                    <span class="text-gray-400">/</span>
                    <span class="font-semibold text-gray-800 dark:text-gray-200 font-mono">Заказ #{{ String(workOrder.id).padStart(6, '0') }}</span>
                </div>
                <div class="flex gap-2">
                    <button v-if="workOrder.status !== 'completed'" @click="completeOrder" :disabled="completeOrderForm.processing" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-success text-white hover:bg-success-600 shadow-sm disabled:opacity-50">
                        <i class="ri-check-double-line mr-1.5"></i> Завершить заказ
                    </button>
                    <button v-if="workOrder.status !== 'completed'" @click="openModal" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm">
                        <i class="ri-pencil-line mr-1.5"></i> Редактировать форму
                    </button>
                </div>
            </div>
        </template>

        <!-- Блок ошибок (например, нехватка на складе) -->
        <div v-if="page.props.errors.error" class="w-[99%] mx-auto mb-4 p-4 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-start gap-3">
            <i class="ri-error-warning-fill text-xl shrink-0"></i>
            <div>
                <p class="font-bold mb-1">Ошибка выполнения операции:</p>
                <p>{{ page.props.errors.error }}</p>
            </div>
        </div>

        <!-- Заказ хотя бы раз возвращался на доработку после "Выдан" (CLAUDE.md
             «Закрытие заказ-наряда после выдачи») — производный факт из Истории,
             не пропадает даже после повторной выдачи. -->
        <div v-if="wasReopenedAfterCompletion" class="w-[99%] mx-auto mb-4 bg-warning/10 border border-warning/30 rounded-md p-4 flex items-start gap-3">
            <i class="ri-history-line text-warning text-lg shrink-0 mt-0.5"></i>
            <p class="text-sm text-gray-700 dark:text-gray-300">Заказ изменялся после выдачи клиенту — как минимум один раз возвращался на доработку. Подробности и причины — во вкладке «История».</p>
        </div>

        <!-- TRI-STATE 2: Карточка (w-[99%] mx-auto для Fluid-дизайна) -->
        <div class="w-[99%] mx-auto flex flex-col lg:flex-row gap-6 font-sans text-slate-600">
            
            <!-- Левая колонка: About (Свойства сущности) -->
            <CollapsiblePanel storage-key="show-card-left" side="left">

                <!-- Статус и Базовая инфа -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex flex-col items-center text-center">
                    <div class="w-20 h-20 rounded bg-primary/10 flex items-center justify-center text-primary font-bold text-3xl mb-4">
                        <i class="ri-briefcase-line"></i>
                    </div>
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight mb-1 font-mono">
                        Заказ #{{ String(workOrder.id).padStart(6, '0') }}
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mb-4">
                        от {{ new Date(workOrder.created_at).toLocaleDateString('ru-RU') }}
                    </p>
                    <div class="flex flex-wrap justify-center gap-2">
                        <StatusBadgeSelect
                            :model-value="workOrder.status"
                            :options="workOrderStatuses"
                            :title="workOrder.status === 'completed' ? 'Заказ выдан — смена статуса вернёт его на доработку (потребуется указать причину)' : ''"
                            @update:model-value="changeStatus"
                        />
                        <PointBadge :branch="workOrder.branch" :legal-entity="workOrder.legal_entity" />
                    </div>
                </div>

                <!-- Клиент -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Клиент</h3>
                        <Link v-if="workOrder.client" :href="route('crm.clients.show', workOrder.client.id)" class="text-primary hover:text-primary-600 transition-colors text-sm font-medium">
                            Перейти <i class="ri-arrow-right-s-line"></i>
                        </Link>
                    </div>
                    <div class="p-6 space-y-4" v-if="workOrder.client">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold shrink-0">
                                {{ workOrder.client.name.charAt(0) }}
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-800 dark:text-gray-200 leading-tight">{{ workOrder.client.name }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ workOrder.client.phone || 'Нет телефона' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Автомобиль -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Автомобиль</h3>
                        <Link v-if="workOrder.vehicle" :href="route('crm.vehicles.show', workOrder.vehicle.id)" class="text-primary hover:text-primary-600 transition-colors text-sm font-medium">
                            Перейти <i class="ri-arrow-right-s-line"></i>
                        </Link>
                    </div>
                    <div class="p-6 space-y-4" v-if="workOrder.vehicle">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-gray-500 dark:text-gray-400 shrink-0">
                                <i class="ri-car-line text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-800 dark:text-gray-200 leading-tight">{{ workOrder.vehicle.make ? workOrder.vehicle.make.name : '' }} {{ workOrder.vehicle.vehicle_model ? workOrder.vehicle.vehicle_model.name : '' }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ workOrder.vehicle.plate_number || 'Госномер не указан' }}</p>
                            </div>
                        </div>
                        <div v-if="workOrder.mileage" class="pt-3 border-t border-gray-100 dark:border-gray-700/50">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Пробег при заезде</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">{{ workOrder.mileage }} км</p>
                        </div>
                    </div>
                    <div v-else class="p-6 text-sm text-gray-500 text-center">
                        Автомобиль не привязан
                    </div>
                </div>

                <!-- Кастомные поля (EAV) -->
                <div v-if="customFieldsData && customFieldsData.length > 0" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Дополнительная информация</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div v-for="field in customFieldsData" :key="field.definition.id">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">{{ getLocalizedLabel(field.definition.label) }}</p>
                            <p class="text-sm font-medium text-gray-800 dark:text-gray-200">
                                <template v-if="field.definition.type === 'checkbox'">
                                    {{ field.value == '1' ? 'Да' : 'Нет' }}
                                </template>
                                <template v-else>
                                    {{ field.value || '—' }}
                                </template>
                            </p>
                        </div>
                    </div>
                </div>
            </CollapsiblePanel>

            <!-- Центральная колонка: Работы и Таймлайн -->
            <div class="w-full lg:flex-1 lg:min-w-0 space-y-6">
                
                <!-- Вкладки (Работы / Комментарии / История / Документы) -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col min-h-[400px]">
                    <div class="flex flex-wrap gap-x-6 gap-y-1 border-b border-gray-200 dark:border-gray-700 px-6 bg-gray-50/50 dark:bg-gray-800/50">
                        <button @click="activeMainTab = 'items'" :class="[activeMainTab === 'items' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-tools-line"></i> Работы и Материалы
                        </button>
                        <button @click="openPayrollTab" :class="[activeMainTab === 'payroll' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-team-line"></i> Зарплата
                        </button>
                        <button @click="activeMainTab = 'comments'" :class="[activeMainTab === 'comments' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-chat-3-line"></i> Комментарии
                            <span v-if="comments.length > 0" class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-primary/10 text-primary text-[10px] font-bold">{{ comments.length }}</span>
                        </button>
                        <button @click="activeMainTab = 'history'" :class="[activeMainTab === 'history' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-history-line"></i> История
                        </button>
                        <button @click="activeMainTab = 'documents'" :class="[activeMainTab === 'documents' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-file-text-line"></i> Документы
                            <span v-if="workOrder.documents && workOrder.documents.length > 0" class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-primary/10 text-primary text-[10px] font-bold">{{ workOrder.documents.length }}</span>
                        </button>
                    </div>

                    <div v-if="activeMainTab === 'items'" class="flex-1 flex flex-col">
                        <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-white dark:bg-[#313a46]">
                            <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Позиции заказа</h3>
                            <div class="flex gap-2">
                                <button v-if="workOrder.status !== 'completed'" @click="isBatchDrawerOpen = true" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                                    <i class="ri-add-line"></i> Добавить / Редактировать
                                </button>
                                <button v-if="workOrder.status !== 'completed'" @click="openItemModal" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm gap-1.5">
                                    <i class="ri-search-line"></i> Быстрый поиск
                                </button>
                                <Dropdown
                                    v-if="workOrder.status !== 'completed' && workOrder.items && workOrder.items.length > 1"
                                    align="right"
                                    width="80"
                                    content-classes="w-80 py-1 bg-white dark:bg-gray-800 divide-y divide-gray-100 dark:divide-gray-700"
                                >
                                    <template #trigger>
                                        <button
                                            title="Упорядочить позиции"
                                            class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 shadow-sm gap-1.5"
                                        >
                                            <i class="ri-sort-desc"></i> Упорядочить
                                        </button>
                                    </template>
                                    <template #content>
                                        <button
                                            type="button"
                                            @click="autoSortItems('grouped')"
                                            class="block w-full px-4 py-2.5 text-start text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                        >
                                            <div class="font-medium">Сначала услуги, затем материалы</div>
                                            <div class="text-[11px] text-gray-400 mt-0.5">Все услуги — одним блоком сверху, товары и материалы — снизу</div>
                                        </button>
                                        <button
                                            type="button"
                                            @click="autoSortItems('nested')"
                                            class="block w-full px-4 py-2.5 text-start text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                        >
                                            <div class="font-medium">Материалы после своей услуги</div>
                                            <div class="text-[11px] text-gray-400 mt-0.5">После каждой услуги сразу идут прикреплённые к ней материалы, затем следующая услуга</div>
                                        </button>
                                    </template>
                                </Dropdown>
                            </div>
                        </div>
                        
                        <div class="overflow-x-auto w-full flex-1">
                            <table class="min-w-full text-left">
                                <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                    <tr>
                                        <th class="py-3 px-2 border-b border-gray-200 dark:border-gray-700 w-8"></th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Наименование</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Исполнитель</th>
                                        <th class="py-3 px-3 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Кол-во</th>
                                        <th class="py-3 px-3 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Цена</th>
                                        <th class="py-3 px-3 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Скидка</th>
                                        <th class="py-3 px-3 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Сумма</th>
                                        <th class="py-3 px-3 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right w-16"></th>
                                    </tr>
                                </thead>
                                <tbody v-if="!workOrder.items || workOrder.items.length === 0" class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr>
                                        <td colspan="8" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                            В заказ-наряд еще не добавлены услуги или товары. Нажмите "Добавить / Редактировать" или "Быстрый поиск".
                                        </td>
                                    </tr>
                                </tbody>
                                <draggable
                                    v-else
                                    v-model="sortedItems"
                                    tag="tbody"
                                    item-key="id"
                                    class="divide-y divide-gray-200 dark:divide-gray-700"
                                    handle=".item-drag-handle"
                                    :disabled="workOrder.status === 'completed'"
                                    @end="onItemsReordered"
                                >
                                    <template #item="{ element: item }">
                                        <tr class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                            <td class="py-3 px-2 text-center">
                                                <i v-if="workOrder.status !== 'completed'" class="ri-draggable item-drag-handle text-gray-400 cursor-grab active:cursor-grabbing" title="Перетащить для сортировки"></i>
                                            </td>
                                            <td class="py-3 px-3 text-sm font-medium text-gray-800 dark:text-gray-200" :class="item.linked_item_id ? 'pl-8' : ''">
                                                <div>{{ item.name }}</div>
                                                <div v-if="item.linked_item_id" class="text-[11px] text-gray-400 font-normal mt-0.5">к услуге «{{ parentItemName(item) }}»</div>
                                                <div class="mt-1">
                                                    <span v-if="item.linked_item_id" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400 uppercase tracking-wider"><i class="ri-flask-line"></i> Материал<span v-if="!item.is_billable" class="normal-case font-normal opacity-70"> · скрыт</span></span>
                                                    <span v-else-if="item.itemable_type.includes('Service')" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400 uppercase tracking-wider"><i class="ri-tools-line"></i> Услуга</span>
                                                    <span v-else class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400 uppercase tracking-wider"><i class="ri-box-3-line"></i> Товар</span>
                                                </div>
                                            </td>
                                            <td class="py-3 px-3 text-sm">
                                                <!-- Исполнители позиции: штатные ИЛИ подрядчики, смешивать нельзя (только для услуг) -->
                                                <AssigneeMultiSelect
                                                    v-if="item.itemable_type.includes('Service')"
                                                    class="max-w-[160px]"
                                                    :employee-ids="(item.employees || []).map(e => e.id)"
                                                    :contractor-ids="(item.contractors || []).map(c => c.id)"
                                                    :employees="employees"
                                                    :contractors="contractors"
                                                    :disabled="workOrder.status === 'completed'"
                                                    @update="payload => updateItemAssignees(item, payload)"
                                                />
                                                <span v-else class="text-xs text-gray-400 font-medium">Складское списание</span>
                                            </td>
                                            <td class="py-3 px-3 text-sm text-gray-800 dark:text-gray-200 text-right">{{ parseFloat(item.quantity) }}</td>
                                            <td class="py-3 px-3 text-sm text-gray-800 dark:text-gray-200 text-right whitespace-nowrap">{{ formatMoney(item.price) }}</td>
                                            <td class="py-3 px-3 text-sm text-right whitespace-nowrap">
                                                <span v-if="item.discount_amount > 0" class="text-danger font-medium">- {{ formatMoney(item.discount_amount) }} <span class="text-gray-400 font-normal">({{ itemDiscountPercent(item) }}%)</span></span>
                                                <span v-else class="text-gray-400">—</span>
                                            </td>
                                            <td class="py-3 px-3 text-sm font-bold text-gray-800 dark:text-gray-200 text-right whitespace-nowrap">{{ formatMoney(item.total) }}</td>
                                            <td class="py-3 px-3 text-sm text-right whitespace-nowrap">
                                                <button v-if="item.itemable_type.includes('Service') && workOrder.status !== 'completed'" @click="openPayoutModal(item)" title="Настроить выплаты" class="text-gray-400 hover:text-primary transition-colors p-1"><i class="ri-team-line text-lg"></i></button>
                                                <button v-if="item.itemable_type.includes('Service') && workOrder.status !== 'completed'" @click="openAddMaterialModal(item)" title="Добавить материал к услуге" class="text-gray-400 hover:text-primary transition-colors p-1"><i class="ri-flask-line text-lg"></i></button>
                                                <button v-if="canConfigureItemStock(item) && workOrder.status !== 'completed'" @click="openMaterialSettingsModal(item)" :title="item.linked_item_id ? 'Настройки материала' : 'Настройки списания'" class="text-gray-400 hover:text-primary transition-colors p-1"><i class="ri-settings-3-line text-lg"></i></button>
                                                <button v-if="workOrder.status !== 'completed'" @click="deleteItem(item)" class="text-danger hover:text-danger-600 transition-colors p-1"><i class="ri-delete-bin-line text-lg"></i></button>
                                            </td>
                                        </tr>
                                    </template>
                                </draggable>
                            </table>
                        </div>
                        
                        <!-- Итоги -->
                        <div class="bg-gray-50/50 dark:bg-gray-800/30 p-6 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                            <div class="w-full sm:w-1/2 lg:w-1/3 space-y-3">
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">Сумма позиций:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ formatMoney(workOrder.total_amount) }}</span>
                                </div>
                                <div v-if="itemsDiscountTotal > 0" class="flex justify-between items-center text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">Скидка по позициям:</span>
                                    <span class="font-medium text-danger">- {{ formatMoney(itemsDiscountTotal) }}</span>
                                </div>
                                <div class="flex justify-between items-center text-sm group">
                                    <span class="text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                        Скидка на заказ:
                                        <button v-if="workOrder.status !== 'completed'" @click="openDiscountModal" class="text-primary hover:text-primary-600 opacity-0 group-hover:opacity-100 transition-opacity"><i class="ri-pencil-line"></i></button>
                                    </span>
                                    <span class="font-medium text-danger">- {{ formatMoney(workOrder.discount_amount) }} <span class="text-gray-400 font-normal">({{ orderDiscountPercent }}%)</span></span>
                                </div>
                                <div v-if="workOrder.vat_rate !== null" class="flex justify-between items-center text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">{{ workOrder.vat_calculation_method === 'exclusive' ? `НДС ${workOrder.vat_rate}% сверху:` : `в т.ч. НДС ${workOrder.vat_rate}%:` }}</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200">{{ formatMoney(workOrder.vat_amount) }}</span>
                                </div>
                                <div class="pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                                    <span class="font-bold text-gray-800 dark:text-gray-200 text-base">Итого к оплате:</span>
                                    <span class="text-xl font-bold text-success">{{ formatMoney(workOrder.final_amount) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div v-if="activeMainTab === 'comments'" class="flex-1 flex flex-col min-h-0">
                        <ActivityTimeline :activities="comments" :comment-url="route('operations.work-orders.comment', workOrder.id)" />
                    </div>

                    <div v-if="activeMainTab === 'history'" class="flex-1 flex flex-col min-h-0">
                        <ActivityTimeline :activities="activities" />
                    </div>

                    <div v-if="activeMainTab === 'documents'" class="flex-1 flex flex-col min-h-0">
                        <div v-if="legalEntityAmbiguousForDocuments" class="p-3 bg-warning/10 border-b border-warning/20 text-xs text-gray-700 dark:text-gray-300 flex items-start gap-1.5">
                            <i class="ri-error-warning-line text-warning shrink-0 mt-0.5"></i>
                            <span>Юрлицо не выбрано, а у локации их несколько — система не может определить, от чьего имени формировать документ. Новые документы получат временный номер без сквозной нумерации и пустые реквизиты. Выберите юрлицо в <button type="button" @click="openModal" class="text-primary hover:underline font-medium">Форме</button> заказа.</span>
                        </div>
                        <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46] flex items-center gap-2">
                            <select v-if="documentTemplates.length > 0" v-model="selectedDocumentTemplateId" class="block w-64 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option v-for="t in documentTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                            </select>
                            <button v-if="documentTemplates.length > 0" @click="generateDocument" :disabled="generatingDocument" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm disabled:opacity-50">
                                <i class="ri-file-add-line"></i> Сформировать документ
                            </button>
                            <p v-else class="text-sm text-gray-400">Нет активных шаблонов документов для заказов — настройте их в Настройках → Шаблоны документов.</p>
                        </div>
                        <div class="flex-1 overflow-auto custom-scrollbar">
                            <table v-if="workOrder.documents && workOrder.documents.length > 0" class="min-w-full text-left">
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="doc in workOrder.documents" :key="doc.id" :class="[doc.superseded_by_document_id ? 'opacity-50' : '', 'odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors']">
                                        <td class="py-3 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200">
                                            {{ doc.number }}
                                            <span v-if="doc.superseded_by_document_id" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400 ml-1" :title="`Заменён документом №${doc.superseded_by?.number ?? ''}`">заменён</span>
                                            <i v-else-if="doc.is_stale" class="ri-error-warning-line text-warning ml-1" title="Данные заказа изменились с момента формирования — рекомендуем обновить документ"></i>
                                        </td>
                                        <td class="py-3 px-6 text-sm text-gray-600 dark:text-gray-300">{{ doc.title }}</td>
                                        <td class="py-3 px-6 text-sm text-gray-400">{{ new Date(doc.created_at).toLocaleDateString('ru-RU') }}</td>
                                        <td class="py-3 px-6 text-sm text-right space-x-1 whitespace-nowrap">
                                            <template v-if="doc.is_stale && !doc.superseded_by_document_id">
                                                <button @click="regenerateAsNew(doc)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-warning/10 text-warning hover:bg-warning hover:text-white" title="Сформировать новый документ (этот сохранится в истории)"><i class="ri-file-add-line"></i></button>
                                                <button @click="replaceDocument(doc)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-warning/10 text-warning hover:bg-warning hover:text-white" title="Заменить этот документ актуальными данными (номер тот же)"><i class="ri-refresh-line"></i></button>
                                            </template>
                                            <a :href="route('documents.print', doc.id)" target="_blank" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Печать"><i class="ri-printer-line"></i></a>
                                            <a :href="route('documents.download', doc.id)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Скачать PDF"><i class="ri-download-2-line"></i></a>
                                            <button @click="deleteDocument(doc)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить"><i class="ri-delete-bin-line"></i></button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-else class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">Документов по этому заказу ещё нет.</div>
                        </div>
                    </div>

                    <div v-if="activeMainTab === 'payroll'" class="flex-1 flex flex-col min-h-0">
                        <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-info/5">
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                <i class="ri-information-line text-info"></i>
                                Предварительный расчёт по текущим ставкам и составу бригады — пересчитывается каждый раз при открытии вкладки. Итоговое начисление создаётся при переводе заказа в статус «Завершён» и может отличаться, если до этого момента изменится состав исполнителей, цены или ставки.
                            </p>
                        </div>

                        <div v-if="payrollPreviewLoading" class="flex-1 flex items-center justify-center text-sm text-gray-400 py-12">
                            <i class="ri-loader-4-line animate-spin mr-2"></i> Считаем...
                        </div>

                        <div v-else-if="payrollPreview" class="flex-1 overflow-y-auto custom-scrollbar p-6 space-y-5">
                            <div v-if="payrollPreview.items.length === 0" class="text-center text-sm text-gray-400 py-8">
                                В заказе нет услуг с назначенными исполнителями/администратором.
                            </div>

                            <div v-for="item in payrollPreview.items" :key="item.item_id" class="border border-gray-200 dark:border-gray-700 rounded-md overflow-hidden">
                                <div class="px-4 py-2.5 bg-gray-50/50 dark:bg-gray-800/50 border-b border-gray-200 dark:border-gray-700">
                                    <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ item.item_name }}</h4>
                                </div>
                                <div class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                    <div v-if="item.admin" class="flex justify-between items-center px-4 py-2.5 bg-primary/5">
                                        <div class="flex items-center gap-2 text-sm">
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-primary/10 text-primary">Админ</span>
                                            <span class="text-gray-700 dark:text-gray-300">{{ item.admin.name }}</span>
                                        </div>
                                        <span class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(item.admin.amount) }}</span>
                                    </div>
                                    <!-- key составной: у подрядчика employee_id пуст, и по одному
                                         только employee_id все подрядчики позиции слились бы в одну строку -->
                                    <div v-for="w in item.workers" :key="`${w.employee_id || 'c'}-${w.client_id || ''}`" class="flex justify-between items-center px-4 py-2.5">
                                        <div class="flex items-center gap-2 text-sm">
                                            <span v-if="w.type === 'contractor'" class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400"><i class="ri-briefcase-line"></i> Подрядчик</span>
                                            <span v-else-if="w.type === 'self_employed'" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">Самозанятый</span>
                                            <span v-else class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase bg-success/10 text-success">Исполнитель</span>
                                            <span class="text-gray-700 dark:text-gray-300">{{ w.name }}</span>
                                        </div>
                                        <span class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(w.amount) }}</span>
                                    </div>
                                </div>
                            </div>

                            <div v-if="payrollPreview.skipped.length > 0" class="p-4 rounded-md bg-warning/10 border border-warning/20 text-sm text-gray-700 dark:text-gray-300">
                                <p class="font-bold text-warning flex items-center gap-1.5 mb-1.5"><i class="ri-error-warning-line"></i> Не рассчитано (нет настроенной ставки):</p>
                                <ul class="list-disc list-inside space-y-0.5 text-xs text-gray-500 dark:text-gray-400">
                                    <li v-for="(reason, idx) in payrollPreview.skipped" :key="idx">{{ reason }}</li>
                                </ul>
                            </div>

                            <div v-if="payrollPreview.items.length > 0" class="pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                                <span class="font-bold text-gray-800 dark:text-gray-200">Итого к начислению:</span>
                                <span class="text-lg font-bold text-primary">{{ formatMoney(payrollPreview.total) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Правая колонка: Финансы -->
            <CollapsiblePanel storage-key="show-card-right" side="right">

                <!-- Запись, из которой создан заказ (или запись того же клиента, которую можно привязать) -->
                <div v-if="linkedAppointment || candidateAppointment" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Запись</h3>
                        <span v-if="linkedAppointment" class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold tracking-wide uppercase bg-success/10 text-success">Привязана</span>
                        <span v-else class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold tracking-wide uppercase bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Не привязана</span>
                    </div>
                    <div class="p-6 space-y-4">
                        <template v-if="linkedAppointment">
                            <p class="text-sm text-gray-600 dark:text-gray-400">Этот заказ-наряд создан из записи в календаре на <strong class="text-gray-800 dark:text-gray-200">{{ linkedAppointment.start_at_local }}</strong>.</p>
                            <button
                                @click="openAppointment(linkedAppointment.id)"
                                class="w-full inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-semibold transition-all duration-300 bg-primary text-white hover:bg-primary-600 shadow-sm"
                            >
                                <i class="ri-calendar-check-line"></i> Открыть запись
                            </button>
                        </template>
                        <template v-else-if="candidateAppointment">
                            <p class="text-sm text-gray-600 dark:text-gray-400">У этого клиента есть незакрытая запись в календаре на <strong class="text-gray-800 dark:text-gray-200">{{ candidateAppointment.start_at_local }}</strong>, не привязанная ни к одному заказу.</p>
                            <button
                                @click="linkCandidateAppointment"
                                class="w-full inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-semibold transition-all duration-300 bg-info text-white hover:bg-info/80 shadow-sm"
                            >
                                <i class="ri-link"></i> Привязать к этому заказу
                            </button>
                        </template>
                    </div>
                </div>

                <!-- Администратор заказа (Фаза 10.1): по умолчанию — для расчёта ЗП по каждой позиции; переопределяется на уровне отдельной услуги через модалку выплат -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Администраторы заказа</h3>
                        <span :class="[workOrder.admin_assignment_mode === 'auto' ? 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' : 'bg-primary/10 text-primary', 'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold tracking-wide uppercase']">
                            {{ workOrder.admin_assignment_mode === 'auto' ? 'Авто' : 'Вручную' }}
                        </span>
                    </div>
                    <div class="p-6 space-y-3">
                        <EmployeeMultiSelect
                            :model-value="(workOrder.admins || []).map(e => e.id)"
                            :options="adminEligibleEmployees"
                            :disabled="workOrder.status === 'completed'"
                            @update:model-value="updateOrderAdmins"
                        />
                        <p v-if="workOrder.status === 'completed'" class="text-xs text-warning">Заказ выдан — состав администраторов зафиксирован, изменение недоступно.</p>
                        <p v-else class="text-xs text-gray-400">Может быть несколько — по умолчанию делят ЗП поровну на каждой услуге заказа. ЗП считается по каждой услуге заказа. Для отдельной услуги можно назначить других администраторов, настроить доли или убрать их — клик на позицию в таблице услуг.</p>
                    </div>
                </div>

                <!-- Финансовый блок -->
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Финансы</h3>
                        <span :class="[paymentStatuses[workOrder.payment_status]?.class || 'bg-gray-100 text-gray-700', 'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold tracking-wide uppercase']">
                            {{ paymentStatuses[workOrder.payment_status]?.label || workOrder.payment_status }}
                        </span>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Сумма по прайсу:</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ formatMoney(workOrder.total_amount) }}</span>
                        </div>
                        <div v-if="itemsDiscountTotal > 0" class="flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Скидка по позициям:</span>
                            <span class="font-medium text-danger">- {{ formatMoney(itemsDiscountTotal) }}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm group">
                            <span class="text-gray-500 dark:text-gray-400 flex items-center gap-1">
                                Скидка на заказ:
                                <button v-if="workOrder.status !== 'completed'" @click="openDiscountModal" class="text-primary hover:text-primary-600 opacity-0 group-hover:opacity-100 transition-opacity"><i class="ri-pencil-line"></i></button>
                            </span>
                            <span class="font-medium text-danger">- {{ formatMoney(workOrder.discount_amount) }} <span class="text-gray-400 font-normal">({{ orderDiscountPercent }}%)</span></span>
                        </div>
                        <div v-if="workOrder.vat_rate !== null" class="flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">{{ workOrder.vat_calculation_method === 'exclusive' ? `НДС ${workOrder.vat_rate}% сверху:` : `в т.ч. НДС ${workOrder.vat_rate}%:` }}</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ formatMoney(workOrder.vat_amount) }}</span>
                        </div>
                        <div class="pt-4 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                            <span class="font-bold text-gray-800 dark:text-gray-200">К оплате:</span>
                            <span class="text-xl font-bold text-success">{{ formatMoney(workOrder.final_amount) }}</span>
                        </div>

                        <div class="pt-4 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                            <span class="text-sm text-gray-500 dark:text-gray-400">Оплачено:</span>
                            <span class="text-sm font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(workOrder.final_amount - remainingAmount) }}</span>
                        </div>

                        <button v-if="remainingAmount > 0" @click="openPaymentModal" class="w-full mt-4 inline-flex items-center justify-center rounded-md px-4 py-2 text-sm font-semibold transition-all duration-300 bg-success text-white hover:bg-success-600 shadow-sm">
                            <i class="ri-bank-card-line mr-2"></i> Принять оплату
                        </button>
                    </div>
                </div>

                <!-- История транзакций -->
                <div v-if="paymentTransactions.length > 0" class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">История оплат</h3>
                    </div>
                    <div class="p-6 space-y-3">
                        <Link
                            v-for="tx in paymentTransactions"
                            :key="tx.id"
                            :href="transactionLink(tx)"
                            class="flex justify-between items-start border-b border-gray-100 dark:border-gray-700/50 pb-3 last:border-0 last:pb-0 group hover:bg-gray-50/50 dark:hover:bg-gray-800/30 -mx-2 px-2 rounded transition-colors"
                        >
                            <div>
                                <p class="text-sm font-medium text-gray-800 dark:text-gray-200 group-hover:text-primary transition-colors">{{ tx.account ? tx.account.name : 'Касса' }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ new Date(tx.created_at).toLocaleString('ru-RU', {day: 'numeric', month: 'short', hour: '2-digit', minute:'2-digit'}) }}</p>
                            </div>
                            <span class="text-sm font-bold text-success">+ {{ formatMoney(tx.amount) }}</span>
                        </Link>
                    </div>
                </div>

            </CollapsiblePanel>

        </div>

        <!-- TRI-STATE 1: Двухколоночный слайдер пакетного выбора (Drawer Offcanvas maxWidth="75vw") -->
        <Offcanvas :show="isBatchDrawerOpen" @close="isBatchDrawerOpen = false" maxWidth="75vw">
            <div class="flex flex-col h-full bg-white dark:bg-[#313a46]">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <div class="flex items-center gap-2.5">
                        <i class="ri-shopping-basket-2-line text-primary text-xl"></i>
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Пакетный набор услуг и товаров</h3>
                    </div>
                    <button @click="isBatchDrawerOpen = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="ri-close-line text-2xl"></i></button>
                </div>

                <!-- Быстрый поиск и Фильтры -->
                <div class="p-4 border-b border-gray-100 dark:border-gray-700/80 bg-white dark:bg-gray-800 flex gap-2">
                    <div class="relative flex-1">
                        <input v-model="drawerSearch" type="text" placeholder="Поиск по названию услуги или товара..." class="block w-full pl-9 pr-4 py-1.5 border border-gray-200 dark:border-gray-700 rounded-md text-sm leading-5 bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:outline-none focus:ring-1 focus:ring-primary focus:border-primary transition-colors" />
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="ri-search-line text-gray-400"></i>
                        </div>
                    </div>
                    <div v-if="drawerTab === 'services'" class="w-48 shrink-0">
                        <select v-model="selectedDirectionId" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                            <option value="">Все направления</option>
                            <option v-for="dir in businessDirections" :key="dir.id" :value="dir.id">{{ dir.name }}</option>
                        </select>
                    </div>
                    <div v-if="drawerTab === 'products' && warehouseEnabled && warehouses.length > 1" class="w-48 shrink-0">
                        <select v-model="catalogWarehouseFilter" title="Показывать только остаток на выбранном складе" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                            <option value="">Все склады</option>
                            <option v-for="wh in warehouses" :key="wh.id" :value="wh.id">{{ wh.name }}</option>
                        </select>
                    </div>
                </div>

                <!-- Вкладки Услуги / Товары -->
                <div class="flex space-x-6 border-b border-gray-200 dark:border-gray-700 px-6 bg-gray-50/30 dark:bg-gray-800/10">
                    <button @click="drawerTab = 'services'" :class="[drawerTab === 'services' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3 px-1 text-sm transition-colors focus:outline-none']">
                        <i class="ri-tools-line mr-1"></i> Услуги (Прайс-лист)
                    </button>
                    <button @click="drawerTab = 'products'" :class="[drawerTab === 'products' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3 px-1 text-sm transition-colors focus:outline-none']">
                        <i class="ri-box-3-line mr-1"></i> Товары и Расходники
                    </button>
                </div>

                <!-- Toast уведомление прямо внутри слайдера при добавлении -->
                <div v-if="showToast" class="m-4 p-3 bg-success/10 border border-success/20 rounded text-success text-xs font-semibold flex items-center gap-1.5 animate-fade-in shadow-sm">
                    <i class="ri-checkbox-circle-fill"></i> {{ addedToastMessage }}
                </div>

                <!-- Двухколоночный сплит внутри слайдера -->
                <div class="flex-1 overflow-hidden grid grid-cols-12 gap-0">
                    
                    <!-- Левая часть (4 столбца / 1/3): Каталог с подсвечиванием добавленных -->
                    <div class="col-span-12 lg:col-span-4 border-r border-gray-200 dark:border-gray-700 overflow-y-auto p-4 space-y-5 custom-scrollbar">
                        
                        <!-- Рендеринг Услуг -->
                        <template v-if="drawerTab === 'services'">
                            <div v-for="group in groupedDrawerServices" :key="'g_srv_' + group.id" class="space-y-2">
                                <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2 flex items-center gap-1">
                                    <i class="ri-folder-open-line"></i> {{ group.name }}
                                </h4>
                                <div class="space-y-1.5">
                                    <div v-for="srv in group.items" :key="srv.id" class="flex justify-between items-center p-3 border border-gray-100 dark:border-gray-700/50 rounded-md bg-gray-50/50 dark:bg-gray-800/10 hover:border-primary/20 transition-all group">
                                        <div class="pr-2">
                                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(srv.name) }}</p>
                                            <p class="text-xs text-gray-500 font-medium mt-0.5 flex items-center gap-2">
                                                <span><i class="ri-time-line"></i> {{ srv.duration_minutes }} мин</span>
                                                <span>•</span>
                                                <span class="text-primary font-semibold">
                                                    <template v-if="pricingBasis === 'vehicle_body' && workOrder.vehicle?.vehicle_model?.body_type && srv.prices?.[workOrder.vehicle.vehicle_model.body_type]">
                                                        {{ formatMoney(srv.prices[workOrder.vehicle.vehicle_model.body_type]) }}
                                                    </template>
                                                    <template v-else-if="pricingBasis === 'vehicle_class' && workOrder.vehicle?.vehicle_model?.category && srv.prices?.[workOrder.vehicle.vehicle_model.category]">
                                                        {{ formatMoney(srv.prices[workOrder.vehicle.vehicle_model.category]) }}
                                                    </template>
                                                    <template v-else>
                                                        {{ formatMoney(srv.price) }}
                                                    </template>
                                                </span>
                                            </p>
                                        </div>
                                        <button 
                                            @click="addItemDirect('service', srv)" 
                                            :class="[
                                                getItemCountInOrder('service', srv.id) > 0 
                                                    ? 'bg-success text-white hover:bg-success-600' 
                                                    : 'bg-primary/10 text-primary hover:bg-primary hover:text-white',
                                                'inline-flex items-center justify-center rounded px-2.5 py-1.5 text-xs font-bold transition-all shadow-sm gap-1 shrink-0'
                                            ]"
                                        >
                                            <i class="ri-add-line"></i>
                                            <span v-if="getItemCountInOrder('service', srv.id) > 0">В заказе ({{ getItemCountInOrder('service', srv.id) }}) +</span>
                                            <span v-else>Добавить</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div v-if="groupedDrawerServices.length === 0" class="text-center py-12 text-sm text-gray-500">Услуги не найдены.</div>
                        </template>

                        <!-- Рендеринг Товаров -->
                        <template v-else>
                            <div v-for="group in groupedDrawerProducts" :key="'g_prd_' + group.id" class="space-y-2">
                                <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2 flex items-center gap-1">
                                    <i class="ri-folder-open-line"></i> {{ group.name }}
                                </h4>
                                <div class="space-y-1.5">
                                    <div v-for="prd in group.items" :key="prd.id" class="flex justify-between items-center p-3 border border-gray-100 dark:border-gray-700/50 rounded-md bg-gray-50/50 dark:bg-gray-800/10 hover:border-primary/20 transition-all group">
                                        <div class="pr-2">
                                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ getLocalizedLabel(prd.name) }}</p>
                                            <p class="text-xs text-gray-500 font-medium mt-0.5 flex items-center gap-1.5">
                                                <span class="font-mono">{{ prd.sku || 'Без артикула' }}</span>
                                                <span>•</span>
                                                <span>Ед: {{ prd.unit }}</span>
                                            </p>
                                        </div>
                                        <button 
                                            @click="addItemDirect('product', prd)" 
                                            :class="[
                                                getItemCountInOrder('product', prd.id) > 0 
                                                    ? 'bg-success text-white hover:bg-success-600' 
                                                    : 'bg-primary/10 text-primary hover:bg-primary hover:text-white',
                                                'inline-flex items-center justify-center rounded px-2.5 py-1.5 text-xs font-bold transition-all shadow-sm gap-1 shrink-0'
                                            ]"
                                        >
                                            <i class="ri-add-line"></i>
                                            <span v-if="getItemCountInOrder('product', prd.id) > 0">В заказе ({{ getItemCountInOrder('product', prd.id) }}) +</span>
                                            <span v-else>Добавить</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div v-if="groupedDrawerProducts.length === 0" class="text-center py-12 text-sm text-gray-500">Товары не найдены.</div>
                        </template>

                    </div>

                    <!-- Правая часть (8 столбцов / 2/3): Живая корзина состава заказа -->
                    <div class="col-span-12 lg:col-span-8 bg-gray-50/50 dark:bg-gray-800/30 flex flex-col h-full overflow-hidden">
                        <div class="p-3 border-b border-gray-200 dark:border-gray-700 bg-gray-100/50 dark:bg-gray-800/50 flex justify-between items-center gap-3 flex-wrap">
                            <div class="flex items-center gap-3 flex-wrap">
                                <span class="text-xs font-bold text-gray-700 dark:text-gray-300 uppercase tracking-wider">Состав заказа ({{ workOrder.items?.length || 0 }})</span>
                                <div class="inline-flex rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden text-[11px] font-semibold">
                                    <button
                                        type="button"
                                        @click="cartFilter = 'all'"
                                        :class="cartFilter === 'all' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                        class="px-2.5 py-1 transition-colors"
                                    >Все ({{ workOrder.items?.length || 0 }})</button>
                                    <button
                                        type="button"
                                        @click="cartFilter = 'service'"
                                        :class="cartFilter === 'service' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                        class="px-2.5 py-1 border-l border-gray-200 dark:border-gray-700 transition-colors"
                                    >Услуги ({{ cartServiceCount }})</button>
                                    <button
                                        type="button"
                                        @click="cartFilter = 'product'"
                                        :class="cartFilter === 'product' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700'"
                                        class="px-2.5 py-1 border-l border-gray-200 dark:border-gray-700 transition-colors"
                                    >Товары ({{ cartProductCount }})</button>
                                </div>
                            </div>
                            <span class="text-xs font-bold text-primary">{{ formatMoney(workOrder.final_amount) }}</span>
                        </div>

                        <!-- Список добавленных позиций с прямым редактированием -->
                        <div v-if="filteredCartItems.length === 0" class="flex-1 flex items-center justify-center text-sm text-gray-500 dark:text-gray-400 p-6 text-center">
                            {{ cartFilter === 'service' ? 'В заказе нет услуг' : cartFilter === 'product' ? 'В заказе нет товаров и материалов' : 'В заказ ещё ничего не добавлено' }}
                        </div>
                        <div v-else class="flex-1 overflow-y-auto p-3 space-y-2.5 custom-scrollbar">
                            <div
                                v-for="item in filteredCartItems"
                                :key="item.id"
                                :class="[item.linked_item_id ? 'ml-4 border-dashed opacity-90' : '', 'p-3 bg-white dark:bg-[#313a46] border border-gray-200 dark:border-gray-700 rounded-md shadow-sm space-y-2.5']"
                            >
                                <!-- Наименование, количество и сумма — в одну строку -->
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-gray-800 dark:text-gray-200 leading-tight truncate" :title="item.name">{{ item.name }}</p>
                                        <p v-if="item.linked_item_id" class="text-[11px] text-gray-400 leading-tight truncate" :title="parentItemName(item)">к услуге «{{ parentItemName(item) }}»</p>
                                        <span v-if="item.linked_item_id" class="text-[11px] text-purple-600 font-semibold uppercase inline-flex items-center gap-1"><i class="ri-flask-line"></i> Материал<span v-if="!item.is_billable" class="text-gray-400 normal-case font-normal"> · скрыт от клиента</span></span>
                                        <span v-else-if="item.itemable_type.includes('Service')" class="text-[11px] text-blue-600 font-semibold uppercase">Услуга</span>
                                        <span v-else class="text-[11px] text-orange-600 font-semibold uppercase">Товар</span>
                                    </div>
                                    <div class="flex items-center gap-1.5 shrink-0">
                                        <input
                                            type="number"
                                            step="any"
                                            min="0.001"
                                            :value="parseFloat(item.quantity)"
                                            @change="e => updateItemDetails(item, { quantity: e.target.value })"
                                            title="Количество"
                                            class="w-14 rounded border-gray-200 dark:border-gray-700 bg-transparent py-1 px-1.5 text-sm font-bold text-gray-800 dark:text-gray-200 text-center focus:border-primary focus:ring-0"
                                        />
                                        <span class="text-gray-400">×</span>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            :value="item.price / 100"
                                            @change="e => updateItemDetails(item, { price: e.target.value })"
                                            title="Цена за ед."
                                            class="w-20 rounded border-gray-200 dark:border-gray-700 bg-transparent py-1 px-1.5 text-sm font-bold text-gray-800 dark:text-gray-200 text-right focus:border-primary focus:ring-0"
                                        />
                                        <span class="text-gray-400">=</span>
                                        <span class="text-sm font-bold text-primary w-20 text-right">{{ formatMoney(item.total) }}</span>
                                        <button v-if="item.itemable_type.includes('Service') && workOrder.status !== 'completed'" @click="openPayoutModal(item)" class="text-gray-400 hover:text-primary p-1 shrink-0" title="Настроить выплаты"><i class="ri-team-line text-lg"></i></button>
                                        <button v-if="item.itemable_type.includes('Service') && workOrder.status !== 'completed'" @click="openAddMaterialModal(item)" class="text-gray-400 hover:text-primary p-1 shrink-0" title="Добавить материал к услуге"><i class="ri-flask-line text-lg"></i></button>
                                        <button v-if="canConfigureItemStock(item) && workOrder.status !== 'completed'" @click="openMaterialSettingsModal(item)" class="text-gray-400 hover:text-primary p-1 shrink-0" :title="item.linked_item_id ? 'Настройки материала' : 'Настройки списания'"><i class="ri-settings-3-line text-lg"></i></button>
                                        <button @click="deleteItem(item)" class="text-danger hover:text-danger-600 p-1 shrink-0" title="Удалить"><i class="ri-delete-bin-line text-lg"></i></button>
                                    </div>
                                </div>

                                <!-- Исполнители (можно несколько) и скидка на позицию -->
                                <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-gray-100 dark:border-gray-700/50">
                                    <AssigneeMultiSelect
                                        v-if="item.itemable_type.includes('Service')"
                                        :employee-ids="(item.employees || []).map(e => e.id)"
                                        :contractor-ids="(item.contractors || []).map(c => c.id)"
                                        :employees="employees"
                                        :contractors="contractors"
                                        @update="payload => updateItemAssignees(item, payload)"
                                    />

                                    <div class="flex items-center gap-1.5 ml-auto" :title="hasOrderDiscount ? 'Скидка на позицию недоступна — на заказе уже установлена общая скидка. Уберите общую скидку, чтобы задать скидку на позицию.' : ''">
                                        <span class="text-[11px] text-gray-400 font-semibold">Скидка:</span>
                                        <div class="inline-flex rounded border border-gray-200 dark:border-gray-700 overflow-hidden shrink-0">
                                            <button type="button" :disabled="hasOrderDiscount" @click="setItemDiscountMode(item, 'amount')" :class="[getItemDiscountMode(item) === 'amount' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-700', 'px-1.5 py-1 text-[11px] font-bold transition-colors disabled:opacity-50 disabled:cursor-not-allowed']">₽</button>
                                            <button type="button" :disabled="hasOrderDiscount" @click="setItemDiscountMode(item, 'percent')" :class="[getItemDiscountMode(item) === 'percent' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-700', 'px-1.5 py-1 text-[11px] font-bold transition-colors border-l border-gray-200 dark:border-gray-700 disabled:opacity-50 disabled:cursor-not-allowed']">%</button>
                                        </div>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            :max="getItemDiscountMode(item) === 'percent' ? 100 : itemBaseRub(item)"
                                            :value="itemDiscountDisplayValue(item)"
                                            :disabled="hasOrderDiscount"
                                            @change="e => applyItemDiscount(item, e.target.value)"
                                            class="w-16 rounded border-gray-200 dark:border-gray-700 bg-transparent py-1 px-1.5 text-xs font-bold text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 disabled:opacity-50 disabled:cursor-not-allowed"
                                        />
                                    </div>
                                </div>
                                <p v-if="hasOrderDiscount" class="text-[11px] text-gray-400 flex items-center gap-1 pt-1">
                                    <i class="ri-information-line"></i>
                                    Скидка на позицию недоступна, пока на заказе установлена общая скидка.
                                </p>
                            </div>

                            <div v-if="!workOrder.items || workOrder.items.length === 0" class="text-center py-8 text-xs text-gray-400">
                                Корзина заказа пуста.<br>Нажмите "+ Добавить" слева.
                            </div>
                        </div>

                        <!-- Итоговый расчет и скидка внизу корзины -->
                        <div class="p-4 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-[#313a46] space-y-2">
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-gray-500">Сумма услуг:</span>
                                <span class="font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(workOrder.total_amount) }}</span>
                            </div>
                            <div v-if="itemsDiscountTotal > 0" class="flex justify-between items-center text-xs">
                                <span class="text-gray-500">Скидка по позициям:</span>
                                <span class="font-bold text-danger">- {{ formatMoney(itemsDiscountTotal) }}</span>
                            </div>
                            <div class="flex justify-between items-center text-xs">
                                <span class="text-gray-500">Скидка на заказ:</span>
                                <span class="font-bold text-danger">- {{ formatMoney(workOrder.discount_amount) }} <span class="text-gray-400 font-normal">({{ orderDiscountPercent }}%)</span></span>
                            </div>

                            <!-- Инлайн-редактирование скидки: сумма (₽) или процент (%). Взаимоисключает скидку по позициям (см. WorkOrderController::updateDiscount()). -->
                            <p v-if="hasItemDiscounts" class="text-[11px] text-danger flex items-start gap-1">
                                <i class="ri-error-warning-line mt-0.5"></i>
                                <span>Общая скидка на заказ недоступна — на одной из позиций уже задана индивидуальная скидка. Уберите её на позиции, чтобы задать общую.</span>
                            </p>
                            <div v-else class="flex items-center justify-end gap-1.5">
                                <div class="inline-flex rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden shrink-0">
                                    <button type="button" @click="setDrawerDiscountMode('amount')" :class="[drawerDiscountMode === 'amount' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-700', 'px-2 py-1 text-[11px] font-bold transition-colors']">₽</button>
                                    <button type="button" @click="setDrawerDiscountMode('percent')" :class="[drawerDiscountMode === 'percent' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-700', 'px-2 py-1 text-[11px] font-bold transition-colors border-l border-gray-200 dark:border-gray-700']">%</button>
                                </div>
                                <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :max="drawerDiscountMode === 'percent' ? 100 : drawerTotalRub"
                                    v-model="drawerDiscountValue"
                                    @keyup.enter="applyDrawerDiscount"
                                    class="shrink-0 w-24 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1 px-2 text-xs font-bold text-gray-800 dark:text-gray-200 text-right focus:border-primary focus:ring-0"
                                />
                                <button type="button" @click="applyDrawerDiscount" class="shrink-0 inline-flex items-center justify-center rounded px-2.5 py-1 text-[11px] font-bold bg-primary/10 text-primary hover:bg-primary hover:text-white transition-colors">Применить</button>
                            </div>

                            <div v-if="workOrder.vat_rate !== null" class="flex justify-between items-center text-xs">
                                <span class="text-gray-500">{{ workOrder.vat_calculation_method === 'exclusive' ? `НДС ${workOrder.vat_rate}% сверху:` : `в т.ч. НДС ${workOrder.vat_rate}%:` }}</span>
                                <span class="font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(workOrder.vat_amount) }}</span>
                            </div>

                            <div class="pt-2 border-t border-gray-100 dark:border-gray-700 flex justify-between items-center">
                                <span class="text-sm font-bold text-gray-800 dark:text-gray-200">Итого:</span>
                                <span class="text-lg font-bold text-success">{{ formatMoney(workOrder.final_amount) }}</span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Footer Drawer -->
                <div class="px-6 py-3 border-t border-gray-200 dark:border-gray-700 bg-gray-50/80 dark:bg-gray-800/80 flex gap-2.5">
                    <button v-if="drawerTab === 'services'" @click="openQuickServiceModal" title="Добавить услугу, которой нет в каталоге" class="flex-1 inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold transition-all bg-primary text-white hover:bg-primary-600 gap-1"><i class="ri-add-line"></i> Добавить услугу не из прайса</button>
                    <button v-else @click="openQuickProductModal" class="flex-1 inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold transition-all bg-primary text-white hover:bg-primary-600 gap-1"><i class="ri-add-line"></i> Быстрый товар</button>
                    <button @click="isBatchDrawerOpen = false" class="flex-1 inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-semibold transition-all bg-white border border-gray-300 text-gray-700 hover:bg-gray-50">Сохранить и закрыть</button>
                </div>
            </div>
        </Offcanvas>

        <!-- Модальное окно (Форма) (Ширина 3xl - увеличено в 1.5 раза) -->
        <Teleport to="body">
            <div v-if="isModalOpen" class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-3xl my-8 mx-auto flex flex-col">
                    <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                            Редактирование формы заказа
                        </h3>
                        <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                            <i class="ri-close-line text-xl"></i>
                        </button>
                    </div>
                    <form @submit.prevent="submit" class="flex flex-col">
                        <div class="p-6 space-y-4">
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div v-if="branches.length > 1">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Локация <span class="text-danger">*</span></label>
                                    <select
                                        v-model="form.branch_id"
                                        @change="onBranchChangedInForm"
                                        required
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                    >
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Выберите локацию...</option>
                                        <option v-for="branch in branches" :key="branch.id" :value="branch.id" class="bg-white dark:bg-gray-800">{{ branch.name }}</option>
                                    </select>
                                    <span v-if="form.errors.branch_id" class="text-xs text-danger mt-1">{{ form.errors.branch_id }}</span>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Статус <span class="text-danger">*</span></label>
                                    <select
                                        v-model="form.status"
                                        required
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                    >
                                        <option v-for="(status, key) in statuses" :key="key" :value="key" class="bg-white dark:bg-gray-800">{{ status.label }}</option>
                                    </select>
                                    <span v-if="form.errors.status" class="text-xs text-danger mt-1">{{ form.errors.status }}</span>
                                </div>
                            </div>

                            <div v-if="legalEntitiesForSelectedBranch.length > 0">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Юрлицо</label>
                                <select
                                    v-model="form.legal_entity_id"
                                    :disabled="isLegalEntityLocked"
                                    :class="[isLegalEntityLocked ? 'bg-gray-50 dark:bg-gray-800/60 cursor-not-allowed text-gray-500 dark:text-gray-400' : 'bg-transparent text-gray-800 dark:text-gray-200', 'block w-full rounded-md border border-gray-200 dark:border-gray-700 py-2 px-3 text-sm focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0']"
                                >
                                    <option value="" class="bg-white dark:bg-gray-800">Не указано (без реквизитов в документах)</option>
                                    <option v-for="le in legalEntitiesForSelectedBranch" :key="le.id" :value="le.id" class="bg-white dark:bg-gray-800">{{ le.name }}</option>
                                </select>
                                <p v-if="isLegalEntityLocked" class="text-xs text-gray-500 dark:text-gray-400 mt-1.5">
                                    Зафиксировано юрлицом, выбранным в шапке. Чтобы выставить документ от другого — переключите юрлицо в шапке сайта.
                                </p>
                                <span v-if="form.errors.legal_entity_id" class="text-xs text-danger mt-1">{{ form.errors.legal_entity_id }}</span>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Клиент <span class="text-danger">*</span></label>
                                    <select 
                                        v-model="form.client_id" 
                                        @change="form.vehicle_id = ''"
                                        required
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0"
                                    >
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Выберите клиента...</option>
                                        <option v-for="client in clients" :key="client.id" :value="client.id" class="bg-white dark:bg-gray-800">
                                            {{ client.name }} {{ client.phone ? `(${client.phone})` : '' }}
                                        </option>
                                    </select>
                                    <span v-if="form.errors.client_id" class="text-xs text-danger mt-1">{{ form.errors.client_id }}</span>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Автомобиль</label>
                                    <select 
                                        v-model="form.vehicle_id" 
                                        :disabled="!form.client_id"
                                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:bg-gray-100 dark:disabled:bg-gray-800 disabled:text-gray-400"
                                    >
                                        <option value="" class="bg-white dark:bg-gray-800">Без автомобиля</option>
                                        <option v-for="vehicle in vehicles.filter(v => v.client_id === form.client_id)" :key="vehicle.id" :value="vehicle.id" class="bg-white dark:bg-gray-800">
                                            {{ vehicle.make ? vehicle.make.name : '' }} {{ vehicle.vehicleModel ? vehicle.vehicleModel.name : '' }} {{ vehicle.plate_number ? `[${vehicle.plate_number}]` : '' }}
                                        </option>
                                    </select>
                                    <span v-if="form.errors.vehicle_id" class="text-xs text-danger mt-1">{{ form.errors.vehicle_id }}</span>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Пробег (км)</label>
                                <input 
                                    v-model="form.mileage" 
                                    type="number" 
                                    min="0"
                                    placeholder="Например: 150000" 
                                    class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500" 
                                />
                            </div>

                            <!-- Кастомные поля (EAV) -->
                            <div v-if="customFieldDefs.length > 0" class="border-t border-gray-200 dark:border-gray-700 pt-4 mt-4 space-y-4">
                                <h4 class="text-sm font-bold text-gray-800 dark:text-gray-200 mb-2">Дополнительные поля</h4>
                                
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div v-for="def in customFieldDefs" :key="def.id">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
                                            {{ getLocalizedLabel(def.label) }} <span v-if="def.is_required" class="text-danger">*</span>
                                        </label>
                                        
                                        <template v-if="def.type === 'text'">
                                            <input type="text" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                        </template>
                                        
                                        <template v-else-if="def.type === 'number'">
                                            <input type="number" step="any" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                        </template>
                                        
                                        <template v-else-if="def.type === 'date'">
                                            <input type="date" v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0" />
                                        </template>
                                        
                                        <template v-else-if="def.type === 'select'">
                                            <select v-model="form.custom_fields[def.key]" :required="def.is_required" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                                <option value="" disabled class="bg-white dark:bg-gray-800">Выберите...</option>
                                                <option v-for="opt in def.options" :key="opt" :value="opt" class="bg-white dark:bg-gray-800">{{ opt }}</option>
                                            </select>
                                        </template>
                                        
                                        <template v-else-if="def.type === 'checkbox'">
                                            <div class="flex items-center pt-2">
                                                <div @click="form.custom_fields[def.key] = !form.custom_fields[def.key]" :class="[form.custom_fields[def.key] ? 'bg-primary' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                                    <div :class="[form.custom_fields[def.key] ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </div>

                        </div>
                        <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                            <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                            <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Модальное окно быстрого поиска одной позиции -->
        <Teleport to="body">
            <div v-if="isItemModalOpen" class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-xl my-8 mx-auto flex flex-col">
                    <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                            Добавление позиции
                        </h3>
                        <button @click="closeItemModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                            <i class="ri-close-line text-xl"></i>
                        </button>
                    </div>
                    <form @submit.prevent="submitItem" class="flex flex-col">
                        <div class="p-6 space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип <span class="text-danger">*</span></label>
                                    <select v-model="itemForm.itemable_type" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0">
                                        <option value="service" class="bg-white dark:bg-gray-800">Услуга</option>
                                        <option value="product" class="bg-white dark:bg-gray-800">Товар / Материал</option>
                                    </select>
                                </div>
                                <div>
                                    <div class="flex justify-between items-center mb-1.5">
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Выбор из справочника <span class="text-danger">*</span></label>
                                        <button v-if="itemForm.itemable_type === 'service'" type="button" @click="openQuickServiceModal" title="Добавить услугу, которой нет в каталоге" class="text-xs text-primary hover:underline font-medium">+ Нет в списке?</button>
                                        <button v-if="itemForm.itemable_type === 'product'" type="button" @click="openQuickProductModal" class="text-xs text-primary hover:underline font-medium">+ Создать новый</button>
                                    </div>
                                    <select v-model="itemForm.itemable_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Выберите...</option>
                                        <template v-if="itemForm.itemable_type === 'service'">
                                            <option v-for="s in services" :key="s.id" :value="s.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(s.name) }}</option>
                                        </template>
                                        <template v-else>
                                            <option v-for="p in stockFilteredProducts" :key="p.id" :value="p.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(p.name) }}</option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Наименование в чеке <span class="text-danger">*</span></label>
                                <input v-model="itemForm.name" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Количество <span class="text-danger">*</span></label>
                                    <input v-model="itemForm.quantity" type="number" step="any" min="0.001" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цена за ед. (₽) <span class="text-danger">*</span></label>
                                    <input v-model="itemForm.price" type="number" step="0.01" min="0" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                </div>
                            </div>
                            
                            <div class="bg-gray-50 dark:bg-gray-800/50 p-3 rounded-md border border-gray-200 dark:border-gray-700 flex justify-between items-center mt-2">
                                <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Итого по позиции:</span>
                                <span class="text-lg font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(itemForm.quantity * itemForm.price * 100) }}</span>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                            <button type="button" @click="closeItemModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                            <button type="submit" :disabled="itemForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Модальное окно быстрого создания Услуги — через общий <Modal>,
        открывается поверх формы добавления позиции (тоже нужно перевести
        на <Modal> — см. ниже) и поверх корзины пакетного добавления. -->
        <Modal :show="isQuickServiceModalOpen" @close="closeQuickServiceModal" maxWidth="3xl">
            <div class="bg-white dark:bg-[#313a46] rounded-md flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Добавить услугу, которой нет в каталоге
                    </h3>
                    <button @click="closeQuickServiceModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submitQuickService" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название услуги <span class="text-danger">*</span></label>
                            <input v-model="quickServiceForm.name" type="text" required placeholder="Например: разовая работа по просьбе клиента" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Цена (₽) <span class="text-danger">*</span></label>
                            <input v-model="quickServiceForm.price" type="number" step="0.01" min="0" required class="block w-full sm:w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>

                        <div class="flex items-center pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                            <div @click="quickServiceForm.save_to_catalog = !quickServiceForm.save_to_catalog" :class="[quickServiceForm.save_to_catalog ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[quickServiceForm.save_to_catalog ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="quickServiceForm.save_to_catalog = !quickServiceForm.save_to_catalog">
                                Сохранить в каталог услуг на будущее
                            </label>
                        </div>
                        <p v-if="!quickServiceForm.save_to_catalog" class="text-xs text-gray-500">Позиция добавится только в этот заказ и не появится в общем прайс-листе.</p>

                        <div v-if="quickServiceForm.save_to_catalog" class="space-y-4 pt-2">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория</label>
                                    <select v-model="quickServiceForm.service_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                        <option value="" class="bg-white dark:bg-gray-800">Без категории</option>
                                        <option v-for="cat in serviceCategories" :key="cat.id" :value="cat.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(cat.name) }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Направление</label>
                                    <select v-model="quickServiceForm.business_direction_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                        <option value="" class="bg-white dark:bg-gray-800">Без направления</option>
                                        <option v-for="dir in businessDirections" :key="dir.id" :value="dir.id" class="bg-white dark:bg-gray-800">{{ dir.name }}</option>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Нормо-время (мин) <span class="text-danger">*</span></label>
                                <input v-model="quickServiceForm.duration_minutes" type="number" min="0" :required="quickServiceForm.save_to_catalog" class="block w-full sm:w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeQuickServiceModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="quickServiceForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Добавить в заказ</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Модальное окно быстрого создания Товара — через общий <Modal> (не
        голый div с z-index): открывается поверх других модалок этой страницы
        (например, скидки или оплаты), а те тоже используют <Modal> —
        нативный <dialog>.showModal() корректно стекуется по порядку
        открытия только с таким же <dialog>, обычный div тут не поможет
        независимо от z-index. См. CLAUDE.md про пополняемые списки. -->
        <Modal :show="isQuickProductModalOpen" @close="closeQuickProductModal" maxWidth="3xl">
            <div class="bg-white dark:bg-[#313a46] rounded-md flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        Быстрое добавление товара в каталог
                    </h3>
                    <button @click="closeQuickProductModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none">
                        <i class="ri-close-line text-xl"></i>
                    </button>
                </div>
                <form @submit.prevent="submitQuickProduct" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Категория</label>
                            <select v-model="quickProductForm.product_category_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="" class="bg-white dark:bg-gray-800">Без категории</option>
                                <option v-for="cat in productCategories" :key="cat.id" :value="cat.id" class="bg-white dark:bg-gray-800">{{ getLocalizedLabel(cat.name) }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название товара <span class="text-danger">*</span></label>
                            <input v-model="quickProductForm.name" type="text" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Ед. изм. <span class="text-danger">*</span></label>
                                <select v-model="quickProductForm.unit" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="шт" class="bg-white dark:bg-gray-800">Штуки (шт)</option>
                                    <option value="мл" class="bg-white dark:bg-gray-800">Миллилитры (мл)</option>
                                    <option value="л" class="bg-white dark:bg-gray-800">Литры (л)</option>
                                    <option value="гр" class="bg-white dark:bg-gray-800">Граммы (гр)</option>
                                    <option value="кг" class="bg-white dark:bg-gray-800">Килограммы (кг)</option>
                                    <option value="пог.м" class="bg-white dark:bg-gray-800">Погонные метры (пог.м)</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Тип учета <span class="text-danger">*</span></label>
                                <select v-model="quickProductForm.accounting_type" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="average" class="bg-white dark:bg-gray-800">Средневзвешенный</option>
                                    <option value="batch" class="bg-white dark:bg-gray-800">Партионный (FIFO)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeQuickProductModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="quickProductForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </Modal>

        <!-- Модальное окно скидки -->
        <Teleport to="body">
            <div v-if="isDiscountModalOpen" class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                    <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                            Скидка на заказ
                        </h3>
                        <button @click="closeDiscountModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                            <i class="ri-close-line text-xl"></i>
                        </button>
                    </div>
                    <form @submit.prevent="submitDiscount" class="flex flex-col">
                        <div class="p-6 space-y-4">
                            <p v-if="hasItemDiscounts" class="text-xs text-danger flex items-start gap-1.5 bg-danger/10 rounded-md p-3">
                                <i class="ri-error-warning-line mt-0.5"></i>
                                <span>Общую скидку нельзя задать — на одной из позиций заказа уже установлена индивидуальная скидка. Уберите скидки с позиций в корзине заказа, чтобы задать общую.</span>
                            </p>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма скидки (₽) <span class="text-danger">*</span></label>
                                <input v-model="discountForm.discount_amount" type="number" step="0.01" min="0" :max="workOrder.total_amount / 100" :disabled="hasItemDiscounts" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-gray-300 dark:focus:border-gray-600 focus:ring-0 disabled:opacity-50 disabled:cursor-not-allowed" />
                                <p class="text-xs text-gray-500 mt-1">Максимальная скидка: {{ formatMoney(workOrder.total_amount) }}</p>
                            </div>
                            <p v-if="!workOrder.discount_is_manual" class="text-xs text-gray-500 dark:text-gray-400">
                                <i class="ri-magic-line"></i> Сейчас скидка считается автоматически по грейду клиента. Сохранение здесь зафиксирует её вручную.
                            </p>
                            <button v-else type="button" @click="resetDiscountAuto" class="text-xs text-primary hover:underline flex items-center gap-1">
                                <i class="ri-restart-line"></i> Сбросить и считать автоматически по грейду клиента
                            </button>
                        </div>
                        <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                            <button type="button" @click="closeDiscountModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                            <button type="submit" :disabled="discountForm.processing || hasItemDiscounts" :title="hasItemDiscounts ? 'Недоступно, пока на позициях есть индивидуальные скидки' : ''" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-primary text-white hover:bg-primary-600 disabled:opacity-50 disabled:cursor-not-allowed">Применить</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <!-- Модальное окно оплаты -->
        <Teleport to="body">
            <div v-if="isPaymentModalOpen" class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-md my-8 mx-auto flex flex-col">
                    <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                        <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                            Прием оплаты
                        </h3>
                        <button @click="closePaymentModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none bg-white dark:bg-gray-800 rounded-md p-1 shadow-sm border border-gray-200 dark:border-gray-700">
                            <i class="ri-close-line text-xl"></i>
                        </button>
                    </div>
                    <form @submit.prevent="submitPayment" class="flex flex-col">
                        <div class="p-6 space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Касса / Счет <span class="text-danger">*</span></label>
                                <select v-model="paymentForm.account_id" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="" disabled class="bg-white dark:bg-gray-800">Выберите счет...</option>
                                    <option v-for="acc in accounts" :key="acc.id" :value="acc.id" class="bg-white dark:bg-gray-800">{{ acc.name }} — {{ accountTypeLabels[acc.type] || acc.type }}</option>
                                </select>
                                <span v-if="paymentForm.errors.account_id" class="text-xs text-danger mt-1">{{ paymentForm.errors.account_id }}</span>
                            </div>

                            <div v-if="selectedAccount?.type === 'bonus'" class="p-3 rounded-md bg-info/5 border border-info/20 text-xs text-gray-600 dark:text-gray-400">
                                Доступно бонусами: <span class="font-bold text-info">{{ formatMoney(clientBonusRub * 100) }}</span>
                                ({{ workOrder.client?.bonus_points || 0 }} баллов)
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Сумма к оплате (₽) <span class="text-danger">*</span></label>
                                <input v-model="paymentForm.amount" type="number" step="0.01" min="0.01" :max="maxPayableRub" required class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                <p class="text-xs text-gray-500 mt-1">Остаток долга: {{ formatMoney(remainingAmount) }}</p>
                            </div>

                            <div v-if="acquiringCommissionPreview" class="p-3 rounded-md bg-warning/5 border border-warning/20 text-xs text-gray-600 dark:text-gray-400 space-y-1">
                                <div class="flex justify-between"><span>Комиссия банка ({{ selectedAccount.commission_percent }}%):</span> <span class="font-bold text-warning">− {{ formatMoney(acquiringCommissionPreview.commission * 100) }}</span></div>
                                <div class="flex justify-between"><span>Зачислится на счет:</span> <span class="font-bold text-gray-800 dark:text-gray-200">{{ formatMoney(acquiringCommissionPreview.net * 100) }}</span></div>
                                <p class="text-[11px] text-gray-400 pt-1">Заказ будет учтен как оплаченный на полную сумму — комиссия проводится отдельным расходом.</p>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                            <button type="button" @click="closePaymentModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                            <button type="submit" :disabled="paymentForm.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-colors bg-success text-white hover:bg-success-600 disabled:opacity-50">Провести оплату</button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>

        <WorkOrderItemPayoutModal
            :show="isPayoutModalOpen"
            :item="payoutItem"
            :work-order="workOrder"
            :employees="employees"
            @close="closePayoutModal"
        />

        <WorkOrderItemMaterialSettingsModal
            :show="isMaterialSettingsModalOpen"
            :item="materialSettingsItem"
            :work-order="workOrder"
            @close="closeMaterialSettingsModal"
        />

        <AddMaterialModal
            :show="isAddMaterialModalOpen"
            :service-item="addMaterialServiceItem"
            :work-order="workOrder"
            :products="products"
            :warehouses="warehouses"
            @close="closeAddMaterialModal"
        />

        <ServiceMaterialAutoAddModal
            :show="isAutoAddModalOpen"
            :service-item="autoAddServiceItem"
            :work-order="workOrder"
            :default-materials="autoAddDefaultMaterials"
            @close="closeAutoAddModal"
        />

        <WorkOrderReopenModal
            :show="isReopenModalOpen"
            :work-order="workOrder"
            :target-status="reopenTargetStatus"
            :statuses="workOrderStatuses"
            @close="closeReopenModal"
        />

    </AuthenticatedLayout>
</template>