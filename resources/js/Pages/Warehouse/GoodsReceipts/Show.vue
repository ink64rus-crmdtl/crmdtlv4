<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import CollapsiblePanel from '@/Components/CollapsiblePanel.vue';
import ActivityTimeline from '@/Components/ActivityTimeline.vue';
import PointBadge from '@/Components/PointBadge.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    receipt: Object,
    activities: { type: Array, default: () => [] },
});

const page = usePage();

const activeMainTab = ref('items');

const statusMeta = {
    posted: { label: 'Оприходована', class: 'bg-success/10 text-success' },
    canceled: { label: 'Отменена', class: 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' },
};

const getLocalizedLabel = (label) => {
    if (!label) return '';
    if (typeof label === 'string') {
        try { label = JSON.parse(label); } catch (e) { return label; }
    }
    return label['ru'] || label['en'] || Object.values(label)[0] || '';
};

const formatMoney = (amount) => new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(amount / 100);

const cancelReceipt = () => {
    if (!confirm(`Отменить накладную №${String(props.receipt.id).padStart(6, '0')}? Все её движения будут реверсированы. Возможно, только если товар с неё ещё не был списан.`)) return;
    router.post(route('warehouse.goods-receipts.cancel', props.receipt.id), {}, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Накладная №${String(receipt.id).padStart(6, '0')}`" />

    <AuthenticatedLayout>
        <template #header>
            <Link :href="route('warehouse.goods-receipts.index')" class="text-gray-400 hover:text-primary transition-colors mr-2"><i class="ri-arrow-left-line"></i></Link>
            Приходная накладная №{{ String(receipt.id).padStart(6, '0') }}
        </template>

        <div v-if="page.props.errors.error" class="w-[99%] mx-auto mb-4 p-4 bg-danger/10 border border-danger/20 rounded-md text-sm text-danger font-medium flex items-start gap-3">
            <i class="ri-error-warning-fill text-xl shrink-0"></i>
            <div><p class="font-bold mb-1">Ошибка:</p><p>{{ page.props.errors.error }}</p></div>
        </div>

        <!-- TRI-STATE 2: Карточка (w-[99%] mx-auto, трёхколоночная) -->
        <div class="w-[99%] mx-auto flex flex-col lg:flex-row gap-6 font-sans text-slate-600">

            <CollapsiblePanel storage-key="goods-receipt-card-left" side="left">

                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex flex-col items-center text-center">
                    <div class="w-20 h-20 rounded bg-primary/10 flex items-center justify-center text-primary font-bold text-3xl mb-4">
                        <i class="ri-truck-line"></i>
                    </div>
                    <h2 class="text-lg font-bold text-gray-800 dark:text-gray-200 leading-tight mb-1 font-mono">
                        Накладная №{{ String(receipt.id).padStart(6, '0') }}
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 font-medium mb-1">
                        от {{ new Date(receipt.receipt_date).toLocaleDateString('ru-RU') }}
                    </p>
                    <p v-if="receipt.supplier_document_number" class="text-xs text-gray-400 mb-4">их №{{ receipt.supplier_document_number }}</p>
                    <div class="flex flex-wrap justify-center gap-2 mt-1">
                        <span :class="[statusMeta[receipt.status]?.class, 'inline-flex items-center py-0.5 px-2 rounded text-xs font-bold uppercase tracking-wide']">{{ statusMeta[receipt.status]?.label || receipt.status }}</span>
                        <PointBadge :branch="receipt.branch" :legal-entity="receipt.legal_entity" />
                    </div>
                </div>

                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30 flex justify-between items-center">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Поставщик</h3>
                        <Link v-if="receipt.supplier" :href="route('crm.clients.show', receipt.supplier.id)" class="text-primary hover:text-primary-600 transition-colors text-sm font-medium">
                            Перейти <i class="ri-arrow-right-s-line"></i>
                        </Link>
                    </div>
                    <div class="p-6" v-if="receipt.supplier">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary font-bold shrink-0">
                                {{ receipt.supplier.name.charAt(0) }}
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-800 dark:text-gray-200 leading-tight">{{ receipt.supplier.name }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ receipt.supplier.phone || 'Нет телефона' }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Склад</h3>
                    </div>
                    <div class="p-6 space-y-3">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-gray-500 dark:text-gray-400 shrink-0">
                                <i class="ri-building-4-line text-xl"></i>
                            </div>
                            <p class="text-sm font-bold text-gray-800 dark:text-gray-200 leading-tight">{{ receipt.warehouse?.name || '—' }}</p>
                        </div>
                        <div v-if="receipt.comment" class="pt-3 border-t border-gray-100 dark:border-gray-700/50">
                            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-1">Комментарий</p>
                            <p class="text-sm text-gray-700 dark:text-gray-300">{{ receipt.comment }}</p>
                        </div>
                    </div>
                </div>
            </CollapsiblePanel>

            <!-- Центральная колонка -->
            <div class="w-full lg:flex-1 lg:min-w-0 space-y-6">
                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 flex flex-col min-h-[400px]">
                    <div class="flex flex-wrap gap-x-6 gap-y-1 border-b border-gray-200 dark:border-gray-700 px-6 bg-gray-50/50 dark:bg-gray-800/50">
                        <button @click="activeMainTab = 'items'" :class="[activeMainTab === 'items' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-box-3-line"></i> Позиции
                        </button>
                        <button @click="activeMainTab = 'history'" :class="[activeMainTab === 'history' ? 'border-primary text-primary font-bold border-b-2' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300 font-medium border-b-2', 'py-3.5 px-2 text-sm transition-colors focus:outline-none flex items-center gap-2']">
                            <i class="ri-history-line"></i> История
                        </button>
                    </div>

                    <div v-if="activeMainTab === 'items'" class="flex-1 flex flex-col">
                        <div class="overflow-x-auto">
                            <table class="min-w-full text-left whitespace-nowrap">
                                <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                                    <tr>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Товар</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Кол-во</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Цена</th>
                                        <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Сумма</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="item in receipt.items" :key="item.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40">
                                        <td class="py-3 px-6 text-sm">
                                            <div class="font-bold text-gray-800 dark:text-gray-200">{{ item.product ? getLocalizedLabel(item.product.name) : '—' }}</div>
                                            <div v-if="item.batch_number" class="text-xs text-gray-500 mt-0.5">Партия: {{ item.batch_number }}</div>
                                        </td>
                                        <td class="py-3 px-6 text-sm text-gray-800 dark:text-gray-200 text-right">{{ parseFloat(item.quantity) }} {{ item.product?.unit }}</td>
                                        <td class="py-3 px-6 text-sm text-gray-800 dark:text-gray-200 text-right">{{ formatMoney(item.cost_price) }}</td>
                                        <td class="py-3 px-6 text-sm font-bold text-gray-800 dark:text-gray-200 text-right">{{ formatMoney(Math.round(item.quantity * item.cost_price)) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="activeMainTab === 'history'" class="flex-1 flex flex-col min-h-0">
                        <ActivityTimeline :activities="activities" />
                    </div>
                </div>
            </div>

            <CollapsiblePanel storage-key="goods-receipt-card-right" side="right">

                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Итоги</h3>
                    </div>
                    <div class="p-6 space-y-4">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-500 dark:text-gray-400">Позиций:</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ receipt.items.length }}</span>
                        </div>
                        <div class="pt-4 border-t border-gray-200 dark:border-gray-700 flex justify-between items-center">
                            <span class="font-bold text-gray-800 dark:text-gray-200">Сумма закупки:</span>
                            <span class="text-xl font-bold text-success">{{ formatMoney(receipt.total_value) }}</span>
                        </div>
                    </div>
                </div>

                <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/30">
                        <h3 class="text-sm font-bold text-gray-800 dark:text-gray-200">Действия</h3>
                    </div>
                    <div class="p-6">
                        <button
                            v-if="receipt.status === 'posted'"
                            @click="cancelReceipt"
                            class="w-full inline-flex items-center justify-center gap-1.5 rounded-md px-4 py-2 text-sm font-semibold transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white"
                        >
                            <i class="ri-close-circle-line"></i> Отменить накладную
                        </button>
                        <p v-if="receipt.status === 'posted'" class="text-[11px] text-gray-400 mt-2">Реверсирует движения и остатки. Недоступно, если товар с этой накладной уже частично списан — тогда система объяснит это в ошибке.</p>
                        <p v-else class="text-sm text-gray-400 text-center">Накладная отменена, действия недоступны.</p>
                    </div>
                </div>
            </CollapsiblePanel>
        </div>
    </AuthenticatedLayout>
</template>
