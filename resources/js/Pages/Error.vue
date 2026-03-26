<script setup>
/**
 * TPIX TRADE — Error Page (ทุกประเภท)
 * รองรับ 401, 403, 404, 419, 429, 500, 503 + custom
 * แสดงผลสวยงามแบบ TPIX Chain branding
 * Developed by Xman Studio
 */
import { computed, ref, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { useTranslation } from '@/Composables/useTranslation';

const props = defineProps({
    status: { type: Number, default: 404 },
});

const { t, locale } = useTranslation();
const isThai = computed(() => locale.value === 'th');

// Floating particles animation
const particles = ref([]);
onMounted(() => {
    particles.value = Array.from({ length: 20 }, (_, i) => ({
        id: i,
        x: Math.random() * 100,
        y: Math.random() * 100,
        size: Math.random() * 3 + 1,
        duration: Math.random() * 15 + 10,
        delay: Math.random() * 5,
    }));
});

// Error config by status code
const errorConfig = computed(() => {
    const configs = {
        401: {
            title: isThai.value ? 'ยังไม่ได้เข้าสู่ระบบ' : 'Unauthorized',
            subtitle: isThai.value ? 'กรุณาเข้าสู่ระบบเพื่อเข้าถึงหน้านี้' : 'Please sign in to access this page.',
            icon: 'lock',
            color: 'amber',
            gradient: 'from-amber-500/20 via-orange-500/10 to-transparent',
            glowColor: 'bg-amber-500/15',
            action: { label: isThai.value ? 'เข้าสู่ระบบ' : 'Sign In', href: '/login' },
        },
        403: {
            title: isThai.value ? 'ไม่มีสิทธิ์เข้าถึง' : 'Access Forbidden',
            subtitle: isThai.value ? 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้' : 'You don\'t have permission to access this resource.',
            icon: 'shield',
            color: 'red',
            gradient: 'from-red-500/20 via-rose-500/10 to-transparent',
            glowColor: 'bg-red-500/15',
            action: { label: isThai.value ? 'กลับหน้าหลัก' : 'Go Home', href: '/' },
        },
        404: {
            title: isThai.value ? 'ไม่พบหน้าที่ค้นหา' : 'Page Not Found',
            subtitle: isThai.value ? 'หน้าที่คุณกำลังค้นหาอาจถูกย้ายหรือไม่มีอยู่แล้ว' : 'The page you\'re looking for has been moved or doesn\'t exist.',
            icon: 'search',
            color: 'cyan',
            gradient: 'from-primary-500/20 via-accent-500/10 to-transparent',
            glowColor: 'bg-primary-500/15',
            action: { label: isThai.value ? 'กลับหน้าหลัก' : 'Go Home', href: '/' },
        },
        419: {
            title: isThai.value ? 'เซสชันหมดอายุ' : 'Session Expired',
            subtitle: isThai.value ? 'เซสชันของคุณหมดอายุแล้ว กรุณารีเฟรชหน้า' : 'Your session has expired. Please refresh the page.',
            icon: 'clock',
            color: 'purple',
            gradient: 'from-purple-500/20 via-violet-500/10 to-transparent',
            glowColor: 'bg-purple-500/15',
            action: { label: isThai.value ? 'รีเฟรช' : 'Refresh', href: null, reload: true },
        },
        429: {
            title: isThai.value ? 'คำขอมากเกินไป' : 'Too Many Requests',
            subtitle: isThai.value ? 'คุณส่งคำขอเร็วเกินไป กรุณารอสักครู่แล้วลองใหม่' : 'You\'re sending requests too fast. Please wait a moment.',
            icon: 'zap',
            color: 'orange',
            gradient: 'from-orange-500/20 via-amber-500/10 to-transparent',
            glowColor: 'bg-orange-500/15',
            action: { label: isThai.value ? 'ลองใหม่' : 'Try Again', href: null, reload: true },
        },
        500: {
            title: isThai.value ? 'เซิร์ฟเวอร์ขัดข้อง' : 'Server Error',
            subtitle: isThai.value ? 'เกิดข้อผิดพลาดภายในเซิร์ฟเวอร์ ทีมงานกำลังแก้ไข' : 'Something went wrong on our end. Our team is working on it.',
            icon: 'server',
            color: 'red',
            gradient: 'from-red-500/20 via-pink-500/10 to-transparent',
            glowColor: 'bg-red-500/15',
            action: { label: isThai.value ? 'กลับหน้าหลัก' : 'Go Home', href: '/' },
        },
        503: {
            title: isThai.value ? 'กำลังปรับปรุงระบบ' : 'Under Maintenance',
            subtitle: isThai.value ? 'ระบบกำลังปรับปรุง กรุณากลับมาอีกครั้ง' : 'We\'re upgrading our systems. Please check back soon.',
            icon: 'tool',
            color: 'blue',
            gradient: 'from-blue-500/20 via-indigo-500/10 to-transparent',
            glowColor: 'bg-blue-500/15',
            action: { label: isThai.value ? 'รีเฟรช' : 'Refresh', href: null, reload: true },
        },
    };

    return configs[props.status] || configs[404];
});

const colorMap = {
    cyan: { text: 'text-primary-400', border: 'border-primary-500/30', bg: 'bg-primary-500/10', ring: 'ring-primary-500/20' },
    red: { text: 'text-red-400', border: 'border-red-500/30', bg: 'bg-red-500/10', ring: 'ring-red-500/20' },
    amber: { text: 'text-amber-400', border: 'border-amber-500/30', bg: 'bg-amber-500/10', ring: 'ring-amber-500/20' },
    purple: { text: 'text-purple-400', border: 'border-purple-500/30', bg: 'bg-purple-500/10', ring: 'ring-purple-500/20' },
    orange: { text: 'text-orange-400', border: 'border-orange-500/30', bg: 'bg-orange-500/10', ring: 'ring-orange-500/20' },
    blue: { text: 'text-blue-400', border: 'border-blue-500/30', bg: 'bg-blue-500/10', ring: 'ring-blue-500/20' },
};

const colors = computed(() => colorMap[errorConfig.value.color] || colorMap.cyan);

function handleAction() {
    if (errorConfig.value.action.reload) {
        window.location.reload();
    } else if (errorConfig.value.action.href) {
        window.location.href = errorConfig.value.action.href;
    }
}

// Quick links
const quickLinks = [
    { label: { th: 'หน้าหลัก', en: 'Home' }, href: '/', icon: 'home' },
    { label: { th: 'เทรด', en: 'Trade' }, href: '/trade', icon: 'chart' },
    { label: { th: 'ตลาด', en: 'Markets' }, href: '/markets', icon: 'market' },
    { label: { th: 'สำรวจ', en: 'Explorer' }, href: '/explorer', icon: 'explore' },
];
</script>

<template>
    <Head :title="`${status} - ${errorConfig.title}`" />

    <div class="min-h-screen bg-dark-950 relative overflow-hidden flex flex-col">
        <!-- Background Image (faint) -->
        <div class="fixed inset-0 pointer-events-none">
            <div class="absolute inset-0 bg-cover bg-center bg-no-repeat opacity-[0.04]"
                style="background-image: url('/images/bg1.webp')" />
        </div>

        <!-- Animated Gradient Orbs -->
        <div class="fixed inset-0 pointer-events-none overflow-hidden">
            <div class="absolute top-1/4 left-1/4 w-[500px] h-[500px] rounded-full blur-[120px] animate-pulse"
                :class="errorConfig.glowColor" />
            <div class="absolute bottom-1/4 right-1/4 w-[400px] h-[400px] bg-primary-500/8 rounded-full blur-[100px] animate-pulse"
                style="animation-delay: 2s" />
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[300px] h-[300px] bg-accent-500/5 rounded-full blur-[80px] animate-pulse"
                style="animation-delay: 4s" />
        </div>

        <!-- Floating Particles -->
        <div class="fixed inset-0 pointer-events-none overflow-hidden">
            <div
                v-for="p in particles"
                :key="p.id"
                class="absolute rounded-full bg-white/10"
                :style="{
                    left: p.x + '%',
                    top: p.y + '%',
                    width: p.size + 'px',
                    height: p.size + 'px',
                    animation: `float ${p.duration}s ease-in-out ${p.delay}s infinite alternate`,
                }"
            />
        </div>

        <!-- Grid pattern overlay -->
        <div class="fixed inset-0 pointer-events-none opacity-[0.03]"
            style="background-image: linear-gradient(rgba(255,255,255,0.1) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.1) 1px, transparent 1px); background-size: 60px 60px;" />

        <!-- Main Content -->
        <div class="relative z-10 flex-1 flex items-center justify-center px-4 py-12">
            <div class="max-w-lg w-full text-center">

                <!-- Logo -->
                <Link href="/" class="inline-flex items-center gap-3 mb-10 group">
                    <img src="/logo.webp" alt="TPIX TRADE" class="w-10 h-10 object-contain group-hover:scale-110 transition-transform" />
                    <div class="text-left">
                        <span class="text-lg font-bold text-white">TPIX <span class="text-primary-400">TRADE</span></span>
                        <span class="block text-[10px] text-dark-400 -mt-0.5">by Xman Studio</span>
                    </div>
                </Link>

                <!-- Error Code (large) -->
                <div class="relative mb-6">
                    <!-- Ghost number behind -->
                    <span class="absolute inset-0 flex items-center justify-center text-[160px] sm:text-[200px] font-black select-none opacity-[0.04] leading-none"
                        :class="colors.text">
                        {{ status }}
                    </span>
                    <!-- Main number -->
                    <span class="relative text-7xl sm:text-8xl font-black leading-none"
                        :class="colors.text"
                        style="text-shadow: 0 0 40px currentColor;">
                        {{ status }}
                    </span>
                </div>

                <!-- Icon -->
                <div class="mb-6 flex justify-center">
                    <div class="w-16 h-16 rounded-2xl flex items-center justify-center ring-1"
                        :class="[colors.bg, colors.ring]">
                        <!-- Lock -->
                        <svg v-if="errorConfig.icon === 'lock'" class="w-7 h-7" :class="colors.text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                        <!-- Shield -->
                        <svg v-else-if="errorConfig.icon === 'shield'" class="w-7 h-7" :class="colors.text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m0-10.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.75c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.57-.598-3.75h-.152c-3.196 0-6.1-1.249-8.25-3.286zm0 13.036h.008v.008H12v-.008z" />
                        </svg>
                        <!-- Search -->
                        <svg v-else-if="errorConfig.icon === 'search'" class="w-7 h-7" :class="colors.text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                        <!-- Clock -->
                        <svg v-else-if="errorConfig.icon === 'clock'" class="w-7 h-7" :class="colors.text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <!-- Zap -->
                        <svg v-else-if="errorConfig.icon === 'zap'" class="w-7 h-7" :class="colors.text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                        </svg>
                        <!-- Server -->
                        <svg v-else-if="errorConfig.icon === 'server'" class="w-7 h-7" :class="colors.text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.75 17.25v-.228a4.5 4.5 0 00-.12-1.03l-2.268-9.64a3.375 3.375 0 00-3.285-2.602H7.923a3.375 3.375 0 00-3.285 2.602l-2.268 9.64a4.5 4.5 0 00-.12 1.03v.228m19.5 0a3 3 0 01-3 3H5.25a3 3 0 01-3-3m19.5 0a3 3 0 00-3-3H5.25a3 3 0 00-3 3m16.5 0h.008v.008h-.008v-.008zm-3 0h.008v.008h-.008v-.008z" />
                        </svg>
                        <!-- Tool (wrench) -->
                        <svg v-else class="w-7 h-7" :class="colors.text" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.42 15.17l-4.655 4.655a2.625 2.625 0 11-3.712-3.712l4.655-4.655m3.712 3.712a2.625 2.625 0 010-3.712M11.42 15.17l4.655-4.655a2.625 2.625 0 013.712 3.712l-4.655 4.655m-3.712-3.712a2.625 2.625 0 010 3.712" />
                        </svg>
                    </div>
                </div>

                <!-- Title & Subtitle -->
                <h1 class="text-2xl sm:text-3xl font-bold text-white mb-3">
                    {{ errorConfig.title }}
                </h1>
                <p class="text-dark-400 text-sm sm:text-base leading-relaxed mb-8 max-w-md mx-auto">
                    {{ errorConfig.subtitle }}
                </p>

                <!-- Action Button -->
                <button
                    @click="handleAction"
                    class="inline-flex items-center gap-2 px-8 py-3 rounded-xl font-semibold text-white transition-all duration-200 hover:scale-105 active:scale-95"
                    :class="[colors.bg, colors.border, 'border', 'hover:brightness-125', 'backdrop-blur-sm']"
                    style="box-shadow: 0 0 30px rgba(6, 182, 212, 0.15);"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path v-if="errorConfig.action.reload" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182" />
                        <path v-else-if="errorConfig.action.href === '/login'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                    </svg>
                    {{ errorConfig.action.label }}
                </button>

                <!-- Quick Links -->
                <div class="mt-12 pt-8 border-t border-white/5">
                    <p class="text-xs text-dark-500 mb-4">
                        {{ isThai ? 'ลิงก์ที่อาจเป็นประโยชน์' : 'Helpful links' }}
                    </p>
                    <div class="flex flex-wrap justify-center gap-3">
                        <Link
                            v-for="link in quickLinks"
                            :key="link.href"
                            :href="link.href"
                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs text-dark-300 bg-white/[0.03] border border-white/5 hover:border-primary-500/30 hover:text-primary-400 transition-all duration-200"
                        >
                            <!-- Home -->
                            <svg v-if="link.icon === 'home'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75" />
                            </svg>
                            <!-- Chart -->
                            <svg v-else-if="link.icon === 'chart'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                            </svg>
                            <!-- Market -->
                            <svg v-else-if="link.icon === 'market'" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
                            </svg>
                            <!-- Explore -->
                            <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                            </svg>
                            {{ isThai ? link.label.th : link.label.en }}
                        </Link>
                    </div>
                </div>

                <!-- Chain Badge -->
                <div class="mt-8 flex justify-center">
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-white/[0.03] border border-white/5">
                        <div class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                        <span class="text-[10px] text-dark-500">TPIX Chain (ID: 4289) — Powered by Polygon Edge</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="relative z-10 py-4 text-center border-t border-white/5">
            <div class="flex items-center justify-center gap-2">
                <img src="/logo.webp" alt="TPIX" class="w-4 h-4 object-contain opacity-50" />
                <span class="text-[10px] text-dark-500">&copy; {{ new Date().getFullYear() }} Xman Studio. All rights reserved.</span>
            </div>
        </footer>
    </div>
</template>

<style scoped>
@keyframes float {
    0% { transform: translateY(0) translateX(0); opacity: 0.3; }
    50% { opacity: 0.6; }
    100% { transform: translateY(-30px) translateX(15px); opacity: 0.1; }
}
</style>
