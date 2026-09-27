<script setup>
/**
 * TPIX TRADE — AI Chatbot Widget
 * บอทลอยหน้าเว็บ ตอบเรื่อง TPIX Chain + Trade + นำทางอัตโนมัติ
 * ห้ามเปิดเผยข้อมูล sensitive ของระบบ
 *
 * ประวัติแชทอยู่ใน useChatbot (ใช้ร่วมกับบับเบิ้ลของน้อง TPIX บนหน้าแรก)
 * หน้าที่มีน้อง TPIX อยู่บนจอ: ซ่อนปุ่มลอยของตัวเอง (น้องคือทางเข้าแชทแทน)
 * และคุยด้วยบุคลิกของน้อง ให้บทสนทนาต่อเนื่องไม่สลับเสียง
 *
 * Developed by Xman Studio
 */
import { ref, computed, nextTick, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useTranslation } from '@/Composables/useTranslation';
import { useChatbot } from '@/Composables/useChatbot';
import { useMascot } from '@/Composables/useMascot';
import { FACE_SRC } from '@/Components/Home/spriteStage';

const { t, locale } = useTranslation();
const chat = useChatbot();
const mascot = useMascot();

const message = ref('');
const chatContainer = ref(null);

const isOpen = chat.isOpen;
const isLoading = chat.isLoading;
const asMascot = computed(() => mascot.active.value);

// เลื่อน scroll ลงล่างสุด
function scrollBottom() {
    nextTick(() => {
        if (chatContainer.value) {
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
        }
    });
}

watch(() => chat.messages.value.length, scrollBottom);
watch(isOpen, (open) => open && scrollBottom());

async function sendMessage() {
    const msg = message.value.trim();
    if (!msg || isLoading.value) return;
    message.value = '';
    scrollBottom();
    await chat.send(msg, {
        language: locale.value,
        persona: asMascot.value ? 'mascot' : 'assistant',
        errorText: t('chatbot.error'),
    });
}

function navigateTo(url) {
    router.visit(url);
    chat.close();
}

// Quick actions — computed เพื่อให้สลับภาษาแล้วคำถามเปลี่ยนตาม
const quickActions = computed(() => [
    { label: t('chatbot.q1'), msg: locale.value === 'th' ? 'TPIX Chain คืออะไร' : 'What is TPIX Chain' },
    { label: t('chatbot.q2'), msg: locale.value === 'th' ? 'ซื้อเหรียญ TPIX ได้อย่างไร' : 'How to buy TPIX tokens' },
    { label: t('chatbot.q3'), msg: locale.value === 'th' ? 'สอนวิธีเทรดบน TPIX TRADE' : 'How to trade on TPIX TRADE' },
    { label: t('chatbot.q4'), msg: locale.value === 'th' ? 'ระบบ Carbon Credit ทำงานอย่างไร' : 'How does Carbon Credit work' },
]);

function sendQuick(msg) {
    message.value = msg;
    sendMessage();
}
</script>

<template>
    <!-- ปุ่มเปิด Chatbot (หน้าที่มีน้อง TPIX ใช้น้องเป็นปุ่มแทน) -->
    <button v-if="!isOpen && !asMascot" type="button" @click="chat.open()"
        :aria-label="t('chatbot.open')"
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
                <div class="w-8 h-8 rounded-full bg-primary-500/30 flex items-center justify-center overflow-hidden">
                    <img v-if="asMascot" :src="FACE_SRC" alt="" class="w-8 h-8 object-cover" />
                    <span v-else class="text-sm">🤖</span>
                </div>
                <div>
                    <p class="text-white text-sm font-semibold">{{ asMascot ? t('mascot.name') : 'TPIX AI Assistant' }}</p>
                    <p class="text-trading-green text-[10px]">● Online</p>
                </div>
            </div>
            <button type="button" @click="chat.close()" :aria-label="t('chatbot.close')" class="text-dark-400 hover:text-white transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <!-- Chat Messages -->
        <div ref="chatContainer" class="flex-1 overflow-y-auto p-4 space-y-3">
            <!-- คำทักทาย: ไม่เก็บลงประวัติ จึงสลับภาษาได้และไม่ถูกส่งให้บอท -->
            <div class="flex justify-start">
                <div class="max-w-[85%] px-3 py-2 rounded-xl text-sm bg-white/5 text-dark-200 rounded-bl-sm">
                    <p class="whitespace-pre-wrap">{{ asMascot ? t('mascot.chatGreeting') : t('chatbot.greeting') }}</p>
                </div>
            </div>

            <div v-for="msg in chat.messages.value" :key="msg.id"
                :class="['flex', msg.role === 'user' ? 'justify-end' : 'justify-start']">
                <div :class="[
                    'max-w-[85%] px-3 py-2 rounded-xl text-sm',
                    msg.role === 'user'
                        ? 'bg-primary-500/20 text-white rounded-br-sm'
                        : 'bg-white/5 text-dark-200 rounded-bl-sm'
                ]">
                    <p class="whitespace-pre-wrap">{{ msg.text }}</p>
                    <button v-if="msg.navUrl" type="button" @click="navigateTo(msg.navUrl)"
                        class="mt-2 px-3 py-1 bg-primary-500/30 text-primary-300 rounded-lg text-xs hover:bg-primary-500/50 transition-colors">
                        📍 {{ t('chatbot.goToPage') }}
                    </button>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="isLoading" class="flex justify-start">
                <div class="bg-white/5 px-4 py-2 rounded-xl text-sm text-dark-400">
                    <span class="animate-pulse">{{ t('chatbot.thinking') }}</span>
                </div>
            </div>

            <!-- Quick Actions (แสดงเมื่อยังไม่เคยคุย) -->
            <div v-if="chat.messages.value.length === 0" class="space-y-2 mt-4">
                <p class="text-dark-500 text-xs">{{ t('chatbot.trySuggestion') }}</p>
                <div class="flex flex-wrap gap-2">
                    <button v-for="q in quickActions" :key="q.label" type="button" @click="sendQuick(q.msg)"
                        class="px-3 py-1.5 bg-white/5 border border-white/10 rounded-lg text-xs text-dark-300 hover:bg-primary-500/10 hover:border-primary-500/30 transition-all">
                        {{ q.label }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Input -->
        <div class="p-3 border-t border-white/10">
            <form @submit.prevent="sendMessage" class="flex gap-2">
                <input v-model="message" type="text" maxlength="1000" :placeholder="t('chatbot.placeholder')"
                    :aria-label="t('chatbot.placeholder')"
                    class="flex-1 bg-dark-700 border border-dark-600 rounded-xl px-4 py-2.5 text-white text-sm placeholder-dark-500 focus:border-primary-500 outline-none"
                    :disabled="isLoading" />
                <button type="submit" :disabled="isLoading || !message.trim()" :aria-label="t('chatbot.send')"
                    class="px-4 py-2.5 bg-primary-500 text-white rounded-xl text-sm font-medium hover:bg-primary-600 disabled:opacity-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>
</template>
