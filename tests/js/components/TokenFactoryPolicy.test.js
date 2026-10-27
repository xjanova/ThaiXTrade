/**
 * TPIX TRADE — ฟอร์มสร้างเหรียญตามนโยบายของผู้ดูแล
 *
 * เจ้าของสั่ง: ของเสี่ยงปิดไว้ก่อน · เปิดแล้วผู้ใช้ต้องเห็นคำเตือนสีแดงและติ๊กยอมรับ
 *
 * กัน: ตัวเลือก/ชนิดที่ปิดโผล่ในฟอร์ม · เปิดของเสี่ยงได้โดยไม่เห็นคำเตือน · ไปต่อได้โดยไม่ติ๊กยอมรับ
 *      · ไม่ส่ง risk_ack ให้เซิร์ฟเวอร์ · ตัวเลือก Testnet ฟรีกลับมา
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { reactive } from 'vue';

const wallet = reactive({
    address: '0x1111111111111111111111111111111111111111',
    isConnected: true,
    chainId: 4289,
    signer: null,
    openConnectModal: vi.fn(),
    switchChain: vi.fn(),
});

const store = reactive({
    tokens: [],
    myTokens: [],
    fetchTokens: vi.fn(async () => {}),
    fetchMyTokens: vi.fn(async () => {}),
    requestQuote: vi.fn(),
    checkFeeStatus: vi.fn(),
    createToken: vi.fn(),
    requestSourceVerifyQuote: vi.fn(),
    purchaseSourceVerify: vi.fn(),
});

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: {} }),
    router: { visit: vi.fn() },
}));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/AwaitingDeployNotice.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));
vi.mock('@/Stores/tokenFactoryStore', () => ({ useTokenFactoryStore: () => store }));
vi.mock('@/utils/web3', () => ({
    addTokenToWallet: vi.fn(),
    switchToChain: vi.fn(),
    TPIX_CHAIN_CONFIG: { chainIdNum: 4289 },
}));

import TokenFactory from '@/Pages/TokenFactory.vue';

const PAUSABLE_WARNING = 'เจ้าของเหรียญหยุดการโอนทั้งหมดได้ทุกเมื่อ — ระหว่างที่หยุด ไม่มีใครโอน ขาย หรือถอนสภาพคล่องเหรียญนี้ได้เลย';

/** นโยบายรูปแบบเดียวกับ TokenFactoryPolicy::publicConfig() */
function makePolicy() {
    const type = (state, extra = {}) => ({
        state, fee: 50, max_supply: '1000000000000000', decimals_min: 18, decimals_max: 18,
        risky: false, warning_th: null, locked_reason: state === 'locked' ? 'ล็อกถาวร' : null, ...extra,
    });
    const option = (state, extra = {}) => ({
        state, fee: 5, risky: true, warning_th: 'เตือน', locked_reason: null, requires: null,
        applies_to: ['erc20', 'governance'], ...extra,
    });

    return {
        chain_id: 4289,
        currency: 'TPIX',
        fee_wallet: '0x9999999999999999999999999999999999999999',
        types: {
            erc20: type('on', { label_th: 'เหรียญ ERC-20 มาตรฐาน', category: 'fungible', ui_types: ['standard', 'mintable', 'burnable', 'mintable_burnable'] }),
            utility: type('locked', { label_th: 'Utility', category: 'fungible', ui_types: ['utility'], risky: true }),
            reward: type('locked', { label_th: 'Reward', category: 'fungible', ui_types: ['reward'], risky: true }),
            governance: type('off', { label_th: 'Governance', category: 'special', ui_types: ['governance'], risky: true }),
            stablecoin: type('locked', { label_th: 'Stablecoin', category: 'special', ui_types: ['stablecoin'], risky: true }),
            nft: type('off', { label_th: 'NFT', category: 'nft', ui_types: ['nft'], risky: true, decimals_min: 0, decimals_max: 0 }),
            nft_collection: type('off', { label_th: 'NFT Collection', category: 'nft', ui_types: ['nft_collection'], risky: true, decimals_min: 0, decimals_max: 0 }),
        },
        options: {
            burnable: option('on', { label_th: 'เผาเหรียญได้', risky: false, fee: 0, ui_keys: ['burnable'] }),
            mintable: option('off', { label_th: 'Mint เพิ่มได้', ui_keys: ['mintable'] }),
            mint_cap: option('off', { label_th: 'เพดาน Mint', risky: false, requires: 'mintable', ui_keys: ['mint_cap_enabled', 'mint_cap'] }),
            pausable: option('on', { label_th: 'หยุดการโอนได้ (Pausable)', warning_th: PAUSABLE_WARNING, ui_keys: ['pausable'] }),
            blacklist: option('off', { label_th: 'บัญชีดำ (Blacklist)', ui_keys: ['blacklist_enabled'] }),
            auto_burn: option('locked', { label_th: 'Auto Burn', applies_to: ['erc20'], ui_keys: ['auto_burn_enabled', 'auto_burn_rate', 'burn_floor'] }),
            source_verify: option('on', { label_th: 'ยืนยันซอร์สโค้ดบน Explorer', risky: false, fee: 1000, ui_keys: ['source_verify'] }),
        },
        limits: { name_max_length: 50, symbol_max_length: 11, daily_limit_per_wallet: 3, required_confirmations: 2, quote_ttl_minutes: 30 },
        reserved_symbols: ['TPIX', 'USDT', 'IDTC'],
    };
}

const mounted = [];
function mountPage() {
    const w = mount(TokenFactory, {
        props: {
            tokens: { data: [] },
            factoryConfig: {
                creation_enabled: true,
                ready: true,
                awaiting_deploy: false,
                issues: [],
                max_supply_limit: '1000000000000000',
                policy: makePolicy(),
            },
        },
    });
    mounted.push(w);
    return w;
}

const buttonWithText = (w, text) => w.findAll('button').find((b) => b.text().trim() === text);

async function next(w) {
    await buttonWithText(w, 'Next').trigger('click');
}

beforeEach(() => {
    localStorage.clear();
    store.requestQuote.mockReset();
    store.createToken.mockReset();
    store.checkFeeStatus.mockReset();
    store.requestSourceVerifyQuote.mockReset();
    store.purchaseSourceVerify.mockReset();
    store.myTokens = [];
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

describe('TokenFactory — policy-driven form', () => {
    it('hides token types and categories the admin has not enabled, and offers no testnet', async () => {
        const w = mountPage();
        await flushPromises();

        // หมวด NFT / พิเศษ ไม่มีชนิดไหนเปิด → กดไม่ได้
        expect(w.text()).toContain('ยังไม่เปิด');
        expect(w.text()).not.toContain('TPIX Testnet');

        await next(w);

        const text = w.text();
        expect(text).toContain('Standard');
        expect(text).toContain('Burnable');
        expect(text).not.toContain('Mintable');       // mintable ปิด
        expect(text).not.toContain('Full Function');  // ต้องใช้ mintable
        expect(text).not.toContain('Utility Token');  // ล็อก
        expect(text).not.toContain('Reward Token');   // ล็อก
    });

    it('hides disabled and locked sub-options', async () => {
        const w = mountPage();
        await flushPromises();
        await next(w);
        await next(w);

        expect(w.find('[data-test="opt-pausable"]').exists()).toBe(true);
        expect(w.find('[data-test="opt-blacklist_enabled"]').exists()).toBe(false);
        expect(w.find('[data-test="opt-auto_burn_enabled"]').exists()).toBe(false);
        expect(w.find('[data-test="opt-mint_cap_enabled"]').exists()).toBe(false);
    });

    it('shows a red warning with a required checkbox for an enabled risky option and blocks Next until ticked', async () => {
        const w = mountPage();
        await flushPromises();
        await next(w);
        await next(w);

        expect(w.find('[data-test="risk-warning-pausable"]').exists()).toBe(false);

        await w.find('[data-test="opt-pausable"]').trigger('click');

        const box = w.find('[data-test="risk-warning-pausable"]');
        expect(box.exists()).toBe(true);
        expect(box.classes().join(' ')).toContain('bg-red-500/10');
        expect(box.classes().join(' ')).toContain('border-red-500/50');
        expect(box.text()).toContain(PAUSABLE_WARNING);

        const checkbox = w.find('[data-test="risk-ack-pausable"]');
        expect(checkbox.attributes('required')).toBeDefined();
        expect(buttonWithText(w, 'Next').attributes('disabled')).toBeDefined();

        await checkbox.setValue(true);
        expect(buttonWithText(w, 'Next').attributes('disabled')).toBeUndefined();
    });

    it('sends the acknowledged risks with the quote and the create request', async () => {
        store.requestQuote.mockResolvedValue({
            quote_id: '11111111-2222-3333-4444-555555555555', amount: '0', amount_wei: '0',
            fee_wallet: null, chain_id: 4289, is_free: true, expires_at: '2026-10-11T10:00:00Z',
        });
        store.createToken.mockResolvedValue({ id: 1, status: 'pending' });

        const w = mountPage();
        await flushPromises();
        await next(w);
        await next(w);
        await w.find('[data-test="opt-pausable"]').trigger('click');
        await w.find('[data-test="risk-ack-pausable"]').setValue(true);
        await next(w);

        await w.find('input[placeholder="e.g. My Token"]').setValue('Good Coin');
        const symbol = w.find('[data-test="symbol-input"]');
        await symbol.setValue('go-od!ไทย');
        expect(symbol.element.value).toBe('GOOD'); // ASCII A–Z0–9 เท่านั้น
        await w.find('input[type="number"]').setValue('1000000');
        await next(w);

        expect(w.find('[data-test="risk-summary"]').text()).toContain('หยุดการโอนได้');

        await buttonWithText(w, 'ชำระและสร้างเหรียญ').trigger('click');
        await flushPromises();

        expect(store.requestQuote).toHaveBeenCalledTimes(1);
        const quotePayload = store.requestQuote.mock.calls[0][0];
        expect(quotePayload.risk_ack).toEqual(['pausable']);
        expect(quotePayload.sub_options.pausable).toBe(true);
        expect(quotePayload.chain_id).toBe(4289);
        expect(quotePayload.symbol).toBe('GOOD');

        expect(store.createToken).toHaveBeenCalledTimes(1);
        const createPayload = store.createToken.mock.calls[0][0];
        expect(createPayload.quote_id).toBe('11111111-2222-3333-4444-555555555555');
        expect(createPayload.risk_ack).toEqual(['pausable']);
        expect(createPayload.fee_tx_hash).toBeNull();
    });

    async function fillAndReview(w) {
        await next(w);
        await next(w);
        await next(w);
        await w.find('input[placeholder="e.g. My Token"]').setValue('Good Coin');
        await w.find('[data-test="symbol-input"]').setValue('GOOD');
        await w.find('input[type="number"]').setValue('1000000');
        await next(w);
    }

    it('pays the quoted fee in native TPIX on chain 4289, waits for confirmations, then creates', async () => {
        const feeHash = '0x' + 'ab'.repeat(32);
        const sendTransaction = vi.fn(async () => ({ hash: feeHash }));
        wallet.signer = { sendTransaction };
        store.requestQuote.mockResolvedValue({
            quote_id: 'q-1', amount: '50', amount_wei: '50000000000000000000',
            fee_wallet: '0x9999999999999999999999999999999999999999', chain_id: 4289, is_free: false,
        });
        store.checkFeeStatus.mockResolvedValue({ confirmed: true, confirmations: 3, required: 2 });
        store.createToken.mockResolvedValue({ id: 2, status: 'pending' });

        const w = mountPage();
        await flushPromises();
        await fillAndReview(w);
        await buttonWithText(w, 'ชำระและสร้างเหรียญ').trigger('click');
        await flushPromises();

        expect(sendTransaction).toHaveBeenCalledWith({
            to: '0x9999999999999999999999999999999999999999',
            value: 50000000000000000000n,
            gasPrice: 0,
        });
        expect(store.checkFeeStatus).toHaveBeenCalledWith(expect.objectContaining({ quote_id: 'q-1', fee_tx_hash: feeHash }));
        expect(store.createToken.mock.calls[0][0].fee_tx_hash).toBe(feeHash);
        expect(localStorage.getItem('tpix_factory_pending_fee_' + wallet.address)).toBeNull();
        wallet.signer = null;
    });

    it('keeps a paid-but-not-submitted fee so the user can resume without paying twice', async () => {
        const feeHash = '0x' + 'cd'.repeat(32);
        const sendTransaction = vi.fn(async () => ({ hash: feeHash }));
        wallet.signer = { sendTransaction };
        store.requestQuote.mockResolvedValue({
            quote_id: 'q-2', amount: '50', amount_wei: '50000000000000000000',
            fee_wallet: '0x9999999999999999999999999999999999999999', chain_id: 4289, is_free: false,
        });
        store.checkFeeStatus.mockResolvedValue({ confirmed: true, confirmations: 2, required: 2 });
        store.createToken
            .mockRejectedValueOnce({ response: { data: { errors: { symbol: ['สัญลักษณ์ GOOD มีคนใช้แล้ว'] } } } })
            .mockResolvedValueOnce({ id: 3, status: 'pending' });

        const w = mountPage();
        await flushPromises();
        await fillAndReview(w);
        await buttonWithText(w, 'ชำระและสร้างเหรียญ').trigger('click');
        await flushPromises();

        expect(w.text()).toContain('สัญลักษณ์ GOOD มีคนใช้แล้ว');
        expect(w.find('[data-test="pending-payment"]').exists()).toBe(true);
        expect(localStorage.getItem('tpix_factory_pending_fee_' + wallet.address)).not.toBeNull();

        await buttonWithText(w, 'ส่งคำขอต่อ (ไม่ต้องจ่ายซ้ำ)').trigger('click');
        await flushPromises();

        expect(sendTransaction).toHaveBeenCalledTimes(1);
        expect(store.createToken).toHaveBeenCalledTimes(2);
        expect(store.createToken.mock.calls[1][0].fee_tx_hash).toBe(feeHash);
        expect(w.find('[data-test="pending-payment"]').exists()).toBe(false);
        wallet.signer = null;
    });
});

describe('TokenFactory — My Tokens: ยืนยันซอร์สโค้ด (เสียเงิน) และสถานะคืนเงิน', () => {
    const FEE_WALLET = '0x9999999999999999999999999999999999999999';
    const pendingKey = () => 'tpix_factory_pending_verify_' + wallet.address.toLowerCase();
    const deployed = (overrides = {}) => ({
        id: 7, name: 'Good Coin', symbol: 'GOOD', status: 'deployed', total_supply: '1000', decimals: 18,
        token_type: 'standard', contract_address: '0x' + 'c'.repeat(40), metadata: {}, ...overrides,
    });

    async function openMyTokens(tokens) {
        store.myTokens = tokens;
        const w = mountPage();
        await flushPromises();
        await buttonWithText(w, 'My Tokens').trigger('click');
        return w;
    }

    it('เหรียญที่ยังไม่จ่ายเห็นปุ่มพร้อมราคา ส่วนที่จ่าย/ยืนยันแล้วเห็นสถานะแทน', async () => {
        const w = await openMyTokens([
            deployed(),
            deployed({ id: 8, symbol: 'PAID', metadata: { source_verify: { paid: true }, explorer_verification: { status: 'submitted' } } }),
            deployed({ id: 9, symbol: 'DONE', metadata: { explorer_verification: { status: 'verified' } } }),
        ]);

        const buttons = w.findAll('[data-test="source-verify-button"]');
        expect(buttons).toHaveLength(1);
        expect(buttons[0].text()).toContain('1,000 TPIX');
        expect(w.text()).toContain('explorer กำลังตรวจซอร์สโค้ด');
        expect(w.text()).toContain('ซอร์สโค้ดยืนยันบน explorer แล้ว');
    });

    it('จ่ายบนเชน 4289 รอยืนยัน แล้วส่ง tx ให้เซิร์ฟเวอร์ — สำเร็จแล้วไม่เหลือรายการค้าง', async () => {
        const feeHash = '0x' + 'ef'.repeat(32);
        const sendTransaction = vi.fn(async () => ({ hash: feeHash }));
        wallet.signer = { sendTransaction };
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
        store.requestSourceVerifyQuote.mockResolvedValue({
            quote_id: 'sv-1', amount: '1000', amount_wei: '1000000000000000000000',
            fee_wallet: FEE_WALLET, chain_id: 4289, is_free: false,
        });
        store.checkFeeStatus.mockResolvedValue({ confirmed: true, confirmations: 2, required: 2 });
        store.purchaseSourceVerify.mockResolvedValue(deployed({ metadata: { source_verify: { paid: true } } }));

        const w = await openMyTokens([deployed()]);
        await w.find('[data-test="source-verify-button"]').trigger('click');
        await flushPromises();

        expect(store.requestSourceVerifyQuote).toHaveBeenCalledWith(7, wallet.address);
        expect(confirmSpy.mock.calls[0][0]).toContain('1000 TPIX');
        expect(sendTransaction).toHaveBeenCalledWith({ to: FEE_WALLET, value: 1000000000000000000000n, gasPrice: 0 });
        expect(store.purchaseSourceVerify).toHaveBeenCalledWith(7, { wallet_address: wallet.address.toLowerCase(), quote_id: 'sv-1', fee_tx_hash: feeHash });
        expect(localStorage.getItem(pendingKey())).toBeNull();

        confirmSpy.mockRestore();
        wallet.signer = null;
    });

    it('ยกเลิกในกล่องยืนยัน = ไม่โอนเงิน', async () => {
        const sendTransaction = vi.fn();
        wallet.signer = { sendTransaction };
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
        store.requestSourceVerifyQuote.mockResolvedValue({ quote_id: 'sv-2', amount: '1000', amount_wei: '1', fee_wallet: FEE_WALLET, chain_id: 4289 });

        const w = await openMyTokens([deployed()]);
        await w.find('[data-test="source-verify-button"]').trigger('click');
        await flushPromises();

        expect(sendTransaction).not.toHaveBeenCalled();
        confirmSpy.mockRestore();
        wallet.signer = null;
    });

    it('จ่ายแล้วแต่ส่งไม่สำเร็จ — กดส่งต่อได้โดยไม่โอนซ้ำ', async () => {
        const feeHash = '0x' + '12'.repeat(32);
        const sendTransaction = vi.fn(async () => ({ hash: feeHash }));
        wallet.signer = { sendTransaction };
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
        store.requestSourceVerifyQuote.mockResolvedValue({ quote_id: 'sv-3', amount: '1000', amount_wei: '1000', fee_wallet: FEE_WALLET, chain_id: 4289 });
        store.checkFeeStatus.mockResolvedValue({ confirmed: true, confirmations: 2, required: 2 });
        store.purchaseSourceVerify
            .mockRejectedValueOnce({ response: { status: 503, data: { error: { code: 'CHAIN_UNAVAILABLE', message: 'ติดต่อเชนไม่ได้' } } } })
            .mockResolvedValueOnce(deployed());

        const w = await openMyTokens([deployed()]);
        await w.find('[data-test="source-verify-button"]').trigger('click');
        await flushPromises();

        expect(localStorage.getItem(pendingKey())).toContain(feeHash);
        expect(w.find('[data-test="source-verify-button"]').text()).toContain('ไม่ต้องจ่ายซ้ำ');

        await w.find('[data-test="source-verify-button"]').trigger('click');
        await flushPromises();

        expect(sendTransaction).toHaveBeenCalledTimes(1);
        expect(store.purchaseSourceVerify).toHaveBeenCalledTimes(2);
        expect(store.purchaseSourceVerify.mock.calls[1][1].fee_tx_hash).toBe(feeHash);
        expect(localStorage.getItem(pendingKey())).toBeNull();
        confirmSpy.mockRestore();
        wallet.signer = null;
    });

    it('คำขอที่ถูกปฏิเสธแสดงสถานะคืนค่าธรรมเนียม', async () => {
        const w = await openMyTokens([
            deployed({ id: 11, status: 'rejected', contract_address: null, reject_reason: 'x', refund_status: 'pending', refund_amount_wei: '50000000000000000000' }),
            deployed({ id: 12, symbol: 'R2', status: 'rejected', contract_address: null, refund_status: 'refunded', refund_amount_wei: '50000000000000000000', refund_tx_hash: '0x' + '6'.repeat(64) }),
        ]);

        const notes = w.findAll('[data-test="refund-note"]');
        expect(notes).toHaveLength(2);
        expect(notes[0].text()).toContain('รอคืนค่าธรรมเนียม');
        expect(notes[0].text()).toContain('50 TPIX');
        expect(notes[1].text()).toContain('คืนค่าธรรมเนียมแล้ว');
        expect(notes[1].find('a').attributes('href')).toContain('/tx/0x6666');
        expect(w.find('[data-test="source-verify-button"]').exists()).toBe(false);
    });
});

