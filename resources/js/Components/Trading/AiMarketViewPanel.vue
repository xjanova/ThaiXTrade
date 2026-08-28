<script setup>
/**
 * TPIX TRADE — มุมมองตลาดที่ AI สรุปไว้ ให้ผู้ใช้เห็นว่าบอทกำลังคิดอะไร
 *
 * ต่างจากแผง "คำแนะนำ AI" ที่อยู่ข้างกัน: อันนั้นเป็นข้อความให้คนอ่านเฉยๆ
 * ส่วนแผงนี้คือ **สิ่งที่มีผลต่อการเทรดจริง** ผู้ใช้จึงควรเห็นและตรวจสอบได้
 *
 * สามอย่างที่ต้องเห็นเสมอ:
 *   1. ท่าทีตลาดตอนนี้ + ความมั่นใจ (บอทเอาไปคูณขนาดไม้จริง)
 *   2. **ประเมินเมื่อไหร่** — มุมมองค้างเก่าคือสัญญาณว่ารอบวิเคราะห์ตาย
 *      ซึ่งเป็นความล้มเหลวเงียบแบบที่โปรเจกต์นี้เจอซ้ำมาหลายรอบ
 *   3. เหรียญที่คัดไว้ พร้อมเหตุผลรายตัว — ไม่ใช่แค่ "AI บอกว่าซื้อ"
 *
 * ⚠️ ไม่มีมุมมอง ≠ ระบบพัง — แปลว่าบอทกำลังใช้กฎล้วน ซึ่งปลอดภัยกว่าเดินตาม
 *    ข่าวที่หมดอายุแล้ว ต้องบอกผู้ใช้ตรงๆ ไม่ใช่ซ่อนแผงทิ้งเฉยๆ
 *
 * Developed by Xman Studio
 */
import { computed } from 'vue';

const props = defineProps({
    /** ผลจาก /api/v1/ai-bot/market-view */
    data: { type: Object, default: null },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(['refresh']);

const enabled = computed(() => props.data?.enabled === true);
const view = computed(() => props.data?.view ?? null);
const shadow = computed(() => props.data?.shadow === true);

const regimeTone = {
    risk_on: { label: 'ตลาดเปิดรับความเสี่ยง', cls: 'text-trading-green bg-trading-green/15 border-trading-green/30' },
    neutral: { label: 'ตลาดเป็นกลาง', cls: 'text-dark-200 bg-white/5 border-white/10' },
    risk_off: { label: 'ตลาดหลบความเสี่ยง', cls: 'text-trading-red bg-trading-red/15 border-trading-red/30' },
};

const regime = computed(() => regimeTone[view.value?.regime] ?? regimeTone.neutral);

const stanceTone = {
    buy: { label: 'น่าซื้อ', cls: 'text-trading-green bg-trading-green/15' },
    hold: { label: 'ถือ', cls: 'text-dark-300 bg-white/5' },
    avoid: { label: 'เลี่ยง', cls: 'text-amber-300 bg-amber-400/15' },
    exit: { label: 'ควรออก', cls: 'text-trading-red bg-trading-red/15' },
};

/** เหรียญที่ AI มีความเห็น เรียงจากคะแนนดีไปแย่ */
const coins = computed(() => Object.entries(view.value?.coins ?? {})
    .map(([symbol, c]) => ({ symbol, ...c }))
    .sort((a, b) => (Number(b.score) || 0) - (Number(a.score) || 0)));

const shortlist = computed(() => view.value?.shortlist ?? []);

/**
 * ประเมินไว้นานแค่ไหนแล้ว
 *
 * แสดงเป็น "กี่นาทีที่แล้ว" ไม่ใช่เวลาสัมบูรณ์ — ผู้ใช้ต้องรู้ว่ามันสดหรือเก่า
 * ในทันที ไม่ต้องมานั่งลบเวลาเอง (และไม่ต้องเดาว่าเป็นโซนเวลาไหน)
 */
const ageMinutes = computed(() => {
    if (!view.value?.created_at) return null;
    return Math.max(0, Math.round((Date.now() - new Date(view.value.created_at).getTime()) / 60000));
});

const ageText = computed(() => {
    const m = ageMinutes.value;
    if (m === null) return '—';
    if (m < 1) return 'เมื่อครู่';
    if (m < 60) return `${m} นาทีที่แล้ว`;
    return `${Math.floor(m / 60)} ชั่วโมง ${m % 60} นาทีที่แล้ว`;
});

/** มุมมองใกล้หมดอายุ = รอบถัดไปอาจไม่มา ต้องเตือนก่อนที่มันจะหายไปเงียบๆ */
const expiringSoon = computed(() => {
    if (!view.value?.expires_at) return false;
    return new Date(view.value.expires_at).getTime() - Date.now() < 15 * 60 * 1000;
});

const confidencePct = computed(() => Math.round((Number(view.value?.confidence) || 0) * 100));
</script>

<template>
    <div v-if="enabled" class="glass-card p-4">
        <div class="flex items-start justify-between gap-3 mb-3">
            <div>
                <h3 class="text-sm font-semibold text-white">มุมมองตลาดของ AI</h3>
                <p class="text-[11px] text-dark-400 mt-0.5">
                    สิ่งที่บอทใช้ประกอบการตัดสินใจจริง ไม่ใช่แค่คำแนะนำ
                </p>
            </div>

            <button
                type="button"
                class="text-[11px] px-2 py-1 rounded-lg bg-white/5 hover:bg-white/10 text-dark-200 transition disabled:opacity-40"
                :disabled="loading"
                @click="emit('refresh')"
            >
                {{ loading ? 'กำลังโหลด…' : 'รีเฟรช' }}
            </button>
        </div>

        <!-- ยังไม่มีมุมมอง — บอกตรงๆ ว่าใช้กฎล้วนอยู่ ไม่ใช่ซ่อนแผงทิ้ง -->
        <div v-if="!view" class="rounded-xl border border-white/10 bg-black/25 p-3.5">
            <p class="text-[12px] text-dark-300 leading-relaxed">
                {{ data?.reason || 'ยังไม่มีมุมมองล่าสุด' }}
            </p>
            <p class="text-[11px] text-dark-500 mt-1.5">
                บอทยังทำงานปกติ — ตัดสินใจจากกฎที่ตรวจย้อนหลังได้ทั้งหมด
            </p>
        </div>

        <template v-else>
            <!-- โหมดเงา: AI คิดแต่ยังไม่มีสิทธิ์แตะเงิน ต้องบอกให้ชัด -->
            <div v-if="shadow" class="mb-3 rounded-lg border border-amber-400/30 bg-amber-400/10 px-3 py-2">
                <p class="text-[11px] text-amber-200 leading-relaxed">
                    กำลังอยู่ในช่วงเก็บสถิติ — AI วิเคราะห์และบันทึกไว้ แต่<strong>ยังไม่มีผลต่อการเทรด</strong>
                </p>
            </div>

            <!-- ท่าทีรวม -->
            <div class="grid grid-cols-3 gap-2 mb-3">
                <div :class="['rounded-lg border px-2.5 py-2', regime.cls]">
                    <span class="block text-[10px] opacity-70">ท่าทีตลาด</span>
                    <span class="block text-[12px] font-semibold mt-0.5">{{ regime.label }}</span>
                </div>
                <div class="rounded-lg border border-white/10 bg-black/25 px-2.5 py-2">
                    <span class="block text-[10px] text-dark-400">ความมั่นใจ</span>
                    <span class="block text-[12px] font-semibold text-white mt-0.5 font-mono">{{ confidencePct }}%</span>
                </div>
                <div class="rounded-lg border border-white/10 bg-black/25 px-2.5 py-2">
                    <span class="block text-[10px] text-dark-400">ตัวคูณขนาดไม้</span>
                    <span class="block text-[12px] font-semibold text-white mt-0.5 font-mono">
                        {{ Number(view.size_multiplier).toFixed(2) }}×
                    </span>
                </div>
            </div>

            <p v-if="view.summary" class="text-[12px] text-dark-100 leading-relaxed mb-3 whitespace-pre-line">
                {{ view.summary }}
            </p>

            <!-- เหรียญที่คัดไว้ -->
            <div v-if="shortlist.length" class="mb-3">
                <span class="block text-[11px] text-dark-400 mb-1.5">เหรียญที่ AI คัดไว้รอบนี้</span>
                <div class="flex flex-wrap gap-1.5">
                    <span
                        v-for="pair in shortlist"
                        :key="pair"
                        class="text-[11px] font-mono px-2 py-1 rounded-lg bg-primary-500/15 text-primary-300 border border-primary-500/25"
                    >{{ pair }}</span>
                </div>
            </div>

            <!-- ความเห็นรายเหรียญ พร้อมเหตุผล -->
            <div v-if="coins.length" class="space-y-1.5 max-h-64 overflow-y-auto pr-1">
                <div
                    v-for="coin in coins"
                    :key="coin.symbol"
                    class="flex items-start gap-2 rounded-lg bg-black/25 border border-white/5 px-2.5 py-2"
                >
                    <span class="text-[11px] font-mono font-semibold text-white w-12 shrink-0">{{ coin.symbol }}</span>
                    <span :class="['text-[10px] px-1.5 py-0.5 rounded shrink-0', (stanceTone[coin.stance] || stanceTone.hold).cls]">
                        {{ (stanceTone[coin.stance] || stanceTone.hold).label }}
                    </span>
                    <span class="text-[11px] text-dark-300 leading-snug flex-1">{{ coin.why || '—' }}</span>
                    <span
                        class="text-[10px] font-mono shrink-0"
                        :class="Number(coin.score) >= 0 ? 'text-trading-green' : 'text-trading-red'"
                    >{{ Number(coin.score) >= 0 ? '+' : '' }}{{ Number(coin.score).toFixed(2) }}</span>
                </div>
            </div>

            <!-- ที่มาและความสด — ตัวชี้ว่ารอบวิเคราะห์ยังเดินอยู่จริงไหม -->
            <div class="mt-3 pt-2.5 border-t border-white/5 flex items-center justify-between gap-2">
                <span class="text-[10px] text-dark-500 font-mono">
                    {{ view.model }} · รอบ{{ view.scope === 'tactical' ? 'สั้น' : 'ใหญ่' }}
                </span>
                <span :class="['text-[10px]', expiringSoon ? 'text-amber-300' : 'text-dark-500']">
                    ประเมิน {{ ageText }}
                </span>
            </div>
        </template>
    </div>
</template>
