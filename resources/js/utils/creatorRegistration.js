/**
 * TPIX TRADE — ตัวช่วยหน้าลงทะเบียนผู้สร้างเหรียญ (แยกจากคอมโพเนนต์เพื่อทดสอบตรงๆ)
 *
 * Developed by Xman Studio
 */

/** กระเป๋ามือถือ (WalletConnect) อาจไม่ตอบกลับเลย — ไม่ปล่อยปุ่มค้างตลอดไป */
export const SIGN_TIMEOUT_MS = 120000;

/** ประเทศที่ให้เลือก — เซิร์ฟเวอร์รับรหัส ISO 3166-1 alpha-2 */
export const COUNTRIES = [
    { code: 'TH', th: 'ไทย', en: 'Thailand' },
    { code: 'LA', th: 'ลาว', en: 'Laos' },
    { code: 'KH', th: 'กัมพูชา', en: 'Cambodia' },
    { code: 'MM', th: 'เมียนมา', en: 'Myanmar' },
    { code: 'MY', th: 'มาเลเซีย', en: 'Malaysia' },
    { code: 'SG', th: 'สิงคโปร์', en: 'Singapore' },
    { code: 'VN', th: 'เวียดนาม', en: 'Vietnam' },
    { code: 'ID', th: 'อินโดนีเซีย', en: 'Indonesia' },
    { code: 'PH', th: 'ฟิลิปปินส์', en: 'Philippines' },
    { code: 'CN', th: 'จีน', en: 'China' },
    { code: 'HK', th: 'ฮ่องกง', en: 'Hong Kong' },
    { code: 'TW', th: 'ไต้หวัน', en: 'Taiwan' },
    { code: 'JP', th: 'ญี่ปุ่น', en: 'Japan' },
    { code: 'KR', th: 'เกาหลีใต้', en: 'South Korea' },
    { code: 'IN', th: 'อินเดีย', en: 'India' },
    { code: 'AE', th: 'สหรัฐอาหรับเอมิเรตส์', en: 'United Arab Emirates' },
    { code: 'AU', th: 'ออสเตรเลีย', en: 'Australia' },
    { code: 'NZ', th: 'นิวซีแลนด์', en: 'New Zealand' },
    { code: 'GB', th: 'สหราชอาณาจักร', en: 'United Kingdom' },
    { code: 'DE', th: 'เยอรมนี', en: 'Germany' },
    { code: 'FR', th: 'ฝรั่งเศส', en: 'France' },
    { code: 'NL', th: 'เนเธอร์แลนด์', en: 'Netherlands' },
    { code: 'CH', th: 'สวิตเซอร์แลนด์', en: 'Switzerland' },
    { code: 'US', th: 'สหรัฐอเมริกา', en: 'United States' },
    { code: 'CA', th: 'แคนาดา', en: 'Canada' },
];

const FIELDS = [
    'applicant_type', 'full_name', 'full_name_en', 'date_of_birth', 'nationality', 'occupation',
    'company_name', 'company_name_en', 'registration_number', 'registration_country', 'incorporation_date',
    'signatory_name', 'signatory_position', 'email', 'phone', 'address', 'country', 'telegram', 'line_id',
    'project_name', 'token_name', 'token_symbol', 'planned_supply', 'category', 'description', 'website',
    'whitepaper_url', 'twitter', 'telegram_group', 'facebook', 'github', 'team_description', 'use_of_funds',
    'source_of_funds', 'source_of_funds_note', 'prior_projects', 'is_pep', 'pep_details',
    'declare_not_sanctioned', 'declare_true',
];

/** ฟอร์มตั้งต้น — ใส่ค่าจากใบเดิมถ้ามี (ผู้สร้างกลับมาแก้ต่อ) */
export function blankCreatorForm(existing = null) {
    const form = Object.fromEntries(FIELDS.map((f) => [f, null]));
    form.applicant_type = 'individual';
    form.nationality = 'TH';
    form.country = 'TH';
    form.is_pep = false;
    form.declare_not_sanctioned = false;
    form.declare_true = false;

    if (existing && typeof existing === 'object') {
        for (const f of FIELDS) {
            if (existing[f] !== undefined && existing[f] !== null) form[f] = existing[f];
        }
    }
    return form;
}

/**
 * ขั้นที่ต้องแสดง: wallet → form → review (KYC + ข้อตกลง) → status
 * ใบที่ถูกปฏิเสธโชว์สถานะ (เหตุผล) จนกว่าผู้สร้างจะกดแก้ไข
 */
export function creatorStep(registration, editing) {
    if (!registration) return 'wallet';
    if (registration.status === 'submitted' || registration.status === 'approved') return 'status';
    if (registration.status === 'rejected' && !editing) return 'status';
    if (editing || !registration.form_hash) return 'form';
    return 'review';
}

/** 1000 bps → "10" · 1250 → "12.5" */
export function formatBps(bps) {
    const n = Number(bps);
    if (!Number.isFinite(n)) return '0';
    return String(Math.round(n) / 100);
}

export function codeError(code) {
    const e = new Error(code);
    e.code = code;
    return e;
}

function withTimeout(promise, ms) {
    let timer;
    return Promise.race([
        promise,
        new Promise((_, reject) => { timer = setTimeout(() => reject(codeError('SIGN_TIMEOUT')), ms); }),
    ]).finally(() => clearTimeout(timer));
}

/**
 * เซ็นข้อความจากเซิร์ฟเวอร์ด้วยกระเป๋าที่กำหนดเท่านั้น
 *
 * ตรวจสองครั้ง: ก่อนขอข้อความ (กระเป๋าที่เชื่อมคือใบที่ตั้งใจ) และหลังเซ็น
 * (ผู้ใช้สลับบัญชีระหว่างเปิดกระเป๋า — ลายเซ็นต้องเป็นของกระเป๋าเดิม ไม่งั้นเซิร์ฟเวอร์ตอบ 403 แบบงงๆ)
 *
 * @returns {Promise<{nonce: string, signature: string}>}
 */
export async function signWithWallet(walletStore, wallet, getChallenge, timeoutMs = SIGN_TIMEOUT_MS) {
    const expected = String(wallet || '').toLowerCase();
    const signer = walletStore.signer;
    if (!signer || !expected) throw codeError('NO_SIGNER');
    if ((await signer.getAddress()).toLowerCase() !== expected) throw codeError('WALLET_CHANGED');

    const challenge = await getChallenge();
    const signature = await withTimeout(signer.signMessage(challenge.message), typeof timeoutMs === 'number' ? timeoutMs : SIGN_TIMEOUT_MS);

    if (String(walletStore.address || '').toLowerCase() !== expected) throw codeError('WALLET_CHANGED');

    return { nonce: challenge.nonce, signature };
}

/**
 * แปลง error เป็นคีย์คำแปล (ขึ้นต้น page.) หรือข้อความจากเซิร์ฟเวอร์ (แปลมาแล้วตามคุกกี้ภาษา)
 */
export function describeCreatorError(err) {
    if (err?.code === 4001 || err?.code === 'ACTION_REJECTED') return 'page.tokenCreator.errors.signCancelled';
    if (err?.code === 'NO_SIGNER') return 'page.tokenCreator.errors.noSigner';
    if (err?.code === 'WALLET_CHANGED') return 'page.tokenCreator.errors.walletChanged';
    if (err?.code === 'SIGN_TIMEOUT') return 'page.tokenCreator.errors.signTimeout';

    const res = err?.response;
    if (res?.status === 429) return 'page.tokenCreator.errors.rateLimited';
    if (res?.status === 401 || res?.status === 419) return 'page.tokenCreator.errors.sessionExpired';
    if (typeof res?.data?.error?.message === 'string') return res.data.error.message;
    if (res) return 'page.tokenCreator.errors.failed';
    return 'page.tokenCreator.errors.network';
}
