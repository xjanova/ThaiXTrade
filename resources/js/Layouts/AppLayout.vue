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

defineProps({
    title: String,
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
    <div class="min-h-screen bg-dark-950">
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
        <div class="flex relative">
            <!-- Sidebar -->
            <Sidebar
                v-if="showSidebar"
                class="hidden lg:block"
            />

            <!-- Main Content -->
            <main class="flex-1 min-h-[calc(100vh-120px)] p-4 lg:p-6">
                <slot />
            </main>
        </div>

        <!-- Wallet Connection Modal -->
        <WalletModal
            v-if="showWalletModal"
            @close="closeWalletModal"
        />

        <!-- Footer -->
        <footer class="relative border-t border-white/5 py-4 px-6">
            <div class="max-w-[1920px] mx-auto flex items-center justify-between text-xs text-dark-500">
                <span>&copy; {{ new Date().getFullYear() }} Xman Studio. All rights reserved.</span>
                <span>TPIX TRADE v{{ versionData.version }}</span>
            </div>
        </footer>

        <!-- Google Translate Widget (Free, No API) -->
        <div id="google_translate_element" class="fixed bottom-4 right-4 z-50"></div>
    </div>
</template>

<style scoped>
/* Layout specific styles */
</style>
