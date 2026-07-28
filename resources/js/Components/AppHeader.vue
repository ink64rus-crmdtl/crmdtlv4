<script setup>
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { usePage } from '@inertiajs/vue3';

const user = usePage().props.auth.user;
</script>

<template>
    <header class="bg-white shadow-sm border-b border-gray-200">
        <div class="flex items-center justify-between px-6 py-4">
            <div class="flex items-center">
                <!-- Здесь в будущем будет поиск или хлебные крошки -->
                <h2 class="text-xl font-semibold text-gray-800" v-if="$slots.header">
                    <slot name="header" />
                </h2>
            </div>

            <div class="flex items-center gap-4">
                <!-- Кнопка Дампа -->
                <a :href="route('dump')" class="text-sm font-medium text-gray-500 hover:text-indigo-600 transition-colors">
                    Создать Дамп
                </a>

                <!-- Профиль -->
                <Dropdown align="right" width="48">
                    <template #trigger>
                        <button class="flex items-center gap-2 text-sm font-medium text-gray-700 hover:text-indigo-600 transition-colors focus:outline-none">
                            <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-bold">
                                {{ user.name.charAt(0) }}
                            </div>
                            <span>{{ user.name }}</span>
                            <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </template>

                    <template #content>
                        <DropdownLink :href="route('profile.edit')">Профиль</DropdownLink>
                        <DropdownLink :href="route('logout')" method="post" as="button">Выйти</DropdownLink>
                    </template>
                </Dropdown>
            </div>
        </div>
    </header>
</template>