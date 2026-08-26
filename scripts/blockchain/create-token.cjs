#!/usr/bin/env node
/**
 * Create a token via TPIX Token Factory V2 contracts
 * Called by PHP backend (Web3DeploymentService)
 *
 * รองรับทุก token type:
 *   ERC-20: standard, mintable, burnable, mintable_burnable, utility, reward, governance, stablecoin
 *   ERC-721: nft, nft_collection
 *
 * Input: Environment variables + CLI args as JSON
 * Output: JSON to stdout
 *
 * Env vars:
 *   DEPLOYER_PRIVATE_KEY       - Wallet private key for signing
 *   TOKEN_FACTORY_ADDRESS      - V1 factory (legacy)
 *   TOKEN_FACTORY_V2_ADDRESS   - V2 ERC-20 factory
 *   NFT_FACTORY_ADDRESS        - NFT factory
 *   TPIX_RPC_URL               - RPC endpoint
 *
 * CLI: node create-token.js '{"name":"My Token","symbol":"MTK",...}'
 *
 * Developed by Xman Studio
 */

const { ethers } = require('ethers');
const path = require('path');
const fs = require('fs');

// ═══════════════════════════════════════════
//  ABI LOADING
// ═══════════════════════════════════════════

/**
 * โหลด ABI ของแฟกทอรี
 *
 * ไฟล์ใน scripts/blockchain/abi/ generate มาจาก artifacts ที่ hardhat คอมไพล์จริง
 * ใน TPIX-Coin (ดู manifest.json) — ห้ามแก้ด้วยมือ ถ้าสัญญาเปลี่ยนให้ generate ใหม่
 *
 * เดิมชี้ไป ../../artifacts/contracts/factory/... ซึ่งเป็นสำเนาเก่าที่ค้างอยู่ใน repo นี้
 * ไม่มีอะไรผูกให้มันตรงกับสัญญาที่ deploy จริง — สัญญาขยับเมื่อไหร่ก็เพี้ยนเงียบ ๆ
 */
function loadABI(contractName) {
    const abiPath = path.resolve(__dirname, 'abi', `${contractName}.json`);

    if (!fs.existsSync(abiPath)) {
        throw new Error(`ไม่พบ ABI ของ ${contractName} ที่ ${abiPath}`);
    }

    return JSON.parse(fs.readFileSync(abiPath, 'utf8')).abi;
}

// ═══════════════════════════════════════════
//  TOKEN TYPE ROUTING
// ═══════════════════════════════════════════

const ERC20_TYPES = ['standard', 'mintable', 'burnable', 'mintable_burnable'];
const UTILITY_TYPE = 'utility';
const REWARD_TYPE = 'reward';
const GOVERNANCE_TYPE = 'governance';
const STABLECOIN_TYPE = 'stablecoin';
const NFT_TYPE = 'nft';
const NFT_COLLECTION_TYPE = 'nft_collection';

function getFactoryInfo(tokenType) {
    if (ERC20_TYPES.includes(tokenType)) {
        return {
            envKey: 'TOKEN_FACTORY_V2_ADDRESS',
            fallbackEnvKey: 'TOKEN_FACTORY_ADDRESS',
            abiPath: 'TPIXTokenFactoryV2',
            fallbackAbiPath: 'TPIXTokenFactory',
            category: 'erc20v2',
        };
    }
    if (tokenType === UTILITY_TYPE) {
        return {
            envKey: 'TOKEN_FACTORY_V2_ADDRESS',
            abiPath: 'TPIXTokenFactoryV2',
            category: 'utility',
        };
    }
    if (tokenType === REWARD_TYPE) {
        return {
            envKey: 'TOKEN_FACTORY_V2_ADDRESS',
            abiPath: 'TPIXTokenFactoryV2',
            category: 'reward',
        };
    }
    if (tokenType === GOVERNANCE_TYPE) {
        return {
            envKey: 'TOKEN_FACTORY_V2_ADDRESS',
            abiPath: 'TPIXTokenFactoryV2',
            category: 'governance',
        };
    }
    if (tokenType === STABLECOIN_TYPE) {
        return {
            envKey: 'TOKEN_FACTORY_V2_ADDRESS',
            abiPath: 'TPIXTokenFactoryV2',
            category: 'stablecoin',
        };
    }
    if (tokenType === NFT_TYPE) {
        return {
            envKey: 'NFT_FACTORY_ADDRESS',
            abiPath: 'TPIXNFTFactory',
            category: 'nft',
        };
    }
    if (tokenType === NFT_COLLECTION_TYPE) {
        return {
            envKey: 'NFT_FACTORY_ADDRESS',
            abiPath: 'TPIXNFTFactory',
            category: 'nft_collection',
        };
    }

    throw new Error(`Unknown token type: ${tokenType}`);
}

// ═══════════════════════════════════════════
//  HELPERS
// ═══════════════════════════════════════════

// ═══════════════════════════════════════════
//  แปลงออฟชั่นจากหน้าเว็บให้เป็นค่าที่สัญญาเข้าใจ
// ═══════════════════════════════════════════
//
// หน้าเว็บ (resources/js/Pages/TokenFactory.vue) ส่ง sub_options มาทั้งก้อน
// **รวมออฟชั่นที่ผู้ใช้ปิดสวิตช์ไว้ด้วย** เพราะมันส่ง subOptions.value ตรง ๆ ไม่ได้กรอง show_if
//
// ก่อนหน้านี้สคริปต์นี้อ่านคีย์คนละชื่อกับที่หน้าเว็บส่ง และไม่เคยดูสวิตช์เปิด/ปิดเลย
// ผลที่เกิดขึ้นจริง:
//   · ปิดสวิตช์ภาษีไว้ แต่ยังโดนภาษีซื้อ 3% ขาย 5% (ค่า default ที่ส่งติดมา)
//   · ติ๊ก blacklist_enabled แล้วไม่มีผล (สคริปต์อ่าน sub.blacklist)
//   · ติ๊ก royalty_enabled แล้วไม่มีผล (สคริปต์อ่าน sub.royalty)
//   · อัตรา auto burn 1% กลายเป็น 0.01% (ลืมคูณ 100 เป็น bps)
//   · ระยะกันบอท 30 "นาที" ถูกส่งเป็น 30 "วินาที"
//   · เลือก "รับฟรี" (free_claim) แล้วได้โหมดขายสาธารณะแทน
//   · เลือก "ไม่มีเจ้าของ" (renounced) แล้วเหรียญยังมีเจ้าของอยู่
//
// ทุกตัวแปลงข้างล่างนี้จับคู่กับคีย์จริงบนหน้าเว็บ และเก็บชื่อเก่าไว้เป็น alias
// เผื่อมีของเก่าค้างอยู่ใน DB หรือมีคนเรียก API ตรง

/** อ่านค่าจากคีย์แรกที่มีจริง (undefined/null ถือว่าไม่มี) */
function pick(sub, ...keys) {
    for (const k of keys) {
        if (sub[k] !== undefined && sub[k] !== null && sub[k] !== '') return sub[k];
    }
    return undefined;
}

function bool(sub, ...keys) {
    const v = pick(sub, ...keys);
    return v === true || v === 'true' || v === 1 || v === '1';
}

function num(sub, fallback, ...keys) {
    const v = pick(sub, ...keys);
    const n = Number(v);
    return Number.isFinite(n) ? n : fallback;
}

/** เปอร์เซ็นต์ → basis points (3% → 300) */
function pctToBps(value) {
    const n = Number(value);
    if (!Number.isFinite(n) || n <= 0) return 0;
    return Math.round(n * 100);
}

/**
 * ออฟชั่นที่หน้าเว็บให้เลือกได้แต่สัญญาชุดนี้ยังไม่รองรับ
 * ต้องรายงานกลับ ไม่ใช่กลืนหายเงียบ ๆ แล้วปล่อยให้ผู้ใช้เข้าใจว่าได้ของครบ
 */
const UNSUPPORTED = {
    auto_lp_enabled: 'สภาพคล่องอัตโนมัติ (auto LP) — สัญญายังไม่มีฟังก์ชันเติม LP ให้อัตโนมัติ',
    treasury_enabled: 'คลังเงิน DAO — ต้องใช้สัญญา Governor แยกต่างหาก',
    voting_type_quadratic: 'โหวตแบบ quadratic — สัญญานับคะแนนตามจำนวนเหรียญเท่านั้น',
    metadata_storage_onchain: 'เก็บ metadata บนเชน — สัญญาเก็บได้แค่ URI',
    peg_config: 'ค่าที่ผูก / อัตราผูก / ประเภทหลักประกัน — เป็นข้อมูลประกอบ ไม่ได้บังคับบนสัญญา',
    renounced: 'เลือก "ไม่มีเจ้าของ" ไว้ — เซิร์ฟเวอร์สละสิทธิ์แทนไม่ได้ ' +
        'ต้องกด renounceOwnership() เองจากกระเป๋าที่เป็นเจ้าของเหรียญ',
};

function collectUnsupported(sub, tokenType) {
    const out = [];
    if (bool(sub, 'auto_lp_enabled')) out.push(UNSUPPORTED.auto_lp_enabled);
    if (bool(sub, 'treasury_enabled')) out.push(UNSUPPORTED.treasury_enabled);
    if (pick(sub, 'voting_type') === 'quadratic') out.push(UNSUPPORTED.voting_type_quadratic);
    if (pick(sub, 'metadata_storage') === 'onchain') out.push(UNSUPPORTED.metadata_storage_onchain);
    if (tokenType === 'stablecoin' && (pick(sub, 'peg_currency') || pick(sub, 'reserve_type'))) {
        out.push(UNSUPPORTED.peg_config);
    }
    // สัญญาตั้ง owner_ เป็นกระเป๋าผู้ใช้ตั้งแต่วินาทีแรก การสละสิทธิ์ต้องเซ็นด้วยคีย์ของเจ้าของ
    // เซิร์ฟเวอร์ไม่มีคีย์นั้น จึงทำแทนไม่ได้ — ต้องบอกผู้ใช้ให้ไปกดเอง ไม่ใช่เงียบ
    if (pick(sub, 'ownership_type') === 'renounced') out.push(UNSUPPORTED.renounced);
    return out;
}



/**
 * แปลงจำนวนใบของ NFT กลับเป็นจำนวนนับ
 *
 * ฝั่ง PHP แปลง total_supply เป็นหน่วยเล็กสุดด้วย 10^decimals ก่อนส่งมาเสมอ
 * ซึ่งถูกสำหรับ ERC-20 แต่ NFT ต้องการ "จำนวนใบ" ตรง ๆ
 * หน้าเว็บตั้ง decimals = 0 ให้ตอนเลือกหมวด NFT อยู่แล้ว แต่ถ้าใครแก้ค่าเอง
 * (หรือเรียก API ตรง) ตัวเลขจะพองขึ้น 10^18 เท่า จนเพดานจำนวนใบไร้ความหมาย
 */
function normalizeNftCount(totalSupply, decimals, fallback) {
    if (!totalSupply || totalSupply === '0') return fallback;

    let n = BigInt(totalSupply);
    const d = Number(decimals || 0);
    if (d > 0) {
        n = n / 10n ** BigInt(d);
    }
    return n > 0n ? n : fallback;
}

// ═══════════════════════════════════════════
//  DEPLOY FUNCTIONS
// ═══════════════════════════════════════════

/**
 * Deploy basic ERC-20 via V2 factory (standard/mintable/burnable)
 */
async function deployERC20V2(factory, input) {
    const sub = input.subOptions || {};

    const tokenTypeInt = { standard: 0, mintable: 1, burnable: 2, mintable_burnable: 3 }[input.tokenType] || 0;
    const mintable = tokenTypeInt === 1 || tokenTypeInt === 3;
    const burnable = tokenTypeInt === 2 || tokenTypeInt === 3;

    const tx = await factory.createERC20V2(
        input.name,
        input.symbol,
        input.decimals,
        input.totalSupply,
        input.tokenOwner,
        mintable,
        burnable,
        bool(sub, 'pausable'),
        bool(sub, 'blacklist_enabled', 'blacklist'),
        // เพดาน mint มีผลเฉพาะเมื่อเปิดสวิตช์ไว้ ไม่งั้นค่า default ที่ติดมาจะกลายเป็นเพดานจริง
        bool(sub, 'mint_cap_enabled') && num(sub, 0, 'mint_cap') > 0
            ? ethers.parseUnits(String(num(sub, 0, 'mint_cap')), input.decimals)
            : 0n,
        bool(sub, 'auto_burn_enabled', 'auto_burn'),
        // หน้าเว็บส่งมาเป็นเปอร์เซ็นต์ (0.1–10) สัญญารับเป็น basis points
        bool(sub, 'auto_burn_enabled', 'auto_burn') ? pctToBps(num(sub, 0, 'auto_burn_rate')) : 0,
        num(sub, 0, 'burn_floor') > 0
            ? ethers.parseUnits(String(num(sub, 0, 'burn_floor')), input.decimals)
            : 0n,
        { gasPrice: 0 }
    );

    return tx;
}

/**
 * Deploy basic ERC-20 via V1 factory (fallback สำหรับ backward compat)
 */
async function deployERC20V1(factory, input) {
    const tokenTypeInt = { standard: 0, mintable: 1, burnable: 2, mintable_burnable: 3 }[input.tokenType] || 0;

    const tx = await factory.createToken(
        input.name,
        input.symbol,
        input.decimals,
        input.totalSupply,
        input.tokenOwner,
        tokenTypeInt,
        { gasPrice: 0 }
    );

    return tx;
}

/**
 * Deploy Utility Token
 */
async function deployUtilityToken(factory, input) {
    const sub = input.subOptions || {};

    // ปิดสวิตช์ไหน = ค่าในกลุ่มนั้นต้องเป็น 0 ทั้งหมด
    // หน้าเว็บส่งค่า default ของออฟชั่นที่ซ่อนอยู่มาด้วยเสมอ ถ้าไม่ดูสวิตช์
    // คนที่ปิดภาษีไว้จะได้เหรียญที่เก็บภาษีซื้อ 3% ขาย 5% โดยไม่รู้ตัว
    const taxOn = bool(sub, 'tax_enabled');
    const whaleOn = bool(sub, 'anti_whale_enabled');
    const botOn = bool(sub, 'anti_bot_enabled');

    const taxConfig = {
        buyTaxBps: taxOn ? pctToBps(num(sub, 0, 'buy_tax_rate')) : 0,
        sellTaxBps: taxOn ? pctToBps(num(sub, 0, 'sell_tax_rate')) : 0,
        transferTaxBps: taxOn ? pctToBps(num(sub, 0, 'transfer_tax_rate')) : 0,
        taxWallet: pick(sub, 'tax_wallet') || input.tokenOwner,
        marketingWallet: pick(sub, 'marketing_wallet') || input.tokenOwner,
        marketingShareBps: taxOn ? pctToBps(num(sub, 0, 'marketing_share')) : 0,
    };

    const protectionConfig = {
        maxWalletBps: whaleOn ? pctToBps(num(sub, 0, 'max_wallet_percent')) : 0,
        maxTxBps: whaleOn ? pctToBps(num(sub, 0, 'max_tx_percent')) : 0,
        // หน้าเว็บถามเป็น "นาที" สัญญานับเป็นวินาที — เดิมส่งเลขดิบไปเลย
        // ผู้ใช้ตั้งกันบอท 30 นาที ได้จริง 30 วินาที
        antiBotDuration: botOn ? Math.round(num(sub, 0, 'anti_bot_duration') * 60) : 0,
        tradingCooldown: botOn ? Math.round(num(sub, 0, 'trading_cooldown')) : 0,
    };

    const tx = await factory.createUtilityToken(
        input.name,
        input.symbol,
        input.decimals,
        input.totalSupply,
        input.tokenOwner,
        bool(sub, 'mintable'),
        bool(sub, 'burnable'),
        bool(sub, 'pausable'),
        bool(sub, 'blacklist_enabled', 'blacklist'),
        taxConfig,
        protectionConfig,
        { gasPrice: 0 }
    );

    return tx;
}

/**
 * Deploy Reward Token
 */
async function deployRewardToken(factory, input) {
    const sub = input.subOptions || {};

    // enum ในสัญญา: 0 = reflection, 1 = dividend, 2 = staking
    const rewardTypeMap = { reflection: 0, dividend: 1, staking: 2 };
    const rewardTypeName = pick(sub, 'reward_type') || 'reflection';
    const rewardType = rewardTypeMap[rewardTypeName];
    if (rewardType === undefined) {
        throw new Error(`ประเภทรางวัลไม่รู้จัก: ${rewardTypeName}`);
    }

    // หน้าเว็บใช้คีย์ reflection_rate (ของเดิมอ่าน reward_rate ซึ่งไม่เคยถูกส่งมา
    // จึงตกไปใช้ค่า default 2% ทุกใบ ไม่ว่าผู้ใช้จะเลื่อนสไลเดอร์ไว้เท่าไหร่)
    const rewardRateBps = pctToBps(num(sub, 3, 'reflection_rate', 'reward_rate'));

    const vestingOn = bool(sub, 'vesting_enabled');

    const tx = await factory.createRewardToken(
        input.name,
        input.symbol,
        input.decimals,
        input.totalSupply,
        input.tokenOwner,
        bool(sub, 'mintable'),
        bool(sub, 'burnable'),
        bool(sub, 'pausable'),
        bool(sub, 'blacklist_enabled', 'blacklist'),
        rewardType,
        rewardRateBps,
        num(sub, 0, 'min_hold_for_reward') > 0
            ? ethers.parseUnits(String(num(sub, 0, 'min_hold_for_reward')), input.decimals)
            : 0n,
        vestingOn ? Math.round(num(sub, 0, 'vesting_cliff_days') * 86400) : 0,
        vestingOn ? Math.round(num(sub, 0, 'vesting_duration_days') * 86400) : 0,
        { gasPrice: 0 }
    );

    return tx;
}

/**
 * Deploy Governance Token
 */
async function deployGovernanceToken(factory, input) {
    const sub = input.subOptions || {};

    const tx = await factory.createGovernanceToken(
        input.name,
        input.symbol,
        input.decimals,
        input.totalSupply,
        input.tokenOwner,
        bool(sub, 'mintable'),
        bool(sub, 'burnable'),
        bool(sub, 'pausable'),
        bool(sub, 'blacklist_enabled', 'blacklist'),
        // หน้าเว็บใช้คีย์ delegation_enabled (default true)
        pick(sub, 'delegation_enabled', 'delegation') === undefined
            ? true
            : bool(sub, 'delegation_enabled', 'delegation'),
        bool(sub, 'mint_cap_enabled') && num(sub, 0, 'mint_cap') > 0
            ? ethers.parseUnits(String(num(sub, 0, 'mint_cap')), input.decimals)
            : 0n,
        num(sub, 0, 'proposal_threshold') > 0
            ? ethers.parseUnits(String(num(sub, 0, 'proposal_threshold')), input.decimals)
            : 0n,
        pctToBps(num(sub, 4, 'quorum_percent')),
        Math.round(num(sub, 3, 'voting_period_days') * 86400),
        { gasPrice: 0 }
    );

    return tx;
}

/**
 * Deploy Stablecoin Token
 */
async function deployStablecoinToken(factory, input) {
    const sub = input.subOptions || {};

    const tx = await factory.createStablecoinToken(
        input.name,
        input.symbol,
        input.decimals,
        input.totalSupply,
        input.tokenOwner,
        pick(sub, 'reserve_wallet') || input.tokenOwner,
        bool(sub, 'pausable'),
        bool(sub, 'freeze_enabled', 'freeze'),
        bool(sub, 'kyc_required', 'kyc'),
        { gasPrice: 0 }
    );

    return tx;
}

/**
 * Deploy Single NFT
 */
async function deployNFT(factory, input) {
    const sub = input.subOptions || {};

    // จำนวนใบของ NFT เป็น "จำนวนนับ" ไม่ใช่ยอดที่คูณทศนิยมมาแล้ว
    // ถ้าฝั่ง PHP เผลอคูณ 10^decimals มา (เช่นผู้ใช้เปลี่ยน decimals เป็น 18 เอง)
    // maxSupply จะกลายเป็นเลขมหาศาลจนเพดานไม่มีความหมาย
    const nftMaxSupply = normalizeNftCount(input.totalSupply, input.decimals, 1n);

    const tx = await factory.createNFT(
        input.name,
        input.symbol,
        input.tokenOwner,
        pick(sub, 'metadata_uri') || input.logoUrl || '',
        nftMaxSupply,
        bool(sub, 'mintable'),
        bool(sub, 'soulbound'),
        bool(sub, 'royalty_enabled', 'royalty'),
        pick(sub, 'royalty_wallet', 'royalty_recipient') || input.tokenOwner,
        bool(sub, 'royalty_enabled', 'royalty') ? pctToBps(num(sub, 0, 'royalty_rate')) : 0,
        { gasPrice: 0 }
    );

    return tx;
}

/**
 * Deploy NFT Collection
 */
async function deployNFTCollection(factory, input) {
    const sub = input.subOptions || {};

    // enum ในสัญญา: 0 = public, 1 = whitelist, 2 = free
    // หน้าเว็บส่งค่า 'free_claim' (ของเดิมรับแค่ 'free' → ตกไปเป็น public
    // คอลเลกชันที่ตั้งใจแจกฟรีจึงกลายเป็นขายตามราคาที่ตั้งไว้)
    const mintTypeMap = { public: 0, whitelist: 1, free: 2, free_claim: 2 };
    const mintTypeName = pick(sub, 'mint_type') || 'public';
    const mintType = mintTypeMap[mintTypeName];
    if (mintType === undefined) {
        throw new Error(`ประเภทการ mint ไม่รู้จัก: ${mintTypeName}`);
    }

    const collectionMaxSupply = normalizeNftCount(input.totalSupply, input.decimals, 10000n);

    const tx = await factory.createNFTCollection(
        input.name,
        input.symbol,
        input.tokenOwner,
        collectionMaxSupply,
        mintType,
        // แจกฟรีต้องราคา 0 เสมอ ไม่ว่าจะเผลอกรอกราคาไว้หรือไม่
        mintType === 2 ? 0n : ethers.parseEther(String(num(sub, 0, 'mint_price'))),
        Math.round(num(sub, 0, 'max_per_wallet')),
        Math.round(num(sub, 0, 'max_per_tx')),
        Math.round(num(sub, 0, 'reserve_count')),
        // หน้าเว็บใช้ metadata_uri เป็นช่องเดียวสำหรับที่อยู่ metadata
        // ถ้าไม่ส่ง base_uri มา tokenURI ของทุกใบจะว่างเปล่า
        pick(sub, 'base_uri', 'metadata_uri') || input.logoUrl || '',
        pick(sub, 'placeholder_uri') || '',
        bool(sub, 'delayed_reveal'),
        bool(sub, 'royalty_enabled', 'royalty'),
        pick(sub, 'royalty_wallet', 'royalty_recipient') || input.tokenOwner,
        bool(sub, 'royalty_enabled', 'royalty') ? pctToBps(num(sub, 0, 'royalty_rate')) : 0,
        { gasPrice: 0 }
    );

    return tx;
}

// ═══════════════════════════════════════════
//  MAIN
// ═══════════════════════════════════════════

/**
 * อ่าน payload จาก stdin (ทางหลัก) หรือ argv[2] (ไว้รันมือ/ดีบัก)
 *
 * PHP ส่งมาทาง stdin เพราะ escapeshellarg() บน Windows ถอดเครื่องหมาย " ออกจาก JSON
 * และชื่อเหรียญที่มีอัญประกาศ/อีโมจิ ก็เสี่ยงเพี้ยนตอนผ่าน shell ทุกแพลตฟอร์ม
 */
function readPayload() {
    if (process.argv[2]) return process.argv[2];

    try {
        return fs.readFileSync(0, 'utf8');
    } catch {
        throw new Error('ไม่ได้รับ payload — ส่งมาทาง stdin หรือเป็น argument ตัวแรก');
    }
}

async function main() {
    const raw = readPayload().trim();
    if (!raw) throw new Error('payload ว่างเปล่า');

    let input;
    try {
        input = JSON.parse(raw);
    } catch (e) {
        throw new Error(`payload ไม่ใช่ JSON ที่ถูกต้อง: ${e.message}`);
    }

    const {
        name,
        symbol,
        decimals = 18,
        totalSupply,
        tokenOwner,
        tokenType = 'standard',
        subOptions = {},
        logoUrl = '',
    } = input;

    if (!name || !symbol || !tokenOwner) {
        throw new Error('Missing required fields: name, symbol, tokenOwner');
    }

    // Read config from environment
    const privateKey = process.env.DEPLOYER_PRIVATE_KEY;

    // rpc.tpix.online อยู่หลัง Cloudflare bot rule ส่วน rpc1 ไม่มีด่านนั้น
    // ใส่ UA แล้วตัวแรกใช้ได้ แต่ถ้าวันไหนกฎรัดขึ้นก็ยังมีตัวสำรองให้สลับ
    const rpcUrls = [
        process.env.TPIX_RPC_URL || 'https://rpc.tpix.online',
        'https://rpc1.tpix.online',
    ].filter((v, i, a) => v && a.indexOf(v) === i);

    if (!privateKey) throw new Error('DEPLOYER_PRIVATE_KEY not set');

    // ── เชื่อมต่อ TPIX Chain ───────────────────────────────────────
    //
    // ⚠️ ต้องส่ง User-Agent เสมอ — rpc.tpix.online อยู่หลัง Cloudflare bot rule
    //    ที่ตอบ 403 ให้ client ที่ไม่มี UA (ยืนยันสด 2026-08-27)
    //
    //    ethers ไม่ได้ล้มทันทีเมื่อโดน 403 แต่จะวน "failed to detect network, retry in 1s"
    //    ไปเรื่อย ๆ จนกว่า Process::timeout(120) ของ PHP จะฆ่าทิ้ง ผู้ใช้เห็นแค่
    //    "Deployment script failed" โดยไม่มีทางรู้ว่าติดที่ด่านบอทของ Cloudflare
    function makeProvider(url) {
        const req = new ethers.FetchRequest(url);
        req.setHeader('User-Agent', 'TPIX-TRADE-TokenFactory/1.0 (+https://tpix.online)');
        req.setHeader('Content-Type', 'application/json');
        req.timeout = 15000;
        return new ethers.JsonRpcProvider(req, { chainId: 4289, name: 'tpix' }, { staticNetwork: true });
    }

    let provider = null;
    const rpcErrors = [];
    for (const url of rpcUrls) {
        try {
            const candidate = makeProvider(url);
            await candidate.getBlockNumber();   // ยืนยันว่าคุยได้จริงก่อนใช้
            provider = candidate;
            break;
        } catch (e) {
            rpcErrors.push(`${url}: ${(e.shortMessage || e.message || '').slice(0, 60)}`);
        }
    }
    if (!provider) {
        throw new Error(`ต่อ RPC ของเชนไม่ได้เลยสักตัว — ${rpcErrors.join(' · ')}`);
    }

    const wallet = new ethers.Wallet(privateKey, provider);

    // Get factory info
    const factoryInfo = getFactoryInfo(tokenType);
    let factoryAddress = process.env[factoryInfo.envKey];

    // Fallback to V1 factory for basic ERC-20 types
    let useV1 = false;
    if (!factoryAddress && factoryInfo.fallbackEnvKey) {
        factoryAddress = process.env[factoryInfo.fallbackEnvKey];
        useV1 = true;
    }

    if (!factoryAddress) {
        throw new Error(`Factory address not configured (${factoryInfo.envKey})`);
    }

    // Load factory ABI
    const abiPath = useV1 ? factoryInfo.fallbackAbiPath : factoryInfo.abiPath;
    const factoryABI = loadABI(abiPath);

    // แฟกทอรีต้องมีโค้ดอยู่ที่ address นั้นจริง
    // เชน TPIX เคย regenesis (6 ส.ค. 2026) แล้วสัญญาหายเกลี้ยงทั้งที่ address ยังค้างใน .env
    // ถ้าไม่เช็ก tx จะถูกส่งไปหา address ว่างเปล่าแล้ว "สำเร็จ" โดยไม่มีเหรียญเกิดขึ้น
    const factoryCode = await provider.getCode(factoryAddress);
    if (factoryCode === '0x') {
        throw new Error(
            `ไม่มีสัญญาอยู่ที่ ${factoryAddress} บนเชน 4289 — ` +
            `แฟกทอรียังไม่ได้ deploy หรือหายไปตอน regenesis (ตรวจ ${factoryInfo.envKey} ใน .env)`
        );
    }

    const factory = new ethers.Contract(factoryAddress, factoryABI, wallet);

    // Build deploy input
    const deployInput = {
        name,
        symbol,
        decimals,
        totalSupply: totalSupply || '0',
        tokenOwner,
        tokenType,
        subOptions,
        logoUrl,
    };

    // Route to correct deploy function
    let tx;

    if (useV1) {
        tx = await deployERC20V1(factory, deployInput);
    } else {
        switch (factoryInfo.category) {
            case 'erc20v2':
                tx = await deployERC20V2(factory, deployInput);
                break;
            case 'utility':
                tx = await deployUtilityToken(factory, deployInput);
                break;
            case 'reward':
                tx = await deployRewardToken(factory, deployInput);
                break;
            case 'governance':
                tx = await deployGovernanceToken(factory, deployInput);
                break;
            case 'stablecoin':
                tx = await deployStablecoinToken(factory, deployInput);
                break;
            case 'nft':
                tx = await deployNFT(factory, deployInput);
                break;
            case 'nft_collection':
                tx = await deployNFTCollection(factory, deployInput);
                break;
            default:
                throw new Error(`No deploy handler for category: ${factoryInfo.category}`);
        }
    }

    // Wait for confirmation
    const receipt = await tx.wait();

    // Parse TokenCreated / NFTCreated event from logs
    const iface = new ethers.Interface(factoryABI);
    let tokenAddress = null;

    for (const log of receipt.logs) {
        try {
            const parsed = iface.parseLog({ topics: log.topics, data: log.data });
            if (parsed && (parsed.name === 'TokenCreated' || parsed.name === 'NFTCreated')) {
                tokenAddress = parsed.args.tokenAddress || parsed.args.nftAddress || parsed.args[0];
                break;
            }
        } catch {
            // Skip logs from other contracts
        }
    }

    if (!tokenAddress) {
        throw new Error('ไม่พบ event ตอนสร้างเหรียญใน receipt — สัญญาอาจไม่ได้สร้างอะไรขึ้นมาจริง');
    }

    // event บอกแค่ว่ามีการ emit ไม่ได้บอกว่าเหรียญมีโค้ดอยู่จริง
    // ถามเชนตรง ๆ ก่อนบอกผู้ใช้ว่าสำเร็จ ไม่งั้นเราไปบันทึกลง DB ว่ามีเหรียญที่ไม่มีอยู่
    const tokenCode = await provider.getCode(tokenAddress);
    if (tokenCode === '0x') {
        throw new Error(`สร้างแล้วแต่ไม่มีโค้ดที่ ${tokenAddress} — การสร้างล้มเหลว`);
    }

    // Output result
    const result = {
        success: true,
        contractAddress: tokenAddress,
        txHash: receipt.hash,
        blockNumber: receipt.blockNumber,
        factoryVersion: useV1 ? 'v1' : 'v2',
        category: factoryInfo.category,
        codeSize: (tokenCode.length - 2) / 2,
        // ออฟชั่นที่ผู้ใช้เลือกไว้แต่สัญญายังทำให้ไม่ได้ — ส่งกลับให้หลังบ้านบันทึก/แจ้งผู้ใช้
        // ห้ามกลืนเงียบ ๆ ไม่งั้นผู้ใช้จ่ายค่าออฟชั่นแล้วเข้าใจว่าได้ของครบ
        unsupportedOptions: collectUnsupported(subOptions, tokenType),
    };

    process.stdout.write(JSON.stringify(result));
}

main().catch((error) => {
    const result = {
        success: false,
        error: error.message || String(error),
    };
    process.stdout.write(JSON.stringify(result));
    process.exit(1);
});
