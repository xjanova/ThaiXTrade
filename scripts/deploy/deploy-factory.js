/**
 * Deploy TPIXTokenFactory to TPIX Chain
 * Usage: npx hardhat run scripts/deploy/deploy-factory.js --network tpix
 * Developed by Xman Studio
 */

const { ethers } = require('hardhat');

async function main() {
    const [deployer] = await ethers.getSigners();
    console.log('Deploying TPIXTokenFactory with account:', deployer.address);
    console.log('Account balance:', (await ethers.provider.getBalance(deployer.address)).toString());

    // --- Deploy TPIXTokenFactory ---
    console.log('\n--- Deploying TPIXTokenFactory ---');
    const Factory = await ethers.getContractFactory('TPIXTokenFactory');
    const factory = await Factory.deploy();
    await factory.waitForDeployment();
    const factoryAddress = await factory.getAddress();

    // --- Summary ---
    console.log('\n========== DEPLOYMENT SUMMARY ==========');
    console.log('Network:          TPIX Chain (Chain ID: 4289)');
    console.log('Deployer:        ', deployer.address);
    console.log('TPIXTokenFactory:', factoryAddress);
    console.log('=========================================');
    console.log('\nAdd to .env:');
    console.log(`TOKEN_FACTORY_ADDRESS=${factoryAddress}`);
}

main()
    .then(() => process.exit(0))
    .catch((error) => {
        console.error(error);
        process.exit(1);
    });
