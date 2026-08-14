import { ref, computed, unref, isRef } from 'vue';

/**
 * Клиентская сортировка (в т.ч. многоколоночная с приоритетом) для страниц
 * БЕЗ серверной пагинации — весь список уже загружен целиком одним пропом
 * (см. CLAUDE.md: такие списки "не разрастаются вместе со всей клиентской
 * базой" — Warehouse/SuppliersDebt, HR/Payroll подрядчики, Settings/Channels
 * и т.п.). Интерфейс намеренно зеркалит useServerSort.js (те же `sort`/
 * `onSort`), чтобы <DataTable> не знал и не заботился, серверная это
 * сортировка через router.get или локальная по массиву — родитель просто
 * передаёт готовый отсортированный `rows`.
 *
 * @param {Array|import('vue').Ref<Array>|() => Array} rows исходный массив
 *   строк — массив, ref на него, ИЛИ функция-геттер (`() => props.suppliers`).
 *   Функция-геттер безопаснее для пропсов, которые Inertia/родитель может
 *   целиком переподставлять новым объектом (та же причина, что и у
 *   useServerSort.js: захваченная один раз ссылка реактивность теряет).
 * @returns {{ sort: Ref<Array>, onSort: (next: Array) => void, sortedRows: ComputedRef<Array> }}
 */
export function useClientSort(rows) {
    const sort = ref([]);

    const onSort = (nextSort) => {
        sort.value = nextSort;
    };

    const sortedRows = computed(() => {
        const list = (typeof rows === 'function' ? rows() : isRef(rows) ? unref(rows) : rows) || [];
        if (sort.value.length === 0) return list;

        return [...list].sort((a, b) => {
            for (const { key, dir } of sort.value) {
                const av = a[key];
                const bv = b[key];
                if (av === bv) continue;
                // null/undefined всегда в начале (как NULL в MySQL ASC) — независимо от направления.
                if (av === null || av === undefined) return -1;
                if (bv === null || bv === undefined) return 1;
                const cmp = av > bv ? 1 : -1;
                return dir === 'desc' ? -cmp : cmp;
            }
            return 0;
        });
    });

    return { sort, onSort, sortedRows };
}
