/**
 * TPIX TRADE — FoodPassport: ลงทะเบียนสินค้า + mint ใบรับรอง จากหน้าเว็บ
 *
 * กันอาการเดิมกลับมา:
 *  - ยิงด้วย fetch ที่ไม่ส่ง CSRF → Sanctum ตอบ 419 ทุกครั้งบนโดเมนของเว็บ
 *  - ส่งแค่ producer_address / owner_address ที่ด่านกระเป๋ามองไม่เห็น (เซิร์ฟเวอร์ไม่ตรวจอะไรเลย)
 *  - 403 เพราะยังไม่เซ็นยืนยัน ขึ้นเป็นข้อความ error ดิบ ไม่มีทางไปต่อ
 *  - คำตอบของกระเป๋าเดิมที่มาช้า ไปโผล่ในรายการของกระเป๋าใบใหม่
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import { reactive } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const wallet = reactive({
    address: null,
    isConnected: false,
    verifyOwnership: vi.fn(),
    openConnectModal: vi.fn(),
});

vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const post = vi.fn();
vi.mock('axios', () => ({ default: { post: (...args) => post(...args) } }));

const ProductRegistration = (await import('@/Components/FoodPassport/ProductRegistration.vue')).default;
const MintCertificate = (await import('@/Components/FoodPassport/MintCertificate.vue')).default;

const WALLET_A = '0x1111111111111111111111111111111111111111';
const WALLET_B = '0x2222222222222222222222222222222222222222';

const CATEGORIES = { grain: { emoji: '🌾', label: 'ธัญพืช' } };
const PRODUCT = { id: 42, name: 'ข้าวหอมมะลิ', category: 'grain', origin: 'สุรินทร์', batch_number: 'BATCH-42', traces_count: 2 };

function connect(address) {
    wallet.address = address;
    wallet.isConnected = !!address;
}

const ok = (data) => Promise.resolve({ data: { success: true, data } });
const fail = (status, code) => Promise.reject({ response: { status, data: { success: false, error: { code, message: `raw ${code}` } } } });

function deferred() {
    let resolve;
    const promise = new Promise((r) => { resolve = r; });
    return { promise, resolve };
}

function mountRegistration() {
    return mount(ProductRegistration, {
        props: { categories: CATEGORIES, walletAddress: wallet.address, isConnected: wallet.isConnected },
    });
}

async function fillForm(w) {
    await w.find('input[placeholder^="เช่น ข้าวหอมมะลิ"]').setValue('ข้าวหอมมะลิ');
    await w.findAll('button').find((b) => b.text().includes('ธัญพืช')).trigger('click');
    await w.find('input[placeholder^="เช่น สุรินทร์"]').setValue('สุรินทร์');
}

const submitButton = (w) => w.findAll('button').find((b) => b.text().includes('ลงทะเบียนสินค้า'));

function mountMint() {
    return mount(MintCertificate, {
        props: { product: PRODUCT, walletAddress: wallet.address, contractAddress: '0x3333333333333333333333333333333333333333' },
    });
}

const mintButton = (w) => w.findAll('button').find((b) => b.text() === 'Mint Certificate');

beforeEach(() => {
    post.mockReset();
    wallet.verifyOwnership.mockReset();
    wallet.openConnectModal.mockReset();
    global.fetch = vi.fn(() => { throw new Error('fetch must not be used for FoodPassport writes'); });
    connect(WALLET_A);
});

describe('ProductRegistration', () => {
    it('ส่งด้วย axios พร้อม wallet_address ของกระเป๋าที่เชื่อมอยู่ แล้วแจ้ง registered', async () => {
        post.mockImplementation(() => ok({ id: 7, batch_number: 'BATCH-7' }));
        const w = mountRegistration();
        await fillForm(w);

        await submitButton(w).trigger('click');
        await flushPromises();

        expect(global.fetch).not.toHaveBeenCalled();
        expect(post).toHaveBeenCalledTimes(1);
        const [url, body] = post.mock.calls[0];
        expect(url).toBe('/api/v1/food-passport/register');
        expect(body).toMatchObject({ wallet_address: WALLET_A, name: 'ข้าวหอมมะลิ', category: 'grain', origin: 'สุรินทร์' });
        expect(body).not.toHaveProperty('producer_address');
        expect(w.emitted('registered')[0][0]).toMatchObject({ id: 7 });
        expect(w.text()).toContain('Product ID: 7');
    });

    it('กดซ้ำระหว่างรอ ส่งคำขอเดียว', async () => {
        const d = deferred();
        post.mockReturnValue(d.promise);
        const w = mountRegistration();
        await fillForm(w);

        await submitButton(w).trigger('click');
        await w.vm.$nextTick();
        await w.findAll('button').at(-1).trigger('click');
        d.resolve({ data: { success: true, data: { id: 1, batch_number: 'B' } } });
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('403 ยังไม่เซ็นยืนยัน → ปุ่มเซ็น แล้วส่งฟอร์มเดิมต่อโดยข้อมูลไม่หาย', async () => {
        post.mockImplementationOnce(() => fail(403, 'WALLET_NOT_VERIFIED')).mockImplementationOnce(() => ok({ id: 8, batch_number: 'BATCH-8' }));
        wallet.verifyOwnership.mockResolvedValue(true);
        const w = mountRegistration();
        await fillForm(w);

        await submitButton(w).trigger('click');
        await flushPromises();

        expect(w.text()).toContain('ต้องเซ็นยืนยันกระเป๋าก่อน');
        expect(w.text()).not.toContain('raw WALLET_NOT_VERIFIED');
        expect(w.emitted('registered')).toBeFalsy();

        await w.find('[data-test="verify-wallet"]').trigger('click');
        await flushPromises();

        expect(wallet.verifyOwnership).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledTimes(2);
        expect(post.mock.calls[1][1]).toMatchObject({ wallet_address: WALLET_A, name: 'ข้าวหอมมะลิ' });
        expect(w.emitted('registered')[0][0]).toMatchObject({ id: 8 });
    });

    it('กระเป๋าฝังที่ยังไม่ปลดล็อก → เปิดหน้าต่างเชื่อมกระเป๋า ไม่ส่งซ้ำ', async () => {
        post.mockImplementation(() => fail(403, 'WALLET_IP_MISMATCH'));
        wallet.verifyOwnership.mockResolvedValue(false);
        const w = mountRegistration();
        await fillForm(w);
        await submitButton(w).trigger('click');
        await flushPromises();

        await w.find('[data-test="verify-wallet"]').trigger('click');
        await flushPromises();

        expect(wallet.openConnectModal).toHaveBeenCalled();
        expect(post).toHaveBeenCalledTimes(1);
    });

    it('error อื่นแสดงข้อความไทย ไม่ใช่ข้อความดิบ และไม่มีปุ่มเซ็น', async () => {
        post.mockImplementationOnce(() => fail(419, 'CSRF')).mockImplementationOnce(() => Promise.reject(new Error('Network Error')));
        const w = mountRegistration();
        await fillForm(w);

        await submitButton(w).trigger('click');
        await flushPromises();
        expect(w.text()).toContain('รีเฟรชหน้า');
        expect(w.find('[data-test="verify-wallet"]').exists()).toBe(false);

        await submitButton(w).trigger('click');
        await flushPromises();
        expect(w.text()).toContain('เชื่อมต่อเซิร์ฟเวอร์ไม่ได้');
        expect(w.text()).not.toContain('Network Error');
    });

    it('สลับกระเป๋าระหว่างรอ → ไม่แจ้ง registered (สินค้าเป็นของกระเป๋าเดิม)', async () => {
        const d = deferred();
        post.mockReturnValue(d.promise);
        const w = mountRegistration();
        await fillForm(w);

        await submitButton(w).trigger('click');
        connect(WALLET_B);
        d.resolve({ data: { success: true, data: { id: 9, batch_number: 'B9' } } });
        await flushPromises();

        expect(w.emitted('registered')).toBeFalsy();
        expect(w.text()).not.toContain('Product ID: 9');
    });
});

describe('MintCertificate', () => {
    it('ส่งด้วย axios พร้อม wallet_address — ไม่ส่ง owner_address', async () => {
        post.mockImplementation(() => ok({ id: 3, token_id: 77, owner_address: WALLET_A }));
        const w = mountMint();

        await mintButton(w).trigger('click');
        await flushPromises();

        expect(global.fetch).not.toHaveBeenCalled();
        const [url, body] = post.mock.calls[0];
        expect(url).toBe('/api/v1/food-passport/mint/42');
        expect(body).toMatchObject({ wallet_address: WALLET_A, contract_address: '0x3333333333333333333333333333333333333333' });
        expect(body).not.toHaveProperty('owner_address');
        expect(w.emitted('minted')[0][0]).toMatchObject({ id: 3 });
    });

    it('403 ยังไม่เซ็นยืนยัน → ปุ่มเซ็น แล้ว mint ต่อ', async () => {
        post.mockImplementationOnce(() => fail(403, 'WALLET_NOT_VERIFIED')).mockImplementationOnce(() => ok({ id: 4, token_id: 1 }));
        wallet.verifyOwnership.mockResolvedValue(true);
        const w = mountMint();

        await mintButton(w).trigger('click');
        await flushPromises();
        expect(w.text()).toContain('ต้องเซ็นยืนยันกระเป๋าก่อน');

        await w.find('[data-test="verify-wallet"]').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledTimes(2);
        expect(w.emitted('minted')[0][0]).toMatchObject({ id: 4 });
    });

    it('สินค้าของคนอื่น → ข้อความไทย กลับไปหน้ายืนยัน', async () => {
        post.mockImplementation(() => fail(403, 'NOT_PRODUCER'));
        const w = mountMint();

        await mintButton(w).trigger('click');
        await flushPromises();

        expect(w.text()).toContain('เฉพาะกระเป๋าผู้ผลิต');
        expect(w.find('[data-test="verify-wallet"]').exists()).toBe(false);
        expect(mintButton(w).exists()).toBe(true);
        expect(w.emitted('minted')).toBeFalsy();
    });

    it('สลับกระเป๋าระหว่างรอ → ปิดหน้าต่าง ไม่แจ้ง minted', async () => {
        const d = deferred();
        post.mockReturnValue(d.promise);
        const w = mountMint();

        await mintButton(w).trigger('click');
        connect(WALLET_B);
        d.resolve({ data: { success: true, data: { id: 5 } } });
        await flushPromises();

        expect(w.emitted('minted')).toBeFalsy();
        expect(w.emitted('close')).toBeTruthy();
    });
});
