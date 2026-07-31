<script setup>
import { computed, onMounted, onUnmounted, watch } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    maxWidth: {
        type: String,
        default: 'md',
    },
});

const emit = defineEmits(['close']);

const maxWidthClass = computed(() => {
    return {
        sm: 'max-w-sm',
        md: 'max-w-md',
        lg: 'max-w-lg',
        xl: 'max-w-xl',
        '2xl': 'max-w-2xl',
        '3xl': 'max-w-3xl',
    }[props.maxWidth] || 'max-w-md';
});

watch(
    () => props.show,
    () => {
        if (props.show) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    }
);

const close = () => {
    emit('close');
};

const closeOnEscape = (e) => {
    if (e.key === 'Escape' && props.show) {
        close();
    }
};

onMounted(() => document.addEventListener('keydown', closeOnEscape));
onUnmounted(() => {
    document.removeEventListener('keydown', closeOnEscape);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="show" class="fixed inset-0 z-[100] flex justify-end">
                <!-- Backdrop (Темный фон) -->
                <div class="fixed inset-0 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm" @click="close"></div>

                <!-- Panel (Выезжающая панель) -->
                <Transition
                    enter-active-class="transform transition ease-out duration-300 sm:duration-400"
                    enter-from-class="translate-x-full"
                    enter-to-class="translate-x-0"
                    leave-active-class="transform transition ease-in duration-200 sm:duration-300"
                    leave-from-class="translate-x-0"
                    leave-to-class="translate-x-full"
                >
                    <div v-show="show" :class="['relative z-10 w-full h-full bg-white dark:bg-[#313a46] shadow-2xl flex flex-col pointer-events-auto border-l border-gray-200 dark:border-gray-700', maxWidthClass]">
                        <slot />
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>