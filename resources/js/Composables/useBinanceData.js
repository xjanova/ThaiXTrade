/**
 * TPIX TRADE - useBinanceData Composable
 * Real-time market data from Binance public API
 * Handles ticker, order book depth, and recent trades
 * Developed by Xman Studio
 */

import { ref, getCurrentScope, onScopeDispose } from 'vue';

const BINANCE_REST = 'https://api.binance.com/api/v3';
const BINANCE_WS = 'wss://stream.binance.com:9443/stream';

/** Binance ตอบรหัสนี้เมื่อไม่มีคู่นั้นในตลาด (พิมพ์ผิด / ถูกถอดออกแล้ว เช่น EOS, MKR) */
const BINANCE_INVALID_SYMBOL = -1121;

/*
 * จังหวะต่อสายใหม่ — เริ่ม 2 วิ แล้วเท่าตัวไปเรื่อยๆ ไม่เกิน 30 วิ
 * เน็ตล่มทั้งตึกแล้วทุกแท็บต่อใหม่ทุก 5 วิพร้อมกัน = ถล่ม Binance จนโดนแบน IP
 */
export const RECONNECT_BASE_MS = 2_000;
export const RECONNECT_MAX_MS = 30_000;

/*
 * สตรีมเงียบเกินนี้ = ตายแล้วแม้สายยังดูเหมือนเปิดอยู่
 * (แล็ปท็อปหลับแล้วตื่น / NAT ของเราเตอร์ตัดสายทิ้งเงียบๆ — onclose ไม่ยิงเลย)
 * ticker ของ Binance ส่งทุกวินาที 30 วิจึงเหลือเฟือ ไม่ตัดสายดีทิ้งผิดๆ
 */
export const STALE_AFTER_MS = 30_000;
const STALE_CHECK_MS = 5_000;

/** กลับมาที่แท็บถี่ๆ (สลับหน้าต่างไปมา) ไม่ต้องดึง REST ซ้ำทุกครั้ง */
const SNAPSHOT_MIN_GAP_MS = 5_000;

/** REST ที่ค้าง (เน็ตมือถือแย่ๆ) ต้องจบเป็น error ให้ได้ ไม่งั้นกระดานหมุน "กำลังโหลด" ไปตลอด */
const REST_TIMEOUT_MS = 15_000;

/** สัญญาณยกเลิกตามเวลา — เบราว์เซอร์เก่าที่ไม่มี AbortSignal.timeout ก็แค่ไม่มี timeout (เหมือนเดิม) */
export function restTimeoutSignal(ms = REST_TIMEOUT_MS) {
    return typeof AbortSignal !== 'undefined' && typeof AbortSignal.timeout === 'function'
        ? AbortSignal.timeout(ms)
        : undefined;
}

/** ทศนิยมสูงสุดที่ยอมแสดง — PEPE 0.00000394 ใช้ 8, เผื่อเหรียญที่เล็กกว่านั้นอีกนิด */
export const MAX_PRICE_DECIMALS = 10;

// ── การแสดงราคา ─────────────────────────────────────────────────────────────
// ใช้ร่วมกันทั้งสมุดคำสั่ง รายการเทรด และกราฟ — กฎเดิม "≥1 แสดง 2 ตำแหน่ง" ทำให้
// ask ของ XRP 1.4035 / 1.4036 / 1.4037 ขึ้นเป็น "1.40" ทั้งสามแถว แยกกันไม่ออก

/**
 * จำนวนทศนิยมที่ตัวเลขนี้ "ใช้จริง" (ตัด 0 ท้ายทิ้ง) ไม่เกิน MAX_PRICE_DECIMALS
 * ผ่าน toFixed ก่อนเพื่อกลืนเศษ floating point (0.1 + 0.2 = 0.30000000000000004 → 1 ตำแหน่ง)
 */
export function decimalsUsed(value) {
    const n = Math.abs(Number(value));
    if (!Number.isFinite(n) || n === 0) return 0;
    const fraction = n.toFixed(MAX_PRICE_DECIMALS).split('.')[1] || '';
    return fraction.replace(/0+$/, '').length;
}

/**
 * ทศนิยมตามขนาดของราคา — ได้ราว 5 หลักนัยสำคัญ อย่างน้อย 2 ตำแหน่ง
 *   67,234.5 → 2 · 1.4035 → 4 · 0.15234 → 5 · 0.00001234 (SHIB) → 9 · 0.00000394 (PEPE) → 10
 * ใช้กับค่าเดี่ยวๆ (ราคาหัวกราฟ, H/L 24 ชม., แกนราคา) ที่ไม่มีข้อมูลรอบข้างให้อ้างอิง
 */
export function decimalsForPrice(price) {
    const n = Math.abs(Number(price));
    if (!Number.isFinite(n) || n === 0) return 2;
    const magnitude = Math.floor(Math.log10(n));
    return Math.min(MAX_PRICE_DECIMALS, Math.max(2, 4 - magnitude));
}

/**
 * ทศนิยมของ "ชุด" ราคา (แถวในสมุดคำสั่ง / รายการเทรด) — ค่าเดียวทั้งคอลัมน์
 * ให้ตัวเลขเรียงตรงกัน และละเอียดเท่าที่ข้อมูลมีจริง (≈ tick size ของคู่นั้น)
 * ไม่มีข้อมูลเลย → ใช้ขนาดของราคาอ้างอิงแทน
 */
export function decimalsForPrices(prices, fallbackPrice = 0) {
    let used = -1;
    for (const p of prices || []) {
        const n = Number(p);
        if (Number.isFinite(n) && n > 0) used = Math.max(used, decimalsUsed(n));
    }
    if (used < 0) return decimalsForPrice(fallbackPrice);
    return Math.min(MAX_PRICE_DECIMALS, Math.max(2, used));
}

/** จัดรูปราคาด้วยทศนิยมที่กำหนด (ไม่ส่งมา = ตามขนาดของราคาเอง) */
export function formatMarketPrice(price, decimals) {
    const n = Number(price);
    if (!Number.isFinite(n)) return '—';
    const d = Math.min(MAX_PRICE_DECIMALS, Math.max(0, decimals ?? decimalsForPrice(n)));
    return n.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}

// ── WebSocket ที่ต่อใหม่เองอย่างมีวินัย ───────────────────────────────────────

/**
 * WebSocket ที่ต่อสายใหม่เฉพาะตอนหลุด "โดยไม่ตั้งใจ" เท่านั้น
 *
 * ทำไมต้องมี: โค้ดเดิมตั้ง onclose = setTimeout(connect, 5000) ทุกกรณี แล้วตอนปิดเองก็แค่ ws.close()
 * → onclose ของสายเก่ายิงตามมาหลังถอดคอมโพเนนต์/สลับคู่ เปิดสายของคู่เก่าขึ้นมาใหม่
 *   ที่ไม่มีใครปิดอีกเลย และวนต่อซ้ำไปตลอดอายุแท็บ (กราฟยิ่งหนัก: เปลี่ยน timeframe ครั้งเดียว
 *   สาย A ที่ถูกปิดไปนัดต่อใหม่ → ปิดสาย B เปิด C → วนทุก 5 วิไม่รู้จบ)
 *
 * กติกา:
 *  - stop() ถอด handler ทุกตัวก่อน close() + ล้าง timer ทั้งหมด → สายที่ปิดเองไม่มีทางฟื้น
 *  - handler ทุกตัวเช็คก่อนว่า "ยังเป็นสายปัจจุบันไหม" — เหตุการณ์ค้างของสายเก่าถูกทิ้ง
 *  - หลุดเอง → รอ 2s, 4s, 8s ... สูงสุด 30s; นับใหม่เมื่อได้ข้อมูลจริง (เปิดสายได้แต่ไม่มีข้อมูล ≠ หายดี)
 *  - เงียบเกิน 30s → ถือว่าตาย ปิดแล้วต่อใหม่
 *
 * @param {object} options
 * @param {string|(() => string)} options.url ที่อยู่สตรีม (อ่านใหม่ทุกครั้งที่ต่อ) — ค่าว่าง = ไม่ต่อ
 * @param {(msg: any) => void} options.onMessage รับข้อความที่ parse JSON แล้ว
 * @param {(live: boolean) => void} [options.onLiveChange] สตรีมเริ่ม/หยุดส่งข้อมูลจริง
 */
export function createLiveSocket({
    url,
    onMessage,
    onLiveChange = () => {},
    staleAfterMs = STALE_AFTER_MS,
    baseDelayMs = RECONNECT_BASE_MS,
    maxDelayMs = RECONNECT_MAX_MS,
}) {
    let socket = null;
    let running = false;
    let attempts = 0;
    let reconnectTimer = null;
    let watchdog = null;
    let lastActivityAt = 0;
    let live = false;

    function setLive(value) {
        if (live === value) return;
        live = value;
        onLiveChange(value);
    }

    /** ปิดสายปัจจุบันแบบเงียบ — ถอด handler ก่อน close() เสมอ onclose จะได้ไม่นัดต่อใหม่ */
    function drop() {
        const s = socket;
        socket = null;
        if (!s) return;
        s.onopen = null;
        s.onmessage = null;
        s.onerror = null;
        s.onclose = null;
        try { s.close(1000); } catch { /* ปิดไปแล้ว */ }
    }

    function scheduleReconnect() {
        if (!running || reconnectTimer) return;
        const delay = Math.min(maxDelayMs, baseDelayMs * 2 ** attempts);
        attempts += 1;
        reconnectTimer = setTimeout(() => {
            reconnectTimer = null;
            open();
        }, delay);
    }

    function open() {
        if (!running) return;
        const target = typeof url === 'function' ? url() : url;
        if (!target) return;

        let s;
        try {
            s = new WebSocket(target);
        } catch (err) {
            console.warn('[TPIX] WebSocket could not open:', err?.message);
            scheduleReconnect();
            return;
        }

        socket = s;
        lastActivityAt = Date.now();

        s.onmessage = (event) => {
            if (socket !== s) return;
            lastActivityAt = Date.now();
            attempts = 0;
            setLive(true);

            let msg;
            try {
                msg = JSON.parse(event.data);
            } catch (parseErr) {
                console.warn('[TPIX] WebSocket parse error:', parseErr.message);
                return;
            }
            try {
                onMessage(msg);
            } catch (handlerErr) {
                // ข้อความเดียวพังต้องไม่ล้มทั้งสาย
                console.warn('[TPIX] WebSocket handler error:', handlerErr?.message);
            }
        };

        s.onclose = (event) => {
            if (socket !== s) return;
            socket = null;
            setLive(false);
            if (event?.code !== 1000) {
                console.warn(`[TPIX] WebSocket closed (code: ${event?.code}), reconnecting...`);
            }
            scheduleReconnect();
        };

        // ไม่นัดต่อใหม่ตรงนี้ — ปิดสายแล้วให้ onclose เป็นทางเดียวที่นัด ไม่งั้นนัดซ้อนสองรอบ
        s.onerror = () => {
            if (socket !== s) return;
            try { s.close(); } catch { /* กำลังปิดอยู่แล้ว */ }
        };
    }

    function checkStale() {
        if (!running || reconnectTimer || !socket) return;
        if (Date.now() - lastActivityAt < staleAfterMs) return;
        console.warn('[TPIX] WebSocket silent too long, reconnecting...');
        drop();
        setLive(false);
        scheduleReconnect();
    }

    function stop() {
        running = false;
        if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
        if (watchdog) { clearInterval(watchdog); watchdog = null; }
        drop();
        setLive(false);
        attempts = 0;
    }

    function start() {
        stop();
        running = true;
        open();
        watchdog = setInterval(checkStale, Math.min(STALE_CHECK_MS, staleAfterMs));
    }

    /** ต่อใหม่ทันทีไม่รอ backoff — ผู้ใช้กลับมาที่แท็บแล้วเจอสายที่เงียบไปนาน */
    function reconnectNow() {
        if (!running) return;
        if (reconnectTimer) { clearTimeout(reconnectTimer); reconnectTimer = null; }
        drop();
        setLive(false);
        attempts = 0;
        open();
    }

    return {
        start,
        stop,
        reconnectNow,
        isRunning: () => running,
        isStale: () => running && (!socket || Date.now() - lastActivityAt >= staleAfterMs),
    };
}

// ── Composable ──────────────────────────────────────────────────────────────

function emptyTicker() {
    return {
        price: 0,
        priceChange: 0,
        priceChangePercent: 0,
        high: 0,
        low: 0,
        volume: 0,
    };
}

export function useBinanceData(getBinanceSymbol) {
    const ticker = ref(emptyTicker());
    const asks = ref([]);
    const bids = ref([]);
    const trades = ref([]);
    const isLoading = ref(true);
    const error = ref(null);
    /** 'invalid-symbol' = Binance ไม่มีคู่นี้ (รีเฟรชก็ไม่หาย) · 'network' = เน็ต/เซิร์ฟเวอร์มีปัญหา */
    const errorCode = ref(null);
    /** สตรีมกำลังส่งข้อมูลจริงอยู่ไหม — จุด "ข้อมูลสด" ควรอ่านค่านี้ ไม่ใช่ติดเขียวไว้ตลอด */
    const isLive = ref(false);

    let disposed = false;

    /*
     * ลำดับการโหลด — กันผลของรอบเก่าทับรอบใหม่
     * idle | pending | ok | failed | cancelled
     * cancelled = ปิดฟีดระหว่างโหลดค้าง (เช่นสลับไปคู่ TPIX ขณะรอ Binance) → คำสั่ง connect
     * ที่ตามมาจากรอบนั้นต้องไม่เปิดสาย
     */
    let fetchSeq = 0;
    let fetchState = 'idle';
    let loadedSymbol = null;
    let invalidSymbol = null;
    let streamSymbol = null;
    let lastSnapshotAt = 0;
    let snapshotInFlight = false;
    let visibilityBound = false;

    function getSymbol() {
        const raw = typeof getBinanceSymbol === 'function'
            ? getBinanceSymbol()
            : getBinanceSymbol;
        // Binance REST รับเฉพาะตัวพิมพ์ใหญ่ — /trade/btc-usdt เคยได้ -1121 ทั้งที่คู่มีอยู่จริง
        return String(raw || '').toUpperCase();
    }

    function formatPrice(price) {
        return formatMarketPrice(price);
    }

    function processDepth(rawAsks, rawBids) {
        const allTotals = [
            ...rawAsks.map(([p, q]) => parseFloat(p) * parseFloat(q)),
            ...rawBids.map(([p, q]) => parseFloat(p) * parseFloat(q)),
        ];
        const maxTotal = Math.max(...allTotals, 1);

        const toRow = ([price, qty]) => {
            const p = parseFloat(price);
            const q = parseFloat(qty);
            return {
                price: p,
                priceFormatted: formatPrice(p),
                amount: q,
                total: p * q,
                depth: Math.min(100, ((p * q) / maxTotal) * 100),
            };
        };

        asks.value = rawAsks.slice(0, 12).map(toRow);
        bids.value = rawBids.slice(0, 12).map(toRow);
    }

    function toTrade(id, price, qty, time, isBuy) {
        const p = parseFloat(price);
        return {
            id,
            price: p,
            priceFormatted: formatPrice(p),
            amount: parseFloat(qty),
            time: new Date(time).toLocaleTimeString('en-US', { hour12: false }),
            isBuy,
        };
    }

    function resetData() {
        ticker.value = emptyTicker();
        asks.value = [];
        bids.value = [];
        trades.value = [];
    }

    /** เรียก REST ของ Binance — ตอบไม่ ok ให้โยน error ที่บอกได้ว่า "คู่ไม่มีจริง" หรือ "เน็ตมีปัญหา" */
    async function requestJson(path) {
        const res = await fetch(`${BINANCE_REST}${path}`, { signal: restTimeoutSignal() });
        if (res.ok) return res.json();

        let body = null;
        try { body = await res.json(); } catch { /* ไม่ใช่ JSON */ }
        const err = new Error(body?.msg || `Binance HTTP ${res.status}`);
        err.code = body?.code === BINANCE_INVALID_SYMBOL ? 'invalid-symbol' : 'network';
        throw err;
    }

    async function loadSnapshot(symbol) {
        const s = encodeURIComponent(symbol);
        const [tickerData, depthData, tradesData] = await Promise.all([
            requestJson(`/ticker/24hr?symbol=${s}`),
            requestJson(`/depth?symbol=${s}&limit=12`),
            requestJson(`/trades?symbol=${s}&limit=20`),
        ]);
        return { tickerData, depthData, tradesData };
    }

    /**
     * @param {boolean} mergeTrades รีเฟรชกลางทาง — เก็บเทรดที่สตรีมส่งมาระหว่างรอ REST ไว้ด้วย
     *                              (ไม่งั้นรายการกระตุกถอยหลังไปเท่ากับตอนที่ยิง REST)
     */
    function applySnapshot({ tickerData, depthData, tradesData }, mergeTrades = false) {
        ticker.value = {
            price: parseFloat(tickerData.lastPrice),
            priceChange: parseFloat(tickerData.priceChange),
            priceChangePercent: parseFloat(tickerData.priceChangePercent),
            high: parseFloat(tickerData.highPrice),
            low: parseFloat(tickerData.lowPrice),
            volume: parseFloat(tickerData.quoteVolume),
        };

        processDepth(depthData?.asks || [], depthData?.bids || []);

        const fresh = [...(tradesData || [])]
            .reverse()
            .map(tr => toTrade(tr.id, tr.price, tr.qty, tr.time, !tr.isBuyerMaker));

        if (mergeTrades && fresh.length) {
            const newestId = fresh[0].id;
            const newer = trades.value.filter(tr => tr.id > newestId);
            trades.value = [...newer, ...fresh].slice(0, 20);
        } else {
            trades.value = fresh;
        }
    }

    /**
     * โหลดภาพรวมครั้งแรก — ล้มเมื่อไหร่ "ต้องโยน error" ให้ผู้เรียกรู้
     *
     * ของเดิมกลืน error ไว้เอง → try/catch ใน Trade.vue ไม่เคยทำงาน หน้าเปิดคู่ที่ Binance ไม่มี
     * (FOO-USDT, EOS/MKR ที่ถูกถอด) ก็เงียบ ไม่มีอะไรบอกผู้ใช้เลย
     * โหลดที่ถูกแทนที่/ยกเลิกระหว่างรอ → จบเงียบๆ (ไม่โยน) เพราะไม่ใช่ความผิดพลาดที่ผู้ใช้ต้องเห็น
     */
    async function fetchInitialData() {
        const symbol = getSymbol();
        const seq = ++fetchSeq;
        fetchState = 'pending';
        isLoading.value = true;
        error.value = null;
        errorCode.value = null;

        // สลับคู่ในหน้าเดิม → ล้างของคู่เก่าก่อน ไม่งั้นราคาคู่เก่าโชว์ใต้ชื่อคู่ใหม่ระหว่างโหลด
        if (symbol !== loadedSymbol) {
            resetData();
            loadedSymbol = null;
        }

        try {
            if (!symbol) {
                throw Object.assign(new Error('No trading pair'), { code: 'invalid-symbol' });
            }
            const snapshot = await loadSnapshot(symbol);
            if (disposed || seq !== fetchSeq) return;

            applySnapshot(snapshot);
            loadedSymbol = symbol;
            if (invalidSymbol === symbol) invalidSymbol = null;
            lastSnapshotAt = Date.now();
            fetchState = 'ok';
            isLoading.value = false;
        } catch (err) {
            if (disposed || seq !== fetchSeq) return;

            const code = err?.code === 'invalid-symbol' ? 'invalid-symbol' : 'network';
            if (code === 'invalid-symbol') invalidSymbol = symbol;
            fetchState = 'failed';
            errorCode.value = code;
            error.value = err?.message || 'Failed to fetch market data';
            // ข้อมูลที่ค้างอยู่ไม่ใช่ของคู่นี้แล้ว — โชว์ว่างดีกว่าโชว์ราคาผิดว่าเป็นของจริง
            resetData();
            loadedSymbol = null;
            isLoading.value = false;
            throw err;
        }
    }

    /** ดึงภาพรวมใหม่แบบเงียบ — ไม่เปิด loading (ไม่ให้กระดานกระพริบ) ล้มก็เก็บข้อมูลเดิมไว้ */
    async function refreshSnapshot() {
        const symbol = streamSymbol;
        if (!symbol || snapshotInFlight || Date.now() - lastSnapshotAt < SNAPSHOT_MIN_GAP_MS) return;

        snapshotInFlight = true;
        const seq = fetchSeq;
        try {
            const snapshot = await loadSnapshot(symbol);
            // ระหว่างรอ อาจสลับคู่ / ปิดฟีด / ถอดคอมโพเนนต์ไปแล้ว
            if (disposed || seq !== fetchSeq || !stream.isRunning() || streamSymbol !== symbol) return;
            applySnapshot(snapshot, true);
            lastSnapshotAt = Date.now();
        } catch {
            // สตรีมยังเติมข้อมูลต่อให้อยู่ — ไม่ต้องเด้งเตือนผู้ใช้เพราะรีเฟรชเบื้องหลังพลาดหนึ่งรอบ
        } finally {
            snapshotInFlight = false;
        }
    }

    function handleStreamMessage(msg) {
        const { stream: name, data } = msg || {};
        if (!data) return;

        if (name?.includes('@ticker')) {
            ticker.value = {
                price: parseFloat(data.c),
                priceChange: parseFloat(data.p),
                priceChangePercent: parseFloat(data.P),
                high: parseFloat(data.h),
                low: parseFloat(data.l),
                volume: parseFloat(data.q),
            };
        }

        if (name?.includes('@depth')) {
            processDepth(data.asks || [], data.bids || []);
        }

        if (name?.endsWith('@trade')) {
            const trade = toTrade(data.t, data.p, data.q, data.T, !data.m);
            trades.value = [trade, ...trades.value.slice(0, 19)];
        }
    }

    const stream = createLiveSocket({
        url: () => {
            if (!streamSymbol) return '';
            const s = streamSymbol.toLowerCase();
            return `${BINANCE_WS}?streams=${[`${s}@ticker`, `${s}@depth20@1000ms`, `${s}@trade`].join('/')}`;
        },
        onMessage: handleStreamMessage,
        onLiveChange: (value) => { isLive.value = value; },
    });

    /*
     * กลับมาที่แท็บ (ตื่นจากหลับ / สลับแท็บกลับมา) → สมุดคำสั่งกับรายการเทรดอาจค้างมาหลายนาที
     * ดึงภาพรวมจาก REST ใหม่ และถ้าสายเงียบไปนานก็ต่อใหม่ทันทีไม่ต้องรอ watchdog
     */
    function onVisibilityChange() {
        if (disposed || !stream.isRunning()) return;
        if (typeof document === 'undefined' || document.visibilityState !== 'visible') return;
        if (stream.isStale()) stream.reconnectNow();
        refreshSnapshot();
    }

    function bindVisibility() {
        if (visibilityBound || typeof document === 'undefined') return;
        document.addEventListener('visibilitychange', onVisibilityChange);
        visibilityBound = true;
    }

    function unbindVisibility() {
        if (!visibilityBound) return;
        document.removeEventListener('visibilitychange', onVisibilityChange);
        visibilityBound = false;
    }

    function connectWebSocket() {
        if (disposed) return;
        // โหลดรอบล่าสุดยังไม่จบ/ถูกยกเลิก → นี่คือคำสั่งค้างจากรอบเก่า ห้ามเปิดสาย
        if (fetchState === 'pending' || fetchState === 'cancelled') return;

        const symbol = getSymbol();
        // คู่ที่ Binance ไม่มี: สายเปิดได้แต่ไม่มีข้อมูลสักไบต์ แล้ว watchdog ก็ต่อใหม่วนไปเรื่อยๆ
        if (!symbol || symbol === invalidSymbol) {
            disconnectWebSocket();
            return;
        }

        streamSymbol = symbol;
        stream.start();
        bindVisibility();
    }

    function disconnectWebSocket() {
        // โหลดค้างอยู่ → ยกเลิก ผลที่กลับมาทีหลังต้องไม่ทับฟีดใหม่ (เช่นสลับไปคู่ TPIX ระหว่างรอ)
        if (fetchState === 'pending') {
            fetchSeq += 1;
            fetchState = 'cancelled';
        }
        stream.stop();
        streamSymbol = null;
        unbindVisibility();
    }

    function dispose() {
        disposed = true;
        disconnectWebSocket();
        fetchSeq += 1;
    }

    // onScopeDispose ทำงานทั้งในคอมโพเนนต์ (ตอน unmount) และใน effectScope ของเทสต์
    if (getCurrentScope()) onScopeDispose(dispose);

    return {
        ticker,
        asks,
        bids,
        trades,
        isLoading,
        error,
        errorCode,
        isLive,
        formatPrice,
        fetchInitialData,
        connectWebSocket,
        disconnectWebSocket,
    };
}
