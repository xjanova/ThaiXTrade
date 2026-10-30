<script setup>
/**
 * SaleLaunchPanel — กล่อง "เปิดรอบขาย" บนหน้า /admin/token-sales
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * เจ้าของกำหนด: "พร้อมจำหน่ายเมื่อไหร่ ก็เริ่มเฟสการขายใหม่แต่แรกวันนั้น"
 * ═══════════════════════════════════════════════════════════════════════════
 * เดิมวันของแต่ละเฟสตั้งตายตัวไว้ล่วงหน้า แล้วระบบไม่เคยพร้อมขายตามวันนั้น
 * เฟสแรกจึงหมดอายุไปเงียบ ๆ โดยยังไม่เคยขายได้เลยสักบาท
 *
 * กล่องนี้ทำสามอย่าง:
 *   1. บอกตรง ๆ ว่ายังขาดอะไรก่อนจะขายได้ (ถามของจริง ไม่ใช่ดูแค่คอนฟิก)
 *   2. โชว์ตารางที่จะได้ถ้ากดเปิดวันนี้ — คนกดต้องเห็นผลลัพธ์ก่อนกด
 *   3. สวิตช์ให้ระบบเปิดขายเองทันทีที่พร้อมครบ (ปิดไว้เป็นค่าเริ่มต้น)
 *   4. ล็อกวันจบรอบ = แบบขั้นบันได ทุกเฟสช่วงเดียวกัน ราคาขยับเมื่อขั้นก่อนขายหมด
 *
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    launch: { type: Object, default: () => ({}) },
    // เปิดรอบขาย = เริ่มรับเงินจริง สั่งได้เฉพาะ super_admin ระดับอื่นเห็นแค่สถานะ
    canManage: { type: Boolean, default: false },
});

const readiness = computed(() => props.launch?.readiness || { ready: false, checks: [], blocking: [] });
const checks = computed(() => readiness.value.checks || []);
const preview = computed(() => props.launch?.preview || []);
const skipped = computed(() => props.launch?.skipped || []);
const launchedAt = computed(() => props.launch?.launched_at || null);
const tiered = computed(() => !!props.launch?.tiered);
const blocked = computed(() => props.launch?.blocked || null);

const passedCount = computed(() => checks.value.filter((c) => c.ok).length);

/** ยืนยันสองชั้นก่อนเปิดทั้งที่ยังไม่พร้อม — เปิดขายคือรับเงินจริงจากคนจริง */
const confirmingForce = ref(false);

const form = useForm({
    sale_id: props.launch?.sale_id || null,
    start_at: '',
    force: false,
});

function submitLaunch(force = false) {
    form.force = force;
    form.post('/admin/token-sales/launch', {
        preserveScroll: true,
        onFinish: () => { confirmingForce.value = false; },
    });
}

function toggleAuto() {
    router.post('/admin/token-sales/auto-launch', { armed: !props.launch?.armed }, { preserveScroll: true });
}

/*
 * วันจบรอบ — ช่อง date ส่งแค่ YYYY-MM-DD เซิร์ฟเวอร์ตีเป็นสิ้นวันนั้นเอง
 * แปลงจาก ISO ด้วยเวลาท้องถิ่นของเครื่อง ไม่ใช่ slice(0,10) ของ ISO ที่เป็น UTC
 * (23:59 เวลาไทย = 16:59Z วันเดียวกัน แต่ถ้าเซิร์ฟเวอร์ส่ง Z มา slice จะได้วันผิดในบางเขตเวลา)
 */
function toDateInput(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

const roundForm = useForm({ round_ends_at: toDateInput(props.launch?.round_ends_at) });

function saveRoundEnd(clear = false) {
    if (clear) roundForm.round_ends_at = '';
    roundForm.post('/admin/token-sales/round-end', { preserveScroll: true });
}

function fmtDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('th-TH', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}
</script>

<template>
    <div class="glass-dark rounded-xl border border-white/10 p-5 mb-4">
        <!-- หัวกล่อง -->
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <h3 class="text-lg font-bold text-white flex items-center gap-2">
                    <span>🚦</span> เปิดรอบขาย
                </h3>
                <p class="text-xs text-gray-400 mt-1">
                    เฟสแรกเริ่มนับจากวันที่กดเปิด ไม่ใช่วันที่ตั้งไว้ล่วงหน้า
                </p>
            </div>

            <div
                class="px-3 py-1 rounded-full text-xs font-semibold"
                :class="launchedAt
                    ? 'bg-green-500/15 text-green-400'
                    : readiness.ready
                        ? 'bg-blue-500/15 text-blue-400'
                        : 'bg-yellow-500/15 text-yellow-400'"
            >
                {{ launchedAt ? 'เปิดขายแล้ว' : readiness.ready ? 'พร้อมเปิดขาย' : `ยังไม่พร้อม (${passedCount}/${checks.length})` }}
            </div>
        </div>

        <p v-if="launchedAt" class="text-xs text-gray-400 mb-4">
            เปิดขายครั้งแรกเมื่อ {{ fmtDate(launchedAt) }} — กดเปิดอีกครั้งจะเป็นการตั้งวันใหม่ทับของเดิม
        </p>

        <!-- รายการความพร้อม -->
        <div class="space-y-2 mb-5">
            <div
                v-for="c in checks"
                :key="c.key"
                class="flex items-start gap-3 p-3 rounded-lg"
                :class="c.ok ? 'bg-white/[0.03]' : 'bg-yellow-500/[0.06] border border-yellow-500/20'"
            >
                <span class="text-sm mt-0.5 shrink-0">{{ c.ok ? '✅' : '⛔' }}</span>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-medium" :class="c.ok ? 'text-gray-200' : 'text-yellow-300'">
                        {{ c.label }}
                    </div>
                    <div class="text-xs text-gray-400 mt-0.5 break-words">{{ c.detail }}</div>
                    <div v-if="!c.ok" class="text-xs text-yellow-400/80 mt-1">→ {{ c.fix }}</div>
                </div>
            </div>
        </div>

        <!-- วันจบรอบ — ล็อกไว้ = แบบขั้นบันได -->
        <div class="mb-5 p-3 rounded-lg bg-white/[0.03] border border-white/10">
            <div class="flex flex-wrap items-center gap-2">
                <label class="text-sm font-medium text-gray-200">วันจบรอบขาย</label>
                <template v-if="canManage">
                    <input v-model="roundForm.round_ends_at" type="date" class="trading-input text-xs py-1.5" />
                    <button
                        class="px-3 py-1.5 text-xs rounded-lg bg-primary-500/20 text-primary-300 hover:bg-primary-500/30 disabled:opacity-40"
                        :disabled="roundForm.processing || !roundForm.round_ends_at"
                        @click="saveRoundEnd(false)"
                    >บันทึก</button>
                    <button
                        v-if="launch.round_ends_at"
                        class="px-3 py-1.5 text-xs rounded-lg text-gray-400 hover:text-white"
                        :disabled="roundForm.processing"
                        @click="saveRoundEnd(true)"
                    >ยกเลิกวันจบ</button>
                </template>
            </div>
            <p class="text-xs text-gray-400 mt-1.5">
                <template v-if="tiered">
                    ล็อกไว้ {{ fmtDate(launch.round_ends_at) }} — ทุกเฟสเปิดช่วงเดียวกันถึงวันนี้
                    ราคาขยับขึ้นเมื่อขั้นก่อนหน้าขายหมด (เปิดช้าแค่ไหนก็จบวันนี้)
                </template>
                <template v-else>
                    ไม่ได้ล็อก — เฟสต่อกันตามเวลา ยาวเฟสละ duration_days
                </template>
            </p>
            <p v-if="roundForm.errors.round_ends_at" class="text-xs text-red-400 mt-1">{{ roundForm.errors.round_ends_at }}</p>
            <p v-if="blocked" class="text-xs text-yellow-400 mt-1">⛔ {{ blocked }}</p>
        </div>

        <!-- ตารางที่จะได้ถ้ากดเปิดวันนี้ -->
        <div v-if="preview.length" class="mb-5">
            <div class="text-sm font-semibold text-white mb-2">
                ตารางที่จะได้ถ้าเปิดวันนี้{{ tiered ? ' (ขั้นบันได — เปิดทีละขั้นตามลำดับ)' : '' }}
            </div>
            <div class="overflow-x-auto rounded-lg border border-white/10">
                <table class="w-full text-xs">
                    <thead class="bg-white/5 text-gray-400">
                        <tr>
                            <th class="text-left px-3 py-2">เฟส</th>
                            <th class="text-right px-3 py-2">ราคา</th>
                            <th class="text-right px-3 py-2">ยาว</th>
                            <th class="text-left px-3 py-2">เริ่ม</th>
                            <th class="text-left px-3 py-2">สิ้นสุด</th>
                            <th class="text-left px-3 py-2">สถานะ</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <tr v-for="row in preview" :key="row.order">
                            <td class="px-3 py-2 text-white">{{ row.order }}. {{ row.name }}</td>
                            <td class="px-3 py-2 text-right text-gray-300">${{ row.price_usd }}</td>
                            <td class="px-3 py-2 text-right text-gray-300">{{ row.days }} วัน</td>
                            <td class="px-3 py-2 text-gray-300">{{ fmtDate(row.starts_at) }}</td>
                            <td class="px-3 py-2 text-gray-300">{{ fmtDate(row.ends_at) }}</td>
                            <td class="px-3 py-2">
                                <span
                                    class="px-2 py-0.5 rounded-full"
                                    :class="row.status === 'active' ? 'bg-green-500/15 text-green-400' : 'bg-gray-500/15 text-gray-400'"
                                >{{ row.status === 'active' ? 'เปิดขาย' : (tiered ? 'รอขั้นก่อนหน้าหมด' : 'รอคิว') }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p v-for="s in skipped" :key="s.name" class="text-xs text-yellow-400/80 mt-2">
                ข้าม "{{ s.name }}" — {{ s.reason }} (ไม่เลื่อนวันของเฟสที่มีคนซื้อไปแล้ว)
            </p>
        </div>

        <p v-else class="text-xs text-gray-400 mb-5">
            ยังไม่มีเฟสที่ตั้งวันใหม่ได้ — สร้างเฟสก่อน หรือทุกเฟสขายหมดแล้ว
        </p>

        <!-- ปุ่มลงมือ -->
        <p v-if="!canManage" class="pt-4 border-t border-white/10 text-xs text-amber-300">
            เปิดรอบขายคือเริ่มรับเงินจริง สั่งได้เฉพาะ Super Admin
        </p>
        <div v-else class="flex flex-wrap items-center gap-3 pt-4 border-t border-white/10">
            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-400">เริ่มวันที่</label>
                <input
                    v-model="form.start_at"
                    type="datetime-local"
                    class="trading-input text-xs py-1.5"
                    :placeholder="'เว้นว่าง = เดี๋ยวนี้'"
                />
                <span class="text-xs text-gray-500">เว้นว่าง = เดี๋ยวนี้</span>
            </div>

            <button
                class="btn-primary px-4 py-2 text-sm font-semibold disabled:opacity-40"
                :disabled="form.processing || !preview.length || !readiness.ready"
                @click="submitLaunch(false)"
            >
                {{ form.processing ? 'กำลังเปิด…' : 'เปิดรอบขาย' }}
            </button>

            <!--
                เปิดทั้งที่ยังไม่พร้อม — ต้องกดสองครั้ง
                ปุ่มนี้ทำให้ระบบรับเงินจริงได้ทั้งที่อาจยังไม่มีเหรียญให้จ่าย
            -->
            <template v-if="!readiness.ready && preview.length">
                <button
                    v-if="!confirmingForce"
                    class="px-4 py-2 text-sm rounded-lg border border-red-500/30 text-red-400 hover:bg-red-500/10"
                    @click="confirmingForce = true"
                >
                    เปิดทั้งที่ยังไม่พร้อม
                </button>
                <div v-else class="flex items-center gap-2">
                    <span class="text-xs text-red-400">แน่ใจนะ? ระบบจะรับเงินได้ทั้งที่ยังขาด: {{ (readiness.blocking || []).join(' · ') }}</span>
                    <button
                        class="px-3 py-1.5 text-xs rounded-lg bg-red-500/20 text-red-300 hover:bg-red-500/30"
                        :disabled="form.processing"
                        @click="submitLaunch(true)"
                    >ยืนยันเปิด</button>
                    <button
                        class="px-3 py-1.5 text-xs rounded-lg text-gray-400 hover:text-white"
                        @click="confirmingForce = false"
                    >ยกเลิก</button>
                </div>
            </template>
        </div>

        <!-- สวิตช์เปิดขายอัตโนมัติ -->
        <div class="flex items-start gap-3 mt-4 pt-4 border-t border-white/10">
            <button
                type="button"
                role="switch"
                :aria-checked="!!launch.armed"
                :disabled="!canManage"
                class="relative w-11 h-6 rounded-full transition-colors shrink-0 mt-0.5 disabled:cursor-not-allowed"
                :class="launch.armed ? 'bg-green-500/70' : 'bg-white/15'"
                @click="toggleAuto"
            >
                <span
                    class="absolute top-0.5 w-5 h-5 rounded-full bg-white transition-all"
                    :class="launch.armed ? 'left-[1.375rem]' : 'left-0.5'"
                />
            </button>
            <div class="text-xs">
                <div class="text-gray-200 font-medium">เปิดขายอัตโนมัติเมื่อระบบพร้อม</div>
                <p class="text-gray-400 mt-0.5">
                    เปิดไว้ = ระบบตรวจทุกชั่วโมง แล้วเริ่มรอบขายเองทันทีที่ผ่านครบทุกข้อ
                    โดยเฟสแรกเริ่มนับจากชั่วโมงนั้น · เปิดได้ครั้งเดียว ไม่เปิดซ้ำ
                </p>
            </div>
        </div>
    </div>
</template>
