<script setup>
/**
 * TPIX TRADE — AI Assistant Page (Full Chat)
 * หน้า AI ผู้ช่วยเทรด — พิมพ์สื่อสารกับ AI ได้เลย
 * ดึงข้อมูลจาก Groq API ผ่าน /api/v1/chatbot
 * Developed by Xman Studio
 */
import { ref, nextTick } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';
import axios from 'axios';

const { t, locale } = useTranslation();

const message = ref('');
const isLoading = ref(false);
const chatContainer = ref(null);

const chatHistory = ref([
    { role: 'bot', text: t('chatbot.greeting'), time: new Date() },
]);

function scrollBottom() {
    nextTick(() => {
        if (chatContainer.value) chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
    });
}

async function sendMessage() {
    const msg = message.value.trim();
    if (!msg || isLoading.value) return;
    chatHistory.value.push({ role: 'user', text: msg, time: new Date() });
    message.value = '';
    isLoading.value = true;
    scrollBottom();
    try {
        const { data } = await axios.post('/api/v1/chatbot', { message: msg, language: locale.value });
        if (data.success) {
            chatHistory.value.push({ role: 'bot', text: data.data.message, time: new Date(), navigation: data.data.navigation });
        } else {
            chatHistory.value.push({ role: 'bot', text: t('chatbot.error'), time: new Date() });
        }
    } catch {
        chatHistory.value.push({ role: 'bot', text: t('chatbot.error'), time: new Date() });
    }
    isLoading.value = false;
    scrollBottom();
}

function sendQuick(msg) { message.value = msg; sendMessage(); }
function formatTime(d) { return new Date(d).toLocaleTimeString(locale.value === 'th' ? 'th-TH' : 'en-US', { hour: '2-digit', minute: '2-digit' }); }
function goTo(url) { router.visit(url); }

const quickActions = [
    { icon: '🔥', label: t('chatbot.q1'), msg: locale.value === 'th' ? 'TPIX Chain คืออะไร อธิบายละเอียด' : 'What is TPIX Chain in detail' },
    { icon: '💰', label: t('chatbot.q2'), msg: locale.value === 'th' ? 'ซื้อเหรียญ TPIX ได้อย่างไร' : 'How to buy TPIX tokens' },
    { icon: '📊', label: t('chatbot.q3'), msg: locale.value === 'th' ? 'สอนวิธีเทรดบน TPIX TRADE' : 'How to trade on TPIX TRADE' },
    { icon: '🛡️', label: 'Master Node', msg: locale.value === 'th' ? 'Master Node TPIX รับรางวัลเท่าไหร่' : 'TPIX master node reward rates' },
    { icon: '🌉', label: 'Bridge', msg: locale.value === 'th' ? 'Bridge ข้าม chain ทำอย่างไร' : 'How to bridge across chains' },
    { icon: '🌱', label: t('chatbot.q4'), msg: locale.value === 'th' ? 'Carbon Credit ทำงานอย่างไร' : 'How does Carbon Credit work' },
];
</script>

<template>
    <Head :title="t('aiAssistant.title')" />
    <AppLayout :hide-sidebar="true">
        <div class="max-w-5xl mx-auto">
            <div class="text-center mb-6">
                <h1 class="text-3xl font-bold text-white mb-2">🤖 {{ t('aiAssistant.title') }}</h1>
                <p class="text-dark-400">{{ locale === 'th' ? 'ถามอะไรก็ได้เกี่ยวกับ TPIX Chain, การเทรด และฟีเจอร์ทั้งหมด' : 'Ask anything about TPIX Chain, trading and all features' }}</p>
            </div>

            <div class="grid lg:grid-cols-4 gap-6">
                <!-- Sidebar — hidden on mobile, shown at bottom or as collapsible -->
                <div class="order-2 lg:order-1 lg:col-span-1 space-y-4">
                    <div class="glass-card">
                        <h3 class="text-sm font-semibold text-dark-300 mb-3">{{ t('chatbot.trySuggestion') }}</h3>
                        <div class="space-y-2">
                            <button v-for="q in quickActions" :key="q.label" @click="sendQuick(q.msg)"
                                class="w-full text-left px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-xs text-dark-300 hover:bg-primary-500/10 hover:border-primary-500/30 transition-all">
                                <span class="mr-1">{{ q.icon }}</span> {{ q.label }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Chat -->
                <div class="order-1 lg:order-2 lg:col-span-3 glass-card flex flex-col" style="height: calc(100vh - 280px); min-height: 400px;">
                    <div ref="chatContainer" class="flex-1 overflow-y-auto p-4 space-y-4">
                        <div v-for="(msg, i) in chatHistory" :key="i" :class="['flex', msg.role === 'user' ? 'justify-end' : 'justify-start']">
                            <div :class="['max-w-[80%] rounded-2xl px-4 py-3',
                                msg.role === 'user' ? 'bg-primary-500/20 border border-primary-500/20 text-white rounded-br-sm' : 'bg-white/5 border border-white/10 text-dark-200 rounded-bl-sm']">
                                <div v-if="msg.role === 'bot'" class="flex items-center gap-2 mb-1">
                                    <span class="text-sm">🤖</span>
                                    <span class="text-primary-400 text-xs font-semibold">TPIX AI</span>
                                    <span class="text-dark-600 text-[10px]">{{ formatTime(msg.time) }}</span>
                                </div>
                                <div v-else class="flex items-center justify-end gap-2 mb-1">
                                    <span class="text-dark-500 text-[10px]">{{ formatTime(msg.time) }}</span>
                                    <span class="text-primary-300 text-xs font-semibold">{{ locale === 'th' ? 'คุณ' : 'You' }}</span>
                                </div>
                                <p class="whitespace-pre-wrap text-sm leading-relaxed">{{ msg.text }}</p>
                                <button v-if="msg.navigation" @click="goTo(msg.navigation)"
                                    class="mt-2 px-3 py-1.5 bg-primary-500/20 text-primary-300 rounded-lg text-xs hover:bg-primary-500/30 transition-colors">
                                    {{ t('chatbot.goToPage') }}
                                </button>
                            </div>
                        </div>
                        <div v-if="isLoading" class="flex justify-start">
                            <div class="bg-white/5 border border-white/10 px-4 py-3 rounded-2xl rounded-bl-sm">
                                <div class="flex items-center gap-2 text-dark-400 text-sm">
                                    <span>🤖</span>
                                    <span class="animate-pulse">{{ t('chatbot.thinking') }}</span>
                                    <span class="flex gap-1">
                                        <span class="w-1.5 h-1.5 bg-primary-400 rounded-full animate-bounce"></span>
                                        <span class="w-1.5 h-1.5 bg-primary-400 rounded-full animate-bounce" style="animation-delay:0.15s"></span>
                                        <span class="w-1.5 h-1.5 bg-primary-400 rounded-full animate-bounce" style="animation-delay:0.3s"></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 border-t border-white/10">
                        <form @submit.prevent="sendMessage" class="flex gap-3">
                            <input v-model="message" type="text" :placeholder="t('chatbot.placeholder')"
                                class="flex-1 bg-dark-700 border border-dark-600 rounded-xl px-4 py-3 text-white placeholder-dark-500 focus:border-primary-500 outline-none" :disabled="isLoading" />
                            <button type="submit" :disabled="isLoading || !message.trim()"
                                class="px-6 py-3 bg-gradient-to-r from-primary-500 to-accent-500 text-white rounded-xl font-medium hover:shadow-lg disabled:opacity-50 transition-all">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                            </button>
                        </form>
                        <p class="text-dark-600 text-[10px] mt-2 text-center">{{ locale === 'th' ? 'AI อาจให้ข้อมูลไม่ถูกต้อง กรุณาตรวจสอบข้อมูลสำคัญด้วยตัวเอง' : 'AI may provide inaccurate info. Please verify important data yourself.' }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
