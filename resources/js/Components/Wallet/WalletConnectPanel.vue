<script setup>
/**
 * TPIX TRADE — เชื่อมกระเป๋าผ่าน WalletConnect (อยู่ในหน้าต่างเชื่อมกระเป๋า)
 *
 *   คอมพิวเตอร์: QR ให้แอป TPIX Wallet (หรือกระเป๋า WalletConnect ตัวไหนก็ได้) สแกน
 *   มือถือ:      ปุ่มเปิดแอปกระเป๋า → กดอนุมัติในแอป → กลับมาที่เบราว์เซอร์
 *
 * ขั้นตอนอ่านจาก walletStore.wcStatus — ตัวนี้แค่วาด ไม่ได้คุมการเชื่อมต่อเอง
 *   pairing → signing (รอยืนยันเข้าสู่ระบบในแอป) → done · verify_failed · error
 *
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import QRCode from 'qrcode';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';
import { isMobile } from '@/utils/mobileWallet';
import {
    WC_MOBILE_WALLETS,
    APP_OPEN_GRACE_MS,
    buildWalletAppLink,
    rememberDeepLinkChoice,
} from '@/utils/walletConnect';
import { formatAddress } from '@/utils/web3';
import { TPIX_WALLET_APP_ICON } from '@/utils/brand';

const props = defineProps({
    /** แอปที่ผู้ใช้เลือก: tpix | metamask | trustwallet | okx | tokenpocket | generic */
    wallet: { type: String, default: 'generic' },
});

const emit = defineEmits(['retry', 'back', 'done', 'close']);

const walletStore = useWalletStore();
const { t } = useTranslation();
const mobile = isMobile();

/*
 * เบราว์เซอร์ยอมให้เปิดแอปอื่นเฉพาะช่วงสั้น ๆ หลังผู้ใช้แตะ (Chrome ~5 วิ)
 * ลิงก์จับคู่มาถึงภายในช่วงนั้น = เปิดแอปให้เลย · มาช้ากว่านั้น = ให้ผู้ใช้แตะปุ่มเอง
 */
const AUTO_OPEN_WINDOW_MS = 4500;
const startedAt = Date.now();

const qrDataUrl = ref(null);
const copied = ref(false);
const showQr = ref(!mobile);
const appMaybeMissing = ref(false);
let appCheckTimer = null;
let copiedTimer = null;
let doneTimer = null;

const status = computed(() => walletStore.wcStatus);
const uri = computed(() => walletStore.wcUri);
/** แอปที่ใช้อยู่ — ผู้ใช้อาจเลือกจากรายการบนมือถือหลังเปิดแบบ "กระเป๋าอื่น ๆ" */
const activeWallet = computed(() => walletStore.wcWallet || props.wallet);
const walletInfo = computed(() => WC_MOBILE_WALLETS[activeWallet.value] || null);
const walletName = computed(() => walletInfo.value?.name || t('wallet.wc.yourWallet'));
const titleName = computed(() => walletInfo.value?.name || t('wallet.wc.genericName'));
const isTpix = computed(() => activeWallet.value === 'tpix');

/** มือถือ + ยังไม่ได้เลือกแอป → ให้เลือกจากรายการ (ลิงก์ของแต่ละแอปต่างกัน) */
const mobilePicker = computed(() => mobile && !walletInfo.value);
const pickerWallets = Object.values(WC_MOBILE_WALLETS);

const pairingLink = computed(() => (uri.value && walletInfo.value
    ? buildWalletAppLink(activeWallet.value, { uri: uri.value })
    : null));

/** เปิดแอปเพื่อไปกดยืนยันลายเซ็น (ไม่มีลิงก์จับคู่แล้ว — session ต่อกันอยู่) */
const openAppLink = computed(() => (walletInfo.value
    ? buildWalletAppLink(activeWallet.value, { sessionTopic: walletStore.wcSessionTopic })
    : null));

const shortAddress = computed(() => formatAddress(walletStore.address));

const failMessage = computed(() => {
    if (walletStore.verifyError === 'link' && walletStore.linkError) return walletStore.linkError;
    switch (walletStore.verifyError) {
        case 'rejected': return t('wallet.wc.failRejected');
        case 'timeout': return t('wallet.wc.failTimeout');
        case 'mismatch': return t('wallet.wc.failMismatch');
        default: return t('wallet.wc.failGeneric');
    }
});

function linkFor(walletId) {
    return uri.value ? buildWalletAppLink(walletId, { uri: uri.value }) : null;
}

/** เปิดแอป แล้วคอยดูว่าหน้านี้ถูกพักไหม — ยังอยู่หน้านี้นานเกินไป = แอปน่าจะยังไม่ได้ติดตั้ง */
function openApp(link) {
    if (!link) return;
    appMaybeMissing.value = false;
    clearTimeout(appCheckTimer);
    window.location.href = link;
    appCheckTimer = setTimeout(() => {
        if (document.visibilityState === 'visible') appMaybeMissing.value = true;
    }, APP_OPEN_GRACE_MS);
}

/** ผู้ใช้เลือกแอปจากรายการบนมือถือ — จำไว้ให้คำขอเซ็นถัดไปเปิดแอปเดียวกัน */
function pickWallet(walletId) {
    walletStore.wcWallet = walletId;
    rememberDeepLinkChoice(walletId);
    openApp(linkFor(walletId));
}

async function copyLink() {
    if (!uri.value) return;
    try {
        await navigator.clipboard.writeText(uri.value);
        copied.value = true;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        // เบราว์เซอร์ไม่ให้เขียนคลิปบอร์ด (หน้าไม่ได้ focus / ไม่ใช่ https) — ปล่อยผ่าน ผู้ใช้ยังสแกน QR ได้
    }
}

async function renderQr(value) {
    if (!value) {
        qrDataUrl.value = null;
        return;
    }
    try {
        // พื้นขาวตัวดำ ขอบ 1 ช่อง — กล้องมือถืออ่านจากจอมืดได้ไวที่สุด
        qrDataUrl.value = await QRCode.toDataURL(value, {
            margin: 1,
            width: 288,
            errorCorrectionLevel: 'M',
            color: { dark: '#000000', light: '#ffffff' },
        });
    } catch {
        qrDataUrl.value = null;
    }
}

watch(uri, (value) => {
    renderQr(value);
    // มือถือ + เลือกแอปไว้แล้ว + ยังอยู่ในช่วงที่ผู้ใช้เพิ่งแตะ → เปิดแอปให้เลย
    if (value && mobile && pairingLink.value && Date.now() - startedAt < AUTO_OPEN_WINDOW_MS) {
        openApp(pairingLink.value);
    }
}, { immediate: true });

watch(status, (value) => {
    if (value !== 'pairing') appMaybeMissing.value = false;
    if (value === 'done') {
        clearTimeout(doneTimer);
        doneTimer = setTimeout(() => emit('done'), 900);
    }
}, { immediate: true });

onMounted(() => {
    if (status.value === 'done') emit('done');
});

onBeforeUnmount(() => {
    clearTimeout(appCheckTimer);
    clearTimeout(copiedTimer);
    clearTimeout(doneTimer);
});

function retryVerification() {
    walletStore.verifyOwnership();
}
</script>

<template>
    <div class="space-y-4" data-testid="wc-panel">
        <!-- หัว: แอปที่เลือก + ขั้นตอน -->
        <div class="flex items-center gap-3">
            <!-- TPIX Wallet = ไอคอนแอปตัวจริง (ตัวเดียวกับการ์ดอันดับ 1) -->
            <img v-if="isTpix" :src="TPIX_WALLET_APP_ICON" alt="TPIX Wallet" class="w-10 h-10 rounded-xl ring-1 ring-white/15 flex-shrink-0" />
            <div v-else class="w-10 h-10 rounded-xl bg-primary-500/15 flex items-center justify-center flex-shrink-0">
                <svg class="w-6 h-6" viewBox="0 0 40 40" aria-hidden="true">
                    <circle cx="20" cy="20" r="18" fill="#3B99FC" />
                    <path d="M12.5 16.5C16.6 12.5 23.4 12.5 27.5 16.5L28 17C28.2 17.2 28.2 17.5 28 17.7L26.5 19.1C26.4 19.2 26.2 19.2 26.1 19.1L25.4 18.5C22.5 15.7 17.5 15.7 14.6 18.5L13.8 19.2C13.7 19.3 13.5 19.3 13.4 19.2L11.9 17.8C11.7 17.6 11.7 17.3 11.9 17.1L12.5 16.5Z" fill="white" />
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="font-semibold text-white truncate">{{ t('wallet.wc.title', { wallet: titleName }) }}</p>
                <div class="flex items-center gap-2 text-[11px] mt-0.5">
                    <span :class="status === 'pairing' || status === 'error' ? 'text-primary-300 font-semibold' : 'text-trading-green'">
                        1 · {{ t('wallet.wc.stepConnect') }}
                    </span>
                    <span class="text-dark-600">→</span>
                    <span :class="status === 'signing' || status === 'verify_failed' ? 'text-primary-300 font-semibold' : status === 'done' ? 'text-trading-green' : 'text-dark-500'">
                        2 · {{ t('wallet.wc.stepSignIn') }}
                    </span>
                </div>
            </div>
        </div>

        <!-- ① จับคู่ -->
        <template v-if="status === 'pairing'">
            <div v-if="!uri" class="flex items-center justify-center gap-3 py-10 text-dark-400 text-sm" data-testid="wc-preparing">
                <div class="spinner"></div>
                {{ t('wallet.wc.preparing') }}
            </div>

            <template v-else>
                <!-- มือถือ: เลือกแอป / ปุ่มเปิดแอป -->
                <template v-if="mobile">
                    <div v-if="mobilePicker" class="space-y-2" data-testid="wc-picker">
                        <p class="text-dark-400 text-xs">{{ t('wallet.wc.pickWallet') }}</p>
                        <button
                            v-for="w in pickerWallets"
                            :key="w.id"
                            type="button"
                            class="w-full flex items-center gap-3 p-3 rounded-xl border bg-dark-800/50 border-white/5 hover:bg-white/5 transition-all"
                            :data-testid="`wc-pick-${w.id}`"
                            @click="pickWallet(w.id)"
                        >
                            <img v-if="w.id === 'tpix'" :src="TPIX_WALLET_APP_ICON" alt="TPIX Wallet" class="w-6 h-6 rounded-md" />
                            <span v-else class="w-6 h-6 rounded-lg bg-white/10 flex items-center justify-center text-[10px] text-white font-bold">{{ w.name.slice(0, 2) }}</span>
                            <span class="text-sm text-white">{{ w.name }}</span>
                        </button>
                    </div>

                    <div v-else class="space-y-3">
                        <a
                            :href="pairingLink"
                            class="w-full flex items-center justify-center gap-2 py-3.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-semibold text-sm"
                            data-testid="wc-open-app"
                            @click.prevent="openApp(pairingLink)"
                        >
                            {{ t('wallet.wc.openApp', { wallet: walletName }) }}
                        </a>
                        <p class="text-dark-400 text-xs text-center">{{ t('wallet.wc.openAppHint') }}</p>
                    </div>

                    <p v-if="appMaybeMissing" class="text-amber-300 text-xs text-center" data-testid="wc-app-missing">
                        {{ t('wallet.wc.appNotOpened') }}
                    </p>
                    <a v-if="isTpix || mobilePicker" href="/download" class="block text-center text-primary-400 hover:text-primary-300 text-xs">
                        {{ t('wallet.wc.downloadTpix') }}
                    </a>
                    <button v-if="!showQr" type="button" class="w-full text-center text-dark-500 hover:text-dark-300 text-xs" @click="showQr = true">
                        {{ t('wallet.wc.showQr') }}
                    </button>
                </template>

                <!-- QR (คอมพิวเตอร์ / มือถือที่ขอดู) -->
                <div v-if="showQr" class="space-y-3">
                    <div class="mx-auto w-fit p-3 rounded-2xl bg-white shadow-lg shadow-primary-500/10">
                        <img v-if="qrDataUrl" :src="qrDataUrl" alt="WalletConnect QR" class="w-64 h-64 max-w-full" data-testid="wc-qr" />
                        <div v-else class="w-64 h-64 flex items-center justify-center"><div class="spinner"></div></div>
                    </div>
                    <ol v-if="isTpix || !mobile" class="text-xs text-dark-300 space-y-1 list-decimal list-inside">
                        <template v-if="isTpix">
                            <li>{{ t('wallet.wc.scanStep1') }}</li>
                            <li>{{ t('wallet.wc.scanStep2') }}</li>
                            <li>{{ t('wallet.wc.scanStep3') }}</li>
                        </template>
                        <li v-else class="list-none">{{ t('wallet.wc.scanGeneric') }}</li>
                    </ol>
                </div>

                <button type="button" class="w-full py-2 rounded-lg bg-white/5 hover:bg-white/10 text-dark-300 text-xs" data-testid="wc-copy" @click="copyLink">
                    {{ copied ? t('wallet.wc.copied') : t('wallet.wc.copyLink') }}
                </button>
            </template>

            <button type="button" class="w-full py-2 text-dark-400 hover:text-white text-sm" @click="emit('back')">
                {{ t('wallet.wc.back') }}
            </button>
        </template>

        <!-- ② รอยืนยันเข้าสู่ระบบในแอป -->
        <template v-else-if="status === 'signing'">
            <div class="p-3 rounded-xl bg-trading-green/10 border border-trading-green/20 text-trading-green text-sm">
                {{ t('wallet.wc.connectedAs', { address: shortAddress }) }}
            </div>
            <div class="flex items-start gap-3 p-4 rounded-xl bg-dark-800/50 border border-white/5" data-testid="wc-signing">
                <div class="spinner flex-shrink-0 mt-0.5"></div>
                <div class="space-y-1">
                    <p class="text-white text-sm font-medium">{{ t('wallet.wc.signTitle', { wallet: walletName }) }}</p>
                    <p class="text-dark-400 text-xs">{{ mobile ? t('wallet.wc.signMobile') : t('wallet.wc.signDesktop') }}</p>
                    <p class="text-dark-500 text-[11px]">{{ t('wallet.wc.signFree') }}</p>
                </div>
            </div>
            <a
                v-if="mobile && openAppLink"
                :href="openAppLink"
                class="w-full flex items-center justify-center py-3.5 rounded-xl bg-primary-500 hover:bg-primary-600 text-white font-semibold text-sm"
                data-testid="wc-open-app-sign"
                @click.prevent="openApp(openAppLink)"
            >
                {{ t('wallet.wc.openApp', { wallet: walletName }) }}
            </a>
            <button type="button" class="w-full py-2 text-dark-400 hover:text-white text-sm" @click="emit('close')">
                {{ t('wallet.wc.later') }}
            </button>
        </template>

        <!-- เซ็นไม่ผ่าน (ยังเชื่อมกระเป๋าอยู่) -->
        <template v-else-if="status === 'verify_failed'">
            <div class="p-3 rounded-xl bg-trading-red/10 border border-trading-red/30 text-trading-red text-sm" data-testid="wc-verify-failed">
                {{ failMessage }}
            </div>
            <div class="flex gap-3">
                <button type="button" class="flex-1 py-2.5 text-dark-400 hover:text-white text-sm" @click="emit('close')">
                    {{ t('wallet.wc.close') }}
                </button>
                <button type="button" class="flex-1 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium text-sm" data-testid="wc-retry-sign" @click="retryVerification">
                    {{ t('wallet.wc.tryAgain') }}
                </button>
            </div>
        </template>

        <!-- เสร็จ -->
        <div v-else-if="status === 'done'" class="flex flex-col items-center gap-2 py-6" data-testid="wc-done">
            <div class="w-12 h-12 rounded-full bg-trading-green/15 flex items-center justify-center">
                <svg class="w-6 h-6 text-trading-green" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <p class="text-white font-semibold">{{ t('wallet.wc.doneTitle') }}</p>
            <p class="text-dark-400 text-xs font-mono">{{ shortAddress }}</p>
        </div>

        <!-- จับคู่ไม่สำเร็จ -->
        <template v-else-if="status === 'error'">
            <div class="p-3 rounded-xl bg-trading-red/10 border border-trading-red/30 text-trading-red text-sm" data-testid="wc-error">
                {{ t('wallet.wc.pairFailed') }}
            </div>
            <div class="flex gap-3">
                <button type="button" class="flex-1 py-2.5 text-dark-400 hover:text-white text-sm" @click="emit('back')">
                    {{ t('wallet.wc.back') }}
                </button>
                <button type="button" class="flex-1 py-2.5 bg-primary-500 hover:bg-primary-600 text-white rounded-xl font-medium text-sm" @click="emit('retry')">
                    {{ t('wallet.wc.tryAgain') }}
                </button>
            </div>
        </template>
    </div>
</template>
