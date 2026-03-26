/**
 * Deploy WTPIX (Wrapped TPIX) to TPIX Chain
 * Usage: npx hardhat run scripts/deploy/deploy-wtpix.js --network tpix
 * Developed by Xman Studio
 */

const { ethers } = require('hardhat');

async function main() {
    const [deployer] = await ethers.getSigners();
    console.log('Deploying WTPIX with account:', deployer.address);

    const balance = await ethers.provider.getBalance(deployer.address);
    console.log('Account balance:', ethers.formatEther(balance), 'TPIX');

    // Deploy WTPIX
    const WTPIX = await ethers.getContractFactory('WTPIX');
    const wtpix = await WTPIX.deploy();
    await wtpix.waitForDeployment();

    const address = await wtpix.getAddress();
    console.log('WTPIX deployed to:', address);

    // Wrap 1000 TPIX as initial liquidity so it shows on explorer
    console.log('Wrapping 1,000 TPIX...');
    const tx = await wtpix.deposit({ value: ethers.parseEther('1000') });
    await tx.wait();
    console.log('Wrapped 1,000 TPIX → WTPIX');

    const wtpixBalance = await wtpix.balanceOf(deployer.address);
    console.log('WTPIX balance:', ethers.formatEther(wtpixBalance));

    console.log('\n=== WTPIX Deployment Complete ===');
    console.log('Contract:', address);
    console.log('Name: Wrapped TPIX');
    console.log('Symbol: WTPIX');
    console.log('Decimals: 18');
    console.log('Explorer: https://explorer.tpix.online/address/' + address);
}

main()
    .then(() => process.exit(0))
    .catch((error) => {
        console.error(error);
        process.exit(1);
    });
