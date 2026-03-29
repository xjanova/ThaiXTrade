<script setup>
/**
 * TPIX TRADE - Token Factory Page
 * Wizard UI สำหรับสร้างเหรียญบน TPIX Chain — ง่าย, ครบ, ใช้งานจริง
 * Developed by Xman Studio
 */

import { ref, computed, onMounted, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useWalletStore } from '@/Stores/walletStore';
import { useTokenFactoryStore } from '@/Stores/tokenFactoryStore';
import { useTranslation } from '@/Composables/useTranslation';

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
        }),
    },
});

const { t } = useTranslation();
const walletStore = useWalletStore();
const factoryStore = useTokenFactoryStore();

const activeTab = ref('create');
const showSuccess = ref(false);
const successMessage = ref('');

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

// ===================== CHAIN / TESTNET =====================
const selectedChainId = ref(4289);

const chainOptions = [
    { id: 4289, name: 'TPIX Chain', label: 'Mainnet', color: '#06B6D4', testnet: false },
    { id: 4290, name: 'TPIX Testnet', label: 'Testnet', color: '#f59e0b', testnet: true },
];

const isTestnet = computed(() => {
    const testnetIds = props.factoryConfig.testnet_chain_ids || [4290, 11155111, 97];
    return testnetIds.includes(selectedChainId.value);
});

const testnetResetMonths = computed(() => props.factoryConfig.testnet_reset_months || 3);

const selectedChainName = computed(() => {
    const chain = chainOptions.find(c => c.id === selectedChainId.value);
    return chain?.name || `Chain ${selectedChainId.value}`;
});

// ===================== TOKEN CATEGORIES =====================
const tokenCategories = [
    {
        id: 'fungible',
        label: 'Token (ERC-20)',
        desc: 'เหรียญดิจิทัลทั่วไป สำหรับเทรด, จ่ายค่าบริการ, หรือ Reward',
        desc_en: 'General digital token for trading, payments, or rewards',
        icon: 'coin',
        color: 'from-primary-500 to-accent-500',
    },
    {
        id: 'nft',
        label: 'NFT (ERC-721)',
        desc: 'สินทรัพย์ดิจิทัลเฉพาะตัว สำหรับ Art, Collectibles, Real Estate',
        desc_en: 'Unique digital asset for Art, Collectibles, Real Estate',
        icon: 'nft',
        color: 'from-purple-500 to-pink-500',
        disabled: !props.factoryConfig.nft_enabled,
    },
    {
        id: 'special',
        label: 'Special Token',
        desc: 'Governance, Stablecoin — เหรียญประเภทพิเศษ',
        desc_en: 'Governance, Stablecoin — Special token types',
        icon: 'star',
        color: 'from-amber-500 to-orange-500',
    },
];

// ===================== TOKEN TYPES per category =====================
const tokenTypesByCategory = {
    fungible: [
        { value: 'standard', label: 'Standard', desc: 'Supply คงที่ ไม่สามารถ Mint/Burn', desc_en: 'Fixed supply, no Mint/Burn', icon: '🔒', recommended: true },
        { value: 'mintable', label: 'Mintable', desc: 'เจ้าของสามารถ Mint เหรียญเพิ่มได้', desc_en: 'Owner can mint more tokens', icon: '➕' },
        { value: 'burnable', label: 'Burnable', desc: 'ผู้ถือสามารถ Burn เหรียญได้', desc_en: 'Holders can burn tokens', icon: '🔥' },
        { value: 'mintable_burnable', label: 'Full Function', desc: 'Mint + Burn ครบทุกฟังก์ชัน', desc_en: 'Full Mint + Burn functions', icon: '⚡' },
        { value: 'utility', label: 'Utility Token', desc: 'มีระบบภาษี, Anti-Whale, Auto Liquidity', desc_en: 'Tax system, Anti-Whale, Auto Liquidity', icon: '🔧' },
        { value: 'reward', label: 'Reward Token', desc: 'แจกรางวัลอัตโนมัติ, Loyalty Points, Staking', desc_en: 'Auto rewards, Loyalty Points, Staking', icon: '🎁' },
    ],
    nft: [
        { value: 'nft', label: 'Single NFT', desc: 'สร้าง NFT เดี่ยว — Art, Certificate, Deed', desc_en: 'Single NFT — Art, Certificate, Deed', icon: '🖼️', recommended: true },
        { value: 'nft_collection', label: 'NFT Collection', desc: 'สร้างชุดสะสม — PFP, Game Items', desc_en: 'Collection — PFP, Game Items', icon: '🃏' },
    ],
    special: [
        { value: 'governance', label: 'Governance', desc: 'โหวตตัดสินใจใน DAO พร้อม Treasury', desc_en: 'DAO voting with Treasury management', icon: '🏛️', recommended: true },
        { value: 'stablecoin', label: 'Stablecoin', desc: 'เหรียญผูกค่าเงิน พร้อมระบบ Reserve', desc_en: 'Pegged currency with Reserve system', icon: '💵' },
    ],
};

// ===================== SUB-OPTIONS CONFIG per token type =====================
// ออฟชั่นย่อยของแต่ละ token type — แสดงใน Step 3 (Advanced Options)
const subOptionsConfig = {
    // ─── Common options (เพิ่มให้ทุก type ที่ต้องการ) ───
    _common: [
        {
            group: 'protection', label: 'Protection', label_th: 'ความปลอดภัย',
            icon: '🛡️', fee: 0,
            options: [
                { key: 'pausable', type: 'toggle', label: 'Pausable', label_th: 'หยุดชั่วคราวได้', desc_en: 'Owner can pause all transfers in emergencies', desc_th: 'เจ้าของสามารถหยุดการโอนชั่วคราวได้ (กรณีฉุกเฉิน)', fee: 5, default: false },
                { key: 'blacklist_enabled', type: 'toggle', label: 'Blacklist', label_th: 'บัญชีดำ', desc_en: 'Block specific addresses from transfers', desc_th: 'บล็อกกระเป๋าที่ต้องการไม่ให้ใช้งาน', fee: 5, default: false },
            ],
        },
        {
            group: 'ownership', label: 'Ownership', label_th: 'ความเป็นเจ้าของ',
            icon: '👑', fee: 0,
            options: [
                { key: 'ownership_type', type: 'select', label: 'Ownership', label_th: 'รูปแบบเจ้าของ', desc_en: 'Choose who controls the token', desc_th: 'เลือกรูปแบบการควบคุมเหรียญ', fee: 0, default: 'ownable',
                    choices: [
                        { value: 'ownable', label: 'Single Owner', label_th: 'เจ้าของคนเดียว' },
                        { value: 'renounced', label: 'Renounced (No Owner)', label_th: 'ไม่มีเจ้าของ (ล็อคถาวร)' },
                    ],
                },
            ],
        },
    ],

    // ─── Standard: แค่ common options ───
    standard: [],

    // ─── Mintable ───
    mintable: [
        {
            group: 'mint_controls', label: 'Mint Controls', label_th: 'ควบคุมการ Mint',
            icon: '➕', fee: 0,
            options: [
                { key: 'mint_cap_enabled', type: 'toggle', label: 'Supply Cap', label_th: 'จำกัด Supply สูงสุด', desc_en: 'Set a maximum total supply that can ever exist', desc_th: 'กำหนดจำนวนสูงสุดที่สามารถ Mint ได้', fee: 0, default: false },
                { key: 'mint_cap', type: 'number', label: 'Max Supply Cap', label_th: 'Supply สูงสุด', desc_en: 'Maximum total supply allowed', desc_th: 'จำนวนเหรียญสูงสุดที่สามารถมีได้', fee: 0, default: '', show_if: 'mint_cap_enabled' },
            ],
        },
    ],

    // ─── Burnable ───
    burnable: [
        {
            group: 'burn_controls', label: 'Burn Controls', label_th: 'ควบคุมการ Burn',
            icon: '🔥', fee: 0,
            options: [
                { key: 'auto_burn_enabled', type: 'toggle', label: 'Auto Burn', label_th: 'Burn อัตโนมัติ', desc_en: 'Automatically burn a percentage on every transfer', desc_th: 'Burn อัตโนมัติทุกครั้งที่โอน', fee: 10, default: false },
                { key: 'auto_burn_rate', type: 'range', label: 'Burn Rate (%)', label_th: 'อัตรา Burn (%)', desc_en: 'Percentage burned per transfer', desc_th: 'เปอร์เซ็นต์ที่ Burn ต่อการโอน', fee: 0, default: 1, min: 0.1, max: 10, step: 0.1, show_if: 'auto_burn_enabled' },
                { key: 'burn_floor', type: 'number', label: 'Burn Floor', label_th: 'ขั้นต่ำที่เหลือ', desc_en: 'Minimum supply that cannot be burned below', desc_th: 'จำนวนเหรียญขั้นต่ำที่ไม่สามารถ Burn ต่ำกว่าได้', fee: 0, default: '' },
            ],
        },
    ],

    // ─── Mintable + Burnable ───
    mintable_burnable: [
        {
            group: 'mint_controls', label: 'Mint Controls', label_th: 'ควบคุมการ Mint',
            icon: '➕', fee: 0,
            options: [
                { key: 'mint_cap_enabled', type: 'toggle', label: 'Supply Cap', label_th: 'จำกัด Supply สูงสุด', desc_en: 'Set a maximum total supply', desc_th: 'กำหนดจำนวนสูงสุดที่ Mint ได้', fee: 0, default: false },
                { key: 'mint_cap', type: 'number', label: 'Max Supply Cap', label_th: 'Supply สูงสุด', desc_en: 'Maximum supply allowed', desc_th: 'จำนวนเหรียญสูงสุด', fee: 0, default: '', show_if: 'mint_cap_enabled' },
            ],
        },
        {
            group: 'burn_controls', label: 'Burn Controls', label_th: 'ควบคุมการ Burn',
            icon: '🔥', fee: 0,
            options: [
                { key: 'auto_burn_enabled', type: 'toggle', label: 'Auto Burn', label_th: 'Burn อัตโนมัติ', desc_en: 'Auto burn on every transfer', desc_th: 'Burn อัตโนมัติทุกครั้งที่โอน', fee: 10, default: false },
                { key: 'auto_burn_rate', type: 'range', label: 'Burn Rate (%)', label_th: 'อัตรา Burn (%)', desc_en: 'Percentage burned per transfer', desc_th: 'เปอร์เซ็นต์ Burn', fee: 0, default: 1, min: 0.1, max: 10, step: 0.1, show_if: 'auto_burn_enabled' },
            ],
        },
    ],

    // ─── Utility Token (ออฟชั่นเยอะที่สุด) ───
    utility: [
        {
            group: 'tax_system', label: 'Transaction Tax', label_th: 'ภาษีธุรกรรม',
            icon: '💰', fee: 15,
            options: [
                { key: 'tax_enabled', type: 'toggle', label: 'Enable Tax', label_th: 'เปิดระบบภาษี', desc_en: 'Charge tax on buy/sell/transfer', desc_th: 'เก็บภาษีทุกครั้งที่ซื้อ/ขาย/โอน', fee: 15, default: false },
                { key: 'buy_tax_rate', type: 'range', label: 'Buy Tax (%)', label_th: 'ภาษีซื้อ (%)', desc_en: 'Tax when token is bought', desc_th: 'ภาษีเมื่อซื้อเหรียญ', fee: 0, default: 3, min: 0, max: 25, step: 0.5, show_if: 'tax_enabled' },
                { key: 'sell_tax_rate', type: 'range', label: 'Sell Tax (%)', label_th: 'ภาษีขาย (%)', desc_en: 'Tax when token is sold', desc_th: 'ภาษีเมื่อขายเหรียญ', fee: 0, default: 5, min: 0, max: 25, step: 0.5, show_if: 'tax_enabled' },
                { key: 'transfer_tax_rate', type: 'range', label: 'Transfer Tax (%)', label_th: 'ภาษีโอน (%)', desc_en: 'Tax on wallet-to-wallet transfers', desc_th: 'ภาษีโอนระหว่างกระเป๋า', fee: 0, default: 0, min: 0, max: 10, step: 0.5, show_if: 'tax_enabled' },
                { key: 'tax_wallet', type: 'address', label: 'Tax Recipient Wallet', label_th: 'กระเป๋ารับภาษี', desc_en: 'Wallet that receives collected tax', desc_th: 'กระเป๋าที่รับภาษีที่เก็บได้', fee: 0, default: '', show_if: 'tax_enabled' },
                { key: 'marketing_wallet', type: 'address', label: 'Marketing Wallet', label_th: 'กระเป๋า Marketing', desc_en: 'Separate wallet for marketing funds', desc_th: 'กระเป๋าแยกสำหรับค่าการตลาด', fee: 0, default: '', show_if: 'tax_enabled' },
                { key: 'marketing_share', type: 'range', label: 'Marketing Share (%)', label_th: 'ส่วนแบ่ง Marketing (%)', desc_en: 'Percentage of tax sent to marketing', desc_th: 'เปอร์เซ็นต์ภาษีที่ส่งไป Marketing', fee: 0, default: 40, min: 0, max: 100, step: 5, show_if: 'tax_enabled' },
            ],
        },
        {
            group: 'anti_whale', label: 'Anti-Whale', label_th: 'ป้องกันวาฬ',
            icon: '🐋', fee: 10,
            options: [
                { key: 'anti_whale_enabled', type: 'toggle', label: 'Enable Anti-Whale', label_th: 'เปิด Anti-Whale', desc_en: 'Limit max balance and transaction amounts', desc_th: 'จำกัดจำนวนเหรียญสูงสุดต่อกระเป๋าและต่อธุรกรรม', fee: 10, default: false },
                { key: 'max_wallet_percent', type: 'range', label: 'Max Wallet (%)', label_th: 'ถือสูงสุดต่อกระเป๋า (%)', desc_en: 'Max % of supply per wallet', desc_th: 'เปอร์เซ็นต์สูงสุดต่อกระเป๋า', fee: 0, default: 2, min: 0.5, max: 10, step: 0.5, show_if: 'anti_whale_enabled' },
                { key: 'max_tx_percent', type: 'range', label: 'Max Transaction (%)', label_th: 'โอนสูงสุดต่อครั้ง (%)', desc_en: 'Max % of supply per transaction', desc_th: 'เปอร์เซ็นต์สูงสุดต่อธุรกรรม', fee: 0, default: 1, min: 0.1, max: 5, step: 0.1, show_if: 'anti_whale_enabled' },
            ],
        },
        {
            group: 'anti_bot', label: 'Anti-Bot (Launch)', label_th: 'ป้องกันบอท',
            icon: '🤖', fee: 10,
            options: [
                { key: 'anti_bot_enabled', type: 'toggle', label: 'Enable Anti-Bot', label_th: 'เปิด Anti-Bot', desc_en: 'Protect against bots at launch', desc_th: 'ป้องกันบอทตอนเปิดขาย', fee: 10, default: false },
                { key: 'anti_bot_duration', type: 'number', label: 'Duration (minutes)', label_th: 'ระยะเวลา (นาที)', desc_en: 'How long anti-bot is active after launch', desc_th: 'ระยะเวลาที่ Anti-Bot ทำงานหลังเปิดขาย', fee: 0, default: 30, min: 5, max: 1440, show_if: 'anti_bot_enabled' },
                { key: 'trading_cooldown', type: 'number', label: 'Cooldown (seconds)', label_th: 'เว้นเวลาเทรด (วินาที)', desc_en: 'Min seconds between trades per wallet', desc_th: 'เวลาขั้นต่ำระหว่างการเทรดต่อกระเป๋า', fee: 0, default: 30, min: 5, max: 300, show_if: 'anti_bot_enabled' },
            ],
        },
        {
            group: 'auto_liquidity', label: 'Auto Liquidity', label_th: 'สภาพคล่องอัตโนมัติ',
            icon: '💧', fee: 15,
            options: [
                { key: 'auto_lp_enabled', type: 'toggle', label: 'Enable Auto LP', label_th: 'เปิด Auto Liquidity', desc_en: 'Auto-add portion of tax to liquidity pool', desc_th: 'เพิ่มสภาพคล่องอัตโนมัติจากส่วนหนึ่งของภาษี', fee: 15, default: false },
                { key: 'auto_lp_rate', type: 'range', label: 'LP Rate (%)', label_th: 'อัตรา LP (%)', desc_en: 'Percentage of tax that goes to liquidity', desc_th: 'เปอร์เซ็นต์ภาษีที่ส่งเข้า LP', fee: 0, default: 20, min: 5, max: 50, step: 5, show_if: 'auto_lp_enabled' },
                { key: 'lp_lock_days', type: 'number', label: 'LP Lock (days)', label_th: 'ล็อค LP (วัน)', desc_en: 'Auto-lock LP tokens for security', desc_th: 'ล็อค LP Token อัตโนมัติเพื่อความปลอดภัย', fee: 0, default: 90, min: 30, max: 365, show_if: 'auto_lp_enabled' },
            ],
        },
    ],

    // ─── Reward Token ───
    reward: [
        {
            group: 'reward_system', label: 'Reward Distribution', label_th: 'แจกรางวัล',
            icon: '🎁', fee: 20,
            options: [
                { key: 'reward_type', type: 'select', label: 'Reward Type', label_th: 'ประเภทรางวัล', desc_en: 'How rewards are distributed', desc_th: 'วิธีการแจกรางวัลให้ผู้ถือ', fee: 20, default: 'reflection',
                    choices: [
                        { value: 'reflection', label: 'Reflection (Auto)', label_th: 'สะท้อน (อัตโนมัติ)' },
                        { value: 'dividend', label: 'Dividend (Other Token)', label_th: 'ปันผล (เหรียญอื่น)' },
                        { value: 'staking', label: 'Staking Pool', label_th: 'Staking Pool' },
                    ],
                },
                { key: 'reflection_rate', type: 'range', label: 'Reward Rate (%)', label_th: 'อัตรารางวัล (%)', desc_en: 'Percentage of transactions redistributed', desc_th: 'เปอร์เซ็นต์ของธุรกรรมที่แจกคืนผู้ถือ', fee: 0, default: 3, min: 1, max: 15, step: 0.5 },
                { key: 'min_hold_for_reward', type: 'number', label: 'Min Hold', label_th: 'ถือขั้นต่ำ', desc_en: 'Minimum tokens to qualify for rewards', desc_th: 'จำนวนเหรียญขั้นต่ำเพื่อรับรางวัล', fee: 0, default: 100 },
            ],
        },
        {
            group: 'vesting', label: 'Vesting / Lock', label_th: 'การปลดล็อคเหรียญ',
            icon: '🔐', fee: 10,
            options: [
                { key: 'vesting_enabled', type: 'toggle', label: 'Enable Vesting', label_th: 'เปิด Vesting', desc_en: 'Lock tokens and release on schedule', desc_th: 'ล็อคเหรียญและปลดล็อคตามกำหนด', fee: 10, default: false },
                { key: 'vesting_cliff_days', type: 'number', label: 'Cliff (days)', label_th: 'ระยะ Cliff (วัน)', desc_en: 'Lock period before first unlock', desc_th: 'ระยะเวลาก่อนปลดล็อคครั้งแรก', fee: 0, default: 90, min: 0, max: 730, show_if: 'vesting_enabled' },
                { key: 'vesting_duration_days', type: 'number', label: 'Vesting Duration (days)', label_th: 'ระยะ Vesting (วัน)', desc_en: 'Total linear vesting period after cliff', desc_th: 'ระยะเวลาทั้งหมดของการปลดล็อคแบบ linear', fee: 0, default: 365, min: 30, max: 1460, show_if: 'vesting_enabled' },
            ],
        },
    ],

    // ─── Single NFT ───
    nft: [
        {
            group: 'nft_metadata', label: 'NFT Metadata', label_th: 'ข้อมูล NFT',
            icon: '📄', fee: 0,
            options: [
                { key: 'metadata_storage', type: 'select', label: 'Storage', label_th: 'ที่เก็บข้อมูล', desc_en: 'Where metadata is stored', desc_th: 'เลือกที่เก็บข้อมูล Metadata', fee: 0, default: 'ipfs',
                    choices: [
                        { value: 'ipfs', label: 'IPFS (Decentralized)', label_th: 'IPFS (กระจายศูนย์)' },
                        { value: 'onchain', label: 'On-chain', label_th: 'บน Blockchain' },
                        { value: 'centralized', label: 'URL (Centralized)', label_th: 'URL (ส่วนกลาง)' },
                    ],
                },
                { key: 'metadata_uri', type: 'text', label: 'Metadata URI', label_th: 'URI ข้อมูล', desc_en: 'URL to metadata JSON', desc_th: 'URL ไปยังไฟล์ metadata', fee: 0, default: '' },
            ],
        },
        {
            group: 'royalty', label: 'Royalty (ERC-2981)', label_th: 'ค่าลิขสิทธิ์',
            icon: '👑', fee: 5,
            options: [
                { key: 'royalty_enabled', type: 'toggle', label: 'Enable Royalty', label_th: 'เปิดค่าลิขสิทธิ์', desc_en: 'Receive % on every secondary sale', desc_th: 'รับเปอร์เซ็นต์ทุกครั้งที่มีการขายต่อ', fee: 5, default: true },
                { key: 'royalty_rate', type: 'range', label: 'Royalty (%)', label_th: 'ค่าลิขสิทธิ์ (%)', desc_en: 'Percentage per resale', desc_th: 'เปอร์เซ็นต์ต่อการขายต่อ', fee: 0, default: 5, min: 0, max: 15, step: 0.5, show_if: 'royalty_enabled' },
                { key: 'royalty_wallet', type: 'address', label: 'Royalty Wallet', label_th: 'กระเป๋ารับลิขสิทธิ์', desc_en: 'Wallet that receives royalties', desc_th: 'กระเป๋าที่รับค่าลิขสิทธิ์', fee: 0, default: '', show_if: 'royalty_enabled' },
            ],
        },
        {
            group: 'nft_transfer', label: 'Transfer Rules', label_th: 'กฎการโอน',
            icon: '🔗', fee: 0,
            options: [
                { key: 'soulbound', type: 'toggle', label: 'Soulbound (SBT)', label_th: 'ผูกกระเป๋า (SBT)', desc_en: 'Non-transferable — for certificates, badges', desc_th: 'โอนไม่ได้ — สำหรับใบรับรอง, เหรียญตรา', fee: 5, default: false },
            ],
        },
    ],

    // ─── NFT Collection ───
    nft_collection: [
        {
            group: 'mint_config', label: 'Minting Config', label_th: 'ตั้งค่า Mint',
            icon: '🎨', fee: 0,
            options: [
                { key: 'mint_type', type: 'select', label: 'Mint Type', label_th: 'ประเภท Mint', desc_en: 'How NFTs are minted', desc_th: 'วิธีการ Mint NFT', fee: 0, default: 'public',
                    choices: [
                        { value: 'public', label: 'Public Sale', label_th: 'ขายสาธารณะ' },
                        { value: 'whitelist', label: 'Whitelist Only', label_th: 'Whitelist เท่านั้น' },
                        { value: 'free_claim', label: 'Free Claim', label_th: 'รับฟรี' },
                    ],
                },
                { key: 'mint_price', type: 'number', label: 'Mint Price (TPIX)', label_th: 'ราคา Mint (TPIX)', desc_en: 'Price per NFT in TPIX', desc_th: 'ราคาต่อ NFT (TPIX)', fee: 0, default: 0, min: 0 },
                { key: 'max_per_wallet', type: 'number', label: 'Max per Wallet', label_th: 'สูงสุดต่อกระเป๋า', desc_en: 'Maximum NFTs per wallet', desc_th: 'จำนวน NFT สูงสุดต่อกระเป๋า', fee: 0, default: 10, min: 1 },
                { key: 'max_per_tx', type: 'number', label: 'Max per TX', label_th: 'สูงสุดต่อธุรกรรม', desc_en: 'Maximum NFTs per transaction', desc_th: 'จำนวน NFT สูงสุดต่อธุรกรรม', fee: 0, default: 5, min: 1 },
                { key: 'reserve_count', type: 'number', label: 'Reserve for Team', label_th: 'สำรองให้ทีม', desc_en: 'NFTs reserved and minted to owner', desc_th: 'จำนวน NFT ที่สำรองให้ทีม (Mint ให้เจ้าของ)', fee: 0, default: 0, min: 0 },
            ],
        },
        {
            group: 'reveal', label: 'Reveal Mechanism', label_th: 'การเปิดเผย',
            icon: '🎭', fee: 10,
            options: [
                { key: 'delayed_reveal', type: 'toggle', label: 'Delayed Reveal', label_th: 'เปิดเผยภายหลัง', desc_en: 'All NFTs show placeholder until reveal', desc_th: 'แสดงภาพ Placeholder จนกว่าจะเปิดเผย', fee: 10, default: false },
                { key: 'placeholder_uri', type: 'text', label: 'Placeholder Image URI', label_th: 'URI ภาพ Placeholder', desc_en: 'Image shown before reveal', desc_th: 'ภาพที่แสดงก่อนเปิดเผย', fee: 0, default: '', show_if: 'delayed_reveal' },
            ],
        },
        {
            group: 'royalty', label: 'Royalty (ERC-2981)', label_th: 'ค่าลิขสิทธิ์',
            icon: '👑', fee: 5,
            options: [
                { key: 'royalty_enabled', type: 'toggle', label: 'Enable Royalty', label_th: 'เปิดค่าลิขสิทธิ์', desc_en: 'Receive % on every secondary sale', desc_th: 'รับเปอร์เซ็นต์ทุกครั้งที่ขายต่อ', fee: 5, default: true },
                { key: 'royalty_rate', type: 'range', label: 'Royalty (%)', label_th: 'ค่าลิขสิทธิ์ (%)', fee: 0, default: 5, min: 0, max: 15, step: 0.5, show_if: 'royalty_enabled' },
                { key: 'royalty_wallet', type: 'address', label: 'Royalty Wallet', label_th: 'กระเป๋ารับลิขสิทธิ์', fee: 0, default: '', show_if: 'royalty_enabled' },
            ],
        },
    ],

    // ─── Governance ───
    governance: [
        {
            group: 'voting', label: 'Voting Mechanism', label_th: 'ระบบโหวต',
            icon: '🗳️', fee: 0,
            options: [
                { key: 'voting_type', type: 'select', label: 'Voting Type', label_th: 'ประเภทการโหวต', desc_en: 'How voting power is calculated', desc_th: 'วิธีคำนวณอำนาจโหวต', fee: 0, default: 'token_weighted',
                    choices: [
                        { value: 'token_weighted', label: 'Token-Weighted', label_th: 'ตามจำนวนเหรียญ' },
                        { value: 'quadratic', label: 'Quadratic', label_th: 'แบบ Quadratic' },
                    ],
                },
                { key: 'proposal_threshold', type: 'number', label: 'Proposal Threshold', label_th: 'ขั้นต่ำเสนอ Proposal', desc_en: 'Min tokens required to create a proposal', desc_th: 'จำนวนเหรียญขั้นต่ำเพื่อเสนอ Proposal', fee: 0, default: 1000 },
                { key: 'quorum_percent', type: 'range', label: 'Quorum (%)', label_th: 'องค์ประชุม (%)', desc_en: 'Min participation for a vote to pass', desc_th: 'สัดส่วนขั้นต่ำของผู้โหวตเพื่อให้ Vote ผ่าน', fee: 0, default: 10, min: 1, max: 67, step: 1 },
                { key: 'voting_period_days', type: 'number', label: 'Voting Period (days)', label_th: 'ระยะโหวต (วัน)', desc_en: 'How long voting stays open', desc_th: 'ระยะเวลาที่เปิดให้โหวต', fee: 0, default: 7, min: 1, max: 30 },
            ],
        },
        {
            group: 'treasury', label: 'Treasury', label_th: 'คลังเงิน',
            icon: '🏦', fee: 15,
            options: [
                { key: 'treasury_enabled', type: 'toggle', label: 'Enable Treasury', label_th: 'เปิดคลังเงิน', desc_en: 'DAO treasury controlled by governance', desc_th: 'คลังเงิน DAO ควบคุมโดยการโหวต', fee: 15, default: false },
                { key: 'treasury_wallet', type: 'address', label: 'Treasury Wallet', label_th: 'กระเป๋าคลัง', desc_en: 'Wallet that holds treasury funds', desc_th: 'กระเป๋าที่เก็บเงินคลัง', fee: 0, default: '', show_if: 'treasury_enabled' },
            ],
        },
        {
            group: 'delegation', label: 'Delegation', label_th: 'มอบอำนาจโหวต',
            icon: '🤝', fee: 0,
            options: [
                { key: 'delegation_enabled', type: 'toggle', label: 'Allow Delegation', label_th: 'เปิดมอบอำนาจ', desc_en: 'Token holders can delegate votes', desc_th: 'ผู้ถือเหรียญสามารถมอบอำนาจโหวตได้', fee: 5, default: true },
            ],
        },
    ],

    // ─── Stablecoin ───
    stablecoin: [
        {
            group: 'peg', label: 'Peg Configuration', label_th: 'การผูกค่าเงิน',
            icon: '📌', fee: 0,
            options: [
                { key: 'peg_currency', type: 'select', label: 'Pegged Currency', label_th: 'ค่าเงินที่ผูก', desc_en: 'Currency this stablecoin tracks', desc_th: 'สกุลเงินที่เหรียญนี้ผูกมูลค่า', fee: 0, default: 'THB',
                    choices: [
                        { value: 'THB', label: 'Thai Baht (THB)', label_th: 'บาทไทย (THB)' },
                        { value: 'USD', label: 'US Dollar (USD)', label_th: 'ดอลลาร์สหรัฐ (USD)' },
                        { value: 'EUR', label: 'Euro (EUR)', label_th: 'ยูโร (EUR)' },
                    ],
                },
                { key: 'peg_ratio', type: 'number', label: 'Peg Ratio', label_th: 'อัตราผูก', desc_en: '1 token = how much of pegged currency', desc_th: '1 เหรียญ = กี่หน่วยของสกุลเงินที่ผูก', fee: 0, default: 1, min: 0.0001 },
            ],
        },
        {
            group: 'reserve', label: 'Reserve Management', label_th: 'จัดการ Reserve',
            icon: '🏦', fee: 0,
            options: [
                { key: 'reserve_type', type: 'select', label: 'Reserve Type', label_th: 'ประเภท Reserve', desc_en: 'How stablecoin is backed', desc_th: 'วิธีหนุนค่าเหรียญ', fee: 0, default: 'full_collateral',
                    choices: [
                        { value: 'full_collateral', label: 'Full Collateral (100%)', label_th: 'หลักประกันเต็ม (100%)' },
                        { value: 'fractional', label: 'Fractional Reserve', label_th: 'หลักประกันบางส่วน' },
                        { value: 'algorithmic', label: 'Algorithmic', label_th: 'อัลกอริธึม' },
                    ],
                },
                { key: 'reserve_wallet', type: 'address', label: 'Reserve Wallet', label_th: 'กระเป๋า Reserve', desc_en: 'Wallet holding collateral', desc_th: 'กระเป๋าที่เก็บหลักประกัน', fee: 0, default: '' },
            ],
        },
        {
            group: 'compliance', label: 'Compliance', label_th: 'การปฏิบัติตามกฎ',
            icon: '⚖️', fee: 0,
            options: [
                { key: 'freeze_enabled', type: 'toggle', label: 'Account Freeze', label_th: 'อายัดบัญชีได้', desc_en: 'Authority can freeze accounts (regulatory)', desc_th: 'มีอำนาจอายัดบัญชี (ตามกฎหมาย)', fee: 5, default: false },
                { key: 'kyc_required', type: 'toggle', label: 'KYC Required', label_th: 'ต้อง KYC', desc_en: 'Require KYC for holders above threshold', desc_th: 'ต้องยืนยันตัวตนสำหรับผู้ถือเกินเกณฑ์', fee: 5, default: false },
            ],
        },
    ],
};

const currentTypes = computed(() => tokenTypesByCategory[form.value.token_category] || []);

const isNFT = computed(() => form.value.token_category === 'nft');

// ===================== SUB-OPTIONS STATE =====================
const subOptions = ref({});

// ดึง config สำหรับ token type ปัจจุบัน (common + type-specific)
const currentSubOptionsGroups = computed(() => {
    const tt = form.value.token_type || 'standard';
    const common = subOptionsConfig._common || [];
    const specific = subOptionsConfig[tt] || [];
    return [...common, ...specific];
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
}, { immediate: true });

// คำนวณ fee เพิ่มจาก sub-options
const subOptionsFee = computed(() => {
    let fee = 0;
    const items = [];
    for (const group of currentSubOptionsGroups.value) {
        for (const opt of group.options) {
            if (opt.fee > 0 && opt.type === 'toggle' && subOptions.value[opt.key]) {
                fee += opt.fee;
                items.push({ label: opt.label, label_th: opt.label_th, amount: opt.fee });
            } else if (opt.fee > 0 && opt.type === 'select' && subOptions.value[opt.key] && subOptions.value[opt.key] !== opt.default) {
                // Select ที่เปลี่ยนจาก default (ถ้ามี fee)
            }
        }
    }
    return { total: fee, items };
});

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
                activeOpts.push({ ...opt, displayValue: 'ON' });
            } else if (opt.type === 'range' || opt.type === 'number') {
                activeOpts.push({ ...opt, displayValue: val });
            } else if (opt.type === 'select') {
                const choice = opt.choices?.find(c => c.value === val);
                activeOpts.push({ ...opt, displayValue: choice?.label || val });
            } else if (opt.type === 'address' || opt.type === 'text') {
                if (val) activeOpts.push({ ...opt, displayValue: val.length > 20 ? val.slice(0, 10) + '...' + val.slice(-6) : val });
            }
        }
        if (activeOpts.length > 0) {
            summary.push({ group: group.label, group_th: group.label_th, icon: group.icon, options: activeOpts });
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
            { label: '1', value: '1', desc: 'Single' },
            { label: '100', value: '100', desc: 'Limited' },
            { label: '1K', value: '1000', desc: 'Collection' },
            { label: '10K', value: '10000', desc: 'Large' },
        ];
    }
    return [
        { label: '1M', value: '1000000', desc: 'Startup' },
        { label: '10M', value: '10000000', desc: 'Standard' },
        { label: '100M', value: '100000000', desc: 'Large' },
        { label: '1B', value: '1000000000', desc: 'Enterprise' },
    ];
});

// ===================== WIZARD VALIDATION =====================
const stepValid = computed(() => {
    switch (wizardStep.value) {
        case 1: return !!form.value.token_category;
        case 2: return !!form.value.token_type;
        case 3: return true; // Advanced options — always valid (all optional)
        case 4: return !!form.value.name && !!form.value.symbol && !!form.value.total_supply;
        case 5: return true; // Review step
        default: return false;
    }
});

const canCreate = computed(() => {
    return walletStore.isConnected
        && form.value.name
        && form.value.symbol
        && form.value.total_supply
        && props.factoryConfig.creation_enabled
        && props.factoryConfig.ready !== false
        && !isSubmitting.value;
});

// ===================== DYNAMIC FEE CALCULATOR =====================
const dynamicFee = computed(() => {
    // Testnet → สร้างฟรีเสมอ
    if (isTestnet.value) {
        return { total: 0, breakdown: [], is_free: true, is_testnet: true };
    }

    const fees = props.factoryConfig.dynamic_fees;
    if (!fees) {
        // Fallback ถ้า backend ยังไม่ส่ง dynamic_fees
        return {
            total: props.factoryConfig.creation_fee_tpix || 100,
            breakdown: [{ label: 'Creation Fee', label_th: 'ค่าสร้างเหรียญ', amount: props.factoryConfig.creation_fee_tpix || 100 }],
            is_free: false,
            is_testnet: false,
        };
    }

    const method = props.factoryConfig.fee_payment_method;
    if (method === 'free') {
        return { total: 0, breakdown: [], is_free: true, is_testnet: false };
    }

    const breakdown = [];
    let total = 0;

    // 1. Base fee
    const baseFee = fees.base_fee || 50;
    breakdown.push({ label: 'Base Fee', label_th: 'ค่าพื้นฐาน', amount: baseFee });
    total += baseFee;

    // 2. Category fee
    const category = form.value.token_category || 'fungible';
    const catFee = fees.category_fees?.[category] || 0;
    if (catFee > 0) {
        const catLabels = { nft: 'NFT Category', special: 'Special Category' };
        const catLabelsTh = { nft: 'ประเภท NFT', special: 'ประเภทพิเศษ' };
        breakdown.push({
            label: catLabels[category] || category,
            label_th: catLabelsTh[category] || category,
            amount: catFee,
        });
        total += catFee;
    }

    // 3. Type fee
    const tokenType = form.value.token_type || 'standard';
    const typFee = fees.type_fees?.[tokenType] || 0;
    if (typFee > 0) {
        const typeLabels = {
            mintable: 'Mint Function', burnable: 'Burn Function',
            mintable_burnable: 'Mint + Burn', utility: 'Utility Features',
            reward: 'Reward Features', nft_collection: 'Collection Features',
            governance: 'Governance', stablecoin: 'Stablecoin Mechanism',
        };
        const typeLabelsTh = {
            mintable: 'ฟังก์ชัน Mint', burnable: 'ฟังก์ชัน Burn',
            mintable_burnable: 'Mint + Burn', utility: 'Utility',
            reward: 'Reward', nft_collection: 'Collection',
            governance: 'Governance', stablecoin: 'Stablecoin',
        };
        breakdown.push({
            label: typeLabels[tokenType] || tokenType,
            label_th: typeLabelsTh[tokenType] || tokenType,
            amount: typFee,
        });
        total += typFee;
    }

    // 4. Custom decimals fee
    const decimals = form.value.decimals;
    if (decimals !== 18 && decimals !== 0) {
        const decFee = fees.option_fees?.custom_decimals || 5;
        if (decFee > 0) {
            breakdown.push({ label: `Custom Decimals (${decimals})`, label_th: `Decimals พิเศษ (${decimals})`, amount: decFee });
            total += decFee;
        }
    }

    // 5. Large supply fee
    const supply = parseFloat(form.value.total_supply) || 0;
    const veryLargeThreshold = fees.very_large_supply_threshold || 100000000000;
    const largeThreshold = fees.large_supply_threshold || 1000000000;

    if (supply > veryLargeThreshold) {
        const supFee = fees.option_fees?.very_large_supply || 25;
        if (supFee > 0) {
            breakdown.push({ label: 'Very Large Supply (>100B)', label_th: 'Supply ใหญ่มาก (>100B)', amount: supFee });
            total += supFee;
        }
    } else if (supply > largeThreshold) {
        const supFee = fees.option_fees?.large_supply || 10;
        if (supFee > 0) {
            breakdown.push({ label: 'Large Supply (>1B)', label_th: 'Supply ใหญ่ (>1B)', amount: supFee });
            total += supFee;
        }
    }

    // 6. Sub-options fees
    for (const item of subOptionsFee.value.items) {
        breakdown.push({ label: item.label, label_th: item.label_th, amount: item.amount });
        total += item.amount;
    }

    return { total: Math.round(total * 100) / 100, breakdown, is_free: false, is_testnet: false };
});

const feeDisplay = computed(() => {
    if (dynamicFee.value.is_free) {
        return {
            amount: 'FREE',
            currency: dynamicFee.value.is_testnet ? '(Testnet)' : '',
            isFree: true,
            isTestnet: dynamicFee.value.is_testnet || false,
        };
    }
    return {
        amount: dynamicFee.value.total.toLocaleString(),
        currency: 'TPIX',
        isFree: false,
        isTestnet: false,
    };
});

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
    // Reset type when changing category
    const types = tokenTypesByCategory[catId];
    const recommended = types?.find(t => t.recommended);
    form.value.token_type = recommended?.value || types?.[0]?.value || 'standard';

    // NFT defaults
    if (catId === 'nft') {
        form.value.decimals = 0;
        form.value.total_supply = '1';
    } else {
        form.value.decimals = 18;
        form.value.total_supply = '';
    }
}

// ===================== LOGO UPLOAD =====================
async function handleLogoSelect(event) {
    const file = event.target.files?.[0];
    if (!file) return;

    // Validate file type & size (client-side)
    const allowedTypes = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
    if (!allowedTypes.includes(file.type)) {
        formErrors.value.logo = 'Only PNG, JPG, WEBP, SVG files are allowed.';
        return;
    }
    if (file.size > 2 * 1024 * 1024) {
        formErrors.value.logo = 'Logo must be less than 2MB.';
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
            formErrors.value.logo = 'Upload failed. Please try again.';
        }
    } catch {
        formErrors.value.logo = 'Upload failed. Please try again.';
    } finally {
        isUploadingLogo.value = false;
    }
}

function removeLogo() {
    logoFile.value = null;
    logoPreview.value = '';
    form.value.logo_url = '';
}

// ===================== SUBMIT =====================
async function handleCreate() {
    if (!canCreate.value) return;
    formErrors.value = {};
    isSubmitting.value = true;

    try {
        await factoryStore.createToken({
            ...form.value,
            sub_options: subOptions.value,
            creator_address: walletStore.address,
            chain_id: selectedChainId.value,
        });

        successMessage.value = `Token ${form.value.symbol.toUpperCase()} submitted for review!`;
        showSuccess.value = true;

        // Reset
        form.value = { name: '', symbol: '', decimals: 18, total_supply: '', description: '', website: '', logo_url: '', token_type: 'standard', token_category: 'fungible' };
        logoFile.value = null;
        logoPreview.value = '';
        wizardStep.value = 1;
        activeTab.value = 'my-tokens';

        // Refresh ทั้ง myTokens และ explore list
        if (walletStore.address) {
            factoryStore.fetchMyTokens(walletStore.address);
        }
        factoryStore.fetchTokens();

        setTimeout(() => { showSuccess.value = false; }, 5000);
    } catch (e) {
        const msg = e.response?.data?.errors
            ? Object.values(e.response.data.errors).flat().join(', ')
            : e.response?.data?.error?.message || e.message;
        formErrors.value.general = msg;
    } finally {
        isSubmitting.value = false;
    }
}

// ===================== LIFECYCLE =====================
onMounted(async () => {
    await factoryStore.fetchTokens();
    if (walletStore.address) {
        await factoryStore.fetchMyTokens(walletStore.address);
    }
});

watch(() => walletStore.address, async (addr) => {
    if (addr) await factoryStore.fetchMyTokens(addr);
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

function getCategoryLabel(cat) {
    return tokenCategories.find(c => c.id === cat)?.label || cat;
}

function getTypeLabel(type) {
    for (const types of Object.values(tokenTypesByCategory)) {
        const found = types.find(t => t.value === type);
        if (found) return found.label;
    }
    return type;
}
</script>

<template>
    <Head title="Token Factory — Create Your Token" />

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
                        <span class="w-2 h-2 rounded-full" :class="isTestnet ? 'bg-amber-400' : 'bg-green-400'"></span>
                        {{ selectedChainName }}
                        <span v-if="isTestnet" class="px-1.5 py-0.5 bg-amber-500/20 text-amber-400 text-[10px] rounded-md font-bold">TESTNET</span>
                    </div>
                    <div class="text-gray-400">Gas: <span class="text-green-400 font-semibold">FREE</span></div>
                    <div class="text-gray-400">
                        Fee:
                        <span :class="feeDisplay.isFree ? 'text-green-400 font-semibold' : 'text-white font-semibold'">
                            {{ feeDisplay.amount }} {{ feeDisplay.currency }}
                        </span>
                    </div>
                    <div class="text-gray-400">Block: <span class="text-white font-semibold">~2s</span></div>
                </div>
            </div>
        </section>

        <!-- Factory Not Ready Warning -->
        <div v-if="props.factoryConfig.ready === false" class="max-w-4xl mx-auto px-4 sm:px-6 mb-6">
            <div class="p-4 rounded-xl bg-yellow-500/10 border border-yellow-500/20 text-yellow-400 flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                <div>
                    <p class="font-medium text-sm">Token Factory is not available yet</p>
                    <p class="text-xs text-yellow-400/70 mt-1">{{ Array.isArray(props.factoryConfig.issues) ? props.factoryConfig.issues.join('. ') : Object.values(props.factoryConfig.issues || {}).join('. ') || 'System configuration pending.' }}</p>
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

        <!-- Tabs -->
        <section class="max-w-4xl mx-auto px-4 sm:px-6">
            <div class="flex gap-1 mb-8 bg-dark-900/50 rounded-xl p-1 max-w-md">
                <button
                    v-for="tab in [
                        { id: 'create', label: 'Create Token' },
                        { id: 'my-tokens', label: 'My Tokens' },
                        { id: 'explore', label: 'Explore' },
                    ]"
                    :key="tab.id"
                    @click="activeTab = tab.id"
                    class="flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-all"
                    :class="activeTab === tab.id ? 'bg-primary-500/20 text-primary-400' : 'text-gray-400 hover:text-white'"
                >
                    {{ tab.label }}
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
                    <h3 class="text-lg font-bold text-white mb-2">Connect Wallet</h3>
                    <p class="text-gray-400 text-sm mb-6">Connect your wallet to start creating tokens on TPIX Chain</p>
                    <button @click="walletStore.openConnectModal()" class="btn-primary px-8 py-2.5">Connect Wallet</button>
                </div>

                <!-- Disabled Gate -->
                <div v-else-if="!factoryConfig.creation_enabled" class="glass-dark p-12 rounded-2xl border border-white/10 text-center max-w-lg mx-auto">
                    <p class="text-gray-400">Token creation is currently disabled. Please check back later.</p>
                </div>

                <!-- Wizard -->
                <div v-else class="max-w-2xl mx-auto">
                    <!-- Persistent Chain Selector Bar -->
                    <div class="mb-4 p-3 rounded-xl glass-dark border border-white/10">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-gray-400 uppercase tracking-wider">Deploy Chain</span>
                            <div class="flex items-center gap-2">
                                <button
                                    v-for="chain in chainOptions"
                                    :key="chain.id"
                                    type="button"
                                    @click="selectedChainId = chain.id"
                                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg border text-xs font-medium transition-all"
                                    :class="selectedChainId === chain.id
                                        ? chain.testnet
                                            ? 'bg-amber-500/15 border-amber-500/40 text-amber-400 ring-1 ring-amber-500/20'
                                            : 'bg-primary-500/15 border-primary-500/40 text-primary-400 ring-1 ring-primary-500/20'
                                        : 'bg-white/5 border-white/10 text-gray-400 hover:border-white/20'"
                                >
                                    <span class="w-1.5 h-1.5 rounded-full" :style="{ backgroundColor: chain.color }"></span>
                                    {{ chain.name }}
                                    <span v-if="chain.testnet && selectedChainId === chain.id" class="px-1 py-0.5 bg-green-500/20 text-green-400 text-[9px] rounded font-bold ml-0.5">FREE</span>
                                    <span v-else-if="chain.testnet" class="px-1 py-0.5 bg-amber-500/20 text-amber-400 text-[9px] rounded font-bold ml-0.5">FREE</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Persistent Testnet Warning Banner -->
                    <div v-if="isTestnet" class="mb-4 p-3 rounded-xl bg-amber-500/10 border border-amber-500/30">
                        <div class="flex items-center gap-3">
                            <div class="flex items-center gap-2">
                                <span class="relative flex h-2.5 w-2.5">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-400"></span>
                                </span>
                                <span class="text-sm font-bold text-amber-400">TESTNET</span>
                            </div>
                            <span class="text-xs text-amber-400/80">FREE tokens · No fees · Reset every {{ testnetResetMonths }} months · No real value / สร้างฟรี · รีเซ็ตทุก {{ testnetResetMonths }} เดือน</span>
                        </div>
                    </div>

                    <!-- Step Indicator -->
                    <div class="flex items-center justify-between mb-8 px-2">
                        <button
                            v-for="step in [
                                { n: 1, label: 'Category' },
                                { n: 2, label: 'Type' },
                                { n: 3, label: 'Options' },
                                { n: 4, label: 'Details' },
                                { n: 5, label: 'Review' },
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
                            <span class="hidden sm:inline text-xs font-medium" :class="wizardStep === step.n ? 'text-white' : 'text-gray-500'">{{ step.label }}</span>
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
                                <h2 class="text-xl font-bold text-white">Choose Token Category</h2>
                                <p class="text-sm text-gray-400 mt-1">เลือกประเภทสินทรัพย์ดิจิทัลที่ต้องการสร้าง / Select the type of digital asset</p>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <button
                                    v-for="cat in tokenCategories"
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

                                    <h3 class="font-semibold text-white mb-1">{{ cat.label }}</h3>
                                    <p class="text-xs text-gray-400 leading-relaxed">{{ cat.desc }}</p>
                                    <p v-if="cat.desc_en" class="text-[10px] text-gray-500 mt-0.5">{{ cat.desc_en }}</p>

                                    <!-- Selected indicator -->
                                    <div v-if="form.token_category === cat.id && !cat.disabled" class="absolute top-3 right-3">
                                        <svg class="w-5 h-5 text-primary-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                    </div>

                                    <span v-if="cat.disabled" class="absolute top-3 right-3 text-xs text-gray-500 bg-white/5 px-2 py-0.5 rounded">Coming Soon</span>
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
                                <h2 class="text-xl font-bold text-white">Choose Token Type</h2>
                                <p class="text-sm text-gray-400 mt-1">เลือกฟังก์ชันสำหรับ {{ getCategoryLabel(form.token_category) }} / Select functionality</p>
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
                                                <h3 class="font-semibold" :class="form.token_type === tt.value ? 'text-primary-400' : 'text-white'">{{ tt.label }}</h3>
                                                <span v-if="tt.recommended" class="text-[10px] px-1.5 py-0.5 rounded-full bg-green-500/20 text-green-400 font-medium">Recommended</span>
                                            </div>
                                            <p class="text-xs text-gray-400 mt-0.5">{{ tt.desc }}</p>
                                            <p v-if="tt.desc_en" class="text-[10px] text-gray-500">{{ tt.desc_en }}</p>
                                        </div>
                                    </div>

                                    <div v-if="form.token_type === tt.value" class="absolute top-3 right-3">
                                        <svg class="w-5 h-5 text-primary-400" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                </button>
                            </div>
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
                                <h2 class="text-xl font-bold text-white">Advanced Options</h2>
                                <p class="text-sm text-gray-400 mt-1">ตั้งค่าฟีเจอร์เพิ่มเติม (ไม่จำเป็น) / Configure additional features (optional)</p>
                            </div>

                            <!-- No options message for standard -->
                            <div v-if="currentSubOptionsGroups.length === 0 || (currentSubOptionsGroups.length === 2 && form.token_type === 'standard')" class="glass-dark p-8 rounded-2xl border border-white/10 text-center">
                                <p class="text-gray-400 text-sm">Standard token ไม่มีออฟชั่นเพิ่มเติม</p>
                                <p class="text-xs text-gray-500 mt-1">Standard token has no additional options. Click Next to continue.</p>
                            </div>

                            <!-- Option Groups -->
                            <div v-for="group in currentSubOptionsGroups" :key="group.group" class="glass-dark rounded-2xl border border-white/10 overflow-hidden">
                                <!-- Group Header -->
                                <div class="p-4 border-b border-white/5 flex items-center gap-3">
                                    <span class="text-xl">{{ group.icon }}</span>
                                    <div class="flex-1">
                                        <h3 class="text-sm font-semibold text-white">{{ group.label }}</h3>
                                        <p class="text-[10px] text-gray-500">{{ group.label_th }}</p>
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
                                                    <p class="text-sm font-medium text-white">{{ opt.label }}</p>
                                                    <span class="text-[10px] text-gray-500">{{ opt.label_th }}</span>
                                                    <span v-if="opt.fee > 0 && subOptions[opt.key]" class="px-1.5 py-0.5 rounded bg-amber-500/10 text-amber-400 text-[9px] font-medium">+{{ opt.fee }} TPIX</span>
                                                </div>
                                                <p class="text-xs text-gray-400 mt-0.5">{{ opt.desc_en || '' }}</p>
                                                <p class="text-[10px] text-gray-500">{{ opt.desc_th || '' }}</p>
                                            </div>
                                            <button type="button" @click="subOptions[opt.key] = !subOptions[opt.key]"
                                                :class="['w-11 h-6 rounded-full transition-colors flex-shrink-0', subOptions[opt.key] ? 'bg-primary-500' : 'bg-white/10']">
                                                <div :class="['w-5 h-5 bg-white rounded-full shadow transition-transform', subOptions[opt.key] ? 'translate-x-5' : 'translate-x-0.5']"></div>
                                            </button>
                                        </div>

                                        <!-- Range / Slider -->
                                        <div v-else-if="opt.type === 'range'" class="py-2">
                                            <div class="flex items-center justify-between mb-1.5">
                                                <div class="flex items-center gap-2">
                                                    <p class="text-sm font-medium text-white">{{ opt.label }}</p>
                                                    <span class="text-[10px] text-gray-500">{{ opt.label_th }}</span>
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
                                                <p class="text-sm font-medium text-white">{{ opt.label }}</p>
                                                <span class="text-[10px] text-gray-500">{{ opt.label_th }}</span>
                                            </div>
                                            <input type="number" v-model.number="subOptions[opt.key]" :min="opt.min" :max="opt.max"
                                                :placeholder="opt.desc_en" class="trading-input w-full max-w-xs" />
                                            <p class="text-[10px] text-gray-500 mt-0.5">{{ opt.desc_th }}</p>
                                        </div>

                                        <!-- Select -->
                                        <div v-else-if="opt.type === 'select'" class="py-2">
                                            <div class="flex items-center gap-2 mb-1.5">
                                                <p class="text-sm font-medium text-white">{{ opt.label }}</p>
                                                <span class="text-[10px] text-gray-500">{{ opt.label_th }}</span>
                                            </div>
                                            <div class="flex flex-wrap gap-2">
                                                <button v-for="choice in opt.choices" :key="choice.value" type="button"
                                                    @click="subOptions[opt.key] = choice.value"
                                                    class="px-3 py-2 rounded-lg border text-xs font-medium transition-all"
                                                    :class="subOptions[opt.key] === choice.value
                                                        ? 'bg-primary-500/15 border-primary-500/40 text-primary-400'
                                                        : 'bg-white/5 border-white/10 text-gray-400 hover:border-white/20'">
                                                    <span>{{ choice.label }}</span>
                                                    <span class="block text-[10px] text-gray-500 mt-0.5">{{ choice.label_th }}</span>
                                                </button>
                                            </div>
                                            <p v-if="opt.desc_th" class="text-[10px] text-gray-500 mt-1">{{ opt.desc_th }}</p>
                                        </div>

                                        <!-- Address -->
                                        <div v-else-if="opt.type === 'address'" class="py-2">
                                            <div class="flex items-center gap-2 mb-1.5">
                                                <p class="text-sm font-medium text-white">{{ opt.label }}</p>
                                                <span class="text-[10px] text-gray-500">{{ opt.label_th }}</span>
                                            </div>
                                            <input type="text" v-model="subOptions[opt.key]" placeholder="0x..." class="trading-input w-full font-mono text-xs" />
                                            <p class="text-[10px] text-gray-500 mt-0.5">{{ opt.desc_th }}</p>
                                        </div>

                                        <!-- Text -->
                                        <div v-else-if="opt.type === 'text'" class="py-2">
                                            <div class="flex items-center gap-2 mb-1.5">
                                                <p class="text-sm font-medium text-white">{{ opt.label }}</p>
                                                <span class="text-[10px] text-gray-500">{{ opt.label_th }}</span>
                                            </div>
                                            <input type="text" v-model="subOptions[opt.key]" :placeholder="opt.desc_en" class="trading-input w-full text-xs" />
                                            <p class="text-[10px] text-gray-500 mt-0.5">{{ opt.desc_th }}</p>
                                        </div>

                                    </div>
                                </div>
                            </div>

                            <!-- Fee Preview -->
                            <div v-if="subOptionsFee.total > 0 && !isTestnet" class="p-3 rounded-xl bg-amber-500/5 border border-amber-500/10 flex items-center justify-between text-sm">
                                <span class="text-gray-400">ค่าธรรมเนียม Options / Options Fee</span>
                                <span class="text-amber-400 font-bold">+{{ subOptionsFee.total }} TPIX</span>
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
                                <h2 class="text-xl font-bold text-white">Token Details</h2>
                                <p class="text-sm text-gray-400 mt-1">กรอกข้อมูลเหรียญ / Fill in token information</p>
                            </div>

                            <div class="glass-dark p-6 rounded-2xl border border-white/10 space-y-5">
                                <!-- Name & Symbol -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1.5">
                                            {{ isNFT ? 'Collection Name' : 'Token Name' }} <span class="text-red-400">*</span>
                                        </label>
                                        <input
                                            v-model="form.name"
                                            type="text"
                                            :placeholder="isNFT ? 'e.g. TPIX Punks' : 'e.g. My Token'"
                                            class="trading-input w-full"
                                            maxlength="100"
                                        />
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1.5">
                                            Symbol <span class="text-red-400">*</span>
                                        </label>
                                        <input
                                            v-model="form.symbol"
                                            type="text"
                                            :placeholder="isNFT ? 'e.g. TPUNK' : 'e.g. MTK'"
                                            class="trading-input w-full uppercase"
                                            maxlength="20"
                                        />
                                    </div>
                                </div>

                                <!-- Total Supply with presets -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1.5">
                                        {{ isNFT ? 'Max Supply' : 'Total Supply' }} <span class="text-red-400">*</span>
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
                                        :placeholder="isNFT ? 'e.g. 10000' : 'e.g. 1000000'"
                                        :min="1"
                                        :max="factoryConfig.max_supply_limit"
                                        class="trading-input w-full"
                                    />
                                </div>

                                <!-- Decimals (hidden for NFT) -->
                                <div v-if="!isNFT" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-300 mb-1.5">Decimals</label>
                                        <select v-model.number="form.decimals" class="trading-input w-full">
                                            <option :value="18">18 (Standard)</option>
                                            <option :value="8">8 (Bitcoin-like)</option>
                                            <option :value="6">6 (USDT-like)</option>
                                            <option :value="0">0 (No decimals)</option>
                                        </select>
                                    </div>
                                    <div class="flex items-end">
                                        <p class="text-xs text-gray-500 pb-3">
                                            Decimals determine the smallest divisible unit. 18 is the most common for ERC-20 tokens.
                                        </p>
                                    </div>
                                </div>

                                <!-- Description (optional) -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1.5">Description <span class="text-gray-500">(Optional)</span></label>
                                    <textarea
                                        v-model="form.description"
                                        rows="2"
                                        placeholder="Briefly describe your token..."
                                        class="trading-input w-full resize-none"
                                        maxlength="1000"
                                    ></textarea>
                                </div>

                                <!-- Website (optional) -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1.5">Website <span class="text-gray-500">(Optional)</span></label>
                                    <input v-model="form.website" type="url" placeholder="https://..." class="trading-input w-full" />
                                </div>

                                <!-- Logo Upload (optional) -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-300 mb-1.5">Token Logo <span class="text-gray-500">(Optional)</span></label>
                                    <div v-if="logoPreview" class="flex items-center gap-4 mb-3">
                                        <img :src="logoPreview" alt="Logo preview" class="w-16 h-16 rounded-xl object-cover border border-white/10" />
                                        <div class="flex-1">
                                            <p class="text-sm text-white">{{ logoFile?.name }}</p>
                                            <p class="text-xs text-gray-400">{{ logoFile ? (logoFile.size / 1024).toFixed(1) + ' KB' : '' }}</p>
                                            <div v-if="isUploadingLogo" class="mt-1 flex items-center gap-2">
                                                <svg class="w-3.5 h-3.5 animate-spin text-primary-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                                <span class="text-xs text-primary-400">Uploading...</span>
                                            </div>
                                            <p v-else-if="form.logo_url" class="text-xs text-green-400 mt-1">✓ Uploaded</p>
                                        </div>
                                        <button type="button" @click="removeLogo" class="text-gray-400 hover:text-red-400 transition-colors p-1">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                    <label v-else class="flex flex-col items-center justify-center p-6 rounded-xl border-2 border-dashed border-white/10 hover:border-primary-500/30 hover:bg-primary-500/5 transition-all cursor-pointer group">
                                        <svg class="w-8 h-8 text-gray-500 group-hover:text-primary-400 transition-colors mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        <span class="text-xs text-gray-400 group-hover:text-gray-300">Click to upload logo</span>
                                        <span class="text-[10px] text-gray-500 mt-0.5">PNG, JPG, WEBP, SVG · Max 2MB</span>
                                        <input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="hidden" @change="handleLogoSelect" />
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
                                <h2 class="text-xl font-bold text-white">Review & Confirm</h2>
                                <p class="text-sm text-gray-400 mt-1">ตรวจสอบข้อมูลก่อนส่ง / Double check everything before submitting</p>
                            </div>

                            <div class="glass-dark p-6 rounded-2xl border border-white/10">
                                <!-- Token Preview Card -->
                                <div class="flex items-center gap-4 mb-6 pb-6 border-b border-white/10">
                                    <div v-if="logoPreview" class="w-14 h-14 rounded-2xl overflow-hidden border border-white/10">
                                        <img :src="logoPreview" alt="Token logo" class="w-full h-full object-cover" />
                                    </div>
                                    <div v-else class="w-14 h-14 rounded-2xl bg-gradient-to-br from-accent-500 to-primary-500 flex items-center justify-center">
                                        <span class="text-xl font-bold text-white">{{ form.symbol?.charAt(0)?.toUpperCase() || '?' }}</span>
                                    </div>
                                    <div>
                                        <h3 class="text-lg font-bold text-white">{{ form.name || 'Token Name' }}</h3>
                                        <p class="text-sm text-gray-400">
                                            {{ form.symbol?.toUpperCase() || 'SYMBOL' }} on {{ selectedChainName }}
                                            <span v-if="isTestnet" class="ml-1 px-1.5 py-0.5 bg-amber-500/20 text-amber-400 text-[10px] rounded-full font-bold">TESTNET</span>
                                        </p>
                                    </div>
                                </div>

                                <!-- Details Grid -->
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                                    <div class="space-y-3">
                                        <div>
                                            <p class="text-gray-500 text-xs">Category</p>
                                            <p class="text-white font-medium">{{ getCategoryLabel(form.token_category) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">Type</p>
                                            <p class="text-white font-medium">{{ getTypeLabel(form.token_type) }}</p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">{{ isNFT ? 'Max Supply' : 'Total Supply' }}</p>
                                            <p class="text-white font-medium font-mono">{{ formatSupply(form.total_supply) }}</p>
                                        </div>
                                    </div>
                                    <div class="space-y-3">
                                        <div>
                                            <p class="text-gray-500 text-xs">Decimals</p>
                                            <p class="text-white font-medium">{{ form.decimals }}</p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">Chain</p>
                                            <p class="text-white font-medium">
                                                {{ selectedChainName }} ({{ selectedChainId }})
                                                <span v-if="isTestnet" class="ml-1 text-amber-400 text-[10px]">⚠ TESTNET</span>
                                            </p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 text-xs">Creator</p>
                                            <p class="text-primary-400 font-mono text-xs">{{ walletStore.address?.slice(0, 8) }}...{{ walletStore.address?.slice(-4) }}</p>
                                        </div>
                                    </div>
                                </div>

                                <div v-if="form.description" class="mt-4 pt-4 border-t border-white/10">
                                    <p class="text-gray-500 text-xs mb-1">Description</p>
                                    <p class="text-sm text-gray-300">{{ form.description }}</p>
                                </div>
                                <div v-if="form.website" class="mt-3">
                                    <p class="text-gray-500 text-xs mb-1">Website</p>
                                    <p class="text-sm text-primary-400">{{ form.website }}</p>
                                </div>
                                <div v-if="form.logo_url" class="mt-3">
                                    <p class="text-gray-500 text-xs mb-1">Logo</p>
                                    <p class="text-sm text-green-400">✓ Uploaded</p>
                                </div>
                            </div>

                            <!-- Sub-Options Review -->
                            <div v-if="hasActiveSubOptions" class="glass-dark p-5 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white mb-3">Advanced Options / ออฟชั่นเพิ่มเติม</p>
                                <div v-for="grp in selectedSubOptionsSummary" :key="grp.group" class="mb-3 last:mb-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="text-sm">{{ grp.icon }}</span>
                                        <span class="text-xs font-medium text-gray-300">{{ grp.group }}</span>
                                        <span class="text-[10px] text-gray-500">{{ grp.group_th }}</span>
                                    </div>
                                    <div class="pl-6 space-y-1">
                                        <div v-for="opt in grp.options" :key="opt.key" class="flex items-center justify-between text-xs">
                                            <div class="flex items-center gap-2">
                                                <span class="text-gray-400">{{ opt.label }}</span>
                                                <span class="text-[10px] text-gray-500">{{ opt.label_th }}</span>
                                            </div>
                                            <span class="text-white font-mono">{{ opt.displayValue }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Dynamic Fee Breakdown -->
                            <div class="glass-dark p-5 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white mb-3">Creation Fee / ค่าธรรมเนียมสร้างเหรียญ</p>

                                <!-- Fee breakdown items -->
                                <div v-if="dynamicFee.breakdown.length > 0" class="space-y-2 mb-3">
                                    <div
                                        v-for="(item, idx) in dynamicFee.breakdown"
                                        :key="idx"
                                        class="flex items-center justify-between text-sm"
                                    >
                                        <span class="text-gray-400">{{ item.label_th || item.label }}</span>
                                        <span class="text-white font-mono">+{{ item.amount }} TPIX</span>
                                    </div>
                                </div>

                                <!-- Total -->
                                <div class="flex items-center justify-between pt-3 border-t border-white/10">
                                    <span class="text-sm font-medium text-gray-300">Total</span>
                                    <span class="text-xl font-bold" :class="dynamicFee.is_free ? 'text-green-400' : 'text-white'">
                                        {{ dynamicFee.is_free ? 'FREE' : `${dynamicFee.total.toLocaleString()} TPIX` }}
                                    </span>
                                </div>

                                <p class="text-xs text-gray-500 mt-2">
                                    Fee is calculated based on selected options — more features = higher fee
                                </p>
                                <p class="text-[10px] text-gray-600">
                                    ค่าธรรมเนียมคำนวณจากออฟชั่นที่คุณเลือก — ยิ่งฟีเจอร์เยอะ ค่าสร้างยิ่งเพิ่ม
                                </p>
                            </div>

                            <!-- Testnet FREE highlight in fee area -->
                            <div v-if="isTestnet" class="p-4 rounded-xl bg-green-500/10 border border-green-500/30 flex items-center gap-3">
                                <span class="text-2xl">🎉</span>
                                <div>
                                    <p class="text-sm font-bold text-green-400">Testnet — FREE / ไม่มีค่าธรรมเนียม!</p>
                                    <p class="text-xs text-green-400/70">Your token will be deployed on {{ selectedChainName }} for free (testing only)</p>
                                    <p class="text-[10px] text-green-400/60">Token จะถูก deploy บน {{ selectedChainName }} ฟรี สำหรับทดสอบเท่านั้น</p>
                                </div>
                            </div>

                            <!-- Process Info -->
                            <div class="p-4 rounded-xl bg-blue-500/5 border border-blue-500/10 text-xs space-y-1">
                                <p class="text-blue-300/80">After submission, our team will review your token within 24 hours. Once approved, it will be deployed on <span class="font-semibold text-blue-300">{{ selectedChainName }}</span> automatically.</p>
                                <p class="text-blue-300/50 text-[10px]">หลังจากส่ง ทีมงานจะรีวิวเหรียญของคุณภายใน 24 ชั่วโมง เมื่ออนุมัติจะ deploy บน {{ selectedChainName }} อัตโนมัติ</p>
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
                            Back
                        </button>
                        <div v-else></div>

                        <!-- Next / Submit -->
                        <button
                            v-if="wizardStep < totalSteps"
                            @click="nextStep"
                            :disabled="!stepValid"
                            class="flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-semibold btn-primary disabled:opacity-40 disabled:cursor-not-allowed transition-all"
                        >
                            Next
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
                            <span v-if="isSubmitting">Creating...</span>
                            <span v-else>Create Token</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ===================== MY TOKENS TAB ===================== -->
            <div v-if="activeTab === 'my-tokens'" class="pb-16">
                <div v-if="!walletStore.isConnected" class="text-center py-16">
                    <p class="text-gray-400 mb-4">Connect your wallet to view your tokens.</p>
                    <button @click="walletStore.openConnectModal()" class="btn-primary px-6 py-2.5">Connect Wallet</button>
                </div>

                <div v-else-if="factoryStore.myTokens.length === 0" class="text-center py-16">
                    <svg class="w-16 h-16 text-gray-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                    <p class="text-gray-400 mb-4">You haven't created any tokens yet.</p>
                    <button @click="activeTab = 'create'" class="btn-primary px-6">Create Your First Token</button>
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
                                {{ token.status }}
                            </span>
                        </div>

                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-400">Supply</span>
                                <span class="text-white font-mono">{{ formatSupply(token.total_supply) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Type</span>
                                <span class="text-white">{{ getTypeLabel(token.token_type) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Decimals</span>
                                <span class="text-white">{{ token.decimals }}</span>
                            </div>
                            <div v-if="token.contract_address" class="flex justify-between">
                                <span class="text-gray-400">Contract</span>
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
                            Reason: {{ token.reject_reason }}
                        </div>

                        <div v-if="token.status === 'failed'" class="mt-3 p-2 rounded-lg bg-red-500/10 text-xs text-red-400">
                            Deployment failed. Our team will retry automatically or contact you.
                        </div>

                        <div v-if="token.status === 'deploying'" class="mt-3 p-2 rounded-lg bg-blue-500/10 text-xs text-blue-400 flex items-center gap-2">
                            <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            Deploying to TPIX Chain...
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===================== EXPLORE TAB ===================== -->
            <div v-if="activeTab === 'explore'" class="pb-16">
                <div v-if="factoryStore.tokens.length === 0" class="text-center py-16">
                    <p class="text-gray-400">No deployed tokens yet. Be the first to create one!</p>
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
                            <div v-if="token.is_verified" class="ml-auto" title="Verified">
                                <svg class="w-5 h-5 text-blue-400" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                </svg>
                            </div>
                        </div>

                        <p v-if="token.description" class="text-xs text-gray-400 mb-3 line-clamp-2">{{ token.description }}</p>

                        <div class="space-y-1.5 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-400">Supply</span>
                                <span class="text-white font-mono">{{ formatSupply(token.total_supply) }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-400">Contract</span>
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
                    </div>
                </div>
            </div>
        </section>
    </AppLayout>
</template>
