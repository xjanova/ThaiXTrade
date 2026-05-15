/**
 * TPIX Master Node — Delegation Manager
 *
 * จัดการระบบ "dual-key delegation" สำหรับ allowlist:
 *
 *   - Wallet (main)    → store เงิน, stake, governance — sign แค่ 1 ครั้งเพื่อ delegate
 *   - Delegate key     → key รองอยู่บนเครื่อง masternode — sign heartbeat ทุก 30 นาที
 *
 * ความปลอดภัย:
 *   - ถ้า delegate key รั่ว → revoke ได้ทันที (ลบไฟล์ + สร้างใหม่)
 *   - ถ้า private key หลักรั่ว → ค่าเสียหายมาก (ขโมย stake ได้) — เลยไม่ใช้ sign บ่อย
 *   - delegate key ถูก encrypt ด้วย password เดียวกับ wallet
 *
 * File layout (~/.tpix-node/):
 *   - delegation.json
 *     {
 *       "delegateAddress": "0x...",
 *       "delegateEncryptedKey": "...",
 *       "delegationSignature": "0x...",
 *       "delegationExpiresAt": 1781200000,
 *       "walletAddress": "0x...",
 *       "createdAt": "2026-05-09T..."
 *     }
 *
 * Developed by Xman Studio
 */

const crypto = require('crypto');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { ethers } = require('ethers');

const DATA_DIR = path.join(os.homedir(), '.tpix-node');
const DELEGATION_FILE = path.join(DATA_DIR, 'delegation.json');

const DELEGATION_PREFIX = 'tpix-masternode-delegate';
const HEARTBEAT_PREFIX = 'tpix-masternode-heartbeat';

// Default delegation lifetime: 30 days
const DEFAULT_LIFETIME_SECONDS = 30 * 24 * 3600;

class DelegationManager {
    constructor(walletManager) {
        this.walletManager = walletManager;
        this._delegateWallet = null;   // ethers.Wallet (loaded after unlock)
        this._meta = null;              // delegation.json content
    }

    exists() {
        return fs.existsSync(DELEGATION_FILE);
    }

    /**
     * Generate ใหม่ — เรียกครั้งเดียวตอน setup
     *   - Create new random delegate key
     *   - Use main wallet (decrypted) เพื่อ sign delegation
     *   - Save encrypted delegate key + delegation proof
     *
     * @param {string} password — password เดียวกับ wallet
     * @param {number} lifetimeSeconds — delegation อยู่ได้กี่วินาที (default 30 วัน)
     */
    async create(password = '', lifetimeSeconds = DEFAULT_LIFETIME_SECONDS) {
        if (typeof password !== 'string') {
            throw new Error('password required');
        }

        // Decrypt main wallet
        const mainPrivateKey = this.walletManager.exportKey(password);
        if (!mainPrivateKey) {
            throw new Error('Cannot unlock main wallet — wrong password?');
        }

        const mainWallet = new ethers.Wallet(mainPrivateKey);
        const walletAddress = mainWallet.address.toLowerCase();

        // Generate delegate key
        const delegateWallet = ethers.Wallet.createRandom();
        const delegateAddress = delegateWallet.address.toLowerCase();

        // Sign delegation message (with main wallet)
        const expiresAt = Math.floor(Date.now() / 1000) + lifetimeSeconds;
        const delegationMessage = `${DELEGATION_PREFIX}:${walletAddress}:${delegateAddress}:${expiresAt}`;
        const delegationSignature = await mainWallet.signMessage(delegationMessage);

        // Encrypt delegate private key (same scheme as wallet)
        const salt = crypto.randomBytes(32).toString('hex');
        const key = this._deriveKey(password, salt);
        const iv = crypto.randomBytes(12);
        const cipher = crypto.createCipheriv('aes-256-gcm', key, iv);
        let encrypted = cipher.update(delegateWallet.privateKey, 'utf8', 'hex');
        encrypted += cipher.final('hex');
        const authTag = cipher.getAuthTag();

        const meta = {
            walletAddress,
            delegateAddress,
            delegateEncryptedKey: encrypted,
            delegateSalt: salt,
            delegateIv: iv.toString('hex'),
            delegateAuthTag: authTag.toString('hex'),
            delegationSignature,
            delegationExpiresAt: expiresAt,
            createdAt: new Date().toISOString(),
        };

        if (!fs.existsSync(DATA_DIR)) {
            fs.mkdirSync(DATA_DIR, { recursive: true });
        }
        fs.writeFileSync(DELEGATION_FILE, JSON.stringify(meta, null, 2), { mode: 0o600 });

        this._meta = meta;
        this._delegateWallet = delegateWallet;

        return {
            success: true,
            walletAddress,
            delegateAddress,
            delegationExpiresAt: expiresAt,
        };
    }

    /**
     * Load + decrypt delegate key (cached)
     */
    unlock(password = '') {
        if (this._delegateWallet) return true;

        if (!this.exists()) {
            throw new Error('No delegation found — run setup first');
        }

        const meta = JSON.parse(fs.readFileSync(DELEGATION_FILE, 'utf-8'));

        if (!meta.delegateEncryptedKey) {
            throw new Error('Delegation file missing encrypted key');
        }

        const key = this._deriveKey(password, meta.delegateSalt);
        const decipher = crypto.createDecipheriv('aes-256-gcm', key, Buffer.from(meta.delegateIv, 'hex'));
        decipher.setAuthTag(Buffer.from(meta.delegateAuthTag, 'hex'));

        let decrypted = decipher.update(meta.delegateEncryptedKey, 'hex', 'utf8');
        decrypted += decipher.final('utf8');

        this._meta = meta;
        this._delegateWallet = new ethers.Wallet(decrypted);

        return true;
    }

    /**
     * Get metadata (without decrypting key) — สำหรับ UI display
     */
    getInfo() {
        if (!this.exists()) {
            return { exists: false };
        }

        const meta = this._meta || JSON.parse(fs.readFileSync(DELEGATION_FILE, 'utf-8'));
        const now = Math.floor(Date.now() / 1000);

        return {
            exists: true,
            walletAddress: meta.walletAddress,
            delegateAddress: meta.delegateAddress,
            delegationExpiresAt: meta.delegationExpiresAt,
            createdAt: meta.createdAt,
            isExpired: meta.delegationExpiresAt < now,
            secondsUntilExpiry: meta.delegationExpiresAt - now,
        };
    }

    /**
     * Sign heartbeat ด้วย delegate key — return signature ที่ส่งไป backend
     */
    async signHeartbeat() {
        if (!this._delegateWallet || !this._meta) {
            throw new Error('Delegation not unlocked');
        }

        const timestamp = Math.floor(Date.now() / 1000);
        const message = `${HEARTBEAT_PREFIX}:${this._meta.walletAddress}:${timestamp}`;
        const signature = await this._delegateWallet.signMessage(message);

        return {
            wallet: this._meta.walletAddress,
            delegate_address: this._meta.delegateAddress,
            delegation_signature: this._meta.delegationSignature,
            delegation_expires_at: this._meta.delegationExpiresAt,
            timestamp,
            signature,
        };
    }

    /**
     * Revoke — ลบ delegation file (force user re-setup)
     * ใช้กรณี delegate key อาจรั่ว
     */
    revoke() {
        if (fs.existsSync(DELEGATION_FILE)) {
            fs.unlinkSync(DELEGATION_FILE);
        }
        this._meta = null;
        this._delegateWallet = null;
        return { success: true };
    }

    /**
     * Same key derivation scheme as WalletManager (machine-bound + PBKDF2)
     */
    _deriveKey(password = '', salt = '') {
        const machineId = os.hostname() + os.userInfo().username;
        const combined = password + ':' + machineId + ':' + salt;
        return crypto.pbkdf2Sync(combined, 'tpix-node-delegate:' + salt, 100000, 32, 'sha256');
    }
}

module.exports = DelegationManager;
