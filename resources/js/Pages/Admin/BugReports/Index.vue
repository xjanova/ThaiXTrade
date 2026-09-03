<script setup>
/**
 * TPIX TRADE — รายงานบั๊กจากแอป/เว็บ/โปรแกรมทั้งหมด (หลังบ้าน)
 *
 * เจ้าของสั่ง: "ทำระบบ bug report หลังบ้านไว้ … เพื่อให้ตรวจสอบได้ทันที ไม่เดา"
 * ข้อมูลมาจากระบบกลางของ xman studio (แอปทุกตัวในบ้านส่งเข้าที่เดียว) หน้านี้ดึง
 * เฉพาะผลิตภัณฑ์ TPIX มาแสดงพร้อม breadcrumb + สภาพแอปที่ตัวรายงานแนบมา
 *
 * Developed by Xman Studio.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
    reports: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    selected: { type: String, default: 'all' },
    fetchErrors: { type: Array, default: () => [] },
    centralAdminUrl: { type: String, default: '' },
    summary: { type: Object, default: () => ({}) },
});

const open = ref(new Set());
const search = ref('');
const copied = ref(null);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return props.reports;
    return props.reports.filter(r =>
        [r.title, r.description, r.device_id, r.os_version, r.product_version]
            .some(v => String(v || '').toLowerCase().includes(q)));
});

function toggle(id) {
    const next = new Set(open.value);
    next.has(id) ? next.delete(id) : next.add(id);
    open.value = next;
}

function pick(product) {
    router.get('/admin/bug-reports', product === 'all' ? {} : { product }, { preserveState: true, preserveScroll: true });
}

function refresh() {
    router.post('/admin/bug-reports/refresh', {}, { preserveScroll: true });
}

const typeClass = (t) => ({
    crash: 'bg-trading-red/15 text-trading-red',
    bug: 'bg-amber-400/15 text-amber-300',
    performance: 'bg-sky-400/15 text-sky-300',
}[t] || 'bg-white/10 text-dark-200');

const productLabel = (p) => ({
    'tpix-trade': 'แอป Trade', 'tpix-wallet': 'แอป Wallet', 'tpix-web': 'เว็บ', 'tpix-masternode': 'Master Node',
}[p] || p);

const when = (iso) => {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString('th-TH', { hour12: false });
};

/** ข้อความสำหรับวางให้คนไล่บั๊ก (หรือ Claude) อ่านได้ทันทีทั้งก้อน */
function asText(r) {
    const lines = [
        `#${r.id} [${r.product_name} v${r.product_version}] ${r.report_type} · ${when(r.created_at)}`,
        `title: ${r.title}`,
        `os: ${r.os_version || '-'} · device: ${r.device_id || '-'} · severity: ${r.severity || '-'}`,
        '',
        r.description,
    ];
    if (r.state && Object.keys(r.state).length) lines.push('', '--- state ---', JSON.stringify(r.state, null, 2));
    if (r.breadcrumbs?.length) lines.push('', '--- breadcrumbs ---', ...r.breadcrumbs);
    if (r.metadata && Object.keys(r.metadata).length) lines.push('', '--- metadata ---', JSON.stringify(r.metadata, null, 2));
    return lines.join('\n');
}

async function copy(r) {
    try {
        await navigator.clipboard.writeText(asText(r));
        copied.value = r.id;
        setTimeout(() => { if (copied.value === r.id) copied.value = null; }, 1500);
    } catch (_) { /* เบราว์เซอร์ไม่ให้เข้าคลิปบอร์ด */ }
}
</script>

<template>
    <Head title="รายงานบั๊ก" />
    <AdminLayout>
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-bold text-white">รายงานบั๊ก</h1>
                    <p class="text-xs text-dark-400 mt-1">
                        จากแอป Trade · แอป Wallet · เว็บ · Master Node — ส่งเข้าระบบกลาง xman studio อัตโนมัติ พร้อมเหตุการณ์ก่อนพัง
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <a v-if="centralAdminUrl" :href="centralAdminUrl" target="_blank" rel="noopener"
                       class="px-3 py-1.5 rounded-lg text-xs bg-white/5 text-dark-200 hover:bg-white/10">
                        เปิดระบบกลาง ↗
                    </a>
                    <button type="button" class="px-3 py-1.5 rounded-lg text-xs bg-primary-500/20 text-primary-300 hover:bg-primary-500/30" @click="refresh">
                        รีเฟรช
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">รายงานที่ดึงมา</p>
                    <p class="text-2xl font-bold text-white">{{ summary.total ?? 0 }}</p>
                </div>
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">24 ชั่วโมงล่าสุด</p>
                    <p class="text-2xl font-bold text-white">{{ summary.last24h ?? 0 }}</p>
                </div>
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">แอปพัง (crash)</p>
                    <p class="text-2xl font-bold text-trading-red">{{ summary.crashes ?? 0 }}</p>
                </div>
                <div class="glass-dark rounded-xl p-3">
                    <p class="text-[11px] text-dark-400">แยกตามผลิตภัณฑ์</p>
                    <p class="text-xs text-dark-200 mt-1 leading-relaxed">
                        <span v-for="(n, p) in (summary.byProduct || {})" :key="p" class="mr-2">{{ productLabel(p) }} {{ n }}</span>
                        <span v-if="!Object.keys(summary.byProduct || {}).length">—</span>
                    </p>
                </div>
            </div>

            <div v-if="fetchErrors.length" class="rounded-xl bg-trading-red/10 border border-trading-red/30 px-4 py-3 text-xs text-trading-red">
                ดึงจากระบบกลางไม่ได้: {{ fetchErrors.map(productLabel).join(', ') }} — ลองรีเฟรช หรือเปิดระบบกลางโดยตรง
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="p in ['all', ...products]" :key="p" type="button"
                    :class="['px-3 py-1 rounded-full text-xs', selected === p ? 'bg-primary-500/30 text-primary-200' : 'bg-white/5 text-dark-300 hover:bg-white/10']"
                    @click="pick(p)"
                >{{ p === 'all' ? 'ทั้งหมด' : productLabel(p) }}</button>
                <input v-model="search" type="search" placeholder="ค้นหาข้อความ / เครื่อง / รุ่น" class="trading-input ml-auto w-64 text-xs" />
            </div>

            <div v-if="!filtered.length" class="glass-dark rounded-xl p-8 text-center text-sm text-dark-400">
                ยังไม่มีรายงาน — แอปจะส่งเข้ามาเองเมื่อพัง หรือผู้ใช้กด "รายงานปัญหา" ในตั้งค่า
            </div>

            <div v-else class="space-y-2">
                <div v-for="r in filtered" :key="r.id" class="glass-dark rounded-xl overflow-hidden">
                    <button type="button" class="w-full flex items-start gap-3 px-4 py-3 text-left hover:bg-white/5" @click="toggle(r.id)">
                        <span :class="['shrink-0 mt-0.5 px-1.5 py-px rounded text-[10px] font-semibold uppercase', typeClass(r.report_type)]">{{ r.report_type }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm text-white truncate">{{ r.title }}</span>
                            <span class="block text-[11px] text-dark-400 font-mono truncate">
                                {{ productLabel(r.product_name) }} v{{ r.product_version }} · {{ r.os_version || '-' }} · {{ r.device_id ? r.device_id.slice(0, 8) : '-' }} · {{ when(r.created_at) }}
                            </span>
                        </span>
                        <span class="shrink-0 text-[11px] text-dark-500">#{{ r.id }}</span>
                    </button>

                    <div v-if="open.has(r.id)" class="border-t border-white/5 px-4 py-3 space-y-3 text-xs">
                        <div class="flex items-center justify-between">
                            <span class="text-dark-400">ความรุนแรง {{ r.severity || '-' }} · ลำดับ {{ r.priority || '-' }} · สถานะ {{ r.status || '-' }}</span>
                            <button type="button" class="px-2.5 py-1 rounded-md bg-white/5 text-dark-200 hover:bg-white/10" @click="copy(r)">
                                {{ copied === r.id ? 'คัดลอกแล้ว' : 'คัดลอกทั้งก้อน' }}
                            </button>
                        </div>
                        <pre class="whitespace-pre-wrap break-words rounded-lg bg-dark-900/60 p-3 text-dark-100 font-mono text-[11px] max-h-72 overflow-auto">{{ r.description }}</pre>

                        <div v-if="Object.keys(r.state || {}).length">
                            <p class="text-dark-400 mb-1">สภาพแอปตอนรายงาน</p>
                            <pre class="rounded-lg bg-dark-900/60 p-3 text-dark-200 font-mono text-[11px] overflow-auto">{{ JSON.stringify(r.state, null, 2) }}</pre>
                        </div>

                        <div v-if="r.breadcrumbs?.length">
                            <p class="text-dark-400 mb-1">เหตุการณ์ก่อนหน้า ({{ r.breadcrumbs.length }})</p>
                            <ol class="rounded-lg bg-dark-900/60 p-3 font-mono text-[11px] text-dark-200 space-y-0.5 max-h-72 overflow-auto">
                                <li v-for="(c, i) in r.breadcrumbs" :key="i">{{ c }}</li>
                            </ol>
                        </div>

                        <div v-if="Object.keys(r.metadata || {}).length">
                            <p class="text-dark-400 mb-1">ข้อมูลเพิ่มเติม</p>
                            <pre class="rounded-lg bg-dark-900/60 p-3 text-dark-200 font-mono text-[11px] overflow-auto">{{ JSON.stringify(r.metadata, null, 2) }}</pre>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
