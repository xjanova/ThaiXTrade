<?php

/**
 * ThaiXTrade - Blockchain Chains Configuration
 * Developed by Xman Studio
 *
 * Add new chains easily by adding to this configuration
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Chain
    |--------------------------------------------------------------------------
    */

    'default' => env('DEFAULT_CHAIN_ID', 56),

    /*
    |--------------------------------------------------------------------------
    | Supported Chains
    |--------------------------------------------------------------------------
    */

    'chains' => [

        // Ethereum Mainnet
        1 => [
            'name' => 'Ethereum',
            'shortName' => 'ETH',
            'chainId' => 1,
            'networkId' => 1,
            'rpc' => [
                env('WEB3_RPC_ETHEREUM', 'https://eth.llamarpc.com'),
                'https://ethereum.publicnode.com',
                'https://rpc.ankr.com/eth',
            ],
            'explorer' => 'https://etherscan.io',
            'nativeCurrency' => [
                'name' => 'Ether',
                'symbol' => 'ETH',
                'decimals' => 18,
            ],
            'icon' => 'https://cryptologos.cc/logos/ethereum-eth-logo.svg',
            'color' => '#627EEA',
            'enabled' => true,
        ],

        // BNB Smart Chain
        56 => [
            'name' => 'BNB Smart Chain',
            'shortName' => 'BSC',
            'chainId' => 56,
            'networkId' => 56,
            'rpc' => [
                env('WEB3_RPC_BSC', 'https://bsc-dataseed.binance.org'),
                'https://bsc-dataseed1.binance.org',
                'https://bsc-dataseed2.binance.org',
                'https://bsc.publicnode.com',
            ],
            'explorer' => 'https://bscscan.com',
            'nativeCurrency' => [
                'name' => 'BNB',
                'symbol' => 'BNB',
                'decimals' => 18,
            ],
            'icon' => 'https://cryptologos.cc/logos/bnb-bnb-logo.svg',
            'color' => '#F3BA2F',
            'enabled' => true,
        ],

        // Polygon
        137 => [
            'name' => 'Polygon',
            'shortName' => 'MATIC',
            'chainId' => 137,
            'networkId' => 137,
            'rpc' => [
                env('WEB3_RPC_POLYGON', 'https://polygon-rpc.com'),
                'https://polygon.llamarpc.com',
                'https://rpc.ankr.com/polygon',
            ],
            'explorer' => 'https://polygonscan.com',
            'nativeCurrency' => [
                'name' => 'MATIC',
                'symbol' => 'MATIC',
                'decimals' => 18,
            ],
            'icon' => 'https://cryptologos.cc/logos/polygon-matic-logo.svg',
            'color' => '#8247E5',
            'enabled' => true,
        ],

        // Arbitrum One
        42161 => [
            'name' => 'Arbitrum One',
            'shortName' => 'ARB',
            'chainId' => 42161,
            'networkId' => 42161,
            'rpc' => [
                env('WEB3_RPC_ARBITRUM', 'https://arb1.arbitrum.io/rpc'),
                'https://arbitrum.llamarpc.com',
                'https://rpc.ankr.com/arbitrum',
            ],
            'explorer' => 'https://arbiscan.io',
            'nativeCurrency' => [
                'name' => 'Ether',
                'symbol' => 'ETH',
                'decimals' => 18,
            ],
            'icon' => 'https://cryptologos.cc/logos/arbitrum-arb-logo.svg',
            'color' => '#28A0F0',
            'enabled' => true,
        ],

        // Optimism
        10 => [
            'name' => 'Optimism',
            'shortName' => 'OP',
            'chainId' => 10,
            'networkId' => 10,
            'rpc' => [
                env('WEB3_RPC_OPTIMISM', 'https://mainnet.optimism.io'),
                'https://optimism.llamarpc.com',
                'https://rpc.ankr.com/optimism',
            ],
            'explorer' => 'https://optimistic.etherscan.io',
            'nativeCurrency' => [
                'name' => 'Ether',
                'symbol' => 'ETH',
                'decimals' => 18,
            ],
            'icon' => 'https://cryptologos.cc/logos/optimism-ethereum-op-logo.svg',
            'color' => '#FF0420',
            'enabled' => true,
        ],

        // Avalanche C-Chain
        43114 => [
            'name' => 'Avalanche C-Chain',
            'shortName' => 'AVAX',
            'chainId' => 43114,
            'networkId' => 43114,
            'rpc' => [
                env('WEB3_RPC_AVALANCHE', 'https://api.avax.network/ext/bc/C/rpc'),
                'https://avalanche.public-rpc.com',
                'https://rpc.ankr.com/avalanche',
            ],
            'explorer' => 'https://snowtrace.io',
            'nativeCurrency' => [
                'name' => 'Avalanche',
                'symbol' => 'AVAX',
                'decimals' => 18,
            ],
            'icon' => 'https://cryptologos.cc/logos/avalanche-avax-logo.svg',
            'color' => '#E84142',
            'enabled' => true,
        ],

        // Fantom
        250 => [
            'name' => 'Fantom',
            'shortName' => 'FTM',
            'chainId' => 250,
            'networkId' => 250,
            'rpc' => [
                env('WEB3_RPC_FANTOM', 'https://rpc.ftm.tools'),
                'https://fantom.publicnode.com',
                'https://rpc.ankr.com/fantom',
            ],
            'explorer' => 'https://ftmscan.com',
            'nativeCurrency' => [
                'name' => 'Fantom',
                'symbol' => 'FTM',
                'decimals' => 18,
            ],
            'icon' => 'https://cryptologos.cc/logos/fantom-ftm-logo.svg',
            'color' => '#1969FF',
            'enabled' => true,
        ],

        // Base
        8453 => [
            'name' => 'Base',
            'shortName' => 'BASE',
            'chainId' => 8453,
            'networkId' => 8453,
            'rpc' => [
                'https://mainnet.base.org',
                'https://base.llamarpc.com',
                'https://base.publicnode.com',
            ],
            'explorer' => 'https://basescan.org',
            'nativeCurrency' => [
                'name' => 'Ether',
                'symbol' => 'ETH',
                'decimals' => 18,
            ],
            'icon' => 'https://raw.githubusercontent.com/base-org/brand-kit/main/logo/symbol/Base_Symbol_Blue.svg',
            'color' => '#0052FF',
            'enabled' => true,
        ],

        // zkSync Era
        324 => [
            'name' => 'zkSync Era',
            'shortName' => 'ZKSYNC',
            'chainId' => 324,
            'networkId' => 324,
            'rpc' => [
                'https://mainnet.era.zksync.io',
            ],
            'explorer' => 'https://explorer.zksync.io',
            'nativeCurrency' => [
                'name' => 'Ether',
                'symbol' => 'ETH',
                'decimals' => 18,
            ],
            'icon' => 'https://cryptologos.cc/logos/zksync-zks-logo.svg',
            'color' => '#8C8DFC',
            'enabled' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testnet Chains (Development)
    |--------------------------------------------------------------------------
    */

    'testnets' => [

        // Sepolia (Ethereum Testnet)
        11155111 => [
            'name' => 'Sepolia',
            'shortName' => 'SEP',
            'chainId' => 11155111,
            'rpc' => ['https://rpc.sepolia.org'],
            'explorer' => 'https://sepolia.etherscan.io',
            'nativeCurrency' => [
                'name' => 'SepoliaETH',
                'symbol' => 'ETH',
                'decimals' => 18,
            ],
            'enabled' => env('APP_ENV') !== 'production',
        ],

        // BSC Testnet
        97 => [
            'name' => 'BSC Testnet',
            'shortName' => 'tBNB',
            'chainId' => 97,
            'rpc' => ['https://data-seed-prebsc-1-s1.binance.org:8545'],
            'explorer' => 'https://testnet.bscscan.com',
            'nativeCurrency' => [
                'name' => 'tBNB',
                'symbol' => 'tBNB',
                'decimals' => 18,
            ],
            'enabled' => env('APP_ENV') !== 'production',
        ],

    ],

];
