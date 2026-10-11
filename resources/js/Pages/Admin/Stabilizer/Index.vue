<script setup>
/**
 * TPIX TRADE — หลังบ้านกองทุนพยุงราคา (TPIX Stability Reserve)
 *
 * ทำได้: เปิด/ปิดสวิตช์ · ปรับพารามิเตอร์ภายในกรอบแข็ง · บันทึกเงินฝากจาก tx hash ·
 * ดูตัวอย่างการตัดสินใจ — ทุกปุ่มที่เปลี่ยนสถานะต้องผ่านหน้าต่างยืนยันก่อน
 * หน้าเว็บเซ็นธุรกรรมไม่ได้ (การเซ็นอยู่ใน scheduler ฝั่ง CLI เท่านั้น)
 * Developed by Xman Studio
 */
import { ref, reactive, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import ConfirmDialog from '@/Components/Admin/ConfirmDialog.vue';
import { t } from '@/Composables/useTranslation';

const props = defineProps({
    state: { type: Object, default: null },
    readiness: { type: Object, default: () => ({ ready: false, checks: [] }) },
    params: { type: Object, default: () => ({}) },
    bounds: { type: Object, default: () => ({}) },
    keystore: { type: Object, default: () => ({}) },
    decisionsPage: { type: Object, default: () => ({ data: [], links: [] }) },
});

const paramLabels = {
    band_pct: 'กรอบราคา ± (%)',
    lookback_days: 'ราคาอ้างอิงย้อนหลัง (วัน)',
    trade_interval_minutes: 'ระยะห่างขั้นต่ำระหว่างไม้ (นาที)',
    opposite_cooldown_hours: 'พักก่อนเทรดฝั่งตรงข้าม (ชั่วโมง)',
    max_impact_pct: 'ผลต่อราคาสูงสุดต่อไม้ (%)',
    max_trade_usdt: 'ขนาดไม้ซื้อสูงสุด (USDT)',
    max_trade_tpix: 'ขนาดไม้ขายสูงสุด (TPIX)',
    daily_budget_usdt: 'งบซื้อต่อวัน (USDT)',
    daily_budget_tpix: 'งบขายต่อวัน (TPIX)',
    min_trade_usdt: 'ไม้ซื้อขั้นต่ำ (USDT)',
    min_trade_tpix: 'ไม้ขายขั้นต่ำ (TPIX)',
    min_pool_usdt: 'พูลต้องมี USDT อย่างน้อย',
    min_pool_tpix: 'พูลต้องมี TPIX อย่างน้อย',
    max_data_age_minutes: 'ข้อมูลเก่าได้ไม่เกิน (นาที)',
    min_history_coverage_pct: 'ประวัติราคาต้องครอบคลุมอย่างน้อย (%)',
    slippage_bps: 'ยอมรับ slippage (bps)',
};

const form = reactive({ ...props.params });
const confirmText = ref('');
const deposit = reactive({ token: 'usdt', tx_hash: '', note: '' });
const busy = ref(false);
const dialog = ref(null); // { kind, title, message, danger }
const preview = ref(null);
const previewError = ref('');
const previewLoading = ref(false);

const st = computed(() => props.state?.status ?? {});
const switchOn = computed(() => !!st.value.switch_on);
const envOn = computed(() => !!st.value.env_enabled);

const reasonText = (code) => {
    const key = `stability.reasons.${code}`;
    const text = t(key);
    return text === key ? code : text;
};
const fmtTime = (iso) => (iso ? new Date(iso).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' }) : '—');
const fmtPrice = (v) => (v === null || v === undefined || v === '' ? '—' : Number(v).toFixed(6));
const short = (h) => (h ? `${h.slice(0, 10)}…${h.slice(-6)}` : '—');

function ask(kind) {
    const texts = {
        enable: { title: 'เปิดกองทุนพยุงราคา', message: 'บอทจะเริ่มซื้อขายจริงจากกระเป๋ากองทุนในรอบถัดไป (ภายใน 5 นาที) ตามกฎและเพดานที่ตั้งไว้ ยืนยันหรือไม่?', danger: true },
        pause: { title: 'หยุดกองทุนพยุงราคา', message: 'บอทจะหยุดเซ็นธุรกรรมใหม่ทันที (ธุรกรรมที่ส่งไปแล้วยังติดตามผลต่อ) ยืนยันหรือไม่?', danger: false },
        params: { title: 'บันทึกพารามิเตอร์', message: 'ค่าที่บันทึกจะมีผลรอบถัดไปและแสดงบนหน้า /stability ทันที ค่าที่เกินกรอบจะถูกหนีบให้อยู่ในกรอบ ยืนยันหรือไม่?', danger: true },
        deposit: { title: 'บันทึกเงินฝาก', message: 'ระบบจะอ่านยอดจากธุรกรรมบนเชนเอง และยอดนี้จะแสดงต่อสาธารณะเป็นงบของกองทุน ยืนยันหรือไม่?', danger: false },
    };
    dialog.value = { kind, ...texts[kind] };
}

function confirmDialog() {
    const kind = dialog.value?.kind;
    busy.value = true;
    const done = { preserveScroll: true, onFinish: () => { busy.value = false; dialog.value = null; } };

    if (kind === 'enable') {
        router.post('/admin/stabilizer/switch', { enabled: true, confirm: confirmText.value }, done);
    } else if (kind === 'pause') {
        router.post('/admin/stabilizer/switch', { enabled: false, reason: 'manual_pause' }, done);
    } else if (kind === 'params') {
        router.put('/admin/stabilizer/params', { ...form }, done);
    } else if (kind === 'deposit') {
        router.post('/admin/stabilizer/deposits', { ...deposit }, {
            ...done,
            onSuccess: () => { deposit.tx_hash = ''; deposit.note = ''; },
        });
    } else {
        busy.value = false;
        dialog.value = null;
    }
}

async function runPreview() {
    previewLoading.value = true;
    previewError.value = '';
    try {
        const { data } = await axios.get('/admin/stabilizer/preview');
        preview.value = data.data;
    } catch (e) {
        previewError.value = e.response?.data?.error?.message || 'อ่านข้อมูลจากเชนไม่ได้ในตอนนี้';
    } finally {
        previewLoading.value = false;
    }
}
</script>

<template>
    <Head title="กองทุนพยุงราคา" />
    <AdminLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-white">กองทุนพยุงราคา (TPIX Stability Reserve)</h1>
                    <p class="text-sm text-dark-400">ทำงานตามกฎตายตัว · AI เขียนแค่รายงานรายวัน · ทุกอย่างแสดงบน <a href="/stability" target="_blank" class="text-sky-300 hover:underline">/stability</a></p>
                </div>
                <span class="px-3 py-1 rounded-full text-xs font-semibold border"
                    :class="st.active ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30' : 'bg-amber-500/15 text-amber-300 border-amber-500/30'">
                    {{ st.active ? 'กำลังทำงาน' : (envOn ? 'หยุดอยู่' : 'ปิดที่เซิร์ฟเวอร์ (.env)') }}
                </span>
            </div>

            <div v-if="!state" class="glass-dark rounded-xl p-4 text-amber-300 text-sm">อ่านข้อมูลจากเชนไม่ได้ในตอนนี้ — ยังกดหยุดกองทุนได้ตามปกติ</div>

            <!-- สวิตช์ + ความพร้อม -->
            <section class="grid lg:grid-cols-2 gap-4">
                <div class="glass-dark rounded-xl p-5 space-y-3">
                    <h2 class="font-semibold text-white">สวิตช์</h2>
                    <p class="text-sm text-dark-300">สวิตช์หลัก .env (STABILIZER_ENABLED): <b :class="envOn ? 'text-emerald-300' : 'text-rose-300'">{{ envOn ? 'เปิด' : 'ปิด' }}</b> · สวิตช์หลังบ้าน: <b :class="switchOn ? 'text-emerald-300' : 'text-amber-300'">{{ switchOn ? 'เปิด' : 'ปิด' }}</b></p>
                    <p v-if="st.pause_reason" class="text-sm text-amber-300">หยุดเพราะ: {{ st.pause_reason }} <span class="text-dark-500">({{ fmtTime(st.paused_at) }})</span></p>

                    <div v-if="switchOn">
                        <button type="button" class="btn-danger px-4 py-2" :disabled="busy" @click="ask('pause')">หยุดกองทุนทันที</button>
                    </div>
                    <div v-else class="flex flex-wrap items-center gap-2">
                        <input v-model="confirmText" type="text" class="trading-input w-40" placeholder="พิมพ์ ENABLE" autocomplete="off" />
                        <button type="button" class="btn-success px-4 py-2" :disabled="busy || confirmText !== 'ENABLE' || !readiness.ready" @click="ask('enable')">เปิดกองทุน</button>
                    </div>
                </div>

                <div class="glass-dark rounded-xl p-5">
                    <h2 class="font-semibold text-white mb-2">ความพร้อมก่อนเปิด</h2>
                    <ul class="space-y-1 text-sm">
                        <li v-for="c in readiness.checks" :key="c.key" class="flex items-start gap-2">
                            <span :class="c.ok ? 'text-emerald-400' : 'text-rose-400'">{{ c.ok ? '✓' : '✗' }}</span>
                            <span class="text-dark-200">{{ c.label }}</span>
                        </li>
                    </ul>
                    <p class="text-xs text-dark-500 mt-2">keystore: {{ keystore.configured ? 'พบไฟล์' : 'ยังไม่พบไฟล์' }} · ที่อยู่ในไฟล์: <span class="font-mono">{{ keystore.address || '—' }}</span> · passphrase: {{ keystore.passphrase_set ? 'ตั้งแล้ว' : 'ยังไม่ตั้ง' }}</p>
                </div>
            </section>

            <!-- กระเป๋า / ตลาด / งบ -->
            <section v-if="state" class="grid lg:grid-cols-3 gap-4">
                <div class="glass-dark rounded-xl p-5 text-sm space-y-1">
                    <h2 class="font-semibold text-white mb-2">กระเป๋ากองทุน</h2>
                    <p class="font-mono break-all text-white">{{ state.wallet.address || 'ยังไม่ได้ตั้ง STABILIZER_WALLET_ADDRESS' }}</p>
                    <p v-if="state.wallet.balances" class="text-dark-300">TPIX: <span class="font-mono text-white">{{ state.wallet.balances.tpix ?? '—' }}</span> · USDT: <span class="font-mono text-white">{{ state.wallet.balances.usdt ?? '—' }}</span></p>
                    <p class="text-dark-300">โหมด USDT: <b class="text-white">{{ state.usdt_backing.mode === 'bridge_proof' ? 'มีหลักฐานทุนสำรองของสะพาน' : 'ใช้ได้เฉพาะเงินฝากที่บันทึก' }}</b></p>
                    <p class="text-dark-300">ฝากแล้ว {{ state.usdt_backing.deposits_total }} · ใช้ไป {{ state.usdt_backing.spent_total }} · เหลือ {{ state.usdt_backing.deposits_remaining }} USDT</p>
                </div>
                <div class="glass-dark rounded-xl p-5 text-sm space-y-1">
                    <h2 class="font-semibold text-white mb-2">ตลาด</h2>
                    <template v-if="state.market">
                        <p class="text-dark-300">ราคาพูล <span class="font-mono text-white">{{ fmtPrice(state.market.pool_price) }}</span></p>
                        <p class="text-dark-300">อ้างอิง <span class="font-mono text-white">{{ fmtPrice(state.market.reference_price) }}</span> · กรอบ <span class="font-mono text-white">{{ fmtPrice(state.market.band_low) }}–{{ fmtPrice(state.market.band_high) }}</span></p>
                        <p class="text-dark-300">พูล {{ state.market.pool_tpix }} TPIX / {{ state.market.pool_usdt }} USDT · ประวัติครอบคลุม {{ state.market.history_coverage_pct }}%</p>
                    </template>
                    <p v-else class="text-dark-400">ยังไม่มีข้อมูลพูล</p>
                    <button type="button" class="btn-secondary px-3 py-1.5 mt-2 text-xs" :disabled="previewLoading" @click="runPreview">{{ previewLoading ? 'กำลังอ่าน…' : 'ดูว่ากฎจะตัดสินใจอย่างไรตอนนี้ (ไม่เทรด)' }}</button>
                    <p v-if="previewError" class="text-rose-300 text-xs">{{ previewError }}</p>
                    <div v-if="preview" class="mt-2 rounded-lg bg-white/5 p-2 text-xs">
                        <p class="text-white">{{ preview.action }} · {{ reasonText(preview.reason_code) }}</p>
                        <pre class="text-dark-300 whitespace-pre-wrap break-all mt-1">{{ JSON.stringify(preview.details, null, 2) }}</pre>
                    </div>
                </div>
                <div class="glass-dark rounded-xl p-5 text-sm space-y-1">
                    <h2 class="font-semibold text-white mb-2">งบวันนี้</h2>
                    <p class="text-dark-300">ใช้ซื้อ <span class="font-mono text-white">{{ state.budget_today.usdt_spent }} / {{ state.budget_today.usdt_limit }}</span> USDT</p>
                    <p class="text-dark-300">ขาย <span class="font-mono text-white">{{ state.budget_today.tpix_sold }} / {{ state.budget_today.tpix_limit }}</span> TPIX</p>
                </div>
            </section>

            <!-- พารามิเตอร์ -->
            <section class="glass-dark rounded-xl p-5">
                <h2 class="font-semibold text-white mb-1">พารามิเตอร์ของกฎ</h2>
                <p class="text-xs text-dark-400 mb-4">ปรับได้เฉพาะภายในกรอบแข็งใน config/stabilizer.php · ค่าทั้งหมดแสดงต่อสาธารณะ</p>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <label v-for="(b, name) in bounds" :key="name" class="text-sm">
                        <span class="block text-dark-300 mb-1">{{ paramLabels[name] || name }}</span>
                        <input v-model.number="form[name]" type="number" step="any" :min="b.min" :max="b.max" class="trading-input w-full" />
                        <span class="block text-xs text-dark-500 mt-0.5">{{ b.min }} – {{ b.max }} (ปริยาย {{ b.default }})</span>
                    </label>
                </div>
                <button type="button" class="btn-primary px-4 py-2 mt-4" :disabled="busy" @click="ask('params')">บันทึกพารามิเตอร์</button>
            </section>

            <!-- เงินฝาก -->
            <section class="glass-dark rounded-xl p-5">
                <h2 class="font-semibold text-white mb-1">บันทึกเงินฝากเข้ากระเป๋ากองทุน</h2>
                <p class="text-xs text-dark-400 mb-3">ใส่ tx hash ของการโอนเข้ากระเป๋ากองทุน ระบบอ่านยอดจากเชนเอง (ไม่เชื่อตัวเลขที่กรอก) — USDT ที่บันทึกตรงนี้คืองบที่บอทใช้ซื้อได้เมื่อยังไม่มีหลักฐานทุนสำรองของสะพาน</p>
                <div class="flex flex-wrap gap-2 items-end">
                    <select v-model="deposit.token" class="trading-input w-28">
                        <option value="usdt">USDT</option>
                        <option value="tpix">TPIX</option>
                    </select>
                    <input v-model="deposit.tx_hash" type="text" class="trading-input flex-1 min-w-0 font-mono" placeholder="0x… (66 ตัวอักษร)" />
                    <input v-model="deposit.note" type="text" class="trading-input w-full sm:w-56" placeholder="หมายเหตุ (ไม่บังคับ)" maxlength="255" />
                    <button type="button" class="btn-primary px-4 py-2" :disabled="busy || !/^0x[0-9a-fA-F]{64}$/.test(deposit.tx_hash)" @click="ask('deposit')">บันทึก</button>
                </div>
                <ul v-if="state?.deposits?.length" class="mt-3 text-sm space-y-1">
                    <li v-for="d in state.deposits" :key="d.tx_hash" class="flex flex-wrap gap-3">
                        <span class="font-mono text-white">{{ d.amount }} {{ d.token.toUpperCase() }}</span>
                        <a :href="d.tx_url" target="_blank" rel="noopener noreferrer" class="font-mono text-sky-300 hover:underline">{{ short(d.tx_hash) }}</a>
                        <span class="text-dark-500">{{ fmtTime(d.at) }}</span>
                    </li>
                </ul>
            </section>

            <!-- ธุรกรรม -->
            <section v-if="state" class="glass-dark rounded-xl p-5">
                <h2 class="font-semibold text-white mb-3">ธุรกรรม (ล่าสุด 50)</h2>
                <p v-if="!state.transactions.length" class="text-sm text-dark-400">ยังไม่มีธุรกรรม</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-dark-400 border-b border-white/5"><th class="py-2 pr-3">เวลา</th><th class="pr-3">ประเภท</th><th class="pr-3">ใส่</th><th class="pr-3">ได้</th><th class="pr-3">สถานะ</th><th>tx</th></tr></thead>
                        <tbody>
                            <tr v-for="tx in state.transactions" :key="tx.tx_hash" class="border-b border-white/5">
                                <td class="py-2 pr-3 text-dark-300">{{ fmtTime(tx.at) }}</td>
                                <td class="pr-3 text-white">{{ tx.purpose === 'approve' ? 'approve' : tx.side }}</td>
                                <td class="pr-3 font-mono">{{ tx.amount_in }} {{ tx.token_in?.toUpperCase() }}</td>
                                <td class="pr-3 font-mono">{{ tx.actual_out ?? tx.expected_out ?? '—' }}</td>
                                <td class="pr-3">{{ tx.status }}</td>
                                <td><a :href="tx.tx_url" target="_blank" rel="noopener noreferrer" class="font-mono text-sky-300 hover:underline">{{ short(tx.tx_hash) }}</a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- การตัดสินใจทั้งหมด -->
            <section class="glass-dark rounded-xl p-5">
                <h2 class="font-semibold text-white mb-3">บันทึกการตัดสินใจ</h2>
                <p v-if="!decisionsPage.data.length" class="text-sm text-dark-400">ยังไม่มี (บันทึกเฉพาะตอนเปิดทั้งสองสวิตช์)</p>
                <ul v-else class="divide-y divide-white/5 text-sm">
                    <li v-for="d in decisionsPage.data" :key="d.id" class="py-2">
                        <div class="flex flex-wrap gap-3">
                            <span class="text-dark-400 w-36">{{ fmtTime(d.at) }}</span>
                            <span class="font-semibold text-white w-16">{{ d.action }}</span>
                            <span class="text-dark-200 flex-1">{{ reasonText(d.reason_code) }} <span class="text-dark-500">({{ d.reason_code }})</span></span>
                            <span class="font-mono text-xs text-dark-400">{{ fmtPrice(d.pool_price) }} · {{ fmtPrice(d.band_low) }}–{{ fmtPrice(d.band_high) }}</span>
                        </div>
                    </li>
                </ul>
                <div v-if="decisionsPage.links?.length > 3" class="flex flex-wrap gap-1 mt-3">
                    <template v-for="(l, i) in decisionsPage.links" :key="i">
                        <Link v-if="l.url" :href="l.url" preserve-scroll class="px-2 py-1 rounded text-xs" :class="l.active ? 'bg-primary-500 text-white' : 'bg-white/5 text-dark-300'" v-html="l.label" />
                    </template>
                </div>
            </section>
        </div>

        <ConfirmDialog
            :show="!!dialog"
            :title="dialog?.title"
            :message="dialog?.message"
            :danger="dialog?.danger"
            :loading="busy"
            :confirm-text="busy ? 'กำลังดำเนินการ…' : 'ยืนยัน'"
            cancel-text="ยกเลิก"
            @confirm="confirmDialog"
            @cancel="dialog = null"
        />
    </AdminLayout>
</template>
