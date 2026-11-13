/**
 * TPIX TRADE — AiPlanPrice tests
 *
 * ราคาแพลนต้องเป็นสิ่งที่เซิร์ฟเวอร์ตัดจริง: `credits_per_day × จำนวนวัน` เป็นเครดิต
 * เดิมหน้านี้โชว์ `price_tpix_per_day` ("300 TPIX/วัน") ซึ่งไม่ใช่ยอดที่ถูกตัด และไม่ตรงกับเรตแพ็กเครดิต
 * (เทสต์รันด้วย locale = en ตาม tests/js/setup.js)
 *
 * Developed by Xman Studio
 */

import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import AiPlanPrice from '@/Components/AiTrade/AiPlanPrice.vue';

// แพลนที่ price_tpix_per_day ไม่ตรงกับเรตแพ็ก — แบบเดียวกับของจริงที่ทำให้ราคาบนจอผิด
const PRO = { code: 'pro', credits_per_day: 30, price_tpix_per_day: 300 };
const FREE = { code: 'free', credits_per_day: 0, price_tpix_per_day: 0 };

function mountPrice(props) {
    return mount(AiPlanPrice, { props });
}

describe('AiPlanPrice', () => {
    it('headlines the credits that are actually deducted, per day and for the selected days', () => {
        const wrapper = mountPrice({ plan: PRO, days: 7, tpixPerCredit: 12 });

        expect(wrapper.find('[data-testid="plan-price-headline"]').text()).toBe('30 credits/day');
        expect(wrapper.find('[data-testid="plan-price-total"]').text()).toBe('7 days = 210 credits');
    });

    it('shows TPIX only as an approximation from the base pack rate', () => {
        const wrapper = mountPrice({ plan: PRO, days: 7, tpixPerCredit: 12 });
        const approx = wrapper.find('[data-testid="plan-price-approx"]').text();

        // 210 เครดิต × 12 TPIX/เครดิต
        expect(approx).toContain('≈ 2,520 TPIX');
        expect(approx).toContain('approx');
    });

    it('never shows price_tpix_per_day or price × days as the cost', () => {
        const text = mountPrice({ plan: PRO, days: 7, tpixPerCredit: 12 }).text();

        expect(text).not.toContain('300');
        expect(text).not.toContain('2,100');
        expect(text).not.toContain('TPIX/day');
    });

    it('follows the selected number of days', async () => {
        const wrapper = mountPrice({ plan: PRO, days: 1, tpixPerCredit: 0.1 });

        expect(wrapper.find('[data-testid="plan-price-total"]').text()).toBe('1 days = 30 credits');
        // ยอดเล็กต้องไม่ถูกปัดเป็น 0 TPIX
        expect(wrapper.find('[data-testid="plan-price-approx"]').text()).toContain('≈ 3 TPIX');

        await wrapper.setProps({ days: 30 });

        expect(wrapper.find('[data-testid="plan-price-total"]').text()).toBe('30 days = 900 credits');
        expect(wrapper.find('[data-testid="plan-price-approx"]').text()).toContain('≈ 90 TPIX');
    });

    it('leaves the TPIX line out when no pack rate is known — no guessed number', () => {
        const wrapper = mountPrice({ plan: PRO, days: 7, tpixPerCredit: null });

        expect(wrapper.find('[data-testid="plan-price-headline"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="plan-price-approx"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('TPIX');
    });

    it('keeps free plans as free with nothing to pay', () => {
        const wrapper = mountPrice({ plan: FREE, days: 7, tpixPerCredit: 12 });

        expect(wrapper.text()).toContain('Free');
        expect(wrapper.text()).toContain('Nothing to pay');
        expect(wrapper.find('[data-testid="plan-price-headline"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="plan-price-approx"]').exists()).toBe(false);
    });

    it('prices a plan by its credits even when the catalog carries no TPIX price for it', () => {
        // สิ่งที่ถูกตัดคือเครดิต — แพลนที่ price_tpix_per_day เป็น 0 แต่ตัดเครดิต ต้องไม่ขึ้นว่าฟรี
        const wrapper = mountPrice({ plan: { code: 'x', credits_per_day: 10, price_tpix_per_day: 0 }, days: 7, tpixPerCredit: null });

        expect(wrapper.find('[data-testid="plan-price-total"]').text()).toBe('7 days = 70 credits');
        expect(wrapper.text()).not.toContain('Nothing to pay');
    });
});
