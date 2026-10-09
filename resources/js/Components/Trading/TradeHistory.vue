<script setup>
/**
 * TPIX TRADE - Trade History Component
 * ประวัติไม้ที่ผู้ใช้วางเอง (market order บน BSC ผ่าน PancakeSwap + รายการเก่า)
 *
 * ใช้ข้อมูลชุดเดียวกับป้ายบนกราฟ (useMyTrades — singleton ระดับโมดูล)
 * เดิมตารางนี้ยิง API เองแยกอีกชุด: กราฟขึ้นไม้ใหม่แล้วแต่ตารางยังว่าง (หรือกลับกัน)
 * และ 403 ถูกกลืนเป็น "ยังไม่มีประวัติ" — คนที่เพิ่งเทรดเห็นตารางว่างแล้วคิดว่าไม้หาย
 *
 * Trade.vue สั่ง myTrades.load(true) หลังเทรดสำเร็จทุกครั้ง ตารางนี้จึงอัปเดตเองโดยไม่ต้องทำอะไรเพิ่ม
 *
 * Developed by Xman Studio
 */

import { ref, computed, watch, onMounted } from 'vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useMyTrades } from '@/Composables/useMyTrades';
import { useTranslation } from '@/Composables/useTranslation';
import { getTxUrl, BSC_CHAIN_ID } from '@/utils/web3';

const walletStore = useWalletStore();
const my = useMyTrades();
const { t, locale } = useTranslation();

const pairFilter = ref('all');
const sideFilter = ref('all');
const isVerifying = ref(false);

const isConnected = computed(() => walletStore.isConnected);

// ── การแสดงผลตัวเลข ─────────────────────────────────────────────────────────
// API ส่งได้ทั้ง number และ numeric string (บางแถวเป็น '0' หรือ null = ไม่รู้ค่า)

function toNumber(value) {
    if (value === null || value === undefined || value === '') return null;
    const num = Number(value);
    return Number.isFinite(num) ? num : null;
}

/**
 * ราคา — ใช้เลขนัยสำคัญกับเหรียญราคาต่ำ
 * toFixed(2) ทำให้เหรียญ 0.00000394 กลายเป็น "0.00" ผู้ใช้จะคิดว่าซื้อได้ฟรีหรือระบบพัง
 */
function formatPrice(value) {
    const num = toNumber(value);
    if (num === null || num <= 0) return '—';   // 0 = แถวเก่าที่ไม่ได้บันทึกราคาไว้ ไม่ใช่ราคาศูนย์จริง
    if (num >= 1000) return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (num >= 1) return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
    return num.toLocaleString('en-US', { maximumSignificantDigits: 4 });
}

/** จำนวนเหรียญ (base asset) — ทศนิยมสูงสุด 8 ตำแหน่ง ตัดศูนย์ท้ายทิ้ง */
function formatAmount(value) {
    const num = toNumber(value);
    if (num === null) return '—';
    if (num !== 0 && Math.abs(num) < 1e-8) return num.toLocaleString('en-US', { maximumSignificantDigits: 4 });
    return num.toLocaleString('en-US', { maximumFractionDigits: 8 });
}

/** มูลค่าฝั่ง quote — 2 ตำแหน่ง แต่ยอดเล็กกว่า 0.01 ต้องไม่โชว์เป็น 0.00 */
function formatTotal(value) {
    const num = toNumber(value);
    if (num === null) return '—';
    if (num !== 0 && Math.abs(num) < 0.01) return num.toLocaleString('en-US', { maximumSignificantDigits: 2 });
    return num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** ค่าธรรมเนียม — ไม่รู้หน่วยแน่ชัด (swap เก็บจากเหรียญขาเข้า) จึงไม่ใส่ $ หรือสัญลักษณ์ใดๆ */
function formatFee(value) {
    const num = toNumber(value);
    if (num === null) return '—';
    if (num === 0) return '0';
    if (Math.abs(num) < 1e-8) return num.toLocaleString('en-US', { maximumSignificantDigits: 4 });
    return num.toLocaleString('en-US', { maximumFractionDigits: 8 });
}

function formatTime(iso) {
    const date = new Date(iso);
    if (!iso || Number.isNaN(date.getTime())) return '—';
    // ไทยแสดงปี พ.ศ. ตามที่ผู้ใช้คุ้น — อ่าน locale.value ที่นี่เพื่อให้สลับภาษาแล้วเปลี่ยนตาม
    return date.toLocaleString(locale.value === 'th' ? 'th-TH' : 'en-US', {
        dateStyle: 'short',
        timeStyle: 'medium',
    });
}

// ── ชื่อคู่เทรด ──────────────────────────────────────────────────────────────

const ADDRESS_RE = /^0x[a-fA-F0-9]{40}$/;
const SYMBOL_RE = /^[A-Za-z0-9.]{1,12}$/;

/** แถวเก่ามากเก็บคู่เป็นที่อยู่สัญญา (tokenA/tokenB) — ย่อให้อ่านได้ แต่ title ยังเห็นเต็ม */
function shortPart(part) {
    return ADDRESS_RE.test(part) ? `${part.slice(0, 6)}…${part.slice(-4)}` : part;
}

/** ฝั่ง API บางที่ใช้ `BTC-USDT` บางที่ `BTC/USDT` — เทียบกันด้วยรูปเดียว */
function pairKeyOf(pair) {
    return String(pair || '').trim().replace(/-/g, '/').toUpperCase();
}

function splitPair(pair) {
    const parts = String(pair || '').trim().replace(/-/g, '/').split('/');
    return parts.length === 2 ? parts : [parts[0] || '', ''];
}

/** สัญลักษณ์ที่ใส่ต่อท้ายตัวเลขได้ — ถ้าเป็นที่อยู่สัญญาไม่ใส่ (ยาวและอ่านไม่รู้เรื่อง) */
function symbolOrEmpty(part) {
    return SYMBOL_RE.test(part) ? part.toUpperCase() : '';
}

// ── แถวที่พร้อมแสดง ─────────────────────────────────────────────────────────

const rows = computed(() => (Array.isArray(my.trades.value) ? my.trades.value : []).map((raw, index) => {
    const rawPair = String(raw?.pair ?? '').trim();
    const [base, quote] = splitPair(rawPair);
    const side = String(raw?.side ?? '').toLowerCase();
    const txHash = typeof raw?.tx_hash === 'string' && raw.tx_hash ? raw.tx_hash : null;

    return {
        // id อาจไม่มีในแถวแปลกๆ — กันไม่ให้ key ซ้ำจน Vue วาดแถวสลับกัน
        key: raw?.id ?? `${txHash ?? 'row'}-${index}`,
        pairKey: pairKeyOf(rawPair),
        pairLabel: rawPair ? [base, quote].filter(Boolean).map(shortPart).join('/') : '—',
        pairTitle: rawPair,
        side: side === 'buy' || side === 'sell' ? side : null,
        // ประเภทแปลกๆ จากข้อมูลเก่า (เช่น swap/deposit) — โชว์ตามจริงแบบกลางๆ ไม่ใช่เดาว่าซื้อหรือขาย
        rawType: String(raw?.type || raw?.side || '').trim() || '—',
        price: formatPrice(raw?.price),
        amount: formatAmount(raw?.amount),
        total: formatTotal(raw?.total),
        fee: formatFee(raw?.fee),
        baseSymbol: symbolOrEmpty(base),
        quoteSymbol: symbolOrEmpty(quote),
        txHash,
        // explorer ต้องตรงกับเชนที่ไม้เกิดจริง — ไม่รู้เชน = BSC (ที่เดียวที่เทรดจริงได้ตอนนี้)
        txUrl: txHash ? getTxUrl(txHash, Number(raw?.chain_id) || BSC_CHAIN_ID) : null,
        time: formatTime(raw?.created_at),
    };
}));

const hasRows = computed(() => rows.value.length > 0);

/** ตัวเลือกคู่เทรดสร้างจากข้อมูลจริง — ไม่มีคู่ที่กดแล้วได้ตารางว่างเสมอ */
const pairOptions = computed(() => {
    const seen = new Map();
    rows.value.forEach((row) => {
        if (row.pairKey && !seen.has(row.pairKey)) seen.set(row.pairKey, row.pairLabel);
    });
    return [...seen.entries()]
        .map(([value, label]) => ({ value, label }))
        .sort((a, b) => a.label.localeCompare(b.label));
});

const filteredRows = computed(() => rows.value.filter((row) => {
    if (pairFilter.value !== 'all' && row.pairKey !== pairFilter.value) return false;
    if (sideFilter.value !== 'all' && row.side !== sideFilter.value) return false;
    return true;
}));

// คู่ที่เลือกไว้หายไปจากข้อมูล (สลับกระเป๋า/โหลดใหม่) → กลับไปทุกคู่ ไม่งั้นตารางว่างโดยไม่มีเหตุผล
watch(pairOptions, (options) => {
    if (pairFilter.value !== 'all' && !options.some(o => o.value === pairFilter.value)) {
        pairFilter.value = 'all';
    }
});

function clearFilters() {
    pairFilter.value = 'all';
    sideFilter.value = 'all';
}

// ── สถานะของตาราง ──────────────────────────────────────────────────────────
// สปินเนอร์เต็มตารางเฉพาะตอนยังไม่มีอะไรให้ดู — รีเฟรชหลังเทรดต้องไม่ทำให้แถวเดิมกะพริบหาย
const showInitialLoading = computed(() => my.isLoading.value && !hasRows.value);
const isRefreshing = computed(() => my.isLoading.value && hasRows.value);

function reload() {
    if (my.isLoading.value) return;   // กันกดรัว — useMyTrades รวมคำขอซ้อนให้อยู่แล้ว แต่ไม่ต้องยิงเพิ่ม
    my.load(true);
}

/**
 * เซ็นยืนยันกระเป๋าแล้วโหลดใหม่ — แบบเดียวกับ AiTradeCard.verifyWallet()
 * ไม่บอก "สำเร็จ" เอง: ดูจากการโหลดซ้ำว่ายังโดน 403 อยู่ไหม (ไม่มี endpoint ให้ถามตรงๆ)
 */
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

        await my.load(true);
    } catch {
        // verifyOwnership จัดการ error ของตัวเองแล้ว — ตารางยังขึ้นปุ่มยืนยันให้กดใหม่ได้
    } finally {
        isVerifying.value = false;
    }
}

onMounted(() => {
    // ครั้งก่อนพลาด (403/เน็ตหลุด) → ยิงใหม่เลย ไม่งั้นแคชของ useMyTrades คืนผลเดิมที่ว่าง
    my.load(my.needsVerification.value || my.loadFailed.value);
});

// สลับกระเป๋า/ตัดการเชื่อมต่อ — load() ล้างข้อมูลเองเมื่อไม่มี address และไม่โชว์ไม้ของใบก่อน
watch(() => walletStore.address, () => {
    clearFilters();
    my.load();
});
</script>

<template>
    <div>
        <!-- ยังไม่เชื่อมกระเป๋า -->
        <div v-if="!isConnected" class="py-12 text-center">
            <svg class="w-12 h-12 mx-auto text-dark-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-dark-400 mb-3">{{ t('history.connectPrompt') }}</p>
            <button type="button" class="btn-primary text-sm px-6 py-2" @click="walletStore.openConnectModal()">
                {{ t('wallet.connect') }}
            </button>
        </div>

        <!-- กระเป๋ายังไม่เซ็นยืนยัน (403) — ห้ามโชว์เป็น "ยังไม่มีประวัติ" -->
        <div v-else-if="my.needsVerification.value" class="py-10 text-center" data-test="history-verify">
            <p class="text-sm font-semibold text-white mb-1">{{ t('history.needVerify') }}</p>
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

        <!-- โหลดไม่สำเร็จ (เน็ต/เซิร์ฟเวอร์) — ต่างจาก "ยังไม่มีไม้" -->
        <div v-else-if="my.loadFailed.value && !hasRows" class="py-10 text-center" data-test="history-failed">
            <p class="text-sm text-trading-red mb-3">{{ t('history.loadFailed') }}</p>
            <button
                type="button"
                class="btn-primary text-sm px-6 py-2 disabled:opacity-50"
                :disabled="my.isLoading.value"
                @click="reload"
            >
                {{ t('history.retry') }}
            </button>
        </div>

        <!-- โหลดครั้งแรก -->
        <div v-else-if="showInitialLoading" class="py-8 text-center text-dark-400">
            <div class="animate-pulse">{{ t('history.loading') }}</div>
        </div>

        <!-- ยังไม่มีไม้ -->
        <div v-else-if="!hasRows" class="py-12 text-center" data-test="history-empty">
            <svg class="w-12 h-12 mx-auto text-dark-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <p class="text-dark-400">{{ t('history.empty') }}</p>
            <p class="text-xs text-dark-500 mt-1">{{ t('history.emptyHint') }}</p>
        </div>

        <div v-else>
            <!-- ตัวกรอง -->
            <div class="flex flex-wrap items-center gap-3 mb-4">
                <select
                    v-model="pairFilter"
                    :aria-label="t('history.col.pair')"
                    data-test="history-pair-filter"
                    class="bg-dark-800 border-dark-600 rounded-lg text-sm text-dark-300 py-2 px-3 focus:ring-primary-500 focus:border-primary-500"
                >
                    <option value="all">{{ t('history.allPairs') }}</option>
                    <option v-for="opt in pairOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
                <select
                    v-model="sideFilter"
                    :aria-label="t('history.col.side')"
                    data-test="history-side-filter"
                    class="bg-dark-800 border-dark-600 rounded-lg text-sm text-dark-300 py-2 px-3 focus:ring-primary-500 focus:border-primary-500"
                >
                    <option value="all">{{ t('history.allSides') }}</option>
                    <option value="buy">{{ t('history.side.buy') }}</option>
                    <option value="sell">{{ t('history.side.sell') }}</option>
                </select>
                <span v-if="isRefreshing" class="ml-auto text-xs text-dark-500 animate-pulse">{{ t('history.refreshing') }}</span>
            </div>

            <div class="overflow-x-auto">
                <table v-if="filteredRows.length > 0" class="trading-table">
                    <thead>
                        <tr>
                            <th>{{ t('history.col.time') }}</th>
                            <th>{{ t('history.col.pair') }}</th>
                            <th>{{ t('history.col.side') }}</th>
                            <th>{{ t('history.col.price') }}</th>
                            <th>{{ t('history.col.amount') }}</th>
                            <th>{{ t('history.col.total') }}</th>
                            <th>{{ t('history.col.fee') }}</th>
                            <th>{{ t('history.col.tx') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filteredRows" :key="row.key" data-test="history-row">
                            <td class="font-mono text-dark-400 whitespace-nowrap">{{ row.time }}</td>
                            <td class="font-medium text-white whitespace-nowrap" :title="row.pairTitle">{{ row.pairLabel }}</td>
                            <td>
                                <span
                                    v-if="row.side"
                                    :class="[
                                        'px-2 py-1 rounded text-xs font-medium',
                                        row.side === 'buy' ? 'bg-trading-green/20 text-trading-green' : 'bg-trading-red/20 text-trading-red'
                                    ]"
                                >
                                    {{ t(`history.side.${row.side}`) }}
                                </span>
                                <span v-else class="px-2 py-1 rounded text-xs font-medium bg-white/5 text-dark-300">{{ row.rawType }}</span>
                            </td>
                            <td class="font-mono text-white whitespace-nowrap">{{ row.price }}</td>
                            <td class="font-mono text-dark-300 whitespace-nowrap">
                                {{ row.amount }}<span v-if="row.baseSymbol && row.amount !== '—'" class="ml-1 text-dark-500 text-[10px]">{{ row.baseSymbol }}</span>
                            </td>
                            <td class="font-mono text-white whitespace-nowrap">
                                {{ row.total }}<span v-if="row.quoteSymbol && row.total !== '—'" class="ml-1 text-dark-500 text-[10px]">{{ row.quoteSymbol }}</span>
                            </td>
                            <td class="font-mono text-dark-400 whitespace-nowrap">{{ row.fee }}</td>
                            <td>
                                <a v-if="row.txUrl"
                                    :href="row.txUrl"
                                    :title="t('history.viewTx')"
                                    target="_blank" rel="noopener noreferrer"
                                    class="text-primary-400 hover:text-primary-300 text-xs font-mono underline">
                                    {{ row.txHash.slice(0, 8) }}…
                                </a>
                                <span v-else class="text-dark-600 text-xs">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- มีไม้ แต่ไม่ตรงตัวกรอง -->
                <div v-else class="py-10 text-center" data-test="history-no-match">
                    <p class="text-dark-400 text-sm mb-2">{{ t('history.noMatch') }}</p>
                    <button type="button" class="text-xs text-primary-400 hover:text-primary-300 underline" @click="clearFilters">
                        {{ t('history.clearFilters') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
