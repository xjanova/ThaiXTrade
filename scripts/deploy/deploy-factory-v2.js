#!/usr/bin/env node
/**
 * ⛔ DEPRECATED — ห้ามใช้ deploy แฟกทอรีจาก repo นี้
 *
 * สัญญาแฟกทอรีสร้างเหรียญ (TPIXTokenFactoryV2 / TPIXNFTFactory) ย้ายไปอยู่ที่ repo
 * TPIX-Coin (contracts/src/token-factory) แล้ว — คอมไพล์ด้วย solc 0.8.24, evmVersion
 * london ให้ตรงกับเชน TPIX (4289) และ deploy ด้วยสคริปต์ของ repo นั้นเท่านั้น
 *
 * สำเนาเก่าที่เคยอยู่ใน ThaiXTrade (contracts/factory + สคริปต์ชุดนี้) คอมไพล์เป็น
 * cancun และได้ bytecode 65,496 ไบต์ เกินเพดาน EIP-170 (24,576) — deploy จริงไม่ได้
 * และถ้าใช้ได้ก็จะได้สัญญาคนละรุ่นกับ ABI ที่ scripts/blockchain/abi ใช้อยู่
 *
 * หลัง deploy จาก TPIX-Coin แล้ว ให้ลงทะเบียนที่อยู่ผ่าน /api/infra/contracts
 * (ContractRegistry) — ไม่ต้องแก้ .env
 */
console.error(
    'deploy-factory-v2.js ถูกยกเลิกแล้ว — deploy แฟกทอรีจาก repo TPIX-Coin ' +
    '(contracts/src/token-factory, evmVersion london) แล้วลงทะเบียนที่อยู่ผ่าน /api/infra/contracts'
);
process.exit(1);
