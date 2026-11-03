<script setup>
/**
 * TPIX TRADE - ชั้นคลัง / Treasury Dashboard
 * ยอดกระเป๋าคลังทุกใบสดจากเชน + กระเป๋าร้อน + สถานะความพร้อมจ่ายเงิน
 * รับ (QR) และโอนออก (เซ็นในเบราว์เซอร์แอดมิน — เซิร์ฟเวอร์ไม่มีคีย์คลัง)
 * Developed by Xman Studio
 */
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ColdSendModal from '@/Components/Admin/Treasury/ColdSendModal.vue';
import ReceiveModal from '@/Components/Admin/Treasury/ReceiveModal.vue';
import { useAdminPolling } from '@/Composables/useAdminPolling';
import axios from 'axios';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale } = useTranslation();
const dateLocale = computed(() => (locale.value === 'th' ? 'th-TH' : 'en-US'));
/* ชื่อบทบาทกระเป๋ามาจากเซิร์ฟเวอร์ทั้งสองภาษา (role_th / role) — เลือกตามภาษาที่ใช้อยู่ */
const roleMain = (p) => (locale.value === 'th' ? p.role_th : (p.role || p.role_th));
const roleSub = (p) => (locale.value === 'th' ? p.role : p.role_th);

const props = defineProps({
    pools: { type: Array, default: () => [] },
    hotWallet: { type: Object, default: () => ({}) },
    readiness: { type: Object, default: () => ({ ready: false, checks: [], blocking: [] }) },
    totalSupply: { type: String, default: '0' },
    blockNumber: { type: Number, default: null },
    rpcUrl: { type: String, default: '' },
    explorerUrl: { type: String, default: '' },
    limits: { type: Object, default: () => ({}) },
    pendingCount: { type: Number, default: 0 },
});

const pools = ref(props.pools);
const hotWallet = ref(props.hotWallet);
const readiness = ref(props.readiness);
const blockNumber = ref(props.blockNumber);
const isRefreshing = ref(false);
const lastRefresh = ref(new Date());

/* จัดรูปตัวเลขให้อ่านง่าย — รับเป็น "string ทศนิยม" ที่ฝั่ง PHP คำนวณมาแล้ว
 * ห้ามเอา wei ดิบมาเข้า Number() เด็ดขาด เพราะ 1e18 เกิน MAX_SAFE_INTEGER */
function fmtTpix(decimalString) {
    if (decimalString === null || decimalString === undefined) return '—';
    const [intPart, fracPart] = String(decimalString).split('.');
    const withCommas = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    if (!fracPart) return withCommas;
    const trimmed = fracPart.replace(/0+$/, '').slice(0, 4);
    return trimmed ? `${withCommas}.${trimmed}` : withCommas;
}

const shortAddr = (a) => (a ? `${a.slice(0, 8)}…${a.slice(-6)}` : '—');

const copied = ref('');
async function copyAddr(addr) {
    try {
        await navigator.clipboard.writeText(addr);
        copied.value = addr;
        setTimeout(() => (copied.value = ''), 1600);
    } catch { /* คลิปบอร์ดไม่ว่างก็ไม่ต้องทำอะไร */ }
}

const readableCount = computed(() => pools.value.filter((p) => p.readable).length);
const anyUnreadable = computed(() => pools.value.some((p) => !p.readable));

/* ผลรวมคลังคำนวณจาก share_pct ที่ฝั่ง PHP ทำมาแล้ว ไม่รวม wei เองฝั่ง JS */
const totalSharePct = computed(() =>
    pools.value
        .filter((p) => p.share_pct !== null)
        .reduce((sum, p) => sum + parseFloat(p.share_pct), 0)
        .toFixed(2),
);

const poolColors = {
    emerald: { bar: 'bg-emerald-500', text: 'text-emerald-400', ring: 'border-emerald-500/30 bg-emerald-500/5' },
    cyan: { bar: 'bg-cyan-500', text: 'text-cyan-400', ring: 'border-cyan-500/30 bg-cyan-500/5' },
    violet: { bar: 'bg-violet-500', text: 'text-violet-400', ring: 'border-violet-500/30 bg-violet-500/5' },
    amber: { bar: 'bg-amber-500', text: 'text-amber-400', ring: 'border-amber-500/30 bg-amber-500/5' },
    blue: { bar: 'bg-blue-500', text: 'text-blue-400', ring: 'border-blue-500/30 bg-blue-500/5' },
    rose: { bar: 'bg-rose-500', text: 'text-rose-400', ring: 'border-rose-500/30 bg-rose-500/5' },
    teal: { bar: 'bg-teal-500', text: 'text-teal-400', ring: 'border-teal-500/30 bg-teal-500/5' },
    slate: { bar: 'bg-slate-500', text: 'text-slate-300', ring: 'border-slate-500/30 bg-slate-500/5' },
};
const colorOf = (key) => poolColors[key] ?? poolColors.cyan;

/* หน้าต่างรับ/โอน — เก็บแค่ "key/ที่อยู่" ที่เลือก แล้วดึง object สดจาก pools ทุกครั้ง
 * ยอดที่หน้าต่างโอนใช้ตรวจจึงตามการรีเฟรช 30 วิไปด้วย */
const sendKey = ref(null);
const receiveAddress = ref(null);
const sendWallet = computed(() => pools.value.find((p) => p.key === sendKey.value) ?? null);
const receiveWallet = computed(() => {
    if (!receiveAddress.value) return null;
    if (hotWallet.value?.address === receiveAddress.value) {
        return { address: hotWallet.value.address, role_th: t('admin.treasury.hot.title') };
    }
    return pools.value.find((p) => p.address === receiveAddress.value) ?? null;
});

function onSent() {
    refresh();
}

async function refresh() {
    if (isRefreshing.value) return;
    isRefreshing.value = true;
    try {
        const { data } = await axios.get('/admin/treasury/balances');
        if (data.success) {
            pools.value = data.data.pools;
            hotWallet.value = data.data.hotWallet;
            blockNumber.value = data.data.blockNumber;
            readiness.value = data.data.readiness;
            lastRefresh.value = new Date();
        }
    } catch { /* ปล่อยค่าเดิมไว้ ดีกว่าล้างเป็นศูนย์แล้วคนอ่านผิด */ }
    finally { isRefreshing.value = false; }
}

useAdminPolling(refresh, 30000);
</script>

<template>
    <Head :title="t('admin.treasury.title')" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white">{{ t('admin.treasury.title') }}</h1>
                    <p class="text-sm text-gray-400 mt-1">
                        {{ t('admin.treasury.subtitle') }}
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Link href="/admin/treasury/payouts"
                          class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all">
                        {{ t('admin.treasury.payoutQueue') }}
                        <span v-if="pendingCount" class="ml-1.5 px-1.5 py-0.5 text-xs rounded-md bg-primary-500/30">{{ pendingCount }}</span>
                    </Link>
                    <Link href="/admin/treasury/ledger"
                          class="px-4 py-2 text-sm font-medium rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                        {{ t('admin.treasury.ledger') }}
                    </Link>
                    <button @click="refresh" :disabled="isRefreshing"
                            class="px-4 py-2 text-sm font-medium rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all disabled:opacity-50">
                        {{ isRefreshing ? t('admin.treasury.refreshing') : t('admin.treasury.refresh') }}
                    </button>
                </div>
            </div>

            <!-- แถบสถานะเชน -->
            <div :class="['flex flex-wrap items-center gap-3 p-4 rounded-xl border',
                          blockNumber ? 'bg-emerald-500/5 border-emerald-500/20' : 'bg-red-500/5 border-red-500/20']">
                <div :class="['w-3 h-3 rounded-full', blockNumber ? 'bg-emerald-500 animate-pulse' : 'bg-red-500']" />
                <span :class="blockNumber ? 'text-emerald-400' : 'text-red-400'" class="font-bold text-sm">
                    {{ blockNumber ? t('admin.treasury.chainOk') : t('admin.treasury.chainDown') }}
                </span>
                <span v-if="blockNumber" class="text-xs text-gray-500">{{ t('admin.treasury.block', { n: blockNumber.toLocaleString(dateLocale) }) }}</span>
                <span class="text-xs text-gray-600 font-mono">{{ rpcUrl }}</span>
                <span class="ml-auto text-xs text-gray-600">
                    {{ t('admin.treasury.readable', { n: readableCount, total: pools.length, time: lastRefresh.toLocaleTimeString(dateLocale) }) }}
                </span>
            </div>

            <!-- ⚠️ ป้ายบอกว่าระบบจ่ายเงินยังไม่เปิด -->
            <div v-if="!readiness.ready"
                 class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-5">
                <div class="flex items-start gap-3">
                    <div class="text-2xl leading-none">&#9888;</div>
                    <div class="flex-1">
                        <h3 class="text-amber-300 font-bold">{{ t('admin.treasury.notReady.title') }}</h3>
                        <p class="text-sm text-gray-400 mt-1">
                            {{ t('admin.treasury.notReady.body1') }}
                            <span class="text-amber-300">{{ t('admin.treasury.notReady.bodyHighlight') }}</span>
                            {{ t('admin.treasury.notReady.body2') }}
                        </p>
                        <ul class="mt-4 space-y-2">
                            <li v-for="c in readiness.checks" :key="c.key"
                                class="flex items-start gap-3 text-sm">
                                <span :class="['mt-0.5 w-5 h-5 rounded-md flex items-center justify-center text-xs shrink-0',
                                               c.ok ? 'bg-emerald-500/20 text-emerald-400' : 'bg-gray-600/30 text-gray-500']">
                                    {{ c.ok ? '&#10003;' : '&#9679;' }}
                                </span>
                                <span>
                                    <span :class="c.ok ? 'text-gray-400 line-through' : 'text-white'">{{ c.label }}</span>
                                    <span class="block text-xs text-gray-500 font-mono mt-0.5">{{ c.hint }}</span>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
            <div v-else class="rounded-xl border border-emerald-500/30 bg-emerald-500/5 p-4 flex items-center gap-3">
                <span class="text-emerald-400 text-lg">&#10003;</span>
                <span class="text-emerald-300 text-sm font-bold">{{ t('admin.treasury.ready') }}</span>
            </div>

            <!-- กระเป๋าร้อน -->
            <div class="glass-card rounded-xl p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-bold text-white uppercase tracking-wide">{{ t('admin.treasury.hot.title') }}</h3>
                            <span v-if="hotWallet.is_empty"
                                  class="px-2 py-0.5 text-xs rounded-md bg-gray-600/30 text-gray-400 border border-gray-500/30">
                                {{ t('admin.treasury.hot.notFunded') }}
                            </span>
                            <span v-else-if="hotWallet.is_low"
                                  class="px-2 py-0.5 text-xs rounded-md bg-amber-500/20 text-amber-400 border border-amber-500/30">
                                {{ t('admin.treasury.hot.low') }}
                            </span>
                        </div>
                        <button @click="copyAddr(hotWallet.address)"
                                class="mt-2 font-mono text-xs text-gray-400 hover:text-primary-400 transition-colors">
                            {{ hotWallet.address }}
                            <span v-if="copied === hotWallet.address" class="ml-1 text-emerald-400">{{ t('admin.treasury.copied') }}</span>
                        </button>
                        <p class="text-xs text-gray-600 mt-2">
                            {{ t('admin.treasury.hot.note') }}
                        </p>
                        <button v-if="hotWallet.address" @click="receiveAddress = hotWallet.address"
                                class="mt-3 px-3 py-1.5 text-xs font-medium rounded-lg bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                            {{ t('admin.treasury.hot.receiveQr') }}
                        </button>
                    </div>
                    <div class="text-right">
                        <div v-if="hotWallet.readable" class="text-3xl font-black text-white">
                            {{ fmtTpix(hotWallet.balance_tpix) }}
                            <span class="text-sm text-gray-500 font-normal">TPIX</span>
                        </div>
                        <div v-else class="text-xl font-bold text-red-400">{{ t('admin.treasury.hot.unreadable') }}</div>
                        <div v-if="hotWallet.low_warning_tpix !== '0'" class="text-xs text-gray-600 mt-1">
                            {{ t('admin.treasury.hot.lowWarn', { amount: fmtTpix(hotWallet.low_warning_tpix) }) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- เตือนเมื่ออ่านยอดบางใบไม่ได้ -->
            <div v-if="anyUnreadable" class="rounded-xl border border-red-500/30 bg-red-500/5 p-4 text-sm">
                <span class="text-red-400 font-bold">{{ t('admin.treasury.partial.title') }}</span>
                <span class="text-gray-400 ml-2">
                    {{ t('admin.treasury.partial.body') }}
                </span>
            </div>

            <!-- กระเป๋าคลัง 6 ใบ -->
            <div>
                <div class="flex items-baseline justify-between mb-3">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">{{ t('admin.treasury.pools.title', { n: pools.length }) }}</h3>
                    <span class="text-xs text-gray-500">
                        {{ t('admin.treasury.pools.total', { pct: totalSharePct, supply: fmtTpix(totalSupply) }) }}
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <div v-for="pool in pools" :key="pool.key"
                         :class="['rounded-xl border p-5 transition-all hover:scale-[1.01]', colorOf(pool.color).ring]">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div :class="['font-bold text-sm', colorOf(pool.color).text]">{{ roleMain(pool) }}</div>
                                <div class="text-xs text-gray-500 truncate">{{ roleSub(pool) }}</div>
                            </div>
                            <span class="text-xs text-gray-600 font-mono shrink-0">
                                {{ pool.share_pct !== null ? pool.share_pct + '%' : '—' }}
                            </span>
                        </div>

                        <div class="mt-4">
                            <div v-if="pool.readable" class="text-2xl font-black text-white">
                                {{ fmtTpix(pool.balance_tpix) }}
                                <span class="text-xs text-gray-500 font-normal">TPIX</span>
                            </div>
                            <div v-else class="text-lg font-bold text-red-400">—</div>

                            <!-- แถบสัดส่วน -->
                            <div class="mt-2 h-1.5 rounded-full bg-white/5 overflow-hidden">
                                <div :class="['h-full rounded-full transition-all', colorOf(pool.color).bar]"
                                     :style="{ width: Math.min(parseFloat(pool.share_pct || 0) * 4, 100) + '%' }" />
                            </div>
                        </div>

                        <div class="mt-4 space-y-1.5 text-xs">
                            <div class="flex justify-between">
                                <span class="text-gray-500">{{ t('admin.treasury.pools.genesis') }}</span>
                                <span class="text-gray-300 font-mono">{{ fmtTpix(pool.genesis_tpix) }}</span>
                            </div>
                            <div v-if="pool.readable" class="flex justify-between">
                                <span class="text-gray-500">
                                    {{ pool.spent_wei.startsWith('-') ? t('admin.treasury.pools.receivedMore') : t('admin.treasury.pools.spent') }}
                                </span>
                                <span :class="pool.spent_wei === '0' ? 'text-gray-600 font-mono' : 'text-amber-400 font-mono'">
                                    {{ pool.spent_wei === '0'
                                        ? t('admin.treasury.pools.noMovement')
                                        : fmtTpix(pool.spent_tpix.replace('-', '')) }}
                                </span>
                            </div>
                            <div class="flex justify-between items-center pt-1">
                                <button @click="copyAddr(pool.address)"
                                        class="font-mono text-gray-500 hover:text-primary-400 transition-colors">
                                    {{ shortAddr(pool.address) }}
                                    <span v-if="copied === pool.address" class="ml-1 text-emerald-400">{{ t('admin.treasury.copied') }}</span>
                                </button>
                                <a :href="`${explorerUrl}/address/${pool.address}`" target="_blank" rel="noopener"
                                   class="text-gray-600 hover:text-primary-400 transition-colors">explorer &#8599;</a>
                            </div>
                            <div class="text-gray-700 font-mono pt-0.5">{{ pool.path }}</div>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-2">
                            <button @click="receiveAddress = pool.address"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                                {{ t('admin.treasury.pools.receive') }}
                            </button>
                            <button @click="sendKey = pool.key" :disabled="!pool.readable"
                                    class="px-3 py-1.5 text-xs font-medium rounded-lg bg-amber-500/10 text-amber-300 border border-amber-500/30 hover:bg-amber-500/20 transition-all disabled:opacity-40 disabled:cursor-not-allowed">
                                {{ t('admin.treasury.pools.send') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- หมายเหตุความปลอดภัย -->
            <div class="rounded-xl border border-white/10 bg-white/[0.02] p-5 text-sm">
                <h3 class="text-white font-bold mb-2">{{ t('admin.treasury.security.title') }}</h3>
                <p class="text-gray-400 leading-relaxed">
                    {{ t('admin.treasury.security.body') }}
                </p>
            </div>
        </div>

        <ColdSendModal :show="!!sendWallet" :wallet="sendWallet" :explorer-url="explorerUrl"
                       @close="sendKey = null" @sent="onSent" />
        <ReceiveModal :show="!!receiveWallet" :wallet="receiveWallet" :explorer-url="explorerUrl"
                      @close="receiveAddress = null" />
    </AdminLayout>
</template>
