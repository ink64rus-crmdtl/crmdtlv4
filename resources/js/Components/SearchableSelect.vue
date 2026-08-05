<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue';
import { useDebounceFn } from '@vueuse/core';
import axios from 'axios';

const props = defineProps({
    modelValue: [String, Number],
    loadUrl: String, // e.g., '/crm/clients' or '/crm/vehicles'
    placeholder: {
        type: String,
        default: 'Выберите значение...'
    },
    initialLabel: {
        type: String,
        default: ''
    }
});

const emit = defineEmits(['update:modelValue', 'change']);

const isOpen = ref(false);
const search = ref('');
const options = ref([]);
const page = ref(1);
const hasMore = ref(true);
const isLoading = ref(false);
const selectedLabel = ref(props.initialLabel);

const containerRef = ref(null);
const scrollRef = ref(null);

// Watch for internal value changes to sync selection label
watch(() => props.modelValue, (newVal) => {
    if (!newVal) {
        selectedLabel.value = '';
    }
});

watch(() => props.initialLabel, (newVal) => {
    if (newVal) {
        selectedLabel.value = newVal;
    }
});

const fetchOptions = async (reset = false) => {
    if (isLoading.value) return;
    if (reset) {
        page.value = 1;
        options.value = [];
        hasMore.value = true;
    }
    if (!hasMore.value) return;

    isLoading.value = true;
    try {
        const response = await axios.get(props.loadUrl, {
            params: {
                search: search.value,
                page: page.value,
            },
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        });

        const data = response.data;
        const newOptions = data.data || [];
        
        options.value = [...options.value, ...newOptions.map(opt => ({
            id: opt.id,
            label: opt.name || opt.plate_number || opt.value || `${opt.first_name || ''} ${opt.last_name || ''}`.trim()
        }))];

        page.value++;
        hasMore.value = data.next_page_url !== null;
    } catch (error) {
        console.error('Failed to load searchable select options', error);
    } finally {
        isLoading.value = false;
    }
};

const handleSearchInput = useDebounceFn(() => {
    fetchOptions(true);
}, 300);

const handleScroll = (e) => {
    const el = e.target;
    if (el.scrollHeight - el.scrollTop <= el.clientHeight + 10) {
        fetchOptions();
    }
};

const selectOption = (opt) => {
    selectedLabel.value = opt.label;
    emit('update:modelValue', opt.id);
    emit('change', opt.id);
    isOpen.value = false;
    search.value = '';
};

const toggleDropdown = () => {
    isOpen.value = !isOpen.value;
    if (isOpen.value) {
        fetchOptions(true);
    }
};

const closeDropdown = (e) => {
    if (containerRef.value && !containerRef.value.contains(e.target)) {
        isOpen.value = false;
        search.value = '';
    }
};

onMounted(() => {
    document.addEventListener('click', closeDropdown);
});

onUnmounted(() => {
    document.removeEventListener('click', closeDropdown);
});
</script>

<template>
    <div class="relative w-full" ref="containerRef">
        <div 
            @click="toggleDropdown"
            class="flex items-center justify-between w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 cursor-pointer focus:border-primary"
        >
            <span :class="selectedLabel ? 'text-gray-800 dark:text-gray-200' : 'text-gray-400 dark:text-gray-500'">
                {{ selectedLabel || placeholder }}
            </span>
            <i class="ri-arrow-down-s-line text-gray-400"></i>
        </div>

        <Transition
            enter-active-class="transition ease-out duration-100"
            enter-from-class="transform opacity-0 scale-95"
            enter-to-class="transform opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="transform opacity-100 scale-100"
            leave-to-class="transform opacity-0 scale-95"
        >
            <div v-if="isOpen" class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg flex flex-col overflow-hidden">
                <div class="p-2 border-b border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50">
                    <input
                        type="text"
                        v-model="search"
                        @input="handleSearchInput"
                        placeholder="Поиск..."
                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 py-1 px-2.5 text-xs text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0"
                        focus
                    />
                </div>
                <ul 
                    ref="scrollRef"
                    @scroll="handleScroll"
                    class="max-h-56 overflow-y-auto custom-scrollbar py-1 text-sm text-gray-700 dark:text-gray-300"
                >
                    <li 
                        v-for="opt in options" 
                        :key="opt.id"
                        @click="selectOption(opt)"
                        class="px-3 py-2 hover:bg-primary/10 hover:text-primary cursor-pointer transition-colors"
                    >
                        {{ opt.label }}
                    </li>
                    <li v-if="options.length === 0 && !isLoading" class="px-3 py-3 text-center text-xs text-gray-400 dark:text-gray-500">
                        Ничего не найдено
                    </li>
                    <li v-if="isLoading" class="px-3 py-2 text-center text-xs text-gray-400 dark:text-gray-500 flex items-center justify-center gap-1.5">
                        <i class="ri-loader-4-line animate-spin"></i> Загрузка...
                    </li>
                </ul>
            </div>
        </Transition>
    </div>
</template>