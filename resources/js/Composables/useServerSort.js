import { computed } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Серверная сортировка (в т.ч. многоколоночная с приоритетом) для страниц с
 * DataTable.vue + пагинацией через QueryFilterService::apply(). Текущее
 * состояние читается из props.filters — оно там уже есть автоматически, т.к.
 * контроллеры отдают 'filters' => request()->all(), а не только специально
 * прокинутые ключи.
 *
 * sort_by/sort_dir передаются и принимаются МАССИВАМИ в порядке приоритета
 * (sort_by[]=name&sort_dir[]=asc&sort_by[]=status&sort_dir[]=desc) — первый
 * элемент главный, PHP/Laravel такие query-параметры сам собирает в массив.
 *
 * @param {string} routeName маршрут index-страницы (route().get эндпоинт)
 * @param {() => Object} filtersGetter функция, возвращающая props.filters —
 *   ИМЕННО функция (не сам объект props.filters!), иначе после первого клика
 *   sort застревает на значении на момент вызова composable: Inertia
 *   при каждом router.get подменяет props.filters на НОВЫЙ объект целиком (не
 *   мутирует старый), поэтому переменная, захватившая ссылку один раз при
 *   setup(), реактивность теряет — нужно каждый раз читать props.filters заново.
 * @param {() => Object} extraParams доп. параметры запроса (например search),
 *   чтобы клик по заголовку не сбрасывал уже применённые фильтры/поиск.
 */
export function useServerSort(routeName, filtersGetter, extraParams = () => ({})) {
    // sort_by/sort_dir от Laravel всегда приходят как массив (даже из одной
    // пары) — но на всякий случай оборачиваем скаляр тоже, если кто-то руками
    // вбил ?sort_by=name&sort_dir=asc в адресную строку.
    const sort = computed(() => {
        const f = filtersGetter() || {};
        const keys = [].concat(f.sort_by || []);
        const dirs = [].concat(f.sort_dir || []);
        return keys.map((key, i) => ({ key, dir: dirs[i] === 'desc' ? 'desc' : 'asc' }));
    });

    const onSort = (nextSort) => {
        router.get(route(routeName), {
            ...extraParams(),
            sort_by: nextSort.map((s) => s.key),
            sort_dir: nextSort.map((s) => s.dir),
        }, { preserveState: true, preserveScroll: true });
    };

    return { sort, onSort };
}
