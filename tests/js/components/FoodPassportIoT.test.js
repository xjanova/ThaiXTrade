/**
 * TPIX TRADE — FoodPassport: รายการ "ของฉัน" + API key ของอุปกรณ์ IoT
 *
 * กันอาการเดิมกลับมา:
 *  - หน้าเว็บขอรายการด้วย ?address= ที่ด่านกระเป๋ามองไม่เห็น (เซิร์ฟเวอร์เลยคืนของใครก็ได้)
 *  - 403 (ยังไม่เซ็นยืนยัน) ดูเหมือน "ยังไม่มีสินค้า" / คำตอบของกระเป๋าเก่าที่มาช้าทับของใบใหม่
 *  - ลงทะเบียนเครื่องแล้วไม่มีกุญแจให้ใส่เฟิร์มแวร์ / ออกกุญแจใหม่ทับของเดิมโดยไม่ถาม
 *  - ปุ่มทดสอบยิงข้อมูลปลอมเข้า /iot/ingest จากเบราว์เซอร์
 *
 * Developed by Xman Studio
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { reactive } from 'vue';
import { mount, flushPromises } from '@vue/test-utils';

const wallet = reactive({
    address: null,
    isConnected: false,
    verifiedAt: null,
    verifyOwnership: vi.fn(),
    openConnectModal: vi.fn(),
});

vi.mock('@/Stores/walletStore', () => ({ useWalletStore: () => wallet }));

const post = vi.fn();
vi.mock('axios', () => ({ default: { post: (...args) => post(...args) } }));

vi.mock('@inertiajs/vue3', () => ({ Head: { template: '<div />' } }));
vi.mock('@/Layouts/AppLayout.vue', () => ({ default: { template: '<div><slot /></div>' } }));
vi.mock('@/Components/FoodPassport/FoodPassportHero.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Components/FoodPassport/HowItWorks.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Components/FoodPassport/ProductRegistration.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Components/FoodPassport/TraceTimeline.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Components/FoodPassport/MintCertificate.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Components/FoodPassport/ProductExplorer.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Components/FoodPassport/TokenGuide.vue', () => ({ default: { template: '<div />' } }));
vi.mock('@/Components/FoodPassport/FDPTokenPanel.vue', () => ({ default: { template: '<div />' } }));

const FoodPassport = (await import('@/Pages/FoodPassport.vue')).default;
const IoTDashboard = (await import('@/Components/FoodPassport/IoTDashboard.vue')).default;

const WALLET_A = '0x1111111111111111111111111111111111111111';
const WALLET_B = '0x2222222222222222222222222222222222222222';
const KEY = `fpk_${'a'.repeat(64)}`;
const KEY_2 = `fpk_${'b'.repeat(64)}`;

const DEVICE = {
    id: 1,
    device_id: 'TPIX-IOT-0WNER001',
    name: 'Sensor ฟาร์มข้าว',
    type: 'multi',
    status: 'active',
    location: '14.8826,103.4945',
    config: { protocol: 'http', interval_minutes: 15 },
    has_api_key: true,
    last_ping_at: null,
};

const LEGACY_DEVICE = { ...DEVICE, id: 2, device_id: 'TPIX-IOT-LEGACY01', has_api_key: false };

const product = (id, name) => ({ id, name, category: 'grain', origin: 'สุรินทร์', batch_number: `BATCH-${id}`, status: 'registered' });

function connect(address) {
    wallet.address = address;
    wallet.isConnected = !!address;
}

function jsonResponse(body, status = 200) {
    return { status, json: async () => body };
}

const page = (rows) => ({ success: true, data: { data: rows } });

/** เส้นทาง → คำตอบ (ฟังก์ชันหรือ Promise ก็ได้) — ที่ไม่ได้ระบุคืนรายการว่าง */
function routeFetch(routes) {
    global.fetch = vi.fn((url) => {
        const match = Object.keys(routes).find((prefix) => String(url).startsWith(prefix));
        const handler = match ? routes[match] : () => jsonResponse(page([]));
        return Promise.resolve(typeof handler === 'function' ? handler(url) : handler);
    });
}

const mounted = [];

function mountTracked(component, options) {
    const wrapper = mount(component, options);
    mounted.push(wrapper);
    return wrapper;
}

const buttonWithText = (wrapper, text) => wrapper.findAll('button').find((b) => b.text().includes(text));

beforeEach(() => {
    connect(null);
    wallet.verifiedAt = null;
    wallet.verifyOwnership.mockReset();
    wallet.openConnectModal.mockReset();
    post.mockReset();
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    vi.restoreAllMocks();
});

describe('FoodPassport page — รายการของฉัน', () => {
    it('ขอรายการด้วย wallet_address ที่ด่านตรวจได้ ไม่ใช่ ?address=', async () => {
        connect(WALLET_A);
        routeFetch({});

        mountTracked(FoodPassport);
        await flushPromises();

        const urls = global.fetch.mock.calls.map(([url]) => String(url));
        expect(urls).toContain(`/api/v1/food-passport/my-products?wallet_address=${WALLET_A}`);
        expect(urls).toContain(`/api/v1/food-passport/iot/my-devices?wallet_address=${WALLET_A}`);
        expect(urls.some((url) => url.includes('?address='))).toBe(false);
    });

    it('ยังไม่เซ็นยืนยัน (403) ขึ้นการ์ดให้เซ็น ไม่ใช่ "ยังไม่มีสินค้า" แล้วเซ็นเสร็จโหลดใหม่', async () => {
        connect(WALLET_A);
        const forbidden = () => jsonResponse({ success: false, error: { code: 'WALLET_IP_MISMATCH' } }, 403);
        routeFetch({ '/api/v1/food-passport/my-products': forbidden, '/api/v1/food-passport/iot/my-devices': forbidden });

        const wrapper = mountTracked(FoodPassport);
        await flushPromises();
        await buttonWithText(wrapper, 'สินค้าของฉัน').trigger('click');

        expect(wrapper.find('[data-test="food-passport-verify"]').exists()).toBe(true);
        expect(wrapper.text()).not.toContain('ยังไม่มีสินค้า');

        routeFetch({ '/api/v1/food-passport/my-products': jsonResponse(page([product(7, 'ข้าวของเจ้าของ')])) });
        wallet.verifyOwnership.mockResolvedValue(true);
        await buttonWithText(wrapper, 'ยืนยันกระเป๋า').trigger('click');
        await flushPromises();

        expect(wallet.verifyOwnership).toHaveBeenCalledTimes(1);
        expect(wrapper.find('[data-test="food-passport-verify"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('ข้าวของเจ้าของ');
    });

    it('เซ็นผ่านจากที่อื่น (verifiedAt เปลี่ยน) ก็โหลดรายการใหม่เอง', async () => {
        connect(WALLET_A);
        const forbidden = () => jsonResponse({ success: false }, 403);
        routeFetch({ '/api/v1/food-passport/my-products': forbidden, '/api/v1/food-passport/iot/my-devices': forbidden });

        const wrapper = mountTracked(FoodPassport);
        await flushPromises();

        routeFetch({ '/api/v1/food-passport/my-products': jsonResponse(page([product(8, 'ข้าวหลังเซ็น')])) });
        wallet.verifiedAt = Date.now();
        await flushPromises();
        await buttonWithText(wrapper, 'สินค้าของฉัน').trigger('click');

        expect(wrapper.text()).toContain('ข้าวหลังเซ็น');
    });

    it('สลับกระเป๋าระหว่างรอ — คำตอบของกระเป๋าเดิมที่มาช้าต้องไม่ทับของใบใหม่', async () => {
        let releaseA;
        const slowA = new Promise((resolve) => { releaseA = resolve; });
        global.fetch = vi.fn((url) => {
            const u = String(url);
            if (u === `/api/v1/food-passport/my-products?wallet_address=${WALLET_A}`) return slowA;
            if (u === `/api/v1/food-passport/my-products?wallet_address=${WALLET_B}`) {
                return Promise.resolve(jsonResponse(page([product(2, 'สินค้าของกระเป๋า B')])));
            }
            return Promise.resolve(jsonResponse(page([])));
        });

        connect(WALLET_A);
        const wrapper = mountTracked(FoodPassport);
        await flushPromises();

        connect(WALLET_B);
        await flushPromises();
        releaseA(jsonResponse(page([product(1, 'สินค้าของกระเป๋า A')])));
        await flushPromises();

        await buttonWithText(wrapper, 'สินค้าของฉัน').trigger('click');
        expect(wrapper.text()).toContain('สินค้าของกระเป๋า B');
        expect(wrapper.text()).not.toContain('สินค้าของกระเป๋า A');
    });
});

describe('IoTDashboard — API key ของเครื่อง', () => {
    function mountDashboard(props = {}) {
        return mountTracked(IoTDashboard, {
            props: { devices: [], walletAddress: WALLET_A, isConnected: true, ...props },
        });
    }

    async function registerThroughWizard(wrapper) {
        post.mockResolvedValue({
            data: {
                success: true,
                data: { ...DEVICE, id: 9, device_id: 'TPIX-IOT-NEW00001', api_key: KEY },
            },
        });
        await buttonWithText(wrapper, 'เพิ่ม Sensor').trigger('click');
        await buttonWithText(wrapper, 'ถัดไป').trigger('click');
        await wrapper.find('input[type="text"]').setValue('Sensor รถห้องเย็น');
        await buttonWithText(wrapper, 'สร้าง Device').trigger('click');
        await flushPromises();
    }

    it('ลงทะเบียนด้วยกระเป๋าที่ยืนยันแล้ว — โชว์กุญแจครั้งเดียวและใส่ในโค้ดตัวอย่างพร้อม Authorization', async () => {
        const wrapper = mountDashboard();
        await registerThroughWizard(wrapper);

        expect(post).toHaveBeenCalledWith(
            '/api/v1/food-passport/iot/register-device',
            expect.objectContaining({ wallet_address: WALLET_A, name: 'Sensor รถห้องเย็น' }),
        );
        expect(wrapper.find('[data-test="iot-api-key"]').text()).toContain(KEY);

        const code = wrapper.find('pre').text();
        expect(code).toContain(`API_KEY        = "${KEY}"`);
        expect(code).toContain('http.addHeader("Authorization", String("Bearer ") + API_KEY);');

        await buttonWithText(wrapper, 'cURL').trigger('click');
        expect(wrapper.find('pre').text()).toContain(`-H "Authorization: Bearer ${KEY}"`);

        // กุญแจไม่ไปกับรายการอุปกรณ์
        expect(wrapper.emitted('device-registered')[0][0]).not.toHaveProperty('api_key');

        await buttonWithText(wrapper, 'เสร็จสิ้น').trigger('click');
        expect(wrapper.html()).not.toContain(KEY);
    });

    it('สลับกระเป๋า — แผงที่โชว์กุญแจของกระเป๋าเดิมถูกปิด', async () => {
        const wrapper = mountDashboard();
        await registerThroughWizard(wrapper);
        expect(wrapper.html()).toContain(KEY);

        await wrapper.setProps({ walletAddress: WALLET_B });
        expect(wrapper.html()).not.toContain(KEY);
    });

    it('ออกกุญแจใหม่ทับของเดิมต้องถามก่อน — ยกเลิกแล้วไม่ยิง', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);
        const wrapper = mountDashboard({ devices: [DEVICE] });

        await buttonWithText(wrapper, 'ออกใหม่').trigger('click');
        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(post).not.toHaveBeenCalled();

        confirmSpy.mockReturnValue(true);
        post.mockResolvedValue({ data: { success: true, data: { device_id: DEVICE.device_id, api_key: KEY_2 } } });
        await buttonWithText(wrapper, 'ออกใหม่').trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith(
            `/api/v1/food-passport/iot/rotate-key/${DEVICE.device_id}`,
            { wallet_address: WALLET_A },
        );
        expect(wrapper.find('[data-test="iot-api-key"]').text()).toContain(KEY_2);
        expect(wrapper.text()).toContain('กุญแจเดิมใช้ไม่ได้แล้ว');
    });

    it('เครื่องเก่าที่ยังไม่มีกุญแจ — ขึ้นป้ายเตือน และออกกุญแจแรกได้โดยไม่ต้องถาม', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm');
        post.mockResolvedValue({ data: { success: true, data: { device_id: LEGACY_DEVICE.device_id, api_key: KEY } } });
        const wrapper = mountDashboard({ devices: [LEGACY_DEVICE] });

        expect(wrapper.text()).toContain('ยังไม่มี API key');
        await buttonWithText(wrapper, 'ออก API key').trigger('click');
        await flushPromises();

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="iot-api-key"]').text()).toContain(KEY);
        expect(wrapper.emitted('devices-updated')[0][0][0]).toMatchObject({ device_id: LEGACY_DEVICE.device_id, has_api_key: true });
    });

    it('ออกกุญแจไม่ผ่านด่านกระเป๋า — ข้อความภาษาไทย ไม่ใช่ข้อความดิบของ axios', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        post.mockRejectedValue(Object.assign(new Error('Request failed with status code 403'), {
            response: { status: 403, data: { success: false, error: { code: 'WALLET_IP_MISMATCH', message: 'Wallet verification session invalid.' } } },
        }));
        const wrapper = mountDashboard({ devices: [DEVICE] });

        await buttonWithText(wrapper, 'ออกใหม่').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('ยืนยันกระเป๋าก่อน');
        expect(wrapper.text()).not.toContain('Request failed');
    });

    it('ตรวจสถานะถามเซิร์ฟเวอร์ — ไม่ยิงข้อมูลปลอมเข้า /iot/ingest', async () => {
        routeFetch({ '/api/v1/food-passport/iot/my-devices': jsonResponse(page([DEVICE])) });
        const wrapper = mountDashboard({ devices: [DEVICE] });

        await buttonWithText(wrapper, 'ตรวจสถานะ').trigger('click');
        await flushPromises();

        expect(global.fetch).toHaveBeenCalledWith(`/api/v1/food-passport/iot/my-devices?wallet_address=${WALLET_A}`);
        expect(global.fetch.mock.calls.some(([url]) => String(url).includes('/iot/ingest'))).toBe(false);
        expect(post).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('ยังไม่เคยได้รับข้อมูล');
    });

    it('ตรวจสถานะ — เครื่องที่เพิ่งส่งข้อมูลขึ้นว่าปกติ', async () => {
        const recent = { ...DEVICE, last_ping_at: new Date(Date.now() - 3 * 60000).toISOString() };
        routeFetch({ '/api/v1/food-passport/iot/my-devices': jsonResponse(page([recent])) });
        const wrapper = mountDashboard({ devices: [DEVICE] });

        await buttonWithText(wrapper, 'ตรวจสถานะ').trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('อุปกรณ์ส่งข้อมูลล่าสุดเมื่อ 3 นาทีที่แล้ว');
        expect(wrapper.emitted('devices-updated')[0][0]).toEqual([recent]);
    });
});
