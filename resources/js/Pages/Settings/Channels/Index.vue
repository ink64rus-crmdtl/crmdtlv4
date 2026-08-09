<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHelper from '@/Components/PageHelper.vue';
import SettingsNav from '@/Components/SettingsNav.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed, onBeforeUnmount } from 'vue';
import axios from 'axios';

const props = defineProps({
    channels: { type: Array, default: () => [] },
    branches: { type: Array, default: () => [] },
});

const providerLabels = {
    wappi_pro: 'Wappi.Pro',
    sms_aero: 'SMS Aero',
};

const messengerTypeLabels = {
    whatsapp: 'WhatsApp',
    telegram: 'Telegram',
    max: 'MAX',
    sms: 'SMS',
};

const statusLabels = {
    pending: 'Не подключён',
    connected: 'Подключён',
    disconnected: 'Отключён',
};

const statusClasses = {
    pending: 'bg-warning/10 text-warning',
    connected: 'bg-success/10 text-success',
    disconnected: 'bg-danger/10 text-danger',
};

const isMessenger = (provider) => provider === 'wappi_pro';

// --- Форма добавления/редактирования канала ---
const isModalOpen = ref(false);
const editingChannel = ref(null);

const form = useForm({
    branch_id: '',
    name: '',
    provider: 'wappi_pro',
    messenger_type: 'whatsapp',
    phone_number: '',
    credentials: {},
    is_active: true,
});

const openModal = (channel = null) => {
    editingChannel.value = channel;
    if (channel) {
        form.branch_id = channel.branch_id ?? '';
        form.name = channel.name;
        form.provider = channel.provider;
        form.messenger_type = channel.messenger_type ?? (channel.provider === 'sms_aero' ? 'sms' : 'whatsapp');
        form.phone_number = channel.phone_number ?? '';
        form.credentials = channel.provider === 'sms_aero'
            ? { email: '', api_key: '', sign: '' }
            : {};
        form.is_active = Boolean(channel.is_active);
    } else {
        form.reset();
        form.provider = 'wappi_pro';
        form.messenger_type = 'whatsapp';
        form.credentials = {};
        form.is_active = true;
    }
    isModalOpen.value = true;
};

const closeModal = () => {
    isModalOpen.value = false;
    editingChannel.value = null;
    form.reset();
    form.clearErrors();
};

const onProviderChange = () => {
    if (form.provider === 'sms_aero') {
        form.messenger_type = 'sms';
        form.credentials = { email: '', api_key: '', sign: '' };
    } else {
        form.messenger_type = 'whatsapp';
        form.credentials = {};
    }
};

const submit = () => {
    if (editingChannel.value) {
        form.put(route('settings.channels.update', editingChannel.value.id), { onSuccess: closeModal });
    } else {
        form.post(route('settings.channels.store'), { onSuccess: closeModal });
    }
};

const deleteChannel = (channel) => {
    if (confirm(`Удалить канал "${channel.name}"? Переписки останутся в истории, но отправка через этот канал станет недоступна.`)) {
        form.delete(route('settings.channels.destroy', channel.id));
    }
};

// --- Повторная попытка создать профиль у провайдера (если создание канала
// прошло, а провижининг — нет, см. ChannelController::attemptProvisioning) ---
const retryingId = ref(null);
const retryProvision = (channel) => {
    retryingId.value = channel.id;
    router.post(route('settings.channels.retry-provision', channel.id), {}, {
        onFinish: () => { retryingId.value = null; },
    });
};

// --- QR-подключение ---
const isQrModalOpen = ref(false);
const qrTarget = ref(null);
const qrImage = ref(null);
const qrLoading = ref(false);
const qrStatus = ref('pending');
let pollTimer = null;

const openQrModal = async (channel) => {
    qrTarget.value = channel;
    qrImage.value = null;
    qrStatus.value = channel.status;
    isQrModalOpen.value = true;
    await loadQr();
    pollTimer = setInterval(pollStatus, 4000);
};

const closeQrModal = () => {
    isQrModalOpen.value = false;
    qrTarget.value = null;
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
};

const loadQr = async () => {
    qrLoading.value = true;
    try {
        const { data } = await axios.get(route('settings.channels.qr', qrTarget.value.id));
        qrImage.value = data.qr;
    } finally {
        qrLoading.value = false;
    }
};

const pollStatus = async () => {
    if (!qrTarget.value) return;
    const { data } = await axios.get(route('settings.channels.status', qrTarget.value.id));
    qrStatus.value = data.status;
    if (data.status === 'connected') {
        closeQrModal();
        window.location.reload();
    }
};

onBeforeUnmount(() => {
    if (pollTimer) clearInterval(pollTimer);
});
</script>

<template>
    <Head title="Каналы связи" />

    <AuthenticatedLayout>
        <template #header>
            Настройки компании
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            <SettingsNav />

            <PageHelper title="Как это устроено">
                <p>Каждый канал — одно подключение (один номер WhatsApp/Telegram/MAX или один SMS-аккаунт). Можно подключить несколько номеров одновременно — например, по одному на точку.</p>
                <p>Подключение WhatsApp/Telegram/MAX идёт через QR-код (как в самом мессенджере на телефоне) — после сканирования канал становится «Подключён» и может принимать/отправлять сообщения. Профиль у провайдера система создаёт сама, вводить Profile ID или токен не нужно.</p>
                <p class="text-xs text-gray-400 mt-2">Реализация не завязана намертво на Wappi.Pro — при необходимости переезда на другого провайдера меняется только код внутри системы, каналы и переписки не теряются.</p>
            </PageHelper>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 p-6 flex justify-between items-center">
                <div>
                    <h1 class="text-base font-semibold text-gray-800 dark:text-gray-200">Каналы связи</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Подключённые номера WhatsApp/Telegram/MAX и SMS-шлюзы</p>
                </div>
                <button @click="openModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 gap-1.5 shadow-sm">
                    <i class="ri-add-line text-base"></i> Добавить канал
                </button>
            </div>

            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 overflow-hidden">
                <div class="overflow-x-auto w-full">
                    <table class="min-w-full text-left whitespace-nowrap">
                        <thead class="bg-gray-50/50 dark:bg-gray-800/50">
                            <tr>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Название</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Тип</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Провайдер</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Точка</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700">Статус</th>
                                <th class="py-3 px-6 text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 text-right">Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="channel in channels" :key="channel.id" class="odd:bg-gray-100/80 dark:odd:bg-gray-800/40 hover:bg-gray-50/50 dark:hover:bg-gray-800/30 transition-colors">
                                <td class="py-4 px-6 text-sm font-semibold text-gray-800 dark:text-gray-200 border-b border-gray-100 dark:border-gray-700/50">
                                    {{ channel.name }}
                                    <div v-if="channel.phone_number" class="text-xs text-gray-400 font-normal">{{ channel.phone_number }}</div>
                                </td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ messengerTypeLabels[channel.messenger_type] || channel.messenger_type }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ providerLabels[channel.provider] || channel.provider }}</td>
                                <td class="py-4 px-6 text-sm text-gray-600 dark:text-gray-300 border-b border-gray-100 dark:border-gray-700/50">{{ channel.branch ? channel.branch.name : 'Все точки' }}</td>
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50">
                                    <span v-if="isMessenger(channel.provider) && !channel.external_profile_id" class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-danger/10 text-danger" title="Не удалось создать профиль у провайдера">Ошибка настройки</span>
                                    <span v-else :class="[statusClasses[channel.status] || 'bg-gray-100 text-gray-600', 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium']">{{ statusLabels[channel.status] || channel.status }}</span>
                                </td>
                                <td class="py-4 px-6 text-sm border-b border-gray-100 dark:border-gray-700/50 text-right space-x-2">
                                    <button v-if="isMessenger(channel.provider) && !channel.external_profile_id" @click="retryProvision(channel)" :disabled="retryingId === channel.id" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white disabled:opacity-50" title="Повторить попытку">
                                        <i :class="['ri-refresh-line', { 'animate-spin': retryingId === channel.id }]"></i>
                                    </button>
                                    <button v-else-if="isMessenger(channel.provider) && channel.status !== 'connected'" @click="openQrModal(channel)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-success/10 text-success hover:bg-success hover:text-white" title="Подключить">
                                        <i class="ri-qr-code-line"></i>
                                    </button>
                                    <button @click="openModal(channel)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-primary/10 text-primary hover:bg-primary hover:text-white" title="Редактировать">
                                        <i class="ri-pencil-line"></i>
                                    </button>
                                    <button @click="deleteChannel(channel)" class="inline-flex items-center justify-center rounded px-3 py-1.5 text-xs font-medium transition-all duration-300 bg-danger/10 text-danger hover:bg-danger hover:text-white" title="Удалить">
                                        <i class="ri-delete-bin-line"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr v-if="channels.length === 0">
                                <td colspan="6" class="py-8 px-6 text-center text-sm text-gray-500 dark:text-gray-400">Каналы ещё не подключены. Нажмите "Добавить канал".</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Модалка добавления/редактирования канала -->
        <div v-if="isModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-xl my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">{{ editingChannel ? 'Редактирование канала' : 'Новый канал' }}</h3>
                    <button @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <form @submit.prevent="submit" class="flex flex-col">
                    <div class="p-6 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Название <span class="text-danger">*</span></label>
                            <input v-model="form.name" type="text" required placeholder="Например: WhatsApp — основной номер" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Провайдер <span class="text-danger">*</span></label>
                                <select v-model="form.provider" :disabled="!!editingChannel" @change="onProviderChange" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 disabled:opacity-60">
                                    <option value="wappi_pro">Wappi.Pro (WhatsApp/Telegram/MAX)</option>
                                    <option value="sms_aero">SMS Aero</option>
                                </select>
                            </div>
                            <div v-if="form.provider === 'wappi_pro'">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Мессенджер <span class="text-danger">*</span></label>
                                <select v-model="form.messenger_type" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="telegram">Telegram</option>
                                    <option value="max">MAX</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Точка</label>
                            <select v-model="form.branch_id" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                                <option value="">Все точки</option>
                                <option v-for="b in branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                            </select>
                        </div>

                        <p v-if="form.provider === 'wappi_pro'" class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800/40 rounded-md p-3">
                            <i class="ri-information-line mr-1"></i> Profile ID и токен вводить не нужно — профиль в Wappi создаётся автоматически при сохранении, затем останется отсканировать QR.
                        </p>

                        <template v-if="form.provider === 'sms_aero'">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Email аккаунта <span v-if="!editingChannel" class="text-danger">*</span></label>
                                <input v-model="form.credentials.email" type="email" :required="!editingChannel" :placeholder="editingChannel ? 'Оставьте пустым, чтобы не менять' : ''" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">API-ключ <span v-if="!editingChannel" class="text-danger">*</span></label>
                                <input v-model="form.credentials.api_key" type="text" :required="!editingChannel" :placeholder="editingChannel ? 'Оставьте пустым, чтобы не менять' : ''" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Подпись отправителя</label>
                                <input v-model="form.credentials.sign" type="text" :placeholder="editingChannel ? 'Оставьте пустым, чтобы не менять' : 'Зарегистрированное имя отправителя'" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                        </template>
                        <p v-if="editingChannel && form.provider === 'sms_aero'" class="text-xs text-gray-400 -mt-2">Поля учётных данных выше показаны пустыми специально — заполните только то, что хотите изменить, остальное останется как есть.</p>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Номер телефона (для отображения)</label>
                            <input v-model="form.phone_number" type="text" placeholder="+7 900 000-00-00" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                        </div>

                        <div class="flex items-center pt-2">
                            <div @click="form.is_active = !form.is_active" :class="[form.is_active ? 'bg-success' : 'bg-gray-200 dark:bg-gray-700', 'flex items-center h-5 w-9 rounded-full cursor-pointer transition-all duration-200 relative']">
                                <div :class="[form.is_active ? 'translate-x-4' : 'translate-x-1', 'h-3.5 w-3.5 bg-white rounded-full shadow transition-all duration-200 absolute']"></div>
                            </div>
                            <label class="ml-2.5 block text-sm font-medium text-gray-800 dark:text-gray-200 cursor-pointer" @click="form.is_active = !form.is_active">Канал активен</label>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-gray-200 dark:border-gray-700 py-4 px-6 bg-gray-50/50 dark:bg-transparent">
                        <button type="button" @click="closeModal()" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-secondary/10 text-secondary hover:bg-secondary hover:text-white">Отмена</button>
                        <button type="submit" :disabled="form.processing" class="inline-flex items-center justify-center rounded px-4 py-2 text-sm font-medium transition-all duration-300 bg-primary text-white hover:bg-primary-600 disabled:opacity-50">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Модалка QR-подключения -->
        <div v-if="isQrModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm overflow-y-auto">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-lg dark:bg-[#313a46] dark:border-gray-700/80 w-full sm:max-w-sm my-8 mx-auto flex flex-col">
                <div class="border-b border-gray-200 dark:border-gray-700 py-3 px-6 flex justify-between items-center">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">Подключение: {{ qrTarget?.name }}</h3>
                    <button @click="closeQrModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors focus:outline-none"><i class="ri-close-line text-xl"></i></button>
                </div>
                <div class="p-6 flex flex-col items-center text-center space-y-3">
                    <p class="text-sm text-gray-500 dark:text-gray-400">Откройте {{ messengerTypeLabels[qrTarget?.messenger_type] || 'мессенджер' }} на телефоне → Связанные устройства → Сканировать код.</p>
                    <div v-if="qrLoading" class="w-48 h-48 flex items-center justify-center text-gray-400"><i class="ri-loader-4-line animate-spin text-3xl"></i></div>
                    <img v-else-if="qrImage" :src="qrImage.startsWith('data:') ? qrImage : `data:image/png;base64,${qrImage}`" class="w-48 h-48 border border-gray-200 dark:border-gray-700 rounded-md" alt="QR-код" />
                    <p v-else class="text-sm text-danger">Не удалось получить QR-код. Попробуйте закрыть окно и открыть заново, либо обратитесь к администратору платформы — возможно, не настроен общий токен Wappi.Pro.</p>
                    <span :class="[statusClasses[qrStatus] || 'bg-gray-100 text-gray-600', 'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium']">{{ statusLabels[qrStatus] || qrStatus }}</span>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
