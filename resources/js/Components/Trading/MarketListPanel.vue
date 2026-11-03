<script setup>
/**
 * TPIX TRADE — MarketListPanel
 * รายการคู่เทรดคอลัมน์ซ้ายของหน้าเทรด: ไอคอนเหรียญ ราคา %24ชม. และเส้นกราฟย่อ
 *
 * ต่างจาก PairSelector (dropdown ที่แถบหัว) ตรงที่อันนี้เปิดค้างไว้ตลอด
 * ผู้ใช้จึงสลับคู่เทรดได้ใน 1 คลิก และเห็นตลาดทั้งกระดานพร้อมกัน
 *
 * ใช้ localStorage key `tpix.favPairs` ร่วมกับ PairSelector และหน้า Markets
 * → ติดดาวที่ไหนก็เห็นเหมือนกันทุกที่
 *
 * แท็บตลาดแยกตามเชนที่เทรดได้จริง (เจ้าของสั่ง 2026-11-03): USDT · BSC Chain → TPIX Chain → ราคาอ้างอิง
 * กระเป๋าอยู่เชนไหนเปิดแท็บเชนนั้นให้ แต่ไม่ซ่อนเชนอื่น — เดิม (2026-10-28) กรองตามกระเป๋า
 * คู่ BSC จึงหายทั้งกระดานเมื่อกระเป๋าอยู่เชน TPIX
 * เชนของคู่มาจากทะเบียนคู่เทรด (network_chain_id) ไม่ใช่เดาจากชื่อเหรียญ
 *
 * Developed by Xman Studio
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import CoinIcon from '@/Components/CoinIcon.vue';
import Sparkline from '@/Components/Trading/Sparkline.vue';
import { useSparklines } from '@/Composables/useSparklines';
import { useTranslation } from '@/Composables/useTranslation';
import { useWalletStore } from '@/Stores/walletStore';
import { TPIX_COIN_ICON } from '@/utils/brand';

const props = defineProps({
    /** คู่ปัจจุบันในรูปแบบ BTC/USDT */
    currentPair: { type: String, default: 'BTC/USDT' },
});

const { t } = useTranslation();
const walletStore = useWalletStore();

const FAV_KEY = 'tpix.favPairs';
const REFRESH_MS = 30000;

const TPIX_CHAIN_ID = 4289;
const BSC_CHAIN_ID = 56;

/*
 * มาตรฐานเหรียญของแต่ละเชน — ให้รู้ว่า USDT ตัวไหน
 * USDT บน BSC (BEP20) กับ USDT บนเชน TPIX เป็นคนละเหรียญ โอนข้ามกันตรงๆ ไม่ได้
 */
const TOKEN_STANDARD = { 1: 'ERC20', 56: 'BEP20', 96: 'KAP20', 137: 'Polygon', [TPIX_CHAIN_ID]: 'TPIX Chain' };
const CHAIN_SHORT = { 1: 'ETH', 56: 'BSC', 96: 'KUB', 137: 'POL', [TPIX_CHAIN_ID]: 'TPIX' };

/** แท็บคู่ดัชนี — ราคาตลาดโลก ดูกราฟได้ ส่งคำสั่งไม่ได้ */
const INDEX_MARKET = 'index';

const tickers = ref([]);
const isLoading = ref(true);
const search = ref('');
const activeFilter = ref('all'); // all | favorites | gainers | losers
const sortKey = ref('volume');   // volume | change | name
const favorites = ref([]);
/** ทะเบียนคู่เทรดที่แอดมินเปิดไว้ { pair, quote, chainId, isIndex } — ต้นทางของแท็บตลาด */
const registry = ref([]);
/** ตลาดที่กำลังดู: 'chain:56' · 'chain:4289' · 'index' — '' = ยังไม่ได้เลือก (ใช้ตลาดของคู่ปัจจุบัน) */
const activeMarket = ref('');

// ── ตลาด (แท็บด้านบน) ──────────────────────────────────────────────────────
/** เชนที่กระเป๋าอยู่ — null = ยังไม่เชื่อม */
const walletChainId = computed(() =>
    (walletStore.isConnected && Number(walletStore.chainId) > 0 ? Number(walletStore.chainId) : null)
);

function chainShortName(chainId) {
    const chain = (walletStore.supportedChains || []).find(c => Number(c.chainId) === Number(chainId));
    return chain?.shortName || CHAIN_SHORT[chainId] || String(chainId);
}

const chainMarket = chainId => `chain:${chainId}`;

/**
 * แถวนี้อยู่ตลาดไหน — แยกตาม "เทรดได้จริงบนเชนไหน" ไม่ใช่ตามสกุลหลัก
 * ⚠️ เดิมแยกตามสกุล (ทุกสกุล / USDT / TPIX) คู่ดัชนี คู่ BSC และคู่เชน TPIX ปนกันในหมวด USDT
 *    แล้วกรองเชนตามกระเป๋าอีกชั้น — กระเป๋าอยู่เชน TPIX คู่ BSC ที่เทรดได้จริงหายจากกระดานทั้งหมด
 *    (เจ้าของถาม 2026-11-03 "หน้ากระดานเทรด bsc chain หายไปไหน")
 */
function marketOf(row) {
    if (row.isIndex) return INDEX_MARKET;
    return row.chainId ? chainMarket(row.chainId) : null;
}

/**
 * แท็บตลาด: BSC ก่อน (กระดานที่เทรดได้มากที่สุด) → เชนอื่นที่แอดมินเปิด → TPIX Chain → ราคาอ้างอิง
 * TPIX Chain มีเสมอแม้ยังไม่มีคู่ — เจ้าของสั่ง: ตลาดของเชนเราต้องมีที่ของมันบนกระดาน
 * ทะเบียนว่าง (fallback ราคา Binance ล้วน) ไม่มีใครรู้เชน → ไม่มีแท็บ ไม่กรอง ดีกว่าลิสต์ว่าง
 */
const markets = computed(() => {
    const source = registry.value.length ? registry.value : tickers.value;
    if (!source.some(p => p.chainId)) return [];

    const chains = new Set(source.filter(p => !p.isIndex && p.chainId).map(p => p.chainId));
    chains.delete(TPIX_CHAIN_ID);
    const ordered = [...chains].sort((a, b) => (a === BSC_CHAIN_ID ? -1 : b === BSC_CHAIN_ID ? 1 : a - b));

    const list = ordered.map(chainId => {
        const quotes = [...new Set(source.filter(p => !p.isIndex && p.chainId === chainId).map(p => p.quote))];
        return {
            id: chainMarket(chainId),
            chainId,
            // สกุลเดียวทั้งเชน (USDT) — บอกสกุลบนปุ่มเลย ผู้ใช้จะได้รู้ว่าราคาทั้งลิสต์เป็น USDT ของเชนนี้
            label: quotes.length === 1 ? quotes[0] : chainShortName(chainId),
            net: `${chainShortName(chainId)} Chain`,
            standard: TOKEN_STANDARD[chainId] || '',
        };
    });
    list.push({ id: chainMarket(TPIX_CHAIN_ID), chainId: TPIX_CHAIN_ID, label: 'TPIX Chain', net: '', standard: '', brand: true });
    if (source.some(p => p.isIndex)) list.push({ id: INDEX_MARKET, chainId: null, label: '', net: '', standard: '' });
    return list;
});

/** ตลาดของคู่ที่เปิดอยู่ — เปิดหน้ามาต้องเห็นคู่ตัวเองในลิสต์ */
const currentMarket = computed(() => {
    const row = tickers.value.find(t => t.symbol === props.currentPair);
    return row ? marketOf(row) : null;
});

/** ตลาดที่แสดงจริง — เลือกเองแล้วใช้ตัวนั้น · ยังไม่เลือก = ตลาดของคู่ปัจจุบัน → แท็บแรก */
const shownMarket = computed(() => {
    const ids = markets.value.map(m => m.id);
    if (ids.length === 0) return null;
    if (ids.includes(activeMarket.value)) return activeMarket.value;
    if (ids.includes(currentMarket.value)) return currentMarket.value;
    return ids[0];
});

const shownMarketInfo = computed(() => markets.value.find(m => m.id === shownMarket.value) || null);

function selectMarket(id) {
    activeMarket.value = id;
}

/*
 * กระเป๋าสลับเชน → ไปแท็บของเชนนั้น (ถ้ามี) แต่ไม่ซ่อนเชนอื่นอีกแล้ว
 * กดคู่ของเชนอื่นได้เสมอ ตอนส่งคำสั่งหน้าเทรดจะขอสลับเครือข่ายกระเป๋าให้เอง
 */
watch(walletChainId, (chainId) => {
    if (chainId && markets.value.some(m => m.id === chainMarket(chainId))) {
        activeMarket.value = chainMarket(chainId);
    }
});

// เปลี่ยนคู่ (กดจากลิสต์/ลิงก์) → ตามไปตลาดของคู่นั้น
watch(() => props.currentPair, () => {
    activeMarket.value = '';
});

const marketRows = computed(() => (
    shownMarket.value ? tickers.value.filter(t => marketOf(t) === shownMarket.value) : tickers.value
));

/** ตลาดนี้มีหลายสกุลหลักปนกันไหม (เช่น TPIX/USDT กับ IDTC/TPIX) — ใช่ = ทุกแถวต้องบอกสกุลของตัวเอง */
const showRowQuote = computed(() => new Set(marketRows.value.map(t => t.quote)).size > 1);

const { series, load: loadSparklines } = useSparklines();

let refreshTimer = null;

// ป้ายทั้งหมดมาจากคีย์คำแปล — สลับภาษาแล้วต้องเปลี่ยนตามทันที
const filters = [
    { id: 'all', key: 'trade.market.all' },
    { id: 'favorites', key: 'trade.market.favorites' },
    { id: 'gainers', key: 'trade.market.gainers' },
    { id: 'losers', key: 'trade.market.losers' },
];

const sorts = [
    { id: 'volume', key: 'trade.market.byVolume' },
    { id: 'change', key: 'trade.market.byChange' },
    { id: 'name', key: 'trade.market.byName' },
];

// ── รายการโปรด ──────────────────────────────────────────────────────────────
function readFavorites() {
    try {
        const raw = JSON.parse(localStorage.getItem(FAV_KEY) || '[]');
        favorites.value = Array.isArray(raw) ? raw.filter(v => typeof v === 'string') : [];
    } catch {
        favorites.value = [];
    }
}

function toggleFavorite(pair) {
    favorites.value = favorites.value.includes(pair)
        ? favorites.value.filter(p => p !== pair)
        : [...favorites.value, pair];
    try {
        localStorage.setItem(FAV_KEY, JSON.stringify(favorites.value));
    } catch {
        // โหมดส่วนตัว/โควตาเต็ม — ยังใช้งานได้ในหน้านี้ แค่ไม่ถูกจำไว้
    }
}

// ── ข้อมูลตลาด ──────────────────────────────────────────────────────────────
async function fetchTickers({ silent = false } = {}) {
    if (!silent) isLoading.value = true;

    try {
        const [tickerRes, pairRes] = await Promise.all([
            axios.get('/api/v1/market/tickers'),
            axios.get('/api/v1/market/pairs'),
        ]);

        /*
         * โหมดและเชนผูกกับ "คู่" ไม่ใช่เหรียญ — BTC/USDT บน BSC กับ BTC/TPIX บนเชน TPIX คนละคู่
         * ⚠️ เดิมเก็บตาม base_asset คู่หลังทับคู่แรก เชน/โหมดของแถวจึงสุ่มตามลำดับทะเบียน
         *    โลโก้เป็นของเหรียญ ยังหาตาม base ได้
         */
        const meta = {};
        const logos = {};
        if (pairRes.data?.success) {
            pairRes.data.data.forEach(p => {
                const pair = String(p.symbol || '').toUpperCase();
                meta[pair] = { mode: p.execution_mode, chainId: Number(p.network_chain_id) || null };
                if (p.base_logo && !logos[p.base_asset]) logos[p.base_asset] = p.base_logo;
            });

            registry.value = pairRes.data.data.map(p => ({
                pair: String(p.symbol || '').toUpperCase(),
                quote: p.quote_asset,
                chainId: Number(p.network_chain_id) || null,
                isIndex: p.execution_mode === 'index',
            }));
        }

        /*
         * ประกอบรายการให้ครบในตัวแปรนี้ก่อน แล้วค่อยใส่ลง tickers ครั้งเดียวตอนท้าย
         *
         * ⚠️ เดิมใส่ราคา Binance ลง tickers ก่อน แล้วค่อยรอราคาพูลจาก /dex/ticker (~2.2 วิ บน prod)
         *    ระหว่างรอ ลิสต์ไม่มีแถว TPIX/USDT — แถวที่ปักหมุดบนสุดหายวูบทุกรอบรีเฟรช 30 วิ
         *    (สุ่มดูเว็บจริง 70 ครั้ง หาย 3 ครั้ง) ตอนนี้ระหว่างรอ ลิสต์เดิมพร้อมราคาเดิมยังอยู่ครบ
         *
         * ราคา Binance ไม่มาในรอบนี้ = ใช้แถวเดิมไปก่อน แต่ทิ้งแถว DEX (isTpix) ไว้สร้างใหม่ให้ราคาสด
         * — ไม่งั้นแถวรอเปิดรอบก่อนจะบังแถวพูลจริงที่ชื่อคู่เดียวกันไว้ตลอด
         */
        let rows = tickers.value.filter(t => !t.isTpix);

        if (tickerRes.data?.success) {
            rows = tickerRes.data.data.map(t => {
                const pair = `${t.baseAsset}-${t.quoteAsset}`;
                return {
                    symbol: `${t.baseAsset}/${t.quoteAsset}`,
                    pair,
                    base: t.baseAsset,
                    quote: t.quoteAsset,
                    logo: logos[t.baseAsset] || null,
                    // index = ดูราคา/กราฟได้ แต่ยังส่งคำสั่งไม่ได้
                    isIndex: meta[pair.toUpperCase()]?.mode === 'index',
                    chainId: meta[pair.toUpperCase()]?.chainId || null,
                    price: parseFloat(t.price) || 0,
                    change: parseFloat(t.priceChangePercent) || 0,
                    volume: parseFloat(t.quoteVolume || 0),
                };
            });
        }

        /*
         * คู่บน TPIX DEX ไม่มีใน Binance — /market/tickers จึงไม่เคยส่งมา ต้องถามราคาจากพูลเอง
         * ⚠️ เดิมใส่แถว TPIX/USDT ปลอม ราคาตายตัว 0.10 + ป้าย "เร็วๆ นี้" ให้คู่เดียวที่เทรดได้จริงบนเชน TPIX
         *    (0.10 บังเอิญตรงพูลตอนนี้ — ไม้แรกที่ขยับราคาก็ผิดทันที)
         */
        const dexRows = pairRes.data?.success ? await fetchDexRows(pairRes.data.data) : [];
        dexRows.forEach((row) => {
            if (!rows.find(t => t.pair === row.pair)) rows.unshift(row);
        });

        if (!rows.find(t => t.base === 'TPIX')) {
            // DEX ยังไม่ซิงก์คู่ให้ — แถวรอเปิด ไม่มีราคา (ไม่แต่งตัวเลขขึ้นมาเอง)
            rows.unshift({
                symbol: 'TPIX/USDT', pair: 'TPIX-USDT', base: 'TPIX', quote: 'USDT',
                logo: logos.TPIX || TPIX_COIN_ICON, chainId: TPIX_CHAIN_ID,
                price: null, change: 0, volume: 0, isTpix: true, live: false,
            });
        }

        tickers.value = rows;
    } catch {
        // API ล่ม — คงรายการเดิมไว้ ถ้ายังไม่เคยโหลดเลยให้เหลือ TPIX ตัวเดียว
        if (tickers.value.length === 0) {
            tickers.value = [{
                symbol: 'TPIX/USDT', pair: 'TPIX-USDT', base: 'TPIX', quote: 'USDT', chainId: TPIX_CHAIN_ID,
                logo: TPIX_COIN_ICON, price: null, change: 0, volume: 0, isTpix: true, live: false,
            }];
        }
    } finally {
        isLoading.value = false;
    }
}

/** ถามราคาพูลได้ไม่เกินเท่านี้คู่ต่อรอบ — รายการรีเฟรชทุก 30 วิ ต้องไม่กินโควตา API ของผู้ใช้ */
const MAX_DEX_ROWS = 5;

/**
 * แถวของคู่บน TPIX DEX (execution_mode onchain บนเชน 4289) — ราคา/เปลี่ยนแปลง/ปริมาณจาก /dex/ticker
 * ถามราคาไม่สำเร็จ = แถวยังอยู่ (เทรดได้) แต่ราคาเป็น "—" ไม่เดาตัวเลข
 */
async function fetchDexRows(pairs) {
    const dexPairs = (Array.isArray(pairs) ? pairs : [])
        .filter(p => p?.execution_mode === 'onchain' && Number(p?.network_chain_id) === 4289 && p?.symbol)
        .slice(0, MAX_DEX_ROWS);

    return Promise.all(dexPairs.map(async (p) => {
        const [base, quote] = String(p.symbol).toUpperCase().split('-');
        const row = {
            symbol: `${base}/${quote}`,
            pair: `${base}-${quote}`,
            base,
            quote,
            logo: p.base_logo || (base === 'TPIX' ? TPIX_COIN_ICON : null),
            chainId: TPIX_CHAIN_ID,
            price: null,
            change: 0,
            volume: 0,
            // isTpix = ไม่มีเส้นกราฟย่อจาก Binance · live = เทรดได้จริงบนเชน TPIX (ไม่ขึ้นป้าย "เร็วๆ นี้")
            isTpix: true,
            live: true,
        };
        try {
            const { data } = await axios.get(`/api/v1/dex/ticker/${row.pair}`);
            if (data?.success && data.data) {
                const price = Number(data.data.price);
                row.price = Number.isFinite(price) && price > 0 ? price : null;
                row.change = Number(data.data.change_24h) || 0;
                row.volume = Number(data.data.quote_volume_24h ?? data.data.volume_24h) || 0;
            }
        } catch {
            // ราคาพูลอ่านไม่ได้รอบนี้ — โชว์ "—" รอบหน้าลองใหม่
        }
        return row;
    }));
}

// ── กรอง + จัดเรียง ─────────────────────────────────────────────────────────
const filtered = computed(() => {
    const q = search.value.trim().toUpperCase();

    // ค้นหา = ทุกตลาด — คนพิมพ์ชื่อเหรียญคาดว่าจะเจอมัน ไม่ว่าจะอยู่แท็บไหน (แต่ละแถวมีป้ายเชนบอก)
    let list = (q ? tickers.value : marketRows.value).filter(t => {
        if (q && !t.symbol.includes(q) && !t.base.includes(q)) return false;
        if (activeFilter.value === 'favorites') return favorites.value.includes(t.pair);
        if (activeFilter.value === 'gainers') return t.change > 0;
        if (activeFilter.value === 'losers') return t.change < 0;
        return true;
    });

    list = [...list].sort((a, b) => {
        if (sortKey.value === 'name') return a.symbol.localeCompare(b.symbol);
        if (sortKey.value === 'change') return b.change - a.change;
        return b.volume - a.volume;
    });

    /*
     * ลำดับการปักหมุด (บนลงล่าง): TPIX → รายการโปรด → ที่เหลือ
     *
     * ทำหลังจัดเรียงเสร็จ เพราะการปักหมุดเป็นเรื่อง "ของฉันอยู่ไหน" ไม่ใช่ผลของ
     * การจัดเรียง — ติดดาวไว้แล้วยังต้องเลื่อนหาคือดาวไม่ได้ทำหน้าที่อะไรเลย
     *
     * ตอนค้นหาไม่ปักหมุด เพราะคนพิมพ์ชื่อเหรียญมาคาดว่าผลที่ตรงที่สุดอยู่บนสุด
     */
    if (!q) {
        const favs = list.filter(t => t.base !== 'TPIX' && favorites.value.includes(t.pair));
        const rest = list.filter(t => t.base !== 'TPIX' && !favorites.value.includes(t.pair));
        const tpix = list.filter(t => t.base === 'TPIX');

        list = [...tpix, ...favs, ...rest];
    }

    return list;
});

/** แถวแรกที่ไม่ใช่รายการโปรด — ใช้ขีดเส้นคั่นให้เห็นว่ากลุ่มโปรดจบตรงไหน */
const firstNonFavoritePair = computed(() => {
    if (search.value.trim() || activeFilter.value === 'favorites') return null;

    const rows = filtered.value;
    const hasFav = rows.some(t => favorites.value.includes(t.pair));
    if (!hasFav) return null;

    return rows.find(t => t.base !== 'TPIX' && !favorites.value.includes(t.pair))?.pair ?? null;
});

/** ลิสต์ว่างเพราะอะไร — บอกให้ตรง ไม่ใช่ "ไม่พบคู่เทรด" ทุกกรณี */
const emptyMessage = computed(() => {
    if (activeFilter.value === 'favorites') return t('trade.market.emptyFavorites');
    const chainId = shownMarketInfo.value?.chainId;
    if (!search.value.trim() && chainId) return t('trade.market.emptyChain', { chain: `${chainShortName(chainId)} Chain` });
    return t('trade.market.empty');
});

/** ป้ายเชนต่อแถว — ตอนค้นหา (หลายตลาดปนกัน) หรือไม่มีแท็บให้รู้ว่าดูเชนไหนอยู่ */
const showRowChain = computed(() => Boolean(search.value.trim()) || !shownMarket.value);

/*
 * โหลดเส้นกราฟของทุกแถวที่แสดงอยู่ — ไม่ตัดทิ้ง
 *
 * ⚠️ เดิม slice(0, 40) ก่อนส่งไปขอ วัดได้จริงว่ามี 70 แถวแต่ได้กราฟแค่ 40
 *    30 แถวท้ายลิสต์ไม่มีกราฟตลอดกาล และไม่มีอะไรฟ้อง — แถวที่ไม่มีกราฟหน้าตา
 *    เหมือนแถวที่ "ยังโหลดไม่เสร็จ" ทุกประการ ผู้ใช้จึงรอไปเรื่อยๆ
 *
 *    เพดาน 40 เป็นของ "ต่อหนึ่งคำขอ" ไม่ใช่ต่อหนึ่งหน้า — useSparklines แบ่ง
 *    ก้อนให้เองอยู่แล้ว และฝั่งเซิร์ฟเวอร์ยิงขนานทั้งก้อน (~1 วิ ต่อ 40 เหรียญ)
 */
watch(filtered, (rows) => {
    const symbols = rows.filter(t => !t.isTpix).map(t => t.pair);
    if (symbols.length) loadSparklines(symbols);
}, { immediate: true });

// ── การแสดงผล ───────────────────────────────────────────────────────────────
function formatPrice(p) {
    // ไม่รู้ราคา (พูลอ่านไม่ได้ / ยังไม่เปิด) = "—" ไม่ใช่ตัวเลขที่แต่งขึ้น
    if (p === null || p === undefined || !Number.isFinite(Number(p))) return '—';
    if (p >= 1000) return p.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    if (p >= 1) return p.toFixed(2);
    if (p >= 0.01) return p.toFixed(4);
    return p.toFixed(8);
}

function formatVolume(v) {
    if (v >= 1e9) return (v / 1e9).toFixed(1) + 'B';
    if (v >= 1e6) return (v / 1e6).toFixed(1) + 'M';
    if (v >= 1e3) return (v / 1e3).toFixed(1) + 'K';
    return v.toFixed(0);
}

function selectPair(t) {
    if (t.symbol === props.currentPair) return;
    router.visit(`/trade/${t.pair}`);
}

onMounted(() => {
    readFavorites();
    fetchTickers();
    refreshTimer = setInterval(() => fetchTickers({ silent: true }), REFRESH_MS);
});

onUnmounted(() => {
    clearInterval(refreshTimer);
});
</script>

<template>
    <div class="flex flex-col h-full min-h-0">
        <!-- ค้นหา -->
        <div class="px-2.5 pt-2.5">
            <div class="relative">
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-dark-500"
                    fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input
                    v-model="search"
                    type="text"
                    :placeholder="t('trade.market.search')"
                    :aria-label="t('trade.market.searchAria')"
                    class="w-full bg-dark-800/70 border border-dark-700 rounded-lg pl-8 pr-2 py-1.5 text-white text-xs placeholder-dark-500 focus:border-primary-500 outline-none transition-colors"
                />
            </div>
        </div>

        <!--
            แท็บตลาด — ต้องเด่นที่สุดในแผงนี้

            แต่ละแท็บคือ "เทรดได้จริงบนเชนไหน" ราคาทั้งลิสต์เป็นสกุลของเชนนั้น
            (USDT · BSC Chain = USDT แบบ BEP20) ถ้าแท็บจางเท่าปุ่มอื่น ผู้ใช้จะไม่รู้ว่า
            กำลังดูกระดานไหน และไม่รู้ว่ามีอีกกระดานให้กด
        -->
        <div v-if="markets.length" class="px-2.5 pt-2.5">
            <div class="flex items-center gap-1 overflow-x-auto scrollbar-none">
                <button
                    v-for="m in markets"
                    :key="m.id"
                    type="button"
                    :data-test="`market-tab-${m.id === 'index' ? 'index' : m.chainId}`"
                    :aria-pressed="shownMarket === m.id"
                    :title="m.standard ? `${m.label} (${m.standard}) · ${m.net}` : undefined"
                    :class="['quote-tab',
                        m.brand && 'quote-tab--brand',
                        shownMarket === m.id && (m.brand ? 'quote-tab--brand-active' : 'quote-tab--active')]"
                    @click="selectMarket(m.id)"
                >
                    <!-- TPIX Chain ใช้ตราเหรียญ + สีไล่แบบโลโก้หัวเว็บ (เจ้าของสั่ง 2026-11-03: ให้โดดเด่น) -->
                    <img v-if="m.brand" :src="TPIX_COIN_ICON" alt="" class="w-3.5 h-3.5 rounded-full shrink-0" />
                    <template v-if="m.id === 'index'">{{ t('trade.market.indexTab') }}</template>
                    <template v-else>{{ m.label }}</template>
                    <span v-if="m.net" data-test="market-network" class="quote-tab__net">{{ m.net }}</span>
                </button>
            </div>
            <!-- บอกว่าแท็บนี้เทรดได้จริงไหม และกดคู่ของเชนอื่นได้ — ระบบสลับเครือข่ายกระเป๋าให้ตอนส่งคำสั่ง -->
            <p data-test="chain-scope" class="mt-1.5 text-[10px] text-dark-500 truncate">
                {{ !shownMarketInfo
                    ? t('trade.market.allChains')
                    : shownMarketInfo.chainId
                        ? t('trade.market.onChain', { chain: `${chainShortName(shownMarketInfo.chainId)} Chain` })
                        : t('trade.market.indexScope') }}
            </p>
        </div>

        <!-- ตัวกรอง -->
        <div class="px-2.5 pt-2 flex items-center gap-1">
            <button
                v-for="f in filters"
                :key="f.id"
                type="button"
                :class="['flex-1 px-1.5 py-1 rounded-md text-[11px] font-medium transition-colors',
                    activeFilter === f.id
                        ? 'bg-primary-500/20 text-primary-300 ring-1 ring-primary-500/30'
                        : 'bg-white/5 text-dark-400 hover:text-white']"
                @click="activeFilter = f.id"
            >
                <span v-if="f.id === 'favorites'" class="text-amber-400">★</span>
                {{ t(f.key) }}
            </button>
        </div>

        <!-- จัดเรียง -->
        <div class="px-2.5 py-1.5 flex items-center gap-1.5 text-[10px]">
            <span class="text-dark-500">{{ t('trade.market.sort') }}</span>
            <button
                v-for="s in sorts"
                :key="s.id"
                type="button"
                :class="['px-1.5 py-0.5 rounded transition-colors',
                    sortKey === s.id ? 'text-primary-400 font-semibold bg-primary-500/10' : 'text-dark-500 hover:text-white']"
                @click="sortKey = s.id"
            >
                {{ t(s.key) }}
            </button>
            <span class="ml-auto text-dark-600 font-mono">{{ filtered.length }}</span>
        </div>

        <!-- รายการ -->
        <div class="flex-1 min-h-0 overflow-y-auto custom-scrollbar border-t border-white/5">
            <div v-if="isLoading" class="p-2.5 space-y-2">
                <div v-for="n in 8" :key="n" class="flex items-center gap-2">
                    <div class="skeleton w-6 h-6 rounded-full"></div>
                    <div class="flex-1 space-y-1">
                        <div class="skeleton h-2.5 w-20"></div>
                        <div class="skeleton h-2 w-12"></div>
                    </div>
                    <div class="skeleton h-2.5 w-12"></div>
                </div>
            </div>

            <template v-else>
                <!-- ดาวเป็นปุ่มแยกนอกปุ่มเลือกคู่ — ปุ่มซ้อนปุ่มเป็น HTML ที่ไม่ถูกต้อง
                     และ screen reader จะอ่านแถวเพี้ยน -->
                <div
                    v-for="row in filtered"
                    :key="row.pair"
                    :class="['market-row',
                        row.symbol === currentPair && 'market-row--active',
                        row.pair === firstNonFavoritePair && 'market-row--after-favorites']"
                >
                    <button
                        type="button"
                        :class="['shrink-0 w-4 text-xs leading-none transition-colors',
                            favorites.includes(row.pair) ? 'text-amber-400' : 'text-dark-600 hover:text-amber-400']"
                        :aria-label="favorites.includes(row.pair)
                            ? t('trade.market.removeFavorite', { pair: row.symbol })
                            : t('trade.market.addFavorite', { pair: row.symbol })"
                        @click="toggleFavorite(row.pair)"
                    >{{ favorites.includes(row.pair) ? '★' : '☆' }}</button>

                    <button
                        type="button"
                        class="flex items-center gap-1.5 flex-1 min-w-0 text-left"
                        :aria-current="row.symbol === currentPair ? 'true' : undefined"
                        @click="selectPair(row)"
                    >
                        <CoinIcon
                            :symbol="row.base"
                            size="sm"
                            :src="row.logo || (row.isTpix ? TPIX_COIN_ICON : undefined)"
                        />

                        <!--
                            ชื่อเหรียญห้ามย่อ — ชื่อที่ถูกตัดคือชื่อที่อ่านผิดได้

                            เลิกใช้ truncate แล้วให้ชื่อกำหนดความกว้างของตัวเอง (w-auto)
                            ช่องว่างที่ได้มาจากการตัด "/USDT" ทิ้งพอสำหรับสัญลักษณ์ที่ยาว
                            ที่สุดที่มีอยู่ ส่วนสกุลหลักไปอยู่ที่ปุ่มหมวดด้านบนแทน
                        -->
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1">
                                <span class="text-white text-xs font-semibold whitespace-nowrap">{{ row.base }}</span>
                                <!-- โชว์สกุลหลักต่อท้ายเมื่อแถวในลิสต์มีหลายสกุลปนกัน ซึ่งชื่อเหรียญซ้ำกันได้ -->
                                <span v-if="showRowQuote || showRowChain" class="text-dark-500 text-[9px] font-mono shrink-0">{{ row.quote }}</span>
                                <!-- ผลค้นหา = หลายเชนปนกัน แต่ละแถวต้องบอกเชนของตัวเอง -->
                                <span v-if="showRowChain && !row.isIndex && row.chainId" data-test="badge-chain" class="text-[8px] px-1 py-px rounded bg-white/5 text-dark-300 font-medium shrink-0">
                                    {{ chainShortName(row.chainId) }}
                                </span>
                                <!-- คู่ดัชนี = ราคาอ้างอิง (เทรดไม่ได้) · คู่ TPIX ที่ DEX ยังไม่เปิด = เร็วๆ นี้ · คู่ DEX ที่เปิดแล้วไม่มีป้าย -->
                                <span v-if="row.isIndex" data-test="badge-index" class="text-[8px] px-1 py-px rounded bg-dark-600/40 text-dark-300 font-medium shrink-0">
                                    {{ t('trade.market.indexOnly') }}
                                </span>
                                <span v-else-if="row.isTpix && !row.live" data-test="badge-soon" class="text-[8px] px-1 py-px rounded bg-amber-500/15 text-amber-400 font-medium shrink-0">
                                    {{ t('trade.market.soon') }}
                                </span>
                            </span>
                            <span class="block text-[9px] text-dark-500 font-mono">Vol {{ formatVolume(row.volume) }}</span>
                        </span>

                        <!-- ยังไม่เคยดึง = undefined → skeleton, ดึงแล้วไม่มีข้อมูล = [] → ขีดจาง -->
                        <Sparkline
                            :points="series[row.pair] || []"
                            :is-up="row.change >= 0"
                            :width="44"
                            :height="20"
                            :loading="!row.isTpix && series[row.pair] === undefined"
                        />

                        <span class="text-right w-[74px] shrink-0">
                            <span class="block text-white text-[11px] font-mono">{{ formatPrice(row.price) }}</span>
                            <span :class="['block text-[10px] font-mono', row.change >= 0 ? 'text-trading-green' : 'text-trading-red']">
                                {{ row.change >= 0 ? '+' : '' }}{{ row.change.toFixed(2) }}%
                            </span>
                        </span>
                    </button>
                </div>

                <p v-if="filtered.length === 0" data-test="market-empty" class="text-dark-500 text-xs text-center py-10 px-4">
                    {{ emptyMessage }}
                </p>
            </template>
        </div>
    </div>
</template>

<style scoped>
.market-row {
    @apply w-full flex items-center gap-1.5 px-2.5 py-1.5 border-l-2 border-transparent transition-colors;
    @apply hover:bg-white/5;
}

.market-row--active {
    @apply bg-primary-500/10 border-l-primary-500;
}

/* เส้นคั่นจบกลุ่มรายการโปรด — ไม่ต้องมีหัวข้อมากินความสูงของแผงที่แคบอยู่แล้ว */
.market-row--after-favorites {
    @apply border-t border-amber-500/20;
}

/*
 * ปุ่มหมวดสกุลหลัก — ตัวที่เลือกอยู่ต้องอ่านออกในพริบตา
 * ใช้พื้นทึบ + ตัวหนา + เงาเรือง ไม่ใช่แค่เปลี่ยนสีตัวอักษรเหมือนปุ่มอื่นในแผงนี้
 */
.quote-tab {
    @apply shrink-0 px-2.5 py-1 rounded-md text-[11px] font-semibold whitespace-nowrap transition-all;
    @apply bg-white/5 text-dark-400 ring-1 ring-transparent hover:text-white hover:bg-white/10;
}

.quote-tab--active {
    @apply bg-primary-500 text-white ring-primary-400/60;
    box-shadow: 0 0 12px rgb(var(--c-primary-500) / 0.35);
}

/*
 * TPIX Chain — สีไล่ม่วง → ฟ้า → ส้ม แบบโลโก้หัวเว็บ (theme.extend.backgroundImage.brand-gradient)
 * ยังไม่เลือก = พื้นไล่สีอ่อน + ขอบไล่สีเต็ม ให้เด่นกว่าแท็บอื่นตลอดเวลา · เลือกแล้ว = พื้นไล่สีเต็มปุ่ม + เรือง
 */
.quote-tab--brand {
    @apply inline-flex items-center gap-1 text-white;
    background:
        linear-gradient(135deg, rgba(139, 92, 246, 0.4) 0%, rgba(6, 182, 212, 0.32) 50%, rgba(249, 115, 22, 0.32) 100%) padding-box,
        linear-gradient(135deg, #8b5cf6 0%, #06b6d4 50%, #f97316 100%) border-box;
    border: 1px solid transparent;
    box-shadow: 0 0 10px rgba(139, 92, 246, 0.3);
}

.quote-tab--brand:hover {
    background:
        linear-gradient(135deg, rgba(139, 92, 246, 0.6) 0%, rgba(6, 182, 212, 0.5) 50%, rgba(249, 115, 22, 0.5) 100%) padding-box,
        linear-gradient(135deg, #8b5cf6 0%, #06b6d4 50%, #f97316 100%) border-box;
}

.quote-tab--brand-active,
.quote-tab--brand-active:hover {
    @apply text-white;
    background: linear-gradient(135deg, #8b5cf6 0%, #06b6d4 50%, #f97316 100%);
    box-shadow: 0 0 14px rgba(6, 182, 212, 0.45), 0 0 22px rgba(139, 92, 246, 0.3);
    text-shadow: 0 1px 2px rgb(0 0 0 / 0.35);
}

/* มาตรฐานเหรียญต่อท้ายชื่อสกุล — เล็กกว่าแต่ยังอ่านออกบนพื้นทึบของปุ่มที่เลือก */
.quote-tab__net {
    @apply ml-1 px-1 py-px rounded text-[9px] font-medium bg-black/20 opacity-80;
}

.scrollbar-none::-webkit-scrollbar { display: none; }
.scrollbar-none { scrollbar-width: none; }
</style>
