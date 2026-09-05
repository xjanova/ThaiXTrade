<script setup>
/**
 * TPIX TRADE — Liquidity (สภาพคล่องพูล TOKEN/TPIX บน TPIX DEX)
 *
 * "ทุกเหรียญบนเชน TPIX เทรดได้" ต้องมีคนเติมสภาพคล่องก่อน — หน้านี้คือทางเดียวที่ผู้ใช้ทั่วไป
 * เติม/ถอนพูลได้เอง (ไม่ต้องรอทีมงาน) เติมแล้ว dex:sync จะสร้างคู่เทรดให้ภายใน 1 นาที
 *
 * ทุกธุรกรรมเซ็นในกระเป๋าของผู้ใช้ผ่าน useTpixDex — หน้านี้ไม่ถือคีย์ ไม่แตะเซิร์ฟเวอร์เพื่อเขียนอะไร
 * fail-closed: DEX ยังไม่ deploy → แสดงป้ายรอ ไม่มีปุ่มให้กด
 *
 * Developed by Xman Studio
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import AppLayout from '@/Layouts/AppLayout.vue';
import CoinIcon from '@/Components/CoinIcon.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTpixDex, toDexAddress } from '@/Composables/useTpixDex';
import { TPIX_DEX, loadDexConfig, isDexConfigured } from '@/Config/dexContracts';
import { useTranslation } from '@/Composables/useTranslation';
import { showToast } from '@/Composables/useToasts';

const { t } = useTranslation();
const walletStore = useWalletStore();
const dex = useTpixDex();

const TPIX_CHAIN_ID = 4289;

// ── สถานะ DEX ───────────────────────────────────────────────────────────────
const dexLoaded = computed(() => TPIX_DEX.loaded);
const dexReady = computed(() => isDexConfigured());
const isOnTpix = computed(() => Number(walletStore.chainId) === TPIX_CHAIN_ID);

// ── รายการโทเคนที่มีพูลอยู่แล้ว (จากเซิร์ฟเวอร์) ───────────────────────────
const pools = ref([]);
async function loadPools() {
    try {
        const { data } = await axios.get('/api/v1/dex/pairs');
        // เฉพาะคู่ที่อ้างอิง TPIX (พูล TOKEN/WTPIX) — หน้านี้จัดการพูลชนิดนี้เท่านั้น
        pools.value = (data?.data || []).filter((p) => toDexAddress(p.quote_address) === 'native');
    } catch {
        pools.value = [];
    }
}

// ── โทเคนที่เลือก ──────────────────────────────────────────────────────────
const tokenInput = ref('');
const token = ref(null); // { symbol, name, address, decimals }
const tokenError = ref('');
const isResolvingToken = ref(false);

function pickPool(pool) {
    tokenInput.value = pool.base_address;
    token.value = {
        symbol: pool.base_asset,
        name: pool.base_asset,
        address: toDexAddress(pool.base_address),
        decimals: Number(pool.base_decimals) || 18,
        logo: pool.base_logo,
    };
    tokenError.value = '';
    refreshPool();
}

let resolveTimer = null;
watch(tokenInput, (val) => {
    clearTimeout(resolveTimer);
    const address = String(val || '').trim().toLowerCase();
    if (token.value && token.value.address === address) return;
    token.value = null;
    tokenError.value = '';
    if (!/^0x[a-f0-9]{40}$/.test(address)) return;
    isResolvingToken.value = true;
    resolveTimer = setTimeout(async () => {
        const meta = await dex.readTokenMeta(address);
        isResolvingToken.value = false;
        if (!meta || meta.native) {
            tokenError.value = t('liquidity.invalidToken');
            return;
        }
        token.value = meta;
        refreshPool();
    }, 400);
});

// ── ข้อมูลพูล + สถานะของฉัน ────────────────────────────────────────────────
const pool = ref(null);      // { reserveToken, reserveTpix, price } หรือ null = พูลใหม่
const position = ref(null);  // จาก dex.getMyPosition
const balances = ref({ token: '0', tpix: '0' });
const isRefreshing = ref(false);

async function refreshPool() {
    if (!token.value || !dexReady.value) {
        pool.value = null;
        position.value = null;
        return;
    }
    isRefreshing.value = true;
    try {
        const info = await dex.getPoolInfo(token.value.address, 'native');
        if (info && info.reserveIn > 0n && info.reserveOut > 0n) {
            const reserveToken = Number(info.reserveIn) / 10 ** token.value.decimals;
            const reserveTpix = Number(info.reserveOut) / 1e18;
            pool.value = {
                pair: info.pair,
                reserveToken,
                reserveTpix,
                price: reserveToken > 0 ? reserveTpix / reserveToken : 0,
            };
        } else {
            pool.value = null;
        }

        if (walletStore.isConnected) {
            const [pos, tokBal, tpixBal] = await Promise.all([
                dex.getMyPosition(token.value.address, token.value.decimals),
                dex.getBalance(token.value.address),
                dex.getBalance('native'),
            ]);
            position.value = pos && pos.lp > 0n ? pos : null;
            balances.value = { token: tokBal, tpix: tpixBal };
        } else {
            position.value = null;
        }
    } catch {
        pool.value = null;
    } finally {
        isRefreshing.value = false;
    }
}

// ── เติมสภาพคล่อง ──────────────────────────────────────────────────────────
const tab = ref('add'); // add | remove
const amountToken = ref('');
const amountTpix = ref('');
const slippage = ref(0.5);
const busy = ref(false);
const status = ref(null); // { type: 'success'|'error', text, url }

// พูลมีอยู่แล้ว → อีกฝั่งคำนวณตามอัตราส่วนพูลให้อัตโนมัติ (แก้ฝั่งไหน อีกฝั่งตาม)
let syncing = false;
watch(amountToken, (val) => {
    if (syncing || !pool.value) return;
    const n = parseFloat(val);
    syncing = true;
    amountTpix.value = n > 0 ? (n * pool.value.price).toFixed(6) : '';
    syncing = false;
});
watch(amountTpix, (val) => {
    if (syncing || !pool.value || pool.value.price <= 0) return;
    const n = parseFloat(val);
    syncing = true;
    amountToken.value = n > 0 ? (n / pool.value.price).toFixed(6) : '';
    syncing = false;
});

const canAdd = computed(() =>
    dexReady.value && token.value && walletStore.isConnected && isOnTpix.value
    && parseFloat(amountToken.value) > 0 && parseFloat(amountTpix.value) > 0 && !busy.value
);

function setStatus(type, text, url = null) {
    status.value = { type, text, url };
    showToast({ text, type: type === 'success' ? 'success' : 'error' });
}

async function submitAdd() {
    if (!canAdd.value) return;
    busy.value = true;
    status.value = null;
    try {
        const needs = await dex.needsApproval(token.value.address, amountToken.value, token.value.decimals);
        if (needs) {
            const ok = await dex.approveToken(token.value.address, amountToken.value, token.value.decimals);
            if (!ok) throw new Error(dex.error.value || 'approve failed');
        }
        const result = await dex.addLiquidity(token.value.address, amountToken.value, amountTpix.value, {
            slippagePct: slippage.value,
            tokenDecimals: token.value.decimals,
        });
        if (!result) throw new Error(dex.error.value || 'failed');
        setStatus('success', t('liquidity.added'), result.url);
        amountToken.value = '';
        amountTpix.value = '';
        await refreshPool();
        loadPools();
    } catch (err) {
        setStatus('error', dex.error.value || err?.message || t('trade.status.failed'));
    } finally {
        busy.value = false;
    }
}

// ── ถอนสภาพคล่อง ──────────────────────────────────────────────────────────
const removePercent = ref(50);
const canRemove = computed(() =>
    dexReady.value && token.value && walletStore.isConnected && isOnTpix.value && position.value && !busy.value
);

async function submitRemove() {
    if (!canRemove.value) return;
    busy.value = true;
    status.value = null;
    try {
        const result = await dex.removeLiquidity(token.value.address, removePercent.value, {
            slippagePct: slippage.value,
            tokenDecimals: token.value.decimals,
        });
        if (!result) throw new Error(dex.error.value || 'failed');
        setStatus('success', t('liquidity.removed'), result.url);
        await refreshPool();
        loadPools();
    } catch (err) {
        setStatus('error', dex.error.value || err?.message || t('trade.status.failed'));
    } finally {
        busy.value = false;
    }
}

async function switchToTpix() {
    try {
        await walletStore.switchChain(TPIX_CHAIN_ID);
    } catch {
        // ผู้ใช้ยกเลิก — ป้ายเตือนยังอยู่
    }
}

function fmt(n, digits = 6) {
    const num = Number(n) || 0;
    return num.toLocaleString('en-US', { maximumFractionDigits: digits });
}

const tradeHref = computed(() => (token.value ? `/trade/${String(token.value.symbol).toUpperCase()}-TPIX` : '/trade'));

watch(() => walletStore.address, refreshPool);
watch(() => walletStore.chainId, refreshPool);

let poolsTimer = null;
onMounted(async () => {
    await loadDexConfig();
    await loadPools();
    poolsTimer = setInterval(loadPools, 60_000);
});
onUnmounted(() => {
    clearTimeout(resolveTimer);
    if (poolsTimer) clearInterval(poolsTimer);
});
</script>

<template>
    <Head :title="t('liquidity.title')" />

    <AppLayout :hide-sidebar="true">
        <div class="flex justify-center px-4 py-6">
            <div class="w-full max-w-5xl grid gap-4 lg:grid-cols-[320px_1fr]">

                <!-- ซ้าย: พูลที่มีอยู่ -->
                <aside class="glass-dark rounded-2xl p-4 h-fit">
                    <h2 class="text-sm font-semibold text-white mb-1">{{ t('liquidity.title') }}</h2>
                    <p class="text-xs text-dark-400 mb-4 leading-relaxed">{{ t('liquidity.subtitle') }}</p>

                    <div v-if="dexLoaded && !dexReady" class="p-3 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-300 text-xs leading-relaxed">
                        {{ t('liquidity.pending') }}
                    </div>

                    <template v-else>
                        <p v-if="pools.length === 0" class="text-xs text-dark-500">{{ t('liquidity.noPools') }}</p>
                        <ul v-else class="space-y-1 max-h-[420px] overflow-y-auto pr-1">
                            <li v-for="p in pools" :key="p.symbol">
                                <button
                                    type="button"
                                    class="w-full flex items-center gap-2 px-2 py-2 rounded-lg text-left transition-colors hover:bg-white/5"
                                    :class="token && toDexAddress(p.base_address) === token.address ? 'bg-primary-500/10 ring-1 ring-primary-500/40' : ''"
                                    @click="pickPool(p)"
                                >
                                    <CoinIcon :symbol="p.base_asset" :src="p.base_logo" size="sm" />
                                    <span class="text-sm text-white font-medium">{{ p.base_asset }}</span>
                                    <span class="text-xs text-dark-400">/ TPIX</span>
                                    <span v-if="!p.is_active" class="ml-auto text-[10px] px-1.5 py-0.5 rounded bg-dark-700 text-dark-400">empty</span>
                                </button>
                            </li>
                        </ul>
                    </template>
                </aside>

                <!-- ขวา: ฟอร์ม -->
                <section class="glass-dark rounded-2xl p-5">
                    <!-- ป้ายเชน -->
                    <div v-if="walletStore.isConnected && !isOnTpix" class="mb-4 p-3 rounded-xl bg-yellow-500/10 border border-yellow-500/30 text-yellow-400 text-sm flex items-center gap-3">
                        <span>{{ t('liquidity.wrongChain') }}</span>
                        <button class="ml-auto px-3 py-1 rounded-lg bg-yellow-500/20 hover:bg-yellow-500/30 text-yellow-300 text-xs font-medium" @click="switchToTpix">
                            TPIX Chain
                        </button>
                    </div>

                    <!-- เลือกโทเคน -->
                    <label class="block text-xs text-dark-400 mb-1">{{ t('liquidity.token') }}</label>
                    <input
                        v-model="tokenInput"
                        type="text"
                        class="trading-input w-full font-mono text-sm"
                        :placeholder="t('liquidity.tokenPlaceholder')"
                        spellcheck="false"
                        :disabled="dexLoaded && !dexReady"
                    />
                    <p v-if="tokenError" class="mt-1 text-xs text-trading-red">{{ tokenError }}</p>
                    <p v-else-if="isResolvingToken" class="mt-1 text-xs text-dark-500">…</p>

                    <div v-if="token" class="mt-4">
                        <!-- สรุปพูล -->
                        <div class="grid grid-cols-2 gap-3 mb-4">
                            <div class="p-3 rounded-xl bg-white/5">
                                <p class="text-[11px] text-dark-400">{{ t('liquidity.poolPrice') }}</p>
                                <p class="text-sm text-white font-semibold">
                                    <template v-if="pool">1 {{ token.symbol }} = {{ fmt(pool.price, 8) }} TPIX</template>
                                    <template v-else>{{ t('liquidity.newPool') }}</template>
                                </p>
                            </div>
                            <div class="p-3 rounded-xl bg-white/5">
                                <p class="text-[11px] text-dark-400">{{ t('liquidity.reserves') }}</p>
                                <p class="text-sm text-white font-semibold">
                                    <template v-if="pool">{{ fmt(pool.reserveToken, 2) }} {{ token.symbol }} · {{ fmt(pool.reserveTpix, 2) }} TPIX</template>
                                    <template v-else>—</template>
                                </p>
                            </div>
                        </div>

                        <div v-if="position" class="mb-4 p-3 rounded-xl bg-primary-500/10 border border-primary-500/20 text-xs text-primary-200">
                            <span class="font-semibold text-white">{{ t('liquidity.yourPosition') }}:</span>
                            {{ fmt(position.token, 4) }} {{ token.symbol }} + {{ fmt(position.tpix, 4) }} TPIX
                            <span class="text-dark-400">({{ t('liquidity.yourShare') }} {{ position.sharePct.toFixed(2) }}%)</span>
                        </div>

                        <!-- แท็บ -->
                        <div class="flex gap-2 mb-4">
                            <button type="button" :class="['flex-1 py-2 rounded-lg text-sm font-medium', tab === 'add' ? 'btn-success' : 'bg-white/5 text-dark-300']" @click="tab = 'add'">{{ t('liquidity.add') }}</button>
                            <button type="button" :class="['flex-1 py-2 rounded-lg text-sm font-medium', tab === 'remove' ? 'btn-danger' : 'bg-white/5 text-dark-300']" @click="tab = 'remove'">{{ t('liquidity.remove') }}</button>
                        </div>

                        <!-- เติม -->
                        <div v-if="tab === 'add'" class="space-y-3">
                            <div>
                                <div class="flex justify-between text-xs text-dark-400 mb-1">
                                    <span>{{ t('liquidity.amountToken', { symbol: token.symbol }) }}</span>
                                    <span v-if="walletStore.isConnected">{{ t('liquidity.balance') }}: {{ fmt(balances.token, 4) }}</span>
                                </div>
                                <input v-model="amountToken" type="text" inputmode="decimal" class="trading-input w-full" placeholder="0.0" />
                            </div>
                            <div>
                                <div class="flex justify-between text-xs text-dark-400 mb-1">
                                    <span>{{ t('liquidity.amountTpix') }}</span>
                                    <span v-if="walletStore.isConnected">{{ t('liquidity.balance') }}: {{ fmt(balances.tpix, 4) }}</span>
                                </div>
                                <input v-model="amountTpix" type="text" inputmode="decimal" class="trading-input w-full" placeholder="0.0" />
                            </div>
                            <div class="flex items-center justify-between text-xs text-dark-400">
                                <span>{{ t('liquidity.slippage') }}</span>
                                <div class="flex gap-1">
                                    <button v-for="s in [0.1, 0.5, 1]" :key="s" type="button" :class="['px-2 py-0.5 rounded', slippage === s ? 'bg-primary-500/30 text-white' : 'bg-white/5']" @click="slippage = s">{{ s }}%</button>
                                </div>
                            </div>

                            <button v-if="!walletStore.isConnected" type="button" class="btn-primary w-full" @click="walletStore.openConnectModal()">
                                {{ t('liquidity.connect') }}
                            </button>
                            <button v-else type="button" class="btn-success w-full" :disabled="!canAdd" @click="submitAdd">
                                <span v-if="busy">{{ t('liquidity.confirmAdd') }}</span>
                                <span v-else>{{ t('liquidity.add') }}</span>
                            </button>
                        </div>

                        <!-- ถอน -->
                        <div v-else class="space-y-3">
                            <p v-if="!position" class="text-xs text-dark-500">{{ t('liquidity.noPosition') }}</p>
                            <template v-else>
                                <div class="flex items-center gap-3">
                                    <input v-model.number="removePercent" type="range" min="1" max="100" class="flex-1" />
                                    <span class="text-sm text-white w-12 text-right">{{ removePercent }}%</span>
                                </div>
                                <p class="text-xs text-dark-400">
                                    ≈ {{ fmt(Number(position.token) * removePercent / 100, 4) }} {{ token.symbol }} + {{ fmt(Number(position.tpix) * removePercent / 100, 4) }} TPIX
                                </p>
                                <button type="button" class="btn-danger w-full" :disabled="!canRemove" @click="submitRemove">
                                    {{ t('liquidity.withdraw', { percent: removePercent }) }}
                                </button>
                            </template>
                        </div>

                        <!-- ผล -->
                        <div v-if="status" :class="['mt-4 p-3 rounded-xl text-xs', status.type === 'success' ? 'bg-trading-green/10 text-trading-green' : 'bg-trading-red/10 text-trading-red']">
                            {{ status.text }}
                            <a v-if="status.url" :href="status.url" target="_blank" rel="noopener" class="underline ml-2">{{ t('liquidity.viewTx') }} ↗</a>
                        </div>

                        <div v-if="pool" class="mt-4 text-right">
                            <Link :href="tradeHref" class="text-xs text-primary-400 hover:text-primary-300">{{ t('liquidity.tradeIt', { pair: `${token.symbol}/TPIX` }) }} →</Link>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
