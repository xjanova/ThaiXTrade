<script setup>
/**
 * TPIX TRADE - Open Orders Component
 * คำสั่งที่ยังเปิดค้างอยู่ของกระเป๋าปัจจุบัน
 *
 * ตอนนี้เทรดจริงได้เฉพาะ market order บน BSC ซึ่งจับคู่ทันที — ปกติตารางนี้จึงว่าง
 * หน้าว่างต้อง "อธิบาย" ว่าทำไมว่าง ไม่ใช่ปล่อยให้ผู้ใช้คิดว่าคำสั่งหาย
 * และต้องแยกให้ออกระหว่าง "ว่างจริง" / "ยังไม่ได้ยืนยันกระเป๋า (403)" / "โหลดไม่สำเร็จ"
 * (เดิมทุก error ถูกกลืนเป็นตารางว่างเหมือนกันหมด)
 *
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted } from 'vue';
import axios from 'axios';
import { useWalletStore } from '@/Stores/walletStore';
import { useTranslation } from '@/Composables/useTranslation';
import { showToast } from '@/Composables/useToasts';

const props = defineProps({
    /** คู่ที่เปิดอยู่ในหน้าเทรด (เช่น 'BTC/USDT') — null = ไม่มีบริบทคู่ ซ่อนช่อง "ซ่อนคู่อื่น" */
    currentPair: { type: String, default: null },
});

const walletStore = useWalletStore();
const { t, locale } = useTranslation();

const orders = ref([]);
const isLoading = ref(false);
const needsVerification = ref(false);
const loadFailed = ref(false);
const hideOtherPairs = ref(false);
const isVerifying = ref(false);
/** id ของคำสั่งที่กำลังยกเลิก — กันกดปุ่มเดิมซ้ำระหว่างรอ */
const cancellingIds = ref([]);
const isCancellingAll = ref(false);

const isConnected = computed(() => walletStore.isConnected);

/** ลำดับคำขอ — ผลของกระเป๋าใบก่อน/คำขอเก่าที่ตอบช้า ห้ามทับผลล่าสุด */
let loadSeq = 0;

// ── การแสดงผล ───────────────────────────────────────────────────────────────

function toNumber(value) {
    if (value === null || value === undefined || value === '') return null;
    const num = Number(value);
    return Number.isFinite(num) ? num : null;
}

/** ราคา — เลขนัยสำคัญกับเหรียญราคาต่ำ (0.00000394 ต้องไม่กลายเป็น 0.00) */
function formatPrice(value) {
    const num = toNumber(value);
    if (num === null || num <= 0) return '—';   // market order ไม่มีราคาตั้ง / ไม่รู้ราคา
    if (num >= 1000) return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (num >= 1) return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
    return num.toLocaleString('en-US', { maximumSignificantDigits: 4 });
}

/** จำนวนเหรียญ (base asset) — สูงสุด 8 ตำแหน่ง */
function formatAmount(value) {
    const num = toNumber(value);
    if (num === null) return '—';
    if (num !== 0 && Math.abs(num) < 1e-8) return num.toLocaleString('en-US', { maximumSignificantDigits: 4 });
    return num.toLocaleString('en-US', { maximumFractionDigits: 8 });
}

/** มูลค่าฝั่ง quote — 2 ตำแหน่ง แต่ยอดจิ๋วต้องไม่โชว์เป็น 0.00 */
function formatTotal(num) {
    if (num === null) return '—';
    if (num !== 0 && Math.abs(num) < 0.01) return num.toLocaleString('en-US', { maximumSignificantDigits: 2 });
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatTime(iso) {
    const date = new Date(iso);
    if (!iso || Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US', {
        dateStyle: 'short',
        timeStyle: 'medium',
    });
}

/** ฝั่ง API ใช้ทั้ง `BTC-USDT` และ `BTC/USDT` — เทียบด้วยรูปเดียว */
function pairKeyOf(pair) {
    return String(pair || '').trim().replace(/-/g, '/').toUpperCase();
}

const TYPE_KEYS = { limit: 'limit', market: 'market', stop_limit: 'stopLimit', 'stop-limit': 'stopLimit' };

function typeLabel(type) {
    const key = TYPE_KEYS[String(type || '').toLowerCase()];
    return key ? t(`history.orders.type.${key}`) : (String(type || '').trim() || '—');
}

function normalizeOrder(raw, index) {
    const side = String(raw?.side ?? '').toLowerCase();
    const price = toNumber(raw?.price);
    const amount = toNumber(raw?.amount);
    const filled = toNumber(raw?.filled_amount);
    // total จาก API ก่อน — คำนวณเองเฉพาะเมื่อไม่มี (และมีราคาจริง ไม่ใช่ 0)
    const total = toNumber(raw?.total) ?? (price && amount !== null ? price * amount : null);
    const filledPct = amount && amount > 0 && filled !== null
        ? Math.min(100, Math.max(0, (filled / amount) * 100))
        : 0;

    return {
        id: raw?.id ?? null,
        key: raw?.id ?? `order-${index}`,
        pair: String(raw?.pair ?? '').trim().replace(/-/g, '/') || '—',
        pairKey: pairKeyOf(raw?.pair),
        type: typeLabel(raw?.type),
        side: side === 'buy' || side === 'sell' ? side : null,
        rawSide: String(raw?.side || raw?.type || '').trim() || '—',
        price: formatPrice(raw?.price),
        amount: formatAmount(raw?.amount),
        filledPct,
        filledLabel: `${filledPct.toFixed(filledPct > 0 && filledPct < 1 ? 2 : 0)}%`,
        total: formatTotal(total),
        createdAt: raw?.created_at ?? null,
    };
}

const rows = computed(() => orders.value.map(o => ({ ...o, time: formatTime(o.createdAt) })));

/** "ซ่อนคู่อื่น" มีผลเฉพาะเมื่อรู้ว่ากำลังดูคู่ไหน */
const canFilterByPair = computed(() => !!props.currentPair);
const currentPairKey = computed(() => pairKeyOf(props.currentPair));

const visibleRows = computed(() => (
    canFilterByPair.value && hideOtherPairs.value
        ? rows.value.filter(r => r.pairKey === currentPairKey.value)
        : rows.value
));

const hasOrders = computed(() => orders.value.length > 0);
const showInitialLoading = computed(() => isLoading.value && !hasOrders.value);
const isRefreshing = computed(() => isLoading.value && hasOrders.value);

// ── โหลดคำสั่ง ──────────────────────────────────────────────────────────────

async function fetchOrders() {
    const seq = ++loadSeq;
    const address = walletStore.address;

    if (!address) {
        orders.value = [];
        needsVerification.value = false;
        loadFailed.value = false;
        isLoading.value = false;
        return;
    }

    isLoading.value = true;
    try {
        const { data } = await axios.get('/api/v1/trading/orders', {
            params: { wallet_address: address },
        });
        if (seq !== loadSeq) return;   // มีคำขอใหม่กว่า (หรือสลับกระเป๋า) — ทิ้งผลนี้

        if (data?.success && Array.isArray(data.data)) {
            orders.value = data.data.map(normalizeOrder);
            needsVerification.value = false;
            loadFailed.value = false;
        } else {
            orders.value = [];
            needsVerification.value = false;
            loadFailed.value = true;
        }
    } catch (err) {
        if (seq !== loadSeq) return;
        // ข้อมูลที่เคยโหลดไว้อาจไม่ตรงความจริงแล้ว — ล้างทิ้งแล้วบอกตรงๆ ดีกว่าโชว์ของเก่า
        orders.value = [];
        // 403 = ยังไม่ได้เซ็นยืนยันกระเป๋า (หรือลายเซ็นหมดอายุ 4 ชม. / IP เปลี่ยน)
        needsVerification.value = err?.response?.status === 403;
        loadFailed.value = !needsVerification.value;
    } finally {
        if (seq === loadSeq) isLoading.value = false;
    }
}

function reload() {
    if (isLoading.value) return;
    fetchOrders();
}

/** เซ็นยืนยันกระเป๋าแล้วโหลดใหม่ — แบบเดียวกับ AiTradeCard.verifyWallet() */
async function verifyWallet() {
    if (isVerifying.value) return;   // ป๊อปอัพขอลายเซ็นซ้อนกันไม่ได้

    isVerifying.value = true;
    try {
        const hasSigner = await walletStore.verifyOwnership();

        // กระเป๋าฝังที่ยังไม่ปลดล็อกไม่มี signer — ต้องใส่รหัสผ่านที่หน้าต่างเชื่อมกระเป๋า
        if (!hasSigner) {
            walletStore.openConnectModal();
            return;
        }

        await fetchOrders();
    } catch {
        // verifyOwnership จัดการ error ของตัวเองแล้ว — ปุ่มยืนยันยังอยู่ให้กดใหม่ได้
    } finally {
        isVerifying.value = false;
    }
}

// ── ยกเลิกคำสั่ง ────────────────────────────────────────────────────────────

function isCancelling(id) {
    return isCancellingAll.value || cancellingIds.value.includes(id);
}

function removeOrder(id) {
    orders.value = orders.value.filter(o => o.id !== id);
}

/**
 * ยิงยกเลิกหนึ่งคำสั่ง — ไม่ throw เด็ดขาด คืนผลเป็นสถานะให้ผู้เรียกตัดสินใจ
 * @returns {Promise<'ok'|'gone'|'verify'|'failed'>}
 */
async function requestCancel(order) {
    try {
        await axios.delete(`/api/v1/trading/order/${encodeURIComponent(order.id)}`, {
            data: { wallet_address: walletStore.address },
        });
        removeOrder(order.id);
        return 'ok';
    } catch (err) {
        const status = err?.response?.status;
        // 404 = คำสั่งไม่ได้เปิดอยู่แล้ว (จับคู่ไป/ถูกยกเลิกก่อนหน้า) — เอาออกจากตารางได้ แต่ต้องบอกผู้ใช้
        if (status === 404) {
            removeOrder(order.id);
            return 'gone';
        }
        if (status === 403) {
            needsVerification.value = true;
            return 'verify';
        }
        // ห้ามโชว์ข้อความดิบของ exception — log ไว้ดูเองพอ
        console.warn('Cancel order failed:', status ?? err?.message);
        return 'failed';
    }
}

function toastFor(result) {
    if (result === 'gone') showToast({ text: t('history.orders.notOpen'), type: 'info' });
    else if (result === 'verify') showToast({ text: t('history.orders.cancelNeedsVerify'), type: 'error' });
    else if (result === 'failed') showToast({ text: t('history.orders.cancelFailed'), type: 'error' });
    else showToast({ text: t('history.orders.cancelled'), type: 'success' });
}

async function cancelOrder(order) {
    if (!order?.id || isCancelling(order.id)) return;

    cancellingIds.value = [...cancellingIds.value, order.id];
    try {
        toastFor(await requestCancel(order));
    } finally {
        cancellingIds.value = cancellingIds.value.filter(id => id !== order.id);
    }
}

/**
 * ยกเลิกทุกคำสั่งที่ "เห็นอยู่" (ถ้าติ๊กซ่อนคู่อื่น = เฉพาะคู่นี้) — ผู้ใช้ต้องรู้ว่ากำลังลบอะไร
 * ถามยืนยันก่อนเสมอ และยิงทีละคำสั่ง: endpoint นี้ติด throttle:trading ยิงพร้อมกันหลายตัวโดนตัด
 */
async function cancelAll() {
    if (isCancellingAll.value || cancellingIds.value.length) return;

    const targets = visibleRows.value.filter(o => o.id);
    if (!targets.length) return;

    if (!window.confirm(t('history.orders.confirmCancelAll', { count: targets.length }))) return;

    isCancellingAll.value = true;
    const startAddress = walletStore.address;
    let failed = 0;
    let blockedByVerify = false;

    try {
        for (const order of targets) {
            // สลับ/ตัดกระเป๋ากลางทาง — หยุดเงียบๆ (watch ล้างตารางให้แล้ว) ไม่ยิงต่อด้วย address ของใบใหม่
            if (walletStore.address !== startAddress) return;

            const result = await requestCancel(order);
            // 403 ตัวเดียว = ตัวที่เหลือก็โดนเหมือนกันหมด ไม่ต้องยิงต่อ
            if (result === 'verify') {
                blockedByVerify = true;
                break;
            }
            if (result === 'failed') failed++;
        }
    } finally {
        isCancellingAll.value = false;
    }

    if (blockedByVerify) {
        showToast({ text: t('history.orders.cancelNeedsVerify'), type: 'error' });
    } else if (failed > 0) {
        showToast({ text: t('history.orders.cancelAllPartial', { failed, total: targets.length }), type: 'error' });
    } else {
        showToast({ text: t('history.orders.cancelledAll', { count: targets.length }), type: 'success' });
    }

    // ซิงก์กับเซิร์ฟเวอร์อีกรอบ — บางคำสั่งอาจจับคู่ไประหว่างที่ยกเลิก
    if (!needsVerification.value) fetchOrders();
}

onMounted(fetchOrders);

// สลับกระเป๋า/ตัดการเชื่อมต่อ — ล้างของใบเดิมทันที ห้ามค้างให้เห็นระหว่างรอโหลดของใบใหม่
watch(() => walletStore.address, (address, previous) => {
    if (address === previous) return;
    orders.value = [];
    needsVerification.value = false;
    loadFailed.value = false;
    fetchOrders();
});
</script>

<template>
    <div>
        <!-- ยังไม่เชื่อมกระเป๋า -->
        <div v-if="!isConnected" class="py-12 text-center">
            <svg class="w-12 h-12 mx-auto text-dark-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            <p class="text-dark-400 mb-3">{{ t('history.orders.connectPrompt') }}</p>
            <button type="button" class="btn-primary text-sm px-6 py-2" @click="walletStore.openConnectModal()">
                {{ t('wallet.connect') }}
            </button>
        </div>

        <!-- กระเป๋ายังไม่เซ็นยืนยัน (403) -->
        <div v-else-if="needsVerification" class="py-10 text-center" data-test="orders-verify">
            <p class="text-sm font-semibold text-white mb-1">{{ t('history.orders.needVerify') }}</p>
            <p class="text-xs text-dark-400 leading-relaxed max-w-sm mx-auto mb-4">{{ t('history.needVerifyBody') }}</p>
            <button
                type="button"
                class="btn-brand text-sm px-6 py-2 disabled:opacity-50 inline-flex items-center gap-2"
                :disabled="isVerifying"
                @click="verifyWallet"
            >
                <span v-if="isVerifying" class="spinner !w-3 !h-3 !border-white/30 !border-t-white"></span>
                {{ isVerifying ? t('history.verifying') : t('history.verifyNow') }}
            </button>
        </div>

        <!-- โหลดไม่สำเร็จ — ต้องไม่ดูเหมือน "ไม่มีคำสั่ง" -->
        <div v-else-if="loadFailed && !hasOrders" class="py-10 text-center" data-test="orders-failed">
            <p class="text-sm text-trading-red mb-3">{{ t('history.orders.loadFailed') }}</p>
            <button
                type="button"
                class="btn-primary text-sm px-6 py-2 disabled:opacity-50"
                :disabled="isLoading"
                @click="reload"
            >
                {{ t('history.retry') }}
            </button>
        </div>

        <!-- โหลดครั้งแรก -->
        <div v-else-if="showInitialLoading" class="py-8 text-center text-dark-400">
            <div class="animate-pulse">{{ t('history.orders.loading') }}</div>
        </div>

        <!-- ไม่มีคำสั่งเปิดอยู่ — อธิบายว่าทำไม -->
        <div v-else-if="!hasOrders" class="py-12 text-center" data-test="orders-empty">
            <svg class="w-12 h-12 mx-auto text-dark-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <p class="text-dark-400">{{ t('history.orders.empty') }}</p>
            <p class="text-xs text-dark-500 mt-1 max-w-sm mx-auto leading-relaxed">{{ t('history.orders.emptyHint') }}</p>
        </div>

        <div v-else>
            <!-- แถบเครื่องมือ -->
            <div class="flex items-center justify-between gap-3 mb-4">
                <div class="flex items-center gap-4">
                    <label v-if="canFilterByPair" class="flex items-center gap-2 text-sm text-dark-400 cursor-pointer">
                        <input
                            v-model="hideOtherPairs"
                            type="checkbox"
                            data-test="orders-hide-other"
                            class="rounded border-dark-600 bg-dark-800 text-primary-500 focus:ring-primary-500"
                        >
                        <span>{{ t('history.orders.hideOtherPairs') }}</span>
                    </label>
                    <span v-if="isRefreshing" class="text-xs text-dark-500 animate-pulse">{{ t('history.refreshing') }}</span>
                </div>
                <button
                    v-if="visibleRows.length > 0"
                    type="button"
                    data-test="orders-cancel-all"
                    :disabled="isCancellingAll || cancellingIds.length > 0"
                    class="text-sm text-trading-red hover:text-trading-red-light transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                    @click="cancelAll"
                >
                    {{ isCancellingAll ? t('history.orders.cancelling') : t('history.orders.cancelAll') }}
                </button>
            </div>

            <div class="overflow-x-auto">
                <table v-if="visibleRows.length > 0" class="trading-table">
                    <thead>
                        <tr>
                            <th>{{ t('history.orders.col.time') }}</th>
                            <th>{{ t('history.orders.col.pair') }}</th>
                            <th>{{ t('history.orders.col.type') }}</th>
                            <th>{{ t('history.orders.col.side') }}</th>
                            <th>{{ t('history.orders.col.price') }}</th>
                            <th>{{ t('history.orders.col.amount') }}</th>
                            <th>{{ t('history.orders.col.filled') }}</th>
                            <th>{{ t('history.orders.col.total') }}</th>
                            <th>{{ t('history.orders.col.action') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="order in visibleRows" :key="order.key" data-test="orders-row">
                            <td class="font-mono text-dark-400 whitespace-nowrap">{{ order.time }}</td>
                            <td class="font-medium text-white whitespace-nowrap">{{ order.pair }}</td>
                            <td class="text-dark-300">{{ order.type }}</td>
                            <td>
                                <span
                                    v-if="order.side"
                                    :class="[
                                        'px-2 py-1 rounded text-xs font-medium',
                                        order.side === 'buy' ? 'bg-trading-green/20 text-trading-green' : 'bg-trading-red/20 text-trading-red'
                                    ]"
                                >
                                    {{ t(`history.side.${order.side}`) }}
                                </span>
                                <span v-else class="px-2 py-1 rounded text-xs font-medium bg-white/5 text-dark-300">{{ order.rawSide }}</span>
                            </td>
                            <td class="font-mono text-white whitespace-nowrap">{{ order.price }}</td>
                            <td class="font-mono text-dark-300 whitespace-nowrap">{{ order.amount }}</td>
                            <td>
                                <div class="flex items-center gap-2">
                                    <div class="w-16 h-1.5 bg-dark-700 rounded-full overflow-hidden">
                                        <div
                                            class="h-full bg-primary-500 rounded-full"
                                            :style="{ width: `${order.filledPct}%` }"
                                        ></div>
                                    </div>
                                    <span class="text-xs text-dark-400">{{ order.filledLabel }}</span>
                                </div>
                            </td>
                            <td class="font-mono text-white whitespace-nowrap">{{ order.total }}</td>
                            <td>
                                <button
                                    v-if="order.id"
                                    type="button"
                                    data-test="orders-cancel"
                                    :disabled="isCancelling(order.id)"
                                    class="text-trading-red hover:text-trading-red-light transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    @click="cancelOrder(order)"
                                >
                                    {{ isCancelling(order.id) ? t('history.orders.cancelling') : t('history.orders.cancel') }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- มีคำสั่ง แต่ไม่ใช่ของคู่นี้ -->
                <div v-else class="py-10 text-center" data-test="orders-no-match">
                    <p class="text-dark-400 text-sm">{{ t('history.orders.noMatch', { pair: currentPair }) }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
