/**
 * TPIX TRADE - useHomeLiveStats
 * ข้อมูลสดของหน้าแรกแบบธรรมดา (HomeClassic) — ทุกตัวเลขบนหน้ามาจาก API จริง ไม่พิมพ์ค่าตายตัว
 *
 *  - /api/v1/tpix/price          ราคา TPIX + ที่มาของราคา
 *  - /api/v1/validators/stats    บล็อกล่าสุด / validator ออนไลน์ / เวลาบล็อก
 *  - /api/v1/masternode/stats    สัญญา Masternode deploy แล้วหรือยัง + ที่อยู่สัญญา
 *  - /api/v1/masternode/tiers    ระดับโหนด อ่านจากสัญญา (source: chain) หรือ config สำรอง
 *  - /api/v1/token-sale          รอบขายและช่องทางจ่ายเงินที่เปิดจริง
 *
 * แต่ละตัวโหลดแยกกัน — ตัวไหนล้มก็แสดง "—" เฉพาะช่องนั้น ไม่ทำให้ทั้งหน้าพัง
 * บล็อก/validator/ราคา รีเฟรชทุก 30 วิ (ฝั่งเซิร์ฟเวอร์แคช 30–60 วิอยู่แล้ว) และหยุดเมื่อแท็บถูกซ่อน
 *
 * Developed by Xman Studio
 */

import { ref, onMounted, onBeforeUnmount } from 'vue';

const REFRESH_MS = 30000;

// ค่าสำรองตอนอ่าน API ระดับโหนดไม่ได้ — ตรงกับ config/masternode.php + เพดาน APR ในสัญญา NodeRegistryV2
const FALLBACK_TIERS = [
    { name: 'Light', min_stake: 10_000, lock_days: 7, max_nodes: 0, active_nodes: 0, apr_cap_bps: 600, source: 'config' },
    { name: 'Sentinel', min_stake: 100_000, lock_days: 30, max_nodes: 500, active_nodes: 0, apr_cap_bps: 900, source: 'config' },
    { name: 'Guardian', min_stake: 1_000_000, lock_days: 90, max_nodes: 100, active_nodes: 0, apr_cap_bps: 1200, source: 'config' },
    { name: 'Validator', min_stake: 10_000_000, lock_days: 180, max_nodes: 21, active_nodes: 0, apr_cap_bps: 2000, source: 'config' },
];

const TIMEOUT_MS = 12000;

/**
 * คำขอแต่ละตัวมีหมดเวลาของตัวเอง — API ที่ค้างไม่ตอบต้องกลายเป็น "—" ไม่ใช่โครงโหลดค้างตลอดไป
 * และยังถูกยกเลิกตาม signal ของหน้าเมื่อผู้ใช้ออกจากหน้า (ได้ AbortError ซึ่งไม่นับเป็นความล้มเหลว)
 */
async function getJson(url, signal) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(new Error('timeout')), TIMEOUT_MS);
    const onAbort = () => ctrl.abort(signal.reason);
    if (signal?.aborted) onAbort();
    else signal?.addEventListener('abort', onAbort, { once: true });
    try {
        const r = await fetch(url, { headers: { Accept: 'application/json' }, signal: ctrl.signal });
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const json = await r.json();
        if (!json?.success) throw new Error('API error');
        return json.data;
    } finally {
        clearTimeout(timer);
        signal?.removeEventListener('abort', onAbort);
    }
}

export function useHomeLiveStats() {
    const price = ref(null);       // { price, change_24h, source }
    const chain = ref(null);       // { block_height, active_validators, total_validators, block_time, chain_id }
    const node = ref(null);        // { registry_deployed, registry_address, explorer_url }
    const tiers = ref(FALLBACK_TIERS);
    const sale = ref(null);        // { phases, payment_methods } | null เมื่อไม่มีรอบขาย
    const loaded = ref({ price: false, chain: false, node: false, sale: false });
    const failed = ref({ price: false, chain: false, node: false, sale: false });

    let timer = null;
    let controller = null;

    function mark(key, ok) {
        loaded.value = { ...loaded.value, [key]: true };
        failed.value = { ...failed.value, [key]: !ok };
    }

    async function loadPrice(signal) {
        try {
            const d = await getJson('/api/v1/tpix/price', signal);
            price.value = { price: Number(d.price), change_24h: Number(d.change_24h || 0), source: d.source };
            mark('price', true);
        } catch (e) {
            if (e.name !== 'AbortError') mark('price', price.value !== null);
        }
    }

    async function loadChain(signal) {
        try {
            const d = await getJson('/api/v1/validators/stats', signal);
            chain.value = {
                block_height: Number(d.block_height) || 0,
                // null = เชนไม่ได้บอก (ถอดลายเซ็นไม่ได้) — หน้าแรกต้องโชว์ "—" ไม่ใช่ "0 / 4"
                active_validators: d.active_validators == null ? null : Number(d.active_validators),
                total_validators: d.total_validators == null ? null : Number(d.total_validators),
                block_time: Number(d.block_time) || 2,
                chain_id: d.chain_id ?? 4289,
                consensus: d.consensus || 'IBFT',
            };
            mark('chain', chain.value.block_height > 0);
        } catch (e) {
            if (e.name !== 'AbortError') mark('chain', chain.value !== null);
        }
    }

    async function loadNode(signal) {
        try {
            const [stats, tierList] = await Promise.all([
                getJson('/api/v1/masternode/stats', signal),
                getJson('/api/v1/masternode/tiers', signal).catch(() => null),
            ]);
            node.value = {
                registry_deployed: !!stats.registry_deployed,
                // RPC ล้ม → API ตอบ registry_deployed=false ทั้งที่สัญญามีอยู่ — หน้าแรกต้องไม่ประกาศว่า "ยังไม่เปิด"
                rpc_connected: stats.rpc_connected !== false,
                registry_address: stats.registry_address || null,
                explorer_url: stats.explorer_url || 'https://explorer.tpix.online',
                // อ่านยอดกองรางวัลได้จริงและเป็นศูนย์ = ยังไม่เติมเงิน (อ่านไม่ได้ ≠ ว่าง)
                pool_unfunded: stats.reward_pool_known === true && Number(stats.reward_pool_available) === 0,
            };
            if (Array.isArray(tierList) && tierList.length) {
                // API โหมด config (สัญญายังไม่ deploy) ไม่ส่งเพดาน APR — เติมจากค่าคงที่ของสัญญาตามชื่อระดับ
                tiers.value = [...tierList]
                    .map(t => {
                        const fb = FALLBACK_TIERS.find(f => f.name === t.name) || {};
                        return { ...fb, ...t, apr_cap_bps: t.apr_cap_bps ?? fb.apr_cap_bps, min_stake: Number(t.min_stake) };
                    })
                    .sort((a, b) => a.min_stake - b.min_stake);
            }
            mark('node', true);
        } catch (e) {
            if (e.name !== 'AbortError') mark('node', false);
        }
    }

    async function loadSale(signal) {
        try {
            const d = await getJson('/api/v1/token-sale', signal);
            sale.value = d
                ? {
                    phases: (d.phases || []).map(p => ({ name: p.name, price_usd: Number(p.price_usd), status: p.status })),
                    payment_methods: d.payment_methods || {},
                }
                : null;
            mark('sale', true);
        } catch (e) {
            if (e.name !== 'AbortError') mark('sale', false);
        }
    }

    function refresh() {
        if (typeof document !== 'undefined' && document.hidden) return;
        const signal = controller?.signal;
        loadPrice(signal);
        loadChain(signal);
    }

    function onVisibility() {
        if (!document.hidden) refresh();
    }

    onMounted(() => {
        controller = new AbortController();
        const { signal } = controller;
        loadPrice(signal);
        loadChain(signal);
        loadNode(signal);
        loadSale(signal);
        timer = setInterval(refresh, REFRESH_MS);
        document.addEventListener('visibilitychange', onVisibility);
    });

    onBeforeUnmount(() => {
        clearInterval(timer);
        timer = null;
        controller?.abort();
        document.removeEventListener('visibilitychange', onVisibility);
    });

    return { price, chain, node, tiers, sale, loaded, failed };
}
