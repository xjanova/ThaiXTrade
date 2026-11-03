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
import { useTranslation } from '@/Composables/useTranslation';

const walletStore = useWalletStore();
const { t, locale } = useTranslation();

/** locale สำหรับจัดรูปแบบวันที่บนจอ — ตามภาษาที่ผู้ใช้เลือก */
const dateLocale = computed(() => (locale.value === 'th' ? 'th-TH' : 'en-US'));

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
        descKey: 'page.masterNode.tier.validatorDesc',
        gradient: 'from-red-500/30 via-rose-500/20 to-pink-500/10',
        border: 'border-red-500/30', glow: 'shadow-[0_0_40px_rgba(239,68,68,0.15)]',
        badge: 'bg-red-500/20 text-red-300 border-red-500/40', accent: 'text-red-400',
        bar: 'from-red-500 to-rose-500 text-white',
    },
    0: {
        name: 'Guardian Node', short: 'Guardian',
        apyHint: '10-12%', hardware: '8 CPU · 16GB · 500GB SSD',
        descKey: 'page.masterNode.tier.guardianDesc',
        gradient: 'from-yellow-500/30 via-amber-500/20 to-orange-500/10',
        border: 'border-yellow-500/30', glow: 'shadow-[0_0_40px_rgba(245,158,11,0.15)]',
        badge: 'bg-yellow-500/20 text-yellow-300 border-yellow-500/40', accent: 'text-yellow-400',
        bar: 'from-yellow-500 to-amber-500 text-black',
    },
    1: {
        name: 'Sentinel Node', short: 'Sentinel',
        apyHint: '7-9%', hardware: '4 CPU · 8GB · 200GB SSD',
        descKey: 'page.masterNode.tier.sentinelDesc',
        gradient: 'from-purple-500/30 via-violet-500/20 to-fuchsia-500/10',
        border: 'border-purple-500/30', glow: 'shadow-[0_0_40px_rgba(139,92,246,0.15)]',
        badge: 'bg-purple-500/20 text-purple-300 border-purple-500/40', accent: 'text-purple-400',
        bar: 'from-purple-500 to-violet-500 text-white',
    },
    2: {
        name: 'Light Node', short: 'Light',
        apyHint: '4-6%', hardware: '2 CPU · 4GB · 100GB SSD',
        descKey: 'page.masterNode.tier.lightDesc',
        gradient: 'from-cyan-500/30 via-blue-500/20 to-teal-500/10',
        border: 'border-cyan-500/30', glow: 'shadow-[0_0_40px_rgba(6,182,212,0.15)]',
        badge: 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40', accent: 'text-cyan-400',
        bar: 'from-cyan-500 to-blue-500 text-white',
    },
};

/** ลำดับที่แสดงบนหน้าจอ (แพงสุด → ถูกสุด) — ไม่เกี่ยวกับ index บนสัญญา */
const DISPLAY_ORDER = [3, 0, 1, 2];

const WEI_PER_TPIX = 10n ** 18n;

/** ชื่อรายการภาษาคน — ใช้ในการ์ด "รอการยืนยัน" และข้อความหลังสลับกระเป๋า (เก็บเป็นคีย์แปล) */
const TX_LABEL_KEY = {
    stake: 'page.masterNode.tx.stake',
    claim: 'page.masterNode.tx.claim',
    deregister: 'page.masterNode.tx.deregister',
    withdraw: 'page.masterNode.tx.withdraw',
    consent: 'page.masterNode.tx.consent',
};

function txLabel(kind) {
    return TX_LABEL_KEY[kind] ? t(TX_LABEL_KEY[kind]) : t('page.masterNode.tx.generic');
}

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
const KYC_STATUS_IDS = [0, 1, 2, 3, 4, 5];

/** คีย์แปลของสถานะ KYC — ไม่รู้จักค่า = ถือเป็น "ยังไม่เริ่ม" */
function kycStatusKey(id) {
    return `page.masterNode.kyc.status.s${KYC_STATUS_IDS.includes(id) ? id : 0}`;
}

/** enum NodeStatus { Inactive, Active, Slashed, Exited, SlashedWithdrawable } */
function nodeStatusLabel(id) {
    return [0, 1, 2, 3, 4].includes(id) ? t(`page.masterNode.nodeStatus.s${id}`) : '';
}

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
    return `${Number((Number(bps) / 100).toFixed(2)).toLocaleString(dateLocale.value, { maximumFractionDigits: 2 })}%`;
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
                projectedText = t('page.masterNode.tierCard.projectedUnfunded');
                projectedTone = 'warn';
            } else {
                projectedText = t('page.masterNode.tierCard.perYear', { v: fmtBps(projectedAprBps) });
                // อ่านยอดพูลไม่ได้ = ไม่เน้นสีเขียว (ยังไม่รู้ว่าจ่ายได้จริงหรือเปล่า)
                projectedTone = poolState.value === 'funded' ? 'good' : 'muted';
            }
        }

        return {
            id: idx,
            ...skin,
            desc: t(skin.descKey),
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
            apyBadge: aprCapBps !== null
                ? t('page.masterNode.tierCard.apyMax', { v: fmtBps(aprCapBps) })
                : t('page.masterNode.tierCard.perYear', { v: skin.apyHint }),
            apyNote: fromChain && aprCapBps !== null
                ? ''
                : (aprCapBps !== null ? t('page.masterNode.tierCard.apyNoteDefault') : t('page.masterNode.tierCard.apyNoteEstimate')),
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
    if (!props.registryLive && props.registryUnknown) return t('page.masterNode.block.registryUnknown');
    if (!props.registryLive) return t('page.masterNode.block.notDeployed');
    if (isRegistrationPaused.value) {
        return t('page.masterNode.block.paused');
    }
    if (chainReadError.value) return t('page.masterNode.block.chainReadError');
    if (walletStore.isConnected && !nodeLoaded.value) return t('page.masterNode.block.loadingNode');
    if (txAwaiting.value) return t('page.masterNode.block.txAwaiting');
    if (myNode.value && myNode.value.statusId === 1) return t('page.masterNode.block.hasActiveNode');
    // สัญญาบังคับ status == Inactive เท่านั้น — โหนดที่ถูกปรับ (2/4) หรือออกไปแล้ว (3)
    // ยังไม่ใช่ Inactive ต้องถอนเงินต้นที่เหลือให้จบก่อน ไม่งั้น registerNode revert "Already registered"
    if (myNode.value && myNode.value.statusId !== 0 && myNode.value.statusId !== 1) {
        return t('page.masterNode.block.withdrawRemainingFirst');
    }
    if (myNode.value && myNode.value.pendingUnclaimed > 0) return t('page.masterNode.block.claimPendingFirst');
    if (tier.isFull) return t('page.masterNode.block.tierFull', { active: tier.activeNodes, max: tier.maxNodes });
    if (tier.minStakeWei === null || tier.minStakeWei <= 0n) return t('page.masterNode.block.minStakeUnknown');
    if (tpixBalanceWei.value === null) {
        return balanceError.value ? t('page.masterNode.block.balanceError') : t('page.masterNode.block.loadingBalance');
    }
    // เทียบเป็น wei (BigInt) — ส่วนต่างปัดขึ้นเป็น TPIX เต็ม ให้เติมครั้งเดียวพอ
    if (tpixBalanceWei.value < tier.minStakeWei) {
        const shortWei = tier.minStakeWei - tpixBalanceWei.value;
        const shortTpix = (shortWei + WEI_PER_TPIX - 1n) / WEI_PER_TPIX;
        return t('page.masterNode.block.needMore', { amount: fmtNum(Number(shortTpix)) });
    }
    if (tier.needsKyc && !props.kycContract) return t('page.masterNode.revert.kycNotConfigured');
    if (tier.needsKyc && kycApproved.value === false) return t('page.masterNode.revert.kycNotApproved');
    if (!endpointValid.value) return t('page.masterNode.block.endpointInvalid');
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
    if (!v) return t('page.masterNode.endpoint.required');
    if (v.length > MAX_ENDPOINT_LEN) return t('page.masterNode.endpoint.tooLong', { n: MAX_ENDPOINT_LEN });
    return t('page.masterNode.endpoint.format');
});

// ============================================================
//  แปลง error จากเชน/กระเป๋า ให้เป็นข้อความที่ผู้ใช้อ่านรู้เรื่อง
//  ห้ามโยน e.message ดิบขึ้นจอ — ethers คืนมาเป็นก้อน JSON ยาวเป็นหน้า
// ============================================================
// คีย์ซ้าย = ข้อความ revert จากสัญญา (ใช้จับคู่ ห้ามแปล) — ค่าขวา = คีย์แปลที่โชว์ผู้ใช้
const REVERT_MAP = {
    'Already registered': 'page.masterNode.revert.alreadyRegistered',
    'Claim pending rewards first': 'page.masterNode.block.claimPendingFirst',
    'Registration paused': 'page.masterNode.revert.registrationPaused',
    // สองข้อความนี้มาจากกติกา msg.value == minStake เป๊ะ (ส่งเกิน / ส่งขาด)
    'Stake must equal tier minimum': 'page.masterNode.revert.stakeMustEqual',
    'Registration expired': 'page.masterNode.revert.registrationExpired',
    'Lock terms changed': 'page.masterNode.revert.lockTermsChanged',
    'Slash terms changed': 'page.masterNode.revert.slashTermsChanged',
    'Insufficient stake': 'page.masterNode.revert.insufficientStake',
    'Endpoint required': 'page.masterNode.revert.endpointRequired',
    'Endpoint too long': 'page.masterNode.revert.endpointTooLong',
    'Tier full': 'page.masterNode.revert.tierFull',
    'KYC contract not configured': 'page.masterNode.revert.kycNotConfigured',
    'KYC not approved': 'page.masterNode.revert.kycNotApproved',
    'Consent already active': 'page.masterNode.revert.consentActive',
    'Not active': 'page.masterNode.revert.notActive',
    'Still locked': 'page.masterNode.revert.stillLocked',
    'Not slashed': 'page.masterNode.revert.notSlashed',
    'Nothing to withdraw': 'page.masterNode.revert.nothingToWithdraw',
    'Transfer failed': 'page.masterNode.revert.transferFailed',
};

const CODE_MESSAGE = {
    WRONG_CHAIN: 'page.masterNode.code.wrongChain',
    NO_SIGNER: 'page.masterNode.code.noSigner',
    TX_CANCELLED: 'page.masterNode.code.txCancelled',
    NO_WALLET_PROVIDER: 'page.masterNode.code.noWalletProvider',
};

function humanError(e) {
    if (!e) return t('page.masterNode.err.failed');
    if (e.code === 'WALLET_SWITCHED') {
        return t('page.masterNode.err.walletSwitched', { addr: shortAddr(e.owner) });
    }
    if (isUserRejection(e)) return t('page.masterNode.err.userRejected');
    if (CODE_MESSAGE[e.code]) return t(CODE_MESSAGE[e.code]);
    if (e.code === 'INSUFFICIENT_FUNDS') return t('page.masterNode.err.insufficientFunds');

    const blob = [e.reason, e.shortMessage, e.info?.error?.message, e.data?.message, e.message]
        .filter(Boolean).join(' | ');

    for (const [key, msg] of Object.entries(REVERT_MAP)) {
        if (blob.includes(key)) return t(msg, { n: MAX_ENDPOINT_LEN });
    }
    if (/user rejected|User denied/i.test(blob)) return t('page.masterNode.err.userRejected');
    if (/network|timeout|could not detect|failed to fetch/i.test(blob)) return t('page.masterNode.err.network');

    // reason คือข้อความ revert สั้น ๆ จากสัญญา (ไม่ใช่ก้อน JSON) — ส่วนที่เหลือไม่โชว์ดิบ
    return e.reason ? t('page.masterNode.err.failedReason', { reason: e.reason }) : t('page.masterNode.err.failedRetry');
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
            const who = sameAddress(p.owner, walletStore.address) ? '' : t('page.masterNode.notice.ofWallet', { addr: shortAddr(p.owner) });
            flowNotice.value = t('page.masterNode.notice.txFailedOnChain', { label: txLabel(p.kind), who });
        }
    } catch { /* RPC ยังล่ม — รอบหน้าลองใหม่ */ } finally {
        checkingReceipt = false;
    }
}

/** หลังเชนยืนยัน — ถ้าผู้ใช้สลับกระเป๋าไปแล้ว ห้ามแสดงผลเป็นของกระเป๋าใหม่ */
async function afterConfirmed(guard, kind, hash) {
    if (!sameAddress(guard.owner, walletStore.address)) {
        flowNotice.value = t('page.masterNode.notice.confirmedOtherWallet', { label: txLabel(kind), addr: shortAddr(guard.owner) });
        return;
    }
    if (kind === 'stake') { stakeTxHash.value = hash; activeTab.value = 'setup'; }
    if (kind === 'claim') { claimIsError.value = false; claimMessage.value = t('page.masterNode.claim.success'); }

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
        claimMessage.value = t('page.masterNode.err.poolEmpty');
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
        stakeError.value = t('page.masterNode.deregister.stillLocked', { date: unlockDateText.value });
        return;
    }
    const guard = walletGuard();
    const warn = myNode.value.pending > myNode.value.claimable
        ? `\n\n${t('page.masterNode.deregister.partialWarn')}`
        : '';
    if (!confirm(`${t('page.masterNode.deregister.confirm', { amount: fmtNum(myNode.value.stake) })}${warn}`)) return;
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
    return new Date(myNode.value.unlockAt * 1000).toLocaleDateString(dateLocale.value, {
        year: 'numeric', month: 'short', day: 'numeric',
    });
});

const unlockCountdown = computed(() => {
    const s = myNode.value?.unlockInSeconds || 0;
    if (s <= 0) return '';
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    return d > 0 ? t('page.masterNode.countdown.daysHours', { d, h }) : t('page.masterNode.countdown.hours', { h });
});

/** พูลรางวัลยังไม่ถูกเติมเงิน = กดเคลมได้ 0 ต้องบอกล่วงหน้าไม่ใช่ให้เสียเที่ยว */
const poolUnfunded = computed(() => poolState.value === 'unfunded');

const canRegister = computed(() => !myNode.value || myNode.value.statusId !== 1);

/** เหตุผลที่ปุ่ม "รับรางวัล" กดไม่ได้ — โชว์ใต้ปุ่ม ไม่ใช่ปล่อยให้งงว่าทำไมปุ่มเทา */
const claimHint = computed(() => {
    const n = myNode.value;
    // pending > claimable มีคำอธิบายของตัวเองอยู่แล้วใต้ยอด — ไม่พูดซ้ำ
    if (!n || Number(n.claimable) > 0 || Number(n.pending) > Number(n.claimable)) return '';
    return poolUnfunded.value ? t('page.masterNode.claim.hintUnfunded') : '';
});

const heartbeatText = computed(() => {
    const ts = heartbeat.value?.lastHeartbeat;
    return ts ? new Date(ts * 1000).toLocaleString(dateLocale.value, { dateStyle: 'medium', timeStyle: 'short' }) : '';
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
        out.push(t('page.masterNode.deploy.noAddress'));
    } else if (props.registryUnknown) {
        out.push(t('page.masterNode.deploy.unknown', { addr: props.registryAddress }));
    } else {
        out.push(t('page.masterNode.deploy.noCode', { addr: props.registryAddress }));
    }
    if (!networkStats.value.rpc_connected) {
        out.push(t('page.masterNode.deploy.rpcDown'));
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
    return v.toLocaleString(dateLocale.value, { maximumFractionDigits: v < 1 ? 6 : 2 });
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
                    <div class="text-yellow-400 font-bold text-sm">{{ t('page.masterNode.registryUnknown.title') }}</div>
                    <div class="text-xs text-gray-400 mt-1">
                        {{ t('page.masterNode.registryUnknown.body') }}
                    </div>
                </div>

                <!-- สัญญาเปิดแล้วแต่พูลรางวัลยังไม่มีเงิน — ต้องบอกก่อนที่ผู้ใช้จะเห็นตัวเลข % ใด ๆ -->
                <div v-if="registryLive && poolUnfunded" data-test="pool-unfunded"
                     class="glass rounded-2xl p-5 border-l-4 border-yellow-500">
                    <div class="text-yellow-400 font-bold text-sm">{{ t('page.masterNode.poolUnfunded.title') }}</div>
                    <div class="text-xs text-gray-400 mt-1 leading-relaxed">
                        {{ t('page.masterNode.poolUnfunded.body') }}
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
                            {{ t('page.masterNode.hero.tagline') }}
                        </p>
                        <p class="text-sm text-gray-500 mb-8 max-w-md mx-auto">
                            {{ t('page.masterNode.hero.subtitle') }}
                        </p>

                        <div class="grid grid-cols-3 gap-3 sm:gap-4 max-w-md mx-auto mb-8">
                            <!-- พูลยังว่าง = ไม่โชว์ % สีเขียวเป็นตัวชูโรง (จะอ่านเป็นผลตอบแทนที่ได้แน่) -->
                            <div v-if="poolUnfunded" class="glass-sm rounded-xl p-3" data-test="hero-pool-unfunded">
                                <div class="text-xs text-gray-400">{{ t('page.masterNode.pool.title') }}</div>
                                <div class="text-base font-black text-yellow-400 leading-7">{{ t('page.masterNode.hero.notFunded') }}</div>
                            </div>
                            <div v-else class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">{{ t('page.masterNode.hero.aprCap') }}</div>
                                <div class="text-xl font-black" :class="poolState === 'funded' ? 'text-trading-green' : 'text-gray-300'">{{ heroMaxApr }}</div>
                            </div>
                            <div class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">{{ t('page.masterNode.hero.activeNodes') }}</div>
                                <div class="text-xl font-black text-gradient">{{ statNum(networkStats.total_nodes) }}</div>
                            </div>
                            <div class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">{{ t('page.masterNode.hero.minimum') }}</div>
                                <div class="text-xl font-black text-cyan-400">{{ minEntryText }}</div>
                            </div>
                        </div>

                        <button @click="connectWallet"
                                class="btn-brand px-10 py-4 text-lg font-bold rounded-2xl shadow-lg hover:shadow-xl hover:scale-105 transition-all duration-300">
                            {{ t('page.masterNode.hero.connect') }}
                        </button>
                        <p class="text-xs text-gray-500 mt-4">
                            MetaMask · Trust Wallet · Coinbase · OKX · TPIX Wallet
                        </p>

                        <div class="flex items-center justify-center gap-4 mt-6 flex-wrap">
                            <Link href="/masternode/guide"
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500/10 transition">
                                📖 {{ t('page.masterNode.links.guide') }}
                            </Link>
                            <a href="/api/v1/app/chain-download?type=masternode"
                                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-white/10 text-gray-400 hover:bg-white/5 transition">
                                📥 {{ t('page.masterNode.links.downloadPc') }}
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
                                            {{ t('page.masterNode.wallet.wrongNetwork') }}
                                            <button @click="switchNetwork" class="underline hover:text-yellow-300 ml-1">{{ t('page.masterNode.wallet.switch') }}</button>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="text-right">
                                <div class="text-[10px] text-gray-400 uppercase tracking-widest">{{ t('page.masterNode.wallet.yourTpix') }}</div>
                                <div class="text-3xl font-black leading-tight" :class="tpixBalance > 0 ? 'text-gradient' : 'text-gray-600'">
                                    <span v-if="tpixBalanceWei === null && isLoadingBalance" class="animate-pulse text-gray-500">···</span>
                                    <span v-else-if="tpixBalanceWei === null" class="text-gray-600" :title="balanceError ? t('page.masterNode.wallet.balanceReadError') : ''">—</span>
                                    <span v-else>{{ fmtNum(Math.floor(tpixBalance)) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-center gap-4 flex-wrap">
                        <Link href="/masternode/guide"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-cyan-500/30 text-cyan-400 hover:bg-cyan-500/10 transition">
                            📖 {{ t('page.masterNode.links.guide') }}
                        </Link>
                        <a href="/api/v1/app/chain-download?type=masternode"
                            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold border border-white/10 text-gray-400 hover:bg-white/5 transition">
                            📥 {{ t('page.masterNode.links.downloadPc') }}
                        </a>
                    </div>

                    <div v-if="!isOnTPIXChain"
                         class="glass rounded-2xl p-5 border-l-4 border-yellow-500 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-yellow-500/20 flex items-center justify-center text-2xl shrink-0">⚡</div>
                        <div class="flex-1">
                            <div class="text-yellow-400 font-bold">{{ t('page.masterNode.switchChain.title') }}</div>
                            <div class="text-xs text-gray-400">{{ t('page.masterNode.switchChain.desc') }}</div>
                        </div>
                        <button @click="switchNetwork" class="btn-primary px-6 py-2.5 rounded-xl font-bold shrink-0">
                            {{ t('page.masterNode.switchChain.button') }}
                        </button>
                    </div>

                    <!-- Tabs -->
                    <div class="flex gap-1 p-1 glass-sm rounded-xl">
                        <button v-for="tab in [{id:'setup',label:t('page.masterNode.tabs.setup'),icon:'🚀'},{id:'network',label:t('page.masterNode.tabs.network'),icon:'🌐'}]" :key="tab.id"
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
                            <button @click="flowNotice = ''" class="text-xs text-gray-500 hover:text-white shrink-0">{{ t('page.masterNode.common.close') }}</button>
                        </div>

                        <!-- ส่ง tx แล้วแต่ยังไม่รู้ผล — ห้ามบอกว่าล้มเหลว เพราะอาจผ่านไปแล้ว -->
                        <div v-if="pendingTx" data-test="pending-tx"
                             class="glass rounded-2xl p-5 border-l-4 border-cyan-500 space-y-2">
                            <div class="flex items-center gap-2 text-cyan-300 font-bold text-sm">
                                <span v-if="!pendingTx.gaveUp" class="w-2 h-2 rounded-full bg-cyan-400 animate-pulse" />
                                {{ txLabel(pendingTx.kind) }} — {{ pendingTx.gaveUp ? t('page.masterNode.pending.notFound') : t('page.masterNode.pending.awaiting') }}
                            </div>
                            <p class="text-xs text-gray-400">
                                <template v-if="pendingTx.gaveUp">
                                    {{ t('page.masterNode.pending.gaveUpBody') }}
                                </template>
                                <template v-else>
                                    {{ t('page.masterNode.pending.pollingBody') }}
                                </template>
                                <span v-if="!sameAddress(pendingTx.owner, walletStore.address)" class="block mt-1">
                                    {{ t('page.masterNode.pending.otherWallet', { addr: shortAddr(pendingTx.owner) }) }}
                                </span>
                            </p>
                            <div class="flex items-center gap-3 flex-wrap">
                                <a :href="`${explorerUrl}/tx/${pendingTx.hash}`" target="_blank" rel="noopener"
                                   class="text-xs text-cyan-400 hover:text-cyan-300 font-mono break-all">{{ pendingTx.hash }}</a>
                                <button @click="checkPendingTx" class="text-xs font-semibold text-white underline">{{ t('page.masterNode.pending.recheck') }}</button>
                                <button v-if="pendingTx.gaveUp" @click="pendingTx = null" class="text-xs text-gray-500 hover:text-white">{{ t('page.masterNode.pending.hide') }}</button>
                            </div>
                        </div>

                        <!-- อ่านเชนไม่ได้ ≠ ไม่มีโหนด — บอกตรง ๆ แล้วให้ลองใหม่ ไม่ใช่เงียบแล้วโชว์ฟอร์มซื้อ -->
                        <div v-if="chainReadError" data-test="chain-read-error"
                             class="glass rounded-2xl p-5 border-l-4 border-yellow-500 flex flex-wrap items-center gap-4">
                            <div class="flex-1 min-w-[200px]">
                                <div class="text-yellow-400 font-bold">{{ t('page.masterNode.chainError.title') }}</div>
                                <div class="text-xs text-gray-400 mt-0.5">
                                    {{ t('page.masterNode.chainError.body') }}
                                </div>
                            </div>
                            <button @click="retryChainRead" :disabled="isLoadingNode" data-test="retry-chain-read"
                                    class="btn-primary px-6 py-2.5 rounded-xl font-bold shrink-0 disabled:opacity-50">
                                {{ isLoadingNode ? t('page.masterNode.chainError.retrying') : t('page.masterNode.chainError.retry') }}
                            </button>
                        </div>

                        <!-- แอดมินปิดรับลงทะเบียนใหม่ — เงินของคนที่อยู่ในระบบแล้วยังออกได้เสมอ -->
                        <div v-if="registryLive && isRegistrationPaused" data-test="registration-paused"
                             class="glass rounded-2xl p-5 border-l-4 border-red-500/60">
                            <div class="text-red-300 font-bold text-sm">{{ t('page.masterNode.paused.title') }}</div>
                            <div class="text-xs text-gray-400 mt-1">
                                {{ t('page.masterNode.paused.body') }}
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
                                                    {{ nodeStatusLabel(myNode.statusId) }}
                                                </span>
                                            </div>
                                            <div class="text-sm text-gray-400 mt-0.5">
                                                {{ t('page.masterNode.node.staked') }} <span class="text-white font-bold">{{ fmtNum(myNode.stake) }} TPIX</span>
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
                                        <div class="text-[10px] text-gray-500 uppercase">{{ t('page.masterNode.node.endpoint') }}</div>
                                        <div class="text-sm text-white font-mono break-all">{{ myNode.endpoint || '—' }}</div>
                                    </div>
                                    <div class="glass-sm rounded-xl p-3">
                                        <div class="text-[10px] text-gray-500 uppercase">{{ t('page.masterNode.node.registeredAt') }}</div>
                                        <div class="text-sm text-white">
                                            {{ new Date(myNode.registeredAt * 1000).toLocaleDateString(dateLocale) }}
                                        </div>
                                    </div>
                                    <div class="glass-sm rounded-xl p-3">
                                        <div class="text-[10px] text-gray-500 uppercase">{{ t('page.masterNode.node.unlock') }}</div>
                                        <div class="text-sm" :class="myNode.isLocked ? 'text-yellow-400' : 'text-trading-green'">
                                            {{ unlockDateText }}
                                            <span v-if="myNode.isLocked" class="block text-[10px] text-gray-500">{{ unlockCountdown }}</span>
                                        </div>
                                    </div>
                                    <div class="glass-sm rounded-xl p-3">
                                        <div class="text-[10px] text-gray-500 uppercase">{{ t('page.masterNode.node.totalRewards') }}</div>
                                        <div class="text-sm text-white">{{ fmtNum(myNode.totalRewards) }} TPIX</div>
                                    </div>
                                </div>

                                <!-- สัญญาณชีพจากโปรแกรม PC — ข้อมูลเสริม อ่านไม่ได้ก็ซ่อน -->
                                <div v-if="heartbeat" data-test="heartbeat" class="flex items-start gap-2 text-[11px]">
                                    <span :class="['w-2 h-2 rounded-full mt-1 shrink-0', heartbeat.isActive ? 'bg-trading-green animate-pulse' : 'bg-gray-500']" />
                                    <div>
                                        <span v-if="heartbeat.exists && heartbeat.lastHeartbeat" class="text-gray-300">
                                            {{ t('page.masterNode.heartbeat.last', { time: heartbeatText }) }}
                                        </span>
                                        <span v-else class="text-yellow-400/90">
                                            {{ t('page.masterNode.heartbeat.none') }}
                                        </span>
                                        <span class="block text-gray-500">
                                            {{ t('page.masterNode.heartbeat.uptimeNote') }}
                                        </span>
                                    </div>
                                </div>

                                <!-- รางวัล: แยก "สะสมได้" ออกจาก "เคลมได้จริงตอนนี้" -->
                                <div class="p-4 rounded-xl bg-trading-green/5 border border-trading-green/20 space-y-3">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <div class="text-xs text-gray-400">{{ t('page.masterNode.reward.accrued') }}</div>
                                            <div class="text-lg font-black text-trading-green">{{ fmtNum(myNode.pending) }} TPIX</div>
                                        </div>
                                        <div>
                                            <div class="text-xs text-gray-400">{{ t('page.masterNode.reward.claimable') }}</div>
                                            <div class="text-lg font-black" :class="myNode.claimable > 0 ? 'text-white' : 'text-gray-600'">
                                                {{ fmtNum(myNode.claimable) }} TPIX
                                            </div>
                                        </div>
                                        <button
                                            @click="claimRewards"
                                            :disabled="busy || txAwaiting || !(myNode.claimable > 0)"
                                            :title="myNode.claimable > 0 ? '' : t('page.masterNode.reward.nothingClaimable')"
                                            class="px-5 py-2.5 rounded-xl text-sm font-bold transition-all bg-trading-green/20 text-trading-green border border-trading-green/30 hover:bg-trading-green/30 disabled:opacity-40 disabled:cursor-not-allowed"
                                        >
                                            {{ isClaiming ? t('page.masterNode.reward.claiming') : t('page.masterNode.reward.claim') }}
                                        </button>
                                    </div>
                                    <p v-if="myNode.pending > myNode.claimable" class="text-[11px] text-yellow-400/90">
                                        {{ t('page.masterNode.reward.partialPool') }}
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
                                        {{ t('page.masterNode.slashed.body') }}
                                    </div>
                                    <button @click="withdrawSlashedStake" :disabled="busy || txAwaiting"
                                            class="px-5 py-2 rounded-xl text-sm font-bold bg-trading-red/20 text-trading-red border border-trading-red/30 hover:bg-trading-red/30 disabled:opacity-40">
                                        {{ isWithdrawing ? t('page.masterNode.slashed.withdrawing') : t('page.masterNode.tx.withdraw') }}
                                    </button>
                                </div>

                                <!-- ปิดโหนด — ปิดปุ่มไว้จนกว่าจะพ้นล็อก ไม่ปล่อยให้เสียเที่ยวเซ็น -->
                                <button
                                    v-if="myNode.statusId === 1"
                                    @click="deregisterNode"
                                    :disabled="busy || txAwaiting || myNode.isLocked"
                                    :title="myNode.isLocked ? t('page.masterNode.deregister.availableOn', { date: unlockDateText }) : ''"
                                    class="w-full py-2.5 rounded-xl text-xs font-medium border transition-all
                                           text-gray-500 border-white/5 hover:text-trading-red hover:border-trading-red/30
                                           disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:text-gray-500 disabled:hover:border-white/5"
                                >
                                    <template v-if="isDeregistering">{{ t('page.masterNode.deregister.processing') }}</template>
                                    <template v-else-if="myNode.isLocked">{{ t('page.masterNode.deregister.lockedUntil') }} · {{ unlockCountdown }}</template>
                                    <template v-else>{{ t('page.masterNode.tx.deregister') }}</template>
                                </button>

                                <!-- ขั้นตอนต่อไปหลังซื้อ — ไม่ใช่จบแค่จ่ายเงิน -->
                                <div class="glass-sm rounded-xl p-4 space-y-2">
                                    <div class="text-xs font-bold text-white">{{ t('page.masterNode.nextSteps.title') }}</div>
                                    <ol class="text-[11px] text-gray-400 space-y-1 list-decimal list-inside">
                                        <li>{{ t('page.masterNode.nextSteps.step1') }} <span class="font-mono text-cyan-400">{{ myNode.endpoint || '—' }}</span></li>
                                        <li>{{ t('page.masterNode.nextSteps.step2Before') }} <span class="font-mono text-cyan-400">{{ walletStore.shortAddress }}</span> {{ t('page.masterNode.nextSteps.step2After') }}</li>
                                        <li>{{ t('page.masterNode.nextSteps.step3') }}</li>
                                    </ol>
                                    <div class="flex gap-3 pt-1 flex-wrap">
                                        <a href="/api/v1/app/chain-download?type=masternode"
                                           class="text-[11px] font-semibold text-cyan-400 hover:text-cyan-300 underline">{{ t('page.masterNode.links.download') }}</a>
                                        <Link href="/masternode/guide" class="text-[11px] font-semibold text-cyan-400 hover:text-cyan-300 underline">{{ t('page.masterNode.links.readGuide') }}</Link>
                                        <a :href="`${explorerUrl}/address/${myNode.operator}`" target="_blank" rel="noopener"
                                           class="text-[11px] font-semibold text-gray-400 hover:text-white underline">{{ t('page.masterNode.links.explorer') }}</a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- ─── ฟอร์มลงทะเบียนใหม่ ─── -->
                        <template v-if="canRegister">
                            <div class="text-center">
                                <h2 class="text-2xl font-bold text-white">{{ t('page.masterNode.register.title') }}</h2>
                                <p class="text-sm text-gray-400 mt-1">
                                    {{ t('page.masterNode.register.youHave') }} <span class="text-gradient font-bold">{{ tpixBalanceWei === null ? '—' : fmtNum(Math.floor(tpixBalance)) }} TPIX</span>
                                </p>
                                <p class="text-[11px] text-gray-500 mt-2 max-w-2xl mx-auto">
                                    {{ t('page.masterNode.register.note') }}
                                </p>
                            </div>

                            <!-- ที่อยู่โหนด: สัญญาบังคับ ต้องกรอกก่อนถึงจะกดซื้อได้ -->
                            <div class="glass rounded-2xl p-5 space-y-2">
                                <label for="mn-endpoint" class="block text-sm font-bold text-white">
                                    {{ t('page.masterNode.register.endpointLabel') }} <span class="text-trading-red">*</span>
                                </label>
                                <p class="text-xs text-gray-400">
                                    {{ t('page.masterNode.register.endpointHelp') }}
                                    <span class="font-mono text-cyan-400">IP:port</span> {{ t('page.masterNode.register.or') }}
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
                                            {{ t('page.masterNode.kyc.title') }}
                                        </div>
                                        <p class="text-xs text-gray-400 mt-1">
                                            {{ t('page.masterNode.kyc.currentStatus') }}
                                            <span class="text-white font-semibold">{{ t(`${kycStatusKey(kycStatusId)}.label`) }}</span>
                                            — {{ t(`${kycStatusKey(kycStatusId)}.hint`) }}
                                        </p>
                                        <p v-if="kycRejectReason" class="text-xs text-trading-red mt-1">
                                            {{ t('page.masterNode.kyc.rejectReason', { reason: kycRejectReason }) }}
                                        </p>
                                    </div>
                                </div>

                                <ol class="text-[11px] text-gray-400 space-y-1 list-decimal list-inside pl-1">
                                    <li>{{ t('page.masterNode.kyc.step1') }}</li>
                                    <li>{{ t('page.masterNode.kyc.step2') }}</li>
                                    <li>{{ t('page.masterNode.kyc.step3') }}</li>
                                </ol>

                                <div class="flex items-center gap-3 flex-wrap">
                                    <button
                                        v-if="kycStatusId === null || kycStatusId === 0 || kycStatusId === 5"
                                        @click="giveKycConsent"
                                        :disabled="busy || txAwaiting"
                                        class="px-5 py-2.5 rounded-xl text-sm font-bold bg-red-500/20 text-red-300 border border-red-500/40 hover:bg-red-500/30 disabled:opacity-40">
                                        {{ isGivingConsent ? t('page.masterNode.kyc.signing') : t('page.masterNode.kyc.giveConsent') }}
                                    </button>
                                    <span v-else-if="kycStatusId === 1 || kycStatusId === 2" class="text-xs text-yellow-400">
                                        ✓ {{ t('page.masterNode.kyc.consentGiven') }}
                                    </span>
                                    <Link href="/validators/apply"
                                          class="text-xs font-semibold text-cyan-400 hover:text-cyan-300 underline">
                                        {{ t('page.masterNode.kyc.submitDocs') }}
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
                                                <span class="text-xs text-gray-500">{{ t('page.masterNode.tierCard.minStake') }}</span>
                                                <span :class="['text-sm font-bold', tier.accent]">{{ fmtNum(tier.minStake) }} TPIX</span>
                                            </div>
                                            <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                            <div class="flex justify-between items-center">
                                                <span class="text-xs text-gray-500">{{ t('page.masterNode.tierCard.lock') }}</span>
                                                <span class="text-sm text-gray-300">{{ t('page.masterNode.tierCard.days', { n: tier.lockDays }) }}</span>
                                            </div>
                                            <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                            <div class="flex justify-between items-center">
                                                <span class="text-xs text-gray-500">{{ t('page.masterNode.tierCard.rewardShare') }}</span>
                                                <span class="text-sm text-gray-300">{{ tier.rewardShare }}</span>
                                            </div>
                                            <div class="h-px bg-gradient-to-r from-transparent via-white/10 to-transparent" />
                                            <div class="flex justify-between items-center">
                                                <span class="text-xs text-gray-500">{{ t('page.masterNode.tierCard.projected') }}</span>
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
                                                <span class="text-xs text-gray-500">{{ t('page.masterNode.tierCard.slotsLeft') }}</span>
                                                <span class="text-sm" :class="tier.isFull ? 'text-trading-red' : 'text-gray-300'">
                                                    {{ tier.maxNodes > 0 ? `${tier.slotsLeft} / ${tier.maxNodes}` : t('page.masterNode.tierCard.unlimited') }}
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
                                                {{ t('page.masterNode.tierCard.ready') }}
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
                                                {{ t('page.masterNode.tierCard.registering') }}
                                            </span>
                                            <span v-else>{{ t('page.masterNode.tierCard.stakeButton', { amount: fmtNum(tier.minStake) }) }}</span>
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
                                    <div class="text-green-400 font-bold mb-1">{{ t('page.masterNode.register.success') }}</div>
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
                                { label: t('page.masterNode.network.totalNodes'), value: statNum(networkStats.total_nodes), color: 'text-white' },
                                { label: 'Validator', value: statNum(networkStats.validator_nodes), color: 'text-red-400' },
                                { label: 'Guardian', value: statNum(networkStats.guardian_nodes), color: 'text-yellow-400' },
                                { label: 'Sentinel', value: statNum(networkStats.sentinel_nodes), color: 'text-purple-400' },
                                { label: 'Light', value: statNum(networkStats.light_nodes), color: 'text-cyan-400' },
                                { label: t('page.masterNode.network.latestBlock'), value: networkStats.rpc_connected ? fmtNum(networkStats.block_height || 0) : '—', color: 'text-white' },
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
                                    <h3 class="text-sm font-bold text-white">{{ t('page.masterNode.pool.title') }}</h3>
                                    <p class="text-xs text-gray-400">{{ t('page.masterNode.pool.schedule') }}</p>
                                </div>
                                <span class="ml-auto text-sm font-bold text-cyan-400">
                                    {{ networkStats.reward_year_ended ? t('page.masterNode.pool.ended') : t('page.masterNode.pool.year', { n: networkStats.current_year || 1 }) }}
                                </span>
                            </div>

                            <div class="grid sm:grid-cols-3 gap-3">
                                <div class="glass-sm rounded-xl p-3">
                                    <div class="text-[10px] text-gray-500 uppercase">{{ t('page.masterNode.pool.funded') }}</div>
                                    <div class="text-lg font-bold text-white">{{ statNum(networkStats.reward_pool_funded) }}</div>
                                </div>
                                <div class="glass-sm rounded-xl p-3">
                                    <div class="text-[10px] text-gray-500 uppercase">{{ t('page.masterNode.pool.available') }}</div>
                                    <div class="text-lg font-bold"
                                         :class="poolState === 'funded' ? 'text-trading-green' : (poolUnfunded ? 'text-yellow-400' : 'text-gray-400')">
                                        {{ poolState === 'unknown' ? '—' : fmtNum(networkStats.reward_pool_available || 0) }}
                                    </div>
                                </div>
                                <div class="glass-sm rounded-xl p-3">
                                    <div class="text-[10px] text-gray-500 uppercase">{{ t('page.masterNode.pool.distributed') }}</div>
                                    <div class="text-lg font-bold text-white">{{ statNum(networkStats.total_rewards_distributed) }}</div>
                                </div>
                            </div>

                            <p v-if="poolUnfunded" class="text-xs text-yellow-400/90">
                                {{ t('page.masterNode.pool.unfundedNote') }}
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
                                        {{ t('page.masterNode.pool.year', { n: em.y }) }}
                                    </div>
                                    <div class="text-lg font-bold mt-1 text-white">{{ em.amt }}</div>
                                    <div class="text-[10px] text-gray-600">{{ em.pct }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- สัดส่วนรางวัลต่อชั้น -->
                        <div class="glass rounded-2xl p-6">
                            <h3 class="text-sm font-bold text-white mb-4">{{ t('page.masterNode.share.title') }}</h3>
                            <!-- สัดส่วน emission ต่อชั้นตาม reward_share บนเชน (ไม่ใช่ตัวเลขเขียนตายตัว) -->
                            <div class="flex rounded-xl overflow-hidden h-8 sm:h-10 mb-3">
                                <div v-for="seg in shareBar" :key="seg.id"
                                     :class="['bg-gradient-to-r flex items-center justify-center text-[8px] sm:text-[10px] font-bold', seg.bar]"
                                     :style="{ width: `${seg.pct}%` }">
                                    <span class="hidden sm:inline">{{ seg.pct }}% {{ seg.short }}</span><span class="sm:hidden">{{ seg.pct }}%</span>
                                </div>
                            </div>
                            <p class="text-xs text-gray-400 leading-relaxed" data-test="reward-explainer">
                                {{ t('page.masterNode.share.p1') }} <span class="text-white">{{ t('page.masterNode.share.s1') }}</span>
                                {{ t('page.masterNode.share.p2') }} <span class="text-white">{{ t('page.masterNode.share.s2') }}</span>
                                {{ t('page.masterNode.share.p3', { cap: lightCapText }) }} <span class="text-white">{{ t('page.masterNode.share.s3') }}</span>
                                {{ t('page.masterNode.share.p4') }}
                            </p>
                        </div>

                        <div class="relative group">
                            <div class="absolute -inset-1 bg-gradient-to-r from-accent-500/20 via-primary-500/20 to-warm-500/20 rounded-3xl blur-xl opacity-60 group-hover:opacity-100 transition-opacity" />
                            <div class="glass-brand relative rounded-2xl p-8 text-center">
                                <BrandLogo variant="mark" alt="TPIX" class="w-16 h-16 mx-auto mb-4 rounded-full ring-2 ring-white/10" />
                                <h3 class="text-xl font-bold text-white mb-2">{{ t('page.masterNode.cta.title') }}</h3>
                                <p class="text-sm text-gray-400 mb-5">{{ t('page.masterNode.cta.desc') }}</p>
                                <div class="flex justify-center gap-3 flex-wrap">
                                    <a href="/api/v1/app/chain-download?type=masternode"
                                       class="btn-brand px-6 py-2.5 rounded-xl font-bold text-sm hover:scale-105 transition-transform">
                                        {{ t('page.masterNode.links.download') }}
                                    </a>
                                    <Link href="/masternode/guide" class="btn-secondary px-6 py-2.5 rounded-xl font-bold text-sm">
                                        {{ t('page.masterNode.links.readGuide') }}
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
