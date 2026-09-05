/**
 * TPIX TRADE - useTpixDex Composable
 * Swap + สภาพคล่อง on-chain จริงบน TPIX Chain (4289) ผ่าน TPIXDEXRouter02 (AMM แบบ UniV2)
 *
 * Invariants (แบบเดียวกับ useSwap.js ฝั่ง BSC):
 *  - quote อ่านจาก RPC ของ TPIX chain โดยตรง ไม่ใช่ provider ของ wallet
 *    → ได้ราคาแม้ wallet อยู่คนละเชน และไม่ fabricate rate 1:1 เด็ดขาด
 *  - fail-closed: ถ้า DEX ยังไม่ deploy (isDexConfigured() = false) ทุก action
 *    คืน error ชัดเจน ไม่เดา address
 *  - fee ของกระดาน = 0.3% ใน pool (LP + protocol feeTo) — ไม่มี fee transaction
 *    แยกให้ user ปฏิเสธได้เหมือนฝั่ง BSC swap
 *  - ที่อยู่สัญญาโหลดจากทะเบียนบนเซิร์ฟเวอร์ (loadDexConfig) — ห้าม hardcode
 *
 * Developed by Xman Studio
 */

import { ref } from 'vue';
import axios from 'axios';
import { Contract, JsonRpcProvider, parseUnits, formatUnits } from 'ethers';
import { useWalletStore } from '@/Stores/walletStore';
import {
    TPIX_DEX,
    TPIX_DEX_ROUTER_ABI,
    TPIX_DEX_FACTORY_ABI,
    TPIX_DEX_PAIR_ABI,
    TPIX_DEX_ERC20_ABI,
    isDexConfigured,
    loadDexConfig,
} from '@/Config/dexContracts';

const NATIVE = 'native'; // sentinel แทน native TPIX coin ใน path ที่ user เลือก
const ZERO = '0x0000000000000000000000000000000000000000';

/** ค่าธรรมเนียมของพูล (UniV2) — เข้า LP ทั้งก้อน โปรโตคอลได้ส่วนแบ่งผ่าน feeTo ไม่ได้หักเพิ่มจากผู้ใช้ */
export const POOL_FEE_PCT = 0.3;

let _readProvider = null;
let _readProviderRpc = null;
function getTpixReadProvider() {
    if (!_readProvider || _readProviderRpc !== TPIX_DEX.RPC) {
        _readProviderRpc = TPIX_DEX.RPC;
        _readProvider = new JsonRpcProvider(TPIX_DEX.RPC, TPIX_DEX.CHAIN_ID, { staticNetwork: true });
    }
    return _readProvider;
}

/** ที่อยู่ในฐานข้อมูล (0x0 = native) หรือ sentinel → ค่าที่ composable ใช้ */
export function toDexAddress(address) {
    const a = String(address || '').toLowerCase();
    if (a === '' || a === NATIVE || a === ZERO || a === '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee') return NATIVE;
    return a;
}

/** แปลง float เป็น wei — cap precision กัน float garbage (แบบเดียวกับ useSwap) */
function toWei(value, decimals) {
    const prec = Math.min(decimals, 12);
    return parseUnits(Number(value).toFixed(prec), decimals);
}

/** map error ดิบเป็นข้อความ user-facing — ห้ามโชว์ raw exception (project rule) */
function friendlyError(err) {
    if (err?.code === 4001 || err?.code === 'ACTION_REJECTED') return 'Transaction rejected by user.';
    const msg = (err?.reason || err?.shortMessage || err?.message || '').toString().toLowerCase();
    if (msg.includes('pair_not_found') || msg.includes('insufficient_liquidity')) return 'No liquidity available for this pair yet.';
    if (msg.includes('insufficient_output_amount') || msg.includes('insufficient_a_amount') || msg.includes('insufficient_b_amount')) return 'Price moved too much — increase slippage and try again.';
    if (msg.includes('expired')) return 'The transaction expired before confirming. Please try again.';
    if (msg.includes('transfer_from_failed') || msg.includes('transferfrom')) return 'Token transfer failed — check your balance and approval.';
    if (msg.includes('insufficient')) return 'Insufficient balance for this transaction.';
    if (msg.includes('user rejected') || msg.includes('user denied')) return 'Transaction rejected by user.';
    if (msg.includes('timeout') || msg.includes('timed out')) return 'Network timed out. Please try again.';
    return 'Transaction failed. Please try again.';
}

/** สร้าง path จริงบนเชน — NATIVE ถูกแทนด้วย WTPIX */
function buildPath(fromToken, toToken) {
    const from = fromToken === NATIVE ? TPIX_DEX.WTPIX : fromToken;
    const to = toToken === NATIVE ? TPIX_DEX.WTPIX : toToken;
    if (from.toLowerCase() === to.toLowerCase()) return null;
    // ถ้าไม่มีคู่ตรง router จะ revert PAIR_NOT_FOUND — caller ลอง route ผ่าน WTPIX ได้
    return [from, to];
}

function explorerTxUrl(hash) {
    return `https://explorer.tpix.online/tx/${hash}`;
}

export function useTpixDex() {
    const isLoadingQuote = ref(false);
    const isExecuting = ref(false);
    const isApproving = ref(false);
    const error = ref(null);
    const txHash = ref(null);
    const txStatus = ref(null); // 'pending' | 'confirmed' | 'failed'

    /** ให้แน่ใจว่าได้ที่อยู่จากทะเบียนแล้ว (ไม่ยิงซ้ำถ้าเพิ่งโหลด) */
    async function ensureConfig() {
        if (!TPIX_DEX.loaded) await loadDexConfig();
        return isDexConfigured();
    }

    /**
     * ขอ quote จริงจาก router (RPC ของ TPIX chain)
     * @param fromToken 'native' หรือ ERC-20 address
     * @param toToken   'native' หรือ ERC-20 address
     * @param amount    จำนวน (human units)
     * @param decimals  { from, to } — decimals ของแต่ละฝั่ง (native = 18)
     * @returns {amountOut, path, amounts} หรือ null ถ้าไม่มีราคา (ไม่ fabricate)
     */
    async function getQuote(fromToken, toToken, amount, decimals = { from: 18, to: 18 }) {
        error.value = null;
        if (!(await ensureConfig())) {
            error.value = 'TPIX DEX is not deployed yet.';
            return null;
        }
        if (!amount || Number(amount) <= 0) return null;

        isLoadingQuote.value = true;
        try {
            const path = buildPath(fromToken, toToken);
            if (!path) return null;

            const router = new Contract(TPIX_DEX.ROUTER, TPIX_DEX_ROUTER_ABI, getTpixReadProvider());
            const amountIn = toWei(amount, decimals.from);

            let amounts;
            try {
                amounts = await router.getAmountsOut(amountIn, path);
            } catch (directErr) {
                // ไม่มีคู่ตรง → ลอง route ผ่าน WTPIX (token↔token เท่านั้น)
                if (fromToken !== NATIVE && toToken !== NATIVE) {
                    const hopPath = [path[0], TPIX_DEX.WTPIX, path[1]];
                    amounts = await router.getAmountsOut(amountIn, hopPath);
                    return {
                        amountOut: formatUnits(amounts[amounts.length - 1], decimals.to),
                        path: hopPath,
                        amounts,
                    };
                }
                throw directErr;
            }

            return {
                amountOut: formatUnits(amounts[amounts.length - 1], decimals.to),
                path,
                amounts,
            };
        } catch (err) {
            error.value = friendlyError(err);
            return null;
        } finally {
            isLoadingQuote.value = false;
        }
    }

    /**
     * Quote ในรูปแบบเดียวกับ useSwap.getQuote() ฝั่ง BSC — ให้หน้าเทรดใช้ตัวเดียวกันได้
     * ทั้งพรีวิวและตอนส่งจริง โดยไม่ต้องแยกโค้ดตามเชน
     *
     * @param fromTok {address|'native', decimals, symbol}
     * @param toTok   {address|'native', decimals, symbol}
     * @param amount  จำนวนฝั่ง input (human units)
     * @param slippagePct ค่าเริ่มต้น 0.5
     */
    async function getTradeQuote(fromTok, toTok, amount, slippagePct = 0.5) {
        const from = toDexAddress(fromTok.address);
        const to = toDexAddress(toTok.address);
        const decimals = { from: fromTok.decimals ?? 18, to: toTok.decimals ?? 18 };

        const q = await getQuote(from, to, amount, decimals);
        if (!q) return null;

        const grossAmount = Number(amount);
        const netOutput = parseFloat(q.amountOut);
        const slippage = Number.isFinite(Number(slippagePct)) ? Number(slippagePct) : 0.5;
        const rawAmountOut = q.amounts[q.amounts.length - 1];

        // price impact จาก reserve ของฮอปแรก — บอกผู้ใช้ก่อนว่าไม้นี้ดันราคาแค่ไหน
        let priceImpact = 0;
        try {
            const pool = await getPoolInfo(q.path[0], q.path[1]);
            if (pool && pool.reserveIn > 0n && pool.reserveOut > 0n) {
                const amountInWei = toWei(amount, decimals.from);
                const spot = Number(pool.reserveOut) / Number(pool.reserveIn);
                const eff = Number(rawAmountOut) / Number(amountInWei);
                priceImpact = spot > 0 ? Math.max(0, (1 - eff / spot) * 100) : 0;
            }
        } catch {
            priceImpact = 0;
        }

        return {
            amountIn: grossAmount,
            swapInput: grossAmount,
            amountOut: netOutput,
            netOutput,
            exchangeRate: grossAmount > 0 ? netOutput / grossAmount : 0,
            // ค่าธรรมเนียมอยู่ในพูล — ผู้ใช้ไม่ต้องจ่ายธุรกรรมที่สอง
            feeRate: POOL_FEE_PCT,
            feeAmount: grossAmount * (POOL_FEE_PCT / 100),
            feeModel: 'in_pool',
            priceImpact: Number(priceImpact.toFixed(4)),
            slippage,
            minimumReceived: Math.max(netOutput * (1 - slippage / 100), 0),
            path: q.path,
            amounts: q.amounts,
            rawAmountOut,
            amountInSwapWei: toWei(amount, decimals.from),
            feeWei: 0n,
            decimals,
            from,
            to,
        };
    }

    /** เช็ค allowance ของ ERC-20 ต่อ router (native ไม่ต้อง approve) */
    async function needsApproval(tokenAddress, amount, decimals = 18) {
        const token = toDexAddress(tokenAddress);
        if (token === NATIVE) return false;
        const walletStore = useWalletStore();
        if (!walletStore.address) return false;
        try {
            const erc20 = new Contract(token, TPIX_DEX_ERC20_ABI, getTpixReadProvider());
            const allowance = await erc20.allowance(walletStore.address, TPIX_DEX.ROUTER);
            return allowance < toWei(amount, decimals);
        } catch {
            return true; // อ่านไม่ได้ → บังคับ approve (fail-closed)
        }
    }

    /** approve token ให้ router */
    async function approveToken(tokenAddress, amount, decimals = 18) {
        const walletStore = useWalletStore();
        if (!walletStore.signer) throw new Error('Wallet not connected');

        isApproving.value = true;
        error.value = null;
        try {
            const erc20 = new Contract(toDexAddress(tokenAddress), TPIX_DEX_ERC20_ABI, walletStore.signer);
            const tx = await erc20.approve(TPIX_DEX.ROUTER, toWei(amount, decimals));
            await tx.wait();
            return true;
        } catch (err) {
            error.value = friendlyError(err);
            return false;
        } finally {
            isApproving.value = false;
        }
    }

    /** ด่านก่อนส่งธุรกรรมทุกชนิด — กระเป๋า + เชน + DEX พร้อม */
    async function guardWrite() {
        const walletStore = useWalletStore();
        if (!(await ensureConfig())) {
            error.value = 'TPIX DEX is not deployed yet.';
            return null;
        }
        if (!walletStore.signer || !walletStore.address) {
            error.value = 'Please connect your wallet first.';
            return null;
        }
        // Wrong-chain guard — ธุรกรรมนี้อยู่บน TPIX chain เท่านั้น
        if (Number(walletStore.chainId) !== TPIX_DEX.CHAIN_ID) {
            error.value = 'Please switch your wallet to TPIX Chain (4289).';
            return null;
        }
        return walletStore;
    }

    /**
     * Execute swap บน TPIX chain
     * @param fromToken 'native' | ERC-20 address
     * @param toToken   'native' | ERC-20 address
     * @param amount    จำนวนฝั่ง input (human units)
     * @param options   { slippagePct = 0.5, decimals = {from:18,to:18}, path = null }
     * @returns {txHash, amountOut} หรือ null ถ้าพลาด (อ่าน error.value)
     */
    async function executeSwap(fromToken, toToken, amount, options = {}) {
        const { slippagePct = 0.5, decimals = { from: 18, to: 18 } } = options;

        error.value = null;
        txHash.value = null;
        txStatus.value = null;

        const walletStore = await guardWrite();
        if (!walletStore) return null;

        isExecuting.value = true;
        try {
            // quote สดก่อนยิงจริง เพื่อคำนวณ minOut จาก slippage
            const quote = options.path
                ? { path: options.path, amounts: null }
                : await getQuote(fromToken, toToken, amount, decimals);
            if (!quote) {
                if (!error.value) error.value = 'No liquidity available for this pair yet.';
                return null;
            }

            const router = new Contract(TPIX_DEX.ROUTER, TPIX_DEX_ROUTER_ABI, walletStore.signer);
            const amountIn = toWei(amount, decimals.from);
            const path = quote.path;
            const amounts = quote.amounts ?? (await router.getAmountsOut(amountIn, path));
            const quotedOut = amounts[amounts.length - 1];
            // minOut = quote × (1 - slippage) — คิดเป็น basis points กัน float precision
            const bps = BigInt(Math.round(slippagePct * 100));
            const minOut = (quotedOut * (10000n - bps)) / 10000n;
            if (minOut <= 0n) {
                error.value = 'Slippage too high — please adjust and retry.';
                return null;
            }
            const deadline = Math.floor(Date.now() / 1000) + 60 * 20;

            let tx;
            if (fromToken === NATIVE) {
                tx = await router.swapExactETHForTokens(
                    minOut, path, walletStore.address, deadline,
                    { value: amountIn },
                );
            } else if (toToken === NATIVE) {
                tx = await router.swapExactTokensForETH(
                    amountIn, minOut, path, walletStore.address, deadline,
                );
            } else {
                tx = await router.swapExactTokensForTokens(
                    amountIn, minOut, path, walletStore.address, deadline,
                );
            }

            txHash.value = tx.hash;
            txStatus.value = 'pending';
            const receipt = await tx.wait();
            txStatus.value = receipt.status === 1 ? 'confirmed' : 'failed';

            if (receipt.status !== 1) {
                error.value = 'Swap failed on-chain.';
                return null;
            }

            return {
                txHash: tx.hash,
                amountOut: formatUnits(quotedOut, decimals.to),
            };
        } catch (err) {
            txStatus.value = 'failed';
            error.value = friendlyError(err);
            return null;
        } finally {
            isExecuting.value = false;
        }
    }

    /**
     * ส่งไม้จากหน้าเทรด — สัญญาแบบเดียวกับ useSwap.executeSwap() ฝั่ง BSC
     * (คืน {hash, status, url} และ throw เมื่อล้มเหลว โดย error.value มีข้อความพร้อมแสดง)
     * บันทึกประวัติเข้าเซิร์ฟเวอร์ให้ด้วย (best-effort) เพื่อให้ป้ายบนกราฟ/ประวัติเห็นไม้นี้
     */
    async function executeTradeSwap(fromTok, toTok, amount, quote, slippage = 0.5) {
        const walletStore = useWalletStore();
        const from = toDexAddress(fromTok.address);
        const to = toDexAddress(toTok.address);
        const decimals = quote?.decimals ?? { from: fromTok.decimals ?? 18, to: toTok.decimals ?? 18 };

        const result = await executeSwap(from, to, amount, {
            slippagePct: slippage,
            decimals,
            path: quote?.path ?? null,
        });

        if (!result) {
            const err = new Error(error.value || 'Swap failed. Please try again.');
            err.isFriendly = true;
            throw err;
        }

        // บันทึกฝั่งเซิร์ฟเวอร์ — ล้มก็ไม่กระทบไม้ที่ลงไปแล้ว
        try {
            await axios.post('/api/v1/swap/execute', {
                from_token: from === NATIVE ? ZERO : from,
                to_token: to === NATIVE ? ZERO : to,
                from_amount: Number(amount),
                to_amount: parseFloat(result.amountOut),
                fee_amount: 0, // ค่าธรรมเนียมอยู่ในพูล
                tx_hash: result.txHash,
                chain_id: TPIX_DEX.CHAIN_ID,
                wallet_address: walletStore.address,
            });
        } catch (apiErr) {
            console.warn('Failed to record TPIX DEX swap on backend:', apiErr?.message);
        }

        return {
            hash: result.txHash,
            status: 'confirmed',
            url: explorerTxUrl(result.txHash),
            amountOut: result.amountOut,
        };
    }

    /** balance ของ token (หรือ native) บน TPIX chain — อ่านผ่าน read RPC */
    async function getBalance(tokenAddress) {
        const walletStore = useWalletStore();
        if (!walletStore.address) return '0';
        try {
            const provider = getTpixReadProvider();
            const token = toDexAddress(tokenAddress);
            if (token === NATIVE) {
                return formatUnits(await provider.getBalance(walletStore.address), 18);
            }
            const erc20 = new Contract(token, TPIX_DEX_ERC20_ABI, provider);
            const [raw, dec] = await Promise.all([
                erc20.balanceOf(walletStore.address),
                erc20.decimals(),
            ]);
            return formatUnits(raw, dec);
        } catch {
            return '0';
        }
    }

    // ── สภาพคล่อง ────────────────────────────────────────────────────────────

    /**
     * ข้อมูลพูลของคู่ (tokenIn, tokenOut) เรียง reserve ตามฝั่งที่ขอ
     * @returns {pair, reserveIn, reserveOut, totalSupply} (BigInt) หรือ null ถ้ายังไม่มีพูล
     */
    async function getPoolInfo(tokenA, tokenB) {
        if (!(await ensureConfig())) return null;
        const a = toDexAddress(tokenA) === NATIVE ? TPIX_DEX.WTPIX : toDexAddress(tokenA);
        const b = toDexAddress(tokenB) === NATIVE ? TPIX_DEX.WTPIX : toDexAddress(tokenB);
        const provider = getTpixReadProvider();
        const factory = new Contract(TPIX_DEX.FACTORY, TPIX_DEX_FACTORY_ABI, provider);
        const pairAddress = await factory.getPair(a, b);
        if (!pairAddress || pairAddress === ZERO) return null;

        const pair = new Contract(pairAddress, TPIX_DEX_PAIR_ABI, provider);
        const [token0, reserves, totalSupply] = await Promise.all([
            pair.token0(),
            pair.getReserves(),
            pair.totalSupply(),
        ]);
        const aIsToken0 = token0.toLowerCase() === a.toLowerCase();
        return {
            pair: pairAddress,
            reserveIn: aIsToken0 ? reserves[0] : reserves[1],
            reserveOut: aIsToken0 ? reserves[1] : reserves[0],
            totalSupply,
        };
    }

    /**
     * สัดส่วนของผู้ใช้ในพูล TOKEN/TPIX — จำนวน LP, ส่วนแบ่ง และมูลค่าที่ถอนได้ตอนนี้
     */
    async function getMyPosition(tokenAddress, tokenDecimals = 18) {
        const walletStore = useWalletStore();
        if (!walletStore.address) return null;
        const pool = await getPoolInfo(tokenAddress, NATIVE);
        if (!pool) return null;

        const pair = new Contract(pool.pair, TPIX_DEX_PAIR_ABI, getTpixReadProvider());
        const lp = await pair.balanceOf(walletStore.address);
        if (pool.totalSupply === 0n) return null;

        const tokenShare = (pool.reserveIn * lp) / pool.totalSupply;
        const tpixShare = (pool.reserveOut * lp) / pool.totalSupply;

        return {
            pair: pool.pair,
            lp,
            lpFormatted: formatUnits(lp, 18),
            sharePct: Number((lp * 1_000_000n) / pool.totalSupply) / 10_000,
            token: formatUnits(tokenShare, tokenDecimals),
            tpix: formatUnits(tpixShare, 18),
            reserveToken: formatUnits(pool.reserveIn, tokenDecimals),
            reserveTpix: formatUnits(pool.reserveOut, 18),
        };
    }

    /**
     * เติมสภาพคล่อง TOKEN + TPIX (พูลใหม่ = ตั้งราคาเปิดจากอัตราส่วนที่ใส่)
     * router จะปรับให้ตรงอัตราส่วนพูลเอง — ส่วนที่เกินส่งคืนกระเป๋า
     */
    async function addLiquidity(tokenAddress, tokenAmount, tpixAmount, options = {}) {
        const { slippagePct = 0.5, tokenDecimals = 18 } = options;
        error.value = null;
        txHash.value = null;

        const walletStore = await guardWrite();
        if (!walletStore) return null;

        const token = toDexAddress(tokenAddress);
        if (token === NATIVE) {
            error.value = 'Choose a token to pair with TPIX.';
            return null;
        }

        isExecuting.value = true;
        try {
            const tokenWei = toWei(tokenAmount, tokenDecimals);
            const tpixWei = toWei(tpixAmount, 18);
            const bps = BigInt(Math.round(slippagePct * 100));
            const tokenMin = (tokenWei * (10000n - bps)) / 10000n;
            const tpixMin = (tpixWei * (10000n - bps)) / 10000n;
            const deadline = Math.floor(Date.now() / 1000) + 60 * 20;

            const router = new Contract(TPIX_DEX.ROUTER, TPIX_DEX_ROUTER_ABI, walletStore.signer);
            const tx = await router.addLiquidityETH(
                token, tokenWei, tokenMin, tpixMin, walletStore.address, deadline,
                { value: tpixWei },
            );
            txHash.value = tx.hash;
            txStatus.value = 'pending';
            const receipt = await tx.wait();
            txStatus.value = receipt.status === 1 ? 'confirmed' : 'failed';
            if (receipt.status !== 1) {
                error.value = 'Adding liquidity failed on-chain.';
                return null;
            }
            return { hash: tx.hash, url: explorerTxUrl(tx.hash) };
        } catch (err) {
            txStatus.value = 'failed';
            error.value = friendlyError(err);
            return null;
        } finally {
            isExecuting.value = false;
        }
    }

    /** approve LP token ให้ router ก่อนถอน */
    async function approveLp(pairAddress, lpAmountWei) {
        const walletStore = useWalletStore();
        if (!walletStore.signer) throw new Error('Wallet not connected');
        isApproving.value = true;
        try {
            const pair = new Contract(pairAddress, TPIX_DEX_PAIR_ABI, walletStore.signer);
            const allowance = await pair.allowance(walletStore.address, TPIX_DEX.ROUTER);
            if (allowance >= lpAmountWei) return true;
            const tx = await pair.approve(TPIX_DEX.ROUTER, lpAmountWei);
            await tx.wait();
            return true;
        } catch (err) {
            error.value = friendlyError(err);
            return false;
        } finally {
            isApproving.value = false;
        }
    }

    /**
     * ถอนสภาพคล่องเป็นสัดส่วน (%) ของ LP ที่ถือ — ได้ TOKEN + TPIX คืน
     */
    async function removeLiquidity(tokenAddress, percent, options = {}) {
        const { slippagePct = 0.5, tokenDecimals = 18 } = options;
        error.value = null;
        txHash.value = null;

        const walletStore = await guardWrite();
        if (!walletStore) return null;

        const pct = Math.min(100, Math.max(0, Number(percent) || 0));
        if (pct <= 0) {
            error.value = 'Choose how much to withdraw.';
            return null;
        }

        isExecuting.value = true;
        try {
            const position = await getMyPosition(tokenAddress, tokenDecimals);
            if (!position || position.lp === 0n) {
                error.value = 'You have no liquidity in this pool.';
                return null;
            }

            const lpToBurn = (position.lp * BigInt(Math.round(pct * 100))) / 10000n;
            const tokenExpected = toWei(position.token, tokenDecimals) * BigInt(Math.round(pct * 100)) / 10000n;
            const tpixExpected = toWei(position.tpix, 18) * BigInt(Math.round(pct * 100)) / 10000n;
            const bps = BigInt(Math.round(slippagePct * 100));
            const tokenMin = (tokenExpected * (10000n - bps)) / 10000n;
            const tpixMin = (tpixExpected * (10000n - bps)) / 10000n;

            if (!(await approveLp(position.pair, lpToBurn))) return null;

            const deadline = Math.floor(Date.now() / 1000) + 60 * 20;
            const router = new Contract(TPIX_DEX.ROUTER, TPIX_DEX_ROUTER_ABI, walletStore.signer);
            const tx = await router.removeLiquidityETH(
                toDexAddress(tokenAddress), lpToBurn, tokenMin, tpixMin, walletStore.address, deadline,
            );
            txHash.value = tx.hash;
            txStatus.value = 'pending';
            const receipt = await tx.wait();
            txStatus.value = receipt.status === 1 ? 'confirmed' : 'failed';
            if (receipt.status !== 1) {
                error.value = 'Removing liquidity failed on-chain.';
                return null;
            }
            return { hash: tx.hash, url: explorerTxUrl(tx.hash) };
        } catch (err) {
            txStatus.value = 'failed';
            error.value = friendlyError(err);
            return null;
        } finally {
            isExecuting.value = false;
        }
    }

    /** symbol/decimals ของ ERC-20 บนเชน TPIX — ใช้ตรวจที่อยู่ที่ผู้ใช้วางเอง */
    async function readTokenMeta(tokenAddress) {
        const token = toDexAddress(tokenAddress);
        if (token === NATIVE) return { symbol: 'TPIX', name: 'TPIX', decimals: 18, native: true };
        if (!/^0x[a-f0-9]{40}$/.test(token)) return null;
        try {
            const erc20 = new Contract(token, TPIX_DEX_ERC20_ABI, getTpixReadProvider());
            const [symbol, decimals, name] = await Promise.all([
                erc20.symbol(),
                erc20.decimals(),
                erc20.name().catch(() => ''),
            ]);
            return { symbol, name: name || symbol, decimals: Number(decimals), native: false, address: token };
        } catch {
            return null;
        }
    }

    return {
        // state
        isLoadingQuote,
        isExecuting,
        isApproving,
        error,
        txHash,
        txStatus,
        // constants
        NATIVE,
        POOL_FEE_PCT,
        // actions
        getQuote,
        getTradeQuote,
        needsApproval,
        approveToken,
        executeSwap,
        executeTradeSwap,
        getBalance,
        getPoolInfo,
        getMyPosition,
        addLiquidity,
        removeLiquidity,
        readTokenMeta,
        ensureConfig,
        isDexConfigured,
    };
}
