<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, nextTick } from 'vue';

const props = defineProps({
    logs: String,
});

const activeTab = ref('logs');
const logContainer = ref(null);

// Прокрутка логов вниз при загрузке
onMounted(() => {
    if (logContainer.value) {
        nextTick(() => {
            logContainer.value.scrollTop = logContainer.value.scrollHeight;
        });
    }
});
</script>

<template>
    <Head title="Система" />

    <AuthenticatedLayout>
        <template #header>
            Служебный раздел
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-slate-600">
            
            <!-- Навигация (Attex Tabs) -->
            <div class="border-b border-gray-200 dark:border-gray-700 mb-6">
                <nav class="-mb-px flex space-x-8">
                    <button
                        @click="activeTab = 'logs'"
                        :class="[
                            activeTab === 'logs'
                                ? 'border-primary text-primary'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600',
                            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors focus:outline-none'
                        ]"
                    >
                        Системные логи
                    </button>
                    <button
                        @click="activeTab = 'backup'"
                        :class="[
                            activeTab === 'backup'
                                ? 'border-primary text-primary'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300 dark:hover:border-gray-600',
                            'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-colors focus:outline-none'
                        ]"
                    >
                        Резервное копирование
                    </button>
                </nav>
            </div>

            <!-- Вкладка: Логи -->
            <div v-if="activeTab === 'logs'" class="bg-white border border-gray-200/80 rounded-xl shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden flex flex-col" style="height: 70vh;">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700/80 flex justify-between items-center bg-gray-50/50 dark:bg-gray-800/50">
                    <h2 class="text-sm font-bold text-slate-800 dark:text-slate-200">laravel.log (Последние 1000 строк)</h2>
                    <a :href="route('system.index')" class="text-xs font-medium text-primary hover:underline">Обновить</a>
                </div>
                <div class="flex-1 p-0 overflow-hidden bg-[#1e1e1e]">
                    <pre ref="logContainer" class="h-full w-full p-4 overflow-auto text-xs text-green-400 font-mono leading-relaxed whitespace-pre-wrap break-words">{{ logs }}</pre>
                </div>
            </div>

            <!-- Вкладка: Бэкап и Дамп -->
            <div v-if="activeTab === 'backup'" class="bg-white border border-gray-200/80 rounded-xl shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6">
                <h2 class="text-lg font-bold text-slate-800 dark:text-slate-200 mb-2">Создание Дампа для LLM</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-6 max-w-3xl">
                    Этот инструмент собирает текущую структуру базы данных тенанта и содержимое всех измененных файлов проекта (Контроллеры, Модели, Vue-компоненты) в единый Markdown-файл. Используйте его для передачи контекста нейросети.
                </p>
                
                <a 
                    :href="route('dump')" 
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-5 py-2.5 text-sm font-medium text-white hover:bg-primary/90 transition-colors shadow-sm"
                >
                    <i class="ri-download-cloud-2-line text-lg"></i>
                    Скачать CRM Дамп
                </a>
            </div>

        </div>
    </AuthenticatedLayout>
</template>