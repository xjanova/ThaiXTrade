/**
 * TPIX DEX (AMM บน TPIX Chain 4289) — contract addresses + ABIs
 *
 * ที่อยู่สัญญามาจาก **ทะเบียนบนเซิร์ฟเวอร์** (`GET /api/v1/dex/config`) ซึ่งสคริปต์
 * deploy-dex.js ลงทะเบียนให้เองตอน deploy — หน้าเว็บจึงเปิดเทรดได้ภายในไม่กี่นาที
 * หลัง deploy โดยไม่ต้อง build ใหม่
 *
 * dexContracts.json ยังอยู่เป็นค่าตั้งต้นตอนโหลดหน้า (ก่อนที่ API จะตอบ) เท่านั้น
 * ก่อน deploy ทุกตัวเป็น zero address → isDexConfigured() = false
 * หน้า UI ต้อง fail-closed แบบเดียวกับ fee_collector_wallet ใน Swap
 *
 * Developed by Xman Studio
 */

import { reactive } from 'vue';
import axios from 'axios';
import deployed from './dexContracts.json';

const ZERO = '0x0000000000000000000000000000000000000000';

/**
 * สถานะ DEX — reactive เพื่อให้หน้าที่ import ไปเห็นตอน API ตอบกลับมาโดยไม่ต้อง reload
 * (ห้ามแทนที่ object นี้ — mutate เท่านั้น เพราะมีคน import ไปถือไว้แล้ว)
 */
export const TPIX_DEX = reactive({
    CHAIN_ID: deployed.chainId ?? 4289,
    RPC: deployed.rpc ?? 'https://rpc.tpix.online',
    WTPIX: deployed.WTPIX ?? ZERO,
    USDT: deployed.USDT ?? ZERO,
    FACTORY: deployed.FACTORY ?? ZERO,
    ROUTER: deployed.ROUTER ?? ZERO,
    // เซิร์ฟเวอร์ยืนยันแล้วว่าสัญญามีโค้ดอยู่บนเชนจริง (ไม่ใช่แค่มีที่อยู่)
    ready: false,
    // โหลดจาก API แล้วหรือยัง — ก่อนโหลดเสร็จอย่าเพิ่งตัดสินว่า "ยังไม่ deploy"
    loaded: false,
});

let _loadPromise = null;
let _loadedAt = 0;
const TTL_MS = 60_000;

/**
 * ดึงที่อยู่จากทะเบียนบนเซิร์ฟเวอร์ — เรียกซ้ำได้ (แคช 60 วิ, รวม request ที่ซ้อนกัน)
 * ล้มเหลว = คงค่าเดิมไว้ (ค่าตั้งต้นจากไฟล์) และ ready ยังเป็นเท็จ → ไม่มีทางเปิดเทรดด้วยที่อยู่ที่เดา
 */
export function loadDexConfig(force = false) {
    const fresh = Date.now() - _loadedAt < TTL_MS;
    if (_loadPromise && (fresh || !force)) return _loadPromise;
    if (!force && fresh && TPIX_DEX.loaded) return Promise.resolve(TPIX_DEX);

    _loadPromise = axios.get('/api/v1/dex/config')
        .then(({ data }) => {
            const cfg = data?.data;
            if (data?.success && cfg) {
                TPIX_DEX.CHAIN_ID = Number(cfg.chainId) || TPIX_DEX.CHAIN_ID;
                TPIX_DEX.RPC = cfg.rpc || TPIX_DEX.RPC;
                TPIX_DEX.WTPIX = cfg.WTPIX || ZERO;
                TPIX_DEX.USDT = cfg.USDT || ZERO;
                TPIX_DEX.FACTORY = cfg.FACTORY || ZERO;
                TPIX_DEX.ROUTER = cfg.ROUTER || ZERO;
                TPIX_DEX.ready = cfg.ready === true;
            }
            _loadedAt = Date.now();
            return TPIX_DEX;
        })
        .catch(() => TPIX_DEX)
        .finally(() => {
            TPIX_DEX.loaded = true;
            _loadPromise = null;
        });

    return _loadPromise;
}

/** ใช้ในเทสต์ — ล้างแคชการโหลด */
export function _resetDexConfigForTests() {
    _loadPromise = null;
    _loadedAt = 0;
    TPIX_DEX.loaded = false;
    TPIX_DEX.ready = false;
    TPIX_DEX.WTPIX = deployed.WTPIX ?? ZERO;
    TPIX_DEX.USDT = deployed.USDT ?? ZERO;
    TPIX_DEX.FACTORY = deployed.FACTORY ?? ZERO;
    TPIX_DEX.ROUTER = deployed.ROUTER ?? ZERO;
}

/**
 * DEX พร้อมใช้เมื่อ address หลักครบทุกตัว **และ** เซิร์ฟเวอร์ยืนยันว่ามีโค้ดบนเชน
 * (เชนเคย regenesis แล้วสัญญาหายทั้งที่ที่อยู่ยังอยู่ — ที่อยู่ครบอย่างเดียวไม่พอ)
 */
export function isDexConfigured() {
    return TPIX_DEX.ready
        && [TPIX_DEX.WTPIX, TPIX_DEX.USDT, TPIX_DEX.FACTORY, TPIX_DEX.ROUTER]
            .every((a) => a && a !== ZERO);
}

/** Router02 — เฉพาะ function ที่ frontend ใช้ */
export const TPIX_DEX_ROUTER_ABI = [
    'function WETH() view returns (address)',
    'function factory() view returns (address)',
    'function getAmountsOut(uint256 amountIn, address[] path) view returns (uint256[] amounts)',
    'function getAmountsIn(uint256 amountOut, address[] path) view returns (uint256[] amounts)',
    'function quote(uint256 amountA, uint256 reserveA, uint256 reserveB) pure returns (uint256 amountB)',
    'function swapExactETHForTokens(uint256 amountOutMin, address[] path, address to, uint256 deadline) payable returns (uint256[] amounts)',
    'function swapExactTokensForETH(uint256 amountIn, uint256 amountOutMin, address[] path, address to, uint256 deadline) returns (uint256[] amounts)',
    'function swapExactTokensForTokens(uint256 amountIn, uint256 amountOutMin, address[] path, address to, uint256 deadline) returns (uint256[] amounts)',
    'function addLiquidityETH(address token, uint256 amountTokenDesired, uint256 amountTokenMin, uint256 amountETHMin, address to, uint256 deadline) payable returns (uint256 amountToken, uint256 amountETH, uint256 liquidity)',
    'function removeLiquidityETH(address token, uint256 liquidity, uint256 amountTokenMin, uint256 amountETHMin, address to, uint256 deadline) returns (uint256 amountToken, uint256 amountETH)',
];

export const TPIX_DEX_FACTORY_ABI = [
    'function getPair(address tokenA, address tokenB) view returns (address pair)',
    'function allPairsLength() view returns (uint256)',
    'function allPairs(uint256) view returns (address pair)',
    'function feeTo() view returns (address)',
];

export const TPIX_DEX_PAIR_ABI = [
    'function getReserves() view returns (uint112 reserve0, uint112 reserve1, uint32 blockTimestampLast)',
    'function token0() view returns (address)',
    'function token1() view returns (address)',
    'function totalSupply() view returns (uint256)',
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address owner, address spender) view returns (uint256)',
    'function approve(address spender, uint256 amount) returns (bool)',
];

export const TPIX_DEX_ERC20_ABI = [
    'function balanceOf(address) view returns (uint256)',
    'function allowance(address owner, address spender) view returns (uint256)',
    'function approve(address spender, uint256 amount) returns (bool)',
    'function decimals() view returns (uint8)',
    'function symbol() view returns (string)',
    'function name() view returns (string)',
];
