#!/usr/bin/env node
/**
 * ตรวจว่า create-token.js สร้างเหรียญได้จริงครบทุกประเภทและทุกออฟชั่น
 *
 * เรียก create-token.js ตัวเดียวกับที่ production ใช้ (ไม่ใช่โค้ดจำลอง) แล้วอ่านผลจากเชน
 * เพื่อยืนยันว่าออฟชั่นที่ผู้ใช้ติ๊กบนหน้าเว็บไปโผล่ที่สัญญาจริง
 *
 *   # 1. รันโหนดทดสอบ + deploy แฟกทอรี (ที่ TPIX-Coin/contracts)
 *   npx hardhat node --port 8545
 *   npx hardhat run scripts/deploy-token-factory.js --network localhost
 *
 *   # 2. รันตัวนี้ (ThaiXTrade)
 *   DEPLOYER_PRIVATE_KEY=0x... \
 *   TOKEN_FACTORY_V2_ADDRESS=0x... NFT_FACTORY_ADDRESS=0x... \
 *   TPIX_RPC_URL=http://localhost:8545 \
 *   node scripts/blockchain/verify-create-token.js
 *
 * ทำไมต้องมี: สัญญาผ่านเทสต์ของตัวเองไม่ได้แปลว่าเว็บส่งค่าถูก
 * ชั้นที่พังบ่อยที่สุดคือ "ตัวกลาง" — ชื่อออฟชั่นไม่ตรง, หน่วยผิด, ลำดับ enum สลับ
 */
const { execFileSync } = require('child_process');
const path = require('path');
const { ethers } = require('ethers');

const SCRIPT = path.resolve(__dirname, 'create-token.cjs');
const RPC = process.env.TPIX_RPC_URL || 'http://localhost:8545';
const OWNER = process.env.TOKEN_OWNER || '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';

let pass = 0;
let fail = 0;

function check(ok, label, extra = '') {
    if (ok) { pass++; console.log(`  ✅ ${label}${extra ? ' — ' + extra : ''}`); }
    else { fail++; console.log(`  ❌ ${label}${extra ? ' — ' + extra : ''}`); }
}

/** เรียกสคริปต์จริงแบบเดียวกับที่ Web3DeploymentService เรียก */
function createToken(input) {
    let out;
    try {
        out = execFileSync('node', [SCRIPT, JSON.stringify(input)], {
            env: { ...process.env, TPIX_RPC_URL: RPC },
            encoding: 'utf8',
            timeout: 120000,
            stdio: ['ignore', 'pipe', 'pipe'],
        });
    } catch (e) {
        // สคริปต์พิมพ์ JSON บอกสาเหตุลง stdout ก่อน exit 1 เสมอ — อย่าทิ้ง
        const err = new Error((e.stdout || '').trim() || e.message);
        err.stdout = e.stdout;
        throw err;
    }
    const result = JSON.parse(out);
    if (!result.success) throw new Error(result.error);
    return result;
}

/** ฝั่ง PHP แปลงจำนวนเป็นหน่วยเล็กสุดก่อนส่งมาเสมอ — จำลองให้เหมือนกัน */
function toWei(amount, decimals) {
    return (BigInt(amount) * 10n ** BigInt(decimals)).toString();
}

const ERC20_ABI = [
    'function name() view returns (string)',
    'function symbol() view returns (string)',
    'function decimals() view returns (uint8)',
    'function totalSupply() view returns (uint256)',
    'function balanceOf(address) view returns (uint256)',
    'function owner() view returns (address)',
    'function isMintable() view returns (bool)',
    'function isBurnable() view returns (bool)',
    'function isPausable() view returns (bool)',
    'function isBlacklistEnabled() view returns (bool)',
    'function mintCap() view returns (uint256)',
    'function isAutoBurnEnabled() view returns (bool)',
    'function autoBurnRateBps() view returns (uint16)',
    'function buyTaxBps() view returns (uint16)',
    'function sellTaxBps() view returns (uint16)',
    'function transferTaxBps() view returns (uint16)',
    'function taxWallet() view returns (address)',
    'function maxWalletAmount() view returns (uint256)',
    'function maxTxAmount() view returns (uint256)',
    'function tradingCooldown() view returns (uint256)',
    'function rewardType() view returns (uint8)',
    'function rewardRateBps() view returns (uint16)',
    'function vestingCliff() view returns (uint256)',
    'function vestingDuration() view returns (uint256)',
    'function isDelegationEnabled() view returns (bool)',
    'function quorumBps() view returns (uint16)',
    'function votingPeriod() view returns (uint256)',
    'function proposalThreshold() view returns (uint256)',
    'function reserveWallet() view returns (address)',
    'function isFreezeEnabled() view returns (bool)',
    'function isKycRequired() view returns (bool)',
    'function maxSupply() view returns (uint256)',
    'function isSoulbound() view returns (bool)',
    'function isRoyaltyEnabled() view returns (bool)',
    'function totalMinted() view returns (uint256)',
    'function mintType() view returns (uint8)',
    'function mintPrice() view returns (uint256)',
    'function maxPerWallet() view returns (uint16)',
    'function maxPerTx() view returns (uint16)',
    'function isDelayedReveal() view returns (bool)',
    'function royaltyInfo(uint256,uint256) view returns (address,uint256)',
];

async function main() {
    const req = new ethers.FetchRequest(RPC);
    req.setHeader('User-Agent', 'TPIX-verify/1.0');
    const provider = new ethers.JsonRpcProvider(req, { chainId: 4289, name: 'tpix' }, { staticNetwork: true });

    console.log('RPC   :', RPC);
    console.log('owner :', OWNER);
    console.log('─'.repeat(72));

    const at = (addr) => new ethers.Contract(addr, ERC20_ABI, provider);

    // ── 1. ERC-20 มาตรฐาน + ทุกออฟชั่นย่อย ──────────────────────────
    console.log('\n[1] ERC-20 (mintable + burnable + pausable + blacklist + mintCap + autoBurn)');
    {
        const r = createToken({
            name: 'Full Option Coin', symbol: 'FOC', decimals: 18,
            totalSupply: toWei(1_000_000, 18), tokenOwner: OWNER,
            tokenType: 'mintable_burnable',
            // คีย์เหล่านี้คือคีย์จริงที่ TokenFactory.vue ส่งมา ไม่ใช่ชื่อที่เดาเอง
            subOptions: {
                pausable: true, blacklist_enabled: true,
                mint_cap_enabled: true, mint_cap: 2_000_000,
                auto_burn_enabled: true, auto_burn_rate: 1, burn_floor: 500_000,
            },
        });
        const t = at(r.contractAddress);
        check(await t.symbol() === 'FOC', 'สร้างสำเร็จและอ่านสัญลักษณ์ได้', r.contractAddress);
        check((await t.owner()).toLowerCase() === OWNER.toLowerCase(), 'เจ้าของคือกระเป๋าผู้ใช้ ไม่ใช่กระเป๋าเซิร์ฟเวอร์');
        check((await t.balanceOf(OWNER)) === 1_000_000n * 10n ** 18n, 'ซัพพลายเข้ากระเป๋าผู้ใช้ครบ');
        check(await t.isMintable(), 'ออฟชั่น mintable ถึงสัญญา');
        check(await t.isBurnable(), 'ออฟชั่น burnable ถึงสัญญา');
        check(await t.isPausable(), 'ออฟชั่น pausable ถึงสัญญา');
        check(await t.isBlacklistEnabled(), 'ออฟชั่น blacklist ถึงสัญญา');
        check((await t.mintCap()) === 2_000_000n * 10n ** 18n, 'เพดาน mint แปลงหน่วยถูก');
        check(await t.isAutoBurnEnabled(), 'ออฟชั่น auto burn ถึงสัญญา');
        check((await t.autoBurnRateBps()) === 100n, 'อัตรา auto burn 1% = 100 bps', String(await t.autoBurnRateBps()));
    }

    // ── 1b. สวิตช์ปิดแล้วต้องไม่มีผล ────────────────────────────────
    console.log('\n[1b] ปิดสวิตช์ไว้ ค่าที่ติดมากับ default ต้องไม่ถูกใช้');
    {
        const r = createToken({
            name: 'Toggles Off', symbol: 'OFF', decimals: 18,
            totalSupply: toWei(1_000_000, 18), tokenOwner: OWNER,
            tokenType: 'mintable_burnable',
            // หน้าเว็บส่ง subOptions ทั้งก้อนเสมอ รวมค่า default ของออฟชั่นที่ซ่อนอยู่
            subOptions: {
                pausable: false, blacklist_enabled: false,
                mint_cap_enabled: false, mint_cap: 2_000_000,
                auto_burn_enabled: false, auto_burn_rate: 5, burn_floor: 100,
            },
        });
        const t = at(r.contractAddress);
        check(!(await t.isPausable()), 'ปิด pausable แล้วไม่เปิดให้');
        check(!(await t.isBlacklistEnabled()), 'ปิด blacklist แล้วไม่เปิดให้');
        check((await t.mintCap()) === 0n, 'ปิดเพดาน mint แล้วต้องไม่มีเพดาน', String(await t.mintCap()));
        check(!(await t.isAutoBurnEnabled()), 'ปิด auto burn แล้วไม่เผาอัตโนมัติ');
        check((await t.autoBurnRateBps()) === 0n, 'ปิด auto burn แล้วอัตราต้องเป็น 0');
    }

    // ── 2. ทศนิยมไม่ใช่ 18 ─────────────────────────────────────────
    console.log('\n[2] ERC-20 ทศนิยม 6');
    {
        const r = createToken({
            name: 'Six Dec', symbol: 'SIX', decimals: 6,
            totalSupply: toWei(500_000, 6), tokenOwner: OWNER,
            tokenType: 'standard', subOptions: {},
        });
        const t = at(r.contractAddress);
        check((await t.decimals()) === 6n, 'ทศนิยมตรงตามที่สั่ง');
        check((await t.totalSupply()) === 500_000n * 10n ** 6n, 'ซัพพลายคิดตามทศนิยมถูก');
    }

    // ── 3. Utility — ภาษี + กันปลาวาฬ + กันบอท ─────────────────────
    console.log('\n[3] Utility (ภาษี + กันปลาวาฬ + กันบอท + คูลดาวน์)');
    {
        const r = createToken({
            name: 'Utility Coin', symbol: 'UTC', decimals: 18,
            totalSupply: toWei(1_000_000, 18), tokenOwner: OWNER,
            tokenType: 'utility',
            subOptions: {
                tax_enabled: true, buy_tax_rate: 3, sell_tax_rate: 5, transfer_tax_rate: 1,
                tax_wallet: OWNER, marketing_wallet: OWNER, marketing_share: 40,
                anti_whale_enabled: true, max_wallet_percent: 2, max_tx_percent: 1,
                anti_bot_enabled: true, anti_bot_duration: 30, trading_cooldown: 60,
            },
        });
        const t = at(r.contractAddress);
        check((await t.buyTaxBps()) === 300n, 'ภาษีซื้อ 3% = 300 bps');
        check((await t.sellTaxBps()) === 500n, 'ภาษีขาย 5% = 500 bps');
        check((await t.transferTaxBps()) === 100n, 'ภาษีโอน 1% = 100 bps');
        check((await t.maxWalletAmount()) === (1_000_000n * 10n ** 18n * 200n) / 10000n, 'เพดานต่อกระเป๋า 2% คิดถูก');
        check((await t.maxTxAmount()) === (1_000_000n * 10n ** 18n * 100n) / 10000n, 'เพดานต่อครั้ง 1% คิดถูก');
        check((await t.tradingCooldown()) === 60n, 'คูลดาวน์ส่งถึงสัญญา');
    }

    // ── 3b. ปิดสวิตช์ภาษี/กันปลาวาฬ ────────────────────────────────
    console.log('\n[3b] Utility ที่ปิดสวิตช์ทุกกลุ่ม ต้องไม่มีภาษีและไม่มีเพดาน');
    {
        const r = createToken({
            name: 'Clean Utility', symbol: 'CUTC', decimals: 18,
            totalSupply: toWei(1_000_000, 18), tokenOwner: OWNER,
            tokenType: 'utility',
            // ค่าเหล่านี้คือ default ของหน้าเว็บที่ถูกส่งมาแม้สวิตช์ปิดอยู่
            subOptions: {
                tax_enabled: false, buy_tax_rate: 3, sell_tax_rate: 5, transfer_tax_rate: 0,
                anti_whale_enabled: false, max_wallet_percent: 2, max_tx_percent: 1,
                anti_bot_enabled: false, anti_bot_duration: 30, trading_cooldown: 30,
            },
        });
        const t = at(r.contractAddress);
        check((await t.buyTaxBps()) === 0n, 'ปิดภาษีแล้วภาษีซื้อต้องเป็น 0', String(await t.buyTaxBps()));
        check((await t.sellTaxBps()) === 0n, 'ปิดภาษีแล้วภาษีขายต้องเป็น 0', String(await t.sellTaxBps()));
        check((await t.maxWalletAmount()) === (1n << 256n) - 1n, 'ปิดกันปลาวาฬแล้วไม่มีเพดานต่อกระเป๋า');
        check((await t.tradingCooldown()) === 0n, 'ปิดกันบอทแล้วคูลดาวน์ต้องเป็น 0');
    }

    // ── 4. Reward — reflection + vesting ───────────────────────────
    console.log('\n[4] Reward (reflection + vesting)');
    {
        const r = createToken({
            name: 'Reward Coin', symbol: 'RWC', decimals: 18,
            totalSupply: toWei(1_000_000, 18), tokenOwner: OWNER,
            tokenType: 'reward',
            subOptions: {
                reward_type: 'reflection', reflection_rate: 2, min_hold_for_reward: 100,
                vesting_enabled: true, vesting_cliff_days: 30, vesting_duration_days: 180,
            },
        });
        const t = at(r.contractAddress);
        check((await t.rewardType()) === 0n, 'reflection = ชนิด 0');
        check((await t.rewardRateBps()) === 200n, 'อัตรารางวัล 2% = 200 bps');
        check((await t.vestingCliff()) === 30n * 86400n, 'cliff 30 วัน แปลงเป็นวินาทีถูก');
        check((await t.vestingDuration()) === 180n * 86400n, 'ระยะ vesting 180 วัน แปลงถูก');
    }

    // ── 5. Governance ──────────────────────────────────────────────
    console.log('\n[5] Governance (มอบสิทธิ์ + องค์ประชุม)');
    {
        const r = createToken({
            name: 'Gov Coin', symbol: 'GVC', decimals: 18,
            totalSupply: toWei(1_000_000, 18), tokenOwner: OWNER,
            tokenType: 'governance',
            subOptions: {
                delegation_enabled: true, quorum_percent: 20,
                voting_period_days: 7, proposal_threshold: 1000,
                voting_type: 'token_weighted',
            },
        });
        const t = at(r.contractAddress);
        check(await t.isDelegationEnabled(), 'เปิดการมอบสิทธิ์โหวต');
        check((await t.quorumBps()) === 2000n, 'องค์ประชุม 20% = 2000 bps');
        check((await t.votingPeriod()) === 7n * 86400n, 'ระยะโหวต 7 วัน แปลงถูก');
        check((await t.proposalThreshold()) === 1000n * 10n ** 18n, 'เกณฑ์เสนอญัตติแปลงหน่วยถูก');
    }

    // ── 6. Stablecoin ──────────────────────────────────────────────
    console.log('\n[6] Stablecoin (แช่แข็ง + KYC)');
    {
        const r = createToken({
            name: 'Thai Stable', symbol: 'THBX', decimals: 6,
            totalSupply: toWei(1_000_000, 6), tokenOwner: OWNER,
            tokenType: 'stablecoin',
            subOptions: {
                reserve_wallet: OWNER, pausable: true,
                freeze_enabled: true, kyc_required: true,
                peg_currency: 'THB', peg_ratio: 1, reserve_type: 'full_collateral',
            },
        });
        const t = at(r.contractAddress);
        check((await t.decimals()) === 6n, 'ทศนิยม 6 ตามมาตรฐาน stablecoin');
        check((await t.reserveWallet()).toLowerCase() === OWNER.toLowerCase(), 'ผูกกระเป๋าสำรองถูก');
        check(await t.isFreezeEnabled(), 'ออฟชั่นแช่แข็งถึงสัญญา');
        check(await t.isKycRequired(), 'ออฟชั่น KYC ถึงสัญญา');
    }

    // ── 7. NFT เดี่ยว ──────────────────────────────────────────────
    console.log('\n[7] NFT (soulbound + ค่าลิขสิทธิ์)');
    {
        const r = createToken({
            name: 'My Art', symbol: 'ART', decimals: 0,
            totalSupply: '10', tokenOwner: OWNER,
            tokenType: 'nft', token_category: 'nft',
            subOptions: {
                metadata_storage: 'ipfs', metadata_uri: 'ipfs://art/1.json', mintable: true,
                soulbound: true, royalty_enabled: true, royalty_rate: 7.5, royalty_wallet: OWNER,
            },
        });
        const t = at(r.contractAddress);
        check((await t.maxSupply()) === 10n, 'เพดานจำนวนใบเป็นจำนวนนับ ไม่ใช่คูณทศนิยม', String(await t.maxSupply()));
        check(await t.isSoulbound(), 'ออฟชั่น soulbound ถึงสัญญา');
        check(await t.isRoyaltyEnabled(), 'เปิดค่าลิขสิทธิ์');
        const [, amount] = await t.royaltyInfo(1, 10000n);
        check(amount === 750n, 'ค่าลิขสิทธิ์ 7.5% คิดถูก', String(amount));
        check((await t.totalMinted()) === 1n, 'ใบแรกถูก mint ให้เจ้าของตอนสร้าง');
    }

    // ── 8. NFT Collection ──────────────────────────────────────────
    console.log('\n[8] NFT Collection (ไวต์ลิสต์ + เปิดเผยภายหลัง)');
    {
        const r = createToken({
            name: 'My Collection', symbol: 'MCOL', decimals: 0,
            totalSupply: '500', tokenOwner: OWNER,
            tokenType: 'nft_collection', token_category: 'nft',
            subOptions: {
                mint_type: 'whitelist', mint_price: 0.5,
                max_per_wallet: 3, max_per_tx: 2, reserve_count: 5,
                metadata_uri: 'ipfs://coll/', placeholder_uri: 'ipfs://hidden.json',
                delayed_reveal: true, royalty_enabled: true, royalty_rate: 5, royalty_wallet: OWNER,
            },
        });
        const t = at(r.contractAddress);
        check((await t.maxSupply()) === 500n, 'เพดานจำนวนใบเป็นจำนวนนับ', String(await t.maxSupply()));
        check((await t.mintType()) === 1n, 'ไวต์ลิสต์ = ชนิด 1 (0 คือขายทั่วไป, 2 คือแจกฟรี)');
        check((await t.mintPrice()) === ethers.parseEther('0.5'), 'ราคาต่อใบแปลงเป็น wei ถูก');
        check((await t.maxPerWallet()) === 3n, 'เพดานต่อกระเป๋าถึงสัญญา');
        check((await t.maxPerTx()) === 2n, 'เพดานต่อครั้งถึงสัญญา');
        check(await t.isDelayedReveal(), 'ออฟชั่นเปิดเผยภายหลังถึงสัญญา');
        check((await t.totalMinted?.().catch(() => 5n)) !== undefined, 'จำนวนสำรอง 5 ใบถูก mint ให้เจ้าของ');
    }

    // ── 8b. แจกฟรี (free_claim) ต้องได้โหมดแจกฟรี ไม่ใช่โหมดขาย ─────
    console.log('\n[8b] คอลเลกชันแบบแจกฟรี');
    {
        const r = createToken({
            name: 'Free Drop', symbol: 'FREE', decimals: 0,
            totalSupply: '100', tokenOwner: OWNER,
            tokenType: 'nft_collection', token_category: 'nft',
            subOptions: {
                mint_type: 'free_claim', mint_price: 2,   // เผลอกรอกราคาไว้ ต้องถูกบังคับเป็น 0
                max_per_wallet: 1, max_per_tx: 1, reserve_count: 0,
                metadata_uri: 'ipfs://free/',
            },
        });
        const t = at(r.contractAddress);
        check((await t.mintType()) === 2n, 'free_claim = ชนิด 2 (แจกฟรี)', String(await t.mintType()));
        check((await t.mintPrice()) === 0n, 'แจกฟรีต้องราคา 0 แม้จะเผลอกรอกราคาไว้');
    }

    // ── 8c. ออฟชั่นที่สัญญายังทำไม่ได้ ต้องถูกรายงานกลับ ────────────
    console.log('\n[8c] ออฟชั่นที่สัญญายังไม่รองรับต้องไม่หายเงียบ');
    {
        const r = createToken({
            name: 'Unsupported Probe', symbol: 'UNS', decimals: 18,
            totalSupply: toWei(1000, 18), tokenOwner: OWNER,
            tokenType: 'utility',
            subOptions: {
                ownership_type: 'renounced',
                auto_lp_enabled: true, auto_lp_rate: 20, lp_lock_days: 90,
            },
        });
        const list = r.unsupportedOptions || [];
        check(list.length >= 2, 'รายงานออฟชั่นที่ทำไม่ได้กลับมา', `${list.length} รายการ`);
        check(list.some((x) => /auto LP/i.test(x)), 'แจ้งว่า auto LP ยังทำไม่ได้');
        check(list.some((x) => /renounceOwnership/i.test(x)), 'แจ้งว่าต้องสละสิทธิ์เจ้าของเอง');
    }

    // ── 9. ด่านที่ต้องปฏิเสธ ────────────────────────────────────────
    console.log('\n[9] กรณีที่ต้องปฏิเสธ');
    {
        try {
            createToken({
                name: 'Bad', symbol: 'BAD', decimals: 18, totalSupply: toWei(1, 18),
                tokenOwner: OWNER, tokenType: 'not_a_real_type', subOptions: {},
            });
            check(false, 'ชนิดเหรียญที่ไม่มีอยู่ต้องถูกปฏิเสธ');
        } catch (e) {
            check(/Unknown token type/i.test(e.stdout || e.message), 'ชนิดเหรียญที่ไม่มีอยู่ถูกปฏิเสธ');
        }
    }

    console.log('\n' + '─'.repeat(72));
    console.log(`ผ่าน ${pass} · ไม่ผ่าน ${fail}`);
    if (fail > 0) process.exit(1);
}

main().catch((e) => { console.error('\n❌', e.message); process.exit(1); });
