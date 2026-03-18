/**
 * TPIX TRADE — Embedded Wallet Core
 * จัดการ key generation, encryption, storage สำหรับ TPIX Wallet ในตัวเว็บ
 * Private key ไม่เคยส่งไป server — encrypt/decrypt ใน browser เท่านั้น
 * Developed by Xman Studio.
 */

import { ethers } from 'ethers';
import { TPIX_CHAIN_CONFIG } from './web3';

// localStorage keys — เก็บ encrypted wallet data
const STORAGE_KEY_ENCRYPTED = 'tpix_wallet_encrypted';
const STORAGE_KEY_ADDRESS = 'tpix_wallet_address';

/**
 * สร้าง wallet ใหม่ — generate mnemonic 12 คำ + keypair
 * @returns {{ wallet: ethers.HDNodeWallet, mnemonic: string, address: string }}
 */
export function generateWallet() {
    const wallet = ethers.Wallet.createRandom();
    return {
        wallet,
        mnemonic: wallet.mnemonic.phrase,
        address: wallet.address,
    };
}

/**
 * Import wallet จาก mnemonic (12/24 คำ)
 * @param {string} mnemonic — seed phrase
 * @returns {ethers.HDNodeWallet}
 */
export function importFromMnemonic(mnemonic) {
    const trimmed = mnemonic.trim().toLowerCase();
    if (!ethers.Mnemonic.isValidMnemonic(trimmed)) {
        throw new Error('Mnemonic ไม่ถูกต้อง — ตรวจสอบคำให้ครบ 12 คำ');
    }
    return ethers.Wallet.fromPhrase(trimmed);
}

/**
 * Import wallet จาก private key (hex)
 * @param {string} privateKey — 0x... หรือ hex string
 * @returns {ethers.Wallet}
 */
export function importFromPrivateKey(privateKey) {
    const key = privateKey.trim();
    if (!key.startsWith('0x')) {
        return new ethers.Wallet('0x' + key);
    }
    return new ethers.Wallet(key);
}

/**
 * เข้ารหัส wallet ด้วย password แล้วเก็บใน localStorage
 * ใช้ ethers.js encrypt — AES-128-CTR + scrypt KDF
 * @param {ethers.Wallet|ethers.HDNodeWallet} wallet
 * @param {string} password — min 8 chars
 * @returns {Promise<string>} — address ที่เก็บ
 */
export async function encryptAndStore(wallet, password) {
    if (password.length < 8) {
        throw new Error('Password ต้องมีอย่างน้อย 8 ตัวอักษร');
    }

    // เข้ารหัส private key ด้วย password (ใช้เวลาประมาณ 1-3 วินาที)
    const encrypted = await wallet.encrypt(password);

    // เก็บใน localStorage
    localStorage.setItem(STORAGE_KEY_ENCRYPTED, encrypted);
    localStorage.setItem(STORAGE_KEY_ADDRESS, wallet.address);

    return wallet.address;
}

/**
 * ถอดรหัส wallet จาก localStorage ด้วย password
 * @param {string} password
 * @returns {Promise<ethers.Wallet>} — wallet ที่พร้อมใช้งาน
 */
export async function unlockWallet(password) {
    const encrypted = localStorage.getItem(STORAGE_KEY_ENCRYPTED);
    if (!encrypted) {
        throw new Error('ไม่พบ wallet ที่เก็บไว้ — กรุณาสร้างหรือ import ใหม่');
    }

    try {
        const wallet = await ethers.Wallet.fromEncryptedJson(encrypted, password);
        return wallet;
    } catch {
        throw new Error('Password ไม่ถูกต้อง');
    }
}

/**
 * เชื่อม wallet กับ TPIX Chain RPC
 * @param {ethers.Wallet} wallet — unlocked wallet
 * @returns {ethers.Wallet} — wallet ที่เชื่อม provider แล้ว (sign + send tx ได้)
 */
export function connectToTPIXChain(wallet) {
    const provider = new ethers.JsonRpcProvider(
        TPIX_CHAIN_CONFIG.rpcUrls[0],
        {
            chainId: TPIX_CHAIN_CONFIG.chainIdNum,
            name: TPIX_CHAIN_CONFIG.chainName,
        }
    );
    return wallet.connect(provider);
}

/**
 * ดู TPIX balance ของ address
 * @param {string} address — wallet address
 * @returns {Promise<string>} — balance ในหน่วย TPIX (formatted)
 */
export async function getTPIXBalance(address) {
    const provider = new ethers.JsonRpcProvider(
        TPIX_CHAIN_CONFIG.rpcUrls[0],
        {
            chainId: TPIX_CHAIN_CONFIG.chainIdNum,
            name: TPIX_CHAIN_CONFIG.chainName,
        }
    );
    const balance = await provider.getBalance(address);
    return ethers.formatEther(balance);
}

/**
 * ส่ง TPIX (native coin) — gasless! gasPrice = 0
 * @param {ethers.Wallet} connectedWallet — wallet ที่ connect provider แล้ว
 * @param {string} toAddress — ปลายทาง
 * @param {string} amount — จำนวน TPIX (เช่น "100.5")
 * @returns {Promise<ethers.TransactionResponse>}
 */
export async function sendTPIX(connectedWallet, toAddress, amount) {
    if (!ethers.isAddress(toAddress)) {
        throw new Error('Address ปลายทางไม่ถูกต้อง');
    }

    const value = ethers.parseEther(amount);

    // TPIX Chain เป็น gasless — gas price = 0
    const tx = await connectedWallet.sendTransaction({
        to: toAddress,
        value,
        gasPrice: 0,
        gasLimit: 21000,
    });

    return tx;
}

/**
 * ตรวจว่ามี wallet เก็บอยู่ใน localStorage หรือไม่
 * @returns {boolean}
 */
export function isWalletStored() {
    return !!localStorage.getItem(STORAGE_KEY_ENCRYPTED);
}

/**
 * อ่าน address ที่เก็บไว้ (ไม่ต้อง unlock)
 * @returns {string|null}
 */
export function getStoredAddress() {
    return localStorage.getItem(STORAGE_KEY_ADDRESS);
}

/**
 * ลบ wallet ออกจาก localStorage
 */
export function clearWallet() {
    localStorage.removeItem(STORAGE_KEY_ENCRYPTED);
    localStorage.removeItem(STORAGE_KEY_ADDRESS);
}

/**
 * Export private key (ต้อง unlock ก่อน)
 * @param {string} password
 * @returns {Promise<string>} — private key hex
 */
export async function exportPrivateKey(password) {
    const wallet = await unlockWallet(password);
    return wallet.privateKey;
}

/**
 * Export mnemonic (ต้อง unlock ก่อน — ถ้า wallet สร้างจาก mnemonic)
 * @param {string} password
 * @returns {Promise<string|null>} — mnemonic phrase หรือ null ถ้า import จาก private key
 */
export async function exportMnemonic(password) {
    const wallet = await unlockWallet(password);
    return wallet.mnemonic?.phrase || null;
}
