/**
 * TPIX TRADE — เหรียญของเชน TPIX ที่เสนอให้เพิ่มลงกระเป๋าผู้ใช้
 *
 * เจ้าของสั่ง (2026-10-11): "ตอนเชื่อมกระเป๋า ควรเพิ่มเหรียญ tpix อัตโนมัติเลย
 * พร้อมโลโก้ใหม่ของเหรียญให้ถูกต้อง · มีปุ่มเพิ่มเหรียญต่างหากด้วย"
 *
 * ⚠️ TPIX เป็นเหรียญเนทีฟของเชน 4289 (แบบ ETH บน Ethereum) ไม่มีที่อยู่สัญญา
 *    wallet_watchAsset (EIP-747) รับแค่ ERC-20 จึงใช้กับ TPIX ไม่ได้
 *    กระเป๋าโชว์ TPIX เองทันทีที่มีเครือข่าย TPIX Chain → "เพิ่มเหรียญ TPIX"
 *    คือเพิ่มเครือข่ายพร้อม iconUrls (ดู addTPIXChainToWallet / TPIX_CHAIN_LOGO_URL)
 *
 * ไฟล์นี้ดูแลส่วนที่เป็นโทเคน (USDT บนเชน TPIX — สกุลที่ TPIX เทรดด้วย)
 * ที่อยู่/ทศนิยม/โลโก้มาจาก /api/v1/dex/pairs แหล่งเดียวกับหน้าเทรด — ห้าม hardcode
 * โหลดไม่ได้ = ไม่เสนออะไรเลย (ไม่เดาที่อยู่ให้กระเป๋า)
 *
 * Developed by Xman Studio
 */

import axios from 'axios';

const TPIX_CHAIN_ID = 4289;
const NATIVE = '0x0000000000000000000000000000000000000000';

/** จำว่ากระเป๋าไหนเคยถูกถามแล้ว — ถามครั้งเดียวต่อกระเป๋าต่อเบราว์เซอร์ (ปฏิเสธแล้วไม่ถามซ้ำ) */
const SUGGESTED_KEY = 'tpix_wallet_assets_suggested_v1';

/**
 * โทเคน ERC-20 บนเชน TPIX ที่จับคู่กับ TPIX อยู่ (ฝั่ง quote ของคู่ที่ base เป็น TPIX เนทีฟ)
 * ตอนนี้คือ USDT ตัวเดียว — เหรียญที่คนสร้างเองไม่ถูกเสนออัตโนมัติ (กันป๊อปอัพท่วม + กันเหรียญหลอก)
 *
 * @returns {Promise<Array<{address: string, symbol: string, decimals: number, image?: string}>>}
 */
export async function fetchTpixChainTokens() {
    let pairs;
    try {
        const { data } = await axios.get('/api/v1/dex/pairs');
        pairs = data?.success && Array.isArray(data.data) ? data.data : [];
    } catch {
        return [];
    }

    const seen = new Set();
    const tokens = [];

    for (const p of pairs) {
        if (Number(p?.chain_id) !== TPIX_CHAIN_ID) continue;
        if (String(p?.base_address || '').toLowerCase() !== NATIVE) continue;

        const address = String(p?.quote_address || '');
        const symbol = String(p?.quote_asset || '').trim();
        const decimals = Number(p?.quote_decimals);

        // ข้อมูลไม่ครบ/ผิดรูป = ข้าม (กระเป๋าจะโชว์ยอดผิดถ้าทศนิยมผิด)
        if (!/^0x[0-9a-fA-F]{40}$/.test(address) || address.toLowerCase() === NATIVE) continue;
        if (!symbol || symbol.length > 11) continue;
        if (!Number.isInteger(decimals) || decimals < 0 || decimals > 36) continue;
        if (seen.has(address.toLowerCase())) continue;
        seen.add(address.toLowerCase());

        const logo = String(p?.quote_logo || '');
        tokens.push({
            address,
            symbol,
            decimals,
            // กระเป๋าดึงรูปเองจากภายนอก — ที่อยู่สัมพัทธ์หรือ http ใช้ไม่ได้
            ...(logo.startsWith('https://') ? { image: logo } : {}),
        });
    }

    return tokens;
}

function readSuggested() {
    try {
        const list = JSON.parse(localStorage.getItem(SUGGESTED_KEY) || '[]');
        return Array.isArray(list) ? list : [];
    } catch {
        return [];
    }
}

/** กระเป๋านี้เคยถูกเสนอเหรียญอัตโนมัติไปแล้วหรือยัง */
export function wasAssetSuggestionShown(walletAddress) {
    if (!walletAddress) return true;
    return readSuggested().includes(String(walletAddress).toLowerCase());
}

/** จดว่าเสนอแล้ว — localStorage ใช้ไม่ได้ (โหมดส่วนตัว) ก็แค่ถามซ้ำรอบหน้า ไม่พัง */
export function markAssetSuggestionShown(walletAddress) {
    if (!walletAddress) return;
    try {
        const list = readSuggested();
        const addr = String(walletAddress).toLowerCase();
        if (!list.includes(addr)) {
            // เก็บแค่ 50 ใบล่าสุด — เครื่องที่สลับกระเป๋าบ่อยไม่ควรโตไม่สิ้นสุด
            localStorage.setItem(SUGGESTED_KEY, JSON.stringify([...list, addr].slice(-50)));
        }
    } catch {
        // ignore
    }
}
