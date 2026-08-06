import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue';

/**
 * Настраиваемая мышью высота строки поста для видов календаря "Неделя (посты слева)"
 * и "День (посты слева)". По умолчанию высота вычисляется как доступная высота
 * экрана, делённая на количество строк, но не более чем на MAX_DEFAULT_VISIBLE_ROWS —
 * дальше включается вертикальный скролл контейнера. Как только пользователь один раз
 * потянул мышью нижнюю границу строки, высота фиксируется в localStorage и больше не
 * пересчитывается автоматически (общая на оба вида, т.к. это одно и то же визуальное
 * понятие "высота строки поста").
 */
const STORAGE_KEY = 'crm:calendar:postRowHeight';
const MIN_ROW_HEIGHT = 32;
const MAX_ROW_HEIGHT = 280;
const MAX_DEFAULT_VISIBLE_ROWS = 10;
const BOTTOM_MARGIN = 16;

export function usePostRowHeight(containerRef, rowsCountRef) {
    const rowHeight = ref(56);
    const containerMaxHeight = ref(null);
    const isDragging = ref(false);
    let hasCustomHeight = false;

    const computeAvailableHeight = () => {
        if (!containerRef.value) return null;
        const top = containerRef.value.getBoundingClientRect().top;
        return Math.max(200, Math.floor(window.innerHeight - top - BOTTOM_MARGIN));
    };

    const applyDefaultHeight = () => {
        const available = computeAvailableHeight();
        if (!available) return;
        containerMaxHeight.value = available;
        if (hasCustomHeight) return;
        const rowsForDefault = Math.min(Math.max(rowsCountRef.value, 1), MAX_DEFAULT_VISIBLE_ROWS);
        rowHeight.value = Math.min(MAX_ROW_HEIGHT, Math.max(MIN_ROW_HEIGHT, Math.floor(available / rowsForDefault)));
    };

    const recalcContainerHeight = () => {
        const available = computeAvailableHeight();
        if (available) containerMaxHeight.value = available;
    };

    const onDragMove = (e) => {
        rowHeight.value = Math.min(MAX_ROW_HEIGHT, Math.max(MIN_ROW_HEIGHT, Math.round(rowHeight.value + (e.movementY || 0))));
    };

    const onDragEnd = () => {
        isDragging.value = false;
        hasCustomHeight = true;
        localStorage.setItem(STORAGE_KEY, String(rowHeight.value));
        document.body.style.cursor = '';
        document.removeEventListener('mousemove', onDragMove);
        document.removeEventListener('mouseup', onDragEnd);
    };

    const startDrag = (e) => {
        e.preventDefault();
        isDragging.value = true;
        document.body.style.cursor = 'row-resize';
        document.addEventListener('mousemove', onDragMove);
        document.addEventListener('mouseup', onDragEnd);
    };

    onMounted(async () => {
        await nextTick();
        const saved = Number(localStorage.getItem(STORAGE_KEY));
        if (saved && !Number.isNaN(saved) && saved > 0) {
            hasCustomHeight = true;
            rowHeight.value = Math.min(MAX_ROW_HEIGHT, Math.max(MIN_ROW_HEIGHT, saved));
            recalcContainerHeight();
        } else {
            applyDefaultHeight();
        }
        window.addEventListener('resize', recalcContainerHeight);
    });

    onUnmounted(() => {
        window.removeEventListener('resize', recalcContainerHeight);
        document.removeEventListener('mousemove', onDragMove);
        document.removeEventListener('mouseup', onDragEnd);
    });

    watch(rowsCountRef, () => {
        if (!hasCustomHeight) applyDefaultHeight();
    });

    return { rowHeight, containerMaxHeight, isDragging, startDrag };
}
