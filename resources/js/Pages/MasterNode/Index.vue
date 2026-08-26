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
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { addTPIXChainToWallet } from '@/utils/web3';

const walletStore = useWalletStore();

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    tiers: { type: Array, default: () => [] },
    nodes: { type: Array, default: () => [] },
    registryAddress: { type: String, default: '' },
    registryLive: { type: Boolean, default: false },
    kycContract: { type: String, default: null },
    rpcUrl: { type: String, default: 'https://rpc.tpix.online' },
    chainId: { type: Number, default: 4289 },
    explorerUrl: { type: String, default: 'https://explorer.tpix.online' },
});

// ============================================================
//  หน้าตาของแต่ละชั้น — key คือ "tier index บนสัญญา" เท่านั้น
//  ตัวเลขจริง (min stake / lock / โควตา) มาจากเชน ไม่ hardcode ที่นี่
// ============================================================
const TIER_SKIN = {
    3: {
        name: 'Validator Node', short: 'Validator',
        apy: '15-20%', hardware: '16 CPU · 32GB · 1TB SSD',
        desc: 'ผู้ปิดบล็อก IBFT2 ตัวจริง มีสิทธิ์โหวตธรรมาภิบาล — ต้องผ่าน KYC นิติบุคคล',
        gradient: 'from-red-500/30 via-rose-500/20 to-pink-500/10',
        border: 'border-red-500/30', glow: 'shadow-[0_0_40px_rgba(239,68,68,0.15)]',
        badge: 'bg-red-500/20 text-red-300 border-red-500/40', accent: 'text-red-400',
    },
    0: {
        name: 'Guardian Node', short: 'Guardian',
        apy: '10-12%', hardware: '8 CPU · 16GB · 500GB SSD',
        desc: 'โหนดระดับพรีเมียม ส่วนแบ่งรางวัลสูงสุดในระบบ',
        gradient: 'from-yellow-500/30 via-amber-500/20 to-orange-500/10',
        border: 'border-yellow-500/30', glow: 'shadow-[0_0_40px_rgba(245,158,11,0.15)]',
        badge: 'bg-yellow-500/20 text-yellow-300 border-yellow-500/40', accent: 'text-yellow-400',
    },
    1: {
        name: 'Sentinel Node', short: 'Sentinel',
        apy: '7-9%', hardware: '4 CPU · 8GB · 200GB SSD',
        desc: 'โหนดมาตรฐานสำหรับดูแลความสมบูรณ์ของเครือข่าย',
        gradient: 'from-purple-500/30 via-violet-500/20 to-fuchsia-500/10',
        border: 'border-purple-500/30', glow: 'shadow-[0_0_40px_rgba(139,92,246,0.15)]',
        badge: 'bg-purple-500/20 text-purple-300 border-purple-500/40', accent: 'text-purple-400',
    },
    2: {
        name: 'Light Node', short: 'Light',
        apy: '4-6%', hardware: '2 CPU · 4GB · 100GB SSD',
        desc: 'เริ่มง่ายที่สุด ช่วยค้ำเครือข่ายแล้วรับรางวัล',
        gradient: 'from-cyan-500/30 via-blue-500/20 to-teal-500/10',
        border: 'border-cyan-500/30', glow: 'shadow-[0_0_40px_rgba(6,182,212,0.15)]',
        badge: 'bg-cyan-500/20 text-cyan-300 border-cyan-500/40', accent: 'text-cyan-400',
    },
};

/** ลำดับที่แสดงบนหน้าจอ (แพงสุด → ถูกสุด) — ไม่เกี่ยวกับ index บนสัญญา */
const DISPLAY_ORDER = [3, 0, 1, 2];

// ============================================================
//  State
// ============================================================
const tpixBalance = ref(0);
const isLoadingBalance = ref(false);
const selectedTier = ref(null);
const isStaking = ref(false);
const stakeTxHash = ref('');
const stakeError = ref('');
const activeTab = ref('setup'); // setup | network

const myNode = ref(null);          // struct จากเชน (null = ยังไม่เคยลงทะเบียน)
const isLoadingNode = ref(false);
const chainTiers = ref(props.tiers || []);

const endpoint = ref('');
const endpointTouched = ref(false);
const kycApproved = ref(null);     // null = ยังไม่ได้ตรวจ
const kycStatusId = ref(null);     // enum KYCStatus จาก getRecord()
const kycRejectReason = ref('');
const isGivingConsent = ref(false);
const kycError = ref('');

const isOnTPIXChain = computed(() => Number(walletStore.chainId) === props.chainId);

const networkStats = ref({ ...props.stats });

const MAX_ENDPOINT_LEN = 100;      // ตรงกับ MAX_ENDPOINT_LEN ในสัญญา

// ============================================================
//  ABI — ชื่อและลำดับพารามิเตอร์ต้องตรงกับ NodeRegistryV2 เป๊ะ ๆ
// ============================================================
const NODE_REGISTRY_ABI = [
    'function registerNode(uint8 _tier, string _endpoint) payable',
    'function claimRewards()',
    'function deregisterNode()',
    'function withdrawSlashedStake()',
    'function pendingReward(address _operator) view returns (uint256)',
    'function claimableNow(address _operator) view returns (uint256)',
    'function availableRewardFunds() view returns (uint256)',
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
//  Provider สำหรับ "อ่าน" — ยิงตรงไปที่ RPC ของเชนเสมอ
//  ไม่ใช้ provider ของกระเป๋า เพราะถ้าผู้ใช้อยู่คนละเชนจะอ่านได้ค่าของเชนอื่น
// ============================================================
let readProviderPromise = null;
async function getReadProvider() {
    if (!readProviderPromise) {
        readProviderPromise = import('ethers').then(({ JsonRpcProvider, Network }) =>
            new JsonRpcProvider(props.rpcUrl, Network.from(props.chainId), { staticNetwork: true })
        );
    }
    return readProviderPromise;
}

async function getReadContract() {
    if (!props.registryAddress) return null;
    const { Contract } = await import('ethers');
    return new Contract(props.registryAddress, NODE_REGISTRY_ABI, await getReadProvider());
}

// ============================================================
//  Wallet & Chain
// ============================================================
function connectWallet() { walletStore.showConnectModal = true; }

async function ensureTPIXChain() {
    if (Number(walletStore.chainId) === props.chainId) return;
    try {
        await walletStore.switchChain(props.chainId);
    } catch {
        if (walletStore.provider) {
            await addTPIXChainToWallet(walletStore.provider);
            await walletStore.switchChain(props.chainId);
        }
    }
}

async function fetchBalance() {
    if (!walletStore.isConnected || !walletStore.address) return;
    isLoadingBalance.value = true;
    try {
        // อ่านจาก RPC ของเชนโดยตรง — ได้ยอดของ TPIX Chain เสมอ
        // ต่อให้กระเป๋ายังชี้เชนอื่นอยู่ ตัวเลขบนการ์ดก็ยังถูก
        const provider = await getReadProvider();
        const bal = await provider.getBalance(walletStore.address);
        tpixBalance.value = Number(bal) / 1e18;
    } catch {
        try {
            const resp = await fetch(`/api/v1/wallet/balances?wallet_address=${walletStore.address}&chain_id=${props.chainId}`);
            if (resp.ok) {
                const d = await resp.json();
                if (d.success && d.data?.native) tpixBalance.value = parseFloat(d.data.native.balance || 0);
            }
        } catch { /* ปล่อยยอดเดิมไว้ ดีกว่าโชว์ 0 ทั้งที่มีเงิน */ }
    } finally { isLoadingBalance.value = false; }
}

// ============================================================
//  อ่านสถานะโหนดของตัวเองจากเชนตรง ๆ
// ============================================================
async function fetchMyNode() {
    if (!walletStore.address || !props.registryAddress) { myNode.value = null; return; }
    isLoadingNode.value = true;
    try {
        const c = await getReadContract();
        if (!c) return;
        const [info, pending, claimable] = await Promise.all([
            c.getNodeInfo(walletStore.address),
            c.pendingReward(walletStore.address).catch(() => 0n),
            c.claimableNow(walletStore.address).catch(() => 0n),
        ]);

        // ไม่เคยลงทะเบียน = operator เป็น 0x0
        // ห้ามดูจาก tier เพราะ tier 0 (Guardian) เป็นค่าที่ถูกต้อง
        if (!info || /^0x0{40}$/i.test(info.operator)) { myNode.value = null; return; }

        const now = Math.floor(Date.now() / 1000);
        myNode.value = {
            operator: info.operator,
            tierId: Number(info.tier),
            statusId: Number(info.status),
            stake: Number(info.stakedAmount) / 1e18,
            registeredAt: Number(info.registeredAt),
            unlockAt: Number(info.unlockAt),
            totalRewards: Number(info.totalRewards) / 1e18,
            uptime: Number(info.uptime) / 100,
            nodeId: info.nodeId,
            endpoint: info.endpoint,
            pendingUnclaimed: Number(info.pendingUnclaimed) / 1e18,
            pending: Number(pending) / 1e18,
            claimable: Number(claimable) / 1e18,
            isLocked: Number(info.unlockAt) > now,
            unlockInSeconds: Math.max(0, Number(info.unlockAt) - now),
        };
    } catch (e) {
        console.warn('[masternode] อ่านสถานะโหนดไม่สำเร็จ', e);
    } finally { isLoadingNode.value = false; }
}

/** ชั้น Validator ลงทะเบียนไม่ได้ถ้ายังไม่ผ่าน KYC — ตรวจก่อนให้กด ไม่ใช่ปล่อยให้ revert */
async function fetchKycStatus() {
    if (!props.kycContract || !walletStore.address) { kycApproved.value = null; kycStatusId.value = null; return; }
    try {
        const { Contract } = await import('ethers');
        const kyc = new Contract(props.kycContract, KYC_ABI, await getReadProvider());
        const [approved, record] = await Promise.all([
            kyc.isApproved(walletStore.address),
            kyc.getRecord(walletStore.address).catch(() => null),
        ]);
        kycApproved.value = approved;
        kycStatusId.value = record ? Number(record.status) : null;
        kycRejectReason.value = record?.rejectReason || '';
    } catch { kycApproved.value = null; kycStatusId.value = null; }
}

/** ขั้นที่ 1 ของ KYC — ผู้สมัครต้องเซ็นเอง กฎหมาย PDPA ให้ทีมงานกดแทนไม่ได้ */
async function giveKycConsent() {
    if (!walletStore.isConnected || !props.kycContract) return;
    isGivingConsent.value = true; kycError.value = '';
    try {
        await ensureTPIXChain();
        const signer = walletStore.signer;
        if (!signer) throw new Error('ไม่พบตัวเซ็นในกระเป๋า');

        const { Contract } = await import('ethers');
        const kyc = new Contract(props.kycContract, KYC_ABI, signer);
        const tx = await kyc.giveConsent({ gasPrice: 0 });
        await tx.wait();
        await fetchKycStatus();
    } catch (e) {
        kycError.value = /Consent already active/.test(e?.message || '')
            ? 'ให้ความยินยอมไว้แล้ว'
            : humanError(e);
    } finally { isGivingConsent.value = false; }
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
        const minStake = Number(chain.min_stake ?? 0);
        const maxNodes = Number(chain.max_nodes ?? 0);
        const activeNodes = Number(chain.active_nodes ?? 0);
        const isFull = maxNodes > 0 && activeNodes >= maxNodes;
        const needsKyc = idx === 3;

        return {
            id: idx,
            ...skin,
            minStake,
            lockDays: Number(chain.lock_days ?? 0),
            maxNodes,
            activeNodes,
            slotsLeft: maxNodes > 0 ? Math.max(0, maxNodes - activeNodes) : null,
            rewardShare: `${(Number(chain.reward_share ?? 0) / 100).toFixed(0)}%`,
            slashPercent: Number(chain.slash_percent ?? 0) / 100,
            isFull,
            needsKyc,
            canAfford: minStake > 0 && tpixBalance.value >= minStake,
            source: chain.source || 'config',
        };
    });
});

/** ปุ่มซื้อกดได้ไหม + ถ้ากดไม่ได้ เพราะอะไร (ตอบเป็นภาษาคน ไม่ใช่ revert string) */
function blockReason(tier) {
    if (!props.registryLive) return 'สัญญายังไม่ได้ติดตั้งบนเชน — เปิดขายเมื่อระบบพร้อม';
    if (myNode.value && myNode.value.statusId === 1) return 'คุณมีโหนดที่ทำงานอยู่แล้ว 1 ตัวต่อ 1 กระเป๋า';
    // สัญญาบังคับ status == Inactive เท่านั้น — โหนดที่ถูกปรับ (2/4) หรือออกไปแล้ว (3)
    // ยังไม่ใช่ Inactive ต้องถอนเงินต้นที่เหลือให้จบก่อน ไม่งั้น registerNode revert "Already registered"
    if (myNode.value && myNode.value.statusId !== 0 && myNode.value.statusId !== 1) {
        return 'ต้องถอนเงินต้นที่เหลือของโหนดเดิมให้จบก่อนจึงลงทะเบียนใหม่ได้';
    }
    if (myNode.value && myNode.value.pendingUnclaimed > 0) return 'ต้องกดรับรางวัลค้างให้หมดก่อนลงทะเบียนใหม่';
    if (tier.isFull) return `ชั้นนี้เต็มแล้ว (${tier.activeNodes}/${tier.maxNodes})`;
    if (!tier.canAfford) return `ต้องมีอีก ${fmtNum(Math.max(0, tier.minStake - Math.floor(tpixBalance.value)))} TPIX`;
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
    'Insufficient stake': 'จำนวน TPIX ที่ส่งไปน้อยกว่าขั้นต่ำของชั้นนี้',
    'Endpoint required': 'ต้องกรอกที่อยู่โหนด',
    'Endpoint too long': `ที่อยู่โหนดยาวเกิน ${MAX_ENDPOINT_LEN} ตัวอักษร`,
    'Tier full': 'ชั้นนี้เต็มโควตาแล้ว',
    'KYC contract not configured': 'ชั้น Validator ยังไม่เปิด — ผู้ดูแลยังไม่ผูกสัญญา KYC',
    'KYC not approved': 'ชั้น Validator ต้องผ่าน KYC ก่อน',
    'Not active': 'โหนดนี้ไม่ได้อยู่ในสถานะทำงาน',
    'Still locked': 'ยังไม่พ้นระยะล็อก ถอนเงินต้นไม่ได้',
    'Not slashed': 'โหนดนี้ไม่ได้อยู่ในสถานะถูกปรับ',
    'Nothing to withdraw': 'ไม่มีเงินต้นเหลือให้ถอน',
    'Transfer failed': 'โอนเงินไม่สำเร็จ — สัญญามีเงินไม่พอในตอนนี้',
};

function humanError(e) {
    if (!e) return 'ทำรายการไม่สำเร็จ';
    if (e.code === 'ACTION_REJECTED' || e.code === 4001) return 'คุณยกเลิกการเซ็นในกระเป๋า';
    if (e.code === 'INSUFFICIENT_FUNDS') return 'ยอด TPIX ในกระเป๋าไม่พอ';

    const blob = [e.reason, e.shortMessage, e.info?.error?.message, e.data?.message, e.message]
        .filter(Boolean).join(' | ');

    for (const [key, msg] of Object.entries(REVERT_MAP)) {
        if (blob.includes(key)) return msg;
    }
    if (/user rejected|User denied/i.test(blob)) return 'คุณยกเลิกการเซ็นในกระเป๋า';
    if (/network|timeout|could not detect/i.test(blob)) return 'ต่อกับเชนไม่ได้ — ลองใหม่อีกครั้ง';

    return e.shortMessage || e.reason || 'ทำรายการไม่สำเร็จ';
}

// ============================================================
//  ซื้อ / ลงทะเบียนโหนด
// ============================================================
const isClaiming = ref(false);
const isDeregistering = ref(false);
const isWithdrawing = ref(false);
const claimMessage = ref('');
const claimIsError = ref(false);

async function stakeAndRegister(tier) {
    if (!walletStore.isConnected) return connectWallet();

    endpointTouched.value = true;
    stakeError.value = '';
    stakeTxHash.value = '';

    const blocked = blockReason(tier);
    if (blocked) { stakeError.value = blocked; return; }

    isStaking.value = true;
    selectedTier.value = tier.id;
    try {
        await ensureTPIXChain();
        const signer = walletStore.signer;
        if (!signer) throw new Error('ไม่พบตัวเซ็นในกระเป๋า');

        const { Contract, parseUnits } = await import('ethers');
        const registry = new Contract(props.registryAddress, NODE_REGISTRY_ABI, signer);

        // ส่ง "ตัวเลขชั้นบนสัญญา" (tier.id) ไม่ใช่ลำดับการ์ดบนหน้าจอ
        const stakeWei = parseUnits(String(tier.minStake), 18);

        // ลองยิงแห้งก่อน — จับ revert ตั้งแต่ยังไม่เสียเวลาผู้ใช้เซ็น
        await registry.registerNode.staticCall(tier.id, endpoint.value.trim(), { value: stakeWei });

        // เชนนี้ค่าแก๊สเป็น 0 (ตั้งใน genesis) จึงต้องบังคับ gasPrice = 0
        // ไม่งั้นกระเป๋าจะประเมินราคาแก๊สเองแล้วฟ้องว่ายอดไม่พอ
        const tx = await registry.registerNode(tier.id, endpoint.value.trim(), {
            value: stakeWei,
            gasPrice: 0,
        });
        stakeTxHash.value = tx.hash;
        await tx.wait();

        await Promise.all([fetchBalance(), fetchMyNode(), refreshTiers()]);
        activeTab.value = 'setup';
    } catch (e) {
        stakeError.value = humanError(e);
    } finally {
        isStaking.value = false;
        selectedTier.value = null;
    }
}

async function claimRewards() {
    if (!walletStore.isConnected || !props.registryAddress) return;
    isClaiming.value = true; claimMessage.value = ''; claimIsError.value = false;
    try {
        await ensureTPIXChain();
        const signer = walletStore.signer;
        if (!signer) throw new Error('ไม่พบตัวเซ็นในกระเป๋า');

        const { Contract } = await import('ethers');
        const registry = new Contract(props.registryAddress, NODE_REGISTRY_ABI, signer);
        const tx = await registry.claimRewards({ gasPrice: 0 });
        await tx.wait();

        claimMessage.value = 'รับรางวัลเรียบร้อย';
        await Promise.all([fetchBalance(), fetchMyNode()]);
    } catch (e) {
        claimIsError.value = true;
        claimMessage.value = humanError(e);
    } finally { isClaiming.value = false; }
}

async function deregisterNode() {
    if (!walletStore.isConnected || !props.registryAddress || !myNode.value) return;
    if (myNode.value.isLocked) {
        stakeError.value = `ยังไม่พ้นระยะล็อก — ถอนได้ ${unlockDateText.value}`;
        return;
    }
    const warn = myNode.value.pending > myNode.value.claimable
        ? '\n\nหมายเหตุ: รางวัลที่พูลจ่ายได้ตอนนี้น้อยกว่าที่คุณสะสมไว้ ส่วนที่เหลือจะค้างไว้เคลมทีหลังได้'
        : '';
    if (!confirm(`ยืนยันปิดโหนดและถอนเงินต้น ${fmtNum(myNode.value.stake)} TPIX?${warn}`)) return;

    isDeregistering.value = true; stakeError.value = '';
    try {
        await ensureTPIXChain();
        const signer = walletStore.signer;
        if (!signer) throw new Error('ไม่พบตัวเซ็นในกระเป๋า');

        const { Contract } = await import('ethers');
        const registry = new Contract(props.registryAddress, NODE_REGISTRY_ABI, signer);
        const tx = await registry.deregisterNode({ gasPrice: 0 });
        await tx.wait();

        await Promise.all([fetchBalance(), fetchMyNode(), refreshTiers()]);
    } catch (e) { stakeError.value = humanError(e); } finally { isDeregistering.value = false; }
}

/** โหนดที่ถูกปรับ (slashed) ถอนเงินต้นที่เหลือด้วยฟังก์ชันคนละตัว */
async function withdrawSlashedStake() {
    if (!walletStore.isConnected || !props.registryAddress) return;
    isWithdrawing.value = true; stakeError.value = '';
    try {
        await ensureTPIXChain();
        const signer = walletStore.signer;
        if (!signer) throw new Error('ไม่พบตัวเซ็นในกระเป๋า');

        const { Contract } = await import('ethers');
        const registry = new Contract(props.registryAddress, NODE_REGISTRY_ABI, signer);
        const tx = await registry.withdrawSlashedStake({ gasPrice: 0 });
        await tx.wait();

        await Promise.all([fetchBalance(), fetchMyNode()]);
    } catch (e) { stakeError.value = humanError(e); } finally { isWithdrawing.value = false; }
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
const poolUnfunded = computed(() =>
    props.registryLive && parseFloat(networkStats.value.reward_pool_available || '0') <= 0
);

const canRegister = computed(() => !myNode.value || myNode.value.statusId !== 1);

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
}

watch(() => walletStore.address, (a) => {
    if (a) reloadWalletState();
    else { tpixBalance.value = 0; myNode.value = null; kycApproved.value = null; }
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
    }, 30000);
});
onUnmounted(() => clearInterval(pollInterval));

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
                <div v-if="!registryLive" class="glass rounded-2xl p-5 border-l-4 border-yellow-500 flex items-start gap-4">
                    <div class="w-11 h-11 rounded-xl bg-yellow-500/20 flex items-center justify-center text-2xl shrink-0">🚧</div>
                    <div class="flex-1">
                        <div class="text-yellow-400 font-bold">ระบบยังไม่เปิดให้ลงทะเบียน</div>
                        <p class="text-xs text-gray-400 mt-1">
                            สัญญา NodeRegistry ยังไม่ถูกติดตั้งบนเชน (หรือที่อยู่ที่ตั้งไว้ไม่มีโค้ดอยู่จริง)
                            ตัวเลขด้านล่างจึงเป็นค่าตั้งต้น ยังกดซื้อไม่ได้
                        </p>
                        <p v-if="!networkStats.rpc_connected" class="text-xs text-red-400 mt-1">
                            เชื่อมต่อ RPC ของเชนไม่ได้ในตอนนี้
                        </p>
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
                            <img src="/tpixlogo.webp" alt="TPIX" class="relative w-24 h-24 shadow-2xl ring-2 ring-white/10" />
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
                            <div class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">APY สูงสุด</div>
                                <div class="text-xl font-black text-trading-green">20%</div>
                            </div>
                            <div class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">โหนดที่ทำงานอยู่</div>
                                <div class="text-xl font-black text-gradient">{{ fmtNum(networkStats.total_nodes || 0) }}</div>
                            </div>
                            <div class="glass-sm rounded-xl p-3">
                                <div class="text-xs text-gray-400">ขั้นต่ำ</div>
                                <div class="text-xl font-black text-cyan-400">10K</div>
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
                                    <img src="/tpixlogo.webp" alt="TPIX" class="relative w-12 h-12 ring-2 ring-white/20" />
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
                                            <button @click="ensureTPIXChain" class="underline hover:text-yellow-300 ml-1">สลับ</button>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="text-right">
                                <div class="text-[10px] text-gray-400 uppercase tracking-widest">TPIX ของคุณ</div>
                                <div class="text-3xl font-black leading-tight" :class="tpixBalance > 0 ? 'text-gradient' : 'text-gray-600'">
                                    <span v-if="isLoadingBalance" class="animate-pulse text-gray-500">···</span>
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
                        <button @click="ensureTPIXChain" class="btn-primary px-6 py-2.5 rounded-xl font-bold shrink-0">
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

                        <!-- ─── โหนดที่มีอยู่แล้ว ─── -->
                        <div v-if="showNodeCard" class="relative">
                            <div :class="['absolute -inset-1 bg-gradient-to-b rounded-3xl blur-xl opacity-50', myTierSkin?.gradient]" />
                            <div :class="['glass relative rounded-2xl p-6 space-y-5', myTierSkin?.border]">

                                <div class="flex flex-wrap items-start justify-between gap-4">
                                    <div class="flex items-center gap-4">
                                        <img src="/tpixlogo.webp" alt="TPIX" class="w-14 h-14 ring-1 ring-white/10" />
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
                                            :disabled="isClaiming || myNode.claimable <= 0"
                                            class="px-5 py-2.5 rounded-xl text-sm font-bold transition-all bg-trading-green/20 text-trading-green border border-trading-green/30 hover:bg-trading-green/30 disabled:opacity-40 disabled:cursor-not-allowed"
                                        >
                                            {{ isClaiming ? 'กำลังรับ...' : 'รับรางวัล' }}
                                        </button>
                                    </div>
                                    <p v-if="myNode.pending > myNode.claimable" class="text-[11px] text-yellow-400/90">
                                        พูลรางวัลมีเงินไม่พอจ่ายเต็มจำนวนในตอนนี้ ส่วนที่เหลือไม่หาย — ค้างไว้เคลมได้เมื่อพูลถูกเติม
                                        (เงินต้นของผู้วางค้ำถูกกันไว้ ไม่ถูกนำมาจ่ายรางวัล)
                                    </p>
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
                                    <button @click="withdrawSlashedStake" :disabled="isWithdrawing"
                                            class="px-5 py-2 rounded-xl text-sm font-bold bg-trading-red/20 text-trading-red border border-trading-red/30 hover:bg-trading-red/30 disabled:opacity-40">
                                        {{ isWithdrawing ? 'กำลังถอน...' : 'ถอนเงินต้นที่เหลือ' }}
                                    </button>
                                </div>

                                <!-- ปิดโหนด — ปิดปุ่มไว้จนกว่าจะพ้นล็อก ไม่ปล่อยให้เสียเที่ยวเซ็น -->
                                <button
                                    v-if="myNode.statusId === 1"
                                    @click="deregisterNode"
                                    :disabled="isDeregistering || myNode.isLocked"
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
                                        <li>ปล่อยให้โปรแกรมส่งสัญญาณชีพต่อเนื่อง — คะแนน uptime มีผลกับรางวัลโดยตรง</li>
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
                                    คุณมี <span class="text-gradient font-bold">{{ fmtNum(Math.floor(tpixBalance)) }} TPIX</span>
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
                                        :disabled="isGivingConsent"
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
                                                <img src="/tpixlogo.webp" alt="TPIX" class="relative w-14 h-14 ring-1 ring-white/10" />
                                            </div>
                                            <div :class="['px-3 py-1 rounded-full text-xs font-black border', tier.badge]">
                                                {{ tier.apy }} APY
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
                                                :disabled="!!blockReason(tier) || isStaking"
                                                :class="[
                                                    'w-full py-3 rounded-xl font-bold text-sm transition-all duration-300',
                                                    !blockReason(tier) && !isStaking
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
                                { label: 'โหนดทั้งหมด', value: fmtNum(networkStats.total_nodes || 0), color: 'text-white' },
                                { label: 'Validator', value: networkStats.validator_nodes || 0, color: 'text-red-400' },
                                { label: 'Guardian', value: networkStats.guardian_nodes || 0, color: 'text-yellow-400' },
                                { label: 'Sentinel', value: networkStats.sentinel_nodes || 0, color: 'text-purple-400' },
                                { label: 'Light', value: networkStats.light_nodes || 0, color: 'text-cyan-400' },
                                { label: 'บล็อกล่าสุด', value: fmtNum(networkStats.block_height || 0), color: 'text-white' },
                            ]" :key="stat.label"
                               class="glass-card rounded-xl p-4 text-center group hover:scale-105 transition-transform">
                                <div class="text-[10px] text-gray-500 uppercase tracking-wider mb-1">{{ stat.label }}</div>
                                <div :class="['text-2xl font-black', stat.color]">{{ stat.value }}</div>
                            </div>
                        </div>

                        <!-- พูลรางวัล — แยก "เพดานตามตาราง" ออกจาก "เงินที่มีอยู่จริง" -->
                        <div class="glass rounded-2xl p-6 space-y-4">
                            <div class="flex items-center gap-3 flex-wrap">
                                <img src="/tpixlogo.webp" alt="TPIX" class="w-8 h-8 ring-1 ring-white/10" />
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
                                    <div class="text-lg font-bold text-white">{{ fmtNum(networkStats.reward_pool_funded || 0) }}</div>
                                </div>
                                <div class="glass-sm rounded-xl p-3">
                                    <div class="text-[10px] text-gray-500 uppercase">จ่ายได้จริงตอนนี้</div>
                                    <div class="text-lg font-bold" :class="poolUnfunded ? 'text-yellow-400' : 'text-trading-green'">
                                        {{ fmtNum(networkStats.reward_pool_available || 0) }}
                                    </div>
                                </div>
                                <div class="glass-sm rounded-xl p-3">
                                    <div class="text-[10px] text-gray-500 uppercase">จ่ายออกไปแล้ว</div>
                                    <div class="text-lg font-bold text-white">{{ fmtNum(networkStats.total_rewards_distributed || 0) }}</div>
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
                            <div class="flex rounded-xl overflow-hidden h-8 sm:h-10 mb-3">
                                <div class="bg-gradient-to-r from-red-500 to-rose-500 flex items-center justify-center text-[8px] sm:text-[10px] font-bold text-white" style="width:20%">
                                    <span class="hidden sm:inline">20% Validator</span><span class="sm:hidden">20%</span>
                                </div>
                                <div class="bg-gradient-to-r from-yellow-500 to-amber-500 flex items-center justify-center text-[8px] sm:text-[10px] font-bold text-black" style="width:35%">
                                    <span class="hidden sm:inline">35% Guardian</span><span class="sm:hidden">35%</span>
                                </div>
                                <div class="bg-gradient-to-r from-purple-500 to-violet-500 flex items-center justify-center text-[8px] sm:text-[10px] font-bold text-white" style="width:30%">
                                    <span class="hidden sm:inline">30% Sentinel</span><span class="sm:hidden">30%</span>
                                </div>
                                <div class="bg-gradient-to-r from-cyan-500 to-blue-500 flex items-center justify-center text-[8px] sm:text-[10px] font-bold text-white" style="width:15%">
                                    <span class="hidden sm:inline">15% Light</span><span class="sm:hidden">15%</span>
                                </div>
                            </div>
                            <p class="text-xs text-gray-400">
                                รางวัลของแต่ละชั้นถูกหารเท่า ๆ กันในหมู่โหนดที่ทำงานอยู่ของชั้นนั้น
                                แล้วคูณด้วยคะแนน uptime ของโหนดคุณอีกที
                            </p>
                        </div>

                        <div class="relative group">
                            <div class="absolute -inset-1 bg-gradient-to-r from-accent-500/20 via-primary-500/20 to-warm-500/20 rounded-3xl blur-xl opacity-60 group-hover:opacity-100 transition-opacity" />
                            <div class="glass-brand relative rounded-2xl p-8 text-center">
                                <img src="/tpixlogo.webp" alt="TPIX" class="w-16 h-16 mx-auto mb-4 ring-2 ring-white/10" />
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
