// Список слотов времени в пределах рабочего дня локации — выделено из
// Operations/Appointments/Index.vue (единственное место, где этот паттерн уже был),
// чтобы переиспользовать в форме заказ-наряда (CLAUDE.md "Дата/время создания и
// готовности заказ-наряда") без дублирования логики. Appointments/Index.vue
// сохраняет свою исходную инлайн-копию нетронутой — риск регресса там нулевой.

const JS_DOW_TO_KEY = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
const FALLBACK_HOURS = { open: '07:00', close: '22:00' };

// Часы работы локации на конкретную дату: { open, close, closed }. closed=true
// значит локация в этот день не работает (FALLBACK_HOURS в этом случае — только
// заглушка для построения слотов, реальный смысл несёт именно closed).
export function hoursForDate(dateStr, workingHours, defaultWorkingHours) {
    if (!dateStr) return null;
    const wh = workingHours || defaultWorkingHours || null;
    if (!wh) return { ...FALLBACK_HOURS, closed: false };
    const dow = JS_DOW_TO_KEY[new Date(`${dateStr}T00:00:00`).getDay()];
    const dayEntry = wh.find(d => d.day === dow);
    if (!dayEntry) return { ...FALLBACK_HOURS, closed: false };
    if (!dayEntry.is_open) return { ...FALLBACK_HOURS, closed: true };
    return { open: dayEntry.open, close: dayEntry.close, closed: false };
}

export function buildTimeSlots(dateStr, workingHours, defaultWorkingHours, stepMinutes = 30) {
    const hours = hoursForDate(dateStr, workingHours, defaultWorkingHours);
    if (!hours) return [];
    const [openH, openM] = hours.open.split(':').map(Number);
    const [closeH, closeM] = hours.close.split(':').map(Number);
    const startTotal = openH * 60 + openM;
    const endTotal = closeH * 60 + closeM;
    const slots = [];
    for (let t = startTotal; t <= endTotal; t += stepMinutes) {
        const h = String(Math.floor(t / 60)).padStart(2, '0');
        const m = String(t % 60).padStart(2, '0');
        slots.push(`${h}:${m}`);
    }
    return slots;
}

// Текущее значение всегда включаем в список, даже если оно не попадает в шаг
// сетки или выходит за пределы графика (правка старой записи, смена локации) —
// иначе выбранное время пропадёт из выпадающего списка незаметно для пользователя.
export function withCurrentValue(slots, current) {
    return current && !slots.includes(current) ? [...slots, current].sort() : slots;
}
