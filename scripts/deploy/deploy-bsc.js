/**
 * Deploy BSC Contracts — wTPIX + TokenSale
 * ใช้: npx hardhat run scripts/deploy/deploy-bsc.js --network bsc
 * Developed by Xman Studio
 */

const { ethers } = require('hardhat');

async function main() {
    const [deployer] = await ethers.getSigners();
    console.log('Deploying contracts with account:', deployer.address);
    console.log('Account balance:', (await ethers.provider.getBalance(deployer.address)).toString());

    // ที่อยู่ Treasury Wallet (รับเงินจากการขาย)
    const TREASURY_WALLET = process.env.TREASURY_WALLET || deployer.address;

    // USDT & BUSD addresses บน BSC
    const USDT_BSC = '0x55d398326f99059fF775485246999027B3197955';
    const BUSD_BSC = '0xe9e7CEA3DedcA5984780Bafc599bD69ADd087D56';

    // --- 1. Deploy wTPIX (Wrapped TPIX BEP-20) ---
    console.log('\n--- Deploying wTPIX (Wrapped TPIX) ---');
    const WTPIX = await ethers.getContractFactory('WTPIX');
    const wtpix = await WTPIX.deploy();
    await wtpix.waitForDeployment();
    const wtpixAddress = await wtpix.getAddress();
    console.log('wTPIX deployed to:', wtpixAddress);

    // --- 2. Deploy TPIXTokenSale ---
    console.log('\n--- Deploying TPIXTokenSale ---');
    const TokenSale = await ethers.getContractFactory('TPIXTokenSale');
    const tokenSale = await TokenSale.deploy(TREASURY_WALLET);
    await tokenSale.waitForDeployment();
    const tokenSaleAddress = await tokenSale.getAddress();
    console.log('TPIXTokenSale deployed to:', tokenSaleAddress);

    // --- 3. ตั้งค่า ---
    console.log('\n--- Setting up ---');

    // ตั้ง TokenSale เป็น minter ของ wTPIX
    await wtpix.setMinter(tokenSaleAddress, true);
    console.log('TokenSale set as wTPIX minter');

    // ตั้งค่า accepted tokens (USDT, BUSD)
    await tokenSale.setAcceptedToken(USDT_BSC, true);
    await tokenSale.setAcceptedToken(BUSD_BSC, true);
    console.log('USDT and BUSD accepted');

    // --- Summary ---
    console.log('\n========== DEPLOYMENT SUMMARY ==========');
    console.log('Network:       BSC Mainnet (Chain ID: 56)');
    console.log('Deployer:     ', deployer.address);
    console.log('Treasury:     ', TREASURY_WALLET);
    console.log('wTPIX:        ', wtpixAddress);
    console.log('TokenSale:    ', tokenSaleAddress);
    console.log('=========================================');
    console.log('\nNext steps:');
    console.log('1. Verify contracts on BscScan');
    console.log('2. Set VITE_SALE_WALLET_ADDRESS in .env');
    console.log('3. Activate sale: tokenSale.setSaleActive(true)');
}

main()
    .then(() => process.exit(0))
    .catch((error) => {
        console.error(error);
        process.exit(1);
    });
