/**
 * ตัวตรวจยอดของสะพาน TPIX ⇄ wTPIX — เพดานของ "สัญญาปลายทาง" ต้องถูกตรวจทั้งสองทิศ
 *
 * ล็อก TPIX ที่ยอดได้รับเกิน maxPerTx ของ minter บน BSC = เหรียญถูกล็อกแล้วแต่ mint ครั้งเดียวไม่ได้
 * (depositId ใช้ได้ครั้งเดียว) ต้องห้ามตั้งแต่หน้าเว็บ ไม่ใช่ไปค้างรอตรวจ
 */
import { describe, it, expect } from 'vitest';
import { checkWtpixAmount, parseTpixAmount } from '@/utils/wtpixBridge';

const info = (overrides = {}) => ({
    limits: { min_lock: '10', max_lock_per_tx: '100000', lock_fee: '1', min_burn: '10', max_burn_per_tx: '100000', burn_fee: '0' },
    vault: { min_lock: '10', max_lock: '500000', max_per_tx: '100000' },
    minter: { max_per_tx: '50000' },
    ...overrides,
});

describe('checkWtpixAmount — destination caps', () => {
    it('blocks a lock whose payout exceeds the BSC minter max_per_tx even when the vault would accept it', () => {
        const result = checkWtpixAmount(parseTpixAmount('60000'), 'to_bsc', info());

        expect(result.error).toBe('bridge.wtpix.errAboveContractMax');
        // เพดานที่บอกผู้ใช้ = เพดานของ minter + ค่าธรรมเนียมที่ถูกหักก่อน mint
        expect(result.params.max).toBe('50001');
    });

    it('allows a lock whose payout lands exactly on the minter cap', () => {
        expect(checkWtpixAmount(parseTpixAmount('50001'), 'to_bsc', info()).error).toBeNull();
    });

    it('still blocks a lock above the vault max_lock first', () => {
        const result = checkWtpixAmount(parseTpixAmount('600000'), 'to_bsc', info({ minter: { max_per_tx: '1000000' } }));

        expect(result.error).toBe('bridge.wtpix.errAboveContractMax');
        expect(result.params.max).toBe('500000');
    });

    it('checks a burn against the vault release cap, net of the burn fee', () => {
        const withFee = info({ limits: { ...info().limits, burn_fee: '2' } });

        expect(checkWtpixAmount(parseTpixAmount('100002'), 'to_tpix', withFee).error).toBeNull();
        expect(checkWtpixAmount(parseTpixAmount('100003'), 'to_tpix', withFee).error).toBe('bridge.wtpix.errAboveContractMax');
    });

    it('does not block when the caps could not be read', () => {
        expect(checkWtpixAmount(parseTpixAmount('60000'), 'to_bsc', info({ minter: { max_per_tx: null } })).error).toBeNull();
    });
});
