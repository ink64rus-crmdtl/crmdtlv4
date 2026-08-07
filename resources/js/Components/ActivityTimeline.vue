<script setup>
/**
 * Единая лента "История" для всех полных карточек (Клиент/Авто/Сотрудник/
 * Заказ-наряд) — см. CLAUDE.md, Tri-State Record Pattern. Данные приходят
 * готовыми с бэкенда (App\Services\ActivityLogger), компонент только рендерит
 * + умеет постить комментарий, если передан commentUrl.
 */
import { useForm, Link } from '@inertiajs/vue3';

const props = defineProps({
    activities: { type: Array, default: () => [] },
    commentUrl: { type: String, default: null },
});

const commentForm = useForm({ comment: '' });

const submitComment = () => {
    if (!commentForm.comment.trim() || !props.commentUrl) return;
    commentForm.post(props.commentUrl, {
        preserveScroll: true,
        onSuccess: () => commentForm.reset(),
    });
};

// Полный статический список классов важен для Tailwind JIT — динамически
// собранная строка вида `bg-${color}/10` в сборке не заработает.
const EVENT_META = {
    created: { icon: 'ri-add-circle-line', badgeClass: 'bg-success/10 text-success' },
    updated: { icon: 'ri-pencil-line', badgeClass: 'bg-primary/10 text-primary' },
    item_added: { icon: 'ri-add-line', badgeClass: 'bg-primary/10 text-primary' },
    item_removed: { icon: 'ri-subtract-line', badgeClass: 'bg-danger/10 text-danger' },
    discount_updated: { icon: 'ri-price-tag-3-line', badgeClass: 'bg-warning/10 text-warning' },
    payment_received: { icon: 'ri-bank-card-line', badgeClass: 'bg-success/10 text-success' },
    status_changed: { icon: 'ri-exchange-line', badgeClass: 'bg-info/10 text-info' },
    completed: { icon: 'ri-check-double-line', badgeClass: 'bg-success/10 text-success' },
    comment: { icon: 'ri-chat-3-line', badgeClass: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' },
    appointment_linked: { icon: 'ri-calendar-check-line', badgeClass: 'bg-primary/10 text-primary' },
    rescheduled: { icon: 'ri-time-line', badgeClass: 'bg-info/10 text-info' },
    deleted: { icon: 'ri-delete-bin-line', badgeClass: 'bg-danger/10 text-danger' },
    default: { icon: 'ri-information-line', badgeClass: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' },
};

const metaFor = (event) => EVENT_META[event] || EVENT_META.default;

const LINK_ROUTES = {
    work_order: (id) => route('operations.work-orders.show', id),
    appointment: (id) => route('operations.appointments.index', { appointment: id }),
    client: (id) => route('crm.clients.show', id),
    vehicle: (id) => route('crm.vehicles.show', id),
    employee: (id) => route('hr.employees.show', id),
};

const linkHref = (link) => LINK_ROUTES[link.type] ? LINK_ROUTES[link.type](link.id) : null;

const formatRelative = (dateStr) => {
    const diffSeconds = Math.max(0, Math.floor((Date.now() - new Date(dateStr).getTime()) / 1000));
    if (diffSeconds < 60) return 'только что';
    if (diffSeconds < 3600) return `${Math.floor(diffSeconds / 60)} мин назад`;
    if (diffSeconds < 86400) return `${Math.floor(diffSeconds / 3600)} ч назад`;
    if (diffSeconds < 86400 * 7) return `${Math.floor(diffSeconds / 86400)} дн назад`;
    return new Date(dateStr).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' });
};

const formatAbsolute = (dateStr) => new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <div class="flex flex-col h-full">
        <div v-if="commentUrl" class="p-4 border-b border-gray-200 dark:border-gray-700">
            <textarea
                v-model="commentForm.comment"
                rows="2"
                placeholder="Добавить комментарий..."
                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 resize-none"
            ></textarea>
            <div class="flex justify-end mt-2">
                <button
                    type="button"
                    @click="submitComment"
                    :disabled="commentForm.processing || !commentForm.comment.trim()"
                    class="inline-flex items-center gap-1.5 rounded px-3 py-1.5 text-xs font-medium bg-primary text-white hover:bg-primary-600 disabled:opacity-50 transition-colors"
                >
                    <i class="ri-send-plane-line"></i> Добавить
                </button>
            </div>
        </div>

        <div class="flex-1 overflow-y-auto custom-scrollbar p-6">
            <div v-if="activities.length === 0" class="text-center text-sm text-gray-400 dark:text-gray-500 py-8">
                История пока пуста
            </div>
            <ol v-else class="space-y-5">
                <li v-for="a in activities" :key="a.id" class="flex gap-3">
                    <div :class="metaFor(a.event).badgeClass" class="w-8 h-8 rounded-full flex items-center justify-center shrink-0">
                        <i :class="metaFor(a.event).icon"></i>
                    </div>
                    <div class="flex-1 min-w-0 pb-5 border-b border-gray-100 dark:border-gray-700/50 last:border-0 last:pb-0">
                        <p class="text-sm text-gray-800 dark:text-gray-200">{{ a.description }}</p>
                        <div class="flex items-center gap-1.5 mt-1 text-xs text-gray-400 dark:text-gray-500">
                            <span>{{ a.causer?.name || 'Система' }}</span>
                            <span>·</span>
                            <span :title="formatAbsolute(a.created_at)">{{ formatRelative(a.created_at) }}</span>
                        </div>
                        <div v-if="a.properties?.links?.length" class="flex flex-wrap gap-1.5 mt-2">
                            <Link
                                v-for="link in a.properties.links"
                                :key="`${link.type}-${link.id}`"
                                :href="linkHref(link)"
                                class="inline-flex items-center gap-1 text-xs px-2 py-1 rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-primary/10 hover:text-primary transition-colors"
                            >
                                <i class="ri-arrow-right-up-line"></i> {{ link.label }}
                            </Link>
                        </div>
                    </div>
                </li>
            </ol>
        </div>
    </div>
</template>
