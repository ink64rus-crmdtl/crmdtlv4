<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import OperationsNav from '@/Components/OperationsNav.vue';
import DataTableToolbar from '@/Components/DataTableToolbar.vue';
import Pagination from '@/Components/Pagination.vue';
import BulkActions from '@/Components/BulkActions.vue';
import ColumnSettingsModal from '@/Components/ColumnSettingsModal.vue';
import StatusBadgeSelect from '@/Components/StatusBadgeSelect.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import ruLocale from '@fullcalendar/core/locales/ru';
import PostsWeekTable from '@/Components/Calendar/PostsWeekTable.vue';
import PostsDayTimeline from '@/Components/Calendar/PostsDayTimeline.vue';
import PostsDayColumns from '@/Components/Calendar/PostsDayColumns.vue';
import { appointmentCardLines, APPOINTMENT_FIELDS, DEFAULT_CARD_FIELDS, DEFAULT_TOOLTIP_FIELDS } from '@/Utils/appointmentCard.js';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    appointments: Object,
    filters: Object,
    branches: Array,
    clients: Array,
    vehicles: Array,
    employees: Array,
    posts: { type: Array, default: () => [] },
    services: Array,
    products: Array,
    availableColumns: { type: Array, default: () => [] },
    listView: { type: Object, default: () => ({ visible_columns: [] }) },
    appointmentStatuses: { type: Array, default: () => [] },
    defaultWorkingHours: { type: Array, default: () => null },
    calendarFieldOptions: { type: Array, default: () => [] },
    calendarCardFields: { type: Array, default: () => DEFAULT_CARD_FIELDS },
    calendarTooltipFields: { type: Array, default: () => DEFAULT_TOOLTIP_FIELDS },
    openAppointment: { type: Object, default: () => null },
});

const isModalOpen = ref(false);
const editingAppointment = ref(null);
const isColumnsModalOpen = ref(false);
const isCardFieldsModalOpen = ref(false);
const isTooltipFieldsModalOpen = ref(false);

// Каталог полей карточки/тултипа с бэкенда (Настройки → полный набор с русскими
// названиями), но подстраховываемся тем же каталогом на фронте (APPOINTMENT_FIELDS),
// чтобы модалка настройки никогда не показывала "сырые" ключи вида time/client.
const calendarFieldCatalog = computed(() => (props.calendarFieldOptions && props.calendarFieldOptions.length) ? props.calendarFieldOptions : APPOINTMENT_FIELDS);
const activeTab = ref('main');

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
    post_id: '',
    start_at: '',
    end_at: '',
    status: 'scheduled',
    comment: '',
    items: [],
});

// Посты общие для всего центра (branch_id === null) видны при любом выбранном филиале.
const filteredPosts = computed(() => {
    if (!form.branch_id) return props.posts;
    return props.posts.filter(p => !p.branch_id || p.branch_id === form.branch_id);
});

const filteredVehicles = computed(() => {
    if (!form.client_id) return [];
    return props.vehicles.filter(v => v.client_id === form.client_id);
});

// --- ОПЦИИ ДЛЯ SearchableSelect ---
const branchOptions = computed(() => props.branches.map(b => ({ value: b.id, label: b.name })));
const employeeOptions = computed(() => props.employees.map(e => ({ value: e.id, label: `${e.first_name} ${e.last_name}` })));
const postOptions = computed(() => filteredPosts.value.map(p => ({ value: p.id, label: p.name })));
const clientOptions = computed(() => props.clients.map(c => ({ value: c.id, label: `${c.name}${c.phone ? ` (${c.phone})` : ''}` })));
const vehicleOptions = computed(() => filteredVehicles.value.map(v => ({
    value: v.id,
    label: `${v.make ? v.make.name : ''} ${v.vehicle_model ? v.vehicle_model.name : ''}${v.plate_number ? ` [${v.plate_number}]` : ''}`.replace(/\s+/g, ' ').trim(),
})));

// --- ВЫБОР ВРЕМЕНИ СЛОТАМИ ИЗ ЧАСОВ РАБОТЫ ---
// День недели JS (Date.getDay(): 0 = воскресенье) -> ключ дня в working_hours.
const JS_DOW_TO_KEY = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
const FALLBACK_HOURS = { open: '07:00', close: '22:00' };
const TIME_SLOT_STEP_MINUTES = 30;

// Часы работы филиала (если задано своё расписание), иначе — расписание центра по умолчанию.
const effectiveWorkingHours = computed(() => {
    const branch = props.branches.find(b => b.id === form.branch_id);
    return (branch && branch.working_hours) || props.defaultWorkingHours || null;
});

const hoursForDate = (dateStr) => {
    if (!dateStr) return null;
    const wh = effectiveWorkingHours.value;
    if (!wh) return { ...FALLBACK_HOURS, closed: false };
    const dow = JS_DOW_TO_KEY[new Date(`${dateStr}T00:00:00`).getDay()];
    const dayEntry = wh.find(d => d.day === dow);
    if (!dayEntry) return { ...FALLBACK_HOURS, closed: false };
    if (!dayEntry.is_open) return { ...FALLBACK_HOURS, closed: true };
    return { open: dayEntry.open, close: dayEntry.close, closed: false };
};

const buildTimeSlots = (dateStr) => {
    const hours = hoursForDate(dateStr);
    if (!hours) return [];
    const [openH, openM] = hours.open.split(':').map(Number);
    const [closeH, closeM] = hours.close.split(':').map(Number);
    const startTotal = openH * 60 + openM;
    const endTotal = closeH * 60 + closeM;
    const slots = [];
    for (let t = startTotal; t <= endTotal; t += TIME_SLOT_STEP_MINUTES) {
        const h = String(Math.floor(t / 60)).padStart(2, '0');
        const m = String(t % 60).padStart(2, '0');
        slots.push(`${h}:${m}`);
    }
    return slots;
};

// Дата/время храним раздельно в UI, но собираем обратно в единую строку
// "YYYY-MM-DDTHH:mm" в form.start_at/end_at — этот контракт уже используется
// бэкендом и предзаполнением из видов календаря "по постам".
const startDate = computed({
    get: () => (form.start_at ? form.start_at.slice(0, 10) : ''),
    set: (val) => {
        const time = form.start_at ? form.start_at.slice(11, 16) : '';
        form.start_at = val ? `${val}T${time}` : '';
    },
});
const startTime = computed({
    get: () => (form.start_at ? form.start_at.slice(11, 16) : ''),
    set: (val) => {
        const date = startDate.value || toLocalISODate(new Date());
        form.start_at = val ? `${date}T${val}` : '';
    },
});
const endDate = computed({
    get: () => (form.end_at ? form.end_at.slice(0, 10) : ''),
    set: (val) => {
        const time = form.end_at ? form.end_at.slice(11, 16) : '';
        form.end_at = val ? `${val}T${time}` : '';
    },
});
const endTime = computed({
    get: () => (form.end_at ? form.end_at.slice(11, 16) : ''),
    set: (val) => {
        const date = endDate.value || startDate.value || toLocalISODate(new Date());
        form.end_at = val ? `${date}T${val}` : '';
    },
});

// Текущее значение всегда включаем в список слотов, даже если оно не попадает
// в шаг сетки или выходит за пределы графика (правка старой записи, смена филиала) —
// иначе выбранное время пропадёт из выпадающего списка незаметно для пользователя.
const withCurrentValue = (slots, current) => (current && !slots.includes(current) ? [...slots, current].sort() : slots);

const startTimeSlots = computed(() => withCurrentValue(buildTimeSlots(startDate.value), startTime.value));
const endTimeSlots = computed(() => withCurrentValue(buildTimeSlots(endDate.value), endTime.value));
const startDayClosed = computed(() => hoursForDate(startDate.value)?.closed || false);

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

const openModal = (appointment = null, prefill = null) => {
    // На всякий случай гасим всплывающую подсказку записи — теперь она
    // кликабельна (кнопка "Оформить заказ-наряд"), и если она осталась
    // открытой поверх модалки, могла бы перехватывать клики под собой.
    calendarTooltip.value = null;
    editingAppointment.value = appointment;
    if (appointment) {
        form.branch_id = appointment.branch_id;
        form.client_id = appointment.client_id;
        form.vehicle_id = appointment.vehicle_id || '';
        form.employee_id = appointment.employee_id || '';
        form.post_id = appointment.post_id || '';
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
        // Предзаполнение при создании из клика по слоту в видах "по постам"
        if (prefill) {
            if (prefill.branch_id) form.branch_id = prefill.branch_id;
            if (prefill.post_id) form.post_id = prefill.post_id;
            if (prefill.start_at) form.start_at = prefill.start_at;
            if (prefill.end_at) form.end_at = prefill.end_at;
        }
    }
    activeTab.value = 'main';
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingAppointment.value = null;
    form.reset();
    form.clearErrors();
    newItem.value = { itemable_type: 'service', itemable_id: '', name: '', quantity: 1, price: 0 };
};

// Переход по ссылке "Открыть запись" (например, из карточки заказ-наряда) —
// приходит как ?appointment=ID, бэкенд подгружает эту запись отдельно от
// текущей страницы/фильтра списка и сразу открывает её на редактирование.
onMounted(() => {
    if (props.openAppointment) {
        openModal(props.openAppointment);
    }
});

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

const page = usePage();
const isAdmin = computed(() => page.props.auth?.isAdmin || false);

const deleteAppointment = (appointment) => {
    const clientLabel = appointment.client?.name || appointment.client_name || 'клиента';
    // Админ может удалить запись в любом статусе (CLAUDE.md, п. 6) — но раз это
    // обход обычной защиты, предупреждаем отдельно и явно, если запись уже
    // оформлена в заказ-наряд.
    const message = appointment.status === 'converted'
        ? `Запись ${clientLabel} уже оформлена в заказ-наряд. Удалить всё равно? Связь с заказом (если он ещё существует) не удаляется — отвяжите его отдельно при необходимости.`
        : `Удалить запись ${clientLabel}?`;
    if (confirm(message)) {
        router.delete(route('operations.appointments.destroy', appointment.id));
    }
};

// Фаза 9.4: конвертация записи в заказ-наряд по факту приезда клиента —
// AppointmentItem копируются как стартовые позиции WorkOrder. Доступна только
// для "живых" записей (не отменённых, не "не пришёл", ещё не оформленных).
const isConvertible = (appointment) => !['converted', 'cancelled', 'no_show'].includes(appointment.status);

const convertAppointment = (appointment) => {
    const clientLabel = appointment.client?.name || appointment.client_name || 'клиента';
    if (!confirm(`Оформить заказ-наряд по записи ${clientLabel}? Позиции сметы будут перенесены в заказ.`)) return;
    router.post(route('operations.appointments.convert', appointment.id));
};

const changeStatus = (appointment, status) => {
    router.patch(route('operations.appointments.status.update', appointment.id), { status });
};

// --- ВИД ПО УМОЛЧАНИЮ: запоминается локально в браузере (localStorage), т.к.
// это личная настройка отображения, а не бизнес-данные тенанта. ---
const DEFAULT_VIEW_STORAGE_KEY = 'crm:appointments:defaultView';

const loadDefaultView = () => {
    try {
        const raw = localStorage.getItem(DEFAULT_VIEW_STORAGE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch (e) {
        return null;
    }
};

const defaultView = ref(loadDefaultView());

// --- ВИД: СПИСОК / КАЛЕНДАРЬ (Фаза 9.1) ---
const viewMode = ref(defaultView.value?.viewMode || 'list'); // 'list' | 'calendar'

const toLocalDateTimeInput = (date) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
};

// Бэкенд отдаёт время уже в поясе филиала записи, поэтому виджет настроен на
// timeZone:'local' — просто показывает переданные строки как есть, без
// повторной конвертации под часовой пояс браузера.
const fetchCalendarEvents = (fetchInfo, successCallback, failureCallback) => {
    axios.get(route('operations.appointments.calendar-events'), {
        params: { start: fetchInfo.startStr, end: fetchInfo.endStr },
    })
        .then(response => successCallback(response.data))
        .catch(error => failureCallback(error));
};

const onCalendarEventClick = (info) => {
    calendarTooltip.value = null;
    openModal(info.event.extendedProps.appointment);
};

// --- ИЗМЕНЕНИЕ ВРЕМЕНИ/ДНЯ ПЕРЕТАСКИВАНИЕМ МЫШЬЮ (любой вид просмотра) ---
// Полный payload собирается из уже загруженных данных записи (без items —
// его отсутствие в теле запроса означает "не трогать позиции сметы", см.
// AppointmentController::update()), поэтому отдельный лёгкий backend-эндпоинт
// не нужен — переиспользуем обычный PUT records.update.
const rescheduleAppointment = (appointment, patch, revert = null) => {
    const payload = {
        branch_id: appointment.branch_id,
        client_id: appointment.client_id,
        vehicle_id: appointment.vehicle_id || '',
        employee_id: appointment.employee_id || '',
        post_id: (patch.post_id !== undefined ? patch.post_id : appointment.post_id) || '',
        start_at: patch.start_at_local,
        end_at: patch.end_at_local,
        status: appointment.status,
        comment: appointment.comment || '',
    };

    router.put(route('operations.appointments.update', appointment.id), payload, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            if (viewMode.value === 'calendar' && calendarViewMode.value !== 'month') {
                fetchPostsCalendarAppointments();
            }
        },
        onError: (errors) => {
            if (revert) revert();
            alert(Object.values(errors).flat().join('\n') || 'Не удалось изменить время записи.');
        },
    });
};

const onChildReschedule = ({ appointment, start_at_local, end_at_local, post_id, revert }) => {
    rescheduleAppointment(appointment, { start_at_local, end_at_local, post_id }, revert);
};

const onCalendarEventDrop = (info) => {
    rescheduleAppointment(info.event.extendedProps.appointment, {
        start_at_local: toLocalDateTimeInput(info.event.start),
        end_at_local: toLocalDateTimeInput(info.event.end),
    }, info.revert);
};

const onCalendarDateClick = (info) => {
    openModal();
    const hasTime = info.dateStr.includes('T');
    const startDate = hasTime ? new Date(info.dateStr) : new Date(info.dateStr + 'T09:00:00');
    const endDate = new Date(startDate.getTime() + 60 * 60 * 1000); // по умолчанию час

    form.start_at = toLocalDateTimeInput(startDate);
    form.end_at = toLocalDateTimeInput(endDate);
};

// --- ВСПЛЫВАЮЩАЯ ПОДСКАЗКА ПРИ НАВЕДЕНИИ НА ЗАПИСЬ (все режимы просмотра) ---
const calendarTooltip = ref(null);

const appointmentStatusInfo = (value) => props.appointmentStatuses.find(s => s.value === value) || null;

const clientById = (id) => props.clients.find(c => c.id === id) || null;

const vehicleLabel = (id) => {
    const v = props.vehicles.find(x => x.id === id);
    if (!v) return null;
    return `${v.make ? v.make.name : ''} ${v.vehicle_model ? v.vehicle_model.name : ''}${v.plate_number ? ` [${v.plate_number}]` : ''}`.replace(/\s+/g, ' ').trim();
};

const employeeNameById = (id) => {
    if (!id) return null;
    const e = props.employees.find(x => x.id === id);
    return e ? `${e.first_name} ${e.last_name}` : null;
};

const postNameById = (id) => {
    if (!id) return null;
    const p = props.posts.find(x => x.id === id);
    return p ? p.name : null;
};

const branchNameById = (id) => {
    const b = props.branches.find(x => x.id === id);
    return b ? b.name : null;
};

// Дополняет "сырую" запись (только ID-поля из calendarEvents()/postsCalendarAppointments)
// человекочитаемыми значениями — единая точка для appointmentCardLines(), используется
// и карточками (Неделя/День-слева/День-сверху), и тултипом (в т.ч. для FullCalendar,
// где extendedProps.appointment изначально содержит только ID).
const enrichAppointment = (appt) => {
    const client = clientById(appt.client_id);
    const status = appointmentStatusInfo(appt.status);
    return {
        ...appt,
        client_name: client ? client.name : null,
        client_phone: client ? client.phone : null,
        vehicle_label: vehicleLabel(appt.vehicle_id),
        branch_name: branchNameById(appt.branch_id),
        employee_name: employeeNameById(appt.employee_id),
        post_name: postNameById(appt.post_id),
        status_label: status ? (status.label || status.value) : appt.status,
    };
};

// Показ тултипа единообразен для FullCalendar (Месяц/День-посты-сверху, через
// info.el) и для собственных Vue-компонентов (Неделя/День-посты-слева, через
// @hover-событие с DOM-элементом записи) — оба пути используют один и тот же
// calendarTooltip и один и тот же Teleport-блок в шаблоне.
// Тултип прячется не мгновенно, а с небольшой задержкой — иначе невозможно
// довести курсор от записи до самого тултипа (там теперь кликабельная кнопка
// конвертации), он схлопывался бы раньше, чем мышь до него доедет. Наведение
// на сам тултип (см. @mouseenter/@mouseleave в шаблоне) отменяет/перезапускает
// эту задержку так же, как наведение на исходную запись.
let tooltipHideTimer = null;

const cancelTooltipHide = () => {
    if (tooltipHideTimer) {
        clearTimeout(tooltipHideTimer);
        tooltipHideTimer = null;
    }
};

const scheduleTooltipHide = () => {
    cancelTooltipHide();
    tooltipHideTimer = setTimeout(() => {
        calendarTooltip.value = null;
        tooltipHideTimer = null;
    }, 250);
};

const showAppointmentTooltip = (appointment, el, color) => {
    cancelTooltipHide();
    const rect = el.getBoundingClientRect();
    const left = Math.min(Math.max(8, rect.left), window.innerWidth - 300);
    calendarTooltip.value = {
        color,
        appointment,
        style: { position: 'fixed', top: `${rect.bottom + 6}px`, left: `${left}px` },
    };
};

const tooltipLines = computed(() => {
    if (!calendarTooltip.value) return [];
    return appointmentCardLines(calendarTooltip.value.appointment, props.calendarTooltipFields);
});

const onCalendarEventMouseEnter = (info) => {
    // info.event.extendedProps.appointment для Месяца содержит только ID — дополняем.
    showAppointmentTooltip(enrichAppointment(info.event.extendedProps.appointment), info.el, info.event.backgroundColor);
};

const onCalendarEventMouseLeave = () => {
    scheduleTooltipHide();
};

const onChildAppointmentHover = (appt, el) => {
    // Записи из postsCalendarAppointments уже обогащены в fetchPostsCalendarAppointments().
    showAppointmentTooltip(appt, el, appt.color);
};

const onChildAppointmentUnhover = () => {
    scheduleTooltipHide();
};

const convertFromTooltip = () => {
    if (!calendarTooltip.value) return;
    const appointment = calendarTooltip.value.appointment;
    calendarTooltip.value = null;
    convertAppointment(appointment);
};

onUnmounted(() => cancelTooltipHide());

const calendarOptions = {
    plugins: [dayGridPlugin, interactionPlugin],
    initialView: 'dayGridMonth',
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: '',
    },
    locales: [ruLocale],
    locale: 'ru',
    firstDay: 1,
    height: 'auto',
    timeZone: 'local',
    editable: true,
    eventResizableFromStart: true,
    events: fetchCalendarEvents,
    eventClick: onCalendarEventClick,
    eventMouseEnter: onCalendarEventMouseEnter,
    eventMouseLeave: onCalendarEventMouseLeave,
    eventDrop: onCalendarEventDrop,
    eventResize: onCalendarEventDrop,
    dateClick: onCalendarDateClick,
};

// --- ВИДЫ "ПО ПОСТАМ" (Фаза 9.5): Неделя (слева) / День (слева) / День (сверху) ---
const calendarViewMode = ref((defaultView.value?.viewMode === 'calendar' && defaultView.value?.calendarViewMode) || 'month'); // 'month' | 'week-posts' | 'day-posts-left' | 'day-posts-top'
const postsCalendarDate = ref(new Date());
const postsCalendarAppointments = ref([]);
const postsCalendarLoading = ref(false);

const toLocalISODate = (d) => {
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
};

const postsViewRange = computed(() => {
    const base = new Date(postsCalendarDate.value);
    base.setHours(0, 0, 0, 0);
    if (calendarViewMode.value === 'week-posts') {
        const dow = (base.getDay() + 6) % 7; // 0 = понедельник
        const start = new Date(base);
        start.setDate(base.getDate() - dow);
        const end = new Date(start);
        end.setDate(start.getDate() + 7);
        return { start, end };
    }
    const start = base;
    const end = new Date(start);
    end.setDate(start.getDate() + 1);
    return { start, end };
});

const weekDays = computed(() => {
    if (calendarViewMode.value !== 'week-posts') return [];
    const { start } = postsViewRange.value;
    const dayLabels = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
    return Array.from({ length: 7 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);
        return { date: d, iso: toLocalISODate(d), label: dayLabels[i], dayNumber: d.getDate() };
    });
});

const postsViewDateLabel = computed(() => {
    const { start, end } = postsViewRange.value;
    const fmt = (d) => d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
    if (calendarViewMode.value === 'week-posts') {
        const last = new Date(end);
        last.setDate(last.getDate() - 1);
        return `${fmt(start)} — ${fmt(last)}`;
    }
    return fmt(start);
});

const fetchPostsCalendarAppointments = async () => {
    postsCalendarLoading.value = true;
    try {
        const { start, end } = postsViewRange.value;
        const response = await axios.get(route('operations.appointments.calendar-events'), {
            params: { start: start.toISOString(), end: end.toISOString() },
        });
        postsCalendarAppointments.value = response.data.map(e => ({
            ...enrichAppointment(e.extendedProps.appointment),
            title: e.title,
            color: e.color,
            start: e.start,
            end: e.end,
        }));
    } catch (error) {
        console.error('Не удалось загрузить записи для календаря по постам', error);
    } finally {
        postsCalendarLoading.value = false;
    }
};

watch([viewMode, calendarViewMode, postsCalendarDate], () => {
    if (viewMode.value === 'calendar' && calendarViewMode.value !== 'month') {
        fetchPostsCalendarAppointments();
    }
}, { immediate: true });

const navigatePosts = (direction) => {
    const d = new Date(postsCalendarDate.value);
    d.setDate(d.getDate() + direction * (calendarViewMode.value === 'week-posts' ? 7 : 1));
    postsCalendarDate.value = d;
};

const goToPostsToday = () => {
    postsCalendarDate.value = new Date();
};

const isPostsToday = computed(() => toLocalISODate(postsCalendarDate.value) === toLocalISODate(new Date()));

// Кнопка между стрелочками навигации открывает нативный календарь браузера
// для выбора произвольной даты — быстрее, чем листать стрелками далеко вперёд/назад.
const postsDatePickerInput = ref(null);

const openPostsDatePicker = () => {
    if (postsDatePickerInput.value?.showPicker) {
        try {
            postsDatePickerInput.value.showPicker();
            return;
        } catch (e) {
            // showPicker() может бросить исключение вне пользовательского жеста в некоторых браузерах — просто фокусируемся.
        }
    }
    postsDatePickerInput.value?.focus();
};

const onPostsDatePicked = (event) => {
    if (!event.target.value) return;
    postsCalendarDate.value = new Date(`${event.target.value}T00:00:00`);
};

// Гранулярность сетки времени в видах "День" (посты слева/сверху) — 30 минут или 1 час.
const daySlotMinutes = ref(30);

const handlePostsCreate = (payload) => {
    let { branch_id, post_id, start_at, end_at } = payload;
    if (!end_at && start_at) {
        const [datePart, timePart] = start_at.split('T');
        const [h, m] = timePart.split(':').map(Number);
        const endDate = new Date(2000, 0, 1, h, m);
        endDate.setHours(endDate.getHours() + 1);
        const pad = (n) => String(n).padStart(2, '0');
        end_at = `${datePart}T${pad(endDate.getHours())}:${pad(endDate.getMinutes())}`;
    }
    openModal(null, { branch_id, post_id, start_at, end_at });
};

const handlePostsEdit = (appointment) => {
    openModal(appointment);
};

// --- ПЕРЕКЛЮЧАТЕЛЬ "ПРОСМОТР ПО УМОЛЧАНИЮ" (для текущего активного режима) ---
const calendarViewModeLabels = {
    month: 'Месяц',
    'week-posts': 'Неделя (посты слева)',
    'day-posts-left': 'День (посты слева)',
    'day-posts-top': 'День (посты сверху)',
};

const currentViewLabel = computed(() => {
    return viewMode.value === 'list' ? 'Список' : (calendarViewModeLabels[calendarViewMode.value] || '');
});

const isCurrentViewDefault = computed(() => {
    if (!defaultView.value || defaultView.value.viewMode !== viewMode.value) return false;
    return viewMode.value === 'calendar' ? defaultView.value.calendarViewMode === calendarViewMode.value : true;
});

const toggleDefaultView = () => {
    if (isCurrentViewDefault.value) {
        defaultView.value = null;
        localStorage.removeItem(DEFAULT_VIEW_STORAGE_KEY);
        return;
    }
    defaultView.value = {
        viewMode: viewMode.value,
        calendarViewMode: viewMode.value === 'calendar' ? calendarViewMode.value : null,
    };
    localStorage.setItem(DEFAULT_VIEW_STORAGE_KEY, JSON.stringify(defaultView.value));
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
                v-if="viewMode === 'list' && selectedIds.length > 0"
                :selectedCount="selectedIds.length"
                noun="записей"
                @delete="bulkDelete"
            />

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex flex-wrap gap-3 justify-between items-center bg-gray-50/50 dark:bg-gray-800/30">
                    <div class="inline-flex rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden shrink-0">
                        <button
                            type="button"
                            @click="viewMode = 'list'"
                            :class="viewMode === 'list' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            class="px-3 py-1.5 text-xs font-medium flex items-center gap-1.5 transition-colors"
                        >
                            <i class="ri-list-check"></i> Список
                        </button>
                        <button
                            type="button"
                            @click="viewMode = 'calendar'"
                            :class="viewMode === 'calendar' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'"
                            class="px-3 py-1.5 text-xs font-medium flex items-center gap-1.5 border-l border-gray-200 dark:border-gray-700 transition-colors"
                        >
                            <i class="ri-calendar-line"></i> Календарь
                        </button>
                    </div>

                    <button
                        type="button"
                        @click="toggleDefaultView"
                        class="inline-flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400"
                        :title="isCurrentViewDefault ? `«${currentViewLabel}» — вид по умолчанию` : `Сделать «${currentViewLabel}» видом по умолчанию`"
                    >
                        <span
                            :class="isCurrentViewDefault ? 'bg-primary' : 'bg-gray-200 dark:bg-gray-700'"
                            class="flex items-center h-5 w-9 rounded-full transition-all duration-200 relative shrink-0"
                        >
                            <span :class="isCurrentViewDefault ? 'translate-x-4' : 'translate-x-1'" class="h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute"></span>
                        </span>
                        Просмотр по умолчанию
                    </button>

                    <button
                        @click="openModal()"
                        class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm"
                    >
                        <i class="ri-add-line text-base"></i>
                        Новая запись
                    </button>
                </div>

                <!-- Вид: Календарь -->
                <div v-if="viewMode === 'calendar'" class="p-4">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Клик по записи — открыть на редактирование. Клик по пустому времени — создать новую запись с предзаполненным временем.</p>

                    <!-- Переключатель видов календаря -->
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div class="inline-flex rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <button type="button" @click="calendarViewMode = 'month'" :class="calendarViewMode === 'month' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'" class="px-3 py-1.5 text-xs font-medium transition-colors">Месяц</button>
                            <button type="button" @click="calendarViewMode = 'week-posts'" :class="calendarViewMode === 'week-posts' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'" class="px-3 py-1.5 text-xs font-medium border-l border-gray-200 dark:border-gray-700 transition-colors">Неделя (посты слева)</button>
                            <button type="button" @click="calendarViewMode = 'day-posts-left'" :class="calendarViewMode === 'day-posts-left' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'" class="px-3 py-1.5 text-xs font-medium border-l border-gray-200 dark:border-gray-700 transition-colors">День (посты слева)</button>
                            <button type="button" @click="calendarViewMode = 'day-posts-top'" :class="calendarViewMode === 'day-posts-top' ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'" class="px-3 py-1.5 text-xs font-medium border-l border-gray-200 dark:border-gray-700 transition-colors">День (посты сверху)</button>
                        </div>

                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                @click="isCardFieldsModalOpen = true"
                                class="inline-flex items-center gap-1.5 px-2.5 h-8 rounded-md border border-gray-200 dark:border-gray-700 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                                title="Какие поля и в каком порядке показывать в карточке записи (Неделя/День)"
                            ><i class="ri-layout-line"></i> Карточка: поля</button>
                            <button
                                type="button"
                                @click="isTooltipFieldsModalOpen = true"
                                class="inline-flex items-center gap-1.5 px-2.5 h-8 rounded-md border border-gray-200 dark:border-gray-700 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                                title="Какие поля и в каком порядке показывать во всплывающей подсказке при наведении"
                            ><i class="ri-chat-check-line"></i> Тултип: поля</button>
                        </div>

                        <!-- Навигация по дате — только для видов "по постам", у Месяца своя внутренняя навигация FullCalendar -->
                        <div v-if="calendarViewMode !== 'month'" class="flex items-center gap-2">
                            <button type="button" @click="navigatePosts(-1)" class="w-8 h-8 inline-flex items-center justify-center rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"><i class="ri-arrow-left-s-line"></i></button>

                            <div class="relative">
                                <button
                                    type="button"
                                    @click="openPostsDatePicker"
                                    class="px-3 h-8 inline-flex items-center gap-1.5 justify-center rounded-md border border-gray-200 dark:border-gray-700 text-xs font-medium text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors capitalize"
                                    title="Выбрать дату из календаря"
                                >
                                    <i class="ri-calendar-line"></i>
                                    {{ isPostsToday ? 'Сегодня' : postsViewDateLabel }}
                                </button>
                                <input
                                    ref="postsDatePickerInput"
                                    type="date"
                                    tabindex="-1"
                                    class="absolute inset-0 w-full h-full opacity-0 pointer-events-none"
                                    :value="toLocalISODate(postsCalendarDate)"
                                    @change="onPostsDatePicked"
                                />
                            </div>

                            <button type="button" @click="navigatePosts(1)" class="w-8 h-8 inline-flex items-center justify-center rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"><i class="ri-arrow-right-s-line"></i></button>

                            <button
                                v-if="!isPostsToday"
                                type="button"
                                @click="goToPostsToday"
                                class="w-8 h-8 inline-flex items-center justify-center rounded-md border border-gray-200 dark:border-gray-700 text-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors"
                                title="Перейти к сегодняшнему дню"
                            ><i class="ri-calendar-check-line"></i></button>

                            <!-- Гранулярность сетки времени — только для видов с осью времени -->
                            <div v-if="calendarViewMode === 'day-posts-left' || calendarViewMode === 'day-posts-top'" class="inline-flex rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden ml-1">
                                <button type="button" @click="daySlotMinutes = 30" :class="daySlotMinutes === 30 ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'" class="px-2.5 h-8 text-xs font-medium transition-colors">30 мин</button>
                                <button type="button" @click="daySlotMinutes = 60" :class="daySlotMinutes === 60 ? 'bg-primary text-white' : 'bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'" class="px-2.5 h-8 text-xs font-medium border-l border-gray-200 dark:border-gray-700 transition-colors">1 час</button>
                            </div>
                        </div>
                    </div>

                    <FullCalendar v-if="calendarViewMode === 'month'" :options="calendarOptions" />
                    <PostsWeekTable
                        v-else-if="calendarViewMode === 'week-posts'"
                        :posts="posts"
                        :appointments="postsCalendarAppointments"
                        :week-days="weekDays"
                        :loading="postsCalendarLoading"
                        :card-fields="calendarCardFields"
                        @edit="handlePostsEdit"
                        @create="handlePostsCreate"
                        @reschedule="onChildReschedule"
                        @hover="onChildAppointmentHover"
                        @unhover="onChildAppointmentUnhover"
                    />
                    <PostsDayTimeline
                        v-else-if="calendarViewMode === 'day-posts-left'"
                        :posts="posts"
                        :appointments="postsCalendarAppointments"
                        :date="postsCalendarDate"
                        :loading="postsCalendarLoading"
                        :card-fields="calendarCardFields"
                        :slot-minutes="daySlotMinutes"
                        @edit="handlePostsEdit"
                        @create="handlePostsCreate"
                        @reschedule="onChildReschedule"
                        @hover="onChildAppointmentHover"
                        @unhover="onChildAppointmentUnhover"
                    />
                    <PostsDayColumns
                        v-else-if="calendarViewMode === 'day-posts-top'"
                        :posts="posts"
                        :appointments="postsCalendarAppointments"
                        :date="postsCalendarDate"
                        :loading="postsCalendarLoading"
                        :card-fields="calendarCardFields"
                        :slot-minutes="daySlotMinutes"
                        @edit="handlePostsEdit"
                        @create="handlePostsCreate"
                        @reschedule="onChildReschedule"
                        @hover="onChildAppointmentHover"
                        @unhover="onChildAppointmentUnhover"
                    />

                    <Teleport to="body">
                        <div
                            v-if="calendarTooltip"
                            :style="[calendarTooltip.style, calendarTooltip.color ? { borderLeftColor: calendarTooltip.color, borderLeftWidth: '4px' } : {}]"
                            class="z-[300] w-72 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg p-3"
                            @mouseenter="cancelTooltipHide"
                            @mouseleave="scheduleTooltipHide"
                        >
                            <dl class="space-y-1.5 text-xs text-gray-600 dark:text-gray-400">
                                <div
                                    v-for="(line, idx) in tooltipLines"
                                    :key="line.key"
                                    :class="idx === 0 ? 'text-sm font-bold text-gray-800 dark:text-gray-100' : ''"
                                    class="flex items-center gap-1.5"
                                >
                                    <i :class="line.icon" class="text-gray-400 w-3.5 text-center shrink-0"></i>
                                    <span class="truncate">{{ line.text }}</span>
                                </div>
                                <p v-if="tooltipLines.length === 0" class="text-gray-400 italic">Нет полей для отображения — настройте в «Тултип: поля»</p>
                            </dl>
                            <div v-if="isConvertible(calendarTooltip.appointment)" class="mt-2.5 pt-2.5 border-t border-gray-100 dark:border-gray-700">
                                <button
                                    type="button"
                                    @click="convertFromTooltip"
                                    class="w-full inline-flex items-center justify-center gap-1.5 rounded px-2.5 py-1.5 text-xs font-medium transition-colors bg-success/10 text-success hover:bg-success hover:text-white"
                                >
                                    <i class="ri-file-transfer-line"></i> Оформить заказ-наряд
                                </button>
                            </div>
                            <div v-else-if="calendarTooltip.appointment.work_order_id" class="mt-2.5 pt-2.5 border-t border-gray-100 dark:border-gray-700">
                                <button
                                    type="button"
                                    @click="router.visit(route('operations.work-orders.show', calendarTooltip.appointment.work_order_id))"
                                    class="w-full inline-flex items-center justify-center gap-1.5 rounded px-2.5 py-1.5 text-xs font-medium transition-colors bg-success/10 text-success hover:bg-success hover:text-white"
                                >
                                    <i class="ri-external-link-line"></i> Перейти к заказ-наряду
                                </button>
                            </div>
                        </div>
                    </Teleport>
                </div>

                <!-- Вид: Список -->
                <template v-else>
                <DataTableToolbar
                    v-model="search"
                    :has-filters="false"
                    @open-columns="isColumnsModalOpen = true"
                    placeholder="Поиск по комментарию..."
                />
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
                                        v-if="isConvertible(appointment)"
                                        @click="convertAppointment(appointment)"
                                        title="Клиент приехал — оформить заказ-наряд"
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-success/10 text-success hover:bg-success hover:text-white"
                                    >
                                        <i class="ri-file-transfer-line"></i>
                                    </button>
                                    <button
                                        v-else-if="appointment.work_order_id"
                                        @click="router.visit(route('operations.work-orders.show', appointment.work_order_id))"
                                        title="Перейти к заказ-наряду"
                                        class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-success/10 text-success hover:bg-success hover:text-white"
                                    >
                                        <i class="ri-external-link-line"></i>
                                    </button>
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
                                        :disabled="appointment.status === 'converted' && !isAdmin"
                                        :title="appointment.status === 'converted' ? (isAdmin ? 'Запись оформлена в заказ-наряд — удаление доступно администратору' : 'Запись оформлена в заказ-наряд — недоступна для удаления') : 'Удалить'"
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
                </template>
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

                <!-- Вкладки внутри модалки -->
                <div class="flex overflow-x-auto border-b border-gray-200 dark:border-gray-700 px-6 bg-white dark:bg-[#313a46] custom-scrollbar">
                    <button
                        type="button"
                        @click="activeTab = 'main'"
                        :class="[activeTab === 'main' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-calendar-event-line"></i> Основное
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'estimate'"
                        :class="[activeTab === 'estimate' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-3 text-sm transition-colors flex items-center gap-2 focus:outline-none whitespace-nowrap']"
                    >
                        <i class="ri-file-list-3-line"></i> Смета
                        <span v-if="form.items.length > 0" class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-primary/10 text-primary text-[10px] font-bold">{{ form.items.length }}</span>
                    </button>
                </div>

                <form @submit.prevent="submit" class="flex flex-col">
                    <!-- Вкладка: Основное -->
                    <div v-show="activeTab === 'main'" class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Филиал <span class="text-danger">*</span></label>
                                <SearchableSelect
                                    v-model="form.branch_id"
                                    :options="branchOptions"
                                    placeholder="Выберите филиал..."
                                    searchPlaceholder="Поиск филиала..."
                                    @update:model-value="form.post_id = ''"
                                />
                                <p v-if="form.errors.branch_id" class="mt-1 text-xs text-danger">{{ form.errors.branch_id }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Мастер</label>
                                <SearchableSelect
                                    v-model="form.employee_id"
                                    :options="employeeOptions"
                                    placeholder="Не назначен"
                                    searchPlaceholder="Поиск сотрудника..."
                                    clearable
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Пост</label>
                                <SearchableSelect
                                    v-model="form.post_id"
                                    :options="postOptions"
                                    placeholder="Не назначен"
                                    searchPlaceholder="Поиск поста..."
                                    clearable
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Клиент <span class="text-danger">*</span></label>
                                <SearchableSelect
                                    v-model="form.client_id"
                                    :options="clientOptions"
                                    placeholder="Выберите клиента..."
                                    searchPlaceholder="Поиск клиента..."
                                    @update:model-value="form.vehicle_id = ''"
                                />
                                <p v-if="form.errors.client_id" class="mt-1 text-xs text-danger">{{ form.errors.client_id }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Автомобиль</label>
                                <SearchableSelect
                                    v-model="form.vehicle_id"
                                    :options="vehicleOptions"
                                    :disabled="!form.client_id"
                                    placeholder="Без автомобиля"
                                    searchPlaceholder="Поиск автомобиля..."
                                    clearable
                                />
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Начало <span class="text-danger">*</span></label>
                                <div class="flex gap-2">
                                    <input v-model="startDate" type="date" required class="block w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    <select v-model="startTime" required class="block w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Время</option>
                                        <option v-for="slot in startTimeSlots" :key="slot" :value="slot" class="bg-white dark:bg-gray-800">{{ slot }}</option>
                                    </select>
                                </div>
                                <p v-if="startDate && startDayClosed" class="mt-1 text-xs text-warning">По графику этот день нерабочий — время можно выбрать вручную, запись всё равно будет создана.</p>
                                <p v-if="form.errors.start_at" class="mt-1 text-xs text-danger">{{ form.errors.start_at }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Окончание <span class="text-danger">*</span></label>
                                <div class="flex gap-2">
                                    <input v-model="endDate" type="date" required class="block w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                                    <select v-model="endTime" required class="block w-1/2 rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                        <option value="" disabled class="bg-white dark:bg-gray-800">Время</option>
                                        <option v-for="slot in endTimeSlots" :key="slot" :value="slot" class="bg-white dark:bg-gray-800">{{ slot }}</option>
                                    </select>
                                </div>
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
                    </div>

                    <!-- Вкладка: Ориентировочная смета (не резервирует остатки, не создает финансовых операций) -->
                    <div v-show="activeTab === 'estimate'" class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Ориентировочная смета для клиента. Остатки на складе не резервируются, финансовые операции не создаются — это происходит только после конвертации записи в заказ-наряд.</p>

                        <div v-if="form.items.length > 0" class="space-y-1.5">
                            <div v-for="(item, index) in form.items" :key="index" class="flex items-center justify-between gap-2 py-1.5 px-3 rounded bg-gray-50/50 dark:bg-gray-800/30 text-sm">
                                <span class="flex-1 truncate">{{ item.name }}</span>
                                <span class="text-gray-400">{{ item.quantity }} × {{ item.price }} ₽</span>
                                <button type="button" @click="removeItemRow(index)" class="text-danger hover:opacity-70">
                                    <i class="ri-close-line"></i>
                                </button>
                            </div>
                            <div class="text-right text-sm font-semibold text-gray-700 dark:text-gray-300 pr-3">Итого: {{ itemsTotal.toFixed(2) }} ₽</div>
                        </div>
                        <p v-else class="text-sm text-gray-400 text-center py-4">Позиции сметы не добавлены</p>

                        <div class="flex flex-wrap items-end gap-2 pt-3 border-t border-gray-200 dark:border-gray-700">
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

                    <div class="flex justify-between items-center gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button
                            v-if="editingAppointment && isConvertible(editingAppointment)"
                            type="button"
                            @click="convertAppointment(editingAppointment)"
                            title="Клиент приехал — оформить заказ-наряд, позиции сметы перенесутся автоматически"
                            class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-success/10 text-success hover:bg-success hover:text-white gap-1.5"
                        >
                            <i class="ri-file-transfer-line"></i> Оформить заказ-наряд
                        </button>
                        <span v-else></span>
                        <div class="flex gap-3">
                            <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                            <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                        </div>
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

        <ColumnSettingsModal
            :show="isCardFieldsModalOpen"
            entity-type="appointment_calendar_card"
            :available-columns="calendarFieldCatalog"
            :visible-columns="calendarCardFields"
            @close="isCardFieldsModalOpen = false"
            @saved="isCardFieldsModalOpen = false"
        />

        <ColumnSettingsModal
            :show="isTooltipFieldsModalOpen"
            entity-type="appointment_calendar_tooltip"
            :available-columns="calendarFieldCatalog"
            :visible-columns="calendarTooltipFields"
            @close="isTooltipFieldsModalOpen = false"
            @saved="isTooltipFieldsModalOpen = false"
        />
    </AuthenticatedLayout>
</template>

<style>
/* Переопределяем CSS-переменные FullCalendar (официальный механизм темизации виджета,
   см. https://fullcalendar.io/docs/css-customization) под палитру Attex/Tailwind
   вместо цветов Bootstrap по умолчанию — иначе тулбар/кнопки визуально выпадали из CRM. */
.fc {
    --fc-border-color: rgb(229 231 235); /* gray-200, совпадает с border-gray-200 */
    --fc-button-bg-color: #3e60d5; /* primary */
    --fc-button-border-color: #3e60d5;
    --fc-button-hover-bg-color: #324fb6; /* primary-600 */
    --fc-button-hover-border-color: #324fb6;
    --fc-button-active-bg-color: #324fb6;
    --fc-button-active-border-color: #324fb6;
    --fc-today-bg-color: rgba(62, 96, 213, 0.06); /* primary/6% */
    --fc-neutral-bg-color: rgb(249 250 251); /* gray-50 */
    --fc-page-bg-color: transparent;
    font-family: inherit;
}

.dark .fc {
    --fc-border-color: rgb(55 65 81); /* gray-700 */
    --fc-neutral-bg-color: rgb(31 41 55); /* gray-800 */
    --fc-page-bg-color: transparent;
    color: rgb(229 231 235);
}

.fc .fc-button {
    text-transform: none;
    border-radius: 0.375rem; /* rounded-md, как остальные элементы формы в CRM */
    box-shadow: none !important;
    font-weight: 500;
}

.fc .fc-toolbar-title {
    font-size: 1rem;
    font-weight: 700;
}
</style>
