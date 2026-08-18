/**
 * TPIX TRADE — คลัง TPIX และใบอนุญาตวางไม้ (ฝั่งหน้าเว็บ)
 *
 * เจ้าของกำหนดโมเดล: ต้องมี TPIX ในคลังก่อนถึงวางไม้ได้ · ค่าบริการเก็บตอน
 * ขึ้นออเดอร์เลย · ไม่มี TPIX ก็เทรดได้ แต่จ่ายแพงกว่าและคืนเงินโดนหักค่าแก๊ส
 *
 * ⚠️ ลำดับที่ห้ามสลับ: ขอใบอนุญาต → เซ็นธุรกรรมของไม้ → ปิดใบอนุญาต
 *    ขอใบอนุญาตแล้วผู้ใช้กดยกเลิกในกระเป๋า ต้องเรียกคืนเงินเสมอ
 *    ไม่งั้นเขาเสีย TPIX เพราะกดผิดปุ่มใน MetaMask ซึ่งไม่มีใครยอมรับได้
 *
 * Developed by Xman Studio
 */

import { ref, computed } from 'vue';
import axios from 'axios';

// ตารางราคาไม่เปลี่ยนบ่อย — เก็บระดับโมดูลให้ทุกหน้าใช้ผลเดียวกัน
const tiers = ref([]);
const topupInfo = ref(null);
const refundGasFee = ref(0);
const ticketTtlMinutes = ref(15);
const feeEnabled = ref(false);
let tiersPromise = null;

const balance = ref(0);
const minimumTopup = ref(0);
const history = ref([]);

const currentQuote = ref(null);
const activeTicket = ref(null);
const isWorking = ref(false);
const error = ref(null);

function readError(err, fallback) {
    return err?.response?.data?.error?.message || fallback;
}

export function useTradingFee() {
    /** ตารางขั้นบันไดค่าบริการ — สาธารณะ ดูได้ก่อนเชื่อมกระเป๋า */
    async function loadTiers() {
        if (tiers.value.length) return tiers.value;
        if (tiersPromise) return tiersPromise;

        tiersPromise = axios.get('/api/v1/trading-fee/tiers')
            .then(({ data }) => {
                if (!data?.success) return [];
                feeEnabled.value = data.data.enabled;
                tiers.value = data.data.tiers || [];
                topupInfo.value = data.data.topup || null;
                refundGasFee.value = data.data.refund_gas_fee ?? 0;
                ticketTtlMinutes.value = data.data.ticket_ttl_minutes ?? 15;
                return tiers.value;
            })
            .catch(() => [])
            .finally(() => { tiersPromise = null; });

        return tiersPromise;
    }

    async function loadBalance(wallet) {
        if (!wallet) {
            balance.value = 0;
            history.value = [];
            return 0;
        }

        try {
            const { data } = await axios.get('/api/v1/trading-fee/balance', {
                params: { wallet_address: wallet },
            });
            if (data?.success) {
                balance.value = data.data.balance ?? 0;
                minimumTopup.value = data.data.minimum_topup ?? 0;
                history.value = data.data.history || [];
            }
        } catch {
            // ยังไม่ได้เซ็นยืนยันกระเป๋า หรือ API ล่ม — ยังไม่รู้ยอด ไม่ใช่ยอดศูนย์
        }

        return balance.value;
    }

    /**
     * ค่าบริการของไม้ขนาดนี้ — เรียกทุกครั้งที่ผู้ใช้พิมพ์จำนวน (ผู้เรียก debounce เอง)
     *
     * เรียกไม่สำเร็จแล้วปล่อยค่าเดิมไว้ ไม่ล้างเป็นศูนย์ — ตัวเลขค่าบริการที่
     * กะพริบหายตอนพิมพ์ทำให้อ่านไม่ทัน และดูเหมือนระบบคิดเงินไม่แน่นอน
     */
    async function quote({ wallet, orderValueUsd, chainId, pair }) {
        if (!(orderValueUsd > 0)) {
            currentQuote.value = null;
            return null;
        }

        try {
            const { data } = await axios.post('/api/v1/trading-fee/quote', {
                wallet_address: wallet || undefined,
                order_value_usd: orderValueUsd,
                chain_id: chainId,
                pair: pair || undefined,
            });

            if (data?.success) {
                currentQuote.value = data.data;
                if (typeof data.data.balance === 'number') balance.value = data.data.balance;
            }
        } catch {
            // เงียบไว้ตามเหตุผลด้านบน
        }

        return currentQuote.value;
    }

    /**
     * ขอใบอนุญาตวางไม้ — เงินออกจากคลังตรงนี้
     *
     * ต้องได้ใบอนุญาตก่อนเสมอ ไม่มีใบ = ห้ามปล่อยให้เซ็นธุรกรรมของไม้
     */
    async function issueTicket({
        wallet, pair, side, orderValueUsd, chainId,
        method = 'tpix_credit', feeTxHash = null, feeAmount = null, feeCurrency = null,
    }) {
        isWorking.value = true;
        error.value = null;

        try {
            const { data } = await axios.post('/api/v1/trading-fee/tickets', {
                wallet_address: wallet,
                pair,
                side,
                order_value_usd: orderValueUsd,
                chain_id: chainId,
                method,
                fee_tx_hash: feeTxHash || undefined,
                fee_amount: feeAmount || undefined,
                fee_currency: feeCurrency || undefined,
            });

            if (data?.success) {
                activeTicket.value = data.data;
                balance.value = data.data.balance ?? balance.value;
                return data.data;
            }

            return null;
        } catch (err) {
            error.value = readError(err, 'ขอใบอนุญาตวางไม้ไม่สำเร็จ');
            return null;
        } finally {
            isWorking.value = false;
        }
    }

    /** ไม้ลงจริงแล้ว — ปิดใบอนุญาต */
    async function consumeTicket(wallet, uuid, orderTxHash = null) {
        try {
            const { data } = await axios.post('/api/v1/trading-fee/tickets/' + uuid + '/consume', {
                wallet_address: wallet,
                order_tx_hash: orderTxHash || undefined,
            });
            if (data?.success) {
                activeTicket.value = null;
                balance.value = data.data.balance ?? balance.value;
            }
            return data?.data ?? null;
        } catch (err) {
            // ปิดใบไม่สำเร็จไม่ควรทำให้ผู้ใช้ตกใจ — ไม้ลงไปแล้ว เงินก็เก็บไปแล้ว
            console.warn('[TPIX] ปิดใบอนุญาตไม่สำเร็จ:', readError(err, ''));
            return null;
        }
    }

    /**
     * ไม้ไม่ได้ลง — คืนค่าบริการ
     *
     * ⚠️ ต้องเรียกทุกครั้งที่ขอใบอนุญาตแล้วไม่ได้วางไม้ รวมถึงตอนผู้ใช้กดยกเลิก
     *    ในกระเป๋า ซึ่งเป็นเคสที่เกิดบ่อยที่สุด
     */
    async function refundTicket(wallet, uuid, reason = '') {
        try {
            const { data } = await axios.post('/api/v1/trading-fee/tickets/' + uuid + '/refund', {
                wallet_address: wallet,
                reason: reason || undefined,
            });
            if (data?.success) {
                activeTicket.value = null;
                balance.value = data.data.balance ?? balance.value;
            }
            return data?.data ?? null;
        } catch (err) {
            console.warn('[TPIX] คืนค่าบริการไม่สำเร็จ:', readError(err, ''));
            return null;
        }
    }

    /** ยืนยันการโอน TPIX เข้าคลัง */
    async function confirmTopup(wallet, txHash) {
        isWorking.value = true;
        error.value = null;

        try {
            const { data } = await axios.post('/api/v1/trading-fee/topup/confirm', {
                wallet_address: wallet,
                tx_hash: txHash,
            });

            if (data?.success) {
                balance.value = data.data.balance ?? balance.value;
                return data.data;
            }

            return null;
        } catch (err) {
            error.value = readError(err, 'ยืนยันการเติมเครดิตไม่สำเร็จ');
            return null;
        } finally {
            isWorking.value = false;
        }
    }

    // ── ค่าที่หน้าจอใช้บ่อย ──────────────────────────────────────────────────

    /** ทางที่ระบบแนะนำสำหรับไม้นี้ */
    const recommended = computed(() => currentQuote.value?.recommended ?? null);

    /** จ่ายด้วย TPIX ได้ไหม (มีขั้นบันไดครอบคลุม + เครดิตพอ) */
    const canPayWithCredit = computed(() =>
        currentQuote.value?.tpix?.available === true && currentQuote.value?.tpix?.has_enough === true
    );

    /** ขาดอีกเท่าไรถึงจะจ่ายด้วย TPIX ได้ */
    const creditShortfall = computed(() => currentQuote.value?.tpix?.shortfall ?? 0);

    return {
        // state
        tiers, topupInfo, refundGasFee, ticketTtlMinutes, feeEnabled,
        balance, minimumTopup, history,
        currentQuote, activeTicket, isWorking, error,
        // derived
        recommended, canPayWithCredit, creditShortfall,
        // actions
        loadTiers, loadBalance, quote,
        issueTicket, consumeTicket, refundTicket, confirmTopup,
    };
}
