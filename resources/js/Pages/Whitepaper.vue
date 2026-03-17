<script setup>
/**
 * TPIX TRADE - Whitepaper Page
 * เอกสาร Whitepaper แบบ interactive บนเว็บ
 * รองรับ smooth scroll, table of contents, download PDF
 * Developed by Xman Studio
 */

import { ref, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// Table of Contents — สารบัญ
const sections = [
    { id: 'executive-summary', title: '1. Executive Summary' },
    { id: 'problem-solution', title: '2. Problem & Solution' },
    { id: 'tpix-chain', title: '3. TPIX Chain Architecture' },
    { id: 'tokenomics', title: '4. Tokenomics' },
    { id: 'dex-protocol', title: '5. DEX Protocol' },
    { id: 'token-sale', title: '6. Token Sale Details' },
    { id: 'staking', title: '7. Staking & Rewards' },
    { id: 'ecosystem', title: '8. Ecosystem & Affiliate' },
    { id: 'roadmap', title: '9. Roadmap' },
    { id: 'team', title: '10. Team & Partners' },
    { id: 'security', title: '11. Security & Audits' },
    { id: 'legal', title: '12. Legal Disclaimer' },
];

// Section ที่กำลังดูอยู่
const activeSection = ref('executive-summary');

// Scroll ไปยัง section
function scrollTo(id) {
    activeSection.value = id;
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ติดตาม scroll เพื่ออัปเดต active section
onMounted(() => {
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    activeSection.value = entry.target.id;
                }
            });
        },
        { threshold: 0.3, rootMargin: '-80px 0px -50% 0px' }
    );

    sections.forEach((s) => {
        const el = document.getElementById(s.id);
        if (el) observer.observe(el);
    });
});
</script>

<template>
    <Head title="TPIX Chain Whitepaper" />

    <AppLayout :hide-sidebar="true">
        <!-- Hero -->
        <section class="relative py-16 overflow-hidden">
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute top-0 left-1/4 w-96 h-96 rounded-full bg-primary-500/10 blur-[120px]" />
                <div class="absolute bottom-0 right-1/3 w-80 h-80 rounded-full bg-accent-500/10 blur-[100px]" />
            </div>

            <div class="relative max-w-4xl mx-auto px-4 text-center">
                <img src="/logo.png" alt="TPIX" class="w-24 h-24 mx-auto mb-6 rounded-full" />
                <h1 class="text-4xl sm:text-5xl font-bold text-white mb-3">TPIX Chain Whitepaper</h1>
                <p class="text-lg text-gray-400 mb-6">Version 1.0 — March 2026</p>

                <a href="/whitepaper/download" class="btn-primary px-8 py-3 inline-flex items-center gap-2 font-semibold">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    Download PDF
                </a>
            </div>
        </section>

        <!-- Content: TOC + Sections -->
        <div class="max-w-6xl mx-auto px-4 sm:px-6 pb-20">
            <div class="flex gap-8">
                <!-- Sidebar: Table of Contents (sticky) -->
                <aside class="hidden lg:block w-64 flex-shrink-0">
                    <div class="sticky top-24">
                        <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Contents</h3>
                        <nav class="space-y-1">
                            <button
                                v-for="s in sections"
                                :key="s.id"
                                class="block w-full text-left px-3 py-1.5 rounded-lg text-sm transition-colors"
                                :class="activeSection === s.id
                                    ? 'text-primary-400 bg-primary-500/10'
                                    : 'text-gray-400 hover:text-white hover:bg-white/5'"
                                @click="scrollTo(s.id)"
                            >
                                {{ s.title }}
                            </button>
                        </nav>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1 min-w-0">
                    <!-- 1. Executive Summary -->
                    <section id="executive-summary" class="wp-section">
                        <h2 class="wp-heading">1. Executive Summary</h2>
                        <p class="wp-text">
                            TPIX Chain is a next-generation EVM-compatible blockchain built on Polygon Edge technology,
                            designed for the Thai and Southeast Asian markets. With zero gas fees, 2-second block times,
                            and IBFT Proof-of-Authority consensus, TPIX Chain provides a fast, free, and scalable
                            platform for decentralized applications.
                        </p>
                        <p class="wp-text">
                            The TPIX token (7 billion total supply) serves as the native coin of the chain, powering
                            staking rewards, governance, the built-in DEX, token factory, cross-chain bridge, and
                            an affiliate referral program.
                        </p>
                        <div class="wp-highlight">
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
                                <div><p class="text-2xl font-bold text-primary-400">7B</p><p class="text-xs text-gray-400">Total Supply</p></div>
                                <div><p class="text-2xl font-bold text-trading-green">0 Gas</p><p class="text-xs text-gray-400">Transaction Fee</p></div>
                                <div><p class="text-2xl font-bold text-accent-400">2s</p><p class="text-xs text-gray-400">Block Time</p></div>
                                <div><p class="text-2xl font-bold text-warm-400">IBFT</p><p class="text-xs text-gray-400">Consensus</p></div>
                            </div>
                        </div>
                    </section>

                    <!-- 2. Problem & Solution -->
                    <section id="problem-solution" class="wp-section">
                        <h2 class="wp-heading">2. Problem & Solution</h2>
                        <h3 class="wp-subheading">The Problem</h3>
                        <ul class="wp-list">
                            <li>High gas fees on Ethereum and BSC make micro-transactions impractical</li>
                            <li>Existing DEXes are complex and intimidating for new users</li>
                            <li>Limited blockchain infrastructure for Southeast Asian markets</li>
                            <li>No localized DeFi ecosystem with Thai language support</li>
                        </ul>

                        <h3 class="wp-subheading">Our Solution</h3>
                        <ul class="wp-list">
                            <li><strong class="text-white">Zero Gas Fees</strong> — All transactions on TPIX Chain are completely free</li>
                            <li><strong class="text-white">User-Friendly DEX</strong> — Intuitive trading interface with AI-powered insights</li>
                            <li><strong class="text-white">Local Focus</strong> — Built for Thai and ASEAN users with localized support</li>
                            <li><strong class="text-white">Cross-Chain Bridge</strong> — Seamless asset transfer between TPIX Chain and BSC</li>
                        </ul>
                    </section>

                    <!-- 3. TPIX Chain Architecture -->
                    <section id="tpix-chain" class="wp-section">
                        <h2 class="wp-heading">3. TPIX Chain Architecture</h2>
                        <p class="wp-text">
                            TPIX Chain is built on Polygon Edge, an open-source framework for building Ethereum-compatible
                            blockchain networks. It uses Istanbul Byzantine Fault Tolerant (IBFT) consensus with
                            4 validator nodes, providing immediate transaction finality.
                        </p>

                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">Parameter</th>
                                    <th class="text-left py-2 px-3 text-gray-400">Value</th>
                                </tr></thead>
                                <tbody>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-gray-300">Chain Name</td><td class="py-2 px-3 text-white font-medium">TPIX Chain</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-gray-300">Chain ID (Mainnet)</td><td class="py-2 px-3 text-white font-medium">7000</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-gray-300">Chain ID (Testnet)</td><td class="py-2 px-3 text-white font-medium">7001</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-gray-300">Consensus</td><td class="py-2 px-3 text-white font-medium">IBFT (PoA)</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-gray-300">Block Time</td><td class="py-2 px-3 text-white font-medium">2 seconds</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-gray-300">Gas Price</td><td class="py-2 px-3 text-trading-green font-medium">0 (Free)</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-gray-300">EVM Compatible</td><td class="py-2 px-3 text-white font-medium">Yes (Solidity)</td></tr>
                                    <tr><td class="py-2 px-3 text-gray-300">Validators</td><td class="py-2 px-3 text-white font-medium">4 nodes</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 4. Tokenomics -->
                    <section id="tokenomics" class="wp-section">
                        <h2 class="wp-heading">4. Tokenomics</h2>
                        <p class="wp-text">
                            TPIX has a fixed supply of 7,000,000,000 (7 billion) tokens with 18 decimals.
                            There is no inflation or minting mechanism — the total supply is pre-mined in the genesis block.
                        </p>

                        <h3 class="wp-subheading">Token Allocation</h3>
                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">Allocation</th>
                                    <th class="text-right py-2 px-3 text-gray-400">%</th>
                                    <th class="text-right py-2 px-3 text-gray-400">TPIX Amount</th>
                                    <th class="text-left py-2 px-3 text-gray-400">Vesting</th>
                                </tr></thead>
                                <tbody>
                                    <tr class="border-b border-white/5">
                                        <td class="py-2 px-3 text-white">Public Sale (ICO)</td>
                                        <td class="py-2 px-3 text-right text-primary-400 font-medium">10%</td>
                                        <td class="py-2 px-3 text-right text-gray-300">700,000,000</td>
                                        <td class="py-2 px-3 text-gray-400">10-25% TGE, 6-12m vesting</td>
                                    </tr>
                                    <tr class="border-b border-white/5">
                                        <td class="py-2 px-3 text-white">Liquidity Pool</td>
                                        <td class="py-2 px-3 text-right text-accent-400 font-medium">30%</td>
                                        <td class="py-2 px-3 text-right text-gray-300">2,100,000,000</td>
                                        <td class="py-2 px-3 text-gray-400">Locked in DEX pools</td>
                                    </tr>
                                    <tr class="border-b border-white/5">
                                        <td class="py-2 px-3 text-white">Staking Rewards</td>
                                        <td class="py-2 px-3 text-right text-trading-green font-medium">20%</td>
                                        <td class="py-2 px-3 text-right text-gray-300">1,400,000,000</td>
                                        <td class="py-2 px-3 text-gray-400">Distributed over 5 years</td>
                                    </tr>
                                    <tr class="border-b border-white/5">
                                        <td class="py-2 px-3 text-white">Team & Advisors</td>
                                        <td class="py-2 px-3 text-right text-warm-400 font-medium">20%</td>
                                        <td class="py-2 px-3 text-right text-gray-300">1,400,000,000</td>
                                        <td class="py-2 px-3 text-gray-400">1yr cliff, 3yr linear</td>
                                    </tr>
                                    <tr class="border-b border-white/5">
                                        <td class="py-2 px-3 text-white">Ecosystem Fund</td>
                                        <td class="py-2 px-3 text-right text-blue-400 font-medium">10%</td>
                                        <td class="py-2 px-3 text-right text-gray-300">700,000,000</td>
                                        <td class="py-2 px-3 text-gray-400">DAO governed</td>
                                    </tr>
                                    <tr>
                                        <td class="py-2 px-3 text-white">Development</td>
                                        <td class="py-2 px-3 text-right text-pink-400 font-medium">10%</td>
                                        <td class="py-2 px-3 text-right text-gray-300">700,000,000</td>
                                        <td class="py-2 px-3 text-gray-400">2yr linear</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 5. DEX Protocol -->
                    <section id="dex-protocol" class="wp-section">
                        <h2 class="wp-heading">5. DEX Protocol</h2>
                        <p class="wp-text">
                            TPIX DEX is a Uniswap V2 fork deployed natively on TPIX Chain. It provides automated
                            market making (AMM) with constant product formula (x * y = k) and a 0.3% swap fee
                            (0.25% to LPs, 0.05% to protocol treasury).
                        </p>

                        <h3 class="wp-subheading">Key Contracts</h3>
                        <ul class="wp-list">
                            <li><strong class="text-white">TPIXDEXFactory</strong> — Creates trading pair contracts</li>
                            <li><strong class="text-white">TPIXDEXRouter02</strong> — Handles multi-hop swaps and liquidity operations</li>
                            <li><strong class="text-white">TPIXDEXPair</strong> — Individual liquidity pool (ERC-20 LP tokens)</li>
                            <li><strong class="text-white">WTPIX</strong> — Wrapped TPIX for ERC-20 compatibility within the DEX</li>
                        </ul>
                    </section>

                    <!-- 6. Token Sale Details -->
                    <section id="token-sale" class="wp-section">
                        <h2 class="wp-heading">6. Token Sale Details</h2>
                        <p class="wp-text">
                            The TPIX token sale is conducted in 3 phases, accepting BNB and USDT on BSC.
                            Purchased tokens are allocated with a vesting schedule and can be claimed as
                            wTPIX (BEP-20) or native TPIX once the bridge is live.
                        </p>

                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">Phase</th>
                                    <th class="text-right py-2 px-3 text-gray-400">Price</th>
                                    <th class="text-right py-2 px-3 text-gray-400">Allocation</th>
                                    <th class="text-left py-2 px-3 text-gray-400">TGE</th>
                                    <th class="text-left py-2 px-3 text-gray-400">Vesting</th>
                                </tr></thead>
                                <tbody>
                                    <tr class="border-b border-white/5">
                                        <td class="py-2 px-3 text-white font-medium">Private Sale</td>
                                        <td class="py-2 px-3 text-right text-primary-400">$0.05</td>
                                        <td class="py-2 px-3 text-right text-gray-300">100M</td>
                                        <td class="py-2 px-3 text-gray-300">10%</td>
                                        <td class="py-2 px-3 text-gray-400">30d cliff, 180d linear</td>
                                    </tr>
                                    <tr class="border-b border-white/5">
                                        <td class="py-2 px-3 text-white font-medium">Pre-Sale</td>
                                        <td class="py-2 px-3 text-right text-primary-400">$0.08</td>
                                        <td class="py-2 px-3 text-right text-gray-300">200M</td>
                                        <td class="py-2 px-3 text-gray-300">15%</td>
                                        <td class="py-2 px-3 text-gray-400">14d cliff, 120d linear</td>
                                    </tr>
                                    <tr>
                                        <td class="py-2 px-3 text-white font-medium">Public Sale</td>
                                        <td class="py-2 px-3 text-right text-primary-400">$0.10</td>
                                        <td class="py-2 px-3 text-right text-gray-300">400M</td>
                                        <td class="py-2 px-3 text-gray-300">25%</td>
                                        <td class="py-2 px-3 text-gray-400">No cliff, 90d linear</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 7. Staking & Rewards -->
                    <section id="staking" class="wp-section">
                        <h2 class="wp-heading">7. Staking & Rewards</h2>
                        <p class="wp-text">
                            TPIX holders can stake their tokens to earn rewards. APY varies based on lock period,
                            with longer locks offering higher returns. Staking rewards come from the 20% allocation
                            (1.4B TPIX) distributed over 5 years.
                        </p>

                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">Lock Period</th>
                                    <th class="text-right py-2 px-3 text-gray-400">APY</th>
                                    <th class="text-left py-2 px-3 text-gray-400">Unlock</th>
                                </tr></thead>
                                <tbody>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">Flexible</td><td class="py-2 px-3 text-right text-trading-green">5%</td><td class="py-2 px-3 text-gray-400">Anytime</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">30 Days</td><td class="py-2 px-3 text-right text-trading-green">25%</td><td class="py-2 px-3 text-gray-400">After 30 days</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">90 Days</td><td class="py-2 px-3 text-right text-trading-green">60%</td><td class="py-2 px-3 text-gray-400">After 90 days</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">180 Days</td><td class="py-2 px-3 text-right text-trading-green">100%</td><td class="py-2 px-3 text-gray-400">After 180 days</td></tr>
                                    <tr><td class="py-2 px-3 text-white">365 Days</td><td class="py-2 px-3 text-right text-trading-green">200%</td><td class="py-2 px-3 text-gray-400">After 365 days</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 8. Ecosystem & Affiliate -->
                    <section id="ecosystem" class="wp-section">
                        <h2 class="wp-heading">8. Ecosystem & Affiliate</h2>
                        <p class="wp-text">
                            The TPIX ecosystem includes a comprehensive affiliate/referral program and a token factory
                            allowing anyone to create ERC-20 tokens on TPIX Chain.
                        </p>

                        <h3 class="wp-subheading">Affiliate Program</h3>
                        <ul class="wp-list">
                            <li><strong class="text-white">Referrer Reward:</strong> 5% of referee's first purchase</li>
                            <li><strong class="text-white">Referee Bonus:</strong> 2% extra tokens on first purchase</li>
                            <li><strong class="text-white">Max per Referral:</strong> 1,000 TPIX</li>
                        </ul>

                        <h3 class="wp-subheading">Token Factory</h3>
                        <p class="wp-text">
                            Users can create custom ERC-20 tokens on TPIX Chain for a fee of 100 TPIX per token.
                            This enables projects to launch their own tokens with zero gas costs for all subsequent
                            transactions.
                        </p>
                    </section>

                    <!-- 9. Roadmap -->
                    <section id="roadmap" class="wp-section">
                        <h2 class="wp-heading">9. Roadmap</h2>

                        <div class="space-y-6">
                            <div class="flex gap-4">
                                <div class="flex-shrink-0 w-24 text-sm font-bold text-primary-400">Q1 2026</div>
                                <div>
                                    <h4 class="text-white font-semibold">Foundation</h4>
                                    <ul class="wp-list mt-1">
                                        <li>TPIX Chain mainnet launch (4 IBFT validators)</li>
                                        <li>Blockscout explorer deployment</li>
                                        <li>Token Sale (3 phases)</li>
                                        <li>Whitepaper publication</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div class="flex-shrink-0 w-24 text-sm font-bold text-accent-400">Q2 2026</div>
                                <div>
                                    <h4 class="text-white font-semibold">DeFi Launch</h4>
                                    <ul class="wp-list mt-1">
                                        <li>TPIX DEX launch (Uniswap V2 fork)</li>
                                        <li>BSC Bridge (wTPIX ↔ TPIX)</li>
                                        <li>Staking platform</li>
                                        <li>Mobile wallet app</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div class="flex-shrink-0 w-24 text-sm font-bold text-trading-green">Q3 2026</div>
                                <div>
                                    <h4 class="text-white font-semibold">Ecosystem Growth</h4>
                                    <ul class="wp-list mt-1">
                                        <li>Token Factory launch</li>
                                        <li>Affiliate/Referral program</li>
                                        <li>NFT marketplace</li>
                                        <li>CEX listings</li>
                                    </ul>
                                </div>
                            </div>
                            <div class="flex gap-4">
                                <div class="flex-shrink-0 w-24 text-sm font-bold text-warm-400">Q4 2026</div>
                                <div>
                                    <h4 class="text-white font-semibold">Scale & Govern</h4>
                                    <ul class="wp-list mt-1">
                                        <li>DAO governance launch</li>
                                        <li>Multi-chain bridge expansion</li>
                                        <li>Enterprise partnerships</li>
                                        <li>Validator decentralization</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 10. Team & Partners -->
                    <section id="team" class="wp-section">
                        <h2 class="wp-heading">10. Team & Partners</h2>
                        <p class="wp-text">
                            TPIX Chain is developed by <strong class="text-white">Xman Studio</strong>, a blockchain
                            development team specializing in DeFi and Web3 applications for the Southeast Asian market.
                        </p>
                        <p class="wp-text">
                            The team has extensive experience in Solidity smart contract development, EVM chain deployment,
                            and full-stack Web3 application development.
                        </p>
                    </section>

                    <!-- 11. Security & Audits -->
                    <section id="security" class="wp-section">
                        <h2 class="wp-heading">11. Security & Audits</h2>
                        <ul class="wp-list">
                            <li><strong class="text-white">Smart Contract Audits</strong> — All contracts undergo third-party security audits before mainnet deployment</li>
                            <li><strong class="text-white">IBFT Consensus</strong> — Byzantine fault tolerance ensures network security with up to 1/3 faulty validators</li>
                            <li><strong class="text-white">Rate Limiting</strong> — RPC-level rate limiting prevents spam on the gasless chain</li>
                            <li><strong class="text-white">Multi-sig Treasury</strong> — Protocol funds managed by multi-signature wallets</li>
                            <li><strong class="text-white">Bug Bounty</strong> — Ongoing bug bounty program for responsible disclosure</li>
                        </ul>
                    </section>

                    <!-- 12. Legal Disclaimer -->
                    <section id="legal" class="wp-section">
                        <h2 class="wp-heading">12. Legal Disclaimer</h2>
                        <p class="wp-text text-gray-500 text-sm">
                            This whitepaper is for informational purposes only and does not constitute investment advice,
                            financial advice, trading advice, or any other sort of advice. TPIX tokens are utility tokens
                            and are not intended to be securities. The purchase of TPIX tokens involves significant risk.
                            Please conduct your own due diligence before participating in the token sale.
                        </p>
                        <p class="wp-text text-gray-500 text-sm">
                            The information in this whitepaper may be updated from time to time. The team reserves the
                            right to make changes to this document without prior notice. Nothing in this whitepaper
                            shall be deemed to constitute a prospectus of any sort or a solicitation for investment.
                        </p>
                    </section>
                </main>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.wp-section {
    @apply mb-16 scroll-mt-24;
}
.wp-heading {
    @apply text-2xl sm:text-3xl font-bold text-white mb-4 pb-3 border-b border-white/10;
}
.wp-subheading {
    @apply text-lg font-semibold text-white mt-6 mb-3;
}
.wp-text {
    @apply text-gray-300 leading-relaxed mb-4;
}
.wp-list {
    @apply list-disc list-inside space-y-2 text-gray-300 mb-4;
}
.wp-highlight {
    @apply p-6 rounded-xl bg-white/5 border border-white/10 my-6;
}
.wp-table {
    @apply rounded-xl bg-white/5 border border-white/10 overflow-hidden my-6;
}
</style>
