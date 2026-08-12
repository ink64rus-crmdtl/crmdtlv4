// Единый список полей, доступных для настройки в карточке записи (Неделя/
// День-слева/День-сверху) и во всплывающей подсказке (см. AppointmentController
// ::index() -> calendarCardFields/calendarTooltipFields, хранится в list_views
// с entity_type = 'appointment_calendar_card' / 'appointment_calendar_tooltip').
export const APPOINTMENT_FIELDS = [
    { key: 'time', label: 'Время', icon: 'ri-time-line' },
    { key: 'client', label: 'Клиент', icon: 'ri-user-line' },
    { key: 'vehicle', label: 'Автомобиль', icon: 'ri-car-line' },
    { key: 'phone', label: 'Телефон', icon: 'ri-phone-line' },
    { key: 'branch', label: 'Локация', icon: 'ri-store-2-line' },
    { key: 'employee', label: 'Мастер', icon: 'ri-user-star-line' },
    { key: 'post', label: 'Пост', icon: 'ri-parking-box-line' },
    { key: 'status', label: 'Статус', icon: 'ri-flag-line' },
    { key: 'comment', label: 'Комментарий', icon: 'ri-chat-3-line' },
];

export const DEFAULT_CARD_FIELDS = ['time', 'client', 'vehicle', 'phone'];
export const DEFAULT_TOOLTIP_FIELDS = ['time', 'client', 'vehicle', 'phone', 'branch', 'employee', 'post', 'comment'];

const FIELD_MAP = Object.fromEntries(APPOINTMENT_FIELDS.map(f => [f.key, f]));

function appointmentFieldValue(appointment, key) {
    switch (key) {
        case 'time':
            return appointment.start_at_local && appointment.end_at_local
                ? `${appointment.start_at_local.slice(11, 16)}–${appointment.end_at_local.slice(11, 16)}`
                : null;
        case 'client': return appointment.client_name || null;
        case 'vehicle': return appointment.vehicle_label || null;
        case 'phone': return appointment.client_phone || null;
        case 'branch': return appointment.branch_name || null;
        case 'employee': return appointment.employee_name || null;
        case 'post': return appointment.post_name || null;
        case 'status': return appointment.status_label || null;
        case 'comment': return appointment.comment || null;
        default: return null;
    }
}

// Строит массив { icon, text } строго по составу и порядку fieldKeys — поля без
// значения (нет телефона, нет поста и т.п.) просто пропускаются.
export function appointmentCardLines(appointment, fieldKeys = DEFAULT_CARD_FIELDS) {
    const lines = [];
    for (const key of fieldKeys) {
        const field = FIELD_MAP[key];
        if (!field) continue;
        const text = appointmentFieldValue(appointment, key);
        if (!text) continue;
        lines.push({ key, icon: field.icon, text });
    }
    return lines;
}

export function escapeHtml(value) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(value ?? '').replace(/[&<>"']/g, (c) => map[c]);
}
