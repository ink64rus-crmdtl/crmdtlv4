<script setup>
import AppSidebar from '@/Components/AppSidebar.vue';
import AppHeader from '@/Components/AppHeader.vue';
</script>

<template>
    <div class="flex h-screen bg-gray-50 font-sans">
        <!-- Левый сайдбар (Midnight Slate) -->
        <AppSidebar />

        <!-- Правая часть (Контент) -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Верхняя панель (Header) -->
            <AppHeader>
                <template #header v-if="$slots.header">
                    <slot name="header" />
                </template>
            </AppHeader>

            <!-- Основной контент страницы -->
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-50 p-6">
                <slot />
            </main>
        </div>
    </div>
</template>