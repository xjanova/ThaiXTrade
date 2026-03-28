<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>TPIX Chain Whitepaper v2.0</title>
    <style>
        /* === Base Styles === */
        @page {
            margin: 60px 50px;
            size: A4;
        }
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            color: #1a1a2e;
            font-size: 11pt;
            line-height: 1.6;
            background: #ffffff;
        }

        /* === Cover Page === */
        .cover {
            text-align: center;
            padding-top: 180px;
            page-break-after: always;
        }
        .cover-logo {
            width: 120px;
            height: 120px;
            margin: 0 auto 30px;
        }
        .cover h1 {
            font-size: 36pt;
            color: #06b6d4;
            margin: 0 0 10px;
            letter-spacing: 2px;
        }
        .cover h2 {
            font-size: 16pt;
            color: #6b7280;
            font-weight: normal;
            margin: 0 0 40px;
        }
        .cover .version {
            font-size: 12pt;
            color: #9ca3af;
            margin-top: 60px;
        }
        .cover .developer {
            font-size: 10pt;
            color: #9ca3af;
        }
        .cover .tagline {
            font-size: 11pt;
            color: #374151;
            margin-top: 20px;
            font-style: italic;
        }

        /* === Headers === */
        h1 { font-size: 22pt; color: #06b6d4; margin: 30px 0 15px; page-break-after: avoid; }
        h2 { font-size: 16pt; color: #1a1a2e; margin: 25px 0 10px; page-break-after: avoid; }
        h3 { font-size: 13pt; color: #374151; margin: 20px 0 8px; page-break-after: avoid; }

        /* === Text === */
        p { margin: 0 0 12px; text-align: justify; }
        ul { margin: 0 0 12px; padding-left: 20px; }
        li { margin-bottom: 4px; }

        /* === Tables === */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 10pt;
        }
        th {
            background: #f3f4f6;
            color: #374151;
            font-weight: bold;
            text-align: left;
            padding: 8px 10px;
            border-bottom: 2px solid #06b6d4;
        }
        td {
            padding: 6px 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        tr:nth-child(even) td { background: #fafafa; }

        /* === Highlight Boxes === */
        .highlight {
            background: #f0fdfa;
            border-left: 4px solid #06b6d4;
            padding: 15px 20px;
            margin: 15px 0;
            border-radius: 0 8px 8px 0;
        }
        .highlight strong { color: #06b6d4; }

        .highlight-warn {
            background: #fff7ed;
            border-left: 4px solid #f59e0b;
            padding: 15px 20px;
            margin: 15px 0;
            border-radius: 0 8px 8px 0;
        }
        .highlight-warn strong { color: #d97706; }

        /* === Stats Grid === */
        .stats {
            text-align: center;
            margin: 20px 0;
        }
        .stats table { margin: 0 auto; }
        .stats td {
            text-align: center;
            padding: 15px 25px;
            border: none;
            background: transparent;
        }
        .stats .value { font-size: 22pt; font-weight: bold; color: #06b6d4; }
        .stats .label { font-size: 9pt; color: #6b7280; text-transform: uppercase; }

        /* === Footer === */
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            font-size: 8pt;
            color: #9ca3af;
            text-align: center;
            border-top: 1px solid #e5e7eb;
            padding: 8px 0;
        }

        /* === Page Break === */
        .page-break { page-break-before: always; }

        /* === TOC === */
        .toc { margin: 30px 0; }
        .toc-item {
            display: block;
            padding: 6px 0;
            border-bottom: 1px dotted #d1d5db;
            color: #374151;
            text-decoration: none;
        }
        .toc-item span { float: right; color: #9ca3af; }
        .toc-section { font-weight: bold; color: #06b6d4; }

        /* === Disclaimer === */
        .disclaimer { font-size: 9pt; color: #6b7280; font-style: italic; }

        /* === Comparison === */
        .comparison td.yes { color: #059669; font-weight: bold; }
        .comparison td.no { color: #dc2626; }
    </style>
</head>
<body>

    {{-- ===== COVER PAGE ===== --}}
    <div class="cover">
        @if(file_exists(public_path('logo.png')))
            <img src="{{ public_path('logo.png') }}" class="cover-logo" alt="TPIX">
        @endif
        <h1>TPIX Chain</h1>
        <h2>Whitepaper v2.0</h2>
        <p class="tagline">Zero Gas. Instant Finality. Real-World Impact.</p>
        <p class="version">Version 2.0 — March 2026</p>
        <p class="developer">Developed by Xman Studio</p>
    </div>

    {{-- ===== TABLE OF CONTENTS ===== --}}
    <h1>Table of Contents</h1>
    <div class="toc">
        <div class="toc-item toc-section">1. Executive Summary <span>3</span></div>
        <div class="toc-item toc-section">2. Problem & Solution <span>4</span></div>
        <div class="toc-item toc-section">3. TPIX Chain Architecture <span>5</span></div>
        <div class="toc-item">&nbsp;&nbsp;3.1 IBFT 2.0 Consensus</div>
        <div class="toc-item">&nbsp;&nbsp;3.2 Zero Gas Fee Model</div>
        <div class="toc-item">&nbsp;&nbsp;3.3 Comparison with Other Chains</div>
        <div class="toc-item toc-section">4. Tokenomics <span>7</span></div>
        <div class="toc-item">&nbsp;&nbsp;4.1 Token Allocation</div>
        <div class="toc-item">&nbsp;&nbsp;4.2 Emission Schedule</div>
        <div class="toc-item">&nbsp;&nbsp;4.3 Deflationary Mechanisms</div>
        <div class="toc-item toc-section">5. Master Node System <span>9</span></div>
        <div class="toc-item">&nbsp;&nbsp;5.1 Four-Tier Staking Model</div>
        <div class="toc-item">&nbsp;&nbsp;5.2 Reward Distribution</div>
        <div class="toc-item">&nbsp;&nbsp;5.3 Slashing & Penalties</div>
        <div class="toc-item toc-section">6. Validator Governance <span>11</span></div>
        <div class="toc-item toc-section">7. Living Identity — Seedless Recovery <span>12</span></div>
        <div class="toc-item toc-section">8. TPIX TRADE DEX <span>13</span></div>
        <div class="toc-item">&nbsp;&nbsp;8.1 Hybrid Order Book + AMM</div>
        <div class="toc-item">&nbsp;&nbsp;8.2 Fee Structure</div>
        <div class="toc-item">&nbsp;&nbsp;8.3 TPIXRouter Smart Contract</div>
        <div class="toc-item toc-section">9. Cross-Chain Bridge <span>15</span></div>
        <div class="toc-item toc-section">10. Token Factory <span>16</span></div>
        <div class="toc-item toc-section">11. Token Sale Details <span>17</span></div>
        <div class="toc-item toc-section">12. Real-World Applications <span>18</span></div>
        <div class="toc-item">&nbsp;&nbsp;12.1 Carbon Credit Trading</div>
        <div class="toc-item">&nbsp;&nbsp;12.2 Food Passport Traceability</div>
        <div class="toc-item toc-section">13. Products & Applications <span>20</span></div>
        <div class="toc-item toc-section">14. Roadmap <span>21</span></div>
        <div class="toc-item toc-section">15. Security & Audits <span>22</span></div>
        <div class="toc-item toc-section">16. Team & Partners <span>23</span></div>
        <div class="toc-item toc-section">17. Legal Disclaimer <span>24</span></div>
    </div>

    <div class="page-break"></div>

    {{-- ===== 1. EXECUTIVE SUMMARY ===== --}}
    <h1>1. Executive Summary</h1>
    <p>
        TPIX Chain is a high-performance EVM-compatible blockchain built on Polygon Edge technology,
        purpose-built for the Thai and Southeast Asian digital economy. With zero gas fees,
        2-second block times, IBFT 2.0 consensus providing instant finality, and a 4-tier master
        node staking system, TPIX Chain delivers enterprise-grade throughput with consumer-friendly
        accessibility.
    </p>
    <p>
        The TPIX token (7 billion fixed supply) is the native coin of the chain, powering a
        comprehensive ecosystem: master node staking with up to 20% APY, on-chain validator
        governance, the TPIX TRADE decentralized exchange with hybrid order book, a cross-chain
        bridge to BSC, a permissionless token factory, and the world's first seedless wallet
        recovery system (Living Identity).
    </p>
    <p>
        Beyond DeFi, TPIX Chain enables real-world impact through on-chain carbon credit trading
        with IoT verification and food passport traceability from farm to table — leveraging
        zero gas fees to make blockchain accessible to everyday users and small businesses.
    </p>

    <div class="stats">
        <table>
            <tr>
                <td><div class="value">7B</div><div class="label">Fixed Supply</div></td>
                <td><div class="value">0 Gas</div><div class="label">Transaction Fee</div></td>
                <td><div class="value">2s</div><div class="label">Block Time</div></td>
                <td><div class="value">1,500</div><div class="label">TPS Capacity</div></td>
                <td><div class="value">IBFT 2.0</div><div class="label">Consensus</div></td>
            </tr>
        </table>
    </div>

    {{-- ===== 2. PROBLEM & SOLUTION ===== --}}
    <h1>2. Problem & Solution</h1>
    <h2>The Problem</h2>
    <ul>
        <li><strong>Prohibitive gas fees</strong> — Ethereum ($5-50 per tx) and even BSC ($0.10-1.00) make micro-transactions impractical for everyday users and small businesses in emerging markets</li>
        <li><strong>Complex DeFi onboarding</strong> — Existing DEXes require seed phrases, gas tokens, and multiple approvals, alienating non-technical users</li>
        <li><strong>Seed phrase vulnerability</strong> — Over $100M lost annually to seed phrase theft, phishing, and loss. No recovery mechanism exists on major chains</li>
        <li><strong>Limited ASEAN infrastructure</strong> — No purpose-built blockchain for Thai/ASEAN market with native language support and local regulatory awareness</li>
        <li><strong>Real-world disconnection</strong> — Most chains optimize for DeFi speculation, not real-world use cases like supply chain, carbon credits, or food safety</li>
    </ul>

    <h2>Our Solution</h2>
    <div class="highlight">
        <p><strong>TPIX Chain: Zero Gas, Instant Finality, Real-World Impact</strong></p>
        <p>A blockchain where every transaction is free, every block is final in 2 seconds, and every user can recover their wallet without a seed phrase.</p>
    </div>
    <ul>
        <li><strong>Zero Gas Fees</strong> — Hardcoded in genesis block. No token balance needed to transact. True financial inclusion.</li>
        <li><strong>Living Identity</strong> — World's first on-chain seedless wallet recovery via security questions + GPS verification + 48-hour time-lock</li>
        <li><strong>Hybrid DEX</strong> — Internal order book matching (limit/market/stop-limit) + AMM for deep liquidity</li>
        <li><strong>4-Tier Master Node</strong> — From 10K TPIX (Light) to 10M TPIX (Validator) with proportional APY and governance rights</li>
        <li><strong>Real-World Integration</strong> — Carbon credits, food passport, and connected to 500,000+ enterprise users via ThaiPrompt platform</li>
        <li><strong>Cross-Chain Bridge</strong> — Native TPIX ↔ wTPIX (BEP-20) on BSC with trustless lock-and-mint mechanics</li>
    </ul>

    <div class="page-break"></div>

    {{-- ===== 3. TPIX CHAIN ARCHITECTURE ===== --}}
    <h1>3. TPIX Chain Architecture</h1>
    <p>
        TPIX Chain is built on Polygon Edge, an open-source modular framework for building
        Ethereum-compatible blockchain networks. It uses Istanbul Byzantine Fault Tolerant (IBFT 2.0)
        consensus, providing immediate transaction finality with no possibility of chain reorganizations.
    </p>

    <table>
        <thead><tr><th>Parameter</th><th>Value</th></tr></thead>
        <tbody>
            <tr><td>Chain Name</td><td>TPIX Chain</td></tr>
            <tr><td>Chain ID (Mainnet)</td><td>4289</td></tr>
            <tr><td>Chain ID (Testnet)</td><td>4290</td></tr>
            <tr><td>Consensus</td><td>IBFT 2.0 (Istanbul Byzantine Fault Tolerant)</td></tr>
            <tr><td>Block Time</td><td>2 seconds</td></tr>
            <tr><td>Finality</td><td>~10 seconds (5 blocks)</td></tr>
            <tr><td>TPS Capacity</td><td>~1,500 transactions/second</td></tr>
            <tr><td>Gas Price</td><td>0 (hardcoded in genesis — completely free)</td></tr>
            <tr><td>EVM Compatible</td><td>Full (Solidity, Vyper, ERC-20/721/1155)</td></tr>
            <tr><td>Validators</td><td>4 IBFT nodes (BFT tolerates 1 faulty)</td></tr>
            <tr><td>Native Token</td><td>TPIX (18 decimals)</td></tr>
            <tr><td>RPC Endpoint</td><td>https://rpc.tpix.online</td></tr>
            <tr><td>Block Explorer</td><td>https://explorer.tpix.online (Blockscout)</td></tr>
        </tbody>
    </table>

    <h2>3.1 IBFT 2.0 Consensus</h2>
    <p>
        Unlike Proof-of-Work (Ethereum Classic) or probabilistic Proof-of-Stake (Ethereum), IBFT 2.0
        provides <strong>deterministic finality</strong>: once a block is committed, it can never be reverted.
        This is critical for financial applications where transaction reversal would be catastrophic.
    </p>
    <ul>
        <li><strong>Round-robin proposer</strong> — Validators take turns proposing blocks in 2-second slots</li>
        <li><strong>Byzantine tolerance</strong> — Network survives with up to &#8970;(n-1)/3&#8971; faulty validators (1 of 4)</li>
        <li><strong>No forks possible</strong> — Consensus requires 2/3+ agreement before block inclusion</li>
        <li><strong>Instant finality</strong> — No need to wait for "confirmations" like on Ethereum</li>
    </ul>

    <h2>3.2 Zero Gas Fee Model</h2>
    <p>
        TPIX Chain's zero-gas model is fundamentally different from "low fee" chains. Gas price is
        set to 0 in the genesis block itself — not subsidized, not temporarily reduced, but structurally
        free. This enables:
    </p>
    <ul>
        <li>Users can interact with dApps without holding any TPIX for gas</li>
        <li>Micro-transactions (0.001 TPIX) are economically viable</li>
        <li>Smart contract deployments are free</li>
        <li>Token transfers, NFT minting, and DeFi operations cost nothing</li>
    </ul>
    <div class="highlight-warn">
        <p><strong>Anti-Spam Protection:</strong> Since gas is free, RPC-level rate limiting is applied per IP address to prevent transaction spam. Transaction queue prioritization ensures legitimate transactions are processed first.</p>
    </div>

    <h2>3.3 Comparison with Other Chains</h2>
    <table class="comparison">
        <thead><tr><th>Feature</th><th>TPIX Chain</th><th>Ethereum</th><th>BSC</th><th>Polygon PoS</th><th>Solana</th></tr></thead>
        <tbody>
            <tr><td>Gas Fee</td><td class="yes">Free (0)</td><td class="no">$5-50</td><td class="no">$0.10-1</td><td class="no">$0.01-0.1</td><td class="no">$0.0025</td></tr>
            <tr><td>Block Time</td><td class="yes">2 sec</td><td>12 sec</td><td>3 sec</td><td>2 sec</td><td>0.4 sec</td></tr>
            <tr><td>Finality</td><td class="yes">Instant</td><td class="no">~15 min</td><td class="no">~45 sec</td><td class="no">~2 min</td><td>~13 sec</td></tr>
            <tr><td>Fork Possible</td><td class="yes">No</td><td class="no">Yes</td><td class="no">Yes</td><td class="no">Yes</td><td class="no">Yes</td></tr>
            <tr><td>EVM Compatible</td><td class="yes">Full</td><td class="yes">Native</td><td class="yes">Full</td><td class="yes">Full</td><td class="no">No</td></tr>
            <tr><td>Wallet Recovery</td><td class="yes">Living Identity</td><td class="no">None</td><td class="no">None</td><td class="no">None</td><td class="no">None</td></tr>
            <tr><td>Carbon Credits</td><td class="yes">Built-in</td><td class="no">No</td><td class="no">No</td><td class="no">No</td><td class="no">No</td></tr>
        </tbody>
    </table>

    <div class="page-break"></div>

    {{-- ===== 4. TOKENOMICS ===== --}}
    <h1>4. Tokenomics</h1>
    <p>
        TPIX has a fixed supply of <strong>7,000,000,000</strong> (7 billion) tokens with 18 decimals,
        entirely pre-mined in the genesis block. There is no minting mechanism — the total supply is
        permanently capped at 7 billion.
    </p>

    <h2>4.1 Token Allocation</h2>
    <table>
        <thead><tr><th>Allocation</th><th>%</th><th>TPIX Amount</th><th>Purpose</th></tr></thead>
        <tbody>
            <tr><td>Master Node Rewards</td><td>20%</td><td>1,400,000,000</td><td>Staking rewards distributed over 3 years (2025-2028)</td></tr>
            <tr><td>Ecosystem Development</td><td>25%</td><td>1,750,000,000</td><td>Grants, partnerships, marketing, operations</td></tr>
            <tr><td>Community & Rewards</td><td>20%</td><td>1,400,000,000</td><td>Affiliate program, airdrops, community incentives</td></tr>
            <tr><td>Liquidity & Market Making</td><td>15%</td><td>1,050,000,000</td><td>DEX liquidity pools on TPIX Chain + BSC</td></tr>
            <tr><td>Token Sale (ICO)</td><td>10%</td><td>700,000,000</td><td>Private, Pre-Sale, Public sale phases</td></tr>
            <tr><td>Team & Advisors</td><td>10%</td><td>700,000,000</td><td>Locked with vesting schedule</td></tr>
        </tbody>
    </table>

    <h2>4.2 Emission Schedule</h2>
    <p>
        Master node rewards are distributed over a 3-year period from 2025 to 2028, with a
        decreasing emission rate to create a predictable deflationary pressure. After 2028,
        no further emission occurs — the supply is permanently fixed.
    </p>
    <table>
        <thead><tr><th>Year</th><th>Period</th><th>Emission</th><th>Per Block (~)</th><th>Share of Pool</th></tr></thead>
        <tbody>
            <tr><td>Year 1</td><td>2025-2026</td><td>600,000,000 TPIX</td><td>~38.3 TPIX</td><td>42.9%</td></tr>
            <tr><td>Year 2</td><td>2026-2027</td><td>500,000,000 TPIX</td><td>~31.9 TPIX</td><td>35.7%</td></tr>
            <tr><td>Year 3</td><td>2027-2028</td><td>300,000,000 TPIX</td><td>~19.1 TPIX</td><td>21.4%</td></tr>
        </tbody>
    </table>

    <h2>4.3 Deflationary Mechanisms</h2>
    <div class="highlight">
        <p><strong>TPIX is net-deflationary.</strong> Multiple burn mechanisms permanently remove tokens from circulation:</p>
    </div>
    <ul>
        <li><strong>Token Factory Burn</strong> — 50% of the 100 TPIX token creation fee is permanently burned (50 TPIX per token)</li>
        <li><strong>DEX Protocol Fee Burn</strong> — 0.05% of swap volume is directed to an automated buyback-and-burn contract</li>
        <li><strong>Bridge Fee Burn</strong> — 10% of cross-chain bridge fees are permanently burned</li>
    </ul>
    <p>
        As the ecosystem grows, burn volume will accelerate while emission decreases yearly,
        creating compounding deflationary pressure. After 2028, with zero emission and ongoing burns,
        the circulating supply will decrease indefinitely.
    </p>

    <div class="page-break"></div>

    {{-- ===== 5. MASTER NODE SYSTEM ===== --}}
    <h1>5. Master Node System</h1>
    <p>
        The TPIX master node system is a 4-tier staking infrastructure managed by the
        <strong>NodeRegistryV2</strong> smart contract. Each tier serves a distinct role in the
        network, from governance and block validation to data relay and network resilience.
    </p>

    <h2>5.1 Four-Tier Staking Model</h2>
    <table>
        <thead><tr><th>Tier</th><th>Stake Required</th><th>APY</th><th>Lock Period</th><th>Max Nodes</th><th>Slashing</th><th>Reward Share</th></tr></thead>
        <tbody>
            <tr>
                <td><strong>Validator</strong> (Tier 3)</td>
                <td>10,000,000 TPIX</td>
                <td>15-20%</td>
                <td>180 days</td>
                <td>21</td>
                <td>15%</td>
                <td>20%</td>
            </tr>
            <tr>
                <td><strong>Guardian</strong> (Tier 0)</td>
                <td>1,000,000 TPIX</td>
                <td>10-12%</td>
                <td>90 days</td>
                <td>100</td>
                <td>10%</td>
                <td>35%</td>
            </tr>
            <tr>
                <td><strong>Sentinel</strong> (Tier 1)</td>
                <td>100,000 TPIX</td>
                <td>7-9%</td>
                <td>30 days</td>
                <td>500</td>
                <td>5%</td>
                <td>30%</td>
            </tr>
            <tr>
                <td><strong>Light</strong> (Tier 2)</td>
                <td>10,000 TPIX</td>
                <td>4-6%</td>
                <td>7 days</td>
                <td>Unlimited</td>
                <td>0%</td>
                <td>15%</td>
            </tr>
        </tbody>
    </table>

    <h3>Tier Roles</h3>
    <ul>
        <li><strong>Validator</strong> — Real IBFT 2.0 block sealers who validate and propose blocks. They form the chain's "board of directors" with on-chain governance voting rights. Requires KYC approval.</li>
        <li><strong>Guardian</strong> — Premium master nodes providing enhanced network security and data availability. High uptime expectations with 10% slashing for misbehavior.</li>
        <li><strong>Sentinel</strong> — Standard master nodes accessible to most serious stakers. Moderate lock period with 5% slashing.</li>
        <li><strong>Light</strong> — Entry-level nodes for data relay and network resilience. Minimal stake, no slashing, unlimited nodes — designed for broad participation.</li>
    </ul>

    <h2>5.2 Reward Distribution</h2>
    <p>
        Rewards are distributed proportionally based on tier allocation, uptime score, and the number
        of active nodes in each tier. The formula:
    </p>
    <div class="highlight">
        <p><strong>Pending Reward = (tierRewardPerSecond &times; elapsedTime) / activeNodesInTier &times; uptimeScore / 10,000</strong></p>
        <p>Capped at 30 days maximum to prevent stale accumulation gaming.</p>
    </div>

    <h2>5.3 Slashing & Penalties</h2>
    <p>Nodes that misbehave (downtime, double-signing, invalid blocks) face proportional penalties:</p>
    <ul>
        <li>Validators: 15% of staked amount burned</li>
        <li>Guardians: 10% of staked amount burned</li>
        <li>Sentinels: 5% of staked amount burned</li>
        <li>Light nodes: No slashing (entry-level protection)</li>
    </ul>
    <p>Slashed nodes can withdraw their remaining stake but cannot re-register until penalties clear.</p>

    <div class="page-break"></div>

    {{-- ===== 6. VALIDATOR GOVERNANCE ===== --}}
    <h1>6. Validator Governance</h1>
    <p>
        The <strong>ValidatorGovernance</strong> smart contract enables on-chain governance exclusively
        for Validator-tier nodes (10M TPIX stake + KYC-approved). Validators act as the chain's
        decision-making body for protocol upgrades, parameter changes, and membership.
    </p>

    <h2>Proposal Types</h2>
    <table>
        <thead><tr><th>Type</th><th>Description</th><th>Example</th></tr></thead>
        <tbody>
            <tr><td>AddValidator</td><td>Admit new IBFT 2.0 validator</td><td>New node operator applies, existing validators vote to include</td></tr>
            <tr><td>RemoveValidator</td><td>Remove misbehaving validator</td><td>Validator with persistent downtime removed by peer vote</td></tr>
            <tr><td>ChangeParameter</td><td>Adjust protocol parameters</td><td>Modify tier staking requirements, emission rates, fee caps</td></tr>
            <tr><td>UpgradeContract</td><td>Deploy new contract version</td><td>Upgrade NodeRegistryV2 or TPIXRouter</td></tr>
            <tr><td>General</td><td>Free-form governance</td><td>Strategic decisions, partnership approvals, treasury allocations</td></tr>
        </tbody>
    </table>

    <h2>Voting Rules</h2>
    <ul>
        <li><strong>Voting Period:</strong> 7 days from proposal creation</li>
        <li><strong>Quorum:</strong> &gt;50% of active validators must vote</li>
        <li><strong>Approval:</strong> &gt;50% of votes must be "for"</li>
        <li><strong>Timelock:</strong> 48-hour delay after passing before execution</li>
        <li><strong>Execution:</strong> Admin-triggered after timelock (supports on-chain or off-chain actions)</li>
    </ul>

    <h2>Validator KYC</h2>
    <p>
        The <strong>ValidatorKYC</strong> contract implements PDPA-compliant identity verification.
        Zero PII is stored on-chain — only a keccak256 hash of the KYC data is recorded.
        Encrypted documents are stored off-chain with access logging and right-to-erasure support.
    </p>

    <div class="page-break"></div>

    {{-- ===== 7. LIVING IDENTITY ===== --}}
    <h1>7. Living Identity — Seedless Wallet Recovery</h1>
    <div class="highlight">
        <p><strong>World's First On-Chain Seedless Wallet Recovery System</strong></p>
        <p>No more seed phrases. No more lost funds. Your identity is your key.</p>
    </div>

    <h2>The Innovation</h2>
    <p>
        Living Identity (TPIXIdentity smart contract) allows users to recover wallet access without
        seed phrases by combining three verification factors into a single on-chain proof:
    </p>
    <ul>
        <li><strong>Knowledge factor</strong> — Answers to personal security questions chosen by the user</li>
        <li><strong>Location factor</strong> — GPS coordinates of locations meaningful to the user</li>
        <li><strong>Possession factor</strong> — A 6-digit recovery PIN</li>
    </ul>

    <h2>How It Works</h2>
    <table>
        <thead><tr><th>Step</th><th>Action</th><th>On-Chain Data</th></tr></thead>
        <tbody>
            <tr><td>1. Register</td><td>User sets security questions + GPS locations + recovery PIN</td><td>Only 32-byte keccak256 hash stored</td></tr>
            <tr><td>2. Loss Event</td><td>User loses device or seed phrase</td><td>No action needed</td></tr>
            <tr><td>3. Recovery Request</td><td>User answers questions + stands at GPS location + enters PIN</td><td>Proof submitted, 48-hour timelock starts</td></tr>
            <tr><td>4. Safety Window</td><td>Original owner can cancel within 48 hours (theft protection)</td><td>Cancel transaction reverts recovery</td></tr>
            <tr><td>5. Execute</td><td>After 48 hours, wallet control transfers to new address</td><td>Ownership updated on-chain</td></tr>
        </tbody>
    </table>

    <h2>Security Properties</h2>
    <ul>
        <li><strong>Zero knowledge on-chain</strong> — Only a 32-byte hash, no personal data retrievable from blockchain</li>
        <li><strong>48-hour timelock</strong> — Prevents immediate theft even if all factors are compromised</li>
        <li><strong>Multi-factor</strong> — Requires knowledge + location + PIN simultaneously</li>
        <li><strong>Free to use</strong> — Zero gas fees make registration and recovery cost nothing</li>
        <li><strong>Updateable</strong> — Users can change their security factors at any time</li>
    </ul>

    <div class="page-break"></div>

    {{-- ===== 8. TPIX TRADE DEX ===== --}}
    <h1>8. TPIX TRADE DEX</h1>
    <p>
        TPIX TRADE is a decentralized exchange combining an internal order book matching engine
        with Uniswap V2-compatible AMM pools, providing both precision price execution and deep
        liquidity in a single platform.
    </p>

    <h2>8.1 Hybrid Order Book + AMM</h2>
    <table>
        <thead><tr><th>Feature</th><th>Order Book (Internal)</th><th>AMM (Uniswap V2)</th></tr></thead>
        <tbody>
            <tr><td>Order Types</td><td>Limit, Market, Stop-Limit</td><td>Market only</td></tr>
            <tr><td>Price Discovery</td><td>Price-time priority matching</td><td>Constant product (x &times; y = k)</td></tr>
            <tr><td>Slippage</td><td>Zero (limit orders)</td><td>Variable based on pool depth</td></tr>
            <tr><td>Best For</td><td>Precise entries, large orders</td><td>Quick swaps, small amounts</td></tr>
            <tr><td>Settlement</td><td>Internal DB + on-chain confirmation</td><td>Fully on-chain</td></tr>
        </tbody>
    </table>

    <h3>Order Matching Engine</h3>
    <p>
        The matching engine uses price-time priority: best price first, then oldest order.
        Features include partial fills, self-trade prevention, maker/taker fee separation,
        and real-time kline (candlestick) aggregation for charting.
    </p>

    <h2>8.2 Fee Structure</h2>
    <table>
        <thead><tr><th>Fee Type</th><th>Rate</th><th>Distribution</th></tr></thead>
        <tbody>
            <tr><td>Swap Fee (AMM)</td><td>0.3%</td><td>0.25% to LPs, 0.05% to Protocol Treasury</td></tr>
            <tr><td>Maker Fee (Order Book)</td><td>0.1%</td><td>Collected by platform</td></tr>
            <tr><td>Taker Fee (Order Book)</td><td>0.2%</td><td>Collected by platform</td></tr>
            <tr><td>Bridge Fee</td><td>0.1%</td><td>90% to Treasury, 10% burned</td></tr>
        </tbody>
    </table>
    <p>
        Fee rates are configurable per chain, per trading pair, and globally — with a hierarchical
        override system (pair-specific > chain-specific > global default). Maximum fee cap of 5%
        enforced by smart contract to protect users.
    </p>

    <h2>8.3 TPIXRouter Smart Contract</h2>
    <p>
        The TPIXRouter is a fee-collection wrapper around any Uniswap V2-compatible router.
        It deducts platform fees from the input amount before forwarding the swap to the
        underlying DEX, with fees sent directly to the fee collector wallet.
    </p>
    <ul>
        <li><strong>Basis-point precision</strong> — Fees in basis points (1 bp = 0.01%)</li>
        <li><strong>Max fee cap</strong> — 500 bp (5%) hardcoded in contract</li>
        <li><strong>ReentrancyGuard</strong> — Protection against reentrancy attacks</li>
        <li><strong>Pausable</strong> — Emergency circuit breaker for admin</li>
        <li><strong>SafeERC20</strong> — Handles non-standard token implementations</li>
    </ul>

    <div class="page-break"></div>

    {{-- ===== 9. CROSS-CHAIN BRIDGE ===== --}}
    <h1>9. Cross-Chain Bridge</h1>
    <p>
        The TPIX Bridge enables seamless asset transfer between TPIX Chain (native TPIX) and
        BNB Smart Chain (wTPIX, a BEP-20 token). This allows TPIX holders to access BSC's
        DeFi ecosystem while maintaining the ability to bridge back to TPIX Chain.
    </p>

    <h2>Bridge Mechanics</h2>
    <table>
        <thead><tr><th>Direction</th><th>Action</th><th>Result</th></tr></thead>
        <tbody>
            <tr><td>BSC → TPIX Chain</td><td>Burn wTPIX on BSC</td><td>Mint native TPIX on TPIX Chain</td></tr>
            <tr><td>TPIX Chain → BSC</td><td>Lock native TPIX on TPIX Chain</td><td>Mint wTPIX on BSC</td></tr>
        </tbody>
    </table>

    <h2>wTPIX (Wrapped TPIX) — BEP-20</h2>
    <ul>
        <li><strong>Max Supply Cap:</strong> 700,000,000 wTPIX (10% of total TPIX supply)</li>
        <li><strong>Standard:</strong> ERC-20 + Burnable on BNB Smart Chain</li>
        <li><strong>Minter Roles:</strong> TokenSale contract and Bridge contract only</li>
        <li><strong>Bridge Fee:</strong> 0.1% (90% to treasury, 10% permanently burned)</li>
    </ul>

    <div class="highlight">
        <p><strong>Supply Integrity:</strong> The total of native TPIX + wTPIX always equals 7 billion. When wTPIX is minted on BSC, the equivalent native TPIX is locked on TPIX Chain, and vice versa.</p>
    </div>

    {{-- ===== 10. TOKEN FACTORY ===== --}}
    <h1>10. Token Factory</h1>
    <p>
        The TPIX Token Factory allows anyone to create custom ERC-20 tokens on TPIX Chain
        through a simple web interface — no coding required.
    </p>

    <table>
        <thead><tr><th>Feature</th><th>Details</th></tr></thead>
        <tbody>
            <tr><td>Creation Fee</td><td>100 TPIX (50% burned, 50% to treasury)</td></tr>
            <tr><td>Token Types</td><td>Standard, Mintable, Burnable, Mintable+Burnable</td></tr>
            <tr><td>Supply Range</td><td>1 to 1,000,000,000,000 tokens</td></tr>
            <tr><td>Features</td><td>ERC-20 compliant, Permit (EIP-2612), optional Freeze</td></tr>
            <tr><td>Verification</td><td>Auto-verified on Block Explorer</td></tr>
            <tr><td>Gas Cost</td><td>Free (zero gas on TPIX Chain)</td></tr>
        </tbody>
    </table>

    <h2>Use Cases</h2>
    <ul>
        <li><strong>Business Loyalty Points</strong> — Restaurants, shops, and brands create their own reward tokens</li>
        <li><strong>Community/DAO Tokens</strong> — Governance tokens for decentralized organizations</li>
        <li><strong>Real-World Assets (RWA)</strong> — Tokenized property, equity, or commodities</li>
        <li><strong>Carbon Credits</strong> — Tokenized emission reductions (see Section 12)</li>
        <li><strong>NFT Project Tokens</strong> — Utility tokens for NFT communities</li>
    </ul>

    <div class="page-break"></div>

    {{-- ===== 11. TOKEN SALE DETAILS ===== --}}
    <h1>11. Token Sale Details</h1>
    <p>
        The TPIX token sale is conducted in 3 phases on BNB Smart Chain, accepting BNB, USDT,
        BUSD, and USDC. Purchased tokens are allocated as wTPIX (BEP-20) with a vesting schedule,
        bridgeable to native TPIX once the cross-chain bridge is operational.
    </p>

    <table>
        <thead><tr><th>Phase</th><th>Price (USD)</th><th>Allocation</th><th>TGE Unlock</th><th>Vesting</th></tr></thead>
        <tbody>
            <tr><td>Private Sale</td><td>$0.05</td><td>100,000,000 TPIX</td><td>10%</td><td>30-day cliff, 180-day linear</td></tr>
            <tr><td>Pre-Sale</td><td>$0.08</td><td>200,000,000 TPIX</td><td>15%</td><td>14-day cliff, 120-day linear</td></tr>
            <tr><td>Public Sale</td><td>$0.10</td><td>400,000,000 TPIX</td><td>25%</td><td>No cliff, 90-day linear</td></tr>
        </tbody>
    </table>

    <h2>Token Sale Smart Contract (TPIXTokenSale.sol)</h2>
    <p>
        Deployed on BSC, the token sale contract receives BNB directly (fallback function)
        or ERC-20 tokens via <code>purchaseWithToken()</code>. Funds are forwarded immediately
        to the treasury wallet. Token allocation is recorded off-chain and verified by the
        backend before wTPIX distribution.
    </p>

    <div class="page-break"></div>

    {{-- ===== 12. REAL-WORLD APPLICATIONS ===== --}}
    <h1>12. Real-World Applications</h1>

    <h2>12.1 Carbon Credit Trading</h2>
    <p>
        TPIX Chain provides transparent, verified carbon credit trading with IoT sensor integration.
        Zero gas fees make fractional trading (minimum 0.001 tCO&#x2082;e) economically viable for
        individuals and small businesses — not just corporations.
    </p>

    <h3>Supported Standards</h3>
    <ul>
        <li><strong>VCS</strong> (Verified Carbon Standard) — Largest voluntary carbon market</li>
        <li><strong>Gold Standard</strong> — Premium market with UN Sustainable Development Goal co-benefits</li>
        <li><strong>CDM</strong> (Clean Development Mechanism) — UN/UNFCCC developing nation projects</li>
        <li><strong>ACR</strong> (American Carbon Registry) — North American compliance market</li>
    </ul>

    <h3>Blockchain Advantages</h3>
    <table>
        <thead><tr><th>Feature</th><th>Traditional</th><th>TPIX Chain</th></tr></thead>
        <tbody>
            <tr><td>Double-counting</td><td>Possible (registry errors)</td><td>Impossible (unique on-chain token)</td></tr>
            <tr><td>Verification</td><td>Months, expensive audits</td><td>Real-time IoT data on-chain</td></tr>
            <tr><td>Trading Fee</td><td>5-15% (brokers)</td><td>2% (P2P marketplace)</td></tr>
            <tr><td>Minimum Trade</td><td>1 tCO&#x2082;e ($10-50)</td><td>0.001 tCO&#x2082;e (fractional)</td></tr>
            <tr><td>Settlement</td><td>Days to weeks</td><td>2 seconds</td></tr>
            <tr><td>Retirement Proof</td><td>PDF certificate</td><td>Immutable on-chain NFT</td></tr>
        </tbody>
    </table>

    <h3>Project Types</h3>
    <ul>
        <li>Renewable energy (solar, wind, biomass)</li>
        <li>Reforestation & afforestation</li>
        <li>Energy efficiency improvements</li>
        <li>Methane capture from landfills & agriculture</li>
        <li>Clean cookstoves for developing communities</li>
        <li>Blue carbon (mangroves, seagrass protection)</li>
    </ul>

    <h2>12.2 Food Passport Traceability</h2>
    <p>
        The Food Passport system tracks food products from farm to consumer using blockchain-verified
        records and IoT sensors at each stage of the supply chain. Every product receives a unique
        on-chain identity that consumers can verify by scanning a QR code.
    </p>
    <ul>
        <li><strong>Farm origin</strong> — GPS-verified farm location, crop type, harvest date</li>
        <li><strong>Processing</strong> — Temperature, handling, quality certification</li>
        <li><strong>Transport</strong> — Cold chain monitoring, route tracking</li>
        <li><strong>Retail</strong> — Shelf date, storage conditions, expiry verification</li>
    </ul>
    <p>
        All data is immutable on TPIX Chain. Zero gas fees mean even small farmers can participate
        without cost barriers — a critical factor for adoption in Southeast Asian agricultural markets.
    </p>

    <div class="page-break"></div>

    {{-- ===== 13. PRODUCTS & APPLICATIONS ===== --}}
    <h1>13. Products & Applications</h1>
    <table>
        <thead><tr><th>Product</th><th>Platform</th><th>Key Features</th></tr></thead>
        <tbody>
            <tr>
                <td><strong>TPIX Wallet</strong></td>
                <td>Flutter (iOS/Android)</td>
                <td>HD wallet (BIP-39/44), Living Identity recovery, QR scanner, AES-256 encryption, PIN + 6-digit recovery, bilingual (Thai/English), up to 128 wallets</td>
            </tr>
            <tr>
                <td><strong>Master Node UI</strong></td>
                <td>Electron (Windows)</td>
                <td>One-click node setup, multi-node management, real-time dashboard with SQLite, Leaflet map showing network, reward tracking every 60s, auto-update via GitHub</td>
            </tr>
            <tr>
                <td><strong>TPIX TRADE</strong></td>
                <td>Web (Laravel 11 + Vue 3)</td>
                <td>Hybrid order book + AMM, limit/market/stop-limit orders, real-time charts, admin panel with fee management, mobile app (React Native)</td>
            </tr>
            <tr>
                <td><strong>Block Explorer</strong></td>
                <td>Blockscout</td>
                <td>Transaction viewer, contract verification, token tracking, API access</td>
            </tr>
            <tr>
                <td><strong>Token Factory</strong></td>
                <td>Web</td>
                <td>No-code ERC-20 creation, 100 TPIX fee, auto-verified, immediately tradeable</td>
            </tr>
            <tr>
                <td><strong>Carbon Credit System</strong></td>
                <td>Web + IoT</td>
                <td>VCS/Gold Standard support, IoT verification, P2P marketplace, NFT retirement certificates</td>
            </tr>
            <tr>
                <td><strong>Food Passport</strong></td>
                <td>Web + IoT</td>
                <td>Farm-to-table traceability, QR verification, cold chain monitoring</td>
            </tr>
        </tbody>
    </table>

    <div class="page-break"></div>

    {{-- ===== 14. ROADMAP ===== --}}
    <h1>14. Roadmap</h1>

    <h2>Q1 2026 — Foundation</h2>
    <ul>
        <li>TPIX Chain mainnet launch (4 IBFT 2.0 validators)</li>
        <li>Blockscout block explorer deployment</li>
        <li>Token Sale — 3 phases (Private, Pre-Sale, Public) on BSC</li>
        <li>Whitepaper v2.0 publication</li>
        <li>TPIX TRADE DEX platform launch with internal order book</li>
        <li>Master Node UI v1.0 release (Windows)</li>
        <li>Living Identity smart contract deployment</li>
    </ul>

    <h2>Q2 2026 — DeFi Infrastructure</h2>
    <ul>
        <li>BSC Bridge launch (wTPIX ↔ native TPIX)</li>
        <li>TPIX DEX AMM pools deployment (Uniswap V2 fork)</li>
        <li>4-Tier master node staking activation</li>
        <li>Validator Governance smart contract deployment</li>
        <li>TPIX Wallet mobile app release (iOS/Android)</li>
        <li>TPIXRouter fee collection activation</li>
    </ul>

    <h2>Q3 2026 — Ecosystem Growth</h2>
    <ul>
        <li>Token Factory launch — permissionless ERC-20 creation</li>
        <li>Affiliate/Referral program activation</li>
        <li>Carbon Credit trading system pilot</li>
        <li>Food Passport traceability pilot</li>
        <li>CEX listing applications</li>
        <li>Validator KYC onboarding (first external validators)</li>
    </ul>

    <h2>Q4 2026 — Scale & Governance</h2>
    <ul>
        <li>DAO governance transition planning</li>
        <li>Multi-chain bridge expansion (Ethereum, Polygon)</li>
        <li>Enterprise partnership program</li>
        <li>Validator set decentralization (21 validators target)</li>
        <li>NFT marketplace launch</li>
        <li>Master Node UI — macOS/Linux support</li>
    </ul>

    <h2>2027 — Global Expansion</h2>
    <ul>
        <li>Full DAO governance activation</li>
        <li>Multi-language support (Japanese, Korean, Vietnamese)</li>
        <li>Carbon credit exchange full launch</li>
        <li>Food Passport government partnership pilots</li>
        <li>Year 2 emission reduction (500M TPIX/year)</li>
    </ul>

    <div class="page-break"></div>

    {{-- ===== 15. SECURITY & AUDITS ===== --}}
    <h1>15. Security & Audits</h1>
    <ul>
        <li><strong>Smart Contract Audits</strong> — All contracts undergo third-party security audits before mainnet deployment. Focus areas: reentrancy, integer overflow, access control, and economic exploits.</li>
        <li><strong>IBFT 2.0 Consensus</strong> — Byzantine fault tolerance ensures network security with up to 1 faulty validator out of 4. No chain reorganizations possible.</li>
        <li><strong>Rate Limiting</strong> — RPC-level per-IP rate limiting prevents spam on the gasless chain. Transaction queue prioritization for legitimate traffic.</li>
        <li><strong>Multi-sig Treasury</strong> — Protocol funds managed by multi-signature wallets (3-of-5 threshold).</li>
        <li><strong>AES-256 Encryption</strong> — All wallet data encrypted with AES-256-GCM. PBKDF2 key derivation. Private keys auto-clear from memory after 60 seconds.</li>
        <li><strong>ReentrancyGuard</strong> — All value-transfer functions in DEX and bridge contracts protected with OpenZeppelin ReentrancyGuard.</li>
        <li><strong>Pausable Contracts</strong> — Emergency circuit breaker on all critical contracts (DEX router, bridge, token sale).</li>
        <li><strong>ValidatorKYC</strong> — PDPA-compliant KYC for validators. Zero PII on-chain. Encrypted off-chain storage with access logging.</li>
        <li><strong>Bug Bounty</strong> — Ongoing program for responsible vulnerability disclosure.</li>
        <li><strong>24/7 Monitoring</strong> — Chain health monitoring, alerting system, and automatic incident response.</li>
    </ul>

    {{-- ===== 16. TEAM & PARTNERS ===== --}}
    <h1>16. Team & Partners</h1>
    <p>
        TPIX Chain is developed by <strong>Xman Studio</strong>, a blockchain development team
        specializing in DeFi protocols and Web3 applications for the Southeast Asian market.
    </p>
    <p>
        The team brings extensive experience in:
    </p>
    <ul>
        <li>Solidity smart contract development and security auditing</li>
        <li>EVM-based chain deployment (Polygon Edge, Geth, Besu)</li>
        <li>Full-stack Web3 application development (Laravel, Vue, React Native, Flutter)</li>
        <li>Decentralized protocol design and tokenomics modeling</li>
        <li>Real-world blockchain integration (IoT, supply chain, carbon markets)</li>
    </ul>
    <p>
        TPIX Chain is connected to the <strong>ThaiPrompt</strong> ecosystem with 500,000+ registered
        enterprise users, providing a built-in user base for adoption.
    </p>

    <div class="page-break"></div>

    {{-- ===== 17. LEGAL DISCLAIMER ===== --}}
    <h1>17. Legal Disclaimer</h1>
    <p class="disclaimer">
        This whitepaper is for informational purposes only and does not constitute investment advice,
        financial advice, trading advice, or any other sort of advice. You should not treat any of the
        whitepaper's content as such. TPIX tokens are utility tokens designed for use within the
        TPIX Chain ecosystem and are not intended to be securities in any jurisdiction.
    </p>
    <p class="disclaimer">
        The purchase of TPIX tokens involves significant risk, including but not limited to the risk
        of losing all of the purchase amount. Potential purchasers should carefully evaluate all risks
        and uncertainties associated with TPIX tokens and the TPIX Chain platform before making a purchase.
    </p>
    <p class="disclaimer">
        The information in this whitepaper may be updated, modified, or revised at any time without
        prior notice. The team reserves the right to make changes to this document as the project evolves.
        Nothing in this whitepaper shall be deemed to constitute a prospectus of any sort or a solicitation
        for investment, nor does it in any way pertain to an offering or a solicitation of an offer to buy
        any securities in any jurisdiction.
    </p>
    <p class="disclaimer">
        All forward-looking statements, including the roadmap, are subject to change based on market
        conditions, regulatory developments, and technical feasibility. Past performance of blockchain
        technologies does not guarantee future results.
    </p>

    <br><br>
    <div style="text-align: center; color: #9ca3af; font-size: 10pt;">
        <p>&copy; 2026 TPIX Chain — Xman Studio. All rights reserved.</p>
        <p>https://tpix.online &nbsp;|&nbsp; https://xmanstudio.com</p>
        <p style="margin-top: 10px; font-size: 9pt;">
            GitHub: https://github.com/xjanova/TPIX-Coin<br>
            Block Explorer: https://explorer.tpix.online<br>
            RPC: https://rpc.tpix.online
        </p>
    </div>

</body>
</html>
