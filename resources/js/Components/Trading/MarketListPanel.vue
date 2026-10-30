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
 * กรองตามเชนที่กระเป๋าอยู่ (เจ้าของสั่ง 2026-10-28) — ไม่เชื่อมกระเป๋า = เห็นทุกเชน
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

const QUOTE_KEY = 'tpix.marketQuote';

const TPIX_CHAIN_ID = 4289;

/*
 * หมวด TPIX = คู่ที่ตั้งราคาเป็น TPIX (เช่น XXX/TPIX) — โชว์ปุ่มไว้เสมอแม้ยังไม่มีคู่
 * เจ้าของสั่ง: ตลาดหลักของเชนเราต้องมีที่ของมันบนกระดาน ไม่ใช่รอให้แอดมินเพิ่มคู่แรกก่อนถึงจะโผล่
 */
const TPIX_QUOTE = 'TPIX';

/*
 * มาตรฐานเหรียญของแต่ละเชน — ต่อท้ายปุ่มหมวดให้รู้ว่า USDT ตัวไหน
 * USDT บน BSC (BEP20) กับ USDT บนเชน TPIX เป็นคนละเหรียญ โอนข้ามกันตรงๆ ไม่ได้
 */
const TOKEN_STANDARD = { 1: 'ERC20', 56: 'BEP20', 96: 'KAP20', 137: 'Polygon', [TPIX_CHAIN_ID]: 'TPIX Chain' };
const CHAIN_SHORT = { 1: 'ETH', 56: 'BSC', 96: 'KUB', 137: 'POL', [TPIX_CHAIN_ID]: 'TPIX' };

const tickers = ref([]);
const isLoading = ref(true);
const search = ref('');
const activeFilter = ref('all'); // all | favorites | gainers | losers
const sortKey = ref('volume');   // volume | change | name
const favorites = ref([]);
/** สกุลหลักที่กำลังดูอยู่ — '' = ทุกสกุล */
const activeQuote = ref('');
/** ทะเบียนคู่เทรดที่แอดมินเปิดไว้ { pair, quote, chainId, isIndex } — ต้นทางของปุ่มหมวด */
const registry = ref([]);

// ── เชน ────────────────────────────────────────────────────────────────────
/** เชนที่กระเป๋าอยู่ — null = ยังไม่เชื่อม (เห็นทุกเชน) */
const activeChainId = computed(() =>
    (walletStore.isConnected && Number(walletStore.chainId) > 0 ? Number(walletStore.chainId) : null)
);

function chainShortName(chainId) {
    const chain = (walletStore.supportedChains || []).find(c => Number(c.chainId) === Number(chainId));
    return chain?.shortName || CHAIN_SHORT[chainId] || String(chainId);
}

const activeChainName = computed(() => (activeChainId.value ? chainShortName(activeChainId.value) : ''));

/**
 * แถวนี้อยู่บนเชนที่กำลังดูไหม
 * ทะเบียนว่าง (fallback ราคา Binance ล้วน) ไม่มีใครรู้เชน — ไม่กรอง ดีกว่าลิสต์ว่างทั้งกระดาน
 */
function onActiveChain(row, hasChainInfo) {
    if (!activeChainId.value || !hasChainInfo) return true;
    return row.chainId === activeChainId.value;
}

const tickersHaveChain = computed(() => tickers.value.some(t => t.chainId));
const chainRows = computed(() => tickers.value.filter(t => onActiveChain(t, tickersHaveChain.value)));

/** ที่มาของปุ่มหมวด: ทะเบียนคู่เทรดของเชนนี้ (ทะเบียนว่าง = ใช้แถวราคาแทน) */
const quoteSource = computed(() => {
    if (registry.value.length === 0) return chainRows.value;
    const hasChain = registry.value.some(p => p.chainId);
    return registry.value.filter(p => onActiveChain(p, hasChain));
});

/*
 * สกุลหลักทั้งหมดของเชนนี้ — มาจากคู่ที่แอดมินเปิดจริง ไม่ได้เขียนตายไว้
 * แอดมินเพิ่มคู่ THB เมื่อไหร่ ปุ่มหมวด THB ก็โผล่เอง เรียงตามจำนวนคู่ สกุลที่ใช้เยอะสุดอยู่ซ้ายสุด
 * TPIX ต่อท้ายเสมอถ้ายังไม่มีคู่ไหนใช้
 */
const quoteAssets = computed(() => {
    const counts = {};
    quoteSource.value.forEach(p => {
        if (p.quote) counts[p.quote] = (counts[p.quote] || 0) + 1;
    });
    const quotes = Object.keys(counts).sort((a, b) => counts[b] - counts[a]);
    return quotes.includes(TPIX_QUOTE) ? quotes : [...quotes, TPIX_QUOTE];
});

/**
 * มาตรฐานเหรียญของสกุลหลักนี้ เช่น USDT → BEP20
 * นับเฉพาะคู่ที่เทรดได้จริง (คู่ดัชนีเป็นราคาตลาดโลก ไม่ได้อยู่บนเชนไหน)
 * สกุลเดียวกันอยู่หลายเชน (ไม่ได้เชื่อมกระเป๋า) = ไม่บอก เพราะบอกตัวเดียวก็ผิดอีกตัว — แต่ละแถวมีป้ายเชนแทน
 */
function quoteNetwork(quote) {
    const chains = new Set(
        quoteSource.value.filter(p => p.quote === quote && !p.isIndex && p.chainId).map(p => p.chainId)
    );
    if (chains.size !== 1) return '';
    const [chainId] = chains;
    return TOKEN_STANDARD[chainId] || chainShortName(chainId);
}

/** ลิสต์มีคู่เทรดได้จากหลายเชนปนกันไหม — ถ้าใช่ ทุกแถวต้องบอกเชนของตัวเอง */
const showRowChain = computed(() =>
    new Set(chainRows.value.filter(t => !t.isIndex && t.chainId).map(t => t.chainId)).size > 1
);

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

    // เชนกรองก่อนทุกอย่าง แม้ตอนค้นหา — คู่ของเชนอื่นกดเข้าไปแล้วระบบก็จะสลับกระเป๋าออกไปเชนนั้นอยู่ดี
    let list = chainRows.value.filter(t => {
        // หมวดสกุลหลักกรองก่อนเสมอ — ยกเว้นตอนค้นหา เพราะคนพิมพ์ชื่อเหรียญ
        // คาดว่าจะเจอมัน ไม่ว่าจะอยู่หมวดไหน
        if (!q && activeQuote.value && t.quote !== activeQuote.value) return false;
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
    if (!search.value.trim() && activeQuote.value === TPIX_QUOTE) return t('trade.market.emptyTpixQuote');
    if (activeChainId.value) return t('trade.market.emptyChain', { chain: activeChainName.value });
    return t('trade.market.empty');
});

/*
 * สกุลที่เคยเลือกไว้ไม่มีบนเชนนี้แล้ว (แอดมินปิดคู่ / สลับเชน) — กลับไปดูทุกสกุล
 * ไม่งั้นผู้ใช้จะเจอลิสต์ว่างเปล่าโดยไม่รู้ว่าทำไม · รอโหลดเสร็จก่อน ระหว่างโหลดมีแค่ปุ่ม TPIX
 */
watch([quoteAssets, isLoading], () => {
    if (!isLoading.value && activeQuote.value && !quoteAssets.value.includes(activeQuote.value)) {
        activeQuote.value = '';
    }
});

function selectQuote(quote) {
    activeQuote.value = quote;
    try {
        localStorage.setItem(QUOTE_KEY, quote);
    } catch {
        // โหมดส่วนตัว/โควตาเต็ม — ใช้งานต่อได้ แค่ไม่ถูกจำไว้รอบหน้า
    }
}

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
    try {
        activeQuote.value = localStorage.getItem(QUOTE_KEY) || '';
    } catch {
        activeQuote.value = '';
    }
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
            หมวดสกุลหลัก — ต้องเด่นที่สุดในแผงนี้

            เมื่อชื่อคู่ไม่มี "/USDT" ต่อท้ายแล้ว ปุ่มหมวดคือสิ่งเดียวที่บอกว่ากำลังดู
            ราคาเทียบกับสกุลไหนอยู่ ถ้ามันจางเท่าปุ่มอื่น ผู้ใช้จะอ่านราคา BTC 0.0341
            แล้วเข้าใจว่าเป็นดอลลาร์ — ตัวเลขเดียวกันคนละความหมายโดยสิ้นเชิง
        -->
        <div v-if="quoteAssets.length" class="px-2.5 pt-2.5">
            <div class="flex items-center gap-1 overflow-x-auto scrollbar-none">
                <!--
                    ปุ่ม "ทุกสกุล" มีความหมายเมื่อมีสกุลให้เลือกมากกว่าหนึ่งเท่านั้น
                    มีสกุลเดียวแล้วยังใส่ = ปุ่มสองอันที่ให้ผลเหมือนกันเป๊ะ
                -->
                <button
                    v-if="quoteAssets.length > 1"
                    type="button"
                    :class="['quote-tab', activeQuote === '' && 'quote-tab--active']"
                    @click="selectQuote('')"
                >{{ t('trade.market.allQuotes') }}</button>
                <!-- มาตรฐานเหรียญต่อท้าย (USDT · BEP20) — USDT ของแต่ละเชนเป็นคนละเหรียญกัน -->
                <button
                    v-for="quote in quoteAssets"
                    :key="quote"
                    type="button"
                    :data-test="`quote-tab-${quote}`"
                    :title="quoteNetwork(quote) ? `${quote} (${quoteNetwork(quote)})` : quote"
                    :class="['quote-tab',
                        (activeQuote === quote || (quoteAssets.length === 1 && !activeQuote)) && 'quote-tab--active']"
                    @click="selectQuote(quote)"
                >
                    {{ quote }}<span v-if="quoteNetwork(quote)" data-test="quote-network" class="quote-tab__net">{{ quoteNetwork(quote) }}</span>
                </button>
            </div>
            <!-- บอกว่ากำลังดูคู่ของเชนไหน — ลิสต์สั้นลงเพราะกรองเชน ไม่ใช่เพราะคู่หาย -->
            <p data-test="chain-scope" class="mt-1.5 text-[10px] text-dark-500 truncate">
                {{ activeChainId ? t('trade.market.onChain', { chain: activeChainName }) : t('trade.market.allChains') }}
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
                                <!-- โชว์สกุลหลักต่อท้ายเฉพาะตอนดูรวมทุกสกุล ซึ่งชื่อเหรียญซ้ำกันได้ -->
                                <span v-if="!activeQuote" class="text-dark-500 text-[9px] font-mono shrink-0">{{ row.quote }}</span>
                                <!-- ไม่ได้เชื่อมกระเป๋า = หลายเชนปนกัน แต่ละแถวต้องบอกเชนของตัวเอง -->
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

/* มาตรฐานเหรียญต่อท้ายชื่อสกุล — เล็กกว่าแต่ยังอ่านออกบนพื้นทึบของปุ่มที่เลือก */
.quote-tab__net {
    @apply ml-1 px-1 py-px rounded text-[9px] font-medium bg-black/20 opacity-80;
}

.scrollbar-none::-webkit-scrollbar { display: none; }
.scrollbar-none { scrollbar-width: none; }
</style>
