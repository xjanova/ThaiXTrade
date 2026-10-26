<script setup>
/**
 * TPIX Master Node — ซื้อ (stake) + ตั้งค่า + ดูแลโหนดของตัวเอง
 *
 * ทุกอย่างในหน้านี้อ่าน/เขียนกับ NodeRegistryV2 บน TPIX Chain จริง
 *
 * ⚠️ กติกาที่ห้ามพลาด (สัญญาบังคับไว้ ผิดเมื่อไหร่ tx revert หรือได้ชั้นผิด):
 *   enum NodeTier { Guardian=0, Sentinel=1, Light=2, Validator=3 }
 *   Guardian อยู่ที่ 0 ไม่ใช่ Validator — สัญญาคงลำดับนี้ไว้ให้เข้ากันได้กับ V1
 *   เดิมหน้านี้ส่ง index ของ array บนหน้าจอ (Validator=0) ไปตรง ๆ ผลคือ
 *   คนกดซื้อ Validator 10M ได้ Guardian, คนกดซื้อ Light 10K โดน revert ทิ้ง
 *
 *   registerNode ต้องส่ง msg.value == minStake "เป๊ะ" (น้อยกว่า = Insufficient stake,
 *   มากกว่า = Stake must equal tier minimum) → ส่ง min_stake_wei เป็น BigInt ตรง ๆ
 *   ห้ามผ่าน float / parseUnits ของตัวเลขที่ปัดแล้ว
 *
 * กติกาการเขียนธุรกรรม (เคยพังมาแล้วทั้งหมดในหน้าอื่น):
 *   - ทุกปุ่มที่ส่ง tx กดซ้ำระหว่างรายการเดิมยังไม่จบ = ไม่ทำอะไร
 *   - จับกระเป๋าไว้ตั้งแต่เริ่ม ถ้าผู้ใช้สลับบัญชีกลางทาง → หยุด ไม่เอาผลไปแปะให้บัญชีใหม่
 *   - ส่ง tx แล้วแต่ต่อเชนไม่ได้ตอนรอผล ≠ ล้มเหลว → โชว์ hash "รอการยืนยัน" แล้วตรวจซ้ำเอง
 *   - อ่านเชนไม่ได้ ≠ "ไม่มีโหนด" → บอกตรง ๆ พร้อมปุ่มลองใหม่ และปิดปุ่มซื้อไว้ก่อน
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AwaitingDeployNotice from '@/Components/AwaitingDeployNotice.vue';
import { useWalletStore } from '@/Stores/walletStore';
import BrandLogo from '@/Components/Brand/BrandLogo.vue';

const walletStore = useWalletStore();

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    tiers: { type: Array, default: () => [] },
    nodes: { type: Array, default: () => [] },
    registryAddress: { type: String, default: '' },
    registryLive: { type: Boolean, default: false },
    // true = ตั้งที่อยู่สัญญาไว้แต่อ่านเชนไม่ได้ตอนโหลดหน้า — ไม่รู้ว่า deploy แล้วหรือยัง
    registryUnknown: { type: Boolean, default: false },
    kycContract: { type: String, default: null },
    // null = ไม่ทราบ (ยังไม่ deploy / RPC ล้ม / สัญญารุ่นเก่า) — ไม่ตีความว่า "เปิดรับ" หรือ "ปิดรับ"
    registrationPaused: { type: Boolean, default: null },
    rpcUrl: { type: String, default: 'https://rpc1.tpix.online' },
    chainId: { type: Number, default: 4289 },
    explorerUrl: { type: String, default: 'https://explorer.tpix.online' },
});

// ============================================================
//  หน้าตาของแต่ละชั้น — key คือ "tier index บนสัญญา" เท่านั้น
//  ตัวเลขจริง (min stake / lock / โควตา / เพดาน APR) มาจากเชน ไม่ hardcode ที่นี่
//  apyHint ใช้แสดง "เฉพาะ" ตอนอ่านเพดาน APR จากเชน/คอนฟิกไม่ได้เลย และติดป้ายว่าประมาณการ
// ============================================================
const TIER_SKIN = {
    3: {
        name: 'Validator Node', short: 'Validator',
        apyHint: '15-20%', hardware: '16 CPU · 32GB · 1TB SSD',
        desc: 'ผู้ปิดบล็อก IBFT2 ตัวจริง มีสิทธิ์โหวตธรรมาภิบาล — ต้องผ่าน KYC นิติบุคคล',
        gradient: 'from-red-500/30 via-rose-500/20 to-pink-500/10',
        border: 'border-red-500/30', glow: 'shadow-[0_0_40px_rgba(239,68,68,0.15)]',
        badge: 'bg-red-500/20 text-red-300 border-red-500/40', accent: 'text-red-400',
        bar: 'from-red-500 to-rose-500 text-white',
    },
    0: {
        name: 'Guardian Node', short: 'Guardian',
        apyHint: '10-12%', hardware: '8 CPU · 16GB · 500GB SSD',
        desc: 'โหนดระดับพรีเมียม ส่วนแบ่งรางวัลสูงสุดในระบบ',
        gradient: 'from-yellow-500/30 via-amber-500/20 to-orange-500/10',
        border: 'border-yellow-500/30', glow: 'shadow-[0_0_40px_rgba(245,158,11,0.15)]',
        badge: 'bg-yellow-500/20 text-yellow-300 border-yellow-500/40', accent: 'text-yellow-400',
        bar: 'from-yellow-500 to-amber-500 text-black',
    },
    1: {
        name: 'Sentinel Node', short: 'Sentinel',
        apyHint: '7-9%', hardware: '4 CPU · 8GB · 200GB SSD',
        desc: 'โหนดมาตรฐานสำหรับดูแลความสมบูรณ์ของเครือข่าย',
        gradient: 'from-purple-500/30 via-violet-500/20 to-fuchsia-500/10',
        border: 'border-purple-500/30', glow: 'shadow-[0_0_40px_rgba(139,92,246,0.15)]',
        badge: 'bg-purple-500/20 text-purple-300 border-purple-500/40', accent: 'text-purple-400',
        bar: 'from-purple-500 to-violet-500 text-white',
    },
    2: {
        name: 'Light Node', short: 'Light',
        apyHint: '4-6%', hardware: '2 CPU · 4GB · 100GB SSD',
        desc: 'เริ่มง่ายที่สุด ช่วยค้ำเครือข่ายแล้วรับรางวัล',
        gradient: 'from-cyan-500/30 via-blue-500/20 to-teal-500/10',
        border: 'border-cyan-500/30', glow: 'shadow-[0_0_40px_rgba(6,182,212,0.15)]',
        badge: 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40', accent: 'text-cyan-400',
        bar: 'from-cyan-500 to-blue-500 text-white',
    },
};

/** ลำดับที่แสดงบนหน้าจอ (แพงสุด → ถูกสุด) — ไม่เกี่ยวกับ index บนสัญญา */
const DISPLAY_ORDER = [3, 0, 1, 2];

const WEI_PER_TPIX = 10n ** 18n;

/** ชื่อรายการภาษาคน — ใช้ในการ์ด "รอการยืนยัน" และข้อความหลังสลับกระเป๋า */
const TX_LABEL = {
    stake: 'ลงทะเบียนโหนด',
    claim: 'รับรางวัล',
    deregister: 'ปิดโหนดและถอนเงินต้น',
    withdraw: 'ถอนเงินต้นที่เหลือ',
    consent: 'ให้ความยินยอม KYC',
};

/** รอผล tx ที่ส่งไปแล้วแต่ wait() ล้ม — ทุก 5 วิ นานสุด 10 นาที แล้วให้ไปดูบนเอ็กซ์พลอเรอร์เอง */
const RECEIPT_POLL_MS = 5000;
const RECEIPT_POLL_MAX = 120;

// ============================================================
//  State
// ============================================================
// ยอดเป็น wei (BigInt) เท่านั้น — null = ยังไม่รู้ (กำลังอ่าน / อ่านไม่ได้) ไม่ใช่ 0
// เดิมเก็บเป็น float แล้วเทียบกับขั้นต่ำ ยอด 9,999.9999999 อาจถูกปัดเป็น 10,000 แล้วปล่อยให้กดซื้อ
const tpixBalanceWei = ref(null);
const balanceError = ref(false);
const isLoadingBalance = ref(false);
const selectedTier = ref(null);
const isStaking = ref(false);
const stakeTxHash = ref('');
const stakeError = ref('');
const flowNotice = ref('');        // ข้อความระดับหน้า (เช่น สลับกระเป๋ากลางรายการ) — ไม่ผูกกับการ์ดใด
const activeTab = ref('setup'); // setup | network

const myNode = ref(null);          // struct จากเชน (null = ยังไม่เคยลงทะเบียน)
const nodeLoaded = ref(false);     // อ่านโหนดของกระเป๋าปัจจุบันสำเร็จอย่างน้อยหนึ่งครั้งแล้ว
const isLoadingNode = ref(false);
const chainReadError = ref(false); // อ่านเชนไม่ได้ = ไม่รู้ว่ามีโหนดหรือไม่ (ห้ามสรุปว่า "ไม่มีโหนด")
const chainTiers = ref(props.tiers || []);

const endpoint = ref('');
const endpointTouched = ref(false);
const kycApproved = ref(null);     // null = ยังไม่ได้ตรวจ
const kycStatusId = ref(null);     // enum KYCStatus จาก getRecord()
const kycRejectReason = ref('');
const isGivingConsent = ref(false);
const kycError = ref('');

const isClaiming = ref(false);
const isDeregistering = ref(false);
const isWithdrawing = ref(false);
const claimMessage = ref('');
const claimIsError = ref(false);

/** tx ที่ส่งแล้วแต่ยังไม่รู้ผล — { hash, kind, owner, gaveUp? } */
const pendingTx = ref(null);

/** สัญญาณชีพจากโปรแกรม PC — null = ไม่ทราบ (ซ่อนบรรทัด) */
const heartbeat = ref(null);

const isOnTPIXChain = computed(() => Number(walletStore.chainId) === props.chainId);

const networkStats = ref({ ...props.stats });

/**
 * ตัวเลขใน stats อ่านมาจากเชนจริงไหม — false = เซิร์ฟเวอร์ส่งค่าตั้งต้นมา (RPC ล้ม / ยังไม่ deploy)
 * ต้องโชว์ "—" ไม่ใช่ "0 โหนด" เหมือนอ่านมาแล้ว (เซิร์ฟเวอร์รุ่นเก่าไม่มีธงนี้ = ถือว่าอ่านได้)
 */
const statsKnown = computed(() => networkStats.value.stats_available !== false);

/**
 * สถานะพูลรางวัล: 'funded' | 'unfunded' | 'unknown'
 * อ่านยอดพูลไม่ได้ ≠ พูลว่าง — ห้ามประกาศว่า "ยังไม่เติมเงิน" ตอนที่จริง ๆ แค่อ่านไม่ได้
 */
const poolState = computed(() => {
    const s = networkStats.value;
    if (!props.registryLive || s.stats_available === false || s.reward_pool_known === false) return 'unknown';
    return parseFloat(s.reward_pool_available || '0') > 0 ? 'funded' : 'unfunded';
});

/** ตัวเลขสถิติ — อ่านเชนไม่ได้ = "—" */
function statNum(v) {
    return statsKnown.value ? fmtNum(v || 0) : '—';
}

const MAX_ENDPOINT_LEN = 100;      // ตรงกับ MAX_ENDPOINT_LEN ในสัญญา

/** แสดงผลเท่านั้น — ห้ามเอาไปเทียบยอด/ส่งเป็นจำนวนเงิน */
const tpixBalance = computed(() => (tpixBalanceWei.value === null ? 0 : weiToNumber(tpixBalanceWei.value)));

/** แอดมินปิดรับลงทะเบียนใหม่ — ค่าจาก stats ที่ poll ทุก 30 วิ ชนะค่าตอนโหลดหน้า */
const isRegistrationPaused = computed(() => (networkStats.value.registration_paused ?? props.registrationPaused) === true);

// ============================================================
//  ABI — ชื่อและลำดับพารามิเตอร์ต้องตรงกับ NodeRegistryV2 เป๊ะ ๆ
// ============================================================
const NODE_REGISTRY_ABI = [
    'function registerNode(uint8 _tier, string _endpoint, uint256 _maxLockDays, uint256 _maxSlashBps, uint256 _deadline) payable',
    'function claimRewards()',
    'function deregisterNode()',
    'function withdrawSlashedStake()',
    'function pendingReward(address _operator) view returns (uint256)',
    'function claimableNow(address _operator) view returns (uint256)',
    'function availableRewardFunds() view returns (uint256)',
    'function registrationPaused() view returns (bool)',
    'function getNodeInfo(address _operator) view returns (tuple(address operator, uint8 tier, uint8 status, uint256 stakedAmount, uint256 registeredAt, uint256 unlockAt, uint256 lastRewardAt, uint256 totalRewards, uint256 uptime, bytes32 nodeId, string endpoint, uint256 rewardDebt, uint256 pendingUnclaimed))',
    'function getTierInfo(uint8 _tier) view returns (tuple(uint256 minStake, uint256 maxNodes, uint256 activeNodes, uint256 lockDays, uint256 slashPercent, uint256 rewardShare))',
];

/**
 * ValidatorKYC — ชั้น Validator ต้องผ่าน 3 ขั้นก่อนถึงจะลงทะเบียนได้
 *   1. ผู้สมัครกด giveConsent() ด้วยตัวเอง (PDPA — ทีมงานทำแทนไม่ได้)
 *   2. ทีมงานเรียก submitKYC() หลังตรวจเอกสาร
 *   3. ทีมงานเรียก approveKYC()
 * ก่อนหน้านี้ไม่มีหน้าไหนในเว็บให้กดขั้นที่ 1 เลย ชั้น Validator จึงตัน 100%
 * ต่อให้ deploy สัญญาแล้วก็ตาม
 */
const KYC_ABI = [
    'function isApproved(address) view returns (bool)',
    'function giveConsent()',
    'function getRecord(address _applicant) view returns (tuple(address applicant, uint8 status, bytes32 kycHash, uint256 consentAt, uint256 submittedAt, uint256 reviewedAt, address reviewer, string rejectReason))',
];

/** enum KYCStatus { None, ConsentGiven, Submitted, Approved, Rejected, Revoked } */
const KYC_STATUS = {
    0: { label: 'ยังไม่เริ่ม', hint: 'กดให้ความยินยอมเพื่อเริ่มขั้นตอน KYC' },
    1: { label: 'ให้ความยินยอมแล้ว', hint: 'ส่งเอกสารให้ทีมงานตรวจ แล้วรอทีมงานบันทึกเข้าระบบ' },
    2: { label: 'ทีมงานรับเรื่องแล้ว', hint: 'อยู่ระหว่างตรวจสอบ' },
    3: { label: 'อนุมัติแล้ว', hint: 'ลงทะเบียนชั้น Validator ได้เลย' },
    4: { label: 'ไม่ผ่าน', hint: 'ติดต่อทีมงานเพื่อยื่นใหม่' },
    5: { label: 'ถอนความยินยอมแล้ว', hint: 'กดให้ความยินยอมใหม่เพื่อเริ่มอีกครั้ง' },
};

/** enum NodeStatus { Inactive, Active, Slashed, Exited, SlashedWithdrawable } */
const STATUS_LABEL = {
    0: 'ยังไม่ลงทะเบียน', 1: 'ทำงานอยู่', 2: 'ถูกปรับ (slashed)',
    3: 'ออกจากระบบแล้ว', 4: 'ถูกปรับ — ถอนเงินต้นได้',
};

// ============================================================
//  ตัวช่วยเรื่องจำนวนเงิน — wei เป็น BigInt ตลอดทาง
// ============================================================
function sameAddress(a, b) {
    return !!a && !!b && String(a).toLowerCase() === String(b).toLowerCase();
}

function shortAddr(a) {
    return a ? `${String(a).slice(0, 6)}…${String(a).slice(-4)}` : '';
}

/** สตริงจำนวนเต็ม wei จากเซิร์ฟเวอร์ → BigInt (null ถ้าไม่ใช่ตัวเลขล้วน) */
function parseWei(v) {
    if (v === null || v === undefined) return null;
    const s = String(v).trim();
    return /^\d+$/.test(s) ? BigInt(s) : null;
}

/** จำนวน TPIX ทศนิยม ('1234.5') → wei แบบตัดทิ้ง ไม่ปัดขึ้น */
function decimalToWei(v) {
    const m = String(v ?? '').trim().match(/^(\d+)(?:\.(\d+))?$/);
    if (!m) return null;
    const frac = (m[2] || '').slice(0, 18).padEnd(18, '0');
    return BigInt(m[1]) * WEI_PER_TPIX + BigInt(frac);
}

/**
 * wei → number สำหรับแสดงผล — แยกส่วนเต็ม/เศษ เพื่อให้จำนวน TPIX เต็ม ๆ ออกมาตรงตัว
 * (Number(25000n * 10n**18n) / 1e18 อาจได้ 25000.000000000004) และเศษ wei เล็ก ๆ ไม่หายเป็น 0
 */
function weiToNumber(wei) {
    const w = BigInt(wei);
    return Number(w / 10n ** 12n) / 1e6 + Number(w % 10n ** 12n) / 1e18;
}

function numOrNull(v) {
    if (v === null || v === undefined || v === '') return null;
    const n = Number(v);
    return Number.isFinite(n) ? n : null;
}

/** basis points → "12%" / "6.25%" */
function fmtBps(bps) {
    if (bps === null || bps === undefined) return '—';
    return `${Number((Number(bps) / 100).toFixed(2)).toLocaleString(undefined, { maximumFractionDigits: 2 })}%`;
}

function codeError(code) {
    const e = new Error(code);
    e.code = code;
    return e;
}

function isUserRejection(e) {
    return e?.code === 'ACTION_REJECTED' || e?.code === 4001
        || e?.info?.error?.code === 4001 || e?.error?.code === 4001;
}

// ============================================================
//  Provider สำหรับ "อ่าน" — ยิงตรงไปที่ RPC ของเชนเสมอ
//  ไม่ใช้ provider ของกระเป๋า เพราะถ้าผู้ใช้อยู่คนละเชนจะอ่านได้ค่าของเชนอื่น
// ============================================================
// โหลด ethers ครั้งเดียวแล้วใช้ซ้ำ — ทุกฟังก์ชันอ่าน/เขียนวิ่งพร้อมกันตอนเชื่อมกระเป๋า ไม่ต้องสั่ง import ซ้อนกัน
// โหลดไม่สำเร็จ (เน็ตหลุดตอนดึงชังก์) → ล้างทิ้ง ให้ปุ่ม "ลองใหม่" โหลดใหม่ได้ ไม่ค้าง promise ที่ reject ไว้ตลอดไป
let ethersPromise = null;
function loadEthers() {
    if (!ethersPromise) {
        ethersPromise = import('ethers').catch((e) => { ethersPromise = null; throw e; });
    }
    return ethersPromise;
}

let readProviderPromise = null;
async function getReadProvider() {
    if (!readProviderPromise) {
        readProviderPromise = loadEthers().then(({ JsonRpcProvider, Network }) =>
            new JsonRpcProvider(props.rpcUrl, Network.from(props.chainId), { staticNetwork: true })
        ).catch((e) => { readProviderPromise = null; throw e; });
    }
    return readProviderPromise;
}

async function getReadContract() {
    if (!props.registryAddress) return null;
    const { Contract } = await loadEthers();
    return new Contract(props.registryAddress, NODE_REGISTRY_ABI, await getReadProvider());
}

// ============================================================
//  Wallet & Chain
// ============================================================
function connectWallet() { walletStore.showConnectModal = true; }

async function ensureTPIXChain() {
    if (Number(walletStore.chainId) === props.chainId) return;
    // switchChain → switchToChain เพิ่มเชนให้เองเมื่อกระเป๋ายังไม่มี (rpc1 + โลโก้) แล้วตรวจว่าสลับจริง
    // เดิม fallback ส่ง walletStore.provider (ethers BrowserProvider ไม่มี .request) เข้า
    // addTPIXChainToWallet → คืน false เงียบ ๆ ทุกครั้ง แล้วสลับซ้ำให้ล้มอีกรอบ
    await walletStore.switchChain(props.chainId);
}

/** ปุ่ม "สลับเครือข่าย" บนหน้า — ห้ามปล่อย promise reject ลอยเมื่อผู้ใช้กดยกเลิก */
async function switchNetwork() {
    try {
        await ensureTPIXChain();
    } catch (e) {
        flowNotice.value = humanError(e);
    }
}

class WalletSwitchedError extends Error {
    constructor(owner) {
        super('WALLET_SWITCHED');
        this.code = 'WALLET_SWITCHED';
        this.owner = owner;
    }
}

/**
 * จับกระเป๋าที่เริ่มรายการไว้ — เรียก check() หลังทุก await ก่อนส่ง tx
 * walletStore อัปเดต address ทันทีที่ผู้ใช้สลับบัญชี แต่ signer ตามมาทีหลังแบบ async
 * ถ้าไม่จับไว้ รายการที่เริ่มจากบัญชี A อาจถูกเซ็น/แสดงผลเป็นของบัญชี B
 */
function walletGuard() {
    const owner = walletStore.address;
    return {
        owner,
        check() {
            if (!sameAddress(owner, walletStore.address)) throw new WalletSwitchedError(owner);
        },
    };
}

/** เตรียม signer ที่ "เป็นบัญชีเดียวกับตอนกดปุ่ม" และอยู่บน TPIX Chain จริง */
async function signerFor(guard) {
    await ensureTPIXChain();
    guard.check();
    if (Number(walletStore.chainId) !== props.chainId) throw codeError('WRONG_CHAIN');

    const signer = walletStore.signer;
    if (!signer) throw codeError('NO_SIGNER');

    const signerAddress = await signer.getAddress();
    guard.check();
    if (!sameAddress(signerAddress, guard.owner)) throw new WalletSwitchedError(guard.owner);
    return signer;
}

// ============================================================
//  อ่านข้อมูลของกระเป๋า — ทุกตัว race-safe
//  ผลที่กลับมาช้าของกระเป๋าเดิม (หรือคำขอรุ่นก่อน) ต้องไม่เขียนทับสถานะของกระเป๋าปัจจุบัน
// ============================================================
let balanceReq = 0;
async function fetchBalance() {
    const addr = walletStore.address;
    const req = ++balanceReq;
    if (!walletStore.isConnected || !addr) return;
    const stale = () => req !== balanceReq || !sameAddress(addr, walletStore.address);

    isLoadingBalance.value = true;
    try {
        // อ่านจาก RPC ของเชนโดยตรง — ได้ยอดของ TPIX Chain เสมอ
        // ต่อให้กระเป๋ายังชี้เชนอื่นอยู่ ตัวเลขบนการ์ดก็ยังถูก
        const provider = await getReadProvider();
        const bal = await provider.getBalance(addr);
        if (stale()) return;
        tpixBalanceWei.value = BigInt(bal);
        balanceError.value = false;
    } catch {
        try {
            const resp = await fetch(`/api/v1/wallet/balances?wallet_address=${encodeURIComponent(addr)}&chain_id=${props.chainId}`);
            const d = resp.ok ? await resp.json() : null;
            if (stale()) return;
            const wei = d?.success ? decimalToWei(d.data?.native?.balance) : null;
            if (wei !== null) {
                tpixBalanceWei.value = wei;
                balanceError.value = false;
            } else {
                // ปล่อยยอดเดิม (ของกระเป๋าเดียวกัน) ไว้ ดีกว่าโชว์ 0 ทั้งที่มีเงิน
                balanceError.value = true;
            }
        } catch {
            if (!stale()) balanceError.value = true;
        }
    } finally {
        if (req === balanceReq) isLoadingBalance.value = false;
    }
}

let nodeReq = 0;
async function fetchMyNode() {
    const addr = walletStore.address;
    const req = ++nodeReq;
    if (!addr || !props.registryAddress || !props.registryLive) {
        // ยังไม่มีสัญญา = ไม่มีอะไรให้อ่าน (ไม่ใช่อ่านไม่ได้)
        myNode.value = null;
        chainReadError.value = false;
        nodeLoaded.value = true;
        isLoadingNode.value = false;
        return;
    }
    const stale = () => req !== nodeReq || !sameAddress(addr, walletStore.address);

    isLoadingNode.value = true;
    try {
        const c = await getReadContract();
        const provider = await getReadProvider();
        // pendingReward/claimableNow ล้ม = อ่านไม่ได้ทั้งก้อน ไม่แอบเติม 0 ให้ (0 แปลว่า "ไม่มีรางวัล")
        const [info, pending, claimable, block] = await Promise.all([
            c.getNodeInfo(addr),
            c.pendingReward(addr),
            c.claimableNow(addr),
            provider.getBlock('latest').catch(() => null),
        ]);
        if (stale()) return;

        chainReadError.value = false;
        nodeLoaded.value = true;

        // ไม่เคยลงทะเบียน = operator เป็น 0x0
        // ห้ามดูจาก tier เพราะ tier 0 (Guardian) เป็นค่าที่ถูกต้อง
        if (!info || /^0x0{40}$/i.test(info.operator)) { myNode.value = null; return; }

        // ล็อกเทียบกับ "เวลาของเชน" ไม่ใช่นาฬิกาเครื่องผู้ใช้ (เครื่องเวลาเพี้ยน = ปุ่มถอนเปิดก่อนเวลาแล้ว revert)
        const now = block?.timestamp ? Number(block.timestamp) : Math.floor(Date.now() / 1000);
        const claimableWei = BigInt(claimable);
        myNode.value = {
            operator: info.operator,
            tierId: Number(info.tier),
            statusId: Number(info.status),
            stake: weiToNumber(info.stakedAmount),
            registeredAt: Number(info.registeredAt),
            unlockAt: Number(info.unlockAt),
            totalRewards: weiToNumber(info.totalRewards),
            uptime: Number(info.uptime) / 100,
            nodeId: info.nodeId,
            endpoint: info.endpoint,
            pendingUnclaimed: weiToNumber(info.pendingUnclaimed),
            pending: weiToNumber(pending),
            claimable: weiToNumber(claimableWei),
            claimableWei,
            isLocked: Number(info.unlockAt) > now,
            unlockInSeconds: Math.max(0, Number(info.unlockAt) - now),
        };
    } catch (e) {
        if (stale()) return;
        // เก็บข้อมูลเดิม (ของกระเป๋าเดียวกัน) ไว้ให้ดู แต่ติดธงว่าอ่านล่าสุดไม่สำเร็จ
        chainReadError.value = true;
        console.warn('[masternode] อ่านสถานะโหนดไม่สำเร็จ', e);
    } finally {
        if (req === nodeReq) isLoadingNode.value = false;
    }
}

let kycReq = 0;
/** ชั้น Validator ลงทะเบียนไม่ได้ถ้ายังไม่ผ่าน KYC — ตรวจก่อนให้กด ไม่ใช่ปล่อยให้ revert */
async function fetchKycStatus() {
    const addr = walletStore.address;
    const req = ++kycReq;
    if (!props.kycContract || !addr) { kycApproved.value = null; kycStatusId.value = null; kycRejectReason.value = ''; return; }
    const stale = () => req !== kycReq || !sameAddress(addr, walletStore.address);

    try {
        const { Contract } = await loadEthers();
        const kyc = new Contract(props.kycContract, KYC_ABI, await getReadProvider());
        const [approved, record] = await Promise.all([
            kyc.isApproved(addr),
            kyc.getRecord(addr).catch(() => null),
        ]);
        if (stale()) return;
        kycApproved.value = approved;
        kycStatusId.value = record ? Number(record.status) : null;
        kycRejectReason.value = record?.rejectReason || '';
    } catch {
        if (stale()) return;
        kycApproved.value = null;
        kycStatusId.value = null;
    }
}

let hbReq = 0;
/**
 * สัญญาณชีพล่าสุดจากโปรแกรม TPIX Master Node บน PC (GET /api/v1/node/status/{wallet})
 * fail-soft: อ่านไม่ได้ = ซ่อนบรรทัด ไม่ขึ้น error — เป็นข้อมูลเสริม ไม่ใช่สถานะบนเชน
 */
async function fetchHeartbeat() {
    const addr = walletStore.address;
    const req = ++hbReq;
    if (!addr) { heartbeat.value = null; return; }
    try {
        const r = await fetch(`/api/v1/node/status/${addr}`, { headers: { Accept: 'application/json' } });
        const d = r?.ok ? await r.json() : null;
        if (req !== hbReq || !sameAddress(addr, walletStore.address)) return;
        heartbeat.value = d && typeof d.exists === 'boolean'
            ? { exists: d.exists, lastHeartbeat: Number(d.last_heartbeat) || null, isActive: !!d.is_active }
            : null;
    } catch {
        if (req === hbReq) heartbeat.value = null;
    }
}

async function refreshTiers() {
    try {
        const r = await fetch('/api/v1/masternode/tiers');
        if (r.ok) { const d = await r.json(); if (d.success) chainTiers.value = d.data || []; }
    } catch { /* ใช้ค่าที่ server render มาต่อไป */ }
}

// ============================================================
//  รวมข้อมูลชั้น: ตัวเลขจากเชน + หน้าตาจาก TIER_SKIN + เงื่อนไขของผู้ใช้
// ============================================================
const tierCards = computed(() => {
    const byIndex = new Map(chainTiers.value.map(t => [Number(t.tier), t]));

    return DISPLAY_ORDER.map(idx => {
        const chain = byIndex.get(idx) || {};
        const skin = TIER_SKIN[idx];
        // จำนวนที่ต้องส่งจริง — min_stake_wei จากเชนตรง ๆ (สำรองด้วยจำนวนเต็ม TPIX × 1e18)
        const minStakeWei = parseWei(chain.min_stake_wei)
            ?? (/^\d+$/.test(String(chain.min_stake ?? '').trim()) ? BigInt(String(chain.min_stake).trim()) * WEI_PER_TPIX : null);
        const minStake = minStakeWei === null ? 0 : weiToNumber(minStakeWei);
        const maxNodes = Number(chain.max_nodes ?? 0);
        const activeNodes = Number(chain.active_nodes ?? 0);
        const isFull = maxNodes > 0 && activeNodes >= maxNodes;
        const needsKyc = idx === 3;
        const fromChain = chain.source === 'chain';
        const aprCapBps = numOrNull(chain.apr_cap_bps);
        const projectedAprBps = fromChain ? numOrNull(chain.projected_apr_bps) : null;

        // "คาดการณ์ถ้าเข้าตอนนี้" คิดจากอัตราปล่อยรางวัลของสัญญา ไม่ได้ดูว่าพูลมีเงินจริงไหม
        // พูลยังไม่ถูกเติมเงิน = ตอนนี้จ่ายได้ 0 ห้ามโชว์เลข % สีเขียวเหมือนได้แน่
        let projectedText = '—';
        let projectedTone = 'muted';
        if (projectedAprBps !== null && !isFull) {
            if (poolState.value === 'unfunded') {
                projectedText = 'จ่ายได้ 0 — พูลยังไม่เติมเงิน';
                projectedTone = 'warn';
            } else {
                projectedText = `${fmtBps(projectedAprBps)} ต่อปี`;
                // อ่านยอดพูลไม่ได้ = ไม่เน้นสีเขียว (ยังไม่รู้ว่าจ่ายได้จริงหรือเปล่า)
                projectedTone = poolState.value === 'funded' ? 'good' : 'muted';
            }
        }

        return {
            id: idx,
            ...skin,
            minStake,
            minStakeWei,
            lockDays: Number(chain.lock_days ?? 0),
            maxNodes,
            activeNodes,
            slotsLeft: maxNodes > 0 ? Math.max(0, maxNodes - activeNodes) : null,
            rewardShareBps: Number(chain.reward_share ?? 0),
            rewardShare: `${(Number(chain.reward_share ?? 0) / 100).toFixed(0)}%`,
            slashPercent: Number(chain.slash_percent ?? 0) / 100,
            slashBps: Number(chain.slash_percent ?? 0),
            aprCapBps,
            projectedAprBps,
            projectedText,
            projectedTone,
            // ป้ายหัวการ์ด: เพดานจริงจากเชน > ค่าเริ่มต้นของสัญญา (ติดป้าย) > ช่วงประมาณการ (ติดป้าย)
            apyBadge: aprCapBps !== null ? `สูงสุด ${fmtBps(aprCapBps)} ต่อปี` : `${skin.apyHint} ต่อปี`,
            apyNote: fromChain && aprCapBps !== null
                ? ''
                : (aprCapBps !== null ? 'ค่าตั้งต้นของสัญญา — ยังไม่ได้อ่านจากเชน' : 'ประมาณการ — ยังไม่ได้อ่านจากเชน'),
            isFull,
            needsKyc,
            canAfford: minStakeWei !== null && minStakeWei > 0n
                && tpixBalanceWei.value !== null && tpixBalanceWei.value >= minStakeWei,
            source: chain.source || 'config',
        };
    });
});

/** หัวหน้าเว็บ "ผลตอบแทนสูงสุดต่อปี" = เพดานสูงสุดในทุกชั้น (ไม่ใช่เลข 20% ที่เขียนตายตัว) */
const heroMaxApr = computed(() => {
    const caps = tierCards.value.map(t => t.aprCapBps).filter(v => v !== null);
    return caps.length ? fmtBps(Math.max(...caps)) : '20%';
});

/** ตัวอย่างในคำอธิบายการแบ่งรางวัล — ใช้เพดานของชั้น Light จริง */
const lightCapText = computed(() => {
    const light = tierCards.value.find(t => t.id === 2);
    return light?.aprCapBps != null ? fmtBps(light.aprCapBps) : '6%';
});

/** ขั้นต่ำของชั้นที่ถูกที่สุด (หัวหน้าเว็บ) — จาก min stake บนเชน ไม่ใช่ "10K" ที่เขียนตายตัว */
const minEntryText = computed(() => {
    const stakes = tierCards.value.map(t => t.minStake).filter(v => v > 0);
    if (!stakes.length) return '—';
    const min = Math.min(...stakes);
    return min >= 1000 && min % 1000 === 0 ? `${fmtNum(min / 1000)}K` : fmtNum(min);
});

/** แถบสัดส่วน emission ต่อชั้น — จาก reward_share บนเชน ไม่ใช่ 20/35/30/15 ที่เขียนตายตัว */
const shareBar = computed(() => tierCards.value
    .filter(t => t.rewardShareBps > 0)
    .map(t => ({ id: t.id, short: t.short, bar: t.bar, pct: t.rewardShareBps / 100 })));

const busy = computed(() => isStaking.value || isClaiming.value || isDeregistering.value
    || isWithdrawing.value || isGivingConsent.value);

/** ยังมี tx ที่ส่งไปแล้วแต่ไม่รู้ผล — ห้ามส่งรายการใหม่ซ้อนจนกว่าจะรู้ */
const txAwaiting = computed(() => !!pendingTx.value && !pendingTx.value.gaveUp);

/** ปุ่มซื้อกดได้ไหม + ถ้ากดไม่ได้ เพราะอะไร (ตอบเป็นภาษาคน ไม่ใช่ revert string) */
function blockReason(tier) {
    if (!props.registryLive && props.registryUnknown) return 'อ่านสถานะสัญญาจากเชนไม่ได้ชั่วคราว — รีเฟรชหน้าอีกครั้งในอีกสักครู่';
    if (!props.registryLive) return 'สัญญายังไม่ได้ติดตั้งบนเชน — เปิดขายเมื่อระบบพร้อม';
    if (isRegistrationPaused.value) {
        return 'ผู้ดูแลระบบปิดรับลงทะเบียนโหนดใหม่ชั่วคราว — โหนดที่มีอยู่ยังรับรางวัล ปิดโหนด และถอนเงินต้นได้ตามปกติ';
    }
    if (chainReadError.value) return 'อ่านข้อมูลจากเชนไม่ได้ — กด "ลองใหม่" ก่อน (ยังไม่รู้ว่ากระเป๋านี้มีโหนดอยู่แล้วหรือไม่)';
    if (walletStore.isConnected && !nodeLoaded.value) return 'กำลังอ่านสถานะโหนดของคุณจากเชน...';
    if (txAwaiting.value) return 'มีรายการก่อนหน้ารอการยืนยันบนเชนอยู่ — รอให้จบก่อน';
    if (myNode.value && myNode.value.statusId === 1) return 'คุณมีโหนดที่ทำงานอยู่แล้ว 1 ตัวต่อ 1 กระเป๋า';
    // สัญญาบังคับ status == Inactive เท่านั้น — โหนดที่ถูกปรับ (2/4) หรือออกไปแล้ว (3)
    // ยังไม่ใช่ Inactive ต้องถอนเงินต้นที่เหลือให้จบก่อน ไม่งั้น registerNode revert "Already registered"
    if (myNode.value && myNode.value.statusId !== 0 && myNode.value.statusId !== 1) {
        return 'ต้องถอนเงินต้นที่เหลือของโหนดเดิมให้จบก่อนจึงลงทะเบียนใหม่ได้';
    }
    if (myNode.value && myNode.value.pendingUnclaimed > 0) return 'ต้องกดรับรางวัลค้างให้หมดก่อนลงทะเบียนใหม่';
    if (tier.isFull) return `ชั้นนี้เต็มแล้ว (${tier.activeNodes}/${tier.maxNodes})`;
    if (tier.minStakeWei === null || tier.minStakeWei <= 0n) return 'ยังไม่ทราบจำนวนวางค้ำของชั้นนี้จากเชน';
    if (tpixBalanceWei.value === null) {
        return balanceError.value ? 'อ่านยอด TPIX ของคุณไม่ได้ — กดลองใหม่อีกครั้ง' : 'กำลังอ่านยอด TPIX ของคุณ...';
    }
    // เทียบเป็น wei (BigInt) — ส่วนต่างปัดขึ้นเป็น TPIX เต็ม ให้เติมครั้งเดียวพอ
    if (tpixBalanceWei.value < tier.minStakeWei) {
        const shortWei = tier.minStakeWei - tpixBalanceWei.value;
        const shortTpix = (shortWei + WEI_PER_TPIX - 1n) / WEI_PER_TPIX;
        return `ต้องมีอีก ${fmtNum(Number(shortTpix))} TPIX`;
    }
    if (tier.needsKyc && !props.kycContract) return 'ชั้น Validator ยังไม่เปิด — ผู้ดูแลยังไม่ผูกสัญญา KYC';
    if (tier.needsKyc && kycApproved.value === false) return 'ชั้น Validator ต้องผ่าน KYC ก่อน';
    if (!endpointValid.value) return 'กรอกที่อยู่โหนด (IP:port) ให้ถูกต้องก่อน';
    return null;
}

// ============================================================
//  Endpoint — สัญญาบังคับว่าต้องไม่ว่างและยาวไม่เกิน 100 ตัวอักษร
//  ของเดิมยัดค่าปลอม `0x1234abcd.tpix.online` ให้เอง ผู้ใช้จึงลงทะเบียน
//  ด้วยที่อยู่ที่ไม่มีอยู่จริง ไม่มีใครต่อถึงโหนดได้เลย
// ============================================================
const endpointValid = computed(() => {
    const v = endpoint.value.trim();
    if (!v || v.length > MAX_ENDPOINT_LEN) return false;
    // host:port — host เป็น IPv4 หรือโดเมน, port 1-65535
    const m = v.match(/^([a-zA-Z0-9][-a-zA-Z0-9.]*[a-zA-Z0-9]|\d{1,3}(\.\d{1,3}){3}):(\d{1,5})$/);
    if (!m) return false;
    const port = Number(m[3]);
    return port >= 1 && port <= 65535;
});

const endpointError = computed(() => {
    if (!endpointTouched.value || endpointValid.value) return '';
    const v = endpoint.value.trim();
    if (!v) return 'ต้องกรอกที่อยู่โหนด เช่น 203.0.113.10:8545';
    if (v.length > MAX_ENDPOINT_LEN) return `ยาวเกิน ${MAX_ENDPOINT_LEN} ตัวอักษร`;
    return 'รูปแบบต้องเป็น IP:port หรือ domain:port เช่น 203.0.113.10:8545';
});

// ============================================================
//  แปลง error จากเชน/กระเป๋า ให้เป็นข้อความที่ผู้ใช้อ่านรู้เรื่อง
//  ห้ามโยน e.message ดิบขึ้นจอ — ethers คืนมาเป็นก้อน JSON ยาวเป็นหน้า
// ============================================================
const REVERT_MAP = {
    'Already registered': 'กระเป๋านี้มีโหนดที่ทำงานอยู่แล้ว',
    'Claim pending rewards first': 'ต้องกดรับรางวัลค้างให้หมดก่อนลงทะเบียนใหม่',
    'Registration paused': 'ผู้ดูแลระบบปิดรับลงทะเบียนโหนดใหม่ชั่วคราว — ยังไม่มีการโอนเงิน',
    // สองข้อความนี้มาจากกติกา msg.value == minStake เป๊ะ (ส่งเกิน / ส่งขาด)
    'Stake must equal tier minimum': 'ต้องวางค้ำเท่ากับขั้นต่ำของชั้นนี้พอดี (รางวัลแบ่งเท่ากันทุกโหนด วางเกินไม่ได้อะไรเพิ่ม) — ระบบโหลดขั้นต่ำล่าสุดให้แล้ว ลองอีกครั้ง',
    'Registration expired': 'การยืนยันหมดอายุ กรุณาโหลดข้อมูลและยืนยันสมัครใหม่',
    'Lock terms changed': 'ระยะเวลาล็อกเปลี่ยนไป กรุณาโหลดข้อมูลและตรวจเงื่อนไขใหม่',
    'Slash terms changed': 'เพดานค่าปรับเปลี่ยนไป กรุณาโหลดข้อมูลและตรวจเงื่อนไขใหม่',
    'Insufficient stake': 'จำนวน TPIX ที่ส่งไปน้อยกว่าขั้นต่ำของชั้นนี้ — ระบบโหลดขั้นต่ำล่าสุดให้แล้ว ลองอีกครั้ง',
    'Endpoint required': 'ต้องกรอกที่อยู่โหนด',
    'Endpoint too long': `ที่อยู่โหนดยาวเกิน ${MAX_ENDPOINT_LEN} ตัวอักษร`,
    'Tier full': 'ชั้นนี้เต็มโควตาแล้ว',
    'KYC contract not configured': 'ชั้น Validator ยังไม่เปิด — ผู้ดูแลยังไม่ผูกสัญญา KYC',
    'KYC not approved': 'ชั้น Validator ต้องผ่าน KYC ก่อน',
    'Consent already active': 'ให้ความยินยอมไว้แล้ว',
    'Not active': 'โหนดนี้ไม่ได้อยู่ในสถานะทำงาน',
    'Still locked': 'ยังไม่พ้นระยะล็อก ถอนเงินต้นไม่ได้',
    'Not slashed': 'โหนดนี้ไม่ได้อยู่ในสถานะถูกปรับ',
    'Nothing to withdraw': 'ไม่มีเงินต้นเหลือให้ถอน',
    'Transfer failed': 'โอนเงินไม่สำเร็จ — สัญญามีเงินไม่พอในตอนนี้',
};

const CODE_MESSAGE = {
    WRONG_CHAIN: 'กระเป๋ายังไม่ได้อยู่บน TPIX Chain — สลับเครือข่ายแล้วลองใหม่',
    NO_SIGNER: 'ไม่พบตัวเซ็นในกระเป๋า — เชื่อมกระเป๋าใหม่อีกครั้ง',
    TX_CANCELLED: 'รายการถูกยกเลิกในกระเป๋า — ไม่มีเงินออกจากกระเป๋า',
    NO_WALLET_PROVIDER: 'ไม่พบกระเป๋าที่เชื่อมอยู่ — เชื่อมกระเป๋าก่อนทำรายการ',
};

const POOL_EMPTY_MSG = 'ตอนนี้ยังไม่มีรางวัลที่กดรับได้ — ถ้าพูลรางวัลยังไม่ถูกเติมเงิน รางวัลของคุณไม่หาย สะสมรอไว้เคลมเมื่อพูลมีเงิน';

function humanError(e) {
    if (!e) return 'ทำรายการไม่สำเร็จ';
    if (e.code === 'WALLET_SWITCHED') {
        return `คุณสลับกระเป๋าระหว่างทำรายการ — หยุดไว้ก่อนและยังไม่ได้ส่งรายการใด ๆ (รายการนี้เริ่มจาก ${shortAddr(e.owner)}) ตรวจสอบแล้วกดใหม่อีกครั้ง`;
    }
    if (isUserRejection(e)) return 'คุณยกเลิกการเซ็นในกระเป๋า — ยังไม่มีการโอนเงินใด ๆ';
    if (CODE_MESSAGE[e.code]) return CODE_MESSAGE[e.code];
    if (e.code === 'INSUFFICIENT_FUNDS') return 'ยอด TPIX ในกระเป๋าไม่พอ';

    const blob = [e.reason, e.shortMessage, e.info?.error?.message, e.data?.message, e.message]
        .filter(Boolean).join(' | ');

    for (const [key, msg] of Object.entries(REVERT_MAP)) {
        if (blob.includes(key)) return msg;
    }
    if (/user rejected|User denied/i.test(blob)) return 'คุณยกเลิกการเซ็นในกระเป๋า — ยังไม่มีการโอนเงินใด ๆ';
    if (/network|timeout|could not detect|failed to fetch/i.test(blob)) return 'ต่อกับเชนไม่ได้ — ลองใหม่อีกครั้ง';

    // reason คือข้อความ revert สั้น ๆ จากสัญญา (ไม่ใช่ก้อน JSON) — ส่วนที่เหลือไม่โชว์ดิบ
    return e.reason ? `ทำรายการไม่สำเร็จ: ${e.reason}` : 'ทำรายการไม่สำเร็จ — ลองใหม่อีกครั้ง';
}

/** สลับกระเป๋ากลางรายการ → ข้อความระดับหน้า (การ์ดโหนดของกระเป๋าเดิมถูกซ่อนไปแล้ว) */
function reportError(e, setLocal) {
    const msg = humanError(e);
    if (e?.code === 'WALLET_SWITCHED') { flowNotice.value = msg; return; }
    setLocal(msg);
}

function isStakeAmountRevert(e) {
    const blob = [e?.reason, e?.shortMessage, e?.message].filter(Boolean).join(' | ');
    return /Insufficient stake|Stake must equal tier minimum/.test(blob);
}

// ============================================================
//  ส่ง tx + รอผล — แยก "ยืนยันแล้ว" / "revert บนเชน" / "ยังไม่รู้ผล" ออกจากกัน
// ============================================================
let receiptTimer = null;
let receiptPolls = 0;
let checkingReceipt = false;

function stopReceiptPoll() {
    if (receiptTimer) { clearInterval(receiptTimer); receiptTimer = null; }
}

function startReceiptPoll() {
    stopReceiptPoll();
    receiptPolls = 0;
    receiptTimer = setInterval(checkPendingTx, RECEIPT_POLL_MS);
}

/**
 * @returns {Promise<{status:'confirmed'|'pending', hash:string}>}
 *   throw = รายการไม่สำเร็จแน่นอน (ยกเลิก / revert บนเชน)
 */
async function sendAndWait(guard, kind, send) {
    const tx = await send();
    pendingTx.value = { hash: tx.hash, kind, owner: guard.owner };
    try {
        await tx.wait();
        pendingTx.value = null;
        return { status: 'confirmed', hash: tx.hash };
    } catch (e) {
        if (e?.code === 'TRANSACTION_REPLACED') {
            // ผู้ใช้กด speed up / cancel ในกระเป๋า — ethers บอกผลของตัวที่มาแทนให้แล้ว
            pendingTx.value = null;
            if (e.cancelled) throw codeError('TX_CANCELLED');
            return { status: 'confirmed', hash: e.replacement?.hash || tx.hash };
        }
        if (e?.code === 'CALL_EXCEPTION' || e?.receipt) {
            // ขุดแล้วแต่ revert — จบแน่นอน ไม่ต้องรอต่อ (เชนนี้แก๊ส 0 จึงไม่มีเงินออก)
            pendingTx.value = null;
            throw e;
        }
        // ต่อเชนไม่ได้ระหว่างรอ — tx อาจผ่านไปแล้วก็ได้ ห้ามบอกว่า "ล้มเหลว"
        startReceiptPoll();
        return { status: 'pending', hash: tx.hash };
    }
}

/** ตรวจผล tx ที่ค้างอยู่ — เรียกจาก timer หรือปุ่ม "ตรวจอีกครั้ง" */
async function checkPendingTx() {
    const p = pendingTx.value;
    if (!p || checkingReceipt) return;
    checkingReceipt = true;
    try {
        const provider = await getReadProvider();
        const receipt = await provider.getTransactionReceipt(p.hash);
        if (pendingTx.value?.hash !== p.hash) return;
        if (!receipt) {
            if (++receiptPolls >= RECEIPT_POLL_MAX) {
                stopReceiptPoll();
                pendingTx.value = { ...p, gaveUp: true };
            }
            return;
        }

        stopReceiptPoll();
        pendingTx.value = null;
        if (Number(receipt.status) === 1) {
            await afterConfirmed({ owner: p.owner }, p.kind, p.hash);
        } else {
            // ข้อความระดับหน้า — การ์ดเดิมของรายการนี้อาจถูกซ่อนไปแล้ว (เช่น สลับกระเป๋า)
            const who = sameAddress(p.owner, walletStore.address) ? '' : `ของกระเป๋า ${shortAddr(p.owner)} `;
            flowNotice.value = `${TX_LABEL[p.kind] || 'รายการ'}${who}ไม่สำเร็จบนเชน — ไม่มีเงินออกจากกระเป๋า`;
        }
    } catch { /* RPC ยังล่ม — รอบหน้าลองใหม่ */ } finally {
        checkingReceipt = false;
    }
}

/** หลังเชนยืนยัน — ถ้าผู้ใช้สลับกระเป๋าไปแล้ว ห้ามแสดงผลเป็นของกระเป๋าใหม่ */
async function afterConfirmed(guard, kind, hash) {
    if (!sameAddress(guard.owner, walletStore.address)) {
        flowNotice.value = `${TX_LABEL[kind] || 'รายการ'}ของกระเป๋า ${shortAddr(guard.owner)} สำเร็จบนเชนแล้ว — ตอนนี้หน้าจอแสดงกระเป๋าที่คุณสลับมา`;
        return;
    }
    if (kind === 'stake') { stakeTxHash.value = hash; activeTab.value = 'setup'; }
    if (kind === 'claim') { claimIsError.value = false; claimMessage.value = 'รับรางวัลเรียบร้อย'; }

    const jobs = kind === 'consent'
        ? [fetchKycStatus()]
        : [fetchBalance(), fetchMyNode()];
    if (kind === 'stake' || kind === 'deregister') jobs.push(refreshTiers());
    await Promise.all(jobs);
}

// ============================================================
//  ซื้อ / ลงทะเบียนโหนด
// ============================================================
async function stakeAndRegister(tier) {
    if (!walletStore.isConnected) return connectWallet();
    // กดซ้ำ (ดับเบิลคลิก / กดรัว) ระหว่างรายการเดิมยังไม่จบ = ไม่ทำอะไร
    if (busy.value || txAwaiting.value) return;

    endpointTouched.value = true;
    stakeError.value = '';
    stakeTxHash.value = '';
    flowNotice.value = '';

    const blocked = blockReason(tier);
    if (blocked) { stakeError.value = blocked; return; }

    // ตั้งธงก่อน await แรกเสมอ — คลิกที่สองที่เข้ามาระหว่างนี้จะเจอ busy แล้วถอยออก
    isStaking.value = true;
    selectedTier.value = tier.id;
    const guard = walletGuard();
    // ส่ง "ตัวเลขชั้นบนสัญญา" (tier.id) ไม่ใช่ลำดับการ์ดบนหน้าจอ
    // และส่ง minStake เป็น wei ตรง ๆ — สัญญาบังคับ msg.value == minStake เป๊ะ
    const value = tier.minStakeWei;
    const ep = endpoint.value.trim();
    const args = [tier.id, ep, tier.lockDays, tier.slashBps, Math.floor(Date.now() / 1000) + 600];
    try {
        const signer = await signerFor(guard);
        const { Contract } = await loadEthers();
        guard.check();
        const registry = new Contract(props.registryAddress, NODE_REGISTRY_ABI, signer);

        // ลองยิงแห้งก่อน — จับ revert (ปิดรับ / ชั้นเต็ม / ขั้นต่ำเปลี่ยน) ตั้งแต่ยังไม่เสียเวลาผู้ใช้เซ็น
        await registry.registerNode.staticCall(...args, { value });
        guard.check();

        // เชนนี้ค่าแก๊สเป็น 0 (ตั้งใน genesis) จึงต้องบังคับ gasPrice = 0
        // ไม่งั้นกระเป๋าจะประเมินราคาแก๊สเองแล้วฟ้องว่ายอดไม่พอ
        const result = await sendAndWait(guard, 'stake', () => registry.registerNode(...args, { value, gasPrice: 0 }));
        if (result.status === 'pending') return; // การ์ด "รอการยืนยัน" ดูแลต่อ
        await afterConfirmed(guard, 'stake', result.hash);
    } catch (e) {
        // ขั้นต่ำบนเชนเปลี่ยนระหว่างเปิดหน้าค้างไว้ → โหลดใหม่ให้ปุ่มถัดไปส่งจำนวนที่ถูก
        if (isStakeAmountRevert(e)) refreshTiers();
        reportError(e, (m) => { stakeError.value = m; });
    } finally {
        isStaking.value = false;
        selectedTier.value = null;
    }
}

async function claimRewards() {
    if (!walletStore.isConnected || !props.registryAddress || !myNode.value) return;
    if (busy.value || txAwaiting.value) return;

    claimMessage.value = ''; claimIsError.value = false; flowNotice.value = '';
    // พูลยังไม่ถูกเติม = เคลมได้ 0 → ไม่ปล่อยให้เซ็น tx ที่ได้ศูนย์
    if (!(Number(myNode.value.claimable) > 0)) {
        claimIsError.value = true;
        claimMessage.value = POOL_EMPTY_MSG;
        return;
    }

    isClaiming.value = true;
    const guard = walletGuard();
    try {
        const signer = await signerFor(guard);
        const { Contract } = await loadEthers();
        guard.check();
        const registry = new Contract(props.registryAddress, NODE_REGISTRY_ABI, signer);
        const result = await sendAndWait(guard, 'claim', () => registry.claimRewards({ gasPrice: 0 }));
        if (result.status === 'pending') return;
        await afterConfirmed(guard, 'claim', result.hash);
    } catch (e) {
        reportError(e, (m) => { claimIsError.value = true; claimMessage.value = m; });
    } finally { isClaiming.value = false; }
}

async function deregisterNode() {
    if (!walletStore.isConnected || !props.registryAddress || !myNode.value) return;
    if (busy.value || txAwaiting.value) return;
    if (myNode.value.isLocked) {
        stakeError.value = `ยังไม่พ้นระยะล็อก — ถอนได้ ${unlockDateText.value}`;
        return;
    }
    const guard = walletGuard();
    const warn = myNode.value.pending > myNode.value.claimable
        ? '\n\nหมายเหตุ: รางวัลที่พูลจ่ายได้ตอนนี้น้อยกว่าที่คุณสะสมไว้ ส่วนที่เหลือจะค้างไว้เคลมทีหลังได้'
        : '';
    if (!confirm(`ยืนยันปิดโหนดและถอนเงินต้น ${fmtNum(myNode.value.stake)} TPIX?${warn}`)) return;
    if (busy.value || txAwaiting.value) return;

    isDeregistering.value = true; stakeError.value = ''; flowNotice.value = '';
    try {
        const signer = await signerFor(guard);
        const { Contract } = await loadEthers();
        guard.check();
        const registry = new Contract(props.registryAddress, NODE_REGISTRY_ABI, signer);
        const result = await sendAndWait(guard, 'deregister', () => registry.deregisterNode({ gasPrice: 0 }));
        if (result.status === 'pending') return;
        await afterConfirmed(guard, 'deregister', result.hash);
    } catch (e) {
        reportError(e, (m) => { stakeError.value = m; });
    } finally { isDeregistering.value = false; }
}

/** โหนดที่ถูกปรับ (slashed) ถอนเงินต้นที่เหลือด้วยฟังก์ชันคนละตัว */
async function withdrawSlashedStake() {
    if (!walletStore.isConnected || !props.registryAddress) return;
    if (busy.value || txAwaiting.value) return;

    isWithdrawing.value = true; stakeError.value = ''; flowNotice.value = '';
    const guard = walletGuard();
    try {
        const signer = await signerFor(guard);
        const { Contract } = await loadEthers();
        guard.check();
        const registry = new Contract(props.registryAddress, NODE_REGISTRY_ABI, signer);
        const result = await sendAndWait(guard, 'withdraw', () => registry.withdrawSlashedStake({ gasPrice: 0 }));
        if (result.status === 'pending') return;
        await afterConfirmed(guard, 'withdraw', result.hash);
    } catch (e) {
        reportError(e, (m) => { stakeError.value = m; });
    } finally { isWithdrawing.value = false; }
}

/** ขั้นที่ 1 ของ KYC — ผู้สมัครต้องเซ็นเอง กฎหมาย PDPA ให้ทีมงานกดแทนไม่ได้ */
async function giveKycConsent() {
    if (!walletStore.isConnected || !props.kycContract) return;
    if (busy.value || txAwaiting.value) return;

    isGivingConsent.value = true; kycError.value = ''; flowNotice.value = '';
    const guard = walletGuard();
    try {
        const signer = await signerFor(guard);
        const { Contract } = await loadEthers();
        guard.check();
        const kyc = new Contract(props.kycContract, KYC_ABI, signer);
        const result = await sendAndWait(guard, 'consent', () => kyc.giveConsent({ gasPrice: 0 }));
        if (result.status === 'pending') return;
        await afterConfirmed(guard, 'consent', result.hash);
    } catch (e) {
        reportError(e, (m) => { kycError.value = m; });
    } finally { isGivingConsent.value = false; }
}

// ============================================================
//  Derived
// ============================================================
const myTierSkin = computed(() => (myNode.value ? TIER_SKIN[myNode.value.tierId] : null));

const unlockDateText = computed(() => {
    if (!myNode.value?.unlockAt) return '-';
    return new Date(myNode.value.unlockAt * 1000).toLocaleDateString('th-TH', {
        year: 'numeric', month: 'short', day: 'numeric',
    });
});

const unlockCountdown = computed(() => {
    const s = myNode.value?.unlockInSeconds || 0;
    if (s <= 0) return '';
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    return d > 0 ? `อีก ${d} วัน ${h} ชม.` : `อีก ${h} ชม.`;
});

/** พูลรางวัลยังไม่ถูกเติมเงิน = กดเคลมได้ 0 ต้องบอกล่วงหน้าไม่ใช่ให้เสียเที่ยว */
const poolUnfunded = computed(() => poolState.value === 'unfunded');

const canRegister = computed(() => !myNode.value || myNode.value.statusId !== 1);

/** เหตุผลที่ปุ่ม "รับรางวัล" กดไม่ได้ — โชว์ใต้ปุ่ม ไม่ใช่ปล่อยให้งงว่าทำไมปุ่มเทา */
const claimHint = computed(() => {
    const n = myNode.value;
    // pending > claimable มีคำอธิบายของตัวเองอยู่แล้วใต้ยอด — ไม่พูดซ้ำ
    if (!n || Number(n.claimable) > 0 || Number(n.pending) > Number(n.claimable)) return '';
    return poolUnfunded.value ? 'พูลรางวัลยังไม่ถูกเติมเงิน — รางวัลที่สะสมได้จะไม่หาย กดรับได้เมื่อพูลมีเงิน' : '';
});

const heartbeatText = computed(() => {
    const ts = heartbeat.value?.lastHeartbeat;
    return ts ? new Date(ts * 1000).toLocaleString('th-TH', { dateStyle: 'medium', timeStyle: 'short' }) : '';
});

/**
 * สิ่งที่ยังขาดก่อนระบบจะเปิดใช้ได้ — โชว์ในป้าย "รอติดตั้งสัญญา"
 * แยก "ยังไม่ได้ตั้งที่อยู่" ออกจาก "ตั้งแล้วแต่ไม่มีโค้ดที่ address นั้น" เพราะแก้คนละแบบ
 * (สถานะ RPC มาจาก eth_blockNumber ฝั่งเซิร์ฟเวอร์ ไม่ใช่ eth_getCode — getCode ของที่อยู่
 *  ที่ไม่ใช่สัญญาบน polygon-edge ตอบ error ซึ่งไม่ได้แปลว่า RPC ล่ม)
 */
const deployIssues = computed(() => {
    if (props.registryLive) return [];

    const out = [];
    if (!props.registryAddress) {
        out.push('ยังไม่ได้ลงทะเบียนที่อยู่สัญญา NodeRegistry — สคริปต์ deploy จะลงทะเบียนให้เองเมื่อรันเสร็จ');
    } else if (props.registryUnknown) {
        out.push(`ตั้งที่อยู่ไว้ที่ ${props.registryAddress} แต่ตรวจโค้ดสัญญาไม่ได้ (RPC ไม่ตอบ) — ยังไม่รู้ว่ามีสัญญาหรือไม่`);
    } else {
        out.push(`ตั้งที่อยู่ไว้ที่ ${props.registryAddress} แต่ไม่พบโค้ดสัญญาที่ที่อยู่นั้น`);
    }
    if (!networkStats.value.rpc_connected) {
        out.push('เชื่อมต่อ RPC ของเชน TPIX ไม่ได้');
    }
    return out;
});

/**
 * โชว์การ์ด "โหนดของฉัน" เมื่อยังมีอะไรให้จัดการจริง ๆ เท่านั้น
 * หลังปิดโหนดสำเร็จ struct ยังอยู่บนเชน (status=Inactive, stake=0) — ถ้าไม่กรอง
 * ผู้ใช้จะเห็นการ์ดเปล่าเขียนว่า "ยังไม่ลงทะเบียน · วางค้ำ 0 TPIX" ค้างอยู่
 */
const showNodeCard = computed(() => {
    const n = myNode.value;
    if (!n) return false;
    return n.statusId === 1 || n.statusId === 2 || n.statusId === 4 || n.pending > 0 || n.stake > 0;
});

// ============================================================
//  Lifecycle
// ============================================================
function reloadWalletState() {
    fetchBalance();
    fetchMyNode();
    fetchKycStatus();
    fetchHeartbeat();
}

/** ปุ่ม "ลองใหม่" ของการ์ดอ่านเชนไม่ได้ */
function retryChainRead() {
    reloadWalletState();
    refreshTiers();
}

watch(() => walletStore.address, (a, prev) => {
    if (sameAddress(a, prev)) return;
    // สลับ/ตัดกระเป๋า → ล้างของกระเป๋าเดิมทันที ไม่ให้ยอด/โหนด/ข้อความของคนเก่าค้างบนจอคนใหม่
    // (tx ที่ค้างรอผลยังเก็บไว้ — การ์ดบอกชัดว่าเป็นของกระเป๋าไหน)
    tpixBalanceWei.value = null; balanceError.value = false;
    myNode.value = null; nodeLoaded.value = false; chainReadError.value = false;
    kycApproved.value = null; kycStatusId.value = null; kycRejectReason.value = '';
    stakeError.value = ''; stakeTxHash.value = ''; claimMessage.value = ''; claimIsError.value = false;
    kycError.value = ''; flowNotice.value = ''; heartbeat.value = null;
    if (a) reloadWalletState();
});

let pollInterval;
onMounted(() => {
    if (walletStore.isConnected) reloadWalletState();
    refreshTiers();
    pollInterval = setInterval(async () => {
        try {
            const r = await fetch('/api/v1/masternode/stats');
            if (r.ok) { const d = await r.json(); if (d.success) networkStats.value = d.data; }
        } catch { /* เชนล่มชั่วคราว — คงตัวเลขเดิมไว้ */ }
        if (walletStore.isConnected) fetchMyNode();
        // endpoint สัญญาณชีพจำกัด 10 ครั้ง/นาที/IP ร่วมกับโปรแกรม PC — ถามเฉพาะตอนมีการ์ดโหนดให้ดู
        if (walletStore.isConnected && showNodeCard.value) fetchHeartbeat();
    }, 30000);
});
onUnmounted(() => {
    clearInterval(pollInterval);
    stopReceiptPoll();
});

function fmtNum(n) {
    const v = Number(n);
    if (!isFinite(v)) return '0';
    return v.toLocaleString(undefined, { maximumFractionDigits: v < 1 ? 6 : 2 });
}
</script>

<template>
    <Head title="Master Node" />
    <AppLayout>
        <div class="relative min-h-screen overflow-hidden">

            <div class="fixed inset-0 pointer-events-none -z-10">
                <div class="absolute top-1/4 left-1/4 w-[600px] h-[600px] bg-accent-500/8 rounded-full blur-3xl animate-float" />
                <div class="absolute top-1/2 right-1/3 w-[700px] h-[700px] bg-primary-500/6 rounded-full blur-3xl" style="animation: float 8s ease-in-out infinite reverse" />
                <div class="absolute bottom-1/4 right-1/4 w-[500px] h-[500px] bg-warm-500/5 rounded-full blur-3xl animate-float" style="animation-delay: -3s" />
            </div>

            <div class="max-w-6xl mx-auto px-4 py-8 space-y-8 relative z-10">

                <!-- ============================================================ -->
                <!--  แถบเตือนสถานะระบบ — ต้องบอกความจริงว่าตอนนี้ซื้อได้หรือยัง    -->
                <!-- ============================================================ -->
                <AwaitingDeployNotice
                    v-if="!registryLive && !registryUnknown"
                    action="buy"
                    :rpc-connected="!!networkStats.rpc_connected"
                    :issues="deployIssues"
                    :show-details="deployIssues.length > 0"
                />

                <!-- อ่านสถานะสัญญาไม่ได้ ≠ ยังไม่ติดตั้ง — อาจมีคนวางค้ำอยู่แล้ว ห้ามบอกว่า "รอติดตั้ง" -->
                <div v-if="!registryLive && registryUnknown" data-test="registry-unknown"
                     class="glass rounded-2xl p-5 border-l-4 border-yellow-500">
                    <div class="text-yellow-400 font-bold text-sm">อ่านสถานะสัญญามาสเตอร์โหนดจากเชนไม่ได้ชั่วคราว</div>
                    <div class="text-xs text-gray-400 mt-1">
                        ปิดปุ่มวางค้ำไว้ก่อนจนกว่าจะอ่านเชนได้ — เงินที่วางค้ำไว้แล้วอยู่ในสัญญาบนเชนตามเดิม รีเฟรชหน้าอีกครั้งในอีกสักครู่
                    </div>
                </div>

                <!-- สัญญาเปิดแล้วแต่พูลรางวัลยังไม่มีเงิน — ต้องบอกก่อนที่ผู้ใช้จะเห็นตัวเลข % ใด ๆ -->
                <div v-if="registryLive && poolUnfunded" data-test="pool-unfunded"
                     class="glass rounded-2xl p-5 border-l-4 border-yellow-500">
                    <div class="text-yellow-400 font-bold text-sm">พูลรางวัลยังไม่ถูกเติมเงิน — ตอนนี้ยังไม่มีรางวัลให้รับ</div>
                    <div class="text-xs text-gray-400 mt-1 leading-relaxed">
                        สัญญาจ่ายรางวัลได้เท่าที่พูลมีเงินจริงเท่านั้น ตอนนี้จ่ายได้ 0 TPIX
                        ตัวเลข % บนหน้านี้คือเพดานตามสัญญา ไม่ใช่ผลตอบแทนที่รับประกัน
                        รางวัลที่สะสมระหว่างนี้จะกดรับได้เมื่อพูลมีเงิน
                        ส่วนเงินต้นที่วางค้ำถูกกันไว้ต่างหากและถอนคืนได้เมื่อพ้นระยะล็อก
                    </div>
                </div>

                <!-- ============================================================ -->
                <!--  HERO: ยังไม่เชื่อมกระเป๋า                                    -->
                <!-- ============================================================ -->
                <div v-if="!walletStore.isConnected" class="relative">
                    <div class="absolute -inset-2 bg-gradient-to-r from-accent-500/20 via-primary-500/15 to-warm-500/20 rounded-3xl blur-xl opacity-60" />

                    <div class="glass-brand relative rounded-3xl p-10 md:p-16 text-center overflow-hidden">
                        <div class="absolute top-6 left-10 w-2 h-2 bg-cyan-400/40 rounded-full animate-float" />
                        <div class="absolute top-20 right-16 w-1.5 h-1.5 bg-purple-400/40 rounded-full animate-float" style="animation-delay: -2s" />
                        <div class="absolute bottom-12 left-1/4 w-1 h-1 bg-yellow-400/40 rounded-full animate-float" style="animation-delay: -4s" />

                        <div class="relative inline-block mb-6">
                            <div class="absolute -inset-4 bg-gradient-to-r from-accent-500/30 via-primary-500/30 to-warm-500/30 rounded-full blur-2xl animate-glow-brand" />
                            <BrandLogo variant="mark" alt="TPIX" class="relative w-24 h-24 rounded-full shadow-2xl ring-2 ring-white/10" />
                        </div>

                        <h1 class="text-4xl md:text-5xl font-black mb-3">
                            <span class="text-gradient-brand">Master Node</span>
                        </h1>
                        <p class="text-lg text-gray-300 mb-2 max-w-xl mx-auto">
                            ค้ำเครือข่าย TPIX แล้วรับรางวัล
                        </p>
                        <p class="text-sm text-gray-500 mb-8 max-w-md mx-auto">
                            เชื่อมกระเป๋า เลือกชั้น กรอกที่อยู่เครื่อง แล้วลงทะเบียนได้เลย
                        </p>

                        <div class="grid grid-cols-3 gap-3 sm:gap-4 max-w-md mx-auto mb-8">
                            <!-- พูลยังว่าง = ไม่โชว์ % สีเขียวเป็นตัวชูโรง (จะอ่านเป็นผลตอบแทนที่ได้แน่) -->
                            <div v-if="poolUnfunded" class="glass-sm rounded-xl p-3" data-test="hero-pool-unfunded">
                                <div class="text-xs text-gray-400">พูลรางวัล</div>
                                <div class="text-base font-black text-yellow-400 leading-7">ยังไม่เติมเงิน</div>
                            </div>
                            <div v-else class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">เพดานต่อปี</div>
                                <div class="text-xl font-black" :class="poolState === 'funded' ? 'text-trading-green' : 'text-gray-300'">{{ heroMaxApr }}</div>
                            </div>
                            <div class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">โหนดที่ทำงานอยู่</div>
                                <div class="text-xl font-black text-gradient">{{ statNum(networkStats.total_nodes) }}</div>
                            </div>
                            <div class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">ขั้นต่ำ</div>
                                <div class="text-xl font-black text-cyan-400">{{ minEntryText }}</div>
                            </div>
                        </div>

                        <button @click="connectWallet"
                                class="btn-brand px-10 py-4 text-lg font-bold rounded-2xl shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-300">
                            เชื่อมกระเป๋า
                        </button>
                        <p class="text-xs text-gray-500 mt-4">
                            MetaMask · Trust Wallet · Coinbase · OKX · TPIX Wallet
                        </p>

                        <div class="flex items-center justify-center gap-4 mt-6 flex-wrap">
                            <Link href="/masternode/guide"
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500/10 transition">
                                📖 คู่มือการตั้งค่า
                            </Link>
                            <a href="/api/v1/app/chain-download?type=masternode"
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-white/10 text-gray-400 hover:bg-white/5 transition">
                                📥 ดาวน์โหลดโปรแกรม PC
                            </a>
                        </div>
                    </div>
                </div>

                <!-- ============================================================ -->
                <!--  เชื่อมกระเป๋าแล้ว                                            -->
                <!-- ============================================================ -->
                <template v-else>

                    <div class="relative group">
                        <div class="absolute -inset-0.5 bg-gradient-to-r from-accent-500/20 via-primary-500/20 to-warm-500/20 rounded-2xl blur opacity-0 group-hover:opacity-100 transition-opacity duration-500" />
                        <div class="glass relative rounded-2xl p-5 flex flex-wrap items-center justify-between gap-4">
                            <div class="flex items-center gap-4">
                                <div class="relative">
                                    <div class="absolute -inset-1 bg-gradient-to-r from-cyan-500 to-blue-500 rounded-full blur-sm opacity-50" />
                                    <BrandLogo variant="icon" alt="TPIX" class="relative w-12 h-12 rounded-full ring-2 ring-white/20" />
                                </div>
                                <div>
                                    <div class="font-mono text-sm text-cyan-400 font-semibold">{{ walletStore.shortAddress }}</div>
                                    <div class="text-xs flex items-center gap-2 mt-0.5">
                                        <span v-if="isOnTPIXChain" class="flex items-center gap-1 text-green-400">
                                            <span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse" />
                                            TPIX Chain
                                        </span>
                                        <span v-else class="text-yellow-400 flex items-center gap-1">
                                            <span class="w-1.5 h-1.5 rounded-full bg-yellow-400 animate-pulse" />
                                            อยู่ผิดเครือข่าย
                                            <button @click="switchNetwork" class="underline hover:text-yellow-300 ml-1">สลับ</button>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="text-right">
                                <div class="text-[10px] text-gray-400 uppercase tracking-widest">TPIX ของคุณ</div>
                                <div class="text-3xl font-black leading-tight" :class="tpixBalance > 0 ? 'text-gradient' : 'text-gray-600'">
                                    <span v-if="tpixBalanceWei === null && isLoadingBalance" class="animate-pulse text-gray-500">···</span>
                                    <span v-else-if="tpixBalanceWei === null" class="text-gray-600" :title="balanceError ? 'อ่านยอดไม่ได้' : ''">—</span>
                                    <span v-else>{{ fmtNum(Math.floor(tpixBalance)) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-center gap-4 flex-wrap">
                        <Link href="/masternode/guide"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500/10 transition">
                            📖 คู่มือการตั้งค่า
                        </Link>
                        <a href="/api/v1/app/chain-download?type=masternode"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-white/10 text-gray-400 hover:bg-white/5 transition">
                            📥 ดาวน์โหลดโปรแกรม PC
                        </a>
                    </div>

                    <div v-if="!isOnTPIXChain"
                         class="glass rounded-2xl p-5 border-l-4 border-yellow-500 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-yellow-500/20 flex items-center justify-center text-2xl shrink-0">⚡</div>
                        <div class="flex-1">
                            <div class="text-yellow-400 font-bold">สลับไป TPIX Chain</div>
                            <div class="text-xs text-gray-400">ถ้ายังไม่มีในกระเป๋า ระบบจะเพิ่มให้อัตโนมัติ</div>
                        </div>
                        <button @click="switchNetwork" class="btn-primary px-6 py-2.5 rounded-xl font-bold shrink-0">
                            สลับเครือข่าย
                        </button>
                    </div>

                    <!-- Tabs -->
                    <div class="flex gap-1 p-1 glass-sm rounded-xl">
                        <button v-for="tab in [{id:'setup',label:'โหนดของฉัน',icon:'🚀'},{id:'network',label:'เครือข่าย',icon:'🌐'}]" :key="tab.id"
                                @click="activeTab = tab.id"
                                :class="['flex-1 py-2.5 rounded-lg text-sm font-semibold transition-all',
                                         activeTab === tab.id ? 'glass text-white shadow-lg' : 'text-gray-400 hover:text-white']">
                            {{ tab.icon }} {{ tab.label }}
                        </button>
                    </div>

                    <!-- ============================================================ -->
                    <!--  TAB: โหนดของฉัน                                             -->
                    <!-- ============================================================ -->
                    <div v-show="activeTab === 'setup'" class="space-y-6">

                        <!-- ข้อความระดับหน้า เช่น สลับกระเป๋ากลางรายการ / ผลของ tx ที่ตรวจซ้ำ -->
                        <div v-if="flowNotice" data-test="flow-notice"
                             class="glass rounded-2xl p-4 border-l-4 border-yellow-500 flex items-start gap-3">
                            <div class="flex-1 text-sm text-yellow-300">{{ flowNotice }}</div>
                            <button @click="flowNotice = ''" class="text-xs text-gray-500 hover:text-white shrink-0">ปิด</button>
                        </div>

                        <!-- ส่ง tx แล้วแต่ยังไม่รู้ผล — ห้ามบอกว่าล้มเหลว เพราะอาจผ่านไปแล้ว -->
                        <div v-if="pendingTx" data-test="pending-tx"
                             class="glass rounded-2xl p-5 border-l-4 border-cyan-500 space-y-2">
                            <div class="flex items-center gap-2 text-cyan-300 font-bold text-sm">
                                <span v-if="!pendingTx.gaveUp" class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse" />
                                {{ TX_LABEL[pendingTx.kind] || 'รายการ' }} — {{ pendingTx.gaveUp ? 'ยังไม่พบผลบนเชน' : 'รอการยืนยัน' }}
                            </div>
                            <p class="text-xs text-gray-400">
                                <template v-if="pendingTx.gaveUp">
                                    ตรวจซ้ำครบ 10 นาทีแล้วยังไม่พบผล — ดูสถานะจริงบนเอ็กซ์พลอเรอร์ หรือกดตรวจอีกครั้ง
                                </template>
                                <template v-else>
                                    ส่งรายการเข้าเชนแล้วแต่ยังเชื่อมต่อเพื่อรอผลไม่ได้ ระบบกำลังตรวจซ้ำให้อัตโนมัติ — ไม่ต้องกดส่งใหม่
                                </template>
                                <span v-if="!sameAddress(pendingTx.owner, walletStore.address)" class="block mt-1">
                                    รายการนี้เป็นของกระเป๋า {{ shortAddr(pendingTx.owner) }}
                                </span>
                            </p>
                            <div class="flex items-center gap-3 flex-wrap">
                                <a :href="`${explorerUrl}/tx/${pendingTx.hash}`" target="_blank" rel="noopener"
                                   class="text-xs text-cyan-400 hover:text-cyan-300 font-mono break-all">{{ pendingTx.hash }}</a>
                                <button @click="checkPendingTx" class="text-xs font-semibold text-white underline">ตรวจอีกครั้ง</button>
                                <button v-if="pendingTx.gaveUp" @click="pendingTx = null" class="text-xs text-gray-500 hover:text-white">ซ่อน</button>
                            </div>
                        </div>

                        <!-- อ่านเชนไม่ได้ ≠ ไม่มีโหนด — บอกตรง ๆ แล้วให้ลองใหม่ ไม่ใช่เงียบแล้วโชว์ฟอร์มซื้อ -->
                        <div v-if="chainReadError" data-test="chain-read-error"
                             class="glass rounded-2xl p-5 border-l-4 border-yellow-500 flex flex-wrap items-center gap-4">
                            <div class="flex-1 min-w-[200px]">
                                <div class="text-yellow-400 font-bold">อ่านข้อมูลจากเชนไม่ได้</div>
                                <div class="text-xs text-gray-400 mt-0.5">
                                    ยังไม่รู้ว่ากระเป๋านี้มีโหนดอยู่แล้วหรือไม่ จึงปิดปุ่มลงทะเบียนไว้ก่อน
                                    — ข้อมูลที่เห็นด้านล่างอาจไม่เป็นปัจจุบัน
                                </div>
                            </div>
                            <button @click="retryChainRead" :disabled="isLoadingNode" data-test="retry-chain-read"
                                    class="btn-primary px-6 py-2.5 rounded-xl font-bold shrink-0 disabled:opacity-50">
                                {{ isLoadingNode ? 'กำลังลองใหม่...' : 'ลองใหม่' }}
                            </button>
                        </div>

                        <!-- แอดมินปิดรับลงทะเบียนใหม่ — เงินของคนที่อยู่ในระบบแล้วยังออกได้เสมอ -->
                        <div v-if="registryLive && isRegistrationPaused" data-test="registration-paused"
                             class="glass rounded-2xl p-5 border-l-4 border-red-500/60">
                            <div class="text-red-300 font-bold text-sm">ปิดรับลงทะเบียนโหนดใหม่ชั่วคราว</div>
                            <div class="text-xs text-gray-400 mt-1">
                                โหนดที่ลงทะเบียนไว้แล้วยังรับรางวัล ปิดโหนด และถอนเงินต้นได้ตามปกติ
                            </div>
                        </div>

                        <!-- ─── โหนดที่มีอยู่แล้ว ─── -->
                        <div v-if="showNodeCard" class="relative">
                            <div :class="['absolute -inset-1 bg-gradient-to-b rounded-3xl blur-xl opacity-50', myTierSkin?.gradient]" />
                            <div :class="['glass relative rounded-2xl p-6 space-y-5', myTierSkin?.border]">

                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div class="flex items-center gap-4">
                                        <BrandLogo variant="mark" alt="TPIX" class="w-14 h-14 rounded-full ring-1 ring-white/10" />
                                        <div>
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <h3 class="text-xl font-bold text-white">{{ myTierSkin?.name }}</h3>
                                                <span :class="['px-2.5 py-0.5 rounded-full text-[10px] font-black border', myTierSkin?.badge]">
                                                    {{ STATUS_LABEL[myNode.statusId] }}
                                                </span>
                                            </div>
                                            <div class="text-sm text-gray-400 mt-0.5">
                                                วางค้ำ <span class="text-white font-bold">{{ fmtNum(myNode.stake) }} TPIX</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-[10px] text-gray-500 uppercase tracking-widest">Uptime</div>
                                        <div class="text-2xl font-black" :class="myNode.uptime >= 95 ? 'text-trading-green' : 'text-yellow-400'">
                                            {{ myNode.uptime }}%
                                        </div>
                                    </div>
                                </div>

                                <!-- รายละเอียดบนเชน -->
                                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                                    <div class="glass-sm rounded-xl p-3">
                                        <div class="text-[10px] text-gray-500 uppercase">ที่อยู่โหนด</div>
                                        <div class="text-sm text-white font-mono break-all">{{ myNode.endpoint || '—' }}</div>
                                    </div>
                                    <div class="glass-sm rounded-xl p-3">
                                        <div class="text-[10px] text-gray-500 uppercase">ลงทะเบียนเมื่อ</div>
                                        <div class="text-sm text-white">
                                            {{ new Date(myNode.registeredAt * 1000).toLocaleDateString('th-TH') }}
                                        </div>
                                    </div>
                                    <div class="glass-sm rounded-xl p-3">
                                        <div class="text-[10px] text-gray-500 uppercase">ปลดล็อกเงินต้น</div>
                                        <div class="text-sm" :class="myNode.isLocked ? 'text-yellow-400' : 'text-trading-green'">
                                            {{ unlockDateText }}
                                            <span v-if="myNode.isLocked" class="block text-[10px] text-gray-500">{{ unlockCountdown }}</span>
                                        </div>
                                    </div>
                                    <div class="glass-sm rounded-xl p-3">
                                        <div class="text-[10px] text-gray-500 uppercase">รับไปแล้วทั้งหมด</div>
                                        <div class="text-sm text-white">{{ fmtNum(myNode.totalRewards) }} TPIX</div>
                                    </div>
                                </div>

                                <!-- สัญญาณชีพจากโปรแกรม PC — ข้อมูลเสริม อ่านไม่ได้ก็ซ่อน -->
                                <div v-if="heartbeat" data-test="heartbeat" class="flex items-start gap-2 text-[11px]">
                                    <span :class="['w-2 h-2 rounded-full mt-1 shrink-0', heartbeat.isActive ? 'bg-trading-green animate-pulse' : 'bg-gray-500']" />
                                    <div>
                                        <span v-if="heartbeat.exists && heartbeat.lastHeartbeat" class="text-gray-300">
                                            โปรแกรม Master Node ส่งสัญญาณล่าสุด {{ heartbeatText }}
                                        </span>
                                        <span v-else class="text-yellow-400/90">
                                            ยังไม่ได้รับสัญญาณจากโปรแกรม — เปิดโปรแกรม TPIX Master Node เวอร์ชันล่าสุดด้วยกระเป๋าเดียวกัน
                                        </span>
                                        <span class="block text-gray-500">
                                            คะแนน uptime บนเชนทีมงานเป็นผู้บันทึก ไม่ได้อัปเดตอัตโนมัติจากสัญญาณนี้
                                        </span>
                                    </div>
                                </div>

                                <!-- รางวัล: แยก "สะสมได้" ออกจาก "เคลมได้จริงตอนนี้" -->
                                <div class="p-4 rounded-xl bg-trading-green/5 border border-trading-green/20 space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <div class="text-xs text-gray-400">รางวัลสะสม</div>
                                            <div class="text-lg font-black text-trading-green">{{ fmtNum(myNode.pending) }} TPIX</div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-400">กดรับได้ตอนนี้</div>
                                            <div class="text-lg font-black" :class="myNode.claimable > 0 ? 'text-white' : 'text-gray-600'">
                                                {{ fmtNum(myNode.claimable) }} TPIX
                                            </div>
                                        </div>
                                        <button
                                            @click="claimRewards"
                                            :disabled="busy || txAwaiting || !(myNode.claimable > 0)"
                                            :title="myNode.claimable > 0 ? '' : 'ยังไม่มีรางวัลที่กดรับได้ตอนนี้'"
                                            class="px-5 py-2.5 rounded-xl text-sm font-bold transition-all bg-trading-green/20 text-trading-green border border-trading-green/30 hover:bg-trading-green/30 disabled:opacity-40 disabled:cursor-not-allowed"
                                        >
                                            {{ isClaiming ? 'กำลังรับ...' : 'รับรางวัล' }}
                                        </button>
                                    </div>
                                    <p v-if="myNode.pending > myNode.claimable" class="text-[11px] text-yellow-400/90">
                                        พูลรางวัลมีเงินไม่พอจ่ายเต็มจำนวนในตอนนี้ ส่วนที่เหลือไม่หาย — ค้างไว้เคลมได้เมื่อพูลถูกเติม
                                        (เงินต้นของผู้วางค้ำถูกกันไว้ ไม่ถูกนำมาจ่ายรางวัล)
                                    </p>
                                    <p v-if="claimHint" class="text-[11px] text-yellow-400/90">{{ claimHint }}</p>
                                </div>

                                <div v-if="claimMessage"
                                     :class="['text-xs px-3 py-2 rounded-lg', claimIsError ? 'bg-trading-red/10 text-trading-red' : 'bg-trading-green/10 text-trading-green']">
                                    {{ claimMessage }}
                                </div>

                                <!-- ถูกปรับ (slashed) → ถอนเงินต้นที่เหลือด้วยฟังก์ชันคนละตัว -->
                                <div v-if="myNode.statusId === 2 || myNode.statusId === 4"
                                     class="p-4 rounded-xl bg-trading-red/5 border border-trading-red/20 flex flex-wrap items-center justify-between gap-3">
                                    <div class="text-xs text-trading-red">
                                        โหนดนี้ถูกปรับจากการทำงานผิดกติกา — ถอนเงินต้นส่วนที่เหลือได้
                                    </div>
                                    <button @click="withdrawSlashedStake" :disabled="busy || txAwaiting"
                                            class="px-5 py-2 rounded-xl text-sm font-bold bg-trading-red/20 text-trading-red border border-trading-red/30 hover:bg-trading-red/30 disabled:opacity-40">
                                        {{ isWithdrawing ? 'กำลังถอน...' : 'ถอนเงินต้นที่เหลือ' }}
                                    </button>
                                </div>

                                <!-- ปิดโหนด — ปิดปุ่มไว้จนกว่าจะพ้นล็อก ไม่ปล่อยให้เสียเที่ยวเซ็น -->
                                <button
                                    v-if="myNode.statusId === 1"
                                    @click="deregisterNode"
                                    :disabled="busy || txAwaiting || myNode.isLocked"
                                    :title="myNode.isLocked ? `ถอนได้ ${unlockDateText}` : ''"
                                    class="w-full py-2.5 rounded-xl text-xs font-medium border transition-all
                                           text-gray-500 border-white/5 hover:text-trading-red hover:border-trading-red/30
                                           disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-gray-500 disabled:hover:border-white/5"
                                >
                                    <template v-if="isDeregistering">กำลังดำเนินการ...</template>
                                    <template v-else-if="myNode.isLocked">ปิดโหนดได้เมื่อพ้นล็อก · {{ unlockCountdown }}</template>
                                    <template v-else>ปิดโหนดและถอนเงินต้น</template>
                                </button>

                                <!-- ขั้นตอนต่อไปหลังซื้อ — ไม่ใช่จบแค่จ่ายเงิน -->
                                <div class="glass-sm rounded-xl p-4 space-y-2">
                                    <div class="text-xs font-bold text-white">ขั้นตอนต่อไป — เปิดเครื่องให้โหนดทำงานจริง</div>
                                    <ol class="text-[11px] text-gray-400 space-y-1 list-decimal list-inside">
                                        <li>ดาวน์โหลดโปรแกรม TPIX Master Node แล้วติดตั้งบนเครื่องที่ IP ตรงกับ <span class="font-mono text-cyan-400">{{ myNode.endpoint || '—' }}</span></li>
                                        <li>ในโปรแกรม ใส่กระเป๋า <span class="font-mono text-cyan-400">{{ walletStore.shortAddress }}</span> เพื่อผูกกับโหนดที่ลงทะเบียนไว้</li>
                                        <li>ปล่อยให้โปรแกรมส่งสัญญาณชีพต่อเนื่อง — คะแนน uptime มีผลกับรางวัลโดยตรง (ทีมงานเป็นผู้บันทึกขึ้นเชน)</li>
                                    </ol>
                                    <div class="flex gap-3 pt-1 flex-wrap">
                                        <a href="/api/v1/app/chain-download?type=masternode"
                                           class="text-[11px] font-semibold text-cyan-400 hover:text-cyan-300 underline">ดาวน์โหลดโปรแกรม</a>
                                        <Link href="/masternode/guide" class="text-[11px] font-semibold text-cyan-400 hover:text-cyan-300 underline">อ่านคู่มือ</Link>
                                        <a :href="`${explorerUrl}/address/${myNode.operator}`" target="_blank" rel="noopener"
                                           class="text-[11px] font-semibold text-gray-400 hover:text-white underline">ดูบนเอ็กซ์พลอเรอร์</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ─── ฟอร์มลงทะเบียนใหม่ ─── -->
                        <template v-if="canRegister">
                            <div class="text-center">
                                <h2 class="text-2xl font-bold text-white">เลือกชั้นของโหนด</h2>
                                <p class="text-sm text-gray-400 mt-1">
                                    คุณมี <span class="text-gradient font-bold">{{ tpixBalanceWei === null ? '—' : fmtNum(Math.floor(tpixBalance)) }} TPIX</span>
                                </p>
                                <p class="text-[11px] text-gray-500 mt-2 max-w-2xl mx-auto">
                                    วางค้ำเท่ากับขั้นต่ำของชั้นพอดี (รางวัลแบ่งเท่ากันทุกโหนด วางเกินไม่ได้อะไรเพิ่ม)
                                    · "คาดการณ์ถ้าเข้าตอนนี้" คิดที่ uptime 100% และพูลมีเงินพอจ่าย
                                    ถ้ามีโหนดเข้ามาเพิ่ม ส่วนแบ่งต่อโหนดจะลดลง แต่ไม่เกินเพดานของชั้นเสมอ
                                </p>
                            </div>

                            <!-- ที่อยู่โหนด: สัญญาบังคับ ต้องกรอกก่อนถึงจะกดซื้อได้ -->
                            <div class="glass rounded-2xl p-5 space-y-2">
                                <label for="mn-endpoint" class="block text-sm font-bold text-white">
                                    ที่อยู่เครื่องที่จะรันโหนด <span class="text-trading-red">*</span>
                                </label>
                                <p class="text-xs text-gray-400">
                                    ที่อยู่นี้ถูกบันทึกลงเชนถาวรและใช้ให้เครือข่ายติดต่อโหนดของคุณ
                                    กรอกเป็น <span class="font-mono text-cyan-400">IP:port</span> หรือ
                                    <span class="font-mono text-cyan-400">domain:port</span>
                                </p>
                                <input
                                    id="mn-endpoint"
                                    v-model="endpoint"
                                    @blur="endpointTouched = true"
                                    :maxlength="MAX_ENDPOINT_LEN"
                                    type="text"
                                    inputmode="url"
                                    autocomplete="off"
                                    placeholder="203.0.113.10:8545"
                                    class="trading-input w-full font-mono"
                                    :class="endpointError ? 'border-trading-red/60' : ''"
                                />
                                <div class="flex justify-between items-center">
                                    <span class="text-xs text-trading-red">{{ endpointError }}</span>
                                    <span class="text-[10px] text-gray-600">{{ endpoint.length }}/{{ MAX_ENDPOINT_LEN }}</span>
                                </div>
                            </div>

                            <!-- ─── KYC สำหรับชั้น Validator ───
                                 โผล่เฉพาะตอนสัญญา KYC ถูกผูกแล้วและยังไม่อนุมัติ
                                 ขั้นแรกผู้สมัครต้องเซ็นเอง (PDPA) ทีมงานกดแทนไม่ได้ -->
                            <div v-if="kycContract && kycApproved === false"
                                 class="glass rounded-2xl p-5 border-l-4 border-red-500/60 space-y-3">
                                <div class="flex items-start gap-3">
                                    <div class="w-10 h-10 rounded-xl bg-red-500/20 flex items-center justify-center text-xl shrink-0">🪪</div>
                                    <div class="flex-1">
                                        <div class="text-white font-bold text-sm">
                                            ชั้น Validator — ต้องผ่าน KYC ก่อน
                                        </div>
                                        <p class="text-xs text-gray-400 mt-1">
                                            สถานะปัจจุบัน:
                                            <span class="text-white font-semibold">{{ (KYC_STATUS[kycStatusId] || KYC_STATUS[0]).label }}</span>
                                            — {{ (KYC_STATUS[kycStatusId] || KYC_STATUS[0]).hint }}
                                        </p>
                                        <p v-if="kycRejectReason" class="text-xs text-trading-red mt-1">
                                            เหตุผลที่ไม่ผ่าน: {{ kycRejectReason }}
                                        </p>
                                    </div>
                                </div>

                                <ol class="text-[11px] text-gray-400 space-y-1 list-decimal list-inside pl-1">
                                    <li>ให้ความยินยอม PDPA บนเชนด้วยกระเป๋าของคุณเอง</li>
                                    <li>ส่งเอกสารนิติบุคคลให้ทีมงานตรวจ</li>
                                    <li>ทีมงานบันทึกและอนุมัติ แล้วปุ่มลงทะเบียน Validator จะเปิด</li>
                                </ol>

                                <div class="flex items-center gap-3 flex-wrap">
                                    <button
                                        v-if="kycStatusId === null || kycStatusId === 0 || kycStatusId === 5"
                                        @click="giveKycConsent"
                                        :disabled="busy || txAwaiting"
                                        class="px-5 py-2.5 rounded-xl text-sm font-bold bg-red-500/20 text-red-300 border border-red-500/40 hover:bg-red-500/30 disabled:opacity-40">
                                        {{ isGivingConsent ? 'กำลังเซ็น...' : 'ให้ความยินยอม PDPA' }}
                                    </button>
                                    <span v-else-if="kycStatusId === 1 || kycStatusId === 2" class="text-xs text-yellow-400">
                                        ✓ ให้ความยินยอมแล้ว — รอทีมงานตรวจเอกสาร
                                    </span>
                                    <Link href="/validators/apply"
                                          class="text-xs font-semibold text-cyan-400 hover:text-cyan-300 underline">
                                        ส่งเอกสารสมัคร Validator
                                    </Link>
                                </div>

                                <div v-if="kycError" class="text-xs text-trading-red">{{ kycError }}</div>
                            </div>

                            <!-- การ์ดแต่ละชั้น -->
                            <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
                                <div v-for="tier in tierCards" :key="tier.id"
                                     class="group relative rounded-2xl overflow-hidden transition-all duration-500"
                                     :class="tier.canAfford && !tier.isFull ? 'hover:scale-[1.02] hover:-translate-y-1' : 'opacity-50'">

                                    <div :class="['absolute -inset-1 bg-gradient-to-b rounded-3xl blur-xl transition-opacity duration-500 opacity-0 group-hover:opacity-100', tier.gradient]" />

                                    <div :class="['relative glass rounded-2xl p-6 h-full flex flex-col', tier.border, tier.glow]">

                                        <div class="flex items-start justify-between mb-4">
                                            <div class="relative">
                                                <div :class="['absolute -inset-2 rounded-xl blur-lg opacity-40', tier.gradient]" />
                                                <BrandLogo variant="mark" alt="TPIX" class="relative w-14 h-14 rounded-full ring-1 ring-white/10" />
                                            </div>
                                            <div class="text-right">
                                                <div :class="['px-3 py-1 rounded-full text-xs font-black border inline-block', tier.badge]">
                                                    {{ tier.apyBadge }}
                                                </div>
                                                <div v-if="tier.apyNote" class="text-[9px] text-gray-500 mt-1">{{ tier.apyNote }}</div>
                                            </div>
                                        </div>

                                        <h3 class="text-xl font-bold text-white mb-1">{{ tier.name }}</h3>
                                        <p class="text-xs text-gray-400 mb-5 leading-relaxed">{{ tier.desc }}</p>

                                        <div class="space-y-3 mb-5 flex-1">
                                            <div class="flex justify-between items-center">
                                                <span class="text-xs text-gray-500">วางค้ำขั้นต่ำ</span>
                                                <span :class="['text-sm font-bold', tier.accent]">{{ fmtNum(tier.minStake) }} TPIX</span>
                                            </div>
                                            <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                            <div class="flex justify-between items-center">
                                                <span class="text-xs text-gray-500">ระยะล็อก</span>
                                                <span class="text-sm text-gray-300">{{ tier.lockDays }} วัน</span>
                                            </div>
                                            <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                            <div class="flex justify-between items-center">
                                                <span class="text-xs text-gray-500">ส่วนแบ่งรางวัล</span>
                                                <span class="text-sm text-gray-300">{{ tier.rewardShare }}</span>
                                            </div>
                                            <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                            <div class="flex justify-between items-center">
                                                <span class="text-xs text-gray-500">คาดการณ์ถ้าเข้าตอนนี้</span>
                                                <span class="text-sm text-right" data-test="tier-projection"
                                                      :class="{
                                                          'text-trading-green font-bold': tier.projectedTone === 'good',
                                                          'text-yellow-400 text-xs': tier.projectedTone === 'warn',
                                                          'text-gray-400': tier.projectedTone === 'muted',
                                                      }">
                                                    {{ tier.projectedText }}
                                                </span>
                                            </div>
                                            <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                            <div class="flex justify-between items-center">
                                                <span class="text-xs text-gray-500">โควตาที่เหลือ</span>
                                                <span class="text-sm" :class="tier.isFull ? 'text-trading-red' : 'text-gray-300'">
                                                    {{ tier.maxNodes > 0 ? `${tier.slotsLeft} / ${tier.maxNodes}` : 'ไม่จำกัด' }}
                                                </span>
                                            </div>
                                        </div>

                                        <!-- เหตุผลที่กดไม่ได้ — บอกตรง ๆ ก่อนกด -->
                                        <div v-if="blockReason(tier)" class="mb-4 p-2.5 rounded-xl bg-red-500/5 border border-red-500/10">
                                            <div class="text-xs text-red-400/80">{{ blockReason(tier) }}</div>
                                        </div>
                                        <div v-else class="mb-4 p-2.5 rounded-xl bg-green-500/10 border border-green-500/20">
                                            <div class="flex items-center gap-2 text-xs text-green-400 font-semibold">
                                                <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse" />
                                                พร้อมลงทะเบียน
                                            </div>
                                        </div>

                                        <button @click="stakeAndRegister(tier)"
                                                :disabled="!!blockReason(tier) || busy || txAwaiting"
                                                :class="[
                                                    'w-full py-3 rounded-xl font-bold text-sm transition-all duration-300',
                                                    !blockReason(tier) && !busy && !txAwaiting
                                                        ? 'btn-brand hover:shadow-lg hover:scale-[1.02]'
                                                        : 'bg-gray-800/50 text-gray-600 cursor-not-allowed'
                                                ]">
                                            <span v-if="isStaking && selectedTier === tier.id" class="flex items-center justify-center gap-2">
                                                <svg class="animate-spin w-4 h-4" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="31.42" stroke-dashoffset="10"/></svg>
                                                กำลังลงทะเบียน...
                                            </span>
                                            <span v-else>วางค้ำ {{ fmtNum(tier.minStake) }} TPIX</span>
                                        </button>

                                        <div class="text-[10px] text-gray-600 mt-3 text-center">{{ tier.hardware }}</div>
                                    </div>
                                </div>
                            </div>
                        </template>

                        <!-- ผลลัพธ์ tx -->
                        <div v-if="stakeTxHash" class="glass rounded-2xl p-5 border-l-4 border-green-500">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-xl bg-green-500/20 flex items-center justify-center text-xl shrink-0">✅</div>
                                <div>
                                    <div class="text-green-400 font-bold mb-1">ลงทะเบียนโหนดเรียบร้อย</div>
                                    <a :href="`${explorerUrl}/tx/${stakeTxHash}`" target="_blank" rel="noopener"
                                       class="text-xs text-cyan-400 hover:text-cyan-300 font-mono break-all">
                                        {{ stakeTxHash }}
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div v-if="stakeError" class="glass rounded-2xl p-4 border-l-4 border-red-500">
                            <div class="text-red-400 text-sm">{{ stakeError }}</div>
                        </div>
                    </div>

                    <!-- ============================================================ -->
                    <!--  TAB: เครือข่าย                                              -->
                    <!-- ============================================================ -->
                    <div v-show="activeTab === 'network'" class="space-y-6">

                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                            <div v-for="stat in [
                                { label: 'โหนดทั้งหมด', value: statNum(networkStats.total_nodes), color: 'text-white' },
                                { label: 'Validator', value: statNum(networkStats.validator_nodes), color: 'text-red-400' },
                                { label: 'Guardian', value: statNum(networkStats.guardian_nodes), color: 'text-yellow-400' },
                                { label: 'Sentinel', value: statNum(networkStats.sentinel_nodes), color: 'text-purple-400' },
                                { label: 'Light', value: statNum(networkStats.light_nodes), color: 'text-cyan-400' },
                                { label: 'บล็อกล่าสุด', value: networkStats.rpc_connected ? fmtNum(networkStats.block_height || 0) : '—', color: 'text-white' },
                            ]" :key="stat.label"
                               class="glass-card rounded-xl p-4 text-center group hover:scale-105 transition-transform">
                                <div class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">{{ stat.label }}</div>
                                <div :class="['text-2xl font-black', stat.color]">{{ stat.value }}</div>
                            </div>
                        </div>

                        <!-- พูลรางวัล — แยก "เพดานตามตาราง" ออกจาก "เงินที่มีอยู่จริง" -->
                        <div class="glass rounded-2xl p-6 space-y-4">
                            <div class="flex items-center gap-3 flex-wrap">
                                <BrandLogo variant="icon" alt="TPIX" class="w-8 h-8 rounded-full ring-1 ring-white/10" />
                                <div>
                                    <h3 class="text-sm font-bold text-white">พูลรางวัล</h3>
                                    <p class="text-xs text-gray-400">เพดานปล่อยรางวัลตามตาราง 1,400,000,000 TPIX ใน 3 ปี</p>
                                </div>
                                <span class="ml-auto text-sm font-bold text-cyan-400">
                                    {{ networkStats.reward_year_ended ? 'จบโครงการแล้ว' : `ปีที่ ${networkStats.current_year || 1}` }}
                                </span>
                            </div>

                            <div class="grid sm:grid-cols-3 gap-3">
                                <div class="glass-sm rounded-xl p-3">
                                    <div class="text-[10px] text-gray-500 uppercase">เติมเข้าพูลแล้ว</div>
                                    <div class="text-lg font-bold text-white">{{ statNum(networkStats.reward_pool_funded) }}</div>
                                </div>
                                <div class="glass-sm rounded-xl p-3">
                                    <div class="text-[10px] text-gray-500 uppercase">จ่ายได้จริงตอนนี้</div>
                                    <div class="text-lg font-bold"
                                         :class="poolState === 'funded' ? 'text-trading-green' : (poolUnfunded ? 'text-yellow-400' : 'text-gray-400')">
                                        {{ poolState === 'unknown' ? '—' : fmtNum(networkStats.reward_pool_available || 0) }}
                                    </div>
                                </div>
                                <div class="glass-sm rounded-xl p-3">
                                    <div class="text-[10px] text-gray-500 uppercase">จ่ายออกไปแล้ว</div>
                                    <div class="text-lg font-bold text-white">{{ statNum(networkStats.total_rewards_distributed) }}</div>
                                </div>
                            </div>

                            <p v-if="poolUnfunded" class="text-xs text-yellow-400/90">
                                พูลรางวัลยังไม่ถูกเติมเงิน — รางวัลจะสะสมต่อไปเรื่อย ๆ แต่ยังกดรับไม่ได้จนกว่าพูลจะมีเงิน
                                เงินต้นของผู้วางค้ำถูกกันไว้ต่างหากและถอนคืนได้เสมอเมื่อพ้นล็อก
                            </p>

                            <!-- ตารางปล่อยรางวัล — ตัวเลขตรงกับ yearlyEmission ในสัญญา -->
                            <div class="grid grid-cols-3 gap-3">
                                <div v-for="em in [
                                    { y: 1, amt: '600M', pct: '42.9%' },
                                    { y: 2, amt: '500M', pct: '35.7%' },
                                    { y: 3, amt: '300M', pct: '21.4%' },
                                ]" :key="em.y"
                                     :class="[
                                         'rounded-xl p-3 text-center border transition-all',
                                         networkStats.current_year === em.y && !networkStats.reward_year_ended
                                             ? 'glass border-cyan-500/40 shadow-[0_0_20px_rgba(6,182,212,0.15)] scale-105'
                                             : 'glass-sm border-white/5 opacity-60'
                                     ]">
                                    <div :class="['text-xs font-black', networkStats.current_year === em.y ? 'text-cyan-400' : 'text-gray-500']">
                                        ปีที่ {{ em.y }}
                                    </div>
                                    <div class="text-lg font-bold mt-1 text-white">{{ em.amt }}</div>
                                    <div class="text-[10px] text-gray-600">{{ em.pct }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- สัดส่วนรางวัลต่อชั้น -->
                        <div class="glass rounded-2xl p-6">
                            <h3 class="text-sm font-bold text-white mb-4">สัดส่วนรางวัลต่อชั้น</h3>
                            <!-- สัดส่วน emission ต่อชั้นตาม reward_share บนเชน (ไม่ใช่ตัวเลขเขียนตายตัว) -->
                            <div class="flex rounded-xl overflow-hidden h-8 sm:h-10 mb-3">
                                <div v-for="seg in shareBar" :key="seg.id"
                                     :class="['bg-gradient-to-r flex items-center justify-center text-[8px] sm:text-[10px] font-bold', seg.bar]"
                                     :style="{ width: `${seg.pct}%` }">
                                    <span class="hidden sm:inline">{{ seg.pct }}% {{ seg.short }}</span><span class="sm:hidden">{{ seg.pct }}%</span>
                                </div>
                            </div>
                            <p class="text-xs text-gray-400 leading-relaxed" data-test="reward-explainer">
                                รางวัลของแต่ละชั้นแบ่ง <span class="text-white">เท่ากันทุกโหนด</span> ในชั้นนั้น
                                (ทุกโหนดวางค้ำเท่ากับขั้นต่ำพอดี จึงได้ส่วนเท่ากัน)
                                แต่หนึ่งโหนดได้ <span class="text-white">ไม่เกินเพดาน APR ของชั้น</span>
                                เช่น Light สูงสุด {{ lightCapText }} ต่อปีของเงินวางค้ำ — กันไม่ให้โหนดชุดแรกไม่กี่ตัวดึงพูลรางวัลไปจนหมด
                                ส่วนที่เกินเพดานไม่ถูกจ่ายออก
                                จากนั้นคูณด้วย <span class="text-white">คะแนน uptime</span> ของโหนดคุณ
                                และจ่ายได้เท่าที่พูลรางวัลมีเงินจริงเท่านั้น
                            </p>
                        </div>

                        <div class="relative group">
                            <div class="absolute -inset-1 bg-gradient-to-r from-accent-500/20 via-primary-500/20 to-warm-500/20 rounded-3xl blur-xl opacity-60 group-hover:opacity-100 transition-opacity" />
                            <div class="glass-brand relative rounded-2xl p-8 text-center">
                                <BrandLogo variant="mark" alt="TPIX" class="w-16 h-16 mx-auto mb-4 rounded-full ring-2 ring-white/10" />
                                <h3 class="text-xl font-bold text-white mb-2">รันโหนดของคุณเอง</h3>
                                <p class="text-sm text-gray-400 mb-5">ดาวน์โหลดโปรแกรม TPIX Master Node สำหรับ Windows</p>
                                <div class="flex justify-center gap-3 flex-wrap">
                                    <a href="/api/v1/app/chain-download?type=masternode"
                                       class="btn-brand px-6 py-2.5 rounded-xl font-bold text-sm hover:scale-105 transition-transform">
                                        ดาวน์โหลดโปรแกรม
                                    </a>
                                    <Link href="/masternode/guide" class="btn-secondary px-6 py-2.5 rounded-xl font-bold text-sm">
                                        อ่านคู่มือ
                                    </Link>
                                </div>
                            </div>
                        </div>
                    </div>

                </template>
            </div>
        </div>
    </AppLayout>
</template>
