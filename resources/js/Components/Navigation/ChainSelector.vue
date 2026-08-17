<script setup>
/**
 * TPIX TRADE - Chain Selector Component
 * ตัวเลือก chain อัจฉริยะ - ตรวจจับ chain อัตโนมัติ พร้อม dropdown
 * ดึงรายการ chain ที่รองรับจาก backend API
 * สลับไปยัง chain หลัก (BSC) อัตโนมัติเมื่อพบ chain ที่ไม่รองรับ
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, onUnmounted } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import axios from 'axios';

const walletStore = useWalletStore();

const showDropdown = ref(false);
const supportedChains = ref([]);
const isLoadingChains = ref(false);

// Default chain ID from backend (BSC = 56)
const DEFAULT_CHAIN_ID = 56;

// Chain brand colors (fallback circle color)
const chainColors = {
    1: '#627EEA',     // Ethereum
    56: '#F3BA2F',    // BSC
    137: '#8247E5',   // Polygon
    42161: '#28A0F0', // Arbitrum
    10: '#FF0420',    // Optimism
    43114: '#E84142', // Avalanche
    250: '#1969FF',   // Fantom
    8453: '#0052FF',  // Base
    324: '#8C8DFC',   // zkSync
    4289: '#00BCD4',  // TPIX Chain
};

// Real chain logo URLs (from TrustWallet assets CDN + official sources)
const chainLogos = {
    1: 'https://assets.trustwalletapp.com/blockchains/ethereum/info/logo.png',
    56: 'https://assets.trustwalletapp.com/blockchains/smartchain/info/logo.png',
    137: 'https://assets.trustwalletapp.com/blockchains/polygon/info/logo.png',
    42161: 'https://assets.trustwalletapp.com/blockchains/arbitrum/info/logo.png',
    10: 'https://assets.trustwalletapp.com/blockchains/optimism/info/logo.png',
    43114: 'https://assets.trustwalletapp.com/blockchains/avalanchec/info/logo.png',
    250: 'https://assets.trustwalletapp.com/blockchains/fantom/info/logo.png',
    8453: 'https://assets.trustwalletapp.com/blockchains/base/info/logo.png',
    324: 'https://assets.trustwalletapp.com/blockchains/zksync/info/logo.png',
    4289: '/tpixlogo.webp', // TPIX Chain uses our own logo
};

// Get chain logo URL — from backend data, our map, or fallback
function getChainLogo(chain) {
    if (chain?.icon) return chain.icon;
    if (chain?.logo) return chain.logo;
    return chainLogos[chain?.chainId] || null;
}

// Track broken images to show fallback
const brokenLogos = ref({});

const isConnected = computed(() => walletStore.isConnected);
const currentChainId = computed(() => walletStore.chainId);

const currentChain = computed(() => {
    if (!currentChainId.value) return null;
    return supportedChains.value.find(c => c.chainId === currentChainId.value);
});

const isOnSupportedChain = computed(() => !!currentChain.value);
const isOnDefaultChain = computed(() => currentChainId.value === DEFAULT_CHAIN_ID);

const currentChainName = computed(() => {
    if (!isConnected.value) return 'Select Network';
    if (currentChain.value) return currentChain.value.shortName;
    return 'Unsupported';
});

const currentChainColor = computed(() => {
    if (!currentChainId.value) return '#6b7280';
    return chainColors[currentChainId.value] || '#6b7280';
});

const statusClass = computed(() => {
    if (!isConnected.value) return 'bg-dark-500';
    if (isOnSupportedChain.value) return 'bg-trading-green animate-pulse';
    return 'bg-yellow-500 animate-pulse';
});

// TPIX Chain (4289) เป็นเชนหลักของเรา — ปักหมุดไว้บนสุดเสมอ
const TPIX_CHAIN_ID = 4289;

// Only show production-enabled chains, with TPIX Chain pinned to the top.
const availableChains = computed(() => {
    const chains = supportedChains.value.filter(c => c.enabled !== false);
    // Array.sort is stable → all non-TPIX chains keep their backend order.
    return [...chains].sort((a, b) => {
        if (a.chainId === TPIX_CHAIN_ID) return -1;
        if (b.chainId === TPIX_CHAIN_ID) return 1;
        return 0;
    });
});

// เชนที่ยังไม่พร้อมใช้งาน (status = coming_soon) — โชว์ในลิสต์แต่กดเลือกไม่ได้
// ไม่มี status (API เก่า/fallback) ถือว่า live เพื่อไม่ให้ selector ใช้งานไม่ได้
function isComingSoon(chain) {
    return !!chain.status && chain.status !== 'live';
}

async function fetchChains() {
    isLoadingChains.value = true;
    try {
        const { data } = await axios.get('/api/v1/chains');
        if (data.success) {
            supportedChains.value = data.data;
        }
    } catch {
        // Fallback: at least show BSC
        supportedChains.value = [{
            chainId: 56,
            name: 'BNB Smart Chain',
            shortName: 'BSC',
            nativeCurrency: { symbol: 'BNB' },
            color: '#F3BA2F',
            enabled: true,
        }];
    } finally {
        isLoadingChains.value = false;
    }
}

/*
 * ผลลัพธ์ของการสลับเชนต้องเห็นบนหน้าจอ ไม่ใช่ใน console
 *
 * เดิมปิด dropdown ทิ้งทันทีแล้ว catch ไปลง console.warn — ผู้ใช้กด reject
 * ในกระเป๋าแล้วจะไม่เห็นอะไรเลย ป้ายยังเป็นเชนเดิม ไม่รู้ว่ากดพลาดหรือระบบพัง
 */
const switchError = ref(null);
const switchingTo = ref(null);

async function selectChain(chain) {
    // เชนที่ยังไม่เปิด — กันไว้อีกชั้นเผื่อ disabled attribute ไม่ทำงาน (เช่น keyboard)
    if (isComingSoon(chain)) return;

    // ยังไม่เชื่อมกระเป๋า → พาไปเชื่อมเลย ดีกว่ากดแล้วเงียบ
    if (!isConnected.value) {
        showDropdown.value = false;
        walletStore.openConnectModal?.();
        return;
    }

    if (chain.chainId === currentChainId.value) {
        showDropdown.value = false;
        return;
    }

    switchError.value = null;
    switchingTo.value = chain.chainId;

    try {
        await walletStore.switchChain(chain.chainId);
        showDropdown.value = false;   // ปิดเฉพาะตอนสำเร็จจริง
    } catch (err) {
        // ข้อความที่ store เตรียมไว้อ่านรู้เรื่องกว่า err.message ดิบๆ
        switchError.value = walletStore.error || err?.message || 'สลับเครือข่ายไม่สำเร็จ';
    } finally {
        switchingTo.value = null;
    }
}

async function switchToDefault() {
    if (!isConnected.value) return;

    switchError.value = null;
    switchingTo.value = DEFAULT_CHAIN_ID;

    try {
        await walletStore.switchChain(DEFAULT_CHAIN_ID);
    } catch (err) {
        switchError.value = walletStore.error || err?.message || 'สลับเครือข่ายไม่สำเร็จ';
    } finally {
        switchingTo.value = null;
    }
}

// Close dropdown on outside click
function handleClickOutside(event) {
    if (!event.target.closest('.chain-selector')) {
        showDropdown.value = false;
    }
}

onMounted(() => {
    fetchChains();
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
    <div class="chain-selector relative">
        <!-- Chain Button -->
        <button
            @click="showDropdown = !showDropdown"
            class="flex items-center gap-2 px-3 py-2 rounded-xl glass-sm hover:bg-white/10 transition-all"
            :class="{
                'border border-yellow-500/30': isConnected && !isOnSupportedChain,
                'border border-transparent': !isConnected || isOnSupportedChain,
            }"
        >
            <!-- Chain icon -->
            <img v-if="currentChain && getChainLogo(currentChain) && !brokenLogos[currentChainId]"
                :src="getChainLogo(currentChain)"
                :alt="currentChain?.shortName"
                class="w-5 h-5 rounded-full object-contain"
                @error="brokenLogos[currentChainId] = true"
            />
            <div v-else
                class="w-5 h-5 rounded-full flex items-center justify-center text-xs font-bold text-white"
                :style="{ backgroundColor: currentChainColor }"
            >
                {{ currentChain?.shortName?.charAt(0) || '?' }}
            </div>

            <!-- Status dot -->
            <div class="w-2 h-2 rounded-full" :class="statusClass"></div>

            <!-- Chain name -->
            <span
                class="text-sm font-medium hidden sm:inline"
                :class="isConnected && !isOnSupportedChain ? 'text-yellow-400' : 'text-white'"
            >
                {{ currentChainName }}
            </span>

            <!-- Dropdown arrow -->
            <svg class="w-3 h-3 text-dark-400 transition-transform" :class="{ 'rotate-180': showDropdown }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <!-- Dropdown -->
        <Transition
            enter-active-class="transition ease-out duration-100"
            enter-from-class="opacity-0 scale-95"
            enter-to-class="opacity-100 scale-100"
            leave-active-class="transition ease-in duration-75"
            leave-from-class="opacity-100 scale-100"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="showDropdown"
                class="absolute right-0 top-full mt-2 w-64 rounded-xl bg-dark-900/90 backdrop-blur-2xl border border-white/10 shadow-2xl py-2 z-50"
            >
                <!-- Header -->
                <div class="px-4 py-2 border-b border-white/5">
                    <p class="text-xs text-dark-400 font-medium">Select Network</p>
                </div>

                <!-- กำลังรอกระเป๋าตอบ — ไม่งั้นผู้ใช้ไม่รู้ว่ากดติดหรือยัง -->
                <div v-if="switchingTo !== null" class="mx-3 mt-2 mb-1 px-3 py-2 rounded-lg bg-primary-500/10 border border-primary-500/20 flex items-center gap-2">
                    <span class="w-3 h-3 rounded-full border-2 border-primary-400 border-t-transparent animate-spin shrink-0"></span>
                    <p class="text-[11px] text-primary-300">กำลังสลับเครือข่าย — ยืนยันในกระเป๋าของคุณ</p>
                </div>

                <!-- ผลลัพธ์ของการสลับที่ล้มเหลว — ต้องเห็นตรงนี้ ไม่ใช่ใน console -->
                <div v-if="switchError" class="mx-3 mt-2 mb-1 px-3 py-2 rounded-lg bg-trading-red/10 border border-trading-red/25">
                    <div class="flex items-start gap-2">
                        <svg class="w-3.5 h-3.5 text-trading-red shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" />
                        </svg>
                        <p class="text-[11px] text-trading-red leading-snug">{{ switchError }}</p>
                    </div>
                </div>

                <!-- Unsupported Chain Warning -->
                <div v-if="isConnected && !isOnSupportedChain" class="mx-3 mt-2 mb-1 px-3 py-2 rounded-lg bg-yellow-500/10 border border-yellow-500/20">
                    <p class="text-xs text-yellow-400 font-medium">Unsupported Network</p>
                    <p class="text-xs text-yellow-400/70 mt-0.5">Switch to a supported chain below.</p>
                </div>

                <!-- Auto-switch suggestion -->
                <div v-if="isConnected && !isOnDefaultChain && isOnSupportedChain" class="mx-3 mt-2 mb-1">
                    <button
                        @click="switchToDefault"
                        class="w-full flex items-center gap-2 px-3 py-2 rounded-lg bg-primary-500/10 hover:bg-primary-500/20 border border-primary-500/20 transition-colors"
                    >
                        <svg class="w-4 h-4 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                        <span class="text-xs text-primary-400 font-medium">Switch to BSC (Recommended)</span>
                    </button>
                </div>

                <!-- Chain List -->
                <div class="max-h-64 overflow-y-auto py-1">
                    <button
                        v-for="chain in availableChains"
                        :key="chain.chainId"
                        @click="selectChain(chain)"
                        :disabled="isComingSoon(chain) || switchingTo !== null"
                        class="w-full flex items-center gap-3 px-4 py-2.5 text-sm transition-colors"
                        :class="{
                            'bg-white/5': chain.chainId === currentChainId,
                            'hover:bg-white/5': !isComingSoon(chain),
                            'opacity-50 cursor-not-allowed': isComingSoon(chain),
                        }"
                    >
                        <!-- Chain logo -->
                        <img v-if="getChainLogo(chain) && !brokenLogos[chain.chainId]"
                            :src="getChainLogo(chain)"
                            :alt="chain.shortName"
                            class="w-6 h-6 rounded-full object-contain flex-shrink-0"
                            :class="{ 'grayscale': isComingSoon(chain) }"
                            @error="brokenLogos[chain.chainId] = true"
                        />
                        <div v-else
                            class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                            :style="{ backgroundColor: chain.color || chainColors[chain.chainId] || '#6b7280' }"
                        >
                            {{ chain.shortName?.charAt(0) || '?' }}
                        </div>

                        <!-- Chain info -->
                        <div class="flex-1 text-left">
                            <p class="text-white font-medium text-sm">{{ chain.name }}</p>
                            <p class="text-dark-500 text-xs">{{ isComingSoon(chain) ? 'Coming soon' : chain.nativeCurrency?.symbol }}</p>
                        </div>

                        <!-- Current chain indicator -->
                        <div v-if="chain.chainId === currentChainId" class="w-2 h-2 rounded-full bg-trading-green"></div>

                        <!-- Coming soon badge — เชนยังไม่พร้อม กดไม่ได้ -->
                        <span
                            v-if="isComingSoon(chain)"
                            class="text-[10px] px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-400 font-medium"
                        >
                            Soon
                        </span>

                        <!-- Default badge -->
                        <span
                            v-else-if="chain.chainId === 56"
                            class="text-[10px] px-1.5 py-0.5 rounded bg-primary-500/20 text-primary-400 font-medium"
                        >
                            Main
                        </span>
                    </button>
                </div>

                <!-- Not connected hint -->
                <div v-if="!isConnected" class="px-4 py-2 border-t border-white/5">
                    <p class="text-xs text-dark-500">Connect wallet to switch networks</p>
                </div>
            </div>
        </Transition>
    </div>
</template>
