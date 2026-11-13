/**
 * TPIX TRADE — AiCopyTradePanel tests (แผง Master / Copy Trade)
 *
 * กัน:
 *  - ผลงานถูกโชว์โดยไม่บอกว่าเป็นเงินจริงจากไม้ที่ปิดแล้ว หรือช่อง "แนะนำ" ถูกอ่านเป็นอันดับผลงาน
 *  - กดตามได้โดยไม่เห็นอัตราค่าบริการ เงื่อนไข และคำเตือนว่าเป็นเงินจริง
 *  - กดตามได้ทั้งที่ยังไม่เปิดเทรดเงินจริง · ก๊อปปี้โปรไฟล์ของตัวเอง
 *  - ค่าบริการที่ยังไม่ถูกเก็บถูกนำเสนอเหมือนเก็บไปแล้ว
 *  - เลิกตาม/ปิดโปรไฟล์โดยไม่ถามยืนยัน · กดซ้ำแล้วยิงซ้ำ
 *  - ของกระเป๋าก่อนหน้าค้างอยู่หลังสลับกระเป๋า
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { mount, flushPromises, enableAutoUnmount } from '@vue/test-utils';
import AiCopyTradePanel from '@/Components/AiTrade/AiCopyTradePanel.vue';

enableAutoUnmount(afterEach);
afterEach(() => vi.restoreAllMocks());

const WALLET = '0x1111111111111111111111111111111111111111';

const LIMITS = {
    max_position_usd: { min: 10, max: 1000000, default: 100 },
    stop_loss_pct: { min: 0.5, max: 50, default: 5 },
    take_profit_pct: { min: 0.5, max: 200, default: 10 },
    max_daily_loss_usd: { min: 5, max: 1000000, default: 50 },
};

function master(overrides = {}) {
    return {
        id: 1, display_name: 'Alpha', bio: 'Trend on 4h', wallet_short: '0xaaaa…aaaa', fee_rate_pct: 20, platform_share_pct: 20,
        status: 'active', accepting: true, promoted: false, pair: 'BTC/USDT', timeframe: '4h', meets_floor: true, followers: 3,
        record: { mode: 'live', closed: 24, win_rate: 83.3, return_pct: 31, max_drawdown_pct: 2, days: 60.4 },
        rank: { tier: 3, key: 'tier_3', name_th: 'ระดับ 3', name_en: 'Tier 3' }, rating: { avg: 4.5, count: 12 },
        ...overrides,
    };
}

function boardData(overrides = {}) {
    return {
        enabled: true, live_ready: true,
        fees: { collect: false, currency: 'TPIX', rate_pct: { min: 5, max: 30, default: 20 }, platform_share_pct: { min: 20, max: 50, default: 20 } },
        requirements: { max_name_length: 40, max_bio_length: 280 },
        masters: [master(), master({ id: 2, display_name: 'Bravo', promoted: true, platform_share_pct: 50, record: { mode: 'live', closed: 24, win_rate: 70, return_pct: 16, max_drawdown_pct: 4, days: 61 } })],
        promoted: [2],
        ...overrides,
    };
}

function mineData(overrides = {}) {
    return { master: null, invite: false, candidates: [], follows: [], kyc_approved: true, ...overrides };
}

function makeApi(board = boardData(), mine = mineData()) {
    const ok = (data = {}) => vi.fn(() => Promise.resolve({ ok: true, data }));

    return {
        loadMasters: vi.fn(() => Promise.resolve({ ok: true, data: board })),
        loadMine: vi.fn(() => Promise.resolve({ ok: true, data: mine })),
        registerMaster: ok(), updateMaster: ok(), closeMaster: ok(), follow: ok(), stopFollow: ok(), acceptRate: ok(),
        loadReviews: ok({ rating: { avg: 3.5, count: 2 }, reviews: [] }), review: ok(), reportReview: ok({ reported: true }),
    };
}

async function mountPanel(api = makeApi(), props = {}) {
    const wrapper = mount(AiCopyTradePanel, { props: { api, wallet: WALLET, limits: LIMITS, ...props } });
    await flushPromises();

    return { wrapper, api };
}

const byId = (wrapper, id) => wrapper.find(`[data-testid="${id}"]`);

describe('AiCopyTradePanel — the board', () => {
    it('says the figures are real money on closed trades and lists masters in server order', async () => {
        const { wrapper } = await mountPanel();

        expect(byId(wrapper, 'copy-real-only').text()).toContain('real money');
        expect(byId(wrapper, 'copy-real-only').text()).toContain('closed trades only');
        expect(wrapper.findAll('[data-testid^="copy-master-"]').map((li) => li.text().slice(0, 7))).toEqual(['#1Alpha', '#2Bravo']);

        const alpha = byId(wrapper, 'copy-master-1').text();
        expect(alpha).toContain('+31.00%');
        expect(alpha).toContain('24');
        expect(alpha).toContain('-2.00%');
        expect(alpha).toContain('60 days');
        expect(alpha).toContain('20% of profit');
    });

    it('labels the promoted strip as paid placement, not ranking', async () => {
        const { wrapper } = await mountPanel();
        const strip = byId(wrapper, 'copy-promoted');

        expect(strip.text()).toContain('Promoted');
        expect(strip.text()).toContain('not by performance');
        expect(strip.text()).toContain('Bravo');
        expect(strip.text()).not.toContain('Alpha');
        // ป้ายบนแถวของกระดานด้วย — และอันดับ #1 ยังเป็นของคนที่ผลงานดีกว่า
        expect(byId(wrapper, 'copy-master-2').text()).toContain('Promoted');
        expect(byId(wrapper, 'copy-master-1').text()).not.toContain('Promoted');
    });

    it('shows nothing but a notice while the feature is off', async () => {
        const { wrapper, api } = await mountPanel(makeApi(boardData({ enabled: false, masters: [], promoted: [] })));

        expect(byId(wrapper, 'copy-disabled').exists()).toBe(true);
        expect(wrapper.find('[data-testid^="copy-master-"]').exists()).toBe(false);
        expect(api.loadMine).not.toHaveBeenCalled();
    });

    it('lets visitors browse without a wallet but offers no follow button', async () => {
        const { wrapper, api } = await mountPanel(makeApi(), { wallet: '' });

        expect(byId(wrapper, 'copy-master-1').exists()).toBe(true);
        expect(byId(wrapper, 'copy-follow-1').exists()).toBe(false);
        expect(api.loadMine).not.toHaveBeenCalled();
    });

    it('offers a retry when the board cannot be loaded', async () => {
        const api = makeApi();
        api.loadMasters.mockResolvedValueOnce({ ok: false, error: { message: 'Too many requests' } });

        const { wrapper } = await mountPanel(api);
        expect(byId(wrapper, 'copy-load-error').text()).toContain('Too many requests');

        await byId(wrapper, 'copy-load-error').find('button').trigger('click');
        await flushPromises();
        expect(byId(wrapper, 'copy-master-1').exists()).toBe(true);
    });

    it('flags a master whose recent results fell below the requirements', async () => {
        const board = boardData({ masters: [master({ meets_floor: false, record: { mode: 'live', closed: 30, win_rate: 40, return_pct: -12.5, max_drawdown_pct: 30, days: 90 } })], promoted: [] });
        const { wrapper } = await mountPanel(makeApi(board));

        expect(byId(wrapper, 'copy-master-1').text()).toContain('below the master requirements');
        expect(byId(wrapper, 'copy-master-1').text()).toContain('-12.50%');
    });
});

describe('AiCopyTradePanel — following', () => {
    it('locks following until real-money trading is open', async () => {
        const { wrapper } = await mountPanel(makeApi(boardData({ live_ready: false })));

        expect(byId(wrapper, 'copy-needs-live').text()).toContain('real-money trading is open');
        expect(byId(wrapper, 'copy-follow-1').attributes('disabled')).toBeDefined();
    });

    it('shows the fee terms and the real-money warning before anything is sent', async () => {
        const { wrapper, api } = await mountPanel();

        await byId(wrapper, 'copy-follow-1').trigger('click');

        expect(byId(wrapper, 'copy-follow-terms').text()).toContain('20% of your realised profit');
        expect(byId(wrapper, 'copy-follow-terms').text()).toContain('high-water mark');
        expect(byId(wrapper, 'copy-follow-terms').text()).toContain('TPIX');
        expect(byId(wrapper, 'copy-follow-terms').text()).toContain('no fee when you lose');
        expect(byId(wrapper, 'copy-follow-warning').text()).toContain('real money');
        expect(api.follow).not.toHaveBeenCalled();
    });

    it('sends the rate the follower saw and the follower own risk limits', async () => {
        const { wrapper, api } = await mountPanel();

        await byId(wrapper, 'copy-follow-1').trigger('click');
        await byId(wrapper, 'copy-follow-max_position_usd').setValue('250');
        await byId(wrapper, 'copy-follow-form').trigger('submit');
        await flushPromises();

        expect(api.follow).toHaveBeenCalledWith({
            master_id: 1,
            accept_fee_rate_pct: 20,
            risk: { max_position_usd: 250, stop_loss_pct: 5, take_profit_pct: 10, max_daily_loss_usd: 50 },
        });
        expect(wrapper.emitted('notify')[0][0]).toEqual({ type: 'success', text: 'You now follow Alpha — start the bot in your bot list when you are ready' });
        expect(byId(wrapper, 'copy-follow-form').exists()).toBe(false);
    });

    it('surfaces the server reason and keeps the form open when following fails', async () => {
        const api = makeApi();
        api.follow.mockResolvedValue({ ok: false, error: { code: 'COPY_RATE_CHANGED', message: 'The master changed the fee to 30%' } });
        const { wrapper } = await mountPanel(api);

        await byId(wrapper, 'copy-follow-1').trigger('click');
        await byId(wrapper, 'copy-follow-form').trigger('submit');
        await flushPromises();

        expect(wrapper.emitted('notify')[0][0]).toEqual({ type: 'error', text: 'The master changed the fee to 30%' });
        expect(byId(wrapper, 'copy-follow-form').exists()).toBe(true);
    });

    it('does not send twice on a double submit', async () => {
        const api = makeApi();
        let finish;
        api.follow.mockImplementation(() => new Promise((resolve) => { finish = resolve; }));
        const { wrapper } = await mountPanel(api);

        await byId(wrapper, 'copy-follow-1').trigger('click');
        await byId(wrapper, 'copy-follow-form').trigger('submit');
        await byId(wrapper, 'copy-follow-form').trigger('submit');

        expect(api.follow).toHaveBeenCalledTimes(1);
        finish({ ok: true, data: {} });
        await flushPromises();
    });

    it('offers no follow button on my own profile or on a paused master', async () => {
        const mine = mineData({ master: { ...master(), earnings: { master_usd: 0, collect: false } } });
        const board = boardData({ masters: [master(), master({ id: 2, display_name: 'Bravo', accepting: false, status: 'paused' })], promoted: [] });
        const { wrapper } = await mountPanel(makeApi(board, mine));

        expect(byId(wrapper, 'copy-follow-1').exists()).toBe(false);
        expect(byId(wrapper, 'copy-follow-2').exists()).toBe(false);
        expect(byId(wrapper, 'copy-master-2').text()).toContain('Not accepting followers');
    });
});

describe('AiCopyTradePanel — my follows', () => {
    const follow = (overrides = {}) => ({
        id: 9, status: 'active', bot_id: 44, master: { id: 1, display_name: 'Alpha', status: 'active', fee_rate_pct: 20 },
        fee_rate_pct: 20, rate_changed: false, realized_pnl: 120, fees_usd: 24, fees_tpix: 48, fees_collect: false, ...overrides,
    });

    it('says recorded fees have not been charged', async () => {
        const { wrapper } = await mountPanel(makeApi(boardData(), mineData({ follows: [follow()] })));
        const row = byId(wrapper, 'copy-follow-row-9').text();

        expect(row).toContain('$+120.00');
        expect(row).toContain('$24.00');
        expect(row).toContain('48 TPIX');
        expect(row).toContain('nothing has been charged');
    });

    it('says fees are due once collection is on', async () => {
        const { wrapper } = await mountPanel(makeApi(boardData(), mineData({ follows: [follow({ fees_collect: true })] })));

        expect(byId(wrapper, 'copy-follow-row-9').text()).toContain('Due');
        expect(byId(wrapper, 'copy-follow-row-9').text()).not.toContain('nothing has been charged');
    });

    it('keeps the old rate visible until the follower accepts the new one', async () => {
        const changed = follow({ rate_changed: true, master: { id: 1, display_name: 'Alpha', status: 'active', fee_rate_pct: 30 } });
        const { wrapper, api } = await mountPanel(makeApi(boardData(), mineData({ follows: [changed] })));

        expect(byId(wrapper, 'copy-rate-changed-9').text()).toContain('changed the fee to 30%');
        expect(byId(wrapper, 'copy-rate-changed-9').text()).toContain('you stay at 20%');

        await byId(wrapper, 'copy-accept-9').trigger('click');
        await flushPromises();
        expect(api.acceptRate).toHaveBeenCalledWith(9);
    });

    it('asks before stopping and explains the open position is kept', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const { wrapper, api } = await mountPanel(makeApi(boardData(), mineData({ follows: [follow()] })));

        await byId(wrapper, 'copy-stop-9').trigger('click');
        expect(confirm.mock.calls[0][0]).toContain('Your open position is not sold');
        expect(api.stopFollow).not.toHaveBeenCalled();

        confirm.mockReturnValue(true);
        await byId(wrapper, 'copy-stop-9').trigger('click');
        await flushPromises();
        expect(api.stopFollow).toHaveBeenCalledWith(9);
    });

    it('offers no stop button on a follow that already ended', async () => {
        const { wrapper } = await mountPanel(makeApi(boardData(), mineData({ follows: [follow({ status: 'stopped' })] })));

        expect(byId(wrapper, 'copy-stop-9').exists()).toBe(false);
        expect(byId(wrapper, 'copy-follow-row-9').text()).toContain('Stopped');
    });
});

describe('AiCopyTradePanel — becoming a master', () => {
    const candidate = (overrides = {}) => ({
        bot_id: 5, name: 'Trend BTC', pair: 'BTC/USDT', eligible: true,
        record: { mode: 'live', closed: 24, return_pct: 16, max_drawdown_pct: 2, days: 60 },
        checks: [
            { code: 'min_days', ok: true, need: 30, have: 60 },
            { code: 'min_closed_trades', ok: true, need: 20, have: 24 },
        ],
        ...overrides,
    });

    it('invites a wallet whose real-money results qualify', async () => {
        const { wrapper } = await mountPanel(makeApi(boardData(), mineData({ invite: true, candidates: [candidate()] })));

        expect(byId(wrapper, 'copy-invite').text()).toContain('Your results qualify you as a master');
        expect(byId(wrapper, 'copy-invite').text()).toContain('never your rules');
        expect(byId(wrapper, 'copy-kyc-needed').exists()).toBe(false);
    });

    it('tells an unverified owner that KYC comes first', async () => {
        const { wrapper } = await mountPanel(makeApi(boardData(), mineData({ invite: true, candidates: [candidate()], kyc_approved: false })));

        expect(byId(wrapper, 'copy-kyc-needed').text()).toContain('KYC');
    });

    it('shows what is still missing instead of an invitation', async () => {
        const short = candidate({ eligible: false, checks: [{ code: 'min_days', ok: true, need: 30, have: 60 }, { code: 'min_closed_trades', ok: false, need: 20, have: 7 }] });
        const { wrapper } = await mountPanel(makeApi(boardData(), mineData({ candidates: [short] })));

        expect(byId(wrapper, 'copy-invite').exists()).toBe(false);
        expect(byId(wrapper, 'copy-progress').text()).toContain('At least 20 closed real-money trades (now 7)');
        expect(byId(wrapper, 'copy-progress').text()).toContain('✓ At least 30 days of real-money trading (now 60)');
    });

    it('shows how the fee splits and registers with the chosen numbers', async () => {
        const { wrapper, api } = await mountPanel(makeApi(boardData(), mineData({ invite: true, candidates: [candidate()] })));

        await byId(wrapper, 'copy-become-master').trigger('click');
        expect(byId(wrapper, 'copy-register-submit').attributes('disabled')).toBeDefined();

        await byId(wrapper, 'copy-register-name').setValue('  Trend Rider ');
        await byId(wrapper, 'copy-register-rate').setValue('25');
        await byId(wrapper, 'copy-register-share').setValue('40');

        expect(byId(wrapper, 'copy-register-split').text()).toBe('Follower profit 100 → fee 25.00 · you get 15.00 · platform gets 10.00');
        expect(byId(wrapper, 'copy-register').text()).toContain('does not change your board ranking');

        await byId(wrapper, 'copy-register').trigger('submit');
        await flushPromises();

        expect(api.registerMaster).toHaveBeenCalledWith({ bot_id: 5, display_name: 'Trend Rider', bio: '', fee_rate_pct: 25, platform_share_pct: 40 });
    });
});

describe('AiCopyTradePanel — my master profile', () => {
    const own = (overrides = {}) => ({ ...master({ followers: 4 }), earnings: { master_usd: 56.4, collect: false }, ...overrides });

    it('shows followers and earnings, and says earnings are only recorded', async () => {
        const { wrapper } = await mountPanel(makeApi(boardData(), mineData({ master: own() })));
        const card = byId(wrapper, 'copy-my-master').text();

        expect(card).toContain('4 followers');
        expect(card).toContain('$56.40');
        expect(card).toContain('nothing has been charged');
        expect(card).toContain('existing followers stay at their rate');
    });

    it('saves new rates, toggles accepting and asks before closing', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const { wrapper, api } = await mountPanel(makeApi(boardData(), mineData({ master: own() })));

        await byId(wrapper, 'copy-rate-input').setValue('30');
        await byId(wrapper, 'copy-rate-save').trigger('click');
        await flushPromises();
        expect(api.updateMaster).toHaveBeenCalledWith({ fee_rate_pct: 30, platform_share_pct: 20 });

        await byId(wrapper, 'copy-toggle-accepting').trigger('click');
        await flushPromises();
        expect(api.updateMaster).toHaveBeenLastCalledWith({ status: 'paused' });

        await byId(wrapper, 'copy-close-master').trigger('click');
        expect(api.closeMaster).not.toHaveBeenCalled();

        confirm.mockReturnValue(true);
        await byId(wrapper, 'copy-close-master').trigger('click');
        await flushPromises();
        expect(api.closeMaster).toHaveBeenCalledTimes(1);
    });

    it('treats a closed profile as no profile', async () => {
        const { wrapper } = await mountPanel(makeApi(boardData(), mineData({ master: own({ status: 'closed' }) })));

        expect(byId(wrapper, 'copy-my-master').exists()).toBe(false);
    });
});

describe('AiCopyTradePanel — switching wallets', () => {
    it('drops the previous wallet data and reloads', async () => {
        const mine = mineData({ follows: [{ id: 9, status: 'active', master: { id: 1, display_name: 'Alpha', fee_rate_pct: 20 }, fee_rate_pct: 20, realized_pnl: 5, fees_usd: 1, fees_tpix: 0, fees_collect: false }] });
        const api = makeApi(boardData(), mine);
        const { wrapper } = await mountPanel(api);

        expect(byId(wrapper, 'copy-my-follows').exists()).toBe(true);

        api.loadMine.mockResolvedValue({ ok: true, data: mineData() });
        await wrapper.setProps({ wallet: '0x2222222222222222222222222222222222222222' });
        await flushPromises();

        expect(byId(wrapper, 'copy-my-follows').exists()).toBe(false);
        expect(api.loadMine).toHaveBeenCalledTimes(2);
    });
});

describe('AiCopyTradePanel — rank and stars', () => {
    const follow = (overrides = {}) => ({
        id: 9, status: 'active', bot_id: 44, master: { id: 1, display_name: 'Alpha', status: 'active', fee_rate_pct: 20 },
        fee_rate_pct: 20, rate_changed: false, realized_pnl: 120, fees_usd: 24, fees_tpix: 48, fees_collect: false,
        can_review: true, my_review: null, ...overrides,
    });

    it('shows the rank name from the server, the rating, and says a rank cannot be bought', async () => {
        const { wrapper } = await mountPanel();

        expect(byId(wrapper, 'copy-rank-1').text()).toBe('Tier 3');
        expect(byId(wrapper, 'copy-rank-1').attributes('title')).toContain('cannot be bought');
        expect(byId(wrapper, 'copy-rating-1').text()).toBe('★ 4.5 out of 5 (12 reviews)');
    });

    it('says so when a master has no rank or no reviews', async () => {
        const board = boardData({ masters: [master({ rank: null, rating: { avg: null, count: 0 } })], promoted: [] });
        const { wrapper } = await mountPanel(makeApi(board));

        expect(byId(wrapper, 'copy-rank-1').text()).toBe('Unranked');
        expect(byId(wrapper, 'copy-rating-1').text()).toBe('★ No reviews yet');
        expect(byId(wrapper, 'copy-reviews-toggle-1').exists()).toBe(false);
    });

    it('renders review text as text, never as markup', async () => {
        const api = makeApi();
        api.loadReviews.mockResolvedValue({ ok: true, data: { reviews: [{ id: 7, stars: 2, comment: '<img src=x onerror=alert(1)>late entries', reviewer: '0xbbbb…bbbb' }] } });
        const { wrapper } = await mountPanel(api);

        await byId(wrapper, 'copy-reviews-toggle-1').trigger('click');
        await flushPromises();

        const row = byId(wrapper, 'copy-review-7');
        expect(row.text()).toContain('★★');
        expect(row.text()).toContain('<img src=x onerror=alert(1)>late entries');
        expect(row.find('img').exists()).toBe(false);
        expect(api.loadReviews).toHaveBeenCalledWith(1);
    });

    it('asks before reporting a review', async () => {
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const api = makeApi();
        api.loadReviews.mockResolvedValue({ ok: true, data: { reviews: [{ id: 7, stars: 1, comment: 'x', reviewer: '0xbbbb…bbbb' }] } });
        const { wrapper } = await mountPanel(api);

        await byId(wrapper, 'copy-reviews-toggle-1').trigger('click');
        await flushPromises();
        await byId(wrapper, 'copy-report-7').trigger('click');
        expect(api.reportReview).not.toHaveBeenCalled();

        confirm.mockReturnValue(true);
        await byId(wrapper, 'copy-report-7').trigger('click');
        await flushPromises();
        expect(api.reportReview).toHaveBeenCalledWith(7);
        expect(wrapper.emitted('notify').pop()[0]).toEqual({ type: 'success', text: 'Report sent — the team will look at it' });
    });

    it('lets an eligible follower rate the master and needs a star before sending', async () => {
        const { wrapper, api } = await mountPanel(makeApi(boardData(), mineData({ follows: [follow()] })));

        expect(byId(wrapper, 'copy-review-submit-9').attributes('disabled')).toBeDefined();

        await byId(wrapper, 'copy-star-9-4').trigger('click');
        await byId(wrapper, 'copy-review-comment-9').setValue('steady');
        await byId(wrapper, 'copy-review-form-9').trigger('submit');
        await flushPromises();

        expect(api.review).toHaveBeenCalledWith(1, { stars: 4, comment: 'steady' });
    });

    it('prefills an existing review for editing', async () => {
        const { wrapper } = await mountPanel(makeApi(boardData(), mineData({ follows: [follow({ my_review: { stars: 2, comment: 'worse lately' } })] })));

        expect(byId(wrapper, 'copy-review-form-9').text()).toContain('Edit your review');
        expect(byId(wrapper, 'copy-review-comment-9').element.value).toBe('worse lately');
        expect(byId(wrapper, 'copy-star-9-2').classes()).toContain('text-amber-300');
        expect(byId(wrapper, 'copy-star-9-3').classes()).toContain('text-dark-600');
    });

    it('explains why a follower cannot review yet', async () => {
        const { wrapper } = await mountPanel(makeApi(boardData(), mineData({ follows: [follow({ can_review: false })] })));

        expect(byId(wrapper, 'copy-review-form-9').exists()).toBe(false);
        expect(byId(wrapper, 'copy-follow-row-9').text()).toContain('You can review after copying with real money');
    });
});
