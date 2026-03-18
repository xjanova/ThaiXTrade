<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>TPIX Chain Whitepaper v1.0</title>
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
            padding-top: 200px;
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

        /* === Stats Grid === */
        .stats {
            text-align: center;
            margin: 20px 0;
        }
        .stats table { margin: 0 auto; }
        .stats td {
            text-align: center;
            padding: 15px 30px;
            border: none;
            background: transparent;
        }
        .stats .value { font-size: 24pt; font-weight: bold; color: #06b6d4; }
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

        /* === Disclaimer === */
        .disclaimer { font-size: 9pt; color: #6b7280; font-style: italic; }
    </style>
</head>
<body>

    {{-- ===== COVER PAGE ===== --}}
    <div class="cover">
        @if(file_exists(public_path('logo.png')))
            <img src="{{ public_path('logo.png') }}" class="cover-logo" alt="TPIX">
        @endif
        <h1>TPIX Chain</h1>
        <h2>Whitepaper</h2>
        <p class="version">Version 1.0 — March 2026</p>
        <p class="developer">Developed by Xman Studio</p>
    </div>

    {{-- ===== TABLE OF CONTENTS ===== --}}
    <h1>Table of Contents</h1>
    <div class="toc">
        <div class="toc-item">1. Executive Summary <span>3</span></div>
        <div class="toc-item">2. Problem & Solution <span>4</span></div>
        <div class="toc-item">3. TPIX Chain Architecture <span>5</span></div>
        <div class="toc-item">4. Tokenomics <span>6</span></div>
        <div class="toc-item">5. DEX Protocol <span>8</span></div>
        <div class="toc-item">6. Token Sale Details <span>9</span></div>
        <div class="toc-item">7. Staking & Rewards <span>10</span></div>
        <div class="toc-item">8. Ecosystem & Affiliate <span>11</span></div>
        <div class="toc-item">9. Roadmap <span>12</span></div>
        <div class="toc-item">10. Team & Partners <span>13</span></div>
        <div class="toc-item">11. Security & Audits <span>14</span></div>
        <div class="toc-item">12. Legal Disclaimer <span>15</span></div>
    </div>

    <div class="page-break"></div>

    {{-- ===== 1. EXECUTIVE SUMMARY ===== --}}
    <h1>1. Executive Summary</h1>
    <p>
        TPIX Chain is a next-generation EVM-compatible blockchain built on Polygon Edge technology,
        designed for the Thai and Southeast Asian markets. With zero gas fees, 2-second block times,
        and IBFT Proof-of-Authority consensus, TPIX Chain provides a fast, free, and scalable
        platform for decentralized applications.
    </p>
    <p>
        The TPIX token (7 billion total supply) serves as the native coin of the chain, powering
        staking rewards, governance, the built-in DEX, token factory, cross-chain bridge, and
        an affiliate referral program.
    </p>

    <div class="stats">
        <table>
            <tr>
                <td><div class="value">7B</div><div class="label">Total Supply</div></td>
                <td><div class="value">0 Gas</div><div class="label">Transaction Fee</div></td>
                <td><div class="value">2s</div><div class="label">Block Time</div></td>
                <td><div class="value">IBFT</div><div class="label">Consensus</div></td>
            </tr>
        </table>
    </div>

    {{-- ===== 2. PROBLEM & SOLUTION ===== --}}
    <h1>2. Problem & Solution</h1>
    <h2>The Problem</h2>
    <ul>
        <li>High gas fees on Ethereum and BSC make micro-transactions impractical</li>
        <li>Existing DEXes are complex and intimidating for new users</li>
        <li>Limited blockchain infrastructure for Southeast Asian markets</li>
        <li>No localized DeFi ecosystem with Thai language support</li>
    </ul>

    <h2>Our Solution</h2>
    <div class="highlight">
        <p><strong>Zero Gas Fees</strong> — All transactions on TPIX Chain are completely free, enabling true micro-transactions and broad accessibility.</p>
    </div>
    <ul>
        <li><strong>User-Friendly DEX</strong> — Intuitive trading interface with AI-powered insights</li>
        <li><strong>Local Focus</strong> — Built for Thai and ASEAN users with localized support</li>
        <li><strong>Cross-Chain Bridge</strong> — Seamless asset transfer between TPIX Chain and BSC</li>
    </ul>

    <div class="page-break"></div>

    {{-- ===== 3. TPIX CHAIN ARCHITECTURE ===== --}}
    <h1>3. TPIX Chain Architecture</h1>
    <p>
        TPIX Chain is built on Polygon Edge, an open-source framework for building Ethereum-compatible
        blockchain networks. It uses Istanbul Byzantine Fault Tolerant (IBFT) consensus with
        4 validator nodes, providing immediate transaction finality.
    </p>

    <table>
        <thead><tr><th>Parameter</th><th>Value</th></tr></thead>
        <tbody>
            <tr><td>Chain Name</td><td>TPIX Chain</td></tr>
            <tr><td>Chain ID (Mainnet)</td><td>4289</td></tr>
            <tr><td>Chain ID (Testnet)</td><td>4290</td></tr>
            <tr><td>Consensus</td><td>IBFT (Proof of Authority)</td></tr>
            <tr><td>Block Time</td><td>2 seconds</td></tr>
            <tr><td>Gas Price</td><td>0 (Free transactions)</td></tr>
            <tr><td>EVM Compatible</td><td>Yes (Solidity / Vyper)</td></tr>
            <tr><td>Validators</td><td>4 nodes (expandable)</td></tr>
            <tr><td>Native Token</td><td>TPIX (18 decimals)</td></tr>
        </tbody>
    </table>

    <div class="page-break"></div>

    {{-- ===== 4. TOKENOMICS ===== --}}
    <h1>4. Tokenomics</h1>
    <p>
        TPIX has a fixed supply of 7,000,000,000 (7 billion) tokens with 18 decimals.
        There is no inflation or minting mechanism — the total supply is pre-mined in the genesis block.
    </p>

    <h2>Token Allocation</h2>
    <table>
        <thead><tr><th>Allocation</th><th>%</th><th>TPIX Amount</th><th>Vesting</th></tr></thead>
        <tbody>
            <tr><td>Public Sale (ICO)</td><td>10%</td><td>700,000,000</td><td>10-25% TGE, 6-12 month vesting</td></tr>
            <tr><td>Liquidity Pool</td><td>30%</td><td>2,100,000,000</td><td>Locked in DEX pools</td></tr>
            <tr><td>Staking Rewards</td><td>20%</td><td>1,400,000,000</td><td>Distributed over 5 years</td></tr>
            <tr><td>Team & Advisors</td><td>20%</td><td>1,400,000,000</td><td>1-year cliff, 3-year linear</td></tr>
            <tr><td>Ecosystem Fund</td><td>10%</td><td>700,000,000</td><td>DAO governed</td></tr>
            <tr><td>Development</td><td>10%</td><td>700,000,000</td><td>2-year linear</td></tr>
        </tbody>
    </table>

    <div class="page-break"></div>

    {{-- ===== 5. DEX PROTOCOL ===== --}}
    <h1>5. DEX Protocol</h1>
    <p>
        TPIX DEX is a Uniswap V2 fork deployed natively on TPIX Chain. It provides automated
        market making (AMM) with constant product formula (x &times; y = k) and a 0.3% swap fee
        (0.25% to liquidity providers, 0.05% to protocol treasury).
    </p>

    <h2>Key Smart Contracts</h2>
    <table>
        <thead><tr><th>Contract</th><th>Description</th></tr></thead>
        <tbody>
            <tr><td><strong>TPIXDEXFactory</strong></td><td>Creates and manages trading pair contracts</td></tr>
            <tr><td><strong>TPIXDEXRouter02</strong></td><td>Handles multi-hop swaps and liquidity operations</td></tr>
            <tr><td><strong>TPIXDEXPair</strong></td><td>Individual liquidity pool with ERC-20 LP tokens</td></tr>
            <tr><td><strong>WTPIX</strong></td><td>Wrapped TPIX for ERC-20 compatibility within DEX</td></tr>
        </tbody>
    </table>

    <h2>Fee Structure</h2>
    <div class="highlight">
        <p><strong>Total Swap Fee: 0.3%</strong></p>
        <ul>
            <li>0.25% to Liquidity Providers (LP reward)</li>
            <li>0.05% to Protocol Treasury (development & operations)</li>
        </ul>
    </div>

    {{-- ===== 6. TOKEN SALE DETAILS ===== --}}
    <h1>6. Token Sale Details</h1>
    <p>
        The TPIX token sale is conducted in 3 phases, accepting BNB and USDT on BNB Smart Chain (BSC).
        Purchased tokens are allocated with a vesting schedule and can be claimed as wTPIX (BEP-20)
        immediately or native TPIX once the cross-chain bridge is operational.
    </p>

    <table>
        <thead><tr><th>Phase</th><th>Price (USD)</th><th>Allocation</th><th>TGE Unlock</th><th>Vesting</th></tr></thead>
        <tbody>
            <tr><td>Private Sale</td><td>$0.05</td><td>100,000,000</td><td>10%</td><td>30-day cliff, 180-day linear</td></tr>
            <tr><td>Pre-Sale</td><td>$0.08</td><td>200,000,000</td><td>15%</td><td>14-day cliff, 120-day linear</td></tr>
            <tr><td>Public Sale</td><td>$0.10</td><td>400,000,000</td><td>25%</td><td>No cliff, 90-day linear</td></tr>
        </tbody>
    </table>

    <div class="page-break"></div>

    {{-- ===== 7. STAKING & REWARDS ===== --}}
    <h1>7. Staking & Rewards</h1>
    <p>
        TPIX holders can stake their tokens to earn rewards. APY varies based on the lock period,
        with longer locks offering higher returns. Staking rewards are sourced from the 20% allocation
        (1.4 billion TPIX) distributed over a period of 5 years.
    </p>

    <table>
        <thead><tr><th>Lock Period</th><th>APY</th><th>Unlock Condition</th></tr></thead>
        <tbody>
            <tr><td>Flexible (No Lock)</td><td>5%</td><td>Withdraw anytime</td></tr>
            <tr><td>30 Days</td><td>25%</td><td>After lock period ends</td></tr>
            <tr><td>90 Days</td><td>60%</td><td>After lock period ends</td></tr>
            <tr><td>180 Days</td><td>100%</td><td>After lock period ends</td></tr>
            <tr><td>365 Days</td><td>200%</td><td>After lock period ends</td></tr>
        </tbody>
    </table>

    {{-- ===== 8. ECOSYSTEM & AFFILIATE ===== --}}
    <h1>8. Ecosystem & Affiliate</h1>
    <h2>Affiliate Referral Program</h2>
    <table>
        <thead><tr><th>Reward Type</th><th>Rate</th><th>Details</th></tr></thead>
        <tbody>
            <tr><td>Referrer Reward</td><td>5%</td><td>Of referee's first purchase amount</td></tr>
            <tr><td>Referee Bonus</td><td>2%</td><td>Extra tokens on first purchase</td></tr>
            <tr><td>Max per Referral</td><td>1,000 TPIX</td><td>Cap per individual referral</td></tr>
        </tbody>
    </table>

    <h2>Token Factory</h2>
    <p>
        The TPIX Token Factory allows anyone to create custom ERC-20 tokens on TPIX Chain
        for a fee of 100 TPIX per token. Created tokens benefit from zero gas fees for all
        subsequent transactions.
    </p>

    <div class="page-break"></div>

    {{-- ===== 9. ROADMAP ===== --}}
    <h1>9. Roadmap</h1>

    <h2>Q1 2026 — Foundation</h2>
    <ul>
        <li>TPIX Chain mainnet launch (4 IBFT validators)</li>
        <li>Blockscout block explorer deployment</li>
        <li>Token Sale (3 phases: Private, Pre-Sale, Public)</li>
        <li>Whitepaper publication</li>
        <li>TPIX TRADE platform launch</li>
    </ul>

    <h2>Q2 2026 — DeFi Launch</h2>
    <ul>
        <li>TPIX DEX deployment (Uniswap V2 fork)</li>
        <li>BSC Bridge (wTPIX ↔ native TPIX)</li>
        <li>Staking platform</li>
        <li>Mobile wallet application</li>
    </ul>

    <h2>Q3 2026 — Ecosystem Growth</h2>
    <ul>
        <li>Token Factory launch</li>
        <li>Affiliate/Referral program activation</li>
        <li>NFT marketplace</li>
        <li>CEX listings</li>
    </ul>

    <h2>Q4 2026 — Scale & Governance</h2>
    <ul>
        <li>DAO governance implementation</li>
        <li>Multi-chain bridge expansion (Ethereum, Polygon, etc.)</li>
        <li>Enterprise partnerships</li>
        <li>Validator set decentralization</li>
    </ul>

    <div class="page-break"></div>

    {{-- ===== 10. TEAM & PARTNERS ===== --}}
    <h1>10. Team & Partners</h1>
    <p>
        TPIX Chain is developed by <strong>Xman Studio</strong>, a blockchain development team
        specializing in DeFi protocols and Web3 applications for the Southeast Asian market.
    </p>
    <p>
        The team brings extensive experience in Solidity smart contract development, EVM-based
        chain deployment, full-stack Web3 application development, and decentralized protocol design.
    </p>

    {{-- ===== 11. SECURITY & AUDITS ===== --}}
    <h1>11. Security & Audits</h1>
    <ul>
        <li><strong>Smart Contract Audits</strong> — All contracts undergo third-party security audits before mainnet deployment</li>
        <li><strong>IBFT Consensus</strong> — Byzantine fault tolerance ensures network security with up to 1/3 faulty validators</li>
        <li><strong>Rate Limiting</strong> — RPC-level rate limiting prevents spam on the gasless chain</li>
        <li><strong>Multi-sig Treasury</strong> — Protocol funds managed by multi-signature wallets (3-of-5)</li>
        <li><strong>Bug Bounty</strong> — Ongoing bug bounty program for responsible vulnerability disclosure</li>
        <li><strong>Monitoring</strong> — 24/7 chain health monitoring and alerting system</li>
    </ul>

    <div class="page-break"></div>

    {{-- ===== 12. LEGAL DISCLAIMER ===== --}}
    <h1>12. Legal Disclaimer</h1>
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

    <br><br>
    <div style="text-align: center; color: #9ca3af; font-size: 10pt;">
        <p>&copy; 2026 TPIX Chain — Xman Studio. All rights reserved.</p>
        <p>https://xmanstudio.com</p>
    </div>

</body>
</html>
