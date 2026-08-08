<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import axios from 'axios';

const props = defineProps({
    clientId: { type: Number, required: true },
    channels: { type: Array, default: () => [] }, // мессенджер-каналы, доступные для написания
});

const chats = ref([]);
const activeChatId = ref(null);
const loading = ref(true);
const sending = ref(false);
const composerText = ref('');
const newChatChannelId = ref('');
const messagesEnd = ref(null);
let echoChannel = null;

const activeChat = computed(() => chats.value.find(c => c.id === activeChatId.value) || null);

const messengerLabels = { whatsapp: 'WhatsApp', telegram: 'Telegram', max: 'MAX', sms: 'SMS' };

const scrollToBottom = () => {
    nextTick(() => messagesEnd.value?.scrollIntoView({ behavior: 'smooth' }));
};

const loadChats = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get(route('crm.clients.chats', props.clientId));
        chats.value = data.chats;
        if (!activeChatId.value && chats.value.length > 0) {
            activeChatId.value = chats.value[0].id;
        }
    } finally {
        loading.value = false;
    }
};

const subscribeToActiveChat = () => {
    // Уже подписаны ровно на этот чат — не дёргаем leave/re-join впустую
    // (вызывается и из onMounted, и из watch(activeChatId), оба пути могут
    // сработать для одного и того же чата при первой загрузке).
    if (echoChannel === activeChatId.value) return;

    if (echoChannel) {
        window.Echo.leave(`chat.${echoChannel}`);
        echoChannel = null;
    }
    if (!activeChatId.value || !window.Echo) return;

    echoChannel = activeChatId.value;
    window.Echo.private(`chat.${activeChatId.value}`).listen('.message.received', (e) => {
        const chat = chats.value.find(c => c.id === e.message.chat_id);
        if (chat) {
            chat.messages.push(e.message);
            if (e.message.chat_id === activeChatId.value) scrollToBottom();
        }
    });
};

watch(activeChatId, () => {
    subscribeToActiveChat();
    scrollToBottom();
});

onMounted(async () => {
    await loadChats();
    subscribeToActiveChat();
    scrollToBottom();
});

onBeforeUnmount(() => {
    if (echoChannel) window.Echo?.leave(`chat.${echoChannel}`);
});

const sendMessage = async () => {
    const channelId = activeChat.value ? activeChat.value.channel_id : newChatChannelId.value;
    if (!composerText.value.trim() || !channelId) return;

    sending.value = true;
    try {
        const { data } = await axios.post(route('crm.clients.chats.send', props.clientId), {
            channel_id: channelId,
            content: composerText.value,
        });

        composerText.value = '';

        if (!activeChat.value) {
            await loadChats();
            activeChatId.value = data.chat_id;
        } else {
            activeChat.value.messages.push(data.message);
        }
        scrollToBottom();
    } finally {
        sending.value = false;
    }
};

const formatTime = (dateStr) => new Date(dateStr).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
</script>

<template>
    <div class="flex-1 flex flex-col min-h-0">
        <div v-if="loading" class="flex-1 flex items-center justify-center text-sm text-gray-400">
            <i class="ri-loader-4-line animate-spin mr-2"></i> Загрузка переписки...
        </div>

        <template v-else>
            <!-- Переключатель между каналами, если переписок несколько -->
            <div v-if="chats.length > 1" class="flex gap-1.5 px-4 pt-3 flex-wrap">
                <button
                    v-for="chat in chats"
                    :key="chat.id"
                    @click="activeChatId = chat.id"
                    :class="[activeChatId === chat.id ? 'bg-primary text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300', 'px-2.5 py-1 rounded-full text-xs font-medium transition-colors']"
                >
                    {{ messengerLabels[chat.channel?.messenger_type] || chat.channel?.name }}
                </button>
            </div>

            <div class="flex-1 overflow-y-auto custom-scrollbar p-4 space-y-3">
                <div v-if="!activeChat" class="h-full flex flex-col items-center justify-center text-center text-sm text-gray-400 gap-2">
                    <i class="ri-chat-3-line text-3xl text-gray-300 dark:text-gray-600"></i>
                    <p>Переписки пока нет. Выберите канал ниже и напишите первое сообщение.</p>
                </div>

                <div
                    v-for="message in (activeChat?.messages || [])"
                    :key="message.id"
                    :class="['max-w-[75%] rounded-lg px-3 py-2 text-sm', message.direction === 'out' ? 'ml-auto bg-primary text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200']"
                >
                    <p class="whitespace-pre-wrap break-words">{{ message.content }}</p>
                    <div :class="['flex items-center gap-1 mt-1 text-[10px]', message.direction === 'out' ? 'text-white/70 justify-end' : 'text-gray-400']">
                        <span>{{ formatTime(message.created_at) }}</span>
                        <i v-if="message.direction === 'out'" :class="message.status === 'failed' ? 'ri-error-warning-line' : 'ri-check-line'"></i>
                    </div>
                </div>
                <div ref="messagesEnd"></div>
            </div>

            <div class="p-3 border-t border-gray-200 dark:border-gray-700 space-y-2">
                <select v-if="!activeChat" v-model="newChatChannelId" class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0">
                    <option value="" disabled>Выберите канал для отправки</option>
                    <option v-for="ch in channels" :key="ch.id" :value="ch.id">{{ ch.name }} ({{ messengerLabels[ch.messenger_type] || ch.messenger_type }})</option>
                </select>
                <div class="flex items-end gap-2">
                    <textarea
                        v-model="composerText"
                        rows="1"
                        placeholder="Написать сообщение..."
                        class="block w-full rounded-md border border-gray-200 dark:border-gray-700 bg-transparent py-2 px-3 text-sm text-gray-800 dark:text-gray-200 focus:border-primary focus:ring-0 resize-none"
                        @keydown.enter.exact.prevent="sendMessage"
                    ></textarea>
                    <button
                        @click="sendMessage"
                        :disabled="sending || !composerText.trim() || (!activeChat && !newChatChannelId)"
                        class="inline-flex items-center justify-center rounded-md w-10 h-10 shrink-0 bg-primary text-white hover:bg-primary-600 disabled:opacity-50 transition-colors"
                    >
                        <i class="ri-send-plane-line"></i>
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
