<script setup>
/**
 * TPIX TRADE - Token Factory Page
 * Wizard UI สำหรับสร้างเหรียญบน TPIX Chain — ง่าย, ครบ, ใช้งานจริง
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import AwaitingDeployNotice from '@/Components/AwaitingDeployNotice.vue';
import RiskAckBox from '@/Components/TokenFactory/RiskAckBox.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTokenFactoryStore } from '@/Stores/tokenFactoryStore';
import { useTranslation } from '@/Composables/useTranslation';
import { TPIX_CHAIN_CONFIG } from '@/utils/web3';
import {
    allowedDecimals,
    computeFee,
    isUiOptionAllowed,
    isUiTypeAllowed,
    optionForUiKey,
    requiredRiskAcks,
    sanitizeSymbol,
} from '@/utils/tokenFactoryPolicy';

const props = defineProps({
    tokens: Object,
    factoryConfig: {
        type: Object,
        default: () => ({
            creation_fee_tpix: 100,
            creation_fee_usd: 10,
            fee_payment_method: 'tpix',
            nft_enabled: true,
            max_supply_limit: 999999999999999,
            creation_enabled: true,
            policy: null,
        }),
    },
});

const { t } = useTranslation();
const walletStore = useWalletStore();
const factoryStore = useTokenFactoryStore();

const activeTab = ref('create');
const showSuccess = ref(false);
const successMessage = ref('');
const showError = ref(false);
const errorMessage = ref('');

function toastSuccess(msg, duration = 3000) {
    successMessage.value = msg;
    showSuccess.value = true;
    setTimeout(() => { showSuccess.value = false; }, duration);
}

function toastError(msg, duration = 4500) {
    errorMessage.value = msg;
    showError.value = true;
    setTimeout(() => { showError.value = false; }, duration);
}

// ===================== WIZARD STATE =====================
const wizardStep = ref(1);
const totalSteps = 5;
const isSubmitting = ref(false);
const formErrors = ref({});

// Form data
const form = ref({
    name: '',
    symbol: '',
    decimals: 18,
    total_supply: '',
    description: '',
    website: '',
    logo_url: '',
    token_type: 'standard',
    token_category: 'fungible',
});

// Logo upload state
const logoFile = ref(null);
const logoPreview = ref('');
const isUploadingLogo = ref(false);

// ===================== CHAIN =====================
// สร้างได้บนเชน TPIX (4289) เท่านั้น — เดิมมีปุ่ม Testnet (4290) ที่บอกว่า "ฟรี"
// แต่สคริปต์ deploy ส่งขึ้น 4289 เสมอ = ได้เหรียญบน mainnet ฟรี และบันทึก chain_id ผิด
const TPIX_CHAIN_ID = 4289;
const selectedChainId = TPIX_CHAIN_ID;
const selectedChainName = 'TPIX Chain';

// ===================== POLICY =====================
// นโยบายจากเซิร์ฟเวอร์ — ประเภท/ตัวเลือกที่ปิดจะไม่แสดง ของเสี่ยงที่เปิดต้องติ๊กยอมรับ
// (เซิร์ฟเวอร์ตรวจซ้ำทุกครั้ง หน้านี้แค่ทำให้ผู้ใช้ไม่ต้องเจอคำขอที่ถูกปฏิเสธแน่ ๆ)
const policy = computed(() => props.factoryConfig.policy || null);
const limits = computed(() => policy.value?.limits || { name_max_length: 50, symbol_max_length: 11 });

// ===================== TOKEN CATEGORIES =====================
// ข้อความ (ชื่อ/คำอธิบาย) ของหมวด ชนิด กลุ่ม และตัวเลือก อยู่ใน i18n ที่ page.tokenFactory.*
// — config ตรงนี้เก็บแค่โครงสร้าง เพื่อให้สลับภาษาแล้วเปลี่ยนตามทันที
const tokenCategories = [
    {
        id: 'fungible',
        icon: 'coin',
        color: 'from-primary-500 to-accent-500',
    },
    {
        id: 'nft',
        icon: 'nft',
        color: 'from-purple-500 to-pink-500',
    },
    {
        id: 'special',
        icon: 'star',
        color: 'from-amber-500 to-orange-500',
    },
];

// ===================== TOKEN TYPES per category =====================
const tokenTypesByCategory = {
    fungible: [
        { value: 'standard', icon: '🔒', recommended: true },
        { value: 'mintable', icon: '➕' },
        { value: 'burnable', icon: '🔥' },
        { value: 'mintable_burnable', icon: '⚡' },
        { value: 'utility', icon: '🔧' },
        { value: 'reward', icon: '🎁' },
    ],
    nft: [
        { value: 'nft', icon: '🖼️', recommended: true },
        { value: 'nft_collection', icon: '🃏' },
    ],
    special: [
        { value: 'governance', icon: '🏛️', recommended: true },
        { value: 'stablecoin', icon: '💵' },
    ],
};

// ===================== SUB-OPTIONS CONFIG per token type =====================
// ออฟชั่นย่อยของแต่ละ token type — แสดงใน Step 3 (Advanced Options)
const subOptionsConfig = {
    // ─── Common options (เพิ่มให้ทุก type ที่ต้องการ) ───
    _common: [
        {
            group: 'protection',
            icon: '🛡️', fee: 0,
            options: [
                { key: 'pausable', type: 'toggle', fee: 5, default: false },
                { key: 'blacklist_enabled', type: 'toggle', fee: 5, default: false },
            ],
        },
        {
            group: 'ownership',
            icon: '👑', fee: 0,
            options: [
                { key: 'ownership_type', type: 'select', fee: 0, default: 'ownable',
                    choices: [
                        { value: 'ownable' },
                        { value: 'renounced' },
                    ],
                },
            ],
        },
        {
            // บริการเสียเงิน — ราคาจริงมาจากนโยบาย (ค่าตั้งต้น 1,000 TPIX) ไม่ใช่จากช่อง fee ตรงนี้
            group: 'explorer',
            icon: '🔍', fee: 0,
            options: [
                { key: 'source_verify', type: 'toggle', fee: 1000, default: false },
            ],
        },
    ],

    // ─── Standard: แค่ common options ───
    standard: [],

    // ─── Mintable ───
    mintable: [
        {
            group: 'mint_controls',
            icon: '➕', fee: 0,
            options: [
                { key: 'mint_cap_enabled', type: 'toggle', fee: 0, default: false },
                { key: 'mint_cap', type: 'number', fee: 0, default: '', show_if: 'mint_cap_enabled' },
            ],
        },
    ],

    // ─── Burnable ───
    burnable: [
        {
            group: 'burn_controls',
            icon: '🔥', fee: 0,
            options: [
                { key: 'auto_burn_enabled', type: 'toggle', fee: 10, default: false },
                { key: 'auto_burn_rate', type: 'range', fee: 0, default: 1, min: 0.1, max: 10, step: 0.1, show_if: 'auto_burn_enabled' },
                { key: 'burn_floor', type: 'number', fee: 0, default: '' },
            ],
        },
    ],

    // ─── Mintable + Burnable ───
    mintable_burnable: [
        {
            group: 'mint_controls',
            icon: '➕', fee: 0,
            options: [
                { key: 'mint_cap_enabled', type: 'toggle', fee: 0, default: false },
                { key: 'mint_cap', type: 'number', fee: 0, default: '', show_if: 'mint_cap_enabled' },
            ],
        },
        {
            group: 'burn_controls',
            icon: '🔥', fee: 0,
            options: [
                { key: 'auto_burn_enabled', type: 'toggle', fee: 10, default: false },
                { key: 'auto_burn_rate', type: 'range', fee: 0, default: 1, min: 0.1, max: 10, step: 0.1, show_if: 'auto_burn_enabled' },
            ],
        },
    ],

    // ─── Utility Token (ออฟชั่นเยอะที่สุด) ───
    utility: [
        {
            group: 'tax_system',
            icon: '💰', fee: 15,
            options: [
                { key: 'tax_enabled', type: 'toggle', fee: 15, default: false },
                { key: 'buy_tax_rate', type: 'range', fee: 0, default: 3, min: 0, max: 25, step: 0.5, show_if: 'tax_enabled' },
                { key: 'sell_tax_rate', type: 'range', fee: 0, default: 5, min: 0, max: 25, step: 0.5, show_if: 'tax_enabled' },
                { key: 'transfer_tax_rate', type: 'range', fee: 0, default: 0, min: 0, max: 10, step: 0.5, show_if: 'tax_enabled' },
                { key: 'tax_wallet', type: 'address', fee: 0, default: '', show_if: 'tax_enabled' },
                { key: 'marketing_wallet', type: 'address', fee: 0, default: '', show_if: 'tax_enabled' },
                { key: 'marketing_share', type: 'range', fee: 0, default: 40, min: 0, max: 100, step: 5, show_if: 'tax_enabled' },
            ],
        },
        {
            group: 'anti_whale',
            icon: '🐋', fee: 10,
            options: [
                { key: 'anti_whale_enabled', type: 'toggle', fee: 10, default: false },
                { key: 'max_wallet_percent', type: 'range', fee: 0, default: 2, min: 0.5, max: 10, step: 0.5, show_if: 'anti_whale_enabled' },
                { key: 'max_tx_percent', type: 'range', fee: 0, default: 1, min: 0.1, max: 5, step: 0.1, show_if: 'anti_whale_enabled' },
            ],
        },
        {
            group: 'anti_bot',
            icon: '🤖', fee: 10,
            options: [
                { key: 'anti_bot_enabled', type: 'toggle', fee: 10, default: false },
                { key: 'anti_bot_duration', type: 'number', fee: 0, default: 30, min: 5, max: 1440, show_if: 'anti_bot_enabled' },
                { key: 'trading_cooldown', type: 'number', fee: 0, default: 30, min: 5, max: 300, show_if: 'anti_bot_enabled' },
            ],
        },
        {
            group: 'auto_liquidity',
            icon: '💧', fee: 15,
            options: [
                { key: 'auto_lp_enabled', type: 'toggle', fee: 15, default: false },
                { key: 'auto_lp_rate', type: 'range', fee: 0, default: 20, min: 5, max: 50, step: 5, show_if: 'auto_lp_enabled' },
                { key: 'lp_lock_days', type: 'number', fee: 0, default: 90, min: 30, max: 365, show_if: 'auto_lp_enabled' },
            ],
        },
    ],

    // ─── Reward Token ───
    reward: [
        {
            group: 'reward_system',
            icon: '🎁', fee: 20,
            options: [
                { key: 'reward_type', type: 'select', fee: 20, default: 'reflection',
                    choices: [
                        { value: 'reflection' },
                        { value: 'dividend' },
                        { value: 'staking' },
                    ],
                },
                { key: 'reflection_rate', type: 'range', fee: 0, default: 3, min: 1, max: 15, step: 0.5 },
                { key: 'min_hold_for_reward', type: 'number', fee: 0, default: 100 },
            ],
        },
        {
            group: 'vesting',
            icon: '🔐', fee: 10,
            options: [
                { key: 'vesting_enabled', type: 'toggle', fee: 10, default: false },
                { key: 'vesting_cliff_days', type: 'number', fee: 0, default: 90, min: 0, max: 730, show_if: 'vesting_enabled' },
                { key: 'vesting_duration_days', type: 'number', fee: 0, default: 365, min: 30, max: 1460, show_if: 'vesting_enabled' },
            ],
        },
    ],

    // ─── Single NFT ───
    nft: [
        {
            group: 'nft_metadata',
            icon: '📄', fee: 0,
            options: [
                { key: 'metadata_storage', type: 'select', fee: 0, default: 'ipfs',
                    choices: [
                        { value: 'ipfs' },
                        { value: 'onchain' },
                        { value: 'centralized' },
                    ],
                },
                { key: 'metadata_uri', type: 'text', fee: 0, default: '' },
            ],
        },
        {
            group: 'royalty',
            icon: '👑', fee: 5,
            options: [
                { key: 'royalty_enabled', type: 'toggle', fee: 5, default: true },
                { key: 'royalty_rate', type: 'range', fee: 0, default: 5, min: 0, max: 15, step: 0.5, show_if: 'royalty_enabled' },
                { key: 'royalty_wallet', type: 'address', fee: 0, default: '', show_if: 'royalty_enabled' },
            ],
        },
        {
            group: 'nft_transfer',
            icon: '🔗', fee: 0,
            options: [
                { key: 'soulbound', type: 'toggle', fee: 5, default: false },
            ],
        },
    ],

    // ─── NFT Collection ───
    nft_collection: [
        {
            group: 'mint_config',
            icon: '🎨', fee: 0,
            options: [
                { key: 'mint_type', type: 'select', fee: 0, default: 'public',
                    choices: [
                        { value: 'public' },
                        { value: 'whitelist' },
                        { value: 'free_claim' },
                    ],
                },
                { key: 'mint_price', type: 'number', fee: 0, default: 0, min: 0 },
                { key: 'max_per_wallet', type: 'number', fee: 0, default: 10, min: 1 },
                { key: 'max_per_tx', type: 'number', fee: 0, default: 5, min: 1 },
                { key: 'reserve_count', type: 'number', fee: 0, default: 0, min: 0 },
            ],
        },
        {
            group: 'reveal',
            icon: '🎭', fee: 10,
            options: [
                { key: 'delayed_reveal', type: 'toggle', fee: 10, default: false },
                { key: 'placeholder_uri', type: 'text', fee: 0, default: '', show_if: 'delayed_reveal' },
            ],
        },
        {
            group: 'royalty',
            icon: '👑', fee: 5,
            options: [
                { key: 'royalty_enabled', type: 'toggle', fee: 5, default: true },
                { key: 'royalty_rate', type: 'range', fee: 0, default: 5, min: 0, max: 15, step: 0.5, show_if: 'royalty_enabled' },
                { key: 'royalty_wallet', type: 'address', fee: 0, default: '', show_if: 'royalty_enabled' },
            ],
        },
    ],

    // ─── Governance ───
    governance: [
        {
            group: 'voting',
            icon: '🗳️', fee: 0,
            options: [
                { key: 'voting_type', type: 'select', fee: 0, default: 'token_weighted',
                    choices: [
                        { value: 'token_weighted' },
                        { value: 'quadratic' },
                    ],
                },
                { key: 'proposal_threshold', type: 'number', fee: 0, default: 1000 },
                { key: 'quorum_percent', type: 'range', fee: 0, default: 10, min: 1, max: 67, step: 1 },
                { key: 'voting_period_days', type: 'number', fee: 0, default: 7, min: 1, max: 30 },
            ],
        },
        {
            group: 'treasury',
            icon: '🏦', fee: 15,
            options: [
                { key: 'treasury_enabled', type: 'toggle', fee: 15, default: false },
                { key: 'treasury_wallet', type: 'address', fee: 0, default: '', show_if: 'treasury_enabled' },
            ],
        },
        {
            group: 'delegation',
            icon: '🤝', fee: 0,
            options: [
                { key: 'delegation_enabled', type: 'toggle', fee: 5, default: true },
            ],
        },
    ],

    // ─── Stablecoin ───
    stablecoin: [
        {
            group: 'peg',
            icon: '📌', fee: 0,
            options: [
                { key: 'peg_currency', type: 'select', fee: 0, default: 'THB',
                    choices: [
                        { value: 'THB' },
                        { value: 'USD' },
                        { value: 'EUR' },
                    ],
                },
                { key: 'peg_ratio', type: 'number', fee: 0, default: 1, min: 0.0001 },
            ],
        },
        {
            group: 'reserve',
            icon: '🏦', fee: 0,
            options: [
                { key: 'reserve_type', type: 'select', fee: 0, default: 'full_collateral',
                    choices: [
                        { value: 'full_collateral' },
                        { value: 'fractional' },
                        { value: 'algorithmic' },
                    ],
                },
                { key: 'reserve_wallet', type: 'address', fee: 0, default: '' },
            ],
        },
        {
            group: 'compliance',
            icon: '⚖️', fee: 0,
            options: [
                { key: 'freeze_enabled', type: 'toggle', fee: 5, default: false },
                { key: 'kyc_required', type: 'toggle', fee: 5, default: false },
            ],
        },
    ],
};

// ===================== LABEL HELPERS (i18n) =====================
// เรียกตอน render เท่านั้น — t() อ่าน locale ทุกครั้ง จึงเปลี่ยนภาษาได้ทันที
const TF = 'page.tokenFactory';
const categoryLabel = (id) => t(`${TF}.categories.${id}.label`);
const categoryDesc = (id) => t(`${TF}.categories.${id}.desc`);
const typeLabel = (value) => t(`${TF}.types.${value}.label`);
const typeDesc = (value) => t(`${TF}.types.${value}.desc`);
const groupLabel = (group) => t(`${TF}.groups.${group}`);
const optLabel = (opt) => t(`${TF}.opts.${opt.key}.label`);
/** ตัวเลือกบางตัวไม่มีคำอธิบาย — คืนค่าว่างแทนการโชว์ชื่อคีย์ */
function optDesc(opt) {
    const key = `${TF}.opts.${opt.key}.desc`;
    const text = t(key);
    return text === key ? '' : text;
}
const choiceLabel = (opt, choice) => t(`${TF}.opts.${opt.key}.choices.${choice.value}`);

const TOKEN_STATUSES = ['pending', 'deploying', 'deployed', 'failed', 'rejected'];
const statusLabel = (status) => (TOKEN_STATUSES.includes(status) ? t(`${TF}.status.${status}`) : status);

/** หมวดที่ไม่มีชนิดไหนเปิดเลย = กดไม่ได้ */
const visibleCategories = computed(() => tokenCategories.map((cat) => ({
    ...cat,
    disabled: !(tokenTypesByCategory[cat.id] || []).some((t) => isUiTypeAllowed(policy.value, t.value)),
})));

const currentTypes = computed(() =>
    (tokenTypesByCategory[form.value.token_category] || []).filter((t) => isUiTypeAllowed(policy.value, t.value))
);

const isNFT = computed(() => form.value.token_category === 'nft');

/** ตัวเลือกที่เป็นจำนวนเงิน/เหรียญ — ส่งเป็นข้อความทศนิยม ไม่ผ่าน Number */
const UNIT_OPTION_KEYS = ['mint_price', 'mint_cap', 'burn_floor', 'proposal_threshold', 'min_hold_for_reward'];

// ===================== SUB-OPTIONS STATE =====================
const subOptions = ref({});
const riskAck = ref({});

// ดึง config สำหรับ token type ปัจจุบัน (common + type-specific)
const currentSubOptionsGroups = computed(() => {
    const tt = form.value.token_type || 'standard';
    const common = subOptionsConfig._common || [];
    const specific = subOptionsConfig[tt] || [];
    // ตัดตัวเลือกที่นโยบายปิด/ล็อก หรือไม่มีผลกับชนิดนี้ (เช่น pausable ของ NFT) ทิ้ง
    return [...common, ...specific]
        .map((group) => ({ ...group, options: group.options.filter((opt) => isUiOptionAllowed(policy.value, tt, opt.key)) }))
        .filter((group) => group.options.length > 0);
});

// ตรวจว่า option ควรแสดงหรือไม่ (show_if dependency)
function shouldShowOption(opt) {
    if (!opt.show_if) return true;
    return !!subOptions.value[opt.show_if];
}

// เมื่อเปลี่ยน token type → reset sub-options เป็น defaults
watch(() => form.value.token_type, () => {
    const defaults = {};
    for (const group of currentSubOptionsGroups.value) {
        for (const opt of group.options) {
            defaults[opt.key] = opt.default;
        }
    }
    subOptions.value = defaults;
    riskAck.value = {};
}, { immediate: true });

// ===================== RISK ACKNOWLEDGEMENT =====================
// ของเสี่ยงที่เปิดอยู่ต้องติ๊กยอมรับทีละรายการ — ส่งไปเป็น risk_ack ให้เซิร์ฟเวอร์ตรวจ
const requiredAcks = computed(() => requiredRiskAcks(policy.value, form.value.token_type, subOptions.value));
const typeAck = computed(() => requiredAcks.value.find((a) => a.kind === 'type') || null);
const missingAcks = computed(() => requiredAcks.value.filter((a) => !riskAck.value[a.key]));

/** ราคาของสวิตช์นี้ตามนโยบาย (แหล่งเดียวกับใบเสนอราคา) — ช่อง fee ใน subOptionsConfig เป็นแค่ค่าเก่าที่ไม่ใช้แล้ว */
function uiOptionFee(opt) {
    if (opt.type !== 'toggle') return 0;
    const option = optionForUiKey(policy.value, opt.key);
    return option ? Number(policy.value?.options?.[option]?.fee) || 0 : 0;
}

/** กล่องเตือนของตัวเลือกนี้ (เฉพาะสวิตช์ที่เปิดอยู่และเป็นของเสี่ยง) */
function ackForOption(opt) {
    if (opt.type !== 'toggle' || !subOptions.value[opt.key]) return null;
    const option = optionForUiKey(policy.value, opt.key);
    return requiredAcks.value.find((a) => a.kind === 'option' && a.key === option) || null;
}

// สรุป sub-options ที่เลือกสำหรับ Review
const selectedSubOptionsSummary = computed(() => {
    const summary = [];
    for (const group of currentSubOptionsGroups.value) {
        const activeOpts = [];
        for (const opt of group.options) {
            if (!shouldShowOption(opt)) continue;
            const val = subOptions.value[opt.key];
            if (val === undefined || val === '' || val === opt.default) continue;
            if (opt.type === 'toggle' && val) {
                activeOpts.push({ ...opt, displayValue: t(`${TF}.review.on`) });
            } else if (opt.type === 'range' || opt.type === 'number') {
                activeOpts.push({ ...opt, displayValue: val });
            } else if (opt.type === 'select') {
                const choice = opt.choices?.find(c => c.value === val);
                activeOpts.push({ ...opt, displayValue: choice ? choiceLabel(opt, choice) : val });
            } else if (opt.type === 'address' || opt.type === 'text') {
                if (val) activeOpts.push({ ...opt, displayValue: val.length > 20 ? val.slice(0, 10) + '...' + val.slice(-6) : val });
            }
        }
        if (activeOpts.length > 0) {
            summary.push({ group: group.group, icon: group.icon, options: activeOpts });
        }
    }
    return summary;
});

// มี sub-options ที่เลือกอยู่หรือไม่
const hasActiveSubOptions = computed(() => selectedSubOptionsSummary.value.length > 0);

// ===================== SUPPLY PRESETS =====================
const supplyPresets = computed(() => {
    if (isNFT.value) {
        return [
            { label: '1', value: '1', desc: t(`${TF}.presets.single`) },
            { label: '100', value: '100', desc: t(`${TF}.presets.limited`) },
            { label: '1K', value: '1000', desc: t(`${TF}.presets.collection`) },
            { label: '10K', value: '10000', desc: t(`${TF}.presets.large`) },
        ];
    }
    return [
        { label: '1M', value: '1000000', desc: t(`${TF}.presets.startup`) },
        { label: '10M', value: '10000000', desc: t(`${TF}.presets.standard`) },
        { label: '100M', value: '100000000', desc: t(`${TF}.presets.large`) },
        { label: '1B', value: '1000000000', desc: t(`${TF}.presets.enterprise`) },
    ];
});

// ===================== WIZARD VALIDATION =====================
const stepValid = computed(() => {
    switch (wizardStep.value) {
        case 1: return !!form.value.token_category;
        case 2: return !!form.value.token_type && (!typeAck.value || !!riskAck.value[typeAck.value.key]);
        case 3: return missingAcks.value.filter((a) => a.kind === 'option').length === 0;
        case 4: return !!form.value.name && !!form.value.symbol && !!form.value.total_supply;
        case 5: return true; // Review step
        default: return false;
    }
});

/** issues จากหลังบ้านมาได้ทั้ง array และ object — ทำให้เป็น array เสมอ */
const factoryIssues = computed(() => {
    const raw = props.factoryConfig.issues;
    if (Array.isArray(raw)) return raw.filter(Boolean);
    return Object.values(raw || {}).filter(Boolean);
});

/**
 * คำขอที่ยังไม่จบของกระเป๋านี้ — ข้อความชุดเดียวกับ TokenFactoryService::IN_PROGRESS_STAGE_TH
 * failed นับว่ายังไม่จบ: ทีมงานสั่งสร้างใหม่ได้ ค่าธรรมเนียมที่จ่ายแล้วไม่หาย
 */
// เก็บเป็นคีย์แปล — แปลตอน render ใน template
const IN_PROGRESS_STAGE = {
    pending: 'page.tokenFactory.stage.pending',
    deploying: 'page.tokenFactory.stage.deploying',
    failed: 'page.tokenFactory.stage.failed',
};
const openRequest = computed(() => factoryStore.myTokens.find((t) => IN_PROGRESS_STAGE[t?.status]) || null);

const canCreate = computed(() => {
    return walletStore.isConnected
        && !openRequest.value
        && form.value.name
        && form.value.symbol
        && form.value.total_supply
        && props.factoryConfig.creation_enabled
        && props.factoryConfig.ready !== false
        && isUiTypeAllowed(policy.value, form.value.token_type)
        && missingAcks.value.length === 0
        && !isSubmitting.value;
});

// ===================== FEE =====================
// แหล่งเดียวกับใบเสนอราคาของเซิร์ฟเวอร์ (นโยบาย) — ยอดที่ต้องจ่ายจริงคือยอดในใบเสนอราคา
const dynamicFee = computed(() => {
    const fee = computeFee(policy.value, form.value.token_type, subOptions.value);
    return { total: fee.total, breakdown: fee.breakdown, is_free: fee.isFree };
});

const feeDisplay = computed(() => (dynamicFee.value.is_free
    ? { amount: t(`${TF}.free`), currency: '', isFree: true }
    : { amount: dynamicFee.value.total.toLocaleString(), currency: 'TPIX', isFree: false }));

/** ทศนิยมที่เลือกได้ตามนโยบายของชนิดนี้ */
const decimalChoices = computed(() => allowedDecimals(policy.value, form.value.token_type));

const maxSupplyForType = computed(() => {
    const erc20 = ['standard', 'mintable', 'burnable', 'mintable_burnable'];
    const type = erc20.includes(form.value.token_type) ? 'erc20' : form.value.token_type;
    return policy.value?.types?.[type]?.max_supply || props.factoryConfig.max_supply_limit;
});

const symbolIsReserved = computed(() =>
    !!form.value.symbol && (policy.value?.reserved_symbols || []).includes(form.value.symbol.toUpperCase())
);

function onSymbolInput(event) {
    form.value.symbol = sanitizeSymbol(event.target.value, limits.value.symbol_max_length);
    event.target.value = form.value.symbol;
}

// ===================== WIZARD NAVIGATION =====================
function nextStep() {
    if (stepValid.value && wizardStep.value < totalSteps) {
        wizardStep.value++;
    }
}

function prevStep() {
    if (wizardStep.value > 1) {
        wizardStep.value--;
    }
}

function goToStep(step) {
    // Only allow going back, not jumping forward
    if (step < wizardStep.value) {
        wizardStep.value = step;
    }
}

function selectCategory(catId) {
    form.value.token_category = catId;
    // Reset type when changing category — เลือกได้เฉพาะชนิดที่นโยบายเปิด
    const types = (tokenTypesByCategory[catId] || []).filter((t) => isUiTypeAllowed(policy.value, t.value));
    const recommended = types.find(t => t.recommended);
    form.value.token_type = recommended?.value || types[0]?.value || '';

    // NFT defaults
    if (catId === 'nft') {
        form.value.decimals = 0;
        form.value.total_supply = '1';
    } else {
        form.value.decimals = decimalChoices.value.includes(18) ? 18 : (decimalChoices.value[0] ?? 18);
        form.value.total_supply = '';
    }
}

// ===================== LOGO UPLOAD =====================
async function handleLogoSelect(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    // Validate file type & size (client-side)
    // ไม่รับ SVG — เซิร์ฟเวอร์ปฏิเสธอยู่แล้ว (SVG ฝังสคริปต์ได้)
    const allowedTypes = ['image/png', 'image/jpeg', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        formErrors.value.logo = t(`${TF}.errors.logoType`);
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        formErrors.value.logo = t(`${TF}.errors.logoSize`);
        return;
    }

    formErrors.value.logo = '';
    logoFile.value = file;

    // Preview
    const reader = new FileReader();
    reader.onload = (e) => { logoPreview.value = e.target.result; };
    reader.readAsDataURL(file);

    // Upload ทันที
    isUploadingLogo.value = true;
    try {
        const formData = new FormData();
        formData.append('logo', file);
        // หลังบ้านผูกไฟล์กับกระเป๋าเพื่อจำกัดจำนวนไฟล์ต่อคน — ไม่ส่งมาจะถูกปฏิเสธ
        formData.append('wallet_address', walletStore.address || '');
        const res = await fetch('/api/v1/token-factory/upload-logo', {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                Accept: 'application/json',
            },
        });
        const data = await res.json();
        if (data.success) {
            form.value.logo_url = data.data.logo_url;
        } else {
            formErrors.value.logo = t(`${TF}.errors.uploadFailed`);
        }
    } catch {
        formErrors.value.logo = t(`${TF}.errors.uploadFailed`);
    } finally {
        isUploadingLogo.value = false;
    }
}

function removeLogo() {
    logoFile.value = null;
    logoPreview.value = '';
    form.value.logo_url = '';
}

// ===================== SUBMIT + FEE PAYMENT =====================
//
// 1. ขอใบเสนอราคา (เซิร์ฟเวอร์ตรวจฟอร์ม/นโยบายครบ แล้วล็อกยอด)
// 2. โอน TPIX บนเชน 4289 จากกระเป๋าที่เชื่อมอยู่ไปที่กระเป๋ารับค่าธรรมเนียม
// 3. ถามสถานะจนครบบล็อกยืนยัน
// 4. ส่งคำขอสร้างพร้อม tx hash (เซิร์ฟเวอร์ตรวจบนเชนซ้ำอีกรอบ)
//
// ⚠️ จ่ายแล้วแต่ส่งคำขอไม่สำเร็จ (ปิดหน้า/เน็ตหลุด/ฟอร์มผิด) ต้องส่งต่อได้โดยไม่ต้องจ่ายซ้ำ
//    จึงจำใบเสนอราคา + tx hash + ฟอร์มไว้ในเครื่อง จนกว่าจะสร้างคำขอสำเร็จ
const payment = ref({ stage: 'idle', txHash: '', confirmations: 0, required: 0, message: '' });
const pendingPayment = ref(null);
let disposed = false;

const FEE_POLL_MS = 5000;
const FEE_POLL_MAX = 72; // ~6 นาที

function pendingKey(address) {
    return `tpix_factory_pending_fee_${String(address || '').toLowerCase()}`;
}

function loadPendingPayment() {
    pendingPayment.value = null;
    if (!walletStore.address) return;
    try {
        const raw = localStorage.getItem(pendingKey(walletStore.address));
        const saved = raw ? JSON.parse(raw) : null;
        if (saved?.quote?.quote_id && saved?.feeTxHash && saved?.payload) pendingPayment.value = saved;
    } catch {
        pendingPayment.value = null;
    }
}

function savePendingPayment(data) {
    pendingPayment.value = data;
    try {
        // คีย์ตามกระเป๋าที่จ่ายจริง (ไม่ใช่กระเป๋าที่เชื่อมอยู่ตอนนี้ ซึ่งอาจถูกสลับไปแล้ว)
        localStorage.setItem(pendingKey(data.payload.wallet_address), JSON.stringify(data));
    } catch {
        // โหมดส่วนตัว/ปิด storage — ยังทำงานต่อได้ แค่กลับมาส่งต่อภายหลังไม่ได้
    }
}

function clearPendingPayment(address = walletStore.address) {
    pendingPayment.value = null;
    try {
        localStorage.removeItem(pendingKey(address));
    } catch {
        // ไม่มีอะไรต้องทำ
    }
}

function discardPendingPayment() {
    const hash = pendingPayment.value?.feeTxHash;
    if (!window.confirm(t(`${TF}.confirm.discardPending`, { hash }))) return;
    clearPendingPayment();
}

function buildPayload() {
    return {
        ...form.value,
        symbol: sanitizeSymbol(form.value.symbol, limits.value.symbol_max_length),
        sub_options: subOptions.value,
        risk_ack: requiredAcks.value.map((a) => a.key),
        creator_address: walletStore.address,
        wallet_address: walletStore.address,
        chain_id: TPIX_CHAIN_ID,
    };
}

function apiErrorMessage(e) {
    const data = e?.response?.data;
    if (data?.errors) return Object.values(data.errors).flat().join(' · ');
    if (data?.error?.message) return data.error.message;
    if (e?.code === 4001 || e?.code === 'ACTION_REJECTED') return t(`${TF}.errors.paymentCancelled`);
    if (/insufficient/i.test(e?.message || '')) return t(`${TF}.errors.insufficientTpix`);
    if (e?.response?.status === 429) {
        return pendingPayment.value
            ? t(`${TF}.errors.rateLimitedResume`)
            : t(`${TF}.errors.rateLimited`);
    }
    return e?.message || t(`${TF}.errors.generic`);
}

/** ค่าธรรมเนียมจ่ายบนเชน TPIX เท่านั้น — สลับเชนก่อน และยืนยันว่าสลับได้จริง */
async function ensureTpixChain() {
    if (walletStore.chainId === TPIX_CHAIN_ID) return;
    await walletStore.switchChain(TPIX_CHAIN_ID);
    if (walletStore.chainId !== TPIX_CHAIN_ID) {
        throw new Error(t(`${TF}.errors.switchToTpix`));
    }
}

/** ถามจนครบบล็อกยืนยัน — ออกเมื่อครบ หรือโยนข้อผิดพลาดที่ต้องแสดงผู้ใช้ */
async function waitForFeeConfirmation(quoteId, feeTxHash) {
    for (let i = 0; i < FEE_POLL_MAX; i++) {
        if (disposed) throw new Error(t(`${TF}.errors.leftPageResume`));

        let status;
        try {
            status = await factoryStore.checkFeeStatus({
                wallet_address: walletStore.address,
                quote_id: quoteId,
                fee_tx_hash: feeTxHash,
            });
        } catch (e) {
            // เน็ตหลุด / เชนตอบช้า (5xx) = ถามใหม่รอบหน้า · 4xx = เซิร์ฟเวอร์ปฏิเสธจริง ต้องบอกผู้ใช้
            const httpStatus = e?.response?.status;
            if (httpStatus && httpStatus < 500) throw e;
            status = { confirmed: false, confirmations: payment.value.confirmations, required: payment.value.required };
        }
        if (disposed) throw new Error(t(`${TF}.errors.leftPageResume`));

        payment.value.confirmations = status.confirmations || 0;
        payment.value.required = status.required || 0;
        if (status.confirmed) return;

        payment.value.message = status.message || '';
        await new Promise((resolve) => setTimeout(resolve, FEE_POLL_MS));
    }
    throw new Error(t(`${TF}.errors.feeNotConfirmed`));
}

async function submitWithFee(payload, quote, feeTxHash) {
    if (feeTxHash) {
        payment.value = { stage: 'confirming', txHash: feeTxHash, confirmations: 0, required: 0, message: '' };
        await waitForFeeConfirmation(quote.quote_id, feeTxHash);
    }

    payment.value.stage = 'submitting';
    await factoryStore.createToken({ ...payload, quote_id: quote.quote_id, fee_tx_hash: feeTxHash || null });
    if (disposed) return;

    clearPendingPayment(payload.wallet_address);
    payment.value = { stage: 'done', txHash: feeTxHash || '', confirmations: 0, required: 0, message: '' };
    onCreated(payload.symbol);
}

function onCreated(symbol) {
    successMessage.value = t(`${TF}.toast.requestSubmitted`, { symbol });
    showSuccess.value = true;

    form.value = { name: '', symbol: '', decimals: 18, total_supply: '', description: '', website: '', logo_url: '', token_type: 'standard', token_category: 'fungible' };
    logoFile.value = null;
    logoPreview.value = '';
    riskAck.value = {};
    wizardStep.value = 1;
    activeTab.value = 'my-tokens';

    if (walletStore.address) factoryStore.fetchMyTokens(walletStore.address);
    factoryStore.fetchTokens();
    setTimeout(() => { showSuccess.value = false; }, 5000);
}

async function handleCreate() {
    if (openRequest.value) {
        formErrors.value = { general: t(`${TF}.errors.openRequest`, { symbol: openRequest.value.symbol }) };
        return;
    }
    if (!canCreate.value || isSubmitting.value) return;
    if (pendingPayment.value) {
        formErrors.value = { general: t(`${TF}.errors.pendingPaymentFirst`) };
        return;
    }

    formErrors.value = {};
    isSubmitting.value = true;

    try {
        const payload = buildPayload();
        payment.value = { stage: 'quoting', txHash: '', confirmations: 0, required: 0, message: '' };
        const quote = await factoryStore.requestQuote(payload);
        if (disposed) return;

        let feeTxHash = null;
        if (!quote.is_free) {
            if (!quote.fee_wallet || quote.chain_id !== TPIX_CHAIN_ID) {
                throw new Error(t(`${TF}.errors.feeNotReady`));
            }
            if (!walletStore.signer) throw new Error(t(`${TF}.errors.reconnectWallet`));

            payment.value.stage = 'awaiting_wallet';
            await ensureTpixChain();

            // gasPrice 0 ใช้ได้บนเชน TPIX — ensureTpixChain ยืนยันแล้วว่าอยู่เชนถูก
            const tx = await walletStore.signer.sendTransaction({
                to: quote.fee_wallet,
                value: BigInt(quote.amount_wei),
                gasPrice: 0,
            });
            feeTxHash = tx.hash;

            // จ่ายแล้ว — จำไว้ก่อนทำอย่างอื่น กันต้องจ่ายซ้ำถ้าขั้นต่อไปพัง
            savePendingPayment({ payload, quote, feeTxHash, savedAt: Date.now() });
        }

        await submitWithFee(payload, quote, feeTxHash);
    } catch (e) {
        if (disposed) return;
        formErrors.value = { general: apiErrorMessage(e) };
        payment.value.stage = pendingPayment.value ? 'paid_pending' : 'idle';
    } finally {
        if (!disposed) isSubmitting.value = false;
    }
}

/** ส่งคำขอต่อจากการชำระที่ค้างไว้ — ไม่โอนเงินซ้ำ */
async function resumePendingPayment() {
    const saved = pendingPayment.value;
    if (!saved || isSubmitting.value) return;

    formErrors.value = {};
    isSubmitting.value = true;
    try {
        await submitWithFee({ ...saved.payload, wallet_address: walletStore.address }, saved.quote, saved.feeTxHash);
    } catch (e) {
        if (disposed) return;
        formErrors.value = { general: apiErrorMessage(e) };
        payment.value.stage = 'paid_pending';
    } finally {
        if (!disposed) isSubmitting.value = false;
    }
}

// ===================== SOURCE VERIFY (ซื้อทีหลัง) =====================
// ยืนยันซอร์สโค้ดบน explorer เป็นบริการเสียเงิน — ติ๊กตอนสร้าง หรือซื้อทีหลังจากการ์ดเหรียญที่ deploy แล้ว
// ลำดับเดียวกับค่าสร้าง: ใบเสนอราคา → โอน → รอยืนยันบนเชน → ส่ง tx ให้เซิร์ฟเวอร์ตรวจซ้ำ
// จ่ายแล้วจำไว้ในเครื่อง (แยกต่อเหรียญ) — ส่งไม่สำเร็จกดส่งต่อได้ ไม่ต้องจ่ายซ้ำ
const verifyJobs = ref({}); // tokenId → { stage, confirmations, required }
const verifyPending = ref({}); // tokenId → { quote, feeTxHash, wallet, savedAt }

const sourceVerifyOption = computed(() => policy.value?.options?.source_verify || null);
const sourceVerifyOn = computed(() => sourceVerifyOption.value?.state === 'on');

function verifyKey(address) {
    return `tpix_factory_pending_verify_${String(address || '').toLowerCase()}`;
}

function loadVerifyPending() {
    verifyPending.value = {};
    if (!walletStore.address) return;
    try {
        const saved = JSON.parse(localStorage.getItem(verifyKey(walletStore.address)) || '{}');
        for (const [id, item] of Object.entries(saved || {})) {
            if (item?.quote?.quote_id && /^0x[a-fA-F0-9]{64}$/.test(item?.feeTxHash || '')
                && item.wallet === String(walletStore.address).toLowerCase()) verifyPending.value[id] = item;
        }
    } catch {
        verifyPending.value = {};
    }
}

function persistVerifyPending(address) {
    try {
        const own = Object.fromEntries(Object.entries(verifyPending.value).filter(([, v]) => v.wallet === String(address).toLowerCase()));
        if (Object.keys(own).length) localStorage.setItem(verifyKey(address), JSON.stringify(own));
        else localStorage.removeItem(verifyKey(address));
    } catch {
        // storage ใช้ไม่ได้ — ยังจ่ายและส่งได้ในรอบนี้ แค่กลับมาส่งต่อภายหลังไม่ได้
    }
}

/** verified | submitted | queued | error | unpaid */
function explorerVerifyState(token) {
    const status = token?.metadata?.explorer_verification?.status;
    if (status === 'verified') return 'verified';
    if (token?.metadata?.source_verify?.paid !== true) return 'unpaid';
    if (status === 'submitted') return 'submitted';
    if (status === 'error' || status === 'skipped') return 'error';
    return 'queued';
}

// text = คีย์แปล (แปลตอน render)
const VERIFY_STATE_TEXT = {
    verified: { text: 'page.tokenFactory.verify.state.verified', cls: 'text-green-400' },
    submitted: { text: 'page.tokenFactory.verify.state.submitted', cls: 'text-blue-300' },
    queued: { text: 'page.tokenFactory.verify.state.queued', cls: 'text-blue-300' },
    error: { text: 'page.tokenFactory.verify.state.error', cls: 'text-amber-300' },
};

const verifyStageText = (job) => ({
    quoting: t(`${TF}.verify.stage.quoting`),
    awaiting_wallet: t(`${TF}.verify.stage.awaitingWallet`),
    confirming: job.required
        ? t(`${TF}.payment.confirmingBlocks`, { n: job.confirmations, total: job.required })
        : t(`${TF}.payment.confirming`),
    submitting: t(`${TF}.verify.stage.submitting`),
}[job.stage] || '');

function assertSameWallet(wallet) {
    if (String(walletStore.address || '').toLowerCase() !== wallet) {
        throw new Error(t(`${TF}.errors.walletSwitched`));
    }
}

async function waitForVerifyFee(tokenId, saved) {
    for (let i = 0; i < FEE_POLL_MAX; i++) {
        if (disposed) throw new Error(t(`${TF}.errors.leftPageVerify`));
        assertSameWallet(saved.wallet);
        let status;
        try {
            status = await factoryStore.checkFeeStatus({ wallet_address: saved.wallet, quote_id: saved.quote.quote_id, fee_tx_hash: saved.feeTxHash });
        } catch (e) {
            const httpStatus = e?.response?.status;
            if (httpStatus && httpStatus < 500) throw e;
            status = { confirmed: false };
        }
        if (status.confirmed) return;
        verifyJobs.value[tokenId] = { stage: 'confirming', confirmations: status.confirmations || 0, required: status.required || 0 };
        await new Promise((resolve) => setTimeout(resolve, FEE_POLL_MS));
    }
    throw new Error(t(`${TF}.errors.verifyFeeNotConfirmed`));
}

async function finishVerifyPurchase(token, saved) {
    const wallet = saved.wallet;
    verifyJobs.value[token.id] = { stage: 'confirming', confirmations: 0, required: 0 };
    await waitForVerifyFee(token.id, saved);

    assertSameWallet(wallet);
    verifyJobs.value[token.id] = { stage: 'submitting' };
    await factoryStore.purchaseSourceVerify(token.id, { wallet_address: wallet, quote_id: saved.quote.quote_id, fee_tx_hash: saved.feeTxHash });

    delete verifyPending.value[token.id];
    persistVerifyPending(wallet);
    if (disposed) return;
    toastSuccess(t(`${TF}.toast.verifyPaid`, { symbol: token.symbol }), 5000);
    if (walletStore.address) factoryStore.fetchMyTokens(walletStore.address);
}

async function buySourceVerify(token) {
    if (!token?.id || verifyJobs.value[token.id]) return;
    if (!walletStore.isConnected) { walletStore.openConnectModal(); return; }

    const saved = verifyPending.value[token.id];
    try {
        if (saved) {
            await finishVerifyPurchase(token, saved);
            return;
        }

        verifyJobs.value[token.id] = { stage: 'quoting' };
        const quote = await factoryStore.requestSourceVerifyQuote(token.id, walletStore.address);
        if (disposed) return;
        if (!quote.fee_wallet || quote.chain_id !== TPIX_CHAIN_ID) throw new Error(t(`${TF}.errors.feeNotReady`));
        if (!window.confirm(t(`${TF}.confirm.buyVerify`, { symbol: token.symbol, amount: quote.amount }))) {
            return;
        }
        if (!walletStore.signer) throw new Error(t(`${TF}.errors.reconnectWallet`));

        verifyJobs.value[token.id] = { stage: 'awaiting_wallet' };
        await ensureTpixChain();
        const tx = await walletStore.signer.sendTransaction({ to: quote.fee_wallet, value: BigInt(quote.amount_wei), gasPrice: 0 });

        // จ่ายแล้ว — จำไว้ก่อนทำอย่างอื่น
        const wallet = String(walletStore.address).toLowerCase();
        verifyPending.value[token.id] = { quote, feeTxHash: tx.hash, wallet, savedAt: Date.now() };
        persistVerifyPending(wallet);

        await finishVerifyPurchase(token, verifyPending.value[token.id]);
    } catch (e) {
        if (disposed) return;
        const code = e?.response?.data?.error?.code;
        // เซิร์ฟเวอร์บอกว่าจ่าย/ยืนยันไปแล้ว — รายการในเครื่องหมดประโยชน์
        if (['SOURCE_VERIFY_ALREADY_PAID', 'SOURCE_VERIFY_ALREADY_VERIFIED'].includes(code)) {
            delete verifyPending.value[token.id];
            persistVerifyPending(walletStore.address);
            factoryStore.fetchMyTokens(walletStore.address);
        }
        toastError(apiErrorMessage(e), 6000);
    } finally {
        if (!disposed) delete verifyJobs.value[token.id];
    }
}

// รายการค้างของเหรียญที่ระบบบันทึกว่าจ่ายแล้ว (ส่งสำเร็จแต่คำตอบไม่ถึงหน้าเว็บ) — ล้างทิ้ง
watch(() => factoryStore.myTokens, (list) => {
    let changed = false;
    for (const t of list || []) {
        if (verifyPending.value[t.id] && explorerVerifyState(t) !== 'unpaid') {
            delete verifyPending.value[t.id];
            changed = true;
        }
    }
    if (changed && walletStore.address) persistVerifyPending(walletStore.address);
});

// text = คีย์แปล (แปลตอน render)
const REFUND_TEXT = {
    pending: { text: 'page.tokenFactory.refund.pending', cls: 'bg-amber-500/10 text-amber-300' },
    sending: { text: 'page.tokenFactory.refund.sending', cls: 'bg-blue-500/10 text-blue-300' },
    refunded: { text: 'page.tokenFactory.refund.refunded', cls: 'bg-green-500/10 text-green-400' },
    failed: { text: 'page.tokenFactory.refund.failed', cls: 'bg-amber-500/10 text-amber-300' },
};

function weiToTpix(wei) {
    if (!wei || !/^[0-9]+$/.test(String(wei))) return '';
    const v = BigInt(wei);
    const unit = 10n ** 18n;
    const frac = (v % unit).toString().padStart(18, '0').slice(0, 6).replace(/0+$/, '');
    return `${(v / unit).toLocaleString('en-US')}${frac ? '.' + frac : ''}`;
}

const paymentStageText = computed(() => ({
    quoting: t(`${TF}.payment.quoting`),
    awaiting_wallet: t(`${TF}.payment.awaitingWallet`),
    confirming: payment.value.required
        ? t(`${TF}.payment.confirmingBlocks`, { n: payment.value.confirmations, total: payment.value.required })
        : t(`${TF}.payment.confirming`),
    submitting: t(`${TF}.payment.submitting`),
}[payment.value.stage] || ''));

// ===================== LIFECYCLE =====================
onMounted(async () => {
    loadPendingPayment();
    loadVerifyPending();
    await factoryStore.fetchTokens();
    if (walletStore.address) {
        await factoryStore.fetchMyTokens(walletStore.address);
        snapshotStatuses();
        if (hasPendingTokens()) startPolling();
    }
});

onUnmounted(() => {
    disposed = true;
    stopPolling();
});

// คำขอเข้าระบบแล้วแต่คำตอบไม่ถึงหน้าเว็บ (เน็ตหลุดหลังเซิร์ฟเวอร์บันทึก) — ถ้าไม่ล้าง
// ป้าย "ส่งคำขอต่อ" จะค้าง และกดส่งซ้ำก็ได้แค่ "ธุรกรรมนี้ถูกใช้แล้ว"
watch([openRequest, pendingPayment], ([open, paid]) => {
    if (open && paid && open.fee_tx_hash && open.fee_tx_hash.toLowerCase() === String(paid.feeTxHash).toLowerCase()) {
        clearPendingPayment();
        payment.value = { stage: 'idle', txHash: '', confirmations: 0, required: 0, message: '' };
        formErrors.value = {};
    }
});

watch(() => walletStore.address, async (addr) => {
    stopPolling();
    // สลับกระเป๋ากลางทาง — รายการชำระค้างเป็นของกระเป๋าเดิม ห้ามเอาไปส่งในนามกระเป๋าใหม่
    loadPendingPayment();
    loadVerifyPending();
    payment.value = { stage: 'idle', txHash: '', confirmations: 0, required: 0, message: '' };
    if (addr) {
        await factoryStore.fetchMyTokens(addr);
        snapshotStatuses();
        if (hasPendingTokens()) startPolling();
    }
});

// ===================== HELPERS =====================
function getStatusColor(status) {
    const colors = {
        pending: 'text-yellow-400 bg-yellow-500/10 border-yellow-500/20',
        deploying: 'text-blue-400 bg-blue-500/10 border-blue-500/20',
        deployed: 'text-green-400 bg-green-500/10 border-green-500/20',
        failed: 'text-red-400 bg-red-500/10 border-red-500/20',
        rejected: 'text-red-400 bg-red-500/10 border-red-500/20',
    };
    return colors[status] || 'text-gray-400 bg-gray-500/10 border-gray-500/20';
}

function formatSupply(val) {
    return Number(val).toLocaleString();
}

// ===================== ADD TOKEN TO WALLET =====================
const addingToken = ref(null); // track which contract is currently being added (for button spinner)
const copyingToken = ref(null); // track which contract is currently being copied

async function handleAddToWallet(token) {
    if (!token?.contract_address) return;
    if (!walletStore.isConnected) {
        walletStore.openConnectModal();
        return;
    }

    // กระเป๋าฝัง (TPIX Wallet) ไม่มีรายการเหรียญให้เพิ่ม — บอกทางออกแทนการกดแล้วเงียบ
    if (!walletStore.canAddTpixAssets) {
        toastError(t(`${TF}.errors.walletNoWatchAsset`, { symbol: token.symbol }));
        return;
    }

    addingToken.value = token.contract_address;
    const owner = walletStore.address;
    try {
        /*
         * เหรียญจาก Token Factory อยู่บนเชน TPIX — ต้องอยู่เชนนี้ก่อน watchAsset
         * (กระเป๋าผูกเหรียญกับเครือข่ายที่เลือกอยู่) สลับไม่ได้ = หยุด ไม่เพิ่มลงเชนผิด
         *
         * ★ เดิมใช้ window.ethereum ตรง ๆ: ผู้ใช้ที่เชื่อม OKX/Coinbase/WalletConnect
         *   คำขอไปโผล่ที่ MetaMask (หรือไม่มีที่ไปเลย) และส่ง TPIX_CHAIN_CONFIG เข้า
         *   buildAddChainParams ได้ chainId '0x0x10C1' → เพิ่มเชนไม่สำเร็จทุกครั้ง
         */
        if (Number(walletStore.chainId) !== TPIX_CHAIN_CONFIG.chainIdNum) {
            try {
                await walletStore.switchChain(TPIX_CHAIN_CONFIG.chainIdNum);
            } catch (switchErr) {
                const declined = switchErr?.code === 4001 || /user rejected|user denied/i.test(switchErr?.message || '');
                toastError(declined
                    ? t(`${TF}.errors.switchDeclined`, { symbol: token.symbol })
                    : t(`${TF}.errors.switchFailed`));
                return;
            }
        }
        // ผู้ใช้สลับบัญชีระหว่างรอ — ไม่เพิ่มให้บัญชีที่ไม่ได้กด
        if (walletStore.address !== owner) return;

        const added = await walletStore.watchAsset({
            address: token.contract_address,
            symbol: token.symbol,
            decimals: Number(token.decimals ?? 18),
            image: token.logo_url || undefined,
        });

        if (added) {
            toastSuccess(t(`${TF}.toast.tokenAdded`, { symbol: token.symbol }));
        }
        // false = ผู้ใช้กดปฏิเสธในกระเป๋าเอง (กระเป๋าโชว์ UI ของมันแล้ว) — ไม่ต้องเตือนซ้ำ
    } finally {
        addingToken.value = null;
    }
}

async function handleCopyContract(token) {
    if (!token?.contract_address) return;
    copyingToken.value = token.contract_address;
    try {
        await navigator.clipboard.writeText(token.contract_address);
        toastSuccess(t(`${TF}.toast.contractCopied`), 2000);
    } catch (e) {
        console.warn('[TokenFactory] Copy failed', e);
        toastError(t(`${TF}.errors.copyFailed`));
    } finally {
        setTimeout(() => { copyingToken.value = null; }, 600);
    }
}

// ===================== STATUS POLLING + DEPLOY-READY DETECTION =====================
// Poll /api/v1/token-factory/my-tokens ทุก 15 วินาที ตราบใดที่มี token อยู่ในสถานะ pending/deploying
// เมื่อตรวจพบ transition → 'deployed' จะแสดง modal ถามว่าจะเพิ่มเข้ากระเป๋าเลยไหม
const POLL_INTERVAL_MS = 15000;
let pollTimer = null;
const knownStatuses = ref(new Map()); // Map<tokenId, status> — snapshot ไว้ detect transition
const deployReadyToken = ref(null);   // token object ที่เพิ่ง deploy เสร็จ (สำหรับ modal)
const promptedDeployIds = ref(new Set()); // กัน prompt ซ้ำสำหรับ id เดิมในเซสชัน

function hasPendingTokens() {
    return factoryStore.myTokens.some(t => ['pending', 'deploying'].includes(t?.status));
}

function snapshotStatuses() {
    const map = new Map();
    for (const t of factoryStore.myTokens) {
        if (t?.id != null) map.set(t.id, t.status);
    }
    knownStatuses.value = map;
}

function detectDeployTransition() {
    for (const t of factoryStore.myTokens) {
        if (t?.id == null || t.status !== 'deployed') continue;
        const prev = knownStatuses.value.get(t.id);
        if (prev && prev !== 'deployed' && !promptedDeployIds.value.has(t.id)) {
            // พบ transition → deployed
            deployReadyToken.value = t;
            promptedDeployIds.value.add(t.id);
            break; // prompt ทีละอัน
        }
    }
    snapshotStatuses();
}

async function pollTick() {
    if (!walletStore.address) return;
    if (!hasPendingTokens() && !deployReadyToken.value) {
        // ไม่มีอะไรต้อง poll หยุด interval เพื่อประหยัด API
        stopPolling();
        return;
    }
    await factoryStore.fetchMyTokens(walletStore.address);
    detectDeployTransition();
}

function startPolling() {
    if (pollTimer) return;
    pollTimer = setInterval(pollTick, POLL_INTERVAL_MS);
}

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
}

// เริ่ม polling เมื่อมี token pending/deploying (เช่น หลัง submit สำเร็จ)
watch(() => factoryStore.myTokens, () => {
    snapshotStatuses();
    if (hasPendingTokens()) startPolling();
}, { deep: false });

async function confirmDeployReady() {
    const t = deployReadyToken.value;
    deployReadyToken.value = null;
    if (t) await handleAddToWallet(t);
}

function dismissDeployReady() {
    deployReadyToken.value = null;
}

function getCategoryLabel(cat) {
    return tokenCategories.some(c => c.id === cat) ? categoryLabel(cat) : cat;
}

function getTypeLabel(type) {
    const known = Object.values(tokenTypesByCategory).some((types) => types.some((tt) => tt.value === type));
    return known ? typeLabel(type) : type;
}
</script>

<template>
    <Head :title="t('page.tokenFactory.meta.title')" />

    <AppLayout :hide-sidebar="true">
        <!-- Hero -->
        <section class="relative py-10 sm:py-14 overflow-hidden">
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute top-1/4 left-1/3 w-80 h-80 rounded-full bg-accent-500/10 blur-[100px]" />
                <div class="absolute bottom-1/4 right-1/3 w-80 h-80 rounded-full bg-warm-500/8 blur-[100px]" />
            </div>
            <div class="relative max-w-4xl mx-auto px-4 sm:px-6 text-center">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-accent-500 to-primary-500 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                    </svg>
                </div>
                <h1 class="text-3xl sm:text-4xl font-bold text-white mb-2">{{ t('tokenFactory.title') }}</h1>
                <p class="text-gray-400 max-w-xl mx-auto text-sm">{{ t('tokenFactory.subtitle') }}</p>

                <!-- Stats Row -->
                <div class="flex flex-wrap items-center justify-center gap-3 sm:gap-6 mt-6 text-sm">
                    <div class="flex items-center gap-1.5 text-gray-400">
                        <span class="w-2 h-2 rounded-full bg-green-400"></span>
                        {{ selectedChainName }}
                    </div>
                    <div class="text-gray-400">{{ t('page.tokenFactory.stats.gas') }}: <span class="text-green-400 font-semibold">{{ t('page.tokenFactory.stats.gasFree') }}</span></div>
                    <div class="text-gray-400">
                        {{ t('page.tokenFactory.stats.fee') }}:
                        <span :class="feeDisplay.isFree ? 'text-green-400 font-semibold' : 'text-white font-semibold'">
                            {{ feeDisplay.amount }} {{ feeDisplay.currency }}
                        </span>
                    </div>
                    <div class="text-gray-400">{{ t('page.tokenFactory.stats.block') }}: <span class="text-white font-semibold">~2s</span></div>
                </div>
            </div>
        </section>

        <!-- รอติดตั้งสัญญาบนเชน — คนละเรื่องกับ "ตั้งค่ายังไม่ครบ" จึงแยกป้ายกัน -->
        <div v-if="props.factoryConfig.awaiting_deploy" class="max-w-4xl mx-auto px-4 sm:px-6 mb-6">
            <AwaitingDeployNotice
                action="create"
                :issues="factoryIssues"
                :show-details="factoryIssues.length > 0"
            />
        </div>

        <!-- ตั้งค่ายังไม่ครบ (สัญญาพร้อมแล้วแต่ติดอย่างอื่น) -->
        <div v-else-if="props.factoryConfig.ready === false" class="max-w-4xl mx-auto px-4 sm:px-6 mb-6">
            <div class="p-4 rounded-xl bg-yellow-500/10 border border-yellow-500/20 text-yellow-400 flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div>
                    <p class="font-medium text-sm">{{ t('page.tokenFactory.notReady.title') }}</p>
                    <p class="text-xs text-yellow-400/70 mt-1">{{ factoryIssues.join(' · ') || t('page.tokenFactory.notReady.fallback') }}</p>
                </div>
            </div>
        </div>

        <!-- Success Alert -->
        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="showSuccess" class="max-w-4xl mx-auto px-4 sm:px-6 mb-6">
                <div class="p-4 rounded-xl bg-green-500/10 border border-green-500/20 text-green-400 flex items-center gap-3">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    {{ successMessage }}
                </div>
            </div>
        </Transition>

        <!-- Error Alert -->
        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0 -translate-y-2"
            enter-to-class="opacity-100 translate-y-0"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="showError" class="max-w-4xl mx-auto px-4 sm:px-6 mb-6">
                <div class="p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 flex items-center gap-3">
                    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    {{ errorMessage }}
                </div>
            </div>
        </Transition>

        <!-- Deploy-Ready Modal: token ที่เพิ่ง deploy สำเร็จ → ถามว่าจะเพิ่มเข้ากระเป๋าไหม -->
        <Transition
            enter-active-class="transition ease-out duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="deployReadyToken"
                class="fixed inset-0 z-50 flex items-center justify-center px-4 bg-black/60 backdrop-blur-sm"
                @click.self="dismissDeployReady"
            >
                <div class="glass-dark w-full max-w-md rounded-2xl p-6 border border-primary-500/30 shadow-2xl">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-green-500 to-accent-500 flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        </div>
                        <div>
                            <p class="text-lg font-semibold text-white">🎉 {{ t('page.tokenFactory.deployReady.title') }}</p>
                            <p class="text-xs text-gray-400">{{ t('page.tokenFactory.deployReady.subtitle') }}</p>
                        </div>
                    </div>

                    <div class="rounded-xl bg-dark-900/50 border border-white/10 p-4 mb-4 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-gray-400">{{ t('page.tokenFactory.fields.name') }}</span>
                            <span class="text-white font-medium">{{ deployReadyToken.name }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-400">{{ t('page.tokenFactory.fields.symbol') }}</span>
                            <span class="text-white font-mono">{{ deployReadyToken.symbol }}</span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-400">{{ t('page.tokenFactory.fields.contract') }}</span>
                            <a
                                :href="`https://explorer.tpix.online/address/${deployReadyToken.contract_address}`"
                                target="_blank"
                                rel="noopener"
                                class="text-primary-400 hover:text-primary-300 font-mono text-xs"
                            >
                                {{ deployReadyToken.contract_address?.slice(0, 8) }}...{{ deployReadyToken.contract_address?.slice(-6) }}
                            </a>
                        </div>
                    </div>

                    <p class="text-sm text-gray-300 mb-4">{{ t('page.tokenFactory.deployReady.askBefore') }} <span class="text-primary-400 font-semibold">{{ deployReadyToken.symbol }}</span> {{ t('page.tokenFactory.deployReady.askAfter') }}</p>

                    <div class="flex gap-2">
                        <button
                            @click="dismissDeployReady"
                            class="flex-1 px-4 py-2.5 rounded-lg bg-dark-800/50 hover:bg-dark-800 border border-white/10 text-gray-300 text-sm font-medium transition-all"
                        >
                            {{ t('page.tokenFactory.actions.later') }}
                        </button>
                        <button
                            @click="confirmDeployReady"
                            class="flex-[2] px-4 py-2.5 rounded-lg bg-gradient-to-r from-accent-500 to-primary-500 hover:opacity-90 text-white text-sm font-semibold transition-all flex items-center justify-center gap-2"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                            {{ t('page.tokenFactory.actions.addToWallet') }}
                        </button>
                    </div>
                </div>
            </div>
        </Transition>

        <!-- Tabs -->
        <section class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex gap-1 mb-8 bg-dark-900/50 rounded-xl p-1 max-w-md">
                <button
                    v-for="tab in [
                        { id: 'create', labelKey: 'page.tokenFactory.tabs.create' },
                        { id: 'my-tokens', labelKey: 'page.tokenFactory.tabs.myTokens' },
                        { id: 'explore', labelKey: 'page.tokenFactory.tabs.explore' },
                    ]"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-all"
                    :class="activeTab === tab.id ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white'"
                >
                    {{ t(tab.labelKey) }}
                </button>
            </div>

            <!-- ===================== CREATE TAB — WIZARD ===================== -->
            <div v-if="activeTab === 'create'" class="pb-16">

                <!-- Connect Wallet Gate -->
                <div v-if="!walletStore.isConnected" class="glass-dark p-12 rounded-2xl border border-white/10 text-center max-w-lg mx-auto">
                    <div class="w-16 h-16 rounded-2xl bg-accent-500/20 flex items-center justify-center mx-auto mb-4">
                        <svg class="w-8 h-8 text-accent-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                    </div>
                    <h3 class="text-lg font-bold text-white mb-2">{{ t('page.tokenFactory.connect.title') }}</h3>
                    <p class="text-gray-400 text-sm mb-6">{{ t('page.tokenFactory.connect.desc') }}</p>
                    <button @click="walletStore.openConnectModal()" class="btn-primary px-8 py-2.5">{{ t('page.tokenFactory.connect.button') }}</button>
                </div>

                <!-- Disabled Gate -->
                <div v-else-if="!factoryConfig.creation_enabled" class="glass-dark p-12 rounded-2xl border border-white/10 text-center max-w-lg mx-auto">
                    <p class="text-gray-400">{{ t('page.tokenFactory.disabled') }}</p>
                </div>

                <!-- Wizard -->
                <div v-else class="max-w-2xl mx-auto">
                    <!-- ค่าธรรมเนียมที่ชำระแล้วแต่ยังส่งคำขอไม่สำเร็จ — ส่งต่อได้โดยไม่ต้องจ่ายซ้ำ -->
                    <div v-if="pendingPayment" class="mb-4 p-4 rounded-xl bg-amber-500/10 border border-amber-500/30" data-test="pending-payment">
                        <p class="text-sm font-semibold text-amber-300">{{ t('page.tokenFactory.pending.title', { symbol: pendingPayment.payload?.symbol }) }}</p>
                        <p class="text-[11px] text-amber-200/80 mt-1 break-all">tx: {{ pendingPayment.feeTxHash }}</p>
                        <div class="flex flex-wrap gap-2 mt-3">
                            <button type="button" class="px-4 py-2 rounded-lg bg-amber-500/20 text-amber-200 text-xs font-semibold hover:bg-amber-500/30 disabled:opacity-40" :disabled="isSubmitting" @click="resumePendingPayment">
                                {{ t('page.tokenFactory.pending.resume') }}
                            </button>
                            <button type="button" class="px-4 py-2 rounded-lg text-xs text-amber-300/70 hover:text-amber-200" :disabled="isSubmitting" @click="discardPendingPayment">
                                {{ t('page.tokenFactory.pending.discard') }}
                            </button>
                        </div>
                    </div>

                    <!-- คำขอที่ยังไม่จบ — กระเป๋าหนึ่งมีคำขอค้างได้ทีละคำขอ (เซิร์ฟเวอร์บังคับซ้ำตอนออกใบเสนอราคา) -->
                    <div v-if="openRequest && !pendingPayment" class="mb-4 p-4 rounded-xl border" :class="openRequest.status === 'failed' ? 'bg-red-500/10 border-red-500/30' : 'bg-blue-500/10 border-blue-500/30'" data-test="request-in-progress">
                        <p class="text-sm font-semibold" :class="openRequest.status === 'failed' ? 'text-red-300' : 'text-blue-200'">
                            {{ t('page.tokenFactory.openRequest.title', { symbol: openRequest.symbol }) }}
                        </p>
                        <p class="text-xs mt-1" :class="openRequest.status === 'failed' ? 'text-red-200/90' : 'text-blue-100/90'">
                            {{ t('page.tokenFactory.openRequest.stage', { stage: t(IN_PROGRESS_STAGE[openRequest.status]) }) }}
                        </p>
                        <p class="text-[11px] text-gray-400 mt-1">{{ t('page.tokenFactory.openRequest.hint') }}</p>
                        <button type="button" class="mt-3 px-4 py-2 rounded-lg bg-white/5 text-xs font-semibold text-white hover:bg-white/10" @click="activeTab = 'my-tokens'">
                            {{ t('page.tokenFactory.openRequest.viewStatus') }}
                        </button>
                    </div>

                    <template v-if="!openRequest || pendingPayment">
                    <!-- Step Indicator -->
                    <div class="flex items-center justify-between mb-8 px-2">
                        <button
                            v-for="step in [
                                { n: 1, labelKey: 'page.tokenFactory.steps.category' },
                                { n: 2, labelKey: 'page.tokenFactory.steps.type' },
                                { n: 3, labelKey: 'page.tokenFactory.steps.options' },
                                { n: 4, labelKey: 'page.tokenFactory.steps.details' },
                                { n: 5, labelKey: 'page.tokenFactory.steps.review' },
                            ]"
                            :key="step.n"
                            @click="goToStep(step.n)"
                            class="flex items-center gap-2 group"
                            :class="step.n < wizardStep ? 'cursor-pointer' : 'cursor-default'"
                        >
                            <div
                                class="w-9 h-9 rounded-full flex items-center justify-center text-sm font-bold transition-all"
                                :class="wizardStep === step.n
                                    ? 'bg-primary-500 text-white ring-2 ring-primary-500/30'
                                    : step.n < wizardStep
                                        ? 'bg-green-500/20 text-green-400 border border-green-500/30'
                                        : 'bg-white/5 text-gray-500 border border-white/10'"
                            >
                                <svg v-if="step.n < wizardStep" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
                                </svg>
                                <span v-else>{{ step.n }}</span>
                            </div>
                            <span class="hidden sm:inline text-xs font-medium" :class="wizardStep === step.n ? 'text-white' : 'text-gray-500'">{{ t(step.labelKey) }}</span>
                        </button>

                        <!-- Progress bar between steps -->
                        <div class="absolute left-0 right-0 top-[18px] h-0.5 bg-white/5 -z-10 mx-20 hidden sm:block">
                            <div class="h-full bg-primary-500 transition-all duration-300" :style="{ width: ((wizardStep - 1) / (totalSteps - 1)) * 100 + '%' }"></div>
                        </div>
                    </div>

                    <!-- Error -->
                    <div v-if="formErrors.general" class="p-3 rounded-xl bg-red-500/10 border border-red-500/20 text-red-400 text-sm mb-6">
                        {{ formErrors.general }}
                    </div>

                    <!-- ===== STEP 1: Choose Category ===== -->
                    <Transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="opacity-0 translate-x-4"
                        enter-to-class="opacity-100 translate-x-0"
                        leave-active-class="transition ease-in duration-150"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div v-if="wizardStep === 1" class="space-y-4">
                            <div class="text-center mb-6">
                                <h2 class="text-xl font-bold text-white">{{ t('page.tokenFactory.step1.title') }}</h2>
                                <p class="text-sm text-gray-400 mt-1">{{ t('page.tokenFactory.step1.subtitle') }}</p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <button
                                    v-for="cat in visibleCategories"
                                    :key="cat.id"
                                    type="button"
                                    @click="!cat.disabled && selectCategory(cat.id)"
                                    class="relative p-6 rounded-2xl border text-left transition-all group"
                                    :class="cat.disabled
                                        ? 'border-white/5 opacity-40 cursor-not-allowed'
                                        : form.token_category === cat.id
                                            ? 'border-primary-500/50 bg-primary-500/10 ring-1 ring-primary-500/20'
                                            : 'border-white/10 hover:border-white/20 hover:bg-white/5'"
                                >
                                    <div class="w-12 h-12 rounded-xl flex items-center justify-center mb-4"
                                        :class="`bg-gradient-to-br ${cat.color} bg-opacity-20`"
                                        :style="{ opacity: cat.disabled ? 0.3 : 1 }"
                                    >
                                        <!-- Coin icon -->
                                        <svg v-if="cat.icon === 'coin'" class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        <!-- NFT icon -->
                                        <svg v-else-if="cat.icon === 'nft'" class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <!-- Star icon -->
                                        <svg v-else class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z" />
                                        </svg>
                                    </div>

                                    <h3 class="font-semibold text-white mb-1">{{ categoryLabel(cat.id) }}</h3>
                                    <p class="text-xs text-gray-400 leading-relaxed">{{ categoryDesc(cat.id) }}</p>

                                    <!-- Selected indicator -->
                                    <div v-if="form.token_category === cat.id && !cat.disabled" class="absolute top-3 right-3">
                                        <svg class="w-5 h-5 text-primary-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                    </div>

                                    <span v-if="cat.disabled" class="absolute top-3 right-3 text-xs text-gray-500 bg-white/5 px-2 py-0.5 rounded">{{ t('page.tokenFactory.notOpenYet') }}</span>
                                </button>
                            </div>
                        </div>
                    </Transition>

                    <!-- ===== STEP 2: Choose Token Type ===== -->
                    <Transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="opacity-0 translate-x-4"
                        enter-to-class="opacity-100 translate-x-0"
                        leave-active-class="transition ease-in duration-150"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div v-if="wizardStep === 2" class="space-y-4">
                            <div class="text-center mb-6">
                                <h2 class="text-xl font-bold text-white">{{ t('page.tokenFactory.step2.title') }}</h2>
                                <p class="text-sm text-gray-400 mt-1">{{ t('page.tokenFactory.step2.subtitle', { category: getCategoryLabel(form.token_category) }) }}</p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <button
                                    v-for="tt in currentTypes"
                                    :key="tt.value"
                                    type="button"
                                    @click="form.token_type = tt.value"
                                    class="relative p-5 rounded-xl border text-left transition-all"
                                    :class="form.token_type === tt.value
                                        ? 'border-primary-500/50 bg-primary-500/10 ring-1 ring-primary-500/20'
                                        : 'border-white/10 hover:border-white/20 hover:bg-white/5'"
                                >
                                    <div class="flex items-start gap-3">
                                        <span class="text-2xl">{{ tt.icon }}</span>
                                        <div class="flex-1">
                                            <div class="flex items-center gap-2">
                                                <h3 class="font-semibold" :class="form.token_type === tt.value ? 'text-primary-400' : 'text-white'">{{ typeLabel(tt.value) }}</h3>
                                                <span v-if="tt.recommended" class="text-[10px] px-1.5 py-0.5 rounded-full bg-green-500/20 text-green-400 font-medium">{{ t('page.tokenFactory.recommended') }}</span>
                                            </div>
                                            <p class="text-xs text-gray-400 mt-0.5">{{ typeDesc(tt.value) }}</p>
                                        </div>
                                    </div>

                                    <div v-if="form.token_type === tt.value" class="absolute top-3 right-3">
                                        <svg class="w-5 h-5 text-primary-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </button>
                            </div>

                            <p v-if="currentTypes.length === 0" class="text-center text-sm text-gray-400">{{ t('page.tokenFactory.step2.empty') }}</p>

                            <!-- ประเภทที่ให้อำนาจเจ้าของเหนือผู้ถือ — ต้องติ๊กยอมรับก่อนไปต่อ -->
                            <RiskAckBox
                                v-if="typeAck"
                                v-model="riskAck[typeAck.key]"
                                :ack-key="typeAck.key"
                                :title="typeAck.label"
                                :warning="typeAck.warning"
                            />
                        </div>
                    </Transition>

                    <!-- ===== STEP 3: Advanced Options (Sub-Options) ===== -->
                    <Transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="opacity-0 translate-x-4"
                        enter-to-class="opacity-100 translate-x-0"
                        leave-active-class="transition ease-in duration-150"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div v-if="wizardStep === 3" class="space-y-4">
                            <div class="text-center mb-6">
                                <h2 class="text-xl font-bold text-white">{{ t('page.tokenFactory.step3.title') }}</h2>
                                <p class="text-sm text-gray-400 mt-1">{{ t('page.tokenFactory.step3.subtitle') }}</p>
                            </div>

                            <!-- No options message for standard -->
                            <div v-if="currentSubOptionsGroups.length === 0" class="glass-dark p-8 rounded-2xl border border-white/10 text-center">
                                <p class="text-gray-400 text-sm">{{ t('page.tokenFactory.step3.empty') }}</p>
                            </div>

                            <!-- Option Groups -->
                            <div v-for="group in currentSubOptionsGroups" :key="group.group" class="glass-dark rounded-2xl border border-white/10 overflow-hidden">
                                <!-- Group Header -->
                                <div class="p-4 border-b border-white/5 flex items-center gap-3">
                                    <span class="text-xl">{{ group.icon }}</span>
                                    <div class="flex-1">
                                        <h3 class="text-sm font-semibold text-white">{{ groupLabel(group.group) }}</h3>
                                    </div>
                                    <div v-if="group.fee > 0" class="flex items-center gap-1 px-2 py-0.5 rounded-lg bg-amber-500/10 text-amber-400 text-[10px] font-medium">
                                        +{{ group.fee }} TPIX
                                    </div>
                                </div>

                                <!-- Options -->
                                <div class="p-4 space-y-4">
                                    <div v-for="opt in group.options" :key="opt.key" v-show="shouldShowOption(opt)">

                                        <!-- Toggle -->
                                        <div v-if="opt.type === 'toggle'" class="flex items-center justify-between py-2">
                                            <div class="flex-1 mr-4">
                                                <div class="flex items-center gap-2">
                                                    <p class="text-sm font-medium text-white">{{ optLabel(opt) }}</p>
                                                    <span v-if="uiOptionFee(opt) > 0" :class="['px-1.5 py-0.5 rounded text-[9px] font-medium', subOptions[opt.key] ? 'bg-amber-500/10 text-amber-400' : 'bg-white/5 text-gray-500']" :data-test="`opt-fee-${opt.key}`">+{{ uiOptionFee(opt).toLocaleString() }} TPIX</span>
                                                </div>
                                                <p class="text-xs text-gray-400 mt-0.5">{{ optDesc(opt) }}</p>
                                            </div>
                                            <button type="button" @click="subOptions[opt.key] = !subOptions[opt.key]"
                                                :data-test="`opt-${opt.key}`"
                                                :class="['w-11 h-6 rounded-full transition-colors flex-shrink-0', subOptions[opt.key] ? 'bg-primary-500' : 'bg-white/10']">
                                                <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', subOptions[opt.key] ? 'translate-x-5' : 'translate-x-0.5']"></div>
                                            </button>
                                        </div>
                                        <RiskAckBox
                                            v-if="ackForOption(opt)"
                                            v-model="riskAck[ackForOption(opt).key]"
                                            :ack-key="ackForOption(opt).key"
                                            :title="ackForOption(opt).label"
                                            :warning="ackForOption(opt).warning"
                                        />

                                        <!-- Range / Slider -->
                                        <div v-else-if="opt.type === 'range'" class="py-2">
                                            <div class="flex items-center justify-between mb-1.5">
                                                <div class="flex items-center gap-2">
                                                    <p class="text-sm font-medium text-white">{{ optLabel(opt) }}</p>
                                                </div>
                                                <span class="text-sm font-bold text-primary-400">{{ subOptions[opt.key] }}%</span>
                                            </div>
                                            <input type="range" v-model.number="subOptions[opt.key]" :min="opt.min" :max="opt.max" :step="opt.step"
                                                class="w-full h-1.5 rounded-full appearance-none bg-white/10 accent-primary-500 cursor-pointer" />
                                            <div class="flex justify-between text-[10px] text-gray-500 mt-0.5">
                                                <span>{{ opt.min }}%</span>
                                                <span>{{ opt.max }}%</span>
                                            </div>
                                        </div>

                                        <!-- Number -->
                                        <div v-else-if="opt.type === 'number'" class="py-2">
                                            <div class="flex items-center gap-2 mb-1.5">
                                                <p class="text-sm font-medium text-white">{{ optLabel(opt) }}</p>
                                            </div>
                                            <!-- จำนวนเงิน/เหรียญเก็บเป็นข้อความ — Number ทำ 0.0000001 เป็น 1e-7 และเลขใหญ่เพี้ยน -->
                                            <input v-if="UNIT_OPTION_KEYS.includes(opt.key)" type="text" inputmode="decimal" v-model.trim="subOptions[opt.key]"
                                                :placeholder="optDesc(opt)" class="trading-input w-full max-w-xs" :data-test="`unit-${opt.key}`" />
                                            <input v-else type="number" v-model.number="subOptions[opt.key]" :min="opt.min" :max="opt.max"
                                                :placeholder="optDesc(opt)" class="trading-input w-full max-w-xs" />
                                            <p class="text-[10px] text-gray-500 mt-0.5">{{ optDesc(opt) }}</p>
                                        </div>

                                        <!-- Select -->
                                        <div v-else-if="opt.type === 'select'" class="py-2">
                                            <div class="flex items-center gap-2 mb-1.5">
                                                <p class="text-sm font-medium text-white">{{ optLabel(opt) }}</p>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <button v-for="choice in opt.choices" :key="choice.value" type="button"
                                                    @click="subOptions[opt.key] = choice.value"
                                                    class="px-3 py-2 rounded-lg border text-xs font-medium transition-all"
                                                    :class="subOptions[opt.key] === choice.value
                                                        ? 'bg-primary-500/15 border-primary-500/40 text-primary-400'
                                                        : 'bg-white/5 border-white/10 text-gray-400 hover:border-white/20'">
                                                    <span>{{ choiceLabel(opt, choice) }}</span>
                                                </button>
                                            </div>
                                            <p v-if="optDesc(opt)" class="text-[10px] text-gray-500 mt-1">{{ optDesc(opt) }}</p>
                                        </div>

                                        <!-- Address -->
                                        <div v-else-if="opt.type === 'address'" class="py-2">
                                            <div class="flex items-center gap-2 mb-1.5">
                                                <p class="text-sm font-medium text-white">{{ optLabel(opt) }}</p>
                                            </div>
                                            <input type="text" v-model="subOptions[opt.key]" placeholder="0x..." class="trading-input w-full font-mono text-xs" />
                                            <p class="text-[10px] text-gray-500 mt-0.5">{{ optDesc(opt) }}</p>
                                        </div>

                                        <!-- Text -->
                                        <div v-else-if="opt.type === 'text'" class="py-2">
                                            <div class="flex items-center gap-2 mb-1.5">
                                                <p class="text-sm font-medium text-white">{{ optLabel(opt) }}</p>
                                            </div>
                                            <input type="text" v-model="subOptions[opt.key]" :placeholder="optDesc(opt)" class="trading-input w-full text-xs" />
                                            <p class="text-[10px] text-gray-500 mt-0.5">{{ optDesc(opt) }}</p>
                                        </div>

                                    </div>
                                </div>
                            </div>

                            <!-- Fee Preview -->
                            <div v-if="!dynamicFee.is_free" class="p-3 rounded-xl bg-amber-500/5 border border-amber-500/10 flex items-center justify-between text-sm">
                                <span class="text-gray-400">{{ t('page.tokenFactory.step3.feeTotal') }}</span>
                                <span class="text-amber-400 font-bold">{{ dynamicFee.total.toLocaleString() }} TPIX</span>
                            </div>
                        </div>
                    </Transition>

                    <!-- ===== STEP 4: Token Details ===== -->
                    <Transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="opacity-0 translate-x-4"
                        enter-to-class="opacity-100 translate-x-0"
                        leave-active-class="transition ease-in duration-150"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div v-if="wizardStep === 4" class="space-y-5">
                            <div class="text-center mb-6">
                                <h2 class="text-xl font-bold text-white">{{ t('page.tokenFactory.step4.title') }}</h2>
                                <p class="text-sm text-gray-400 mt-1">{{ t('page.tokenFactory.step4.subtitle') }}</p>
                            </div>

                            <div class="glass-dark p-6 rounded-2xl border border-white/10 space-y-5">
                                <!-- Name & Symbol -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1.5">
                                            {{ isNFT ? t('page.tokenFactory.fields.collectionName') : t('page.tokenFactory.fields.tokenName') }} <span class="text-red-400">*</span>
                                        </label>
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            :placeholder="isNFT ? t('page.tokenFactory.placeholders.collectionName') : t('page.tokenFactory.placeholders.tokenName')"
                                            class="trading-input w-full"
                                            :maxlength="limits.name_max_length"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1.5">
                                            {{ t('page.tokenFactory.fields.symbol') }} <span class="text-red-400">*</span>
                                        </label>
                                        <input
                                            :value="form.symbol"
                                            type="text"
                                            :placeholder="isNFT ? t('page.tokenFactory.placeholders.collectionSymbol') : t('page.tokenFactory.placeholders.tokenSymbol')"
                                            class="trading-input w-full uppercase"
                                            :maxlength="limits.symbol_max_length"
                                            data-test="symbol-input"
                                            @input="onSymbolInput"
                                        />
                                        <p class="text-[10px] text-gray-500 mt-1">{{ t('page.tokenFactory.step4.symbolHint') }}</p>
                                        <p v-if="symbolIsReserved" class="text-[11px] text-red-400 mt-1">{{ t('page.tokenFactory.step4.symbolReserved') }}</p>
                                    </div>
                                </div>

                                <!-- Total Supply with presets -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1.5">
                                        {{ isNFT ? t('page.tokenFactory.fields.maxSupply') : t('page.tokenFactory.fields.totalSupply') }} <span class="text-red-400">*</span>
                                    </label>
                                    <div class="flex gap-2 mb-2">
                                        <button
                                            v-for="preset in supplyPresets"
                                            :key="preset.value"
                                            type="button"
                                            @click="form.total_supply = preset.value"
                                            class="px-3 py-1.5 rounded-lg text-xs font-medium transition-all"
                                            :class="form.total_supply === preset.value
                                                ? 'bg-primary-500/20 text-primary-400 border border-primary-500/30'
                                                : 'bg-white/5 text-gray-400 border border-white/10 hover:border-white/20'"
                                        >
                                            {{ preset.label }}
                                            <span class="text-gray-500 ml-0.5">{{ preset.desc }}</span>
                                        </button>
                                    </div>
                                    <input
                                        v-model="form.total_supply"
                                        type="number"
                                        :placeholder="isNFT ? t('page.tokenFactory.placeholders.nftSupply') : t('page.tokenFactory.placeholders.tokenSupply')"
                                        :min="1"
                                        :max="maxSupplyForType"
                                        step="1"
                                        class="trading-input w-full"
                                    />
                                    <p class="text-[10px] text-gray-500 mt-1">{{ t('page.tokenFactory.step4.supplyHint', { max: Number(maxSupplyForType).toLocaleString() }) }}</p>
                                </div>

                                <!-- Decimals (hidden for NFT) -->
                                <div v-if="!isNFT" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1.5">{{ t('page.tokenFactory.fields.decimals') }}</label>
                                        <select v-if="decimalChoices.length > 1" v-model.number="form.decimals" class="trading-input w-full">
                                            <option v-for="d in decimalChoices" :key="d" :value="d">{{ d }}{{ d === 18 ? ` (${t('page.tokenFactory.step4.decimalsStandard')})` : '' }}</option>
                                        </select>
                                        <p v-else class="trading-input w-full text-gray-300">{{ decimalChoices[0] ?? 18 }}</p>
                                    </div>
                                    <div class="flex items-end">
                                        <p class="text-xs text-gray-500 pb-3">
                                            {{ t('page.tokenFactory.step4.decimalsHint') }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Description (optional) -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1.5">{{ t('page.tokenFactory.fields.description') }} <span class="text-gray-500">({{ t('page.tokenFactory.optional') }})</span></label>
                                    <textarea
                                        v-model="form.description"
                                        rows="2"
                                        :placeholder="t('page.tokenFactory.placeholders.description')"
                                        class="trading-input w-full resize-none"
                                        maxlength="1000"
                                    ></textarea>
                                </div>

                                <!-- Website (optional) -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1.5">{{ t('page.tokenFactory.fields.website') }} <span class="text-gray-500">({{ t('page.tokenFactory.optional') }})</span></label>
                                    <input v-model="form.website" type="url" placeholder="https://..." class="trading-input w-full" />
                                </div>

                                <!-- Logo Upload (optional) -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1.5">{{ t('page.tokenFactory.fields.logo') }} <span class="text-gray-500">({{ t('page.tokenFactory.optional') }})</span></label>
                                    <div v-if="logoPreview" class="flex items-center gap-4 mb-3">
                                        <img :src="logoPreview" :alt="t('page.tokenFactory.alt.logoPreview')" class="w-16 h-16 rounded-xl object-cover border border-white/10" />
                                        <div class="flex-1">
                                            <p class="text-sm text-white">{{ logoFile?.name }}</p>
                                            <p class="text-xs text-gray-400">{{ logoFile ? (logoFile.size / 1024).toFixed(1) + ' KB' : '' }}</p>
                                            <div v-if="isUploadingLogo" class="mt-1 flex items-center gap-2">
                                                <svg class="w-3.5 h-3.5 animate-spin text-primary-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                                <span class="text-xs text-primary-400">{{ t('page.tokenFactory.step4.uploading') }}</span>
                                            </div>
                                            <p v-else-if="form.logo_url" class="text-xs text-green-400 mt-1">✓ {{ t('page.tokenFactory.step4.uploaded') }}</p>
                                        </div>
                                        <button type="button" @click="removeLogo" class="text-gray-400 hover:text-red-400 transition-colors p-1">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                    <label v-else class="flex flex-col items-center justify-center p-6 rounded-xl border-2 border-dashed border-white/10 hover:border-primary-500/30 hover:bg-primary-500/5 transition-all cursor-pointer group">
                                        <svg class="w-8 h-8 text-gray-500 group-hover:text-primary-400 transition-colors mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        <span class="text-xs text-gray-400 group-hover:text-gray-300">{{ t('page.tokenFactory.step4.clickUpload') }}</span>
                                        <span class="text-[10px] text-gray-500 mt-0.5">{{ t('page.tokenFactory.step4.logoHint') }}</span>
                                        <input type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="handleLogoSelect" />
                                    </label>
                                    <p v-if="formErrors.logo" class="text-xs text-red-400 mt-1">{{ formErrors.logo }}</p>
                                </div>
                            </div>
                        </div>
                    </Transition>

                    <!-- ===== STEP 5: Review & Confirm ===== -->
                    <Transition
                        enter-active-class="transition ease-out duration-200"
                        enter-from-class="opacity-0 translate-x-4"
                        enter-to-class="opacity-100 translate-x-0"
                        leave-active-class="transition ease-in duration-150"
                        leave-from-class="opacity-100"
                        leave-to-class="opacity-0"
                    >
                        <div v-if="wizardStep === 5" class="space-y-5">
                            <div class="text-center mb-6">
                                <h2 class="text-xl font-bold text-white">{{ t('page.tokenFactory.step5.title') }}</h2>
                                <p class="text-sm text-gray-400 mt-1">{{ t('page.tokenFactory.step5.subtitle') }}</p>
                            </div>

                            <div class="glass-dark p-6 rounded-2xl border border-white/10">
                                <!-- Token Preview Card -->
                                <div class="flex items-center gap-4 mb-6 pb-6 border-b border-white/10">
                                    <div v-if="logoPreview" class="w-14 h-14 rounded-2xl overflow-hidden border border-white/10">
                                        <img :src="logoPreview" :alt="t('page.tokenFactory.alt.tokenLogo')" class="w-full h-full object-cover" />
                                    </div>
                                    <div v-else class="w-14 h-14 rounded-2xl bg-gradient-to-br from-accent-500 to-primary-500 flex items-center justify-center">
                                        <span class="text-xl font-bold text-white">{{ form.symbol?.charAt(0)?.toUpperCase() || '?' }}</span>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-white">{{ form.name || t('page.tokenFactory.fields.tokenName') }}</h3>
                                        <p class="text-sm text-gray-400">
                                            {{ t('page.tokenFactory.step5.symbolOnChain', { symbol: form.symbol?.toUpperCase() || 'SYMBOL', chain: selectedChainName }) }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Details Grid -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                                    <div class="space-y-3">
                                        <div>
                                            <p class="text-gray-500 text-xs">{{ t('page.tokenFactory.fields.category') }}</p>
                                            <p class="text-white font-medium">{{ getCategoryLabel(form.token_category) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">{{ t('page.tokenFactory.fields.type') }}</p>
                                            <p class="text-white font-medium">{{ getTypeLabel(form.token_type) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">{{ isNFT ? t('page.tokenFactory.fields.maxSupply') : t('page.tokenFactory.fields.totalSupply') }}</p>
                                            <p class="text-white font-medium font-mono">{{ formatSupply(form.total_supply) }}</p>
                                        </div>
                                    </div>
                                    <div class="space-y-3">
                                        <div>
                                            <p class="text-gray-500 text-xs">{{ t('page.tokenFactory.fields.decimals') }}</p>
                                            <p class="text-white font-medium">{{ form.decimals }}</p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">{{ t('page.tokenFactory.fields.chain') }}</p>
                                            <p class="text-white font-medium">
                                                {{ selectedChainName }} ({{ selectedChainId }})
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">{{ t('page.tokenFactory.fields.creator') }}</p>
                                            <p class="text-primary-400 font-mono text-xs">{{ walletStore.address?.slice(0, 8) }}...{{ walletStore.address?.slice(-4) }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="form.description" class="mt-4 pt-4 border-t border-white/10">
                                    <p class="text-gray-500 text-xs mb-1">{{ t('page.tokenFactory.fields.description') }}</p>
                                    <p class="text-sm text-gray-300">{{ form.description }}</p>
                                </div>
                                <div v-if="form.website" class="mt-3">
                                    <p class="text-gray-500 text-xs mb-1">{{ t('page.tokenFactory.fields.website') }}</p>
                                    <p class="text-sm text-primary-400">{{ form.website }}</p>
                                </div>
                                <div v-if="form.logo_url" class="mt-3">
                                    <p class="text-gray-500 text-xs mb-1">{{ t('page.tokenFactory.fields.logoShort') }}</p>
                                    <p class="text-sm text-green-400">✓ {{ t('page.tokenFactory.step4.uploaded') }}</p>
                                </div>
                            </div>

                            <!-- Sub-Options Review -->
                            <div v-if="hasActiveSubOptions" class="glass-dark p-5 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white mb-3">{{ t('page.tokenFactory.step3.title') }}</p>
                                <div v-for="grp in selectedSubOptionsSummary" :key="grp.group" class="mb-3 last:mb-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-sm">{{ grp.icon }}</span>
                                        <span class="text-xs font-medium text-gray-300">{{ groupLabel(grp.group) }}</span>
                                    </div>
                                    <div class="pl-6 space-y-1">
                                        <div v-for="opt in grp.options" :key="opt.key" class="flex items-center justify-between text-xs">
                                            <div class="flex items-center gap-2">
                                                <span class="text-gray-400">{{ optLabel(opt) }}</span>
                                            </div>
                                            <span class="text-white font-mono">{{ opt.displayValue }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Dynamic Fee Breakdown -->
                            <div class="glass-dark p-5 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white mb-3">{{ t('page.tokenFactory.step5.creationFee') }}</p>

                                <!-- Fee breakdown items -->
                                <div v-if="dynamicFee.breakdown.length > 0" class="space-y-2 mb-3">
                                    <div
                                        v-for="(item, idx) in dynamicFee.breakdown"
                                        :key="idx"
                                        class="flex items-center justify-between text-sm"
                                    >
                                        <span class="text-gray-400">{{ item.label }}</span>
                                        <span class="text-white font-mono">+{{ item.amount }} TPIX</span>
                                    </div>
                                </div>

                                <!-- Total -->
                                <div class="flex items-center justify-between pt-3 border-t border-white/10">
                                    <span class="text-sm font-medium text-gray-300">{{ t('page.tokenFactory.step5.total') }}</span>
                                    <span class="text-xl font-bold" :class="dynamicFee.is_free ? 'text-green-400' : 'text-white'" data-test="fee-total">
                                        {{ dynamicFee.is_free ? t('page.tokenFactory.free') : `${dynamicFee.total.toLocaleString()} TPIX` }}
                                    </span>
                                </div>

                                <p class="text-xs text-gray-500 mt-2">
                                    {{ t('page.tokenFactory.step5.feeHint') }}
                                </p>
                            </div>

                            <!-- ความเสี่ยงที่ยอมรับแล้ว — ยังขาดรายการไหนให้กลับไปติ๊ก -->
                            <div v-if="requiredAcks.length" class="p-4 rounded-xl border" :class="missingAcks.length ? 'bg-red-500/10 border-red-500/40' : 'bg-red-500/5 border-red-500/20'" data-test="risk-summary">
                                <p class="text-sm font-semibold text-red-400">{{ t('page.tokenFactory.step5.riskTitle') }}</p>
                                <ul class="mt-2 space-y-1 text-xs text-red-300">
                                    <li v-for="ack in requiredAcks" :key="ack.key">
                                        {{ riskAck[ack.key] ? '✓' : '✗' }} {{ ack.label }} — {{ ack.warning }}
                                    </li>
                                </ul>
                                <p v-if="missingAcks.length" class="mt-2 text-xs text-red-400">{{ t('page.tokenFactory.step5.riskMissing', { n: missingAcks.length }) }}</p>
                            </div>

                            <!-- สถานะการชำระค่าธรรมเนียม -->
                            <div v-if="paymentStageText" class="p-4 rounded-xl bg-primary-500/10 border border-primary-500/30 text-sm text-primary-200 flex items-center gap-3" data-test="payment-status">
                                <svg class="w-4 h-4 animate-spin flex-shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <div>
                                    <p>{{ paymentStageText }}</p>
                                    <p v-if="payment.txHash" class="text-[10px] text-primary-300/70 break-all mt-0.5">tx: {{ payment.txHash }}</p>
                                </div>
                            </div>

                            <!-- Process Info -->
                            <div class="p-4 rounded-xl bg-blue-500/5 border border-blue-500/10 text-xs space-y-1">
                                <p class="text-blue-300/80">{{ t('page.tokenFactory.step5.processPay', { amount: `${feeDisplay.amount} ${feeDisplay.currency}`.trim(), chain: selectedChainName }) }}</p>
                                <p class="text-blue-300/50 text-[10px]">{{ t('page.tokenFactory.step5.processReview', { chain: selectedChainName }) }}</p>
                            </div>
                        </div>
                    </Transition>

                    <!-- ===== WIZARD NAVIGATION BUTTONS ===== -->
                    <div class="flex items-center justify-between mt-8 pt-6 border-t border-white/5">
                        <button
                            v-if="wizardStep > 1"
                            @click="prevStep"
                            class="flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-medium text-gray-400 hover:text-white bg-white/5 hover:bg-white/10 transition-all"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                            {{ t('common.back') }}
                        </button>
                        <div v-else></div>

                        <!-- Next / Submit -->
                        <button
                            v-if="wizardStep < totalSteps"
                            @click="nextStep"
                            :disabled="!stepValid"
                            class="flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-semibold btn-primary disabled:opacity-40 disabled:cursor-not-allowed transition-all"
                        >
                            {{ t('common.next') }}
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                        <button
                            v-else
                            @click="handleCreate"
                            :disabled="!canCreate || isSubmitting"
                            class="flex items-center gap-2 px-8 py-2.5 rounded-xl text-sm font-semibold bg-gradient-to-r from-primary-500 to-accent-500 text-white hover:brightness-110 disabled:opacity-40 disabled:cursor-not-allowed transition-all"
                        >
                            <svg v-if="isSubmitting" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <span v-if="isSubmitting">{{ t('page.tokenFactory.actions.processing') }}</span>
                            <span v-else>{{ dynamicFee.is_free ? t('page.tokenFactory.actions.create') : t('page.tokenFactory.actions.payAndCreate') }}</span>
                        </button>
                    </div>
                    </template>
                </div>
            </div>

            <!-- ===================== MY TOKENS TAB ===================== -->
            <div v-if="activeTab === 'my-tokens'" class="pb-16">
                <div v-if="!walletStore.isConnected" class="text-center py-16">
                    <p class="text-gray-400 mb-4">{{ t('page.tokenFactory.myTokens.connect') }}</p>
                    <button @click="walletStore.openConnectModal()" class="btn-primary px-6 py-2.5">{{ t('page.tokenFactory.connect.button') }}</button>
                </div>

                <div v-else-if="factoryStore.myTokens.length === 0" class="text-center py-16">
                    <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                    <p class="text-gray-400 mb-4">{{ t('page.tokenFactory.myTokens.empty') }}</p>
                    <button @click="activeTab = 'create'" class="btn-primary px-6">{{ t('page.tokenFactory.myTokens.createFirst') }}</button>
                </div>

                <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div
                        v-for="token in factoryStore.myTokens"
                        :key="token.id"
                        class="glass-dark p-5 rounded-xl border border-white/10 hover:border-white/20 transition-all"
                    >
                        <div class="flex items-center justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-accent-500 to-primary-500 flex items-center justify-center">
                                    <span class="text-sm font-bold text-white">{{ token.symbol?.charAt(0) }}</span>
                                </div>
                                <div>
                                    <p class="font-semibold text-white">{{ token.name }}</p>
                                    <p class="text-xs text-gray-400">{{ token.symbol }}</p>
                                </div>
                            </div>
                            <span :class="['text-xs px-2 py-1 rounded-full border font-medium', getStatusColor(token.status)]">
                                {{ statusLabel(token.status) }}
                            </span>
                        </div>

                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-400">{{ t('page.tokenFactory.fields.supply') }}</span>
                                <span class="text-white font-mono">{{ formatSupply(token.total_supply) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">{{ t('page.tokenFactory.fields.type') }}</span>
                                <span class="text-white">{{ getTypeLabel(token.token_type) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">{{ t('page.tokenFactory.fields.decimals') }}</span>
                                <span class="text-white">{{ token.decimals }}</span>
                            </div>
                            <div v-if="token.contract_address" class="flex justify-between">
                                <span class="text-gray-400">{{ t('page.tokenFactory.fields.contract') }}</span>
                                <a
                                    :href="`https://explorer.tpix.online/address/${token.contract_address}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-primary-400 hover:text-primary-300 font-mono text-xs"
                                >
                                    {{ token.contract_address?.slice(0, 8) }}...{{ token.contract_address?.slice(-6) }}
                                </a>
                            </div>
                        </div>

                        <div v-if="token.reject_reason" class="mt-3 p-2 rounded-lg bg-red-500/10 text-xs text-red-400">
                            {{ t('page.tokenFactory.myTokens.reason', { reason: token.reject_reason }) }}
                        </div>

                        <div v-if="token.status === 'rejected' && REFUND_TEXT[token.refund_status]" :class="['mt-2 p-2 rounded-lg text-xs', REFUND_TEXT[token.refund_status].cls]" data-test="refund-note">
                            {{ t(REFUND_TEXT[token.refund_status].text) }}
                            <span v-if="weiToTpix(token.refund_amount_wei)"> ({{ weiToTpix(token.refund_amount_wei) }} TPIX)</span>
                            <a v-if="token.refund_tx_hash" :href="`https://explorer.tpix.online/tx/${token.refund_tx_hash}`" target="_blank" rel="noopener" class="block mt-1 font-mono underline">
                                tx {{ token.refund_tx_hash.slice(0, 10) }}...{{ token.refund_tx_hash.slice(-6) }}
                            </a>
                        </div>

                        <!-- ยืนยันซอร์สโค้ดบน explorer (บริการเสียเงิน) -->
                        <div v-if="token.contract_address && token.status === 'deployed'" class="mt-3 text-xs" data-test="source-verify">
                            <p v-if="VERIFY_STATE_TEXT[explorerVerifyState(token)]" :class="VERIFY_STATE_TEXT[explorerVerifyState(token)].cls">
                                {{ t(VERIFY_STATE_TEXT[explorerVerifyState(token)].text) }}
                            </p>
                            <template v-else-if="sourceVerifyOn || verifyPending[token.id]">
                                <button
                                    type="button"
                                    @click="buySourceVerify(token)"
                                    :disabled="!!verifyJobs[token.id]"
                                    class="w-full px-3 py-2 rounded-lg bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/20 text-amber-300 font-medium transition-all disabled:opacity-60"
                                    data-test="source-verify-button"
                                >
                                    <template v-if="verifyJobs[token.id]">{{ verifyStageText(verifyJobs[token.id]) }}</template>
                                    <template v-else-if="verifyPending[token.id]">{{ t('page.tokenFactory.verify.resume') }}</template>
                                    <template v-else>🔍 {{ t('page.tokenFactory.verify.buy', { fee: Number(sourceVerifyOption.fee).toLocaleString() }) }}</template>
                                </button>
                            </template>
                        </div>

                        <div v-if="token.status === 'pending'" class="mt-3 p-2 rounded-lg bg-yellow-500/10 text-xs text-yellow-300" data-test="status-note">
                            {{ t('page.tokenFactory.myTokens.pendingNote', { stage: t(IN_PROGRESS_STAGE.pending) }) }}
                        </div>

                        <div v-if="token.status === 'failed'" class="mt-3 p-2 rounded-lg bg-red-500/10 text-xs text-red-400" data-test="status-note">
                            {{ t(IN_PROGRESS_STAGE.failed) }}
                        </div>

                        <div v-if="token.status === 'deploying'" class="mt-3 p-2 rounded-lg bg-blue-500/10 text-xs text-blue-400 flex items-center gap-2" data-test="status-note">
                            <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            {{ t(IN_PROGRESS_STAGE.deploying) }}...
                        </div>

                        <div v-if="token.contract_address && token.status === 'deployed'" class="mt-3 flex gap-2">
                            <button
                                @click="handleCopyContract(token)"
                                :disabled="copyingToken === token.contract_address"
                                :title="t('page.tokenFactory.actions.copyContract')"
                                class="px-3 py-2 rounded-lg bg-dark-800/50 hover:bg-dark-800 border border-white/10 hover:border-white/20 text-gray-300 text-xs font-medium transition-all flex items-center justify-center disabled:opacity-50"
                            >
                                <svg v-if="copyingToken === token.contract_address" class="w-3.5 h-3.5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </button>
                            <button
                                @click="handleAddToWallet(token)"
                                :disabled="addingToken === token.contract_address"
                                class="flex-1 px-3 py-2 rounded-lg bg-primary-500/10 hover:bg-primary-500/20 border border-primary-500/20 hover:border-primary-500/40 text-primary-400 text-xs font-medium transition-all flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <svg v-if="addingToken === token.contract_address" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                {{ addingToken === token.contract_address ? t('page.tokenFactory.actions.adding') : t('page.tokenFactory.actions.addToWallet') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== EXPLORE TAB ===================== -->
            <div v-if="activeTab === 'explore'" class="pb-16">
                <div v-if="factoryStore.tokens.length === 0" class="text-center py-16">
                    <p class="text-gray-400">{{ t('page.tokenFactory.explore.empty') }}</p>
                </div>

                <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div
                        v-for="token in factoryStore.tokens"
                        :key="token.id"
                        class="glass-dark p-5 rounded-xl border border-white/10 hover:border-primary-500/30 transition-all"
                    >
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-accent-500 to-primary-500 flex items-center justify-center">
                                <span class="text-lg font-bold text-white">{{ token.symbol?.charAt(0) }}</span>
                            </div>
                            <div>
                                <p class="font-semibold text-white">{{ token.name }}</p>
                                <p class="text-sm text-gray-400">{{ token.symbol }}</p>
                            </div>
                            <div v-if="token.is_verified" class="ml-auto" :title="t('page.tokenFactory.explore.verified')">
                                <svg class="w-5 h-5 text-blue-400" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                            </div>
                        </div>

                        <p v-if="token.description" class="text-xs text-gray-400 mb-3 line-clamp-2">{{ token.description }}</p>

                        <div class="space-y-1.5 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-400">{{ t('page.tokenFactory.fields.supply') }}</span>
                                <span class="text-white font-mono">{{ formatSupply(token.total_supply) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">{{ t('page.tokenFactory.fields.contract') }}</span>
                                <a
                                    :href="`https://explorer.tpix.online/address/${token.contract_address}`"
                                    target="_blank"
                                    rel="noopener"
                                    class="text-primary-400 hover:text-primary-300 font-mono text-xs"
                                >
                                    {{ token.contract_address?.slice(0, 8) }}...{{ token.contract_address?.slice(-6) }}
                                </a>
                            </div>
                        </div>

                        <div v-if="token.contract_address" class="mt-4 flex gap-2">
                            <button
                                @click="handleCopyContract(token)"
                                :disabled="copyingToken === token.contract_address"
                                :title="t('page.tokenFactory.actions.copyContract')"
                                class="px-3 py-2 rounded-lg bg-dark-800/50 hover:bg-dark-800 border border-white/10 hover:border-white/20 text-gray-300 text-xs font-medium transition-all flex items-center justify-center disabled:opacity-50"
                            >
                                <svg v-if="copyingToken === token.contract_address" class="w-3.5 h-3.5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </button>
                            <button
                                @click="handleAddToWallet(token)"
                                :disabled="addingToken === token.contract_address"
                                class="flex-1 px-3 py-2 rounded-lg bg-primary-500/10 hover:bg-primary-500/20 border border-primary-500/20 hover:border-primary-500/40 text-primary-400 text-xs font-medium transition-all flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <svg v-if="addingToken === token.contract_address" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                {{ addingToken === token.contract_address ? t('page.tokenFactory.actions.adding') : t('page.tokenFactory.actions.addToWallet') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
