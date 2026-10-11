/**
 * TPIX TRADE — อ่านนโยบาย Token Factory ฝั่งหน้าเว็บ
 *
 * นโยบายตัวจริงอยู่ที่เซิร์ฟเวอร์ (App\Services\TokenFactory\TokenFactoryPolicy) และเซิร์ฟเวอร์ตรวจซ้ำทุกครั้ง
 * ไฟล์นี้มีไว้ "ซ่อนสิ่งที่ปิด และเตือนสิ่งที่เสี่ยง" ให้ตรงกับที่เซิร์ฟเวอร์จะยอมรับ — ห้ามใช้ตัดสินแทนเซิร์ฟเวอร์
 *
 * ตรรกะ requestedOptions ต้องตรงกับ TokenFactoryPolicy::requestedOptions() ทุกบรรทัด
 *
 * Developed by Xman Studio
 */

/** token_type ของฟอร์ม → ประเภทในนโยบาย */
export const UI_TYPE_TO_POLICY = {
    standard: 'erc20',
    mintable: 'erc20',
    burnable: 'erc20',
    mintable_burnable: 'erc20',
    utility: 'utility',
    reward: 'reward',
    governance: 'governance',
    stablecoin: 'stablecoin',
    nft: 'nft',
    nft_collection: 'nft_collection',
};

/** ERC-20 ส่ง mint/burn มาเป็นชนิดเหรียญ ไม่ใช่สวิตช์ */
const ERC20_TYPE_OPTIONS = {
    standard: [],
    mintable: ['mintable'],
    burnable: ['burnable'],
    mintable_burnable: ['mintable', 'burnable'],
};

export function policyTypeFor(uiType) {
    return UI_TYPE_TO_POLICY[uiType] || null;
}

function isOn(policy, group, key) {
    return policy?.[group]?.[key]?.state === 'on';
}

function truthy(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

/** ตัวเลือกในนโยบายที่เป็นเจ้าของคีย์นี้ของฟอร์ม (null = เป็นค่าประจำประเภท เช่น royalty) */
export function optionForUiKey(policy, uiKey) {
    for (const [key, meta] of Object.entries(policy?.options || {})) {
        if ((meta.ui_keys || []).includes(uiKey)) return key;
    }
    return null;
}

/** ชนิดเหรียญนี้ (การ์ดในขั้นที่ 2) เลือกได้หรือไม่ */
export function isUiTypeAllowed(policy, uiType) {
    const type = policyTypeFor(uiType);
    if (!type || !isOn(policy, 'types', type)) return false;
    if (type === 'erc20') {
        return (ERC20_TYPE_OPTIONS[uiType] || []).every((option) => isOn(policy, 'options', option));
    }
    return true;
}

/** ตัวเลือกย่อยนี้ (ในขั้นที่ 3) แสดงได้หรือไม่ */
export function isUiOptionAllowed(policy, uiType, uiKey) {
    const type = policyTypeFor(uiType);
    if (!type) return false;

    const option = optionForUiKey(policy, uiKey);
    if (!option) return true;

    const meta = policy.options[option];
    if (!(meta.applies_to || []).includes(type)) return false;

    return meta.state === 'on';
}

/** ตัวเลือก (ชื่อตามนโยบาย) ที่คำขอนี้ขอใช้จริง — ตรงกับฝั่งเซิร์ฟเวอร์ */
export function requestedOptions(policy, uiType, subOptions = {}) {
    const type = policyTypeFor(uiType);
    if (!type) return [];

    const sub = subOptions || {};
    const requested = [];

    if (type === 'erc20') {
        requested.push(...(ERC20_TYPE_OPTIONS[uiType] || []));
    } else if (['governance', 'utility', 'reward'].includes(type)) {
        if (truthy(sub.mintable)) requested.push('mintable');
        if (truthy(sub.burnable)) requested.push('burnable');
    }

    if (truthy(sub.pausable)) requested.push('pausable');
    if (truthy(sub.blacklist_enabled) || truthy(sub.blacklist)) requested.push('blacklist');
    if (truthy(sub.mint_cap_enabled)) requested.push('mint_cap');
    if (truthy(sub.auto_burn_enabled) || truthy(sub.auto_burn)) requested.push('auto_burn');

    return [...new Set(requested)].filter(
        (option) => (policy?.options?.[option]?.applies_to || []).includes(type),
    );
}

/**
 * ความเสี่ยงที่ต้องติ๊กยอมรับ — ประเภทที่เสี่ยง + ตัวเลือกที่เสี่ยงที่เปิดอยู่
 * @returns {{key: string, label: string, warning: string, kind: 'type'|'option'}[]}
 */
export function requiredRiskAcks(policy, uiType, subOptions = {}) {
    const type = policyTypeFor(uiType);
    if (!type || !policy) return [];

    const acks = [];
    const typeMeta = policy.types?.[type];
    if (typeMeta?.risky) {
        acks.push({ key: type, label: typeMeta.label_th, warning: typeMeta.warning_th, kind: 'type' });
    }

    for (const option of requestedOptions(policy, uiType, subOptions)) {
        const meta = policy.options[option];
        if (meta?.risky) {
            acks.push({ key: option, label: meta.label_th, warning: meta.warning_th, kind: 'option' });
        }
    }

    return acks;
}

/** ค่าธรรมเนียมที่จะแสดง (ยอดจริงมาจากใบเสนอราคาของเซิร์ฟเวอร์) */
export function computeFee(policy, uiType, subOptions = {}) {
    const type = policyTypeFor(uiType);
    if (!type || !policy?.types?.[type]) return { total: 0, breakdown: [], isFree: true };

    const breakdown = [];
    const typeFee = Number(policy.types[type].fee) || 0;
    breakdown.push({ key: type, label_th: `ค่าสร้าง${policy.types[type].label_th}`, amount: typeFee });
    let total = typeFee;

    for (const option of requestedOptions(policy, uiType, subOptions)) {
        const amount = Number(policy.options[option]?.fee) || 0;
        if (amount > 0) {
            breakdown.push({ key: option, label_th: policy.options[option].label_th, amount });
            total += amount;
        }
    }

    total = Math.round(total * 1e6) / 1e6;
    return { total, breakdown, isFree: total <= 0 };
}

/** ช่วงทศนิยมที่เลือกได้ของชนิดนี้ */
export function allowedDecimals(policy, uiType, candidates = [18, 8, 6, 0]) {
    const type = policyTypeFor(uiType);
    const meta = policy?.types?.[type];
    if (!meta) return [18];
    return candidates.filter((d) => d >= meta.decimals_min && d <= meta.decimals_max);
}

/** สัญลักษณ์: ตัวอักษรอังกฤษพิมพ์ใหญ่และตัวเลขเท่านั้น */
export function sanitizeSymbol(value, maxLength = 11) {
    return String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, maxLength);
}
