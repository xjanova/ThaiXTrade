<script setup>
/**
 * TPIX TRADE - Navigation Bar Component
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useWalletStore } from '@/Stores/walletStore';
import { useTradeLayout, TRADE_CARDS } from '@/Composables/useTradeLayout';
import ChainSelector from '@/Components/Navigation/ChainSelector.vue';
import LanguageSwitcher from '@/Components/Navigation/LanguageSwitcher.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t } = useTranslation();

const props = defineProps({
    user: Object,
    wallet: Object,
});

const emit = defineEmits(['toggle-sidebar', 'connect-wallet']);

const walletStore = useWalletStore();


const isWalletConnected = computed(() => walletStore.isConnected);
const shortAddress = computed(() => walletStore.shortAddress);

// Auth user from Inertia shared props
const page = usePage();

/*
 * ปุ่มปรับผังกระดานเทรด
 *
 * ย้ายมาไว้ที่นี่ตามที่เจ้าของสั่ง เพื่อคืนพื้นที่ให้หน้าเทรด (เดิมกินเต็มแถวเพื่อปุ่มเดียว)
 *
 * useTradeLayout เป็น module singleton — เรียกตรงจากที่นี่ได้เลย ไม่ต้องส่ง prop
 * ข้ามคอมโพเนนต์ แต่ NavBar อยู่ทุกหน้า จึงต้องกันไม่ให้ปุ่มโผล่นอกหน้าเทรด
 */
const tradeLayout = useTradeLayout();
const showLayoutMenu = ref(false);

const isTradePage = computed(() => (page.url || '').startsWith('/trade'));

const hideableCards = computed(() =>
    TRADE_CARDS.filter(c => !c.essential).map(c => ({ id: c.id, label: t(c.titleKey) }))
);

function closeLayoutMenu(event) {
    if (!event.target.closest('.nav-layout-menu')) showLayoutMenu.value = false;
}

onMounted(() => document.addEventListener('click', closeLayoutMenu));
onUnmounted(() => document.removeEventListener('click', closeLayoutMenu));
const authUser = computed(() => page.props.auth?.user);

const menuOpen = ref(false);
const showWalletMenu = ref(false);
const showUserMenu = ref(false);

/**
 * ชื่อที่แสดงในเมนูบัญชี.
 *
 * ผู้ใช้ที่เข้าระบบด้วยการเซ็นกระเป๋าไม่มีทั้งชื่อและอีเมล — ถ้าไม่ตกมาที่เลขกระเป๋า
 * เมนูจะโชว์ช่องว่างเปล่าๆ แล้วดูเหมือนหน้าเว็บพัง ทั้งที่ล็อกอินสำเร็จแล้ว
 */
const userDisplayName = computed(() => {
    const user = authUser.value;
    if (!user) return '';
    if (user.name) return user.name;
    if (user.email) return user.email;
    if (user.wallet_address) return `${user.wallet_address.slice(0, 6)}...${user.wallet_address.slice(-4)}`;

    return 'Trader';
});

/*
 * บัญชีที่ล็อกอินอยู่กับกระเป๋าที่เชื่อมอยู่ เป็นคนเดียวกันไหม
 *
 * หลังจากที่การเซ็นกระเป๋าเปิด session ให้จริง ผู้ใช้กระเป๋าจะมีทั้ง authUser และ
 * walletStore.address พร้อมกัน — ถ้าไม่รวมเมนู จะได้ปุ่มสองอันข้างกันที่แสดง
 * เลขกระเป๋าใบเดียวกัน ผู้ใช้ต้องเดาเองว่าอันไหนทำอะไร
 */
const sameIdentity = computed(() => {
    const linked = authUser.value?.wallet_address?.toLowerCase();
    const connected = walletStore.address?.toLowerCase();

    return !!linked && !!connected && linked === connected;
});

/*
 * ⚠️ กระเป๋าที่เชื่อมอยู่ไม่ใช่ใบที่ผูกกับบัญชี — ต้องเห็นชัด ห้ามรวมเมนู
 *
 * เกิดเมื่อผู้ใช้ล็อกอินด้วยอีเมลแล้วสลับบัญชีในกระเป๋า หรือเชื่อมกระเป๋าใบใหม่
 * ที่ยังไม่ได้ผูก — รวมเมนูตอนนี้เท่ากับบอกว่าเป็นตัวตนเดียวกันทั้งที่ไม่ใช่
 * แล้วเขาอาจเทรดด้วยกระเป๋าที่ไม่ได้ผูกโดยไม่รู้ตัว
 */
const walletMismatch = computed(() => {
    const linked = authUser.value?.wallet_address?.toLowerCase();
    const connected = walletStore.address?.toLowerCase();

    return !!connected && !!linked && linked !== connected;
});

/** โชว์เมนูกระเป๋าแยกต่างหากเมื่อไหร่ */
/*
 * ป้ายสถานะยืนยันตัวตนในเมนูบัญชี
 *
 * เอาสถานะจาก props ที่ HandleInertiaRequests แชร์มาให้ทุกหน้าอยู่แล้ว
 * ไม่ต้องยิง API เพิ่ม และไม่มีทางค้างไม่ตรงกับด่านจริง เพราะมาจากตัวเดียวกัน
 */
const kycShared = computed(() => page.props.kyc || {});

// โชว์เมนูเฉพาะตอนมีด่านเปิดอยู่จริง — ไม่งั้นเท่ากับขอเอกสารที่เราไม่ได้ใช้
const kycVisible = computed(() => {
    if (!authUser.value || !kycShared.value.enabled) return false;
    return Object.values(kycShared.value.features || {}).some((f) => f.required);
});

const kycBadgeLabel = computed(() => {
    if (kycShared.value.approved_level) return 'ผ่านแล้ว';
    return { pending: 'รอตรวจ', rejected: 'ไม่ผ่าน' }[authUser.value?.kyc_status] ?? 'ยังไม่ยืนยัน';
});

const kycBadgeClass = computed(() => {
    if (kycShared.value.approved_level) return 'bg-emerald-500/15 text-emerald-300';
    if (authUser.value?.kyc_status === 'pending') return 'bg-amber-500/15 text-amber-300';
    if (authUser.value?.kyc_status === 'rejected') return 'bg-red-500/15 text-red-300';
    return 'bg-white/10 text-dark-300';
});

const showSeparateWalletMenu = computed(() =>
    isWalletConnected.value && (!authUser.value || walletMismatch.value)
);

const userInitial = computed(() => {
    const name = authUser.value?.name || authUser.value?.email;
    // เลขกระเป๋าขึ้นต้นด้วย 0x เหมือนกันทุกใบ — ตัวอักษรตัวแรกไม่ได้แยกใครออกจากใคร
    // ใช้ตัวแรกหลัง 0x แทน จะได้ไม่เห็นวงกลม "0" เหมือนกันหมดทุกคน
    if (!name) {
        const wallet = authUser.value?.wallet_address;
        return wallet ? wallet.slice(2, 3).toUpperCase() : 'U';
    }

    return name[0].toUpperCase();
});

const handleLogout = () => {
    showUserMenu.value = false;
    router.post('/logout');
};

const handleDisconnect = () => {
    walletStore.disconnect();
    showWalletMenu.value = false;
};
</script>

<template>
    <nav class="sticky top-0 z-40 glass-dark border-b border-white/5">
        <div class="max-w-[1920px] mx-auto px-4 lg:px-6">
            <div class="flex items-center justify-between h-16">
                <!-- Left: Logo & Toggle -->
                <div class="flex items-center gap-4">
                    <!-- Mobile Menu Toggle -->
                    <button
                        @click="$emit('toggle-sidebar')"
                        class="lg:hidden p-2 rounded-xl text-dark-400 hover:text-white hover:bg-white/5"
                    >
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>

                    <!-- Logo -->
                    <Link href="/" class="flex items-center gap-3">
                        <img src="/logo.webp" alt="TPIX TRADE" class="w-10 h-10 object-contain" />
                        <div class="hidden sm:block">
                            <h1 class="text-xl font-bold text-white">TPIX <span class="text-gradient">TRADE</span></h1>
                            <p class="text-xs text-dark-400">by Xman Studio</p>
                        </div>
                    </Link>
                </div>

                <!-- Center: Main Navigation -->
                <div class="hidden md:flex items-center gap-1">
                    <Link href="/trade" class="nav-link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>
                        </svg>
                        <span>{{ t('nav.trade') }}</span>
                    </Link>
                    <Link href="/swap" class="nav-link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                        <span>{{ t('nav.swap') }}</span>
                    </Link>
                    <Link href="/portfolio" class="nav-link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9"/>
                        </svg>
                        <span>{{ t('nav.portfolio') }}</span>
                    </Link>
                    <Link href="/token-sale" class="nav-link">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span>{{ t('nav.tokenSale') }}</span>
                    </Link>
                    <!-- More dropdown เพื่อไม่ให้ nav ยาวเกินไป -->
                    <div class="relative group">
                        <button class="nav-link">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z"/>
                            </svg>
                            <span>{{ t('common.viewAll') }}</span>
                        </button>
                        <div class="absolute top-full left-0 mt-1 w-48 rounded-xl bg-dark-900/90 backdrop-blur-2xl border border-white/10 shadow-2xl py-2 z-50 invisible group-hover:visible opacity-0 group-hover:opacity-100 transition-all">
                            <Link href="/ai-trade" class="flex items-center gap-2 px-4 py-2 text-sm text-accent-300 hover:text-accent-200 hover:bg-accent-500/10">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09Z"/>
                                </svg>
                                AI TRADE
                            </Link>
                            <Link href="/ai-assistant" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                AI Assistant
                            </Link>
                            <Link href="/explorer" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                Explorer
                            </Link>
                            <Link href="/whitepaper" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                Whitepaper
                            </Link>
                            <Link href="/token-factory" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                Token Factory
                            </Link>
                            <Link href="/launch" class="flex items-center gap-2 px-4 py-2 text-sm text-primary-400 hover:text-primary-300 hover:bg-primary-500/10">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                                </svg>
                                Fair Launch
                            </Link>
                            <Link href="/carbon-credits" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                Carbon Credits
                            </Link>
                            <div class="border-t border-white/5 my-1"></div>
                            <Link href="/liquidity" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                {{ t('nav.liquidity') }}
                            </Link>
                            <Link href="/bridge" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                {{ t('nav.bridge') }}
                            </Link>
                            <Link href="/masternode" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                {{ t('nav.masternode') }}
                            </Link>
                            <Link href="/blog" class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5">
                                {{ t('nav.blog') }}
                            </Link>
                            <div class="border-t border-white/5 my-1"></div>
                            <Link href="/download" class="flex items-center gap-2 px-4 py-2 text-sm text-primary-400 hover:text-primary-300 hover:bg-primary-500/10">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                </svg>
                                Download App
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Right: Wallet & User -->
                <div class="flex items-center gap-3">
                    <!--
                        ปรับผังกระดานเทรด — โผล่เฉพาะหน้าเทรด
                        ย้ายมาจากแถบในหน้าเทรดที่กินเต็มแถวเพื่อปุ่มเดียว (~44px)
                    -->
                    <div v-if="isTradePage" class="nav-layout-menu relative hidden xl:block">
                        <button
                            type="button"
                            class="flex items-center gap-1.5 px-2.5 py-2 rounded-xl glass-sm hover:bg-white/10 text-[11px] text-dark-300 hover:text-white transition-all"
                            :title="t('trade.layout.customize')"
                            @click.stop="showLayoutMenu = !showLayoutMenu"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16M4 12h10M4 18h7" />
                            </svg>
                            <span class="hidden 2xl:inline">{{ t('trade.layout.customize') }}</span>
                        </button>

                        <div
                            v-if="showLayoutMenu"
                            class="absolute right-0 top-full mt-2 w-64 z-50 rounded-xl border border-white/10 bg-dark-800/95 backdrop-blur-xl shadow-2xl p-3"
                            @click.stop
                        >
                            <p class="text-[11px] text-dark-400 mb-2 leading-relaxed">
                                {{ t('trade.layout.hint') }}
                            </p>

                            <label class="flex items-start gap-2 py-1.5 cursor-pointer border-y border-white/5 mb-2">
                                <input
                                    type="checkbox"
                                    class="mt-0.5 rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500 w-3.5 h-3.5"
                                    :checked="tradeLayout.fitScreen.value"
                                    @change="tradeLayout.fitScreen.value = !tradeLayout.fitScreen.value"
                                >
                                <span class="min-w-0">
                                    <span class="block text-xs text-white">{{ t('trade.layout.fitScreen') }}</span>
                                    <span class="block text-[10px] text-dark-500 leading-snug">{{ t('trade.layout.fitScreenHint') }}</span>
                                </span>
                            </label>

                            <p class="text-[10px] text-dark-500 mb-1.5">{{ t('trade.layout.showCards') }}</p>
                            <label
                                v-for="card in hideableCards"
                                :key="card.id"
                                class="flex items-center gap-2 py-1 cursor-pointer text-xs text-dark-300 hover:text-white"
                            >
                                <input
                                    type="checkbox"
                                    class="rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500 w-3.5 h-3.5"
                                    :checked="!tradeLayout.hidden.value.includes(card.id)"
                                    @change="tradeLayout.toggleHidden(card.id)"
                                >
                                {{ card.label }}
                            </label>

                            <button
                                type="button"
                                class="w-full mt-2.5 py-1.5 rounded-lg bg-white/5 text-[11px] text-dark-300 hover:text-white hover:bg-white/10 transition-colors"
                                @click="tradeLayout.reset()"
                            >
                                {{ t('trade.layout.reset') }}
                            </button>
                        </div>
                    </div>

                    <!--
                        ตัวเลือก Chain — รองรับหลาย chain พร้อม auto-switch

                        ห้ามซ่อนบนมือถือ: นี่เป็นที่เดียวในเว็บที่สลับเชนได้ ซ่อนเมื่อไหร่
                        ผู้ใช้มือถือที่กระเป๋าอยู่ผิดเชนจะติดอยู่ตรงนั้นถาวร ทำอะไรไม่ได้เลย
                        ตัวคอมโพเนนต์ย่อชื่อเชนทิ้งเองอยู่แล้วเมื่อจอแคบ เหลือแค่ไอคอน
                    -->
                    <ChainSelector />

                    <!-- Language Switcher -->
                    <LanguageSwitcher />

                    <!-- User Auth Menu -->
                    <div v-if="authUser" class="relative">
                        <button
                            @click="showUserMenu = !showUserMenu"
                            class="flex items-center gap-2 px-3 py-1.5 rounded-xl hover:bg-white/5 transition-all"
                        >
                            <div class="w-8 h-8 rounded-full bg-primary-500/20 border border-primary-500/30 flex items-center justify-center overflow-hidden">
                                <img v-if="authUser.avatar" :src="authUser.avatar" class="w-full h-full object-cover" />
                                <span v-else class="text-sm font-bold text-primary-400">{{ userInitial }}</span>
                            </div>
                            <span class="hidden sm:inline text-sm text-dark-300">{{ userDisplayName }}</span>
                            <svg class="w-3 h-3 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <Transition
                            enter-active-class="transition ease-out duration-100"
                            enter-from-class="opacity-0 scale-95"
                            enter-to-class="opacity-100 scale-100"
                            leave-active-class="transition ease-in duration-75"
                            leave-from-class="opacity-100 scale-100"
                            leave-to-class="opacity-0 scale-95"
                        >
                            <div
                                v-if="showUserMenu"
                                class="absolute right-0 top-full mt-2 w-48 rounded-xl bg-dark-900/90 backdrop-blur-2xl border border-white/10 shadow-2xl py-2 z-50"
                                @click.stop
                            >
                                <div class="px-4 py-2.5 border-b border-white/5">
                                    <p class="text-sm text-white font-medium truncate">{{ authUser.name || 'Trader' }}</p>
                                    <p v-if="authUser.email" class="text-xs text-dark-400 truncate">{{ authUser.email }}</p>

                                    <!--
                                        บัญชีกับกระเป๋าเป็นตัวตนเดียวกัน = รวมรายละเอียดไว้ที่เดียว
                                        ไม่ต้องมีเมนูกระเป๋าอีกอันที่แสดงเลขเดียวกันข้างๆ
                                    -->
                                    <div v-if="sameIdentity" class="mt-1.5 flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-trading-green shrink-0"></span>
                                        <span class="text-[11px] text-dark-400 font-mono truncate">{{ shortAddress }}</span>
                                        <span class="text-[10px] text-dark-500 shrink-0">· {{ walletStore.chainId }}</span>
                                    </div>
                                    <p v-else-if="authUser.wallet_address" class="text-xs text-dark-400 font-mono truncate mt-0.5">
                                        {{ authUser.wallet_address.slice(0, 6) }}...{{ authUser.wallet_address.slice(-4) }}
                                    </p>
                                </div>

                                <!--
                                    ⚠️ กระเป๋าที่เชื่อมอยู่คนละใบกับที่ผูกไว้กับบัญชี
                                    ต้องเตือนก่อนเขาเทรดด้วยกระเป๋าที่ไม่ได้ผูกกับบัญชีนี้โดยไม่รู้ตัว
                                -->
                                <div v-if="walletMismatch" class="mx-2 my-1.5 px-2.5 py-1.5 rounded-lg bg-amber-500/10 border border-amber-500/25">
                                    <p class="text-[11px] text-amber-300 leading-snug">
                                        กระเป๋าที่เชื่อมอยู่ไม่ใช่ใบที่ผูกกับบัญชีนี้
                                    </p>
                                </div>

                                <Link
                                    href="/profile"
                                    class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5 transition-colors"
                                    @click="showUserMenu = false"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    Profile
                                </Link>

                                <!--
                                    ยืนยันตัวตน — โผล่เฉพาะตอนที่มีด่านเปิดอยู่จริง

                                    ระบบปิดอยู่แล้วยังโชว์เมนูนี้ = ชวนให้คนส่งบัตรประชาชนมา
                                    ทั้งที่เราไม่ได้ต้องใช้ ซึ่งผิดหลัก "เก็บเท่าที่จำเป็น" ของ PDPA
                                -->
                                <Link
                                    v-if="kycVisible"
                                    href="/kyc"
                                    class="flex items-center justify-between gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5 transition-colors"
                                    @click="showUserMenu = false"
                                >
                                    <span class="flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                        </svg>
                                        ยืนยันตัวตน
                                    </span>
                                    <span :class="kycBadgeClass" class="text-[10px] px-1.5 py-0.5 rounded-full">
                                        {{ kycBadgeLabel }}
                                    </span>
                                </Link>

                                <!-- ยกมาจากเมนูกระเป๋า — มีเฉพาะตอนที่กระเป๋าคือตัวตนเดียวกับบัญชี -->
                                <a
                                    v-if="sameIdentity"
                                    :href="walletStore.explorerAddressUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5 transition-colors"
                                    @click="showUserMenu = false"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                    </svg>
                                    {{ t('nav.viewOnExplorer') }}
                                </a>

                                <div v-if="sameIdentity" class="border-t border-white/5 my-1"></div>

                                <!--
                                    ผู้ใช้ที่เข้ามาด้วยกระเป๋าล้วน (ไม่มีรหัสผ่าน) มีทางออกทางเดียว
                                    คือตัดการเชื่อมต่อ — เซิร์ฟเวอร์ปิด session ให้เองอยู่แล้ว
                                    (ดู WalletController::disconnect) จึงไม่ต้องมีปุ่มออกสองอันให้งง
                                -->
                                <button
                                    v-if="sameIdentity && !authUser.has_password"
                                    @click="handleDisconnect"
                                    class="w-full flex items-center gap-2 px-4 py-2 text-sm text-trading-red hover:bg-trading-red/10 transition-colors"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                    {{ t('nav.disconnect') }}
                                </button>

                                <button
                                    v-else
                                    @click="handleLogout"
                                    class="w-full flex items-center gap-2 px-4 py-2 text-sm text-trading-red hover:bg-trading-red/10 transition-colors"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                    Sign Out
                                </button>
                            </div>
                        </Transition>
                    </div>

                    <!--
                        เชื่อมกระเป๋าแล้ว = ใช้เลขกระเป๋าเป็นไอดี ไม่ต้องโชว์ Sign In อีก
                        ถ้าอยากผูกบัญชีอีเมลเพิ่ม เข้าไปกดในโปรไฟล์ได้ (ลิงก์อยู่ในเมนูกระเป๋า)

                        ⚠️ ห้ามซ่อนปุ่มนี้โดยไม่มีทางเข้าโปรไฟล์ให้ผู้ใช้กระเป๋า
                           ไม่งั้นคนที่ใช้กระเป๋าอย่างเดียวจะเข้าโปรไฟล์ไม่ได้เลย
                    -->
                    <Link
                        v-else-if="!isWalletConnected"
                        href="/login"
                        class="text-sm text-dark-300 hover:text-white px-3 py-1.5 rounded-lg hover:bg-white/5 transition-all"
                    >
                        {{ t('nav.signIn') }}
                    </Link>

                    <!-- Connect Wallet Button -->
                    <button
                        v-if="!isWalletConnected"
                        @click="$emit('connect-wallet')"
                        class="btn-primary"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        <span class="hidden sm:inline">{{ t('wallet.connect') }}</span>
                    </button>

                    <!--
                        เมนูกระเป๋าแยก — โชว์เฉพาะตอนที่ยังไม่ได้รวมเข้าเมนูบัญชี
                        คือยังไม่ได้ล็อกอิน หรือกระเป๋าที่เชื่อมคนละใบกับที่ผูกไว้
                    -->
                    <div v-if="showSeparateWalletMenu" class="flex items-center gap-3 relative">
                        <button
                            @click="showWalletMenu = !showWalletMenu"
                            class="wallet-badge cursor-pointer hover:bg-white/10 transition-all"
                        >
                            <div class="w-2 h-2 rounded-full bg-trading-green animate-pulse"></div>
                            <span class="wallet-address">{{ shortAddress }}</span>
                            <svg class="w-3 h-3 text-dark-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <!-- Wallet Dropdown Menu -->
                        <Transition
                            enter-active-class="transition ease-out duration-100"
                            enter-from-class="opacity-0 scale-95"
                            enter-to-class="opacity-100 scale-100"
                            leave-active-class="transition ease-in duration-75"
                            leave-from-class="opacity-100 scale-100"
                            leave-to-class="opacity-0 scale-95"
                        >
                            <div
                                v-if="showWalletMenu"
                                class="absolute right-0 top-full mt-2 w-56 rounded-xl bg-dark-900/90 backdrop-blur-2xl border border-white/10 shadow-2xl py-2 z-50"
                                @click.stop
                            >
                                <div class="px-4 py-2 border-b border-white/5">
                                    <p class="text-xs text-dark-400">Connected Wallet</p>
                                    <p class="text-sm text-white font-mono truncate">{{ walletStore.address }}</p>
                                    <p class="text-xs text-dark-500 mt-1">Chain ID: {{ walletStore.chainId }}</p>
                                </div>
                                <!-- ลิงก์ดูที่อยู่บน block explorer (รองรับหลาย chain) -->
                                <a
                                    :href="walletStore.explorerAddressUrl"
                                    target="_blank"
                                    rel="noopener"
                                    class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5 transition-colors"
                                    @click="showWalletMenu = false"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                    </svg>
                                    {{ t('nav.viewOnExplorer') }}
                                </a>
                                <!-- ทางเข้าโปรไฟล์ของผู้ใช้ที่ใช้กระเป๋าเป็นไอดี
                                     (เดิมโปรไฟล์เข้าได้ทางเดียวคือเมนูของผู้ที่ล็อกอินอีเมล) -->
                                <Link
                                    :href="authUser ? '/profile' : '/wallet'"
                                    class="flex items-center gap-2 px-4 py-2 text-sm text-dark-300 hover:text-white hover:bg-white/5 transition-colors"
                                    @click="showWalletMenu = false"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    {{ t('nav.profile') }}
                                </Link>

                                <!-- ยังไม่ได้ผูกบัญชีอีเมล — เสนอทางเลือกไว้ ไม่บังคับ -->
                                <Link
                                    v-if="!authUser"
                                    href="/login"
                                    class="flex items-center gap-2 px-4 py-2 text-sm text-primary-300 hover:text-primary-200 hover:bg-white/5 transition-colors"
                                    @click="showWalletMenu = false"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 010 5.656l-3 3a4 4 0 11-5.656-5.656l1.5-1.5M10.172 13.828a4 4 0 010-5.656l3-3a4 4 0 115.656 5.656l-1.5 1.5"/>
                                    </svg>
                                    {{ t('nav.linkAccount') }}
                                </Link>

                                <button
                                    @click="handleDisconnect"
                                    class="w-full flex items-center gap-2 px-4 py-2 text-sm text-trading-red hover:bg-trading-red/10 transition-colors"
                                >
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                    {{ t('nav.disconnect') }}
                                </button>
                            </div>
                        </Transition>
                    </div>
                </div>
            </div>
        </div>
    </nav>
</template>
