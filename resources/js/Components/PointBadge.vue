<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// Бейджи Локация/Юрлицо для карточек записей (WorkOrder, Transaction и т.п.).
// Показываются по тому же принципу, что и переключатели в топбаре
// (AppHeader.vue) — только если у тенанта реально больше одной локации
// или больше одного юрлица, иначе бейдж — лишний шум для однопопоточных
// тенантов. Источник численности — те же глобальные page.props, что
// использует топбар (HandleInertiaRequests).
const props = defineProps({
    branch: { type: Object, default: null },
    legalEntity: { type: Object, default: null },
});

const page = usePage();

const showBranch = computed(() => (page.props.branches || []).length > 1);
const showLegalEntity = computed(() => (page.props.legal_entities || []).length > 1);
</script>

<template>
    <span v-if="showBranch && branch" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300" :title="branch.deleted_at ? 'Локация удалена из системы' : 'Локация'">
        <i class="ri-store-2-line"></i> {{ branch.name }}<span v-if="branch.deleted_at" class="opacity-70">(удалена)</span>
    </span>
    <span v-if="showLegalEntity && legalEntity" class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300" :title="legalEntity.deleted_at ? 'Юрлицо удалено из системы' : 'Юрлицо'">
        <i class="ri-bank-line"></i> {{ legalEntity.name }}<span v-if="legalEntity.deleted_at" class="opacity-70">(удалено)</span>
    </span>
</template>
