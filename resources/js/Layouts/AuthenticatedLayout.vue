<!-- resources/js/Layouts/AuthenticatedLayout.vue -->
<script setup>
import AppSidebar from '@/Components/AppSidebar.vue';
import AppHeader from '@/Components/AppHeader.vue';
</script>

<template>
    <div class="flex h-screen bg-[#f2f2f7] dark:bg-[#2d333c] font-sans text-[#6c757d] dark:text-[#aab8c5]">
        <!-- Левый сайдбар (Attex Theme) -->
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
            <main class="flex-1 overflow-x-hidden overflow-y-auto p-1.5">
                <slot />
            </main>
        </div>
    </div>
</template>