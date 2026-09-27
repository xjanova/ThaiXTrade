<script setup>
/**
 * TPIX TRADE — AI Chatbot Widget ("น้อง TPIX" ทุกหน้า)
 * บอทลอยหน้าเว็บ ตอบเรื่อง TPIX Chain + Trade + นำทางอัตโนมัติ
 * ห้ามเปิดเผยข้อมูล sensitive ของระบบ
 *
 * เจ้าของสั่ง: อวาตาร์บอทแชทต้องเป็นรูปน้อง TPIX → ปุ่มลอย หัวหน้าต่าง และข้างข้อความบอท
 * ใช้รูปหน้าน้อง (ภาพเจนจาก ChatGPT) และตอบด้วยน้ำเสียงของน้องทุกหน้า
 *
 * ประวัติแชทอยู่ใน useChatbot (ใช้ร่วมกับบับเบิ้ลของน้องบนหน้าแรก 3D)
 * หน้าที่มีตัวน้องยืนอยู่บนจอ: ซ่อนปุ่มลอย (ตัวน้องคือทางเข้าแชทแทน)
 * หน้าที่มีตัวน้องแต่ผู้ใช้กดซ่อนไว้: มีปุ่ม "เรียกน้องกลับมา" เหนือปุ่มแชท + ในหัวหน้าต่างแชท ตลอดเวลา
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
const faceBroken = ref(false);

const isOpen = chat.isOpen;
const isLoading = chat.isLoading;
// ตัวน้องยืนอยู่บนจอแล้ว (หน้าแรก 3D) → ไม่ต้องมีปุ่มลอยซ้ำ
const mascotOnScreen = computed(() => mascot.active.value);
// หน้านี้มีน้องแต่ถูกซ่อน → ต้องมีทางเรียกกลับเสมอ
const canRecall = computed(() => mascot.present.value && mascot.hidden.value);

function recallMascot() {
    chat.close();
    mascot.recall();
}

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
        // น้ำเสียงน้อง TPIX แต่ตอบยาวได้ตามหน้าต่างแชท (บับเบิ้ลบนหน้าแรกใช้ 'mascot' ที่ตอบสั้น)
        persona: 'mascot-chat',
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
    <!-- ปุ่มเปิดแชท = หน้าน้อง TPIX (หน้าที่มีตัวน้องอยู่แล้วใช้ตัวน้องเป็นปุ่มแทน) -->
    <button
        v-if="!isOpen && !mascotOnScreen"
        type="button"
        :aria-label="t('chatbot.open')"
        :title="t('mascot.talkTo')"
        class="tpix-chat-launcher group fixed bottom-6 right-6 z-50 w-16 h-16 rounded-full p-[3px] bg-gradient-to-br from-primary-400 via-accent-500 to-warm-500 shadow-lg shadow-primary-500/30 hover:scale-110 transition-transform"
        @click="chat.open()"
    >
        <span class="block w-full h-full rounded-full overflow-hidden bg-dark-900">
            <img v-if="!faceBroken" :src="FACE_SRC" alt="" class="w-full h-full object-cover" @error="faceBroken = true" />
            <svg v-else class="w-7 h-7 m-auto mt-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
            </svg>
        </span>
        <span class="absolute top-0.5 right-0.5 w-3.5 h-3.5 bg-trading-green rounded-full ring-2 ring-dark-950 animate-pulse"></span>
        <!-- ป้ายเล็กชวนคุย (โผล่ตอนชี้) -->
        <span class="pointer-events-none absolute right-full top-1/2 -translate-y-1/2 mr-3 whitespace-nowrap rounded-full bg-dark-900/90 border border-white/10 px-3 py-1.5 text-xs font-semibold text-white opacity-0 translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all">
            {{ t('mascot.askPill') }}
        </span>
    </button>

    <!-- เรียกน้องกลับมา (ซ่อนน้องไว้บนหน้าที่มีน้อง) -->
    <Transition name="tpix-recall">
        <button
            v-if="canRecall && !isOpen"
            type="button"
            class="tpix-recall group fixed right-[30px] bottom-[100px] z-50 w-12 h-12 rounded-full p-[2px] bg-gradient-to-br from-warm-400 via-accent-500 to-primary-400 shadow-lg shadow-accent-500/30 hover:scale-110 transition-transform"
            :aria-label="t('mascot.recall')"
            :title="t('mascot.recall')"
            @click="recallMascot"
        >
            <span class="block w-full h-full rounded-full overflow-hidden bg-dark-900">
                <img v-if="!faceBroken" :src="FACE_SRC" alt="" class="w-full h-full object-cover" @error="faceBroken = true" />
                <span v-else class="flex w-full h-full items-center justify-center text-lg">💙</span>
            </span>
            <span class="tpix-recall__spark" aria-hidden="true">✨</span>
            <span class="pointer-events-none absolute right-full top-1/2 -translate-y-1/2 mr-3 whitespace-nowrap rounded-full bg-dark-900/90 border border-white/10 px-3 py-1.5 text-xs font-semibold text-white opacity-0 translate-x-1 group-hover:opacity-100 group-hover:translate-x-0 transition-all">
                {{ t('mascot.recall') }}
            </span>
        </button>
    </Transition>

    <!-- Chat Window -->
    <div v-if="isOpen"
        class="fixed bottom-6 right-6 z-50 w-[380px] max-w-[calc(100vw-2rem)] h-[550px] max-h-[calc(100vh-3rem)] bg-dark-800 border border-white/10 rounded-2xl shadow-2xl flex flex-col overflow-hidden">

        <!-- Header -->
        <div class="flex items-center justify-between px-4 py-3 bg-gradient-to-r from-primary-500/20 to-accent-500/20 border-b border-white/10">
            <div class="flex items-center gap-2.5">
                <div class="relative w-10 h-10 rounded-full p-[2px] bg-gradient-to-br from-primary-400 to-accent-500">
                    <img v-if="!faceBroken" :src="FACE_SRC" alt="" class="w-full h-full rounded-full object-cover bg-dark-900" @error="faceBroken = true" />
                    <span v-else class="w-full h-full rounded-full bg-dark-900 flex items-center justify-center text-sm">💙</span>
                    <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-trading-green ring-2 ring-dark-800"></span>
                </div>
                <div>
                    <p class="text-white text-sm font-semibold">{{ t('mascot.name') }}</p>
                    <p class="text-trading-green text-[10px]">● Online · AI</p>
                </div>
            </div>
            <div class="flex items-center gap-1">
            <button
                v-if="canRecall"
                type="button"
                class="px-2.5 py-1 rounded-lg text-[11px] font-semibold text-primary-200 bg-primary-500/15 border border-primary-500/30 hover:bg-primary-500/25 transition-colors"
                @click="recallMascot"
            >
                ✨ {{ t('mascot.recallShort') }}
            </button>
            <button type="button" @click="chat.close()" :aria-label="t('chatbot.close')" class="text-dark-400 hover:text-white transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
            </div>
        </div>

        <!-- Chat Messages -->
        <div ref="chatContainer" class="flex-1 overflow-y-auto p-4 space-y-3">
            <!-- คำทักทาย: ไม่เก็บลงประวัติ จึงสลับภาษาได้และไม่ถูกส่งให้บอท -->
            <div class="flex items-end gap-2 justify-start">
                <img v-if="!faceBroken" :src="FACE_SRC" alt="" class="w-7 h-7 rounded-full object-cover shrink-0 bg-dark-900" />
                <div class="max-w-[82%] px-3 py-2 rounded-xl text-sm bg-white/5 text-dark-200 rounded-bl-sm">
                    <p class="whitespace-pre-wrap">{{ t('mascot.chatGreeting') }}</p>
                </div>
            </div>

            <div v-for="msg in chat.messages.value" :key="msg.id"
                :class="['flex items-end gap-2', msg.role === 'user' ? 'justify-end' : 'justify-start']">
                <img v-if="msg.role !== 'user' && !faceBroken" :src="FACE_SRC" alt="" class="w-7 h-7 rounded-full object-cover shrink-0 bg-dark-900" />
                <div :class="[
                    'max-w-[82%] px-3 py-2 rounded-xl text-sm',
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
            <div v-if="isLoading" class="flex items-end gap-2 justify-start">
                <img v-if="!faceBroken" :src="FACE_SRC" alt="" class="w-7 h-7 rounded-full object-cover shrink-0 bg-dark-900" />
                <div class="bg-white/5 px-4 py-2 rounded-xl text-sm text-dark-400">
                    <span class="animate-pulse">{{ t('mascot.thinking') }}</span>
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
                <input v-model="message" type="text" maxlength="1000" :placeholder="t('mascot.askPlaceholder')"
                    :aria-label="t('mascot.askPlaceholder')"
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

<style scoped>
/* ประกายวิบวับบนปุ่มเรียกน้องกลับมา — ให้รู้ว่ากดได้ */
.tpix-recall__spark {
    position: absolute;
    top: -6px;
    right: -4px;
    font-size: 13px;
    filter: drop-shadow(0 0 6px rgb(251 191 36 / 0.9));
    animation: tpix-recall-twinkle 1.8s ease-in-out infinite;
}

@keyframes tpix-recall-twinkle {
    0%, 100% { opacity: 0.5; transform: scale(0.8) rotate(0deg); }
    50% { opacity: 1; transform: scale(1.15) rotate(20deg); }
}

.tpix-recall-enter-active,
.tpix-recall-leave-active {
    transition: opacity 0.3s ease, transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.tpix-recall-enter-from,
.tpix-recall-leave-to {
    opacity: 0;
    transform: translateY(12px) scale(0.6);
}

@media (prefers-reduced-motion: reduce) {
    .tpix-recall__spark {
        animation: none;
    }
}
</style>
