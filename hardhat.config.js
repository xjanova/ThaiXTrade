/**
 * TPIX TRADE — Hardhat Configuration
 * Networks: BSC Mainnet, BSC Testnet, TPIX Chain, TPIX Testnet
 * ใช้สำหรับ compile, deploy, verify smart contracts
 * Developed by Xman Studio
 */

require('@nomicfoundation/hardhat-toolbox');
require('dotenv').config();

/** @type import('hardhat/config').HardhatUserConfig */
module.exports = {
    solidity: {
        version: '0.8.20',
        settings: {
            optimizer: {
                enabled: true,
                runs: 200,
            },
            evmVersion: 'paris',
        },
    },

    networks: {
        // BSC Mainnet — deploy TokenSale + wTPIX contracts
        bsc: {
            url: process.env.BSC_RPC_URL || 'https://bsc-dataseed1.binance.org',
            chainId: 56,
            accounts: process.env.DEPLOYER_PRIVATE_KEY
                ? [process.env.DEPLOYER_PRIVATE_KEY]
                : [],
        },

        // BSC Testnet — ทดสอบก่อน deploy mainnet
        bscTestnet: {
            url: 'https://data-seed-prebsc-1-s1.binance.org:8545',
            chainId: 97,
            accounts: process.env.DEPLOYER_PRIVATE_KEY
                ? [process.env.DEPLOYER_PRIVATE_KEY]
                : [],
        },

        // TPIX Chain Mainnet — deploy DEX contracts
        tpix: {
            url: process.env.TPIX_RPC_URL || 'http://localhost:8545',
            chainId: 4289,
            accounts: process.env.DEPLOYER_PRIVATE_KEY
                ? [process.env.DEPLOYER_PRIVATE_KEY]
                : [],
            gasPrice: 0,
        },

        // TPIX Chain Testnet
        tpixTestnet: {
            url: process.env.TPIX_TESTNET_RPC_URL || 'http://localhost:8545',
            chainId: 4290,
            accounts: process.env.DEPLOYER_PRIVATE_KEY
                ? [process.env.DEPLOYER_PRIVATE_KEY]
                : [],
            gasPrice: 0,
        },
    },

    paths: {
        sources: './contracts',
        tests: './tests/contracts',
        cache: './cache',
        artifacts: './artifacts',
    },

    etherscan: {
        apiKey: {
            bsc: process.env.BSCSCAN_API_KEY || '',
            bscTestnet: process.env.BSCSCAN_API_KEY || '',
        },
    },
};
