<script setup>
/**
 * TPIX TRADE - Main App Layout
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useWalletStore } from '@/Stores/walletStore';
import NavBar from '@/Components/Navigation/NavBar.vue';
import Sidebar from '@/Components/Navigation/Sidebar.vue';
import TickerStrip from '@/Components/Trading/TickerStrip.vue';
import WalletModal from '@/Components/Wallet/WalletModal.vue';
import BannerAd from '@/Components/BannerAd.vue';
import AIChatbot from '@/Components/AIChatbot.vue';
import versionData from '../../../version.json';

const props = defineProps({
    title: String,
    hideSidebar: Boolean,
});

const page = usePage();
const walletStore = useWalletStore();
const showSidebar = ref(true);
const showMobileMenu = ref(false);
const showWalletModal = ref(false);

const user = computed(() => page.props.auth?.user);
const social = computed(() => page.props.social || {});

// Wallet state from Pinia store (reactive)
const wallet = computed(() => {
    if (walletStore.isConnected) {
        return {
            address: walletStore.address,
            chainId: walletStore.chainId,
            type: walletStore.walletType,
        };
    }
    return null;
});

const toggleSidebar = () => {
    // Desktop: toggle sidebar, Mobile: toggle mobile menu
    if (window.innerWidth >= 1024) {
        showSidebar.value = !showSidebar.value;
    } else {
        showMobileMenu.value = !showMobileMenu.value;
    }
};

const openWalletModal = () => {
    showWalletModal.value = true;
};

// watch store เพื่อให้ child pages เรียกเปิด modal ผ่าน walletStore.openConnectModal()
watch(() => walletStore.showConnectModal, (val) => {
    if (val) {
        showWalletModal.value = true;
        walletStore.showConnectModal = false;
    }
});

const closeWalletModal = () => {
    showWalletModal.value = false;
};

// Auto-reconnect wallet on page load
onMounted(async () => {
    await walletStore.tryReconnect();
});
</script>

<template>
    <div class="flex-1 flex flex-col bg-dark-950">
        <!-- Subtle bg1 background image (very faint) -->
        <div class="fixed inset-0 pointer-events-none">
            <div class="absolute inset-0 bg-cover bg-center bg-no-repeat opacity-[0.04]"
                style="background-image: url('/images/bg1.webp')" />
        </div>
        <!-- Background Gradient Effects (brand colors: purple, cyan, orange) -->
        <div class="fixed inset-0 pointer-events-none">
            <div class="absolute top-0 left-1/4 w-96 h-96 bg-accent-500/8 rounded-full blur-3xl"></div>
            <div class="absolute top-1/3 right-1/3 w-80 h-80 bg-primary-500/10 rounded-full blur-3xl"></div>
            <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-warm-500/5 rounded-full blur-3xl"></div>
        </div>

        <!-- Ticker Strip -->
        <TickerStrip />

        <!-- ป้ายโฆษณาด้านบนทุกหน้า (จัดการจาก Admin) -->
        <BannerAd placement="all_pages_top" class="px-4 py-2" />

        <!-- Navigation Bar -->
        <NavBar
            :user="user"
            :wallet="wallet"
            @toggle-sidebar="toggleSidebar"
            @connect-wallet="openWalletModal"
        />

        <!-- Mobile Menu Overlay -->
        <Teleport to="body">
            <Transition
                enter-active-class="transition ease-out duration-200"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition ease-in duration-150"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-if="showMobileMenu" class="fixed inset-0 z-50 lg:hidden">
                    <div class="absolute inset-0 bg-dark-950/80 backdrop-blur-sm" @click="showMobileMenu = false"></div>
                    <Transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="-translate-x-full"
                        enter-to-class="translate-x-0"
                        leave-active-class="transition ease-in duration-150"
                        leave-from-class="translate-x-0"
                        leave-to-class="-translate-x-full"
                    >
                        <div v-if="showMobileMenu" class="relative w-72 h-full overflow-y-auto" @click.stop>
                            <Sidebar />
                        </div>
                    </Transition>
                </div>
            </Transition>
        </Teleport>

        <!-- Main Layout -->
        <div class="flex relative flex-1">
            <!-- Sidebar (desktop only) -->
            <Sidebar
                v-if="showSidebar && !props.hideSidebar"
                class="hidden lg:block"
            />

            <!-- Main Content -->
            <main class="flex-1 p-4 lg:p-6">
                <slot />
            </main>
        </div>

        <!-- Wallet Connection Modal -->
        <WalletModal
            v-if="showWalletModal"
            @close="closeWalletModal"
            @connected="closeWalletModal"
        />

        <!-- Footer — ลิงก์เข้าถึงหน้าทั้งหมด -->
        <footer class="relative border-t border-white/5 mt-8">
            <div class="max-w-[1920px] mx-auto px-6 py-10">
                <!-- ส่วนลิงก์หลัก -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-8 mb-8">
                    <!-- Trading -->
                    <div>
                        <h4 class="text-sm font-semibold text-white mb-3">Trading</h4>
                        <ul class="space-y-2">
                            <li><Link href="/trade" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Spot Trading</Link></li>
                            <li><Link href="/swap" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Swap</Link></li>
                            <li><Link href="/portfolio" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Portfolio</Link></li>
                            <li><Link href="/markets/spot" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Markets</Link></li>
                        </ul>
                    </div>
                    <!-- TPIX Ecosystem -->
                    <div>
                        <h4 class="text-sm font-semibold text-white mb-3">TPIX Ecosystem</h4>
                        <ul class="space-y-2">
                            <li><Link href="/token-sale" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Token Sale</Link></li>
                            <li><Link href="/token-factory" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Token Factory</Link></li>
                            <li><Link href="/carbon-credits" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Carbon Credits</Link></li>
                            <li><Link href="/whitepaper" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Whitepaper</Link></li>
                            <li><Link href="/explorer" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Explorer</Link></li>
                            <li><Link href="/masternode" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Master Node</Link></li>
                        </ul>
                    </div>
                    <!-- Resources -->
                    <div>
                        <h4 class="text-sm font-semibold text-white mb-3">Resources</h4>
                        <ul class="space-y-2">
                            <li><Link href="/bridge" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Bridge</Link></li>
                            <li><Link href="/ai-assistant" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">AI Assistant</Link></li>
                            <li><Link href="/whitepaper/download" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Download Whitepaper</Link></li>
                            <li><Link href="/settings" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Settings</Link></li>
                        </ul>
                    </div>
                    <!-- About & Social -->
                    <div>
                        <h4 class="text-sm font-semibold text-white mb-3">About</h4>
                        <ul class="space-y-2">
                            <li><a href="https://xmanstudio.com" target="_blank" rel="noopener" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Xman Studio</a></li>
                            <li><span class="text-xs text-dark-500">TPIX Chain ID: 4289</span></li>
                        </ul>
                        <div v-if="social.twitter || social.telegram || social.discord || social.github" class="flex items-center gap-3 mt-4">
                            <a v-if="social.twitter" :href="social.twitter" target="_blank" rel="noopener" class="text-dark-500 hover:text-primary-400 transition-colors" title="Twitter/X">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                            </a>
                            <a v-if="social.telegram" :href="social.telegram" target="_blank" rel="noopener" class="text-dark-500 hover:text-primary-400 transition-colors" title="Telegram">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>
                            </a>
                            <a v-if="social.discord" :href="social.discord" target="_blank" rel="noopener" class="text-dark-500 hover:text-primary-400 transition-colors" title="Discord">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286z"/></svg>
                            </a>
                            <a v-if="social.github" :href="social.github" target="_blank" rel="noopener" class="text-dark-500 hover:text-primary-400 transition-colors" title="GitHub">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>
                            </a>
                        </div>
                    </div>
                </div>
                <!-- ส่วนล่าง -->
                <div class="border-t border-white/5 pt-6 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <img src="/logo.webp" alt="TPIX" class="w-6 h-6 object-contain" />
                        <span class="text-xs text-dark-400">&copy; {{ new Date().getFullYear() }} Xman Studio. All rights reserved.</span>
                    </div>
                    <span class="text-xs text-dark-500">TPIX TRADE v{{ versionData.version }}</span>
                </div>
            </div>
        </footer>

        <!-- AI Chatbot — ลอยทุกหน้า -->
        <AIChatbot />
    </div>
</template>

<style scoped>
/* Layout specific styles */
</style>
