/**
 * TPIX TRADE — ตัวช่วยหน้าแอดมินตรวจ KYC ของผู้สมัคร Validator
 *
 * แยกจากหน้า .vue เพื่อให้เทสต์ได้โดยไม่ต้องมีกระเป๋าจริง:
 * ปุ่มไหนกดได้ในสถานะไหน, hash ไหนถูกใช้, และ calldata ที่ส่งให้กระเป๋า owner เซ็น
 *
 * Developed by Xman Studio
 */

export const KYC_WRITE_ABI = [
    'function submitKYC(address _applicant, bytes32 _kycHash)',
    'function approveKYC(address _applicant)',
    'function rejectKYC(address _applicant, string _reason)',
];

/** สถานะบนสัญญา (enum KYCStatus) → ข้อความที่แอดมินอ่านเข้าใจ */
export const KYC_STATUS = {
    None: { label: 'ยังไม่ยินยอม', tone: 'gray' },
    ConsentGiven: { label: 'ยินยอมแล้ว — รอทีมบันทึกเอกสาร', tone: 'yellow' },
    Submitted: { label: 'บันทึกเอกสารแล้ว — รออนุมัติ', tone: 'cyan' },
    Approved: { label: 'อนุมัติแล้ว — stake ชั้น Validator ได้', tone: 'green' },
    Rejected: { label: 'ไม่ผ่าน', tone: 'red' },
    Revoked: { label: 'ผู้สมัครถอนความยินยอม', tone: 'gray' },
};

export function sameAddress(a, b) {
    return typeof a === 'string' && typeof b === 'string' && a.toLowerCase() === b.toLowerCase();
}

export function isBytes32(value) {
    return typeof value === 'string' && /^0x[0-9a-fA-F]{64}$/.test(value) && !/^0x0{64}$/.test(value);
}

/**
 * ปุ่มที่ทำได้ตามสถานะบนสัญญา — ตรงกับ require() ในสัญญาเป๊ะ
 * (กดปุ่มที่สัญญาจะ revert แน่นอน = เสียเวลาเซ็นฟรี)
 */
export function availableActions(status) {
    if (status === 'ConsentGiven') return ['submit'];
    if (status === 'Submitted') return ['approve', 'reject'];
    return [];
}

/**
 * hash ที่จะใช้ตอนบันทึกเอกสาร
 *   - ใบ KYC ของเว็บอนุมัติแล้ว → ใช้ hash ที่เซิร์ฟเวอร์คำนวณจากใบนั้น (โยงกลับได้)
 *   - ไม่มี → ต้องใส่เองพร้อมเหตุผล
 *
 * @returns {{hash:string|null, uuid:string|null, manual:boolean, error:string|null}}
 */
export function resolveSubmitHash(row, manualHash = '', note = '') {
    const site = row?.site_kyc;
    if (site?.status === 'approved' && isBytes32(site.doc_hash)) {
        return { hash: site.doc_hash.toLowerCase(), uuid: site.uuid, manual: false, error: null };
    }
    if (!isBytes32(manualHash)) {
        return { hash: null, uuid: null, manual: true, error: 'ใส่ hash 0x + 64 ตัวอักษร (keccak256 ของชุดเอกสาร)' };
    }
    if (!String(note).trim()) {
        return { hash: null, uuid: null, manual: true, error: 'hash ที่ไม่ได้มาจากใบ KYC ของเว็บต้องพิมพ์เหตุผลกำกับ' };
    }
    return { hash: manualHash.toLowerCase(), uuid: null, manual: true, error: null };
}

/**
 * calldata ของรายการ — ใช้ Interface ของ ethers ที่โหลดมาแล้ว
 *
 * @param {object} ethers  โมดูล ethers (v6)
 * @param {'submit'|'approve'|'reject'} action
 * @param {string} applicant
 * @param {{hash?:string, reason?:string}} args
 */
export function encodeKycCall(ethers, action, applicant, args = {}) {
    const iface = new ethers.Interface(KYC_WRITE_ABI);
    if (action === 'submit') {
        if (!isBytes32(args.hash)) throw new Error('KYC_HASH_REQUIRED');
        return iface.encodeFunctionData('submitKYC', [applicant, args.hash]);
    }
    if (action === 'approve') return iface.encodeFunctionData('approveKYC', [applicant]);
    if (action === 'reject') {
        const reason = String(args.reason || '').trim();
        if (!reason) throw new Error('REJECT_REASON_REQUIRED');
        return iface.encodeFunctionData('rejectKYC', [applicant, reason]);
    }
    throw new Error(`UNKNOWN_ACTION:${action}`);
}

/** ข้อความผิดพลาดจากกระเป๋า/เชน → ภาษาคน ห้ามโชว์ก้อน JSON ดิบ */
export function humanWalletError(e) {
    const code = e?.code ?? e?.info?.error?.code ?? e?.error?.code;
    const blob = [e?.shortMessage, e?.reason, e?.message].filter(Boolean).join(' | ');
    if (code === 4001 || code === 'ACTION_REJECTED' || /user rejected|user denied/i.test(blob)) {
        return 'ยกเลิกการเซ็นในกระเป๋า — ไม่มีอะไรถูกส่งขึ้นเชน';
    }
    if (e?.message === 'NO_INJECTED_WALLET') return 'ไม่พบกระเป๋าในเบราว์เซอร์ (MetaMask / Rabby) — ติดตั้งแล้วนำเข้ากุญแจ owner ก่อน';
    if (e?.message === 'WALLET_SWITCHED') return 'กระเป๋าถูกสลับระหว่างทำรายการ — ยกเลิกแล้ว ไม่มีอะไรถูกส่ง';
    if (e?.message === 'NOT_OWNER') return 'กระเป๋าที่ต่ออยู่ไม่ใช่ owner ของสัญญา ValidatorKYC';
    if (e?.message === 'WRONG_CHAIN') return 'กระเป๋ายังไม่ได้อยู่บน TPIX Chain (4289)';
    if (e?.message === 'KYC_HASH_REQUIRED') return 'ยังไม่มี hash ของเอกสาร';
    if (e?.message === 'REJECT_REASON_REQUIRED') return 'ต้องใส่เหตุผลที่ไม่ผ่าน';
    if (/network|timeout|failed to fetch/i.test(blob)) return 'ต่อกับเชนไม่ได้ — ลองใหม่อีกครั้ง';
    return e?.reason ? `สัญญาปฏิเสธ: ${e.reason}` : 'ทำรายการไม่สำเร็จ — ลองใหม่อีกครั้ง';
}
