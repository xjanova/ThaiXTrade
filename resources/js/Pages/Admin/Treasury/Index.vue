<script setup>
/**
 * TPIX TRADE - ชั้นคลัง / Treasury Dashboard
 * ยอดกระเป๋าคลัง 6 ใบสดจากเชน + กระเป๋าร้อน + สถานะความพร้อมจ่ายเงิน
 * Developed by Xman Studio
 */
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import axios from 'axios';

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
};
const colorOf = (key) => poolColors[key] ?? poolColors.cyan;

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

let pollInterval;
onMounted(() => { pollInterval = setInterval(refresh, 30000); });
onUnmounted(() => clearInterval(pollInterval));
</script>

<template>
    <Head title="ชั้นคลัง TPIX" />
    <AdminLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-white">ชั้นคลัง TPIX</h1>
                    <p class="text-sm text-gray-400 mt-1">
                        ยอดกระเป๋าคลังอ่านสดจากเชนโดยตรง ไม่ผ่าน explorer
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <Link href="/admin/treasury/payouts"
                          class="px-4 py-2 text-sm font-medium rounded-xl bg-primary-500/20 text-primary-400 border border-primary-500/30 hover:bg-primary-500/30 transition-all">
                        คิวจ่ายเงิน
                        <span v-if="pendingCount" class="ml-1.5 px-1.5 py-0.5 text-xs rounded-md bg-primary-500/30">{{ pendingCount }}</span>
                    </Link>
                    <Link href="/admin/treasury/ledger"
                          class="px-4 py-2 text-sm font-medium rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all">
                        สมุดบัญชี
                    </Link>
                    <button @click="refresh" :disabled="isRefreshing"
                            class="px-4 py-2 text-sm font-medium rounded-xl bg-white/5 text-gray-300 border border-white/10 hover:bg-white/10 transition-all disabled:opacity-50">
                        {{ isRefreshing ? 'กำลังรีเฟรช…' : 'รีเฟรช' }}
                    </button>
                </div>
            </div>

            <!-- แถบสถานะเชน -->
            <div :class="['flex flex-wrap items-center gap-3 p-4 rounded-xl border',
                          blockNumber ? 'bg-emerald-500/5 border-emerald-500/20' : 'bg-red-500/5 border-red-500/20']">
                <div :class="['w-3 h-3 rounded-full', blockNumber ? 'bg-emerald-500 animate-pulse' : 'bg-red-500']" />
                <span :class="blockNumber ? 'text-emerald-400' : 'text-red-400'" class="font-bold text-sm">
                    {{ blockNumber ? 'ต่อเชนได้' : 'ต่อเชนไม่ได้' }}
                </span>
                <span v-if="blockNumber" class="text-xs text-gray-500">บล็อก #{{ blockNumber.toLocaleString() }}</span>
                <span class="text-xs text-gray-600 font-mono">{{ rpcUrl }}</span>
                <span class="ml-auto text-xs text-gray-600">
                    อ่านได้ {{ readableCount }}/{{ pools.length }} ใบ · อัปเดต
                    {{ lastRefresh.toLocaleTimeString('th-TH') }}
                </span>
            </div>

            <!-- ⚠️ ป้ายบอกว่าระบบจ่ายเงินยังไม่เปิด -->
            <div v-if="!readiness.ready"
                 class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-5">
                <div class="flex items-start gap-3">
                    <div class="text-2xl leading-none">&#9888;</div>
                    <div class="flex-1">
                        <h3 class="text-amber-300 font-bold">ระบบจ่ายเงินยังไม่เปิดใช้งาน</h3>
                        <p class="text-sm text-gray-400 mt-1">
                            หน้าดูยอด คิวอนุมัติ whitelist และสมุดบัญชีใช้งานได้ตามปกติ
                            แต่จะยัง <span class="text-amber-300">ไม่เซ็นและไม่ส่งธุรกรรมขึ้นเชน</span>
                            จนกว่าเงื่อนไขข้างล่างจะครบทุกข้อ
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
                <span class="text-emerald-300 text-sm font-bold">ระบบจ่ายเงินพร้อมใช้งาน</span>
            </div>

            <!-- กระเป๋าร้อน -->
            <div class="glass-card rounded-xl p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-sm font-bold text-white uppercase tracking-wide">กระเป๋าร้อน</h3>
                            <span v-if="hotWallet.is_empty"
                                  class="px-2 py-0.5 text-xs rounded-md bg-gray-600/30 text-gray-400 border border-gray-500/30">
                                ยังไม่ได้เติมเงิน
                            </span>
                            <span v-else-if="hotWallet.is_low"
                                  class="px-2 py-0.5 text-xs rounded-md bg-amber-500/20 text-amber-400 border border-amber-500/30">
                                ยอดต่ำ
                            </span>
                        </div>
                        <button @click="copyAddr(hotWallet.address)"
                                class="mt-2 font-mono text-xs text-gray-400 hover:text-primary-400 transition-colors">
                            {{ hotWallet.address }}
                            <span v-if="copied === hotWallet.address" class="ml-1 text-emerald-400">คัดลอกแล้ว</span>
                        </button>
                        <p class="text-xs text-gray-600 mt-2">
                            คีย์สุ่มอิสระ ไม่ derive จาก mnemonic ของคลัง — คลัง 6 ใบไม่ได้อยู่ในระบบนี้
                        </p>
                    </div>
                    <div class="text-right">
                        <div v-if="hotWallet.readable" class="text-3xl font-black text-white">
                            {{ fmtTpix(hotWallet.balance_tpix) }}
                            <span class="text-sm text-gray-500 font-normal">TPIX</span>
                        </div>
                        <div v-else class="text-xl font-bold text-red-400">อ่านยอดไม่ได้</div>
                        <div v-if="hotWallet.low_warning_tpix !== '0'" class="text-xs text-gray-600 mt-1">
                            เตือนเมื่อต่ำกว่า {{ fmtTpix(hotWallet.low_warning_tpix) }} TPIX
                        </div>
                    </div>
                </div>
            </div>

            <!-- เตือนเมื่ออ่านยอดบางใบไม่ได้ -->
            <div v-if="anyUnreadable" class="rounded-xl border border-red-500/30 bg-red-500/5 p-4 text-sm">
                <span class="text-red-400 font-bold">อ่านยอดบางใบไม่ได้</span>
                <span class="text-gray-400 ml-2">
                    ช่องที่ขึ้น "—" คือ RPC ไม่ตอบ ไม่ใช่ยอดเป็นศูนย์ — อย่าเพิ่งสรุปว่าคลังว่าง
                </span>
            </div>

            <!-- กระเป๋าคลัง 6 ใบ -->
            <div>
                <div class="flex items-baseline justify-between mb-3">
                    <h3 class="text-sm font-bold text-white uppercase tracking-wide">กระเป๋าคลัง 6 ใบ</h3>
                    <span class="text-xs text-gray-500">
                        รวม {{ totalSharePct }}% ของ {{ fmtTpix(totalSupply) }} TPIX
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    <div v-for="pool in pools" :key="pool.key"
                         :class="['rounded-xl border p-5 transition-all hover:scale-[1.01]', colorOf(pool.color).ring]">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div :class="['font-bold text-sm', colorOf(pool.color).text]">{{ pool.role_th }}</div>
                                <div class="text-xs text-gray-500 truncate">{{ pool.role }}</div>
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
                                <span class="text-gray-500">ตอน genesis</span>
                                <span class="text-gray-300 font-mono">{{ fmtTpix(pool.genesis_tpix) }}</span>
                            </div>
                            <div v-if="pool.readable" class="flex justify-between">
                                <span class="text-gray-500">
                                    {{ pool.spent_wei.startsWith('-') ? 'รับเข้าเพิ่ม' : 'จ่ายออกไปแล้ว' }}
                                </span>
                                <span :class="pool.spent_wei === '0' ? 'text-gray-600 font-mono' : 'text-amber-400 font-mono'">
                                    {{ pool.spent_wei === '0'
                                        ? 'ยังไม่เคยเคลื่อนไหว'
                                        : fmtTpix(pool.spent_tpix.replace('-', '')) }}
                                </span>
                            </div>
                            <div class="flex justify-between items-center pt-1">
                                <button @click="copyAddr(pool.address)"
                                        class="font-mono text-gray-500 hover:text-primary-400 transition-colors">
                                    {{ shortAddr(pool.address) }}
                                    <span v-if="copied === pool.address" class="ml-1 text-emerald-400">คัดลอกแล้ว</span>
                                </button>
                                <a :href="`${explorerUrl}/address/${pool.address}`" target="_blank" rel="noopener"
                                   class="text-gray-600 hover:text-primary-400 transition-colors">explorer &#8599;</a>
                            </div>
                            <div class="text-gray-700 font-mono pt-0.5">{{ pool.path }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- หมายเหตุความปลอดภัย -->
            <div class="rounded-xl border border-white/10 bg-white/[0.02] p-5 text-sm">
                <h3 class="text-white font-bold mb-2">ทำไมคลัง 6 ใบถึงโอนจากหน้านี้ไม่ได้</h3>
                <p class="text-gray-400 leading-relaxed">
                    ระบบนี้ไม่มีคีย์ของกระเป๋าคลัง และจะไม่มี — เก็บไว้แค่ที่อยู่สำหรับอ่านยอดกับกระทบยอด
                    การเคลื่อนย้ายเงินจากคลังต้องเซ็นจากข้างนอกด้วย Masternode UI ที่เข้ารหัสด้วย AES-256-GCM
                    การจ่ายเงินอัตโนมัติทำผ่านกระเป๋าร้อนเท่านั้น ซึ่งถือเงินเท่าที่จำเป็นและมีวงเงินจำกัดกำกับอยู่
                </p>
            </div>
        </div>
    </AdminLayout>
</template>
