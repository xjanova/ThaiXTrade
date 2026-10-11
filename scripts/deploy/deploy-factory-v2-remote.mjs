#!/usr/bin/env node
/**
 * ⛔ DEPRECATED — ห้ามใช้ deploy แฟกทอรีจาก repo นี้
 *
 * สคริปต์นี้เคยถูก workflow deploy-factory-v2.yml ส่งขึ้นไปรันบนเซิร์ฟเวอร์จริง
 * พร้อม artifact ที่คอมไพล์จากสำเนาเก่า (cancun, เกิน EIP-170) — ยกเลิกแล้ว
 *
 * สัญญาแฟกทอรีอยู่ที่ repo TPIX-Coin (contracts/src/token-factory) และ deploy
 * ด้วยสคริปต์ของ repo นั้นเท่านั้น จากนั้นลงทะเบียนที่อยู่ผ่าน /api/infra/contracts
 */
console.error(
    'deploy-factory-v2-remote.mjs ถูกยกเลิกแล้ว — deploy แฟกทอรีจาก repo TPIX-Coin ' +
    '(contracts/src/token-factory, evmVersion london) แล้วลงทะเบียนที่อยู่ผ่าน /api/infra/contracts'
);
process.exit(1);
