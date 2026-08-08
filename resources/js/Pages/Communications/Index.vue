<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import ChatPanel from '@/Components/ChatPanel.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import axios from 'axios';

const props = defineProps({
    channels: { type: Array, default: () => [] },
    clients: { type: Array, default: () => [] },
});

const chats = ref([]);
const loading = ref(true);
const search = ref('');
const selectedClientId = ref(null);
const isNewChatOpen = ref(false);
const newChatClientId = ref('');

const messengerLabels = { whatsapp: 'WhatsApp', telegram: 'Telegram', max: 'MAX', sms: 'SMS' };

const clientOptions = computed(() => props.clients.map(c => ({ value: c.id, label: c.phone ? `${c.name} (${c.phone})` : c.name })));

const loadChats = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get(route('communications.chats'), { params: { search: search.value || undefined } });
        chats.value = data.chats;
    } finally {
        loading.value = false;
    }
};

let searchTimer = null;
const onSearchInput = () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadChats, 300);
};

const selectChat = (chat) => {
    selectedClientId.value = chat.client.id;
};

const startNewChat = () => {
    if (!newChatClientId.value) return;
    selectedClientId.value = Number(newChatClientId.value);
    isNewChatOpen.value = false;
    newChatClientId.value = '';
};

const lastMessagePreview = (chat) => {
    const msg = chat.messages?.[0];
    if (!msg) return 'Переписки ещё нет';
    const prefix = msg.direction === 'out' ? 'Вы: ' : '';
    return prefix + (msg.content?.length > 60 ? msg.content.slice(0, 60) + '…' : msg.content);
};

const formatTime = (dateStr) => {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
};

onMounted(loadChats);
</script>

<template>
    <Head title="Общение" />

    <AuthenticatedLayout>
        <template #header>
            Общение
        </template>

        <div class="w-[99%] mx-auto space-y-6 font-sans text-gray-600 dark:text-gray-400">
            <div class="bg-white border border-gray-200/80 rounded-md shadow-sm dark:bg-[#313a46] dark:border-gray-700/80 flex h-[calc(100vh-220px)] min-h-[500px] overflow-hidden">
                <!-- Левая колонка: список переписок -->
                <div class="w-80 shrink-0 border-r border-gray-200 dark:border-gray-700 flex flex-col min-h-0">
                    <div class="p-3 border-b border-gray-200 dark:border-gray-700 space-y-2">
                        <div class="flex items-center gap-2">
                            <div class="relative flex-1">
                                <i class="ri-search-line absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input v-model="search" @input="onSearchInput" type="text" placeholder="Поиск по клиенту..." class="w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-1.5 pl-8 pr-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0" />
                            </div>
                            <button @click="isNewChatOpen = !isNewChatOpen" class="inline-flex items-center justify-center rounded-md w-8 h-8 shrink-0 bg-primary text-white hover:bg-primary-600 transition-colors" title="Новый диалог">
                                <i class="ri-add-line"></i>
                            </button>
                        </div>
                        <div v-if="isNewChatOpen" class="pt-1">
                            <SearchableSelect v-model="newChatClientId" :options="clientOptions" placeholder="Выберите клиента" search-placeholder="Поиск клиента..." />
                            <button @click="startNewChat" :disabled="!newChatClientId" class="mt-2 w-full inline-flex items-center justify-center rounded-md px-3 py-1.5 text-xs font-medium bg-primary/10 text-primary hover:bg-primary hover:text-white disabled:opacity-50 transition-colors">Начать переписку</button>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto custom-scrollbar">
                        <div v-if="loading" class="flex items-center justify-center py-8 text-sm text-gray-400">
                            <i class="ri-loader-4-line animate-spin mr-2"></i> Загрузка...
                        </div>
                        <div v-else-if="chats.length === 0" class="flex flex-col items-center justify-center py-10 text-center text-sm text-gray-400 gap-2 px-4">
                            <i class="ri-chat-3-line text-3xl text-gray-300 dark:text-gray-600"></i>
                            <p>Переписок пока нет.</p>
                        </div>
                        <button
                            v-for="chat in chats"
                            :key="chat.id"
                            @click="selectChat(chat)"
                            :class="[selectedClientId === chat.client.id ? 'bg-primary/10' : 'hover:bg-gray-50 dark:hover:bg-gray-800/40', 'w-full text-left px-4 py-3 border-b border-gray-100 dark:border-gray-700/50 transition-colors']"
                        >
                            <div class="flex justify-between items-start gap-2">
                                <span class="text-sm font-semibold text-gray-800 dark:text-gray-200 truncate">{{ chat.client.name }}</span>
                                <span class="text-[10px] text-gray-400 shrink-0">{{ formatTime(chat.last_message_at) }}</span>
                            </div>
                            <div class="flex justify-between items-center gap-2 mt-0.5">
                                <span class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ lastMessagePreview(chat) }}</span>
                                <span class="text-[10px] text-gray-400 shrink-0">{{ messengerLabels[chat.channel?.messenger_type] || chat.channel?.name }}</span>
                            </div>
                        </button>
                    </div>
                </div>

                <!-- Правая колонка: сама переписка -->
                <div class="flex-1 flex flex-col min-h-0">
                    <ChatPanel v-if="selectedClientId" :key="selectedClientId" :client-id="selectedClientId" :channels="channels" />
                    <div v-else class="flex-1 flex flex-col items-center justify-center text-center text-sm text-gray-400 gap-2">
                        <i class="ri-message-3-line text-4xl text-gray-300 dark:text-gray-600"></i>
                        <p>Выберите переписку слева или начните новую</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
