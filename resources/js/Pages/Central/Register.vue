<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    company_name: '',
    subdomain: '',
    country_code: 'DE',
    base_currency: 'EUR',
    timezone: 'Europe/Berlin',
    default_locale: 'de',
    admin_name: '',
    admin_email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/register', {
        onError: () => {
            // Автоматическая прокрутка к верху формы при ошибках
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    });
};
</script>

<template>
    <Head title="Регистрация детейлинг-центра" />

    <div class="min-h-screen bg-slate-950 flex flex-col justify-center py-12 sm:px-6 lg:px-8 font-sans">
        <div class="sm:mx-auto sm:w-full sm:max-w-md">
            <h2 class="text-center text-3xl font-extrabold text-white">
                CRM<span class="text-indigo-500">DTL</span>
            </h2>
            <p class="mt-2 text-center text-sm text-slate-400">
                Создайте собственную CRM за 1 минуту
            </p>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-xl">
            <div class="bg-slate-900 py-8 px-4 shadow-xl border border-slate-800 sm:rounded-lg sm:px-10">
                
                <!-- Блок общих ошибок валидации -->
                <div v-if="form.hasErrors" class="mb-6 p-4 rounded-md bg-red-900/50 border border-red-700 text-red-200 text-sm">
                    <p class="font-bold mb-1">Пожалуйста, исправьте следующие ошибки:</p>
                    <ul class="list-disc list-inside space-y-1">
                        <li v-for="(error, key) in form.errors" :key="key">{{ error }}</li>
                    </ul>
                </div>

                <form @submit.prevent="submit" class="space-y-6">
                    
                    <!-- Блок информации о компании -->
                    <div>
                        <h3 class="text-lg font-medium text-white border-b border-slate-800 pb-2 mb-4">
                            1. Данные компании
                        </h3>
                        
                        <div class="grid grid-cols-1 gap-y-4 sm:grid-cols-2 sm:gap-x-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-300">Название компании</label>
                                <input 
                                    v-model="form.company_name" 
                                    type="text" 
                                    required 
                                    placeholder="Detailing Pro" 
                                    class="mt-1 block w-full rounded-md bg-slate-950 border-slate-700 text-white placeholder-slate-500 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 focus:bg-slate-950 sm:text-sm" 
                                />
                                <span v-if="form.errors.company_name" class="text-xs text-red-400 mt-1 block">{{ form.errors.company_name }}</span>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-300">Желаемый поддомен</label>
                                <div class="mt-1 flex rounded-md shadow-sm">
                                    <input 
                                        v-model="form.subdomain" 
                                        type="text" 
                                        required 
                                        placeholder="mycompany" 
                                        class="block w-full rounded-none rounded-l-md bg-slate-950 border-slate-700 text-white placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500 focus:bg-slate-950 sm:text-sm" 
                                    />
                                    <span class="inline-flex items-center rounded-r-md border border-l-0 border-slate-700 bg-slate-800 px-3 text-slate-300 sm:text-sm">.localhost</span>
                                </div>
                                <span v-if="form.errors.subdomain" class="text-xs text-red-400 mt-1 block">{{ form.errors.subdomain }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Блок региональных настроек -->
                    <div>
                        <h3 class="text-lg font-medium text-white border-b border-slate-800 pb-2 mb-4">
                            2. Региональные настройки
                        </h3>

                        <div class="grid grid-cols-1 gap-y-4 sm:grid-cols-2 sm:gap-x-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-300">Страна</label>
                                <select v-model="form.country_code" class="mt-1 block w-full rounded-md bg-slate-950 border-slate-700 text-white focus:border-indigo-500 focus:ring-indigo-500 focus:bg-slate-950 sm:text-sm">
                                    <option value="DE" class="bg-slate-900 text-white">Германия (DE)</option>
                                    <option value="RU" class="bg-slate-900 text-white">Россия (RU)</option>
                                    <option value="KZ" class="bg-slate-900 text-white">Казахстан (KZ)</option>
                                    <option value="US" class="bg-slate-900 text-white">США (US)</option>
                                </select>
                                <span v-if="form.errors.country_code" class="text-xs text-red-400 mt-1 block">{{ form.errors.country_code }}</span>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-300">Основная валюта</label>
                                <select v-model="form.base_currency" class="mt-1 block w-full rounded-md bg-slate-950 border-slate-700 text-white focus:border-indigo-500 focus:ring-indigo-500 focus:bg-slate-950 sm:text-sm">
                                    <option value="EUR" class="bg-slate-900 text-white">EUR (€)</option>
                                    <option value="RUB" class="bg-slate-900 text-white">RUB (₽)</option>
                                    <option value="KZT" class="bg-slate-900 text-white">KZT (₸)</option>
                                    <option value="USD" class="bg-slate-900 text-white">USD ($)</option>
                                </select>
                                <span v-if="form.errors.base_currency" class="text-xs text-red-400 mt-1 block">{{ form.errors.base_currency }}</span>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-300">Часовой пояс</label>
                                <select v-model="form.timezone" class="mt-1 block w-full rounded-md bg-slate-950 border-slate-700 text-white focus:border-indigo-500 focus:ring-indigo-500 focus:bg-slate-950 sm:text-sm">
                                    <option value="Europe/Berlin" class="bg-slate-900 text-white">Europe/Berlin (UTC+1)</option>
                                    <option value="Europe/Moscow" class="bg-slate-900 text-white">Europe/Moscow (UTC+3)</option>
                                    <option value="Asia/Almaty" class="bg-slate-900 text-white">Asia/Almaty (UTC+5)</option>
                                    <option value="America/New_York" class="bg-slate-900 text-white">America/New_York (UTC-5)</option>
                                </select>
                                <span v-if="form.errors.timezone" class="text-xs text-red-400 mt-1 block">{{ form.errors.timezone }}</span>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-300">Язык интерфейса</label>
                                <select v-model="form.default_locale" class="mt-1 block w-full rounded-md bg-slate-950 border-slate-700 text-white focus:border-indigo-500 focus:ring-indigo-500 focus:bg-slate-950 sm:text-sm">
                                    <option value="de" class="bg-slate-900 text-white">Deutsch</option>
                                    <option value="ru" class="bg-slate-900 text-white">Русский</option>
                                    <option value="en" class="bg-slate-900 text-white">English</option>
                                </select>
                                <span v-if="form.errors.default_locale" class="text-xs text-red-400 mt-1 block">{{ form.errors.default_locale }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Блок Владельца -->
                    <div>
                        <h3 class="text-lg font-medium text-white border-b border-slate-800 pb-2 mb-4">
                            3. Аккаунт администратора
                        </h3>

                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-300">Ваше имя</label>
                                <input 
                                    v-model="form.admin_name" 
                                    type="text" 
                                    required 
                                    placeholder="Алексей" 
                                    class="mt-1 block w-full rounded-md bg-slate-950 border-slate-700 text-white placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500 focus:bg-slate-950 sm:text-sm" 
                                />
                                <span v-if="form.errors.admin_name" class="text-xs text-red-400 mt-1 block">{{ form.errors.admin_name }}</span>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-slate-300">Email (Логин)</label>
                                <input 
                                    v-model="form.admin_email" 
                                    type="email" 
                                    required 
                                    placeholder="admin@mycompany.com" 
                                    class="mt-1 block w-full rounded-md bg-slate-950 border-slate-700 text-white placeholder-slate-500 focus:border-indigo-500 focus:ring-indigo-500 focus:bg-slate-950 sm:text-sm" 
                                />
                                <span v-if="form.errors.admin_email" class="text-xs text-red-400 mt-1 block">{{ form.errors.admin_email }}</span>
                            </div>

                            <div class="grid grid-cols-1 gap-y-4 sm:grid-cols-2 sm:gap-x-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-300">Пароль</label>
                                    <input 
                                        v-model="form.password" 
                                        type="password" 
                                        required 
                                        class="mt-1 block w-full rounded-md bg-slate-950 border-slate-700 text-white focus:border-indigo-500 focus:ring-indigo-500 focus:bg-slate-950 sm:text-sm" 
                                    />
                                    <span v-if="form.errors.password" class="text-xs text-red-400 mt-1 block">{{ form.errors.password }}</span>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-slate-300">Подтверждение пароля</label>
                                    <input 
                                        v-model="form.password_confirmation" 
                                        type="password" 
                                        required 
                                        class="mt-1 block w-full rounded-md bg-slate-950 border-slate-700 text-white focus:border-indigo-500 focus:ring-indigo-500 focus:bg-slate-950 sm:text-sm" 
                                    />
                                    <span v-if="form.errors.password_confirmation" class="text-xs text-red-400 mt-1 block">{{ form.errors.password_confirmation }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <button 
                            type="submit" 
                            :disabled="form.processing" 
                            class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 transition-colors"
                        >
                            <span v-if="form.processing">Создаем вашу CRM...</span>
                            <span v-else>Зарегистрировать компанию</span>
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>
</template>