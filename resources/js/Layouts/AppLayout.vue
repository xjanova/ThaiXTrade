<script setup>
/**
 * TPIX TRADE - Main App Layout
 * Developed by Xman Studio
 */

import { ref, computed, onMounted } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useWalletStore } from '@/Stores/walletStore';
import NavBar from '@/Components/Navigation/NavBar.vue';
import Sidebar from '@/Components/Navigation/Sidebar.vue';
import TickerStrip from '@/Components/Trading/TickerStrip.vue';
import WalletModal from '@/Components/Wallet/WalletModal.vue';
import versionData from '../../../version.json';

const props = defineProps({
    title: String,
    hideSidebar: Boolean,
});

const page = usePage();
const walletStore = useWalletStore();
const showSidebar = ref(true);
const showWalletModal = ref(false);

const user = computed(() => page.props.auth?.user);

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
    showSidebar.value = !showSidebar.value;
};

const openWalletModal = () => {
    showWalletModal.value = true;
};

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
        <!-- Background Gradient Effects (brand colors: purple, cyan, orange) -->
        <div class="fixed inset-0 pointer-events-none">
            <div class="absolute top-0 left-1/4 w-96 h-96 bg-accent-500/8 rounded-full blur-3xl"></div>
            <div class="absolute top-1/3 right-1/3 w-80 h-80 bg-primary-500/10 rounded-full blur-3xl"></div>
            <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-warm-500/5 rounded-full blur-3xl"></div>
        </div>

        <!-- Ticker Strip -->
        <TickerStrip />

        <!-- Navigation Bar -->
        <NavBar
            :user="user"
            :wallet="wallet"
            @toggle-sidebar="toggleSidebar"
            @connect-wallet="openWalletModal"
        />

        <!-- Main Layout -->
        <div class="flex relative flex-1">
            <!-- Sidebar -->
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
                            <li><Link href="/whitepaper" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Whitepaper</Link></li>
                            <li><Link href="/explorer" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Explorer</Link></li>
                            <li><Link href="/staking" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Staking</Link></li>
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
                    <!-- About -->
                    <div>
                        <h4 class="text-sm font-semibold text-white mb-3">About</h4>
                        <ul class="space-y-2">
                            <li><a href="https://xmanstudio.com" target="_blank" rel="noopener" class="text-xs text-dark-400 hover:text-primary-400 transition-colors">Xman Studio</a></li>
                            <li><span class="text-xs text-dark-500">TPIX Chain ID: 4289</span></li>
                        </ul>
                    </div>
                </div>
                <!-- ส่วนล่าง -->
                <div class="border-t border-white/5 pt-6 flex flex-col sm:flex-row items-center justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <img src="/logo.png" alt="TPIX" class="w-6 h-6 rounded-lg object-cover" />
                        <span class="text-xs text-dark-400">&copy; {{ new Date().getFullYear() }} Xman Studio. All rights reserved.</span>
                    </div>
                    <span class="text-xs text-dark-500">TPIX TRADE v{{ versionData.version }}</span>
                </div>
            </div>
        </footer>
    </div>
</template>

<style scoped>
/* Layout specific styles */
</style>
