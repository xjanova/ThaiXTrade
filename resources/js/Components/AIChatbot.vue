<script setup>
/**
 * TPIX TRADE — AI Chatbot Widget
 * บอทลอยหน้าเว็บ ตอบเรื่อง TPIX Chain + Trade + นำทางอัตโนมัติ
 * ห้ามเปิดเผยข้อมูล sensitive ของระบบ
 * Developed by Xman Studio
 */
import { ref, nextTick, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';

const isOpen = ref(false);
const message = ref('');
const isLoading = ref(false);
const chatHistory = ref([
    { role: 'bot', text: 'สวัสดีครับ! ผม TPIX AI Assistant 🤖 ถามอะไรเกี่ยวกับ TPIX Chain, การเทรด, Token Sale หรือฟีเจอร์ต่างๆ ได้เลยครับ' },
]);
const chatContainer = ref(null);

// เลื่อน scroll ลงล่างสุด
function scrollBottom() {
    nextTick(() => {
        if (chatContainer.value) {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }
    });
}

watch(chatHistory, scrollBottom, { deep: true });

async function sendMessage() {
    const msg = message.value.trim();
    if (!msg || isLoading.value) return;

    chatHistory.value.push({ role: 'user', text: msg });
    message.value = '';
    isLoading.value = true;
    scrollBottom();

    try {
        const { data } = await axios.post('/api/v1/chatbot', {
            message: msg,
            language: 'th',
        });

        if (data.success) {
            chatHistory.value.push({ role: 'bot', text: data.data.message });

            // นำทางอัตโนมัติถ้า AI แนะนำ
            if (data.data.navigation) {
                setTimeout(() => {
                    chatHistory.value.push({
                        role: 'bot',
                        text: `📍 กำลังพาคุณไปหน้า ${data.data.navigation}...`,
                        isNav: true,
                        navUrl: data.data.navigation,
                    });
                    scrollBottom();
                }, 500);
            }
        } else {
            chatHistory.value.push({ role: 'bot', text: 'ขออภัย เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้งครับ' });
        }
    } catch {
        chatHistory.value.push({ role: 'bot', text: 'ขออภัย ไม่สามารถเชื่อมต่อได้ กรุณาลองใหม่ครับ' });
    }
    isLoading.value = false;
}

function navigateTo(url) {
    router.visit(url);
    isOpen.value = false;
}

// Quick actions
const quickActions = [
    { label: '🔥 TPIX Chain คืออะไร', msg: 'TPIX Chain คืออะไร' },
    { label: '💰 ซื้อเหรียญ TPIX', msg: 'ซื้อเหรียญ TPIX ได้อย่างไร' },
    { label: '📊 วิธีเทรด', msg: 'สอนวิธีเทรดบน TPIX TRADE' },
    { label: '🌱 Carbon Credit', msg: 'ระบบ Carbon Credit ทำงานอย่างไร' },
];

function sendQuick(msg) {
    message.value = msg;
    sendMessage();
}
</script>

<template>
    <!-- ปุ่มเปิด Chatbot -->
    <button v-if="!isOpen" @click="isOpen = true"
        class="fixed bottom-6 right-6 z-50 w-14 h-14 rounded-full bg-gradient-to-br from-primary-500 to-accent-500 shadow-lg shadow-primary-500/30 flex items-center justify-center hover:scale-110 transition-transform">
        <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
        </svg>
        <span class="absolute -top-1 -right-1 w-3 h-3 bg-trading-green rounded-full animate-pulse"></span>
    </button>

    <!-- Chat Window -->
    <div v-if="isOpen"
        class="fixed bottom-6 right-6 z-50 w-[380px] max-w-[calc(100vw-2rem)] h-[550px] max-h-[calc(100vh-3rem)] bg-dark-800 border border-white/10 rounded-2xl shadow-2xl flex flex-col overflow-hidden">

        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-3 bg-gradient-to-r from-primary-500/20 to-accent-500/20 border-b border-white/10">
            <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-full bg-primary-500/30 flex items-center justify-center">
                    <span class="text-sm">🤖</span>
                </div>
                <div>
                    <p class="text-white text-sm font-semibold">TPIX AI Assistant</p>
                    <p class="text-trading-green text-[10px]">● Online</p>
                </div>
            </div>
            <button @click="isOpen = false" class="text-dark-400 hover:text-white transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Chat Messages -->
        <div ref="chatContainer" class="flex-1 overflow-y-auto p-4 space-y-3">
            <div v-for="(msg, i) in chatHistory" :key="i"
                :class="['flex', msg.role === 'user' ? 'justify-end' : 'justify-start']">
                <div :class="[
                    'max-w-[85%] px-3 py-2 rounded-xl text-sm',
                    msg.role === 'user'
                        ? 'bg-primary-500/20 text-white rounded-br-sm'
                        : 'bg-white/5 text-dark-200 rounded-bl-sm'
                ]">
                    <p class="whitespace-pre-wrap">{{ msg.text }}</p>
                    <button v-if="msg.isNav" @click="navigateTo(msg.navUrl)"
                        class="mt-2 px-3 py-1 bg-primary-500/30 text-primary-300 rounded-lg text-xs hover:bg-primary-500/50 transition-colors">
                        ไปหน้านั้น →
                    </button>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="isLoading" class="flex justify-start">
                <div class="bg-white/5 px-4 py-2 rounded-xl text-sm text-dark-400">
                    <span class="animate-pulse">กำลังคิด...</span>
                </div>
            </div>

            <!-- Quick Actions (แสดงเมื่อไม่มี history มาก) -->
            <div v-if="chatHistory.length <= 1" class="space-y-2 mt-4">
                <p class="text-dark-500 text-xs">ลองถามเรื่องนี้:</p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="q in quickActions" :key="q.label" @click="sendQuick(q.msg)"
                        class="px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-xs text-dark-300 hover:bg-primary-500/10 hover:border-primary-500/30 transition-all">
                        {{ q.label }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Input -->
        <div class="p-3 border-t border-white/10">
            <form @submit.prevent="sendMessage" class="flex gap-2">
                <input v-model="message" type="text" placeholder="พิมพ์คำถามของคุณ..."
                    class="flex-1 bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm placeholder-dark-500 focus:border-primary-500 outline-none"
                    :disabled="isLoading" />
                <button type="submit" :disabled="isLoading || !message.trim()"
                    class="px-4 py-2.5 bg-primary-500 text-white rounded-xl text-sm font-medium hover:bg-primary-600 disabled:opacity-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</template>
