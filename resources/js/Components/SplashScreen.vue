<script setup>
/**
 * TPIX TRADE - Splash Screen
 * แสดง logo แบบ transparent (ไม่มี background) พร้อมเสียง startup
 * สไตล์เดียวกับแอพ wallet
 * Developed by Xman Studio
 */

import { ref, onMounted } from 'vue';
import { playSplashSound } from '@/Composables/useSounds';

const emit = defineEmits(['done']);

const phase = ref('logo');      // 'logo' → 'text' → 'fade'
const isVisible = ref(true);

onMounted(() => {
    // เล่นเสียง splash หลัง mount เล็กน้อย
    setTimeout(() => {
        playSplashSound();
    }, 300);

    // แสดง text หลัง logo animate
    setTimeout(() => {
        phase.value = 'text';
    }, 800);

    // เริ่ม fade out
    setTimeout(() => {
        phase.value = 'fade';
    }, 2200);

    // เสร็จ — ซ่อน splash
    setTimeout(() => {
        isVisible.value = false;
        emit('done');
    }, 2800);
});
</script>

<template>
    <Transition
        leave-active-class="transition-opacity duration-500 ease-out"
        leave-to-class="opacity-0"
    >
        <div v-if="isVisible" class="splash-screen">
            <!-- ไม่มี background — transparent ตาม icon style -->
            <!-- Subtle gradient glow effect only -->
            <div class="splash-glow"></div>

            <!-- Logo Container -->
            <div class="splash-content" :class="{ 'splash-fade': phase === 'fade' }">
                <!-- TPIX Logo — transparent, no background -->
                <div class="splash-logo" :class="{ 'splash-logo-in': phase !== 'logo' || true }">
                    <img
                        src="/tpixlogo.webp"
                        alt="TPIX"
                        class="splash-logo-img"
                    />
                    <!-- Glow ring around logo -->
                    <div class="splash-ring"></div>
                </div>

                <!-- App Name -->
                <div
                    class="splash-text"
                    :class="{ 'splash-text-in': phase === 'text' || phase === 'fade' }"
                >
                    <h1 class="splash-title">TPIX TRADE</h1>
                    <p class="splash-subtitle">Decentralized Exchange</p>
                </div>

                <!-- Loading indicator -->
                <div
                    class="splash-loader"
                    :class="{ 'splash-loader-in': phase === 'text' || phase === 'fade' }"
                >
                    <div class="splash-loader-bar">
                        <div class="splash-loader-fill"></div>
                    </div>
                </div>
            </div>

            <!-- Version -->
            <div class="splash-version" :class="{ 'splash-text-in': phase === 'text' || phase === 'fade' }">
                Powered by Xman Studio
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.splash-screen {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    /* transparent dark — ไม่มี solid background */
    background: radial-gradient(ellipse at center, rgba(6, 182, 212, 0.03) 0%, rgba(2, 6, 23, 0.98) 70%);
}

.splash-glow {
    position: absolute;
    width: 300px;
    height: 300px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(6, 182, 212, 0.15) 0%, rgba(139, 92, 246, 0.08) 40%, transparent 70%);
    filter: blur(60px);
    animation: splash-glow-pulse 2s ease-in-out infinite;
}

@keyframes splash-glow-pulse {
    0%, 100% { opacity: 0.5; transform: scale(1); }
    50% { opacity: 1; transform: scale(1.1); }
}

.splash-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 24px;
    z-index: 1;
    transition: all 0.5s ease;
}

.splash-fade {
    opacity: 0.6;
    transform: scale(0.97);
}

/* Logo */
.splash-logo {
    position: relative;
    width: 96px;
    height: 96px;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: splash-logo-enter 0.8s cubic-bezier(0.34, 1.56, 0.64, 1) both;
}

@keyframes splash-logo-enter {
    0% { opacity: 0; transform: scale(0.3) rotate(-20deg); }
    100% { opacity: 1; transform: scale(1) rotate(0deg); }
}

.splash-logo-img {
    width: 80px;
    height: 80px;
    object-fit: contain;
    filter: drop-shadow(0 0 20px rgba(6, 182, 212, 0.4)) drop-shadow(0 0 40px rgba(139, 92, 246, 0.2));
    /* ไม่มี background — transparent */
}

.splash-ring {
    position: absolute;
    inset: -8px;
    border-radius: 50%;
    border: 2px solid transparent;
    background: conic-gradient(from 0deg, rgba(6, 182, 212, 0.6), rgba(139, 92, 246, 0.4), rgba(249, 115, 22, 0.3), rgba(6, 182, 212, 0.6)) border-box;
    -webkit-mask: linear-gradient(#fff 0 0) padding-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    animation: splash-ring-spin 3s linear infinite;
}

@keyframes splash-ring-spin {
    to { transform: rotate(360deg); }
}

/* Text */
.splash-text {
    text-align: center;
    opacity: 0;
    transform: translateY(12px);
    transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.splash-text-in {
    opacity: 1;
    transform: translateY(0);
}

.splash-title {
    font-size: 1.5rem;
    font-weight: 700;
    letter-spacing: 0.15em;
    background: linear-gradient(135deg, #22d3ee 0%, #a78bfa 50%, #f97316 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.splash-subtitle {
    font-size: 0.75rem;
    color: rgba(148, 163, 184, 0.7);
    margin-top: 4px;
    letter-spacing: 0.2em;
    text-transform: uppercase;
}

/* Loader */
.splash-loader {
    width: 120px;
    opacity: 0;
    transform: translateY(8px);
    transition: all 0.4s ease 0.2s;
}

.splash-loader-in {
    opacity: 1;
    transform: translateY(0);
}

.splash-loader-bar {
    height: 2px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 2px;
    overflow: hidden;
}

.splash-loader-fill {
    height: 100%;
    width: 0%;
    background: linear-gradient(90deg, #06b6d4, #8b5cf6, #f97316);
    border-radius: 2px;
    animation: splash-load 2s ease-in-out forwards;
}

@keyframes splash-load {
    0% { width: 0%; }
    50% { width: 70%; }
    100% { width: 100%; }
}

/* Version */
.splash-version {
    position: absolute;
    bottom: 32px;
    font-size: 0.65rem;
    color: rgba(100, 116, 139, 0.5);
    letter-spacing: 0.1em;
    opacity: 0;
    transform: translateY(8px);
    transition: all 0.4s ease 0.3s;
}
</style>
