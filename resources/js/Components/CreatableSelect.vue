<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    modelValue: {
        type: String,
        default: ''
    },
    options: {
        type: Array,
        default: () => []
    },
    lookupType: {
        type: String,
        required: true
    },
    placeholder: {
        type: String,
        default: 'Выберите или введите...'
    }
});

const emit = defineEmits(['update:modelValue']);

const isOpen = ref(false);
const search = ref(props.modelValue || '');
const containerRef = ref(null);
const inputRef = ref(null);

watch(() => props.modelValue, (newVal) => {
    if (newVal !== search.value) {
        search.value = newVal || '';
    }
});

const filteredOptions = computed(() => {
    if (!search.value) return props.options;
    const lowerSearch = search.value.toLowerCase();
    return props.options.filter(opt => opt.toLowerCase().includes(lowerSearch));
});

const showAddOption = computed(() => {
    if (!search.value) return false;
    return !props.options.some(opt => opt.toLowerCase() === search.value.toLowerCase());
});

const selectOption = (option) => {
    search.value = option;
    emit('update:modelValue', option);
    isOpen.value = false;
};

const addOption = async () => {
    if (!search.value) return;
    const newValue = search.value;
    
    try {
        await axios.post(route('settings.lookups.store'), {
            type: props.lookupType,
            value: newValue,
            is_active: true
        });
        // Эмитим новое значение сразу, чтобы форма обновилась
        emit('update:modelValue', newValue);
        isOpen.value = false;
    } catch (error) {
        console.error('Failed to add lookup value', error);
        // Даже если запрос упал (например, дубликат), мы все равно выбираем его локально
        emit('update:modelValue', newValue);
        isOpen.value = false;
    }
};

const handleInput = (e) => {
    search.value = e.target.value;
    emit('update:modelValue', search.value);
    isOpen.value = true;
};

const closeDropdown = (e) => {
    if (containerRef.value && !containerRef.value.contains(e.target)) {
        isOpen.value = false;
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
        <div class="relative">
            <input
                ref="inputRef"
                type="text"
                :value="search"
                @input="handleInput"
                @focus="isOpen = true"
                :placeholder="placeholder"
                class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 placeholder:text-gray-400 dark:placeholder:text-gray-500"
            />
            <div class="absolute inset-y-0 right-0 flex items-center pr-2 pointer-events-none">
                <i class="ri-arrow-down-s-line text-gray-400"></i>
            </div>
        </div>

        <Transition
            enter-active-class="transition ease-out duration-100"
            enter-from-class="transform opacity-0 scale-95"
            enter-to-class="transform opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="transform opacity-100 scale-100"
            leave-to-class="transform opacity-0 scale-95"
        >
            <div v-if="isOpen && (filteredOptions.length > 0 || showAddOption)" class="absolute z-50 w-full mt-1 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg max-h-60 overflow-y-auto custom-scrollbar">
                <ul class="py-1 text-sm text-gray-700 dark:text-gray-300">
                    <li 
                        v-for="option in filteredOptions" 
                        :key="option"
                        @click="selectOption(option)"
                        class="px-3 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer transition-colors"
                    >
                        {{ option }}
                    </li>
                    <li 
                        v-if="showAddOption"
                        @click="addOption"
                        class="px-3 py-2 hover:bg-primary/10 text-primary cursor-pointer transition-colors border-t border-gray-100 dark:border-gray-700 flex items-center gap-2 font-medium"
                    >
                        <i class="ri-add-line"></i> Добавить "{{ search }}"
                    </li>
                </ul>
            </div>
        </Transition>
    </div>
</template>