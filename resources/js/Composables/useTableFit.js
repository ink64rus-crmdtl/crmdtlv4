import { ref } from 'vue';

/**
 * Общий стор "резинового" вида таблиц (fitColumns) для всех компонентов одной
 * страницы: кнопка-переключатель живёт в DataTableToolbar, а DataTable и raw-таблицы
 * читают тот же флаг. Состояние реактивное на уровне модуля (Map по storageKey),
 * поэтому каждый вызов useTableFit() с одним ключом возвращает ОДИН и тот же ref.
 *
 * Ключ по умолчанию — имя текущего роута (route().current()), т.е. выбор вида
 * запоминается постранично в localStorage (table-fit.{storageKey}).
 */
const state = new Map();

const defaultKey = () => {
    try {
        return route().current() || 'default';
    } catch {
        return 'default';
    }
};

export function useTableFit(storageKey = null) {
    const key = storageKey || defaultKey();
    if (!state.has(key)) {
        state.set(key, ref(localStorage.getItem(`table-fit.${key}`) === '1'));
    }
    const fit = state.get(key);
    const setFit = (value) => {
        fit.value = value;
        localStorage.setItem(`table-fit.${key}`, value ? '1' : '0');
    };
    return { fit, setFit };
}
