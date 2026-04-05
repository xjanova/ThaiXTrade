#!/usr/bin/env node
/**
 * TPIX Bridge Transfer Script
 * เรียกจาก PHP backend (BridgeService) ผ่าน Process::run()
 *
 * 2 Actions:
 *   send_native_tpix — ส่ง native TPIX จาก treasury ไป user (TPIX Chain, gasless)
 *   mint_wtpix       — เรียก wTPIX.mint(user, amount) บน BSC
 *
 * Input:  CLI arg as JSON + env vars
 * Output: JSON to stdout
 *
 * Env vars:
 *   BRIDGE_SIGNER_PRIVATE_KEY — Private key สำหรับ sign transactions
 *   TPIX_RPC_URL              — TPIX Chain RPC (default: https://rpc.tpix.online)
 *   BSC_RPC_URL               — BSC RPC (default: https://bsc-dataseed.binance.org)
 *   WTPIX_BSC_ADDRESS         — wTPIX contract address บน BSC
 *
 * CLI: node bridge-transfer.js '{"action":"send_native_tpix","to":"0x...","amount":"100.5"}'
 *
 * Developed by Xman Studio
 */

const { ethers } = require('ethers');

// ═══════════════════════════════════════════
//  wTPIX ABI (เฉพาะ function ที่ใช้)
// ═══════════════════════════════════════════
const WTPIX_ABI = [
    'function mint(address to, uint256 amount) external',
    'function totalSupply() view returns (uint256)',
    'function balanceOf(address) view returns (uint256)',
];

// ═══════════════════════════════════════════
//  MAIN
// ═══════════════════════════════════════════
async function main() {
    const input = JSON.parse(process.argv[2] || '{}');
    const { action, to, amount } = input;

    if (!action || !to || !amount) {
        throw new Error('Missing required params: action, to, amount');
    }

    if (!ethers.isAddress(to)) {
        throw new Error(`Invalid address: ${to}`);
    }

    const amountWei = ethers.parseEther(String(amount));
    const signerKey = process.env.BRIDGE_SIGNER_PRIVATE_KEY;

    if (!signerKey) {
        throw new Error('BRIDGE_SIGNER_PRIVATE_KEY not set');
    }

    if (action === 'send_native_tpix') {
        return await sendNativeTpix(signerKey, to, amountWei);
    } else if (action === 'mint_wtpix') {
        return await mintWtpix(signerKey, to, amountWei);
    } else {
        throw new Error(`Unknown action: ${action}`);
    }
}

// ═══════════════════════════════════════════
//  Action 1: ส่ง native TPIX บน TPIX Chain (gasless)
// ═══════════════════════════════════════════
async function sendNativeTpix(signerKey, to, amountWei) {
    const rpcUrl = process.env.TPIX_RPC_URL || 'https://rpc.tpix.online';
    const provider = new ethers.JsonRpcProvider(rpcUrl, 4289);
    const wallet = new ethers.Wallet(signerKey, provider);

    // ตรวจ balance ก่อนส่ง
    const balance = await provider.getBalance(wallet.address);
    if (balance < amountWei) {
        throw new Error(`Insufficient treasury balance: have ${ethers.formatEther(balance)} TPIX, need ${ethers.formatEther(amountWei)}`);
    }

    // TPIX Chain = gasless (gasPrice: 0)
    const tx = await wallet.sendTransaction({
        to,
        value: amountWei,
        gasPrice: 0,
        gasLimit: 21000,
    });

    const receipt = await tx.wait(1); // รอ 1 confirmation

    return {
        success: true,
        action: 'send_native_tpix',
        tx_hash: receipt.hash,
        from: wallet.address,
        to,
        amount: ethers.formatEther(amountWei),
        chain_id: 4289,
        block_number: receipt.blockNumber,
    };
}

// ═══════════════════════════════════════════
//  Action 2: Mint wTPIX บน BSC
// ═══════════════════════════════════════════
async function mintWtpix(signerKey, to, amountWei) {
    const rpcUrl = process.env.BSC_RPC_URL || 'https://bsc-dataseed.binance.org';
    const wtpixAddress = process.env.WTPIX_BSC_ADDRESS;

    if (!wtpixAddress) {
        throw new Error('WTPIX_BSC_ADDRESS not set');
    }

    const provider = new ethers.JsonRpcProvider(rpcUrl, 56);
    const wallet = new ethers.Wallet(signerKey, provider);

    const wtpix = new ethers.Contract(wtpixAddress, WTPIX_ABI, wallet);

    // เรียก mint (backend wallet ต้องเป็น minter)
    const tx = await wtpix.mint(to, amountWei);
    const receipt = await tx.wait(1);

    return {
        success: true,
        action: 'mint_wtpix',
        tx_hash: receipt.hash,
        from: wallet.address,
        to,
        amount: ethers.formatEther(amountWei),
        chain_id: 56,
        block_number: receipt.blockNumber,
        contract: wtpixAddress,
    };
}

// ═══════════════════════════════════════════
//  Execute & Output
// ═══════════════════════════════════════════
main()
    .then((result) => {
        process.stdout.write(JSON.stringify(result));
        process.exit(0);
    })
    .catch((err) => {
        process.stdout.write(JSON.stringify({
            success: false,
            error: err.message || String(err),
        }));
        process.exit(1);
    });
