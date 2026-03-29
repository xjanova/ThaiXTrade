@php
    /**
     * TPIX Chain Whitepaper v2.0 — PDF Template (DomPDF)
     * Bilingual: English / Thai
     * Developed by Xman Studio
     *
     * Usage: Pdf::loadView('whitepaper.pdf', ['lang' => 'en'|'th'])
     */
    $isEn = ($lang ?? 'en') === 'en';
    $fontDir = storage_path('fonts');
    $hasThaiFonts = file_exists($fontDir . '/Sarabun-Regular.ttf');
@endphp
<!DOCTYPE html>
<html lang="{{ $isEn ? 'en' : 'th' }}">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $isEn ? 'TPIX Chain Whitepaper v2.0' : 'TPIX Chain ไวท์เปเปอร์ v2.0' }}</title>
    <style>
        /* === Thai Font (Sarabun) — ต้อง install ด้วย php artisan tpix:install-thai-font === */
        @if($hasThaiFonts)
        @font-face {
            font-family: 'Sarabun';
            font-style: normal;
            font-weight: 400;
            src: url('{{ $fontDir }}/Sarabun-Regular.ttf') format('truetype');
        }
        @font-face {
            font-family: 'Sarabun';
            font-style: normal;
            font-weight: 700;
            src: url('{{ $fontDir }}/Sarabun-Bold.ttf') format('truetype');
        }
        @endif

        /* === Page Setup === */
        @page {
            margin: 55px 45px 65px 45px;
            size: A4;
        }

        /* === Base Typography === */
        body {
            font-family: {{ $hasThaiFonts && !$isEn ? "'Sarabun'," : '' }} 'Helvetica', 'Arial', sans-serif;
            color: #1e293b;
            font-size: {{ $isEn ? '10.5pt' : '11pt' }};
            line-height: {{ $isEn ? '1.65' : '1.7' }};
            background: #ffffff;
            margin: 0;
            padding: 0;
        }

        /* === Page Break Management — ป้องกันเนื้อหาขาดข้ามหน้า === */
        .page-break { page-break-before: always; }
        .no-break { page-break-inside: avoid; }
        h1, h2, h3 { page-break-after: avoid; }
        p { orphans: 3; widows: 3; }
        table { page-break-inside: avoid; }
        .highlight, .highlight-warn, .highlight-info { page-break-inside: avoid; }

        /* === Cover Page === */
        .cover {
            text-align: center;
            padding-top: 160px;
            page-break-after: always;
        }
        .cover-logo {
            width: 100px;
            height: 100px;
            margin: 0 auto 25px;
        }
        .cover h1 {
            font-size: 38pt;
            color: #0891b2;
            margin: 0 0 8px;
            letter-spacing: 3px;
            font-weight: 700;
        }
        .cover h2 {
            font-size: 15pt;
            color: #64748b;
            font-weight: 400;
            margin: 0 0 8px;
        }
        .cover .tagline {
            font-size: 12pt;
            color: #475569;
            margin-top: 30px;
            font-style: italic;
        }
        .cover .version-info {
            font-size: 10pt;
            color: #94a3b8;
            margin-top: 50px;
            line-height: 2;
        }
        .cover .cover-line {
            width: 80px;
            height: 3px;
            background: linear-gradient(to right, #0891b2, #7c3aed);
            margin: 30px auto;
            border-radius: 2px;
        }

        /* === Section Headers === */
        h1 {
            font-size: 20pt;
            color: #0891b2;
            margin: 28px 0 14px;
            padding-bottom: 6px;
            border-bottom: 2px solid #e2e8f0;
        }
        h2 {
            font-size: 14pt;
            color: #1e293b;
            margin: 22px 0 10px;
        }
        h3 {
            font-size: 12pt;
            color: #334155;
            margin: 16px 0 8px;
        }

        /* === Text === */
        p {
            margin: 0 0 10px;
            text-align: justify;
        }
        ul, ol {
            margin: 0 0 12px;
            padding-left: 22px;
        }
        li {
            margin-bottom: 4px;
        }
        strong { color: #0f172a; }

        /* === Tables (Premium Style) === */
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 14px 0;
            font-size: 9.5pt;
        }
        th {
            background: #0891b2;
            color: #ffffff;
            font-weight: 700;
            text-align: left;
            padding: 8px 10px;
            border: 1px solid #0891b2;
        }
        td {
            padding: 6px 10px;
            border: 1px solid #e2e8f0;
            vertical-align: top;
        }
        tr:nth-child(even) td { background: #f8fafc; }
        tr:nth-child(odd) td { background: #ffffff; }

        /* === Highlight Boxes === */
        .highlight {
            background: #ecfeff;
            border-left: 4px solid #0891b2;
            padding: 12px 16px;
            margin: 14px 0;
            border-radius: 0 6px 6px 0;
            page-break-inside: avoid;
        }
        .highlight strong { color: #0891b2; }

        .highlight-warn {
            background: #fff7ed;
            border-left: 4px solid #f59e0b;
            padding: 12px 16px;
            margin: 14px 0;
            border-radius: 0 6px 6px 0;
            page-break-inside: avoid;
        }
        .highlight-warn strong { color: #d97706; }

        .highlight-info {
            background: #f0f9ff;
            border-left: 4px solid #3b82f6;
            padding: 12px 16px;
            margin: 14px 0;
            border-radius: 0 6px 6px 0;
            page-break-inside: avoid;
        }
        .highlight-info strong { color: #2563eb; }

        /* === Stats Grid === */
        .stats-grid {
            text-align: center;
            margin: 20px 0;
            page-break-inside: avoid;
        }
        .stats-grid table {
            margin: 0 auto;
            border: none;
        }
        .stats-grid td {
            text-align: center;
            padding: 14px 22px;
            border: none;
            background: transparent !important;
        }
        .stats-grid .val {
            font-size: 20pt;
            font-weight: 700;
            color: #0891b2;
            display: block;
        }
        .stats-grid .lbl {
            font-size: 8pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: block;
            margin-top: 2px;
        }

        /* === TOC === */
        .toc { margin: 24px 0; }
        .toc-item {
            display: block;
            padding: 5px 0;
            border-bottom: 1px dotted #cbd5db;
            color: #334155;
            text-decoration: none;
            font-size: 10.5pt;
        }
        .toc-item span { float: right; color: #94a3b8; font-size: 10pt; }
        .toc-section { font-weight: 700; color: #0891b2; padding-top: 8px; }
        .toc-sub { padding-left: 20px; font-size: 10pt; color: #475569; }

        /* === Comparison Table === */
        .comparison td.yes { color: #059669; font-weight: 700; }
        .comparison td.no { color: #94a3b8; }
        .comparison td.partial { color: #d97706; }

        /* === Disclaimer === */
        .disclaimer {
            font-size: 9pt;
            color: #64748b;
            line-height: 1.5;
        }

        /* === Footer (every page) === */
        .page-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 7.5pt;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding: 6px 45px;
        }
        .page-footer .left { float: left; }
        .page-footer .right { float: right; }

        /* === Section wrapper (avoid break within small blocks) === */
        .block { page-break-inside: avoid; margin-bottom: 16px; }
    </style>
</head>
<body>

    {{-- Running Footer --}}
    <div class="page-footer">
        <span class="left">TPIX Chain — {{ $isEn ? 'Whitepaper v2.0' : 'ไวท์เปเปอร์ v2.0' }}</span>
        <span class="right">&copy; 2026 Xman Studio</span>
    </div>

    {{-- ================================================================ --}}
    {{-- COVER PAGE --}}
    {{-- ================================================================ --}}
    <div class="cover">
        @if(file_exists(public_path('logo.png')))
            <img src="{{ public_path('logo.png') }}" class="cover-logo" alt="TPIX">
        @else
            <div style="width:100px;height:100px;margin:0 auto 25px;background:#0891b2;border-radius:20px;line-height:100px;color:#fff;font-size:28pt;font-weight:700;">TPIX</div>
        @endif

        <h1>TPIX CHAIN</h1>
        <h2>{{ $isEn ? 'Whitepaper v2.0' : 'ไวท์เปเปอร์ v2.0' }}</h2>

        <div class="cover-line"></div>

        <p class="tagline">{{ $isEn ? 'Zero Gas. Instant Finality. Real-World Impact.' : 'ค่า Gas เป็นศูนย์ Finality ทันที ส่งผลกระทบต่อโลกจริง' }}</p>

        <div class="version-info">
            {{ $isEn ? 'Version 2.0 — March 2026' : 'เวอร์ชัน 2.0 — มีนาคม 2569' }}<br>
            {{ $isEn ? 'Developed by' : 'พัฒนาโดย' }} <strong>Xman Studio</strong><br>
            <span style="font-size:9pt;">https://tpix.online &nbsp;|&nbsp; https://xmanstudio.com</span>
        </div>
    </div>

    {{-- ================================================================ --}}
    {{-- TABLE OF CONTENTS --}}
    {{-- ================================================================ --}}
    <h1>{{ $isEn ? 'Table of Contents' : 'สารบัญ' }}</h1>
    <div class="toc">
        <div class="toc-item toc-section">1. {{ $isEn ? 'Executive Summary' : 'บทสรุปผู้บริหาร' }}</div>
        <div class="toc-item toc-section">2. {{ $isEn ? 'Problem & Solution' : 'ปัญหาและทางออก' }}</div>
        <div class="toc-item toc-section">3. {{ $isEn ? 'TPIX Chain Architecture' : 'สถาปัตยกรรม TPIX Chain' }}</div>
        <div class="toc-item toc-sub">3.1 {{ $isEn ? 'IBFT 2.0 Consensus' : 'IBFT 2.0 Consensus' }}</div>
        <div class="toc-item toc-sub">3.2 {{ $isEn ? 'Zero Gas Fee Model' : 'ระบบค่า Gas เป็นศูนย์' }}</div>
        <div class="toc-item toc-sub">3.3 {{ $isEn ? 'Comparison with Other Chains' : 'เปรียบเทียบกับเชนอื่น' }}</div>
        <div class="toc-item toc-section">4. {{ $isEn ? 'Tokenomics' : 'โทเคโนมิกส์' }}</div>
        <div class="toc-item toc-sub">4.1 {{ $isEn ? 'Token Allocation' : 'การจัดสรรโทเคน' }}</div>
        <div class="toc-item toc-sub">4.2 {{ $isEn ? 'Emission Schedule' : 'ตารางการปล่อยเหรียญ' }}</div>
        <div class="toc-item toc-sub">4.3 {{ $isEn ? 'Deflationary Mechanisms' : 'กลไกลดจำนวนเหรียญ' }}</div>
        <div class="toc-item toc-section">5. {{ $isEn ? 'Master Node System' : 'ระบบ Master Node' }}</div>
        <div class="toc-item toc-sub">5.1 {{ $isEn ? 'Four-Tier Staking Model' : 'ระบบ Staking 4 ระดับ' }}</div>
        <div class="toc-item toc-sub">5.2 {{ $isEn ? 'Reward Distribution' : 'การแจกจ่ายรางวัล' }}</div>
        <div class="toc-item toc-sub">5.3 {{ $isEn ? 'Slashing & Penalties' : 'การลงโทษ' }}</div>
        <div class="toc-item toc-section">6. {{ $isEn ? 'Validator Governance' : 'การปกครอง Validator' }}</div>
        <div class="toc-item toc-section">7. {{ $isEn ? 'Living Identity — Seedless Recovery' : 'Living Identity — กู้กระเป๋าไม่ต้องใช้ Seed Phrase' }}</div>
        <div class="toc-item toc-section">8. {{ $isEn ? 'TPIX TRADE DEX' : 'กระดานแลกเปลี่ยน TPIX TRADE' }}</div>
        <div class="toc-item toc-sub">8.1 {{ $isEn ? 'Hybrid Order Book + AMM' : 'Order Book + AMM แบบผสม' }}</div>
        <div class="toc-item toc-sub">8.2 {{ $isEn ? 'Fee Structure' : 'โครงสร้างค่าธรรมเนียม' }}</div>
        <div class="toc-item toc-sub">8.3 {{ $isEn ? 'TPIXRouter Smart Contract' : 'TPIXRouter Smart Contract' }}</div>
        <div class="toc-item toc-section">9. {{ $isEn ? 'Cross-Chain Bridge' : 'สะพานข้ามเชน' }}</div>
        <div class="toc-item toc-section">10. {{ $isEn ? 'Token Factory' : 'โรงงานสร้างโทเคน' }}</div>
        <div class="toc-item toc-section">11. {{ $isEn ? 'Token Sale Details' : 'รายละเอียดการขายโทเคน' }}</div>
        <div class="toc-item toc-section">12. {{ $isEn ? 'Real-World Applications' : 'แอปพลิเคชันในโลกจริง' }}</div>
        <div class="toc-item toc-sub">12.1 {{ $isEn ? 'Carbon Credit Trading' : 'ตลาดซื้อขายคาร์บอนเครดิต' }}</div>
        <div class="toc-item toc-sub">12.2 {{ $isEn ? 'Food Passport Traceability' : 'ระบบตรวจสอบย้อนกลับอาหาร' }}</div>
        <div class="toc-item toc-section">13. {{ $isEn ? 'Products & Applications' : 'ผลิตภัณฑ์และแอปพลิเคชัน' }}</div>
        <div class="toc-item toc-section">14. {{ $isEn ? 'Roadmap' : 'แผนงาน' }}</div>
        <div class="toc-item toc-section">15. {{ $isEn ? 'Security & Audits' : 'ความปลอดภัยและการตรวจสอบ' }}</div>
        <div class="toc-item toc-section">16. {{ $isEn ? 'Team & Partners' : 'ทีมงานและพาร์ทเนอร์' }}</div>
        <div class="toc-item toc-section">17. {{ $isEn ? 'Legal Disclaimer' : 'ข้อจำกัดความรับผิดชอบ' }}</div>
    </div>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 1. EXECUTIVE SUMMARY --}}
    {{-- ================================================================ --}}
    <h1>1. {{ $isEn ? 'Executive Summary' : 'บทสรุปผู้บริหาร' }}</h1>

    @if($isEn)
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
    @else
    <p>
        TPIX Chain เป็นบล็อกเชนประสิทธิภาพสูงที่รองรับ EVM สร้างบนเทคโนโลยี Polygon Edge
        ออกแบบมาโดยเฉพาะสำหรับเศรษฐกิจดิจิทัลไทยและอาเซียน ด้วยการทำธุรกรรมไม่เสียค่า Gas,
        เวลาสร้างบล็อก 2 วินาที, IBFT 2.0 consensus ที่ให้ finality ทันที และระบบ master node
        staking 4 ระดับ TPIX Chain ให้ throughput ระดับองค์กรพร้อมการเข้าถึงที่ง่ายสำหรับผู้ใช้ทั่วไป
    </p>
    <p>
        เหรียญ TPIX (จำนวนคงที่ 7 พันล้าน) เป็น native coin ของเชน ขับเคลื่อนระบบนิเวศทั้งหมด:
        master node staking ผลตอบแทนสูงสุด 20% APY, ระบบ validator governance บนเชน,
        กระดานแลกเปลี่ยน TPIX TRADE DEX แบบ hybrid order book, สะพานข้ามเชนไป BSC,
        โรงงานสร้างโทเคนแบบไม่ต้องขออนุญาต และระบบกู้กระเป๋าไม่ต้องใช้ seed phrase
        แห่งแรกของโลก (Living Identity)
    </p>
    <p>
        นอกเหนือจาก DeFi แล้ว TPIX Chain ยังส่งผลกระทบต่อโลกจริงผ่านตลาดคาร์บอนเครดิตบนเชน
        พร้อมการยืนยันด้วย IoT และระบบตรวจสอบย้อนกลับอาหารจากฟาร์มถึงโต๊ะอาหาร —
        ใช้ประโยชน์จากค่า Gas เป็นศูนย์เพื่อทำให้บล็อกเชนเข้าถึงได้สำหรับผู้ใช้ทั่วไปและธุรกิจขนาดเล็ก
    </p>
    @endif

    <div class="stats-grid">
        <table>
            <tr>
                <td><span class="val">7B</span><span class="lbl">{{ $isEn ? 'Fixed Supply' : 'จำนวนคงที่' }}</span></td>
                <td><span class="val">0 Gas</span><span class="lbl">{{ $isEn ? 'Transaction Fee' : 'ค่าธรรมเนียม' }}</span></td>
                <td><span class="val">2s</span><span class="lbl">{{ $isEn ? 'Block Time' : 'เวลาสร้างบล็อก' }}</span></td>
                <td><span class="val">1,500</span><span class="lbl">{{ $isEn ? 'TPS Capacity' : 'ความจุ TPS' }}</span></td>
                <td><span class="val">IBFT 2.0</span><span class="lbl">Consensus</span></td>
            </tr>
        </table>
    </div>

    {{-- ================================================================ --}}
    {{-- 2. PROBLEM & SOLUTION --}}
    {{-- ================================================================ --}}
    <h1>2. {{ $isEn ? 'Problem & Solution' : 'ปัญหาและทางออก' }}</h1>

    <h2>{{ $isEn ? 'The Problem' : 'ปัญหา' }}</h2>
    <ul>
        @if($isEn)
        <li><strong>Prohibitive gas fees</strong> — Ethereum ($5-50 per tx) and even BSC ($0.10-1.00) make micro-transactions impractical for everyday users and small businesses in emerging markets.</li>
        <li><strong>Complex DeFi onboarding</strong> — Existing DEXes require seed phrases, gas tokens, and multiple approvals, alienating non-technical users.</li>
        <li><strong>Seed phrase vulnerability</strong> — Over $100M lost annually to seed phrase theft, phishing, and loss. No recovery mechanism exists on major chains.</li>
        <li><strong>Limited ASEAN infrastructure</strong> — No purpose-built blockchain for Thai/ASEAN market with native language support and local regulatory awareness.</li>
        <li><strong>Real-world disconnection</strong> — Most chains optimize for DeFi speculation, not real-world use cases like supply chain, carbon credits, or food safety.</li>
        @else
        <li><strong>ค่า Gas สูงเกินไป</strong> — Ethereum ($5-50 ต่อธุรกรรม) และแม้แต่ BSC ($0.10-1.00) ทำให้ธุรกรรมขนาดเล็กไม่คุ้มค่าสำหรับผู้ใช้ทั่วไปและธุรกิจขนาดเล็ก</li>
        <li><strong>การเข้าใช้ DeFi ซับซ้อน</strong> — DEX ที่มีอยู่ต้องใช้ seed phrase, โทเคนสำหรับค่า gas และการอนุมัติหลายขั้นตอน ทำให้ผู้ใช้ทั่วไปถอดใจ</li>
        <li><strong>ช่องโหว่ seed phrase</strong> — สูญเสียเงินกว่า $100M ต่อปีจากการโจรกรรม seed phrase, ฟิชชิ่ง และการสูญหาย ไม่มีกลไกกู้คืนบนเชนหลักๆ</li>
        <li><strong>โครงสร้างพื้นฐาน ASEAN จำกัด</strong> — ไม่มีบล็อกเชนที่สร้างมาเพื่อตลาดไทย/อาเซียนโดยเฉพาะ พร้อมรองรับภาษาท้องถิ่น</li>
        <li><strong>ไม่เชื่อมต่อโลกจริง</strong> — เชนส่วนใหญ่เน้น DeFi เก็งกำไร ไม่ใช่ use case ในโลกจริง เช่น supply chain, คาร์บอนเครดิต หรือความปลอดภัยอาหาร</li>
        @endif
    </ul>

    <h2>{{ $isEn ? 'Our Solution' : 'ทางออกของเรา' }}</h2>
    <div class="highlight">
        <p><strong>{{ $isEn ? 'TPIX Chain: Zero Gas, Instant Finality, Real-World Impact' : 'TPIX Chain: ค่า Gas เป็นศูนย์, Finality ทันที, ส่งผลกระทบต่อโลกจริง' }}</strong></p>
        <p>{{ $isEn ? 'A blockchain where every transaction is free, every block is final in 2 seconds, and every user can recover their wallet without a seed phrase.' : 'บล็อกเชนที่ทุกธุรกรรมฟรี ทุกบล็อก final ใน 2 วินาที และทุกคนสามารถกู้กระเป๋าได้โดยไม่ต้องใช้ seed phrase' }}</p>
    </div>
    <ul>
        @if($isEn)
        <li><strong>Zero Gas Fees</strong> — Hardcoded in genesis block. No token balance needed to transact. True financial inclusion.</li>
        <li><strong>Living Identity</strong> — World's first on-chain seedless wallet recovery via security questions + GPS verification + 48-hour time-lock.</li>
        <li><strong>Hybrid DEX</strong> — Internal order book matching (limit/market/stop-limit) + AMM for deep liquidity.</li>
        <li><strong>4-Tier Master Node</strong> — From 10K TPIX (Light) to 10M TPIX (Validator) with proportional APY and governance rights.</li>
        <li><strong>Real-World Integration</strong> — Carbon credits, food passport, and connected to 500,000+ enterprise users via ThaiPrompt platform.</li>
        <li><strong>Cross-Chain Bridge</strong> — Native TPIX &#8596; wTPIX (BEP-20) on BSC with trustless lock-and-mint mechanics.</li>
        @else
        <li><strong>ค่า Gas เป็นศูนย์</strong> — กำหนดใน genesis block ไม่ต้องมียอดเหรียญเพื่อทำธุรกรรม การเข้าถึงทางการเงินอย่างแท้จริง</li>
        <li><strong>Living Identity</strong> — ระบบกู้กระเป๋าบนเชนแบบไม่ต้องใช้ seed phrase แห่งแรกของโลก ผ่านคำถามความปลอดภัย + ยืนยัน GPS + time-lock 48 ชั่วโมง</li>
        <li><strong>Hybrid DEX</strong> — ระบบจับคู่ order book ภายใน (limit/market/stop-limit) + AMM เพื่อสภาพคล่องเต็มที่</li>
        <li><strong>Master Node 4 ระดับ</strong> — ตั้งแต่ 10K TPIX (Light) ถึง 10M TPIX (Validator) พร้อม APY และสิทธิ์ governance ตามสัดส่วน</li>
        <li><strong>เชื่อมต่อโลกจริง</strong> — คาร์บอนเครดิต, food passport และเชื่อมต่อกับผู้ใช้องค์กรกว่า 500,000 คนผ่านแพลตฟอร์ม ThaiPrompt</li>
        <li><strong>สะพานข้ามเชน</strong> — Native TPIX &#8596; wTPIX (BEP-20) บน BSC ด้วยกลไก lock-and-mint แบบไม่ต้องเชื่อใจตัวกลาง</li>
        @endif
    </ul>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 3. TPIX CHAIN ARCHITECTURE --}}
    {{-- ================================================================ --}}
    <h1>3. {{ $isEn ? 'TPIX Chain Architecture' : 'สถาปัตยกรรม TPIX Chain' }}</h1>

    @if($isEn)
    <p>
        TPIX Chain is built on Polygon Edge, an open-source modular framework for building
        Ethereum-compatible blockchain networks. It uses Istanbul Byzantine Fault Tolerant (IBFT 2.0)
        consensus, providing immediate transaction finality with no possibility of chain reorganizations.
    </p>
    @else
    <p>
        TPIX Chain สร้างบน Polygon Edge เฟรมเวิร์กโอเพนซอร์สสำหรับสร้างเครือข่ายบล็อกเชน
        ที่เข้ากันได้กับ Ethereum ใช้ Istanbul Byzantine Fault Tolerant (IBFT 2.0) consensus
        ให้ finality ของธุรกรรมทันทีโดยไม่มีความเป็นไปได้ที่เชนจะ reorganize
    </p>
    @endif

    <table>
        <thead><tr><th>{{ $isEn ? 'Parameter' : 'พารามิเตอร์' }}</th><th>{{ $isEn ? 'Value' : 'ค่า' }}</th></tr></thead>
        <tbody>
            <tr><td>{{ $isEn ? 'Chain Name' : 'ชื่อเชน' }}</td><td>TPIX Chain</td></tr>
            <tr><td>Chain ID (Mainnet)</td><td>4289</td></tr>
            <tr><td>Chain ID (Testnet)</td><td>4290</td></tr>
            <tr><td>Consensus</td><td>IBFT 2.0 (Istanbul Byzantine Fault Tolerant)</td></tr>
            <tr><td>{{ $isEn ? 'Block Time' : 'เวลาสร้างบล็อก' }}</td><td>{{ $isEn ? '2 seconds' : '2 วินาที' }}</td></tr>
            <tr><td>Finality</td><td>{{ $isEn ? '~10 seconds (5 blocks)' : '~10 วินาที (5 บล็อก)' }}</td></tr>
            <tr><td>{{ $isEn ? 'TPS Capacity' : 'ความจุ TPS' }}</td><td>{{ $isEn ? '~1,500 transactions/second' : '~1,500 ธุรกรรม/วินาที' }}</td></tr>
            <tr><td>{{ $isEn ? 'Gas Price' : 'ค่า Gas' }}</td><td>{{ $isEn ? '0 (hardcoded in genesis — completely free)' : '0 (กำหนดใน genesis — ฟรีทั้งหมด)' }}</td></tr>
            <tr><td>{{ $isEn ? 'EVM Compatible' : 'รองรับ EVM' }}</td><td>{{ $isEn ? 'Full (Solidity, Vyper, ERC-20/721/1155)' : 'เต็มรูปแบบ (Solidity, Vyper, ERC-20/721/1155)' }}</td></tr>
            <tr><td>Validators</td><td>{{ $isEn ? '4 IBFT nodes (BFT tolerates 1 faulty)' : '4 โหนด IBFT (BFT ทนได้ 1 โหนดที่มีปัญหา)' }}</td></tr>
            <tr><td>Native Token</td><td>TPIX (18 decimals)</td></tr>
            <tr><td>RPC Endpoint</td><td>https://rpc.tpix.online</td></tr>
            <tr><td>Block Explorer</td><td>https://explorer.tpix.online (Blockscout)</td></tr>
        </tbody>
    </table>

    <div class="block">
    <h2>3.1 IBFT 2.0 Consensus</h2>
    @if($isEn)
    <p>
        Unlike Proof-of-Work (Ethereum Classic) or probabilistic Proof-of-Stake (Ethereum), IBFT 2.0
        provides <strong>deterministic finality</strong>: once a block is committed, it can never be reverted.
        This is critical for financial applications where transaction reversal would be catastrophic.
    </p>
    <ul>
        <li><strong>Round-robin proposer</strong> — Validators take turns proposing blocks in 2-second slots.</li>
        <li><strong>Byzantine tolerance</strong> — Network survives with up to 1 faulty validator out of 4.</li>
        <li><strong>No forks possible</strong> — Consensus requires 2/3+ agreement before block inclusion.</li>
        <li><strong>Instant finality</strong> — No need to wait for "confirmations" like on Ethereum.</li>
    </ul>
    @else
    <p>
        ต่างจาก Proof-of-Work (Ethereum Classic) หรือ Proof-of-Stake แบบน่าจะเป็น (Ethereum)
        IBFT 2.0 ให้ <strong>finality แบบแน่นอน</strong>: เมื่อบล็อกถูก commit แล้ว จะไม่สามารถย้อนกลับได้เลย
        ซึ่งสำคัญมากสำหรับแอปพลิเคชันทางการเงินที่การย้อนธุรกรรมจะเป็นหายนะ
    </p>
    <ul>
        <li><strong>Round-robin proposer</strong> — Validator ผลัดกันเสนอบล็อกในช่วงเวลา 2 วินาที</li>
        <li><strong>Byzantine tolerance</strong> — เครือข่ายอยู่รอดได้แม้มี validator ที่มีปัญหา 1 ใน 4 โหนด</li>
        <li><strong>ไม่มี fork</strong> — Consensus ต้องได้รับความเห็นชอบ 2/3 ขึ้นไปก่อนรวมบล็อก</li>
        <li><strong>Finality ทันที</strong> — ไม่ต้องรอ "confirmations" เหมือนบน Ethereum</li>
    </ul>
    @endif
    </div>

    <div class="block">
    <h2>3.2 {{ $isEn ? 'Zero Gas Fee Model' : 'ระบบค่า Gas เป็นศูนย์' }}</h2>
    @if($isEn)
    <p>
        TPIX Chain's zero-gas model is fundamentally different from "low fee" chains. Gas price is
        set to 0 in the genesis block itself — not subsidized, not temporarily reduced, but structurally free:
    </p>
    <ul>
        <li>Users can interact with dApps without holding any TPIX for gas.</li>
        <li>Micro-transactions (0.001 TPIX) are economically viable.</li>
        <li>Smart contract deployments are free.</li>
        <li>Token transfers, NFT minting, and DeFi operations cost nothing.</li>
    </ul>
    @else
    <p>
        ระบบ zero-gas ของ TPIX Chain แตกต่างอย่างสิ้นเชิงจากเชน "ค่าธรรมเนียมต่ำ"
        ค่า Gas ถูกกำหนดเป็น 0 ใน genesis block — ไม่ใช่การอุดหนุน ไม่ใช่การลดชั่วคราว
        แต่เป็นฟรีทางโครงสร้าง:
    </p>
    <ul>
        <li>ผู้ใช้สามารถใช้ dApps ได้โดยไม่ต้องถือ TPIX สำหรับค่า gas</li>
        <li>ธุรกรรมขนาดเล็ก (0.001 TPIX) คุ้มค่าทางเศรษฐกิจ</li>
        <li>การ deploy smart contract ฟรี</li>
        <li>การโอนโทเคน, การสร้าง NFT และการดำเนินการ DeFi ไม่มีค่าใช้จ่าย</li>
    </ul>
    @endif

    <div class="highlight-warn">
        <p><strong>{{ $isEn ? 'Anti-Spam Protection:' : 'การป้องกัน Spam:' }}</strong>
        {{ $isEn
            ? 'Since gas is free, RPC-level rate limiting is applied per IP address to prevent transaction spam. Transaction queue prioritization ensures legitimate transactions are processed first.'
            : 'เนื่องจากค่า gas ฟรี จึงมีการจำกัดอัตราการเรียกใช้ RPC ต่อ IP address เพื่อป้องกัน spam ระบบจัดลำดับความสำคัญของคิวธุรกรรมเพื่อให้ธุรกรรมที่ถูกต้องได้รับการประมวลผลก่อน'
        }}</p>
    </div>
    </div>

    <div class="block">
    <h2>3.3 {{ $isEn ? 'Comparison with Other Chains' : 'เปรียบเทียบกับเชนอื่น' }}</h2>
    <table class="comparison">
        <thead><tr>
            <th>{{ $isEn ? 'Feature' : 'คุณสมบัติ' }}</th>
            <th>TPIX Chain</th><th>Ethereum</th><th>BSC</th><th>Polygon PoS</th><th>Solana</th>
        </tr></thead>
        <tbody>
            <tr><td>{{ $isEn ? 'Gas Fee' : 'ค่า Gas' }}</td><td class="yes">{{ $isEn ? 'Free (0)' : 'ฟรี (0)' }}</td><td class="no">$5-50</td><td class="no">$0.10-1</td><td class="no">$0.01-0.1</td><td class="no">$0.0025</td></tr>
            <tr><td>{{ $isEn ? 'Block Time' : 'เวลาบล็อก' }}</td><td class="yes">2 sec</td><td>12 sec</td><td>3 sec</td><td>2 sec</td><td>0.4 sec</td></tr>
            <tr><td>Finality</td><td class="yes">{{ $isEn ? 'Instant' : 'ทันที' }}</td><td class="no">~15 min</td><td class="no">~45 sec</td><td class="no">~2 min</td><td>~13 sec</td></tr>
            <tr><td>{{ $isEn ? 'Fork Possible' : 'มี Fork ได้' }}</td><td class="yes">{{ $isEn ? 'No' : 'ไม่มี' }}</td><td class="no">{{ $isEn ? 'Yes' : 'ได้' }}</td><td class="no">{{ $isEn ? 'Yes' : 'ได้' }}</td><td class="no">{{ $isEn ? 'Yes' : 'ได้' }}</td><td class="no">{{ $isEn ? 'Yes' : 'ได้' }}</td></tr>
            <tr><td>{{ $isEn ? 'EVM Compatible' : 'รองรับ EVM' }}</td><td class="yes">{{ $isEn ? 'Full' : 'เต็ม' }}</td><td class="yes">Native</td><td class="yes">{{ $isEn ? 'Full' : 'เต็ม' }}</td><td class="yes">{{ $isEn ? 'Full' : 'เต็ม' }}</td><td class="no">{{ $isEn ? 'No' : 'ไม่' }}</td></tr>
            <tr><td>{{ $isEn ? 'Wallet Recovery' : 'กู้กระเป๋า' }}</td><td class="yes">Living Identity</td><td class="no">{{ $isEn ? 'None' : 'ไม่มี' }}</td><td class="no">{{ $isEn ? 'None' : 'ไม่มี' }}</td><td class="no">{{ $isEn ? 'None' : 'ไม่มี' }}</td><td class="no">{{ $isEn ? 'None' : 'ไม่มี' }}</td></tr>
            <tr><td>{{ $isEn ? 'Carbon Credits' : 'คาร์บอนเครดิต' }}</td><td class="yes">{{ $isEn ? 'Built-in' : 'ในตัว' }}</td><td class="no">{{ $isEn ? 'No' : 'ไม่มี' }}</td><td class="no">{{ $isEn ? 'No' : 'ไม่มี' }}</td><td class="no">{{ $isEn ? 'No' : 'ไม่มี' }}</td><td class="no">{{ $isEn ? 'No' : 'ไม่มี' }}</td></tr>
        </tbody>
    </table>
    </div>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 4. TOKENOMICS --}}
    {{-- ================================================================ --}}
    <h1>4. {{ $isEn ? 'Tokenomics' : 'โทเคโนมิกส์' }}</h1>

    @if($isEn)
    <p>
        TPIX has a fixed supply of <strong>7,000,000,000</strong> (7 billion) tokens with 18 decimals,
        entirely pre-mined in the genesis block. There is no minting mechanism — the total supply is
        permanently capped at 7 billion.
    </p>
    @else
    <p>
        TPIX มีจำนวนคงที่ <strong>7,000,000,000</strong> (7 พันล้าน) โทเคนด้วย 18 ทศนิยม
        ถูกสร้างทั้งหมดใน genesis block ไม่มีกลไกการสร้างเหรียญเพิ่ม — จำนวนทั้งหมดถูกจำกัดอย่างถาวรที่ 7 พันล้าน
    </p>
    @endif

    <div class="block">
    <h2>4.1 {{ $isEn ? 'Token Allocation' : 'การจัดสรรโทเคน' }}</h2>
    <table>
        <thead><tr>
            <th>{{ $isEn ? 'Allocation' : 'การจัดสรร' }}</th>
            <th>%</th>
            <th>{{ $isEn ? 'TPIX Amount' : 'จำนวน TPIX' }}</th>
            <th>{{ $isEn ? 'Purpose' : 'วัตถุประสงค์' }}</th>
        </tr></thead>
        <tbody>
            <tr><td>{{ $isEn ? 'Master Node Rewards' : 'รางวัล Master Node' }}</td><td>20%</td><td>1,400,000,000</td><td>{{ $isEn ? 'Staking rewards distributed over 3 years (2025-2028)' : 'รางวัล staking แจกจ่ายตลอด 3 ปี (2025-2028)' }}</td></tr>
            <tr><td>{{ $isEn ? 'Ecosystem Development' : 'พัฒนาระบบนิเวศ' }}</td><td>25%</td><td>1,750,000,000</td><td>{{ $isEn ? 'Grants, partnerships, marketing, operations' : 'ทุน, พาร์ทเนอร์, การตลาด, ดำเนินงาน' }}</td></tr>
            <tr><td>{{ $isEn ? 'Community & Rewards' : 'ชุมชนและรางวัล' }}</td><td>20%</td><td>1,400,000,000</td><td>{{ $isEn ? 'Affiliate program, airdrops, community incentives' : 'โปรแกรม Affiliate, airdrop, แรงจูงใจชุมชน' }}</td></tr>
            <tr><td>{{ $isEn ? 'Liquidity & Market Making' : 'สภาพคล่องและ Market Making' }}</td><td>15%</td><td>1,050,000,000</td><td>{{ $isEn ? 'DEX liquidity pools on TPIX Chain + BSC' : 'DEX liquidity pool บน TPIX Chain + BSC' }}</td></tr>
            <tr><td>{{ $isEn ? 'Token Sale (ICO)' : 'ขายโทเคน (ICO)' }}</td><td>10%</td><td>700,000,000</td><td>{{ $isEn ? 'Private, Pre-Sale, Public sale phases' : 'เฟส Private, Pre-Sale, Public' }}</td></tr>
            <tr><td>{{ $isEn ? 'Team & Advisors' : 'ทีมงานและที่ปรึกษา' }}</td><td>10%</td><td>700,000,000</td><td>{{ $isEn ? 'Locked with vesting schedule' : 'ล็อกตามตาราง vesting' }}</td></tr>
        </tbody>
    </table>
    </div>

    <div class="block">
    <h2>4.2 {{ $isEn ? 'Emission Schedule' : 'ตารางการปล่อยเหรียญ' }}</h2>
    @if($isEn)
    <p>
        Master node rewards are distributed over a 3-year period from 2025 to 2028, with a
        decreasing emission rate to create predictable deflationary pressure. After 2028,
        no further emission occurs — the supply is permanently fixed.
    </p>
    @else
    <p>
        รางวัล master node แจกจ่ายตลอด 3 ปีตั้งแต่ 2025 ถึง 2028 ด้วยอัตราการปล่อยที่ลดลง
        เพื่อสร้างแรงกดดันเงินฝืดที่คาดการณ์ได้ หลังปี 2028 ไม่มีการปล่อยเหรียญอีก —
        จำนวนเหรียญคงที่ถาวร
    </p>
    @endif
    <table>
        <thead><tr>
            <th>{{ $isEn ? 'Year' : 'ปี' }}</th>
            <th>{{ $isEn ? 'Period' : 'ช่วงเวลา' }}</th>
            <th>{{ $isEn ? 'Emission' : 'การปล่อย' }}</th>
            <th>{{ $isEn ? 'Per Block (~)' : 'ต่อบล็อก (~)' }}</th>
            <th>{{ $isEn ? 'Share of Pool' : 'สัดส่วน' }}</th>
        </tr></thead>
        <tbody>
            <tr><td>{{ $isEn ? 'Year 1' : 'ปีที่ 1' }}</td><td>2025-2026</td><td>600,000,000 TPIX</td><td>~38.3 TPIX</td><td>42.9%</td></tr>
            <tr><td>{{ $isEn ? 'Year 2' : 'ปีที่ 2' }}</td><td>2026-2027</td><td>500,000,000 TPIX</td><td>~31.9 TPIX</td><td>35.7%</td></tr>
            <tr><td>{{ $isEn ? 'Year 3' : 'ปีที่ 3' }}</td><td>2027-2028</td><td>300,000,000 TPIX</td><td>~19.1 TPIX</td><td>21.4%</td></tr>
        </tbody>
    </table>
    </div>

    <div class="block">
    <h2>4.3 {{ $isEn ? 'Deflationary Mechanisms' : 'กลไกลดจำนวนเหรียญ' }}</h2>
    <div class="highlight">
        <p><strong>{{ $isEn ? 'TPIX is net-deflationary.' : 'TPIX มีลักษณะเงินฝืดสุทธิ' }}</strong>
        {{ $isEn ? 'Multiple burn mechanisms permanently remove tokens from circulation:' : 'กลไก burn หลายรายการลบโทเคนออกจากการหมุนเวียนอย่างถาวร:' }}</p>
    </div>
    <ul>
        @if($isEn)
        <li><strong>Token Factory Burn</strong> — 50% of the 100 TPIX token creation fee is permanently burned (50 TPIX per token).</li>
        <li><strong>DEX Protocol Fee Burn</strong> — 0.05% of swap volume is directed to an automated buyback-and-burn contract.</li>
        <li><strong>Bridge Fee Burn</strong> — 10% of cross-chain bridge fees are permanently burned.</li>
        @else
        <li><strong>Token Factory Burn</strong> — 50% ของค่าสร้างโทเคน 100 TPIX ถูก burn ถาวร (50 TPIX ต่อโทเคน)</li>
        <li><strong>DEX Protocol Fee Burn</strong> — 0.05% ของปริมาณ swap ถูกส่งไปยัง contract buyback-and-burn อัตโนมัติ</li>
        <li><strong>Bridge Fee Burn</strong> — 10% ของค่าธรรมเนียมสะพานข้ามเชนถูก burn ถาวร</li>
        @endif
    </ul>
    @if($isEn)
    <p>
        As the ecosystem grows, burn volume will accelerate while emission decreases yearly,
        creating compounding deflationary pressure. After 2028, with zero emission and ongoing burns,
        the circulating supply will decrease indefinitely.
    </p>
    @else
    <p>
        เมื่อระบบนิเวศเติบโต ปริมาณการ burn จะเร่งตัวขึ้นในขณะที่การปล่อยเหรียญลดลงทุกปี
        สร้างแรงกดดันเงินฝืดแบบทบต้น หลังปี 2028 ด้วยการปล่อยเป็นศูนย์และการ burn ต่อเนื่อง
        จำนวนเหรียญหมุนเวียนจะลดลงอย่างไม่มีที่สิ้นสุด
    </p>
    @endif
    </div>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 5. MASTER NODE SYSTEM --}}
    {{-- ================================================================ --}}
    <h1>5. {{ $isEn ? 'Master Node System' : 'ระบบ Master Node' }}</h1>

    @if($isEn)
    <p>
        The TPIX master node system is a 4-tier staking infrastructure managed by the
        <strong>NodeRegistryV2</strong> smart contract. Each tier serves a distinct role in the
        network, from governance and block validation to data relay and network resilience.
    </p>
    @else
    <p>
        ระบบ master node ของ TPIX เป็นโครงสร้าง staking 4 ระดับที่จัดการโดย
        smart contract <strong>NodeRegistryV2</strong> แต่ละระดับมีบทบาทที่แตกต่างกัน
        ตั้งแต่ governance และการตรวจสอบบล็อก ไปจนถึงการส่งต่อข้อมูลและความยืดหยุ่นของเครือข่าย
    </p>
    @endif

    <div class="block">
    <h2>5.1 {{ $isEn ? 'Four-Tier Staking Model' : 'ระบบ Staking 4 ระดับ' }}</h2>
    <table>
        <thead><tr>
            <th>{{ $isEn ? 'Tier' : 'ระดับ' }}</th>
            <th>{{ $isEn ? 'Stake Required' : 'Stake ที่ต้องการ' }}</th>
            <th>APY</th>
            <th>{{ $isEn ? 'Lock Period' : 'ระยะล็อก' }}</th>
            <th>{{ $isEn ? 'Max Nodes' : 'โหนดสูงสุด' }}</th>
            <th>{{ $isEn ? 'Slashing' : 'หักลงโทษ' }}</th>
            <th>{{ $isEn ? 'Reward Share' : 'ส่วนแบ่งรางวัล' }}</th>
        </tr></thead>
        <tbody>
            <tr>
                <td><strong>Validator</strong> (Tier 3)</td>
                <td>10,000,000 TPIX</td>
                <td>15-20%</td>
                <td>{{ $isEn ? '180 days' : '180 วัน' }}</td>
                <td>21</td>
                <td>15%</td>
                <td>20%</td>
            </tr>
            <tr>
                <td><strong>Guardian</strong> (Tier 0)</td>
                <td>1,000,000 TPIX</td>
                <td>10-12%</td>
                <td>{{ $isEn ? '90 days' : '90 วัน' }}</td>
                <td>100</td>
                <td>10%</td>
                <td>35%</td>
            </tr>
            <tr>
                <td><strong>Sentinel</strong> (Tier 1)</td>
                <td>100,000 TPIX</td>
                <td>7-9%</td>
                <td>{{ $isEn ? '30 days' : '30 วัน' }}</td>
                <td>500</td>
                <td>5%</td>
                <td>30%</td>
            </tr>
            <tr>
                <td><strong>Light</strong> (Tier 2)</td>
                <td>10,000 TPIX</td>
                <td>4-6%</td>
                <td>{{ $isEn ? '7 days' : '7 วัน' }}</td>
                <td>{{ $isEn ? 'Unlimited' : 'ไม่จำกัด' }}</td>
                <td>0%</td>
                <td>15%</td>
            </tr>
        </tbody>
    </table>
    </div>

    <div class="block">
    <h3>{{ $isEn ? 'Tier Roles' : 'บทบาทของแต่ละระดับ' }}</h3>
    <ul>
        @if($isEn)
        <li><strong>Validator</strong> — Real IBFT 2.0 block sealers who validate and propose blocks. They form the chain's "board of directors" with on-chain governance voting rights. Requires KYC approval.</li>
        <li><strong>Guardian</strong> — Premium master nodes providing enhanced network security and data availability. High uptime expectations with 10% slashing for misbehavior.</li>
        <li><strong>Sentinel</strong> — Standard master nodes accessible to most serious stakers. Moderate lock period with 5% slashing.</li>
        <li><strong>Light</strong> — Entry-level nodes for data relay and network resilience. Minimal stake, no slashing, unlimited nodes — designed for broad participation.</li>
        @else
        <li><strong>Validator</strong> — IBFT 2.0 block sealer ตัวจริงที่ตรวจสอบและเสนอบล็อก เป็น "คณะกรรมการบริหาร" ของเชนมีสิทธิ์โหวต governance บนเชน ต้องผ่าน KYC</li>
        <li><strong>Guardian</strong> — Master node ระดับพรีเมียมให้ความปลอดภัยเครือข่ายและความพร้อมใช้งานของข้อมูล คาดหวัง uptime สูง หัก 10% หากทำผิด</li>
        <li><strong>Sentinel</strong> — Master node มาตรฐานสำหรับ staker ที่จริงจัง ระยะล็อกปานกลาง หัก 5%</li>
        <li><strong>Light</strong> — โหนดระดับเริ่มต้นสำหรับส่งต่อข้อมูลและความยืดหยุ่นเครือข่าย stake น้อย ไม่มีการลงโทษ ไม่จำกัดจำนวน — ออกแบบเพื่อการมีส่วนร่วมอย่างกว้างขวาง</li>
        @endif
    </ul>
    </div>

    <div class="block">
    <h2>5.2 {{ $isEn ? 'Reward Distribution' : 'การแจกจ่ายรางวัล' }}</h2>
    @if($isEn)
    <p>Rewards are distributed proportionally based on tier allocation, uptime score, and the number of active nodes in each tier.</p>
    @else
    <p>รางวัลแจกจ่ายตามสัดส่วนตามการจัดสรรระดับ คะแนน uptime และจำนวนโหนดที่ทำงานอยู่ในแต่ละระดับ</p>
    @endif
    <div class="highlight">
        <p><strong>{{ $isEn ? 'Reward Formula:' : 'สูตรรางวัล:' }}</strong></p>
        <p>Pending Reward = (tierRewardPerSecond &times; elapsedTime) / activeNodesInTier &times; uptimeScore / 10,000</p>
        <p>{{ $isEn ? 'Capped at 30 days maximum to prevent stale accumulation gaming.' : 'จำกัดสูงสุด 30 วันเพื่อป้องกันการเก็บสะสมรางวัลแบบไม่ active' }}</p>
    </div>
    </div>

    <div class="block">
    <h2>5.3 {{ $isEn ? 'Slashing & Penalties' : 'การลงโทษ' }}</h2>
    @if($isEn)
    <p>Nodes that misbehave (downtime, double-signing, invalid blocks) face proportional penalties:</p>
    <ul>
        <li>Validators: 15% of staked amount burned</li>
        <li>Guardians: 10% of staked amount burned</li>
        <li>Sentinels: 5% of staked amount burned</li>
        <li>Light nodes: No slashing (entry-level protection)</li>
    </ul>
    <p>Slashed nodes can withdraw their remaining stake but cannot re-register until penalties clear.</p>
    @else
    <p>โหนดที่ทำผิด (downtime, double-signing, บล็อกไม่ถูกต้อง) จะถูกลงโทษตามสัดส่วน:</p>
    <ul>
        <li>Validator: หัก 15% ของ stake ที่ฝากไว้</li>
        <li>Guardian: หัก 10% ของ stake ที่ฝากไว้</li>
        <li>Sentinel: หัก 5% ของ stake ที่ฝากไว้</li>
        <li>Light node: ไม่มีการลงโทษ (ป้องกันระดับเริ่มต้น)</li>
    </ul>
    <p>โหนดที่ถูกลงโทษสามารถถอน stake ที่เหลือได้ แต่ไม่สามารถลงทะเบียนใหม่จนกว่าการลงโทษจะหมดอายุ</p>
    @endif
    </div>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 6. VALIDATOR GOVERNANCE --}}
    {{-- ================================================================ --}}
    <h1>6. {{ $isEn ? 'Validator Governance' : 'การปกครอง Validator' }}</h1>

    @if($isEn)
    <p>
        The <strong>ValidatorGovernance</strong> smart contract enables on-chain governance exclusively
        for Validator-tier nodes (10M TPIX stake + KYC-approved). Validators act as the chain's
        decision-making body for protocol upgrades, parameter changes, and membership.
    </p>
    @else
    <p>
        Smart contract <strong>ValidatorGovernance</strong> เปิดใช้การปกครองบนเชนเฉพาะสำหรับ
        โหนดระดับ Validator (10M TPIX stake + ผ่าน KYC) Validator ทำหน้าที่เป็นองค์กรตัดสินใจของเชน
        สำหรับการอัปเกรดโปรโตคอล เปลี่ยนพารามิเตอร์ และการเป็นสมาชิก
    </p>
    @endif

    <div class="block">
    <h2>{{ $isEn ? 'Proposal Types' : 'ประเภทข้อเสนอ' }}</h2>
    <table>
        <thead><tr><th>{{ $isEn ? 'Type' : 'ประเภท' }}</th><th>{{ $isEn ? 'Description' : 'คำอธิบาย' }}</th><th>{{ $isEn ? 'Example' : 'ตัวอย่าง' }}</th></tr></thead>
        <tbody>
            <tr><td>AddValidator</td><td>{{ $isEn ? 'Admit new IBFT 2.0 validator' : 'รับ validator IBFT 2.0 ใหม่' }}</td><td>{{ $isEn ? 'New node operator applies, existing validators vote to include' : 'ผู้ดำเนินการโหนดใหม่สมัคร validator ที่มีอยู่โหวตรับเข้า' }}</td></tr>
            <tr><td>RemoveValidator</td><td>{{ $isEn ? 'Remove misbehaving validator' : 'ลบ validator ที่ทำผิด' }}</td><td>{{ $isEn ? 'Validator with persistent downtime removed by peer vote' : 'Validator ที่ downtime บ่อยถูกโหวตออกโดยเพื่อนร่วมเครือข่าย' }}</td></tr>
            <tr><td>ChangeParameter</td><td>{{ $isEn ? 'Adjust protocol parameters' : 'ปรับพารามิเตอร์โปรโตคอล' }}</td><td>{{ $isEn ? 'Modify tier staking requirements, emission rates' : 'เปลี่ยนข้อกำหนด staking ของแต่ละระดับ, อัตราการปล่อย' }}</td></tr>
            <tr><td>UpgradeContract</td><td>{{ $isEn ? 'Deploy new contract version' : 'Deploy contract เวอร์ชันใหม่' }}</td><td>{{ $isEn ? 'Upgrade NodeRegistryV2 or TPIXRouter' : 'อัปเกรด NodeRegistryV2 หรือ TPIXRouter' }}</td></tr>
            <tr><td>General</td><td>{{ $isEn ? 'Free-form governance' : 'Governance ทั่วไป' }}</td><td>{{ $isEn ? 'Strategic decisions, partnership approvals' : 'การตัดสินใจเชิงกลยุทธ์, อนุมัติพาร์ทเนอร์' }}</td></tr>
        </tbody>
    </table>
    </div>

    <div class="block">
    <h2>{{ $isEn ? 'Voting Rules' : 'กฎการโหวต' }}</h2>
    <ul>
        <li><strong>{{ $isEn ? 'Voting Period:' : 'ระยะเวลาโหวต:' }}</strong> {{ $isEn ? '7 days from proposal creation' : '7 วันจากวันสร้างข้อเสนอ' }}</li>
        <li><strong>{{ $isEn ? 'Quorum:' : 'องค์ประชุม:' }}</strong> {{ $isEn ? '>50% of active validators must vote' : '>50% ของ validator ที่ active ต้องโหวต' }}</li>
        <li><strong>{{ $isEn ? 'Approval:' : 'การอนุมัติ:' }}</strong> {{ $isEn ? '>50% of votes must be "for"' : '>50% ของโหวตต้องเป็น "เห็นด้วย"' }}</li>
        <li><strong>Timelock:</strong> {{ $isEn ? '48-hour delay after passing before execution' : 'หน่วงเวลา 48 ชั่วโมงหลังผ่านก่อนดำเนินการ' }}</li>
        <li><strong>{{ $isEn ? 'Execution:' : 'ดำเนินการ:' }}</strong> {{ $isEn ? 'Admin-triggered after timelock' : 'Admin กดดำเนินการหลัง timelock' }}</li>
    </ul>
    </div>

    <div class="block">
    <h2>{{ $isEn ? 'Validator KYC' : 'KYC สำหรับ Validator' }}</h2>
    @if($isEn)
    <p>
        The <strong>ValidatorKYC</strong> contract implements PDPA-compliant identity verification.
        Zero PII is stored on-chain — only a keccak256 hash of the KYC data is recorded.
        Encrypted documents are stored off-chain with access logging and right-to-erasure support.
    </p>
    @else
    <p>
        Contract <strong>ValidatorKYC</strong> ดำเนินการยืนยันตัวตนตาม PDPA
        ไม่มีข้อมูลส่วนบุคคลเก็บบนเชน — เก็บเฉพาะ keccak256 hash ของข้อมูล KYC
        เอกสารที่เข้ารหัสเก็บนอกเชนพร้อมระบบบันทึกการเข้าถึงและรองรับสิทธิ์ลบข้อมูล
    </p>
    @endif
    </div>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 7. LIVING IDENTITY --}}
    {{-- ================================================================ --}}
    <h1>7. {{ $isEn ? 'Living Identity — Seedless Wallet Recovery' : 'Living Identity — กู้กระเป๋าไม่ต้องใช้ Seed Phrase' }}</h1>

    <div class="highlight">
        <p><strong>{{ $isEn ? "World's First On-Chain Seedless Wallet Recovery System" : 'ระบบกู้กระเป๋าบนเชนโดยไม่ต้องใช้ Seed Phrase แห่งแรกของโลก' }}</strong></p>
        <p>{{ $isEn ? 'No more seed phrases. No more lost funds. Your identity is your key.' : 'ไม่ต้องใช้ seed phrase อีกต่อไป ไม่มีการสูญเสียเงินอีก ตัวตนของคุณคือกุญแจ' }}</p>
    </div>

    <div class="block">
    <h2>{{ $isEn ? 'The Innovation' : 'นวัตกรรม' }}</h2>
    @if($isEn)
    <p>
        Living Identity (TPIXIdentity smart contract) allows users to recover wallet access without
        seed phrases by combining three verification factors into a single on-chain proof:
    </p>
    <ul>
        <li><strong>Knowledge factor</strong> — Answers to personal security questions chosen by the user.</li>
        <li><strong>Location factor</strong> — GPS coordinates of locations meaningful to the user.</li>
        <li><strong>Possession factor</strong> — A 6-digit recovery PIN.</li>
    </ul>
    @else
    <p>
        Living Identity (TPIXIdentity smart contract) ให้ผู้ใช้กู้การเข้าถึงกระเป๋าโดยไม่ต้องใช้
        seed phrase ด้วยการรวม 3 ปัจจัยยืนยันเป็นหลักฐานบนเชนเดียว:
    </p>
    <ul>
        <li><strong>ปัจจัยความรู้</strong> — คำตอบคำถามความปลอดภัยส่วนตัวที่ผู้ใช้เลือก</li>
        <li><strong>ปัจจัยสถานที่</strong> — พิกัด GPS ของสถานที่ที่มีความหมายกับผู้ใช้</li>
        <li><strong>ปัจจัยการครอบครอง</strong> — PIN กู้คืน 6 หลัก</li>
    </ul>
    @endif
    </div>

    <div class="block">
    <h2>{{ $isEn ? 'How It Works' : 'วิธีการทำงาน' }}</h2>
    <table>
        <thead><tr><th>{{ $isEn ? 'Step' : 'ขั้นตอน' }}</th><th>{{ $isEn ? 'Action' : 'การดำเนินการ' }}</th><th>{{ $isEn ? 'On-Chain Data' : 'ข้อมูลบนเชน' }}</th></tr></thead>
        <tbody>
            <tr><td>1. {{ $isEn ? 'Register' : 'ลงทะเบียน' }}</td><td>{{ $isEn ? 'User sets security questions + GPS locations + recovery PIN' : 'ผู้ใช้ตั้งคำถามความปลอดภัย + สถานที่ GPS + PIN กู้คืน' }}</td><td>{{ $isEn ? 'Only 32-byte keccak256 hash stored' : 'เก็บเฉพาะ keccak256 hash 32 bytes' }}</td></tr>
            <tr><td>2. {{ $isEn ? 'Loss Event' : 'เหตุการณ์สูญหาย' }}</td><td>{{ $isEn ? 'User loses device or seed phrase' : 'ผู้ใช้สูญเสียอุปกรณ์หรือ seed phrase' }}</td><td>{{ $isEn ? 'No action needed' : 'ไม่ต้องดำเนินการ' }}</td></tr>
            <tr><td>3. {{ $isEn ? 'Recovery Request' : 'ขอกู้คืน' }}</td><td>{{ $isEn ? 'User answers questions + stands at GPS location + enters PIN' : 'ผู้ใช้ตอบคำถาม + ยืนที่ตำแหน่ง GPS + กรอก PIN' }}</td><td>{{ $isEn ? 'Proof submitted, 48-hour timelock starts' : 'ส่งหลักฐาน เริ่ม timelock 48 ชั่วโมง' }}</td></tr>
            <tr><td>4. {{ $isEn ? 'Safety Window' : 'หน้าต่างความปลอดภัย' }}</td><td>{{ $isEn ? 'Original owner can cancel within 48 hours (theft protection)' : 'เจ้าของเดิมสามารถยกเลิกภายใน 48 ชั่วโมง (ป้องกันการโจรกรรม)' }}</td><td>{{ $isEn ? 'Cancel transaction reverts recovery' : 'ธุรกรรมยกเลิกจะย้อนกลับการกู้คืน' }}</td></tr>
            <tr><td>5. {{ $isEn ? 'Execute' : 'ดำเนินการ' }}</td><td>{{ $isEn ? 'After 48 hours, wallet control transfers to new address' : 'หลัง 48 ชั่วโมง ควบคุมกระเป๋าโอนไปที่อยู่ใหม่' }}</td><td>{{ $isEn ? 'Ownership updated on-chain' : 'อัปเดตความเป็นเจ้าของบนเชน' }}</td></tr>
        </tbody>
    </table>
    </div>

    <div class="block">
    <h2>{{ $isEn ? 'Security Properties' : 'คุณสมบัติด้านความปลอดภัย' }}</h2>
    <ul>
        @if($isEn)
        <li><strong>Zero knowledge on-chain</strong> — Only a 32-byte hash, no personal data retrievable from blockchain.</li>
        <li><strong>48-hour timelock</strong> — Prevents immediate theft even if all factors are compromised.</li>
        <li><strong>Multi-factor</strong> — Requires knowledge + location + PIN simultaneously.</li>
        <li><strong>Free to use</strong> — Zero gas fees make registration and recovery cost nothing.</li>
        <li><strong>Updateable</strong> — Users can change their security factors at any time.</li>
        @else
        <li><strong>Zero knowledge บนเชน</strong> — เก็บเฉพาะ hash 32 bytes ไม่มีข้อมูลส่วนตัวที่ดึงจากบล็อกเชนได้</li>
        <li><strong>Timelock 48 ชั่วโมง</strong> — ป้องกันการโจรกรรมทันทีแม้ทุกปัจจัยจะถูกเจาะ</li>
        <li><strong>Multi-factor</strong> — ต้องใช้ความรู้ + สถานที่ + PIN พร้อมกัน</li>
        <li><strong>ใช้ฟรี</strong> — ค่า gas เป็นศูนย์ทำให้การลงทะเบียนและกู้คืนไม่มีค่าใช้จ่าย</li>
        <li><strong>อัปเดตได้</strong> — ผู้ใช้สามารถเปลี่ยนปัจจัยความปลอดภัยได้ทุกเมื่อ</li>
        @endif
    </ul>
    </div>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 8. TPIX TRADE DEX --}}
    {{-- ================================================================ --}}
    <h1>8. {{ $isEn ? 'TPIX TRADE DEX' : 'กระดานแลกเปลี่ยน TPIX TRADE' }}</h1>

    @if($isEn)
    <p>
        TPIX TRADE is a decentralized exchange combining an internal order book matching engine
        with Uniswap V2-compatible AMM pools, providing both precision price execution and deep
        liquidity in a single platform.
    </p>
    @else
    <p>
        TPIX TRADE เป็นกระดานแลกเปลี่ยนกระจายอำนาจที่ผสมผสานระบบจับคู่ order book ภายใน
        กับ AMM pool ที่เข้ากันได้กับ Uniswap V2 ให้ทั้งการดำเนินการราคาที่แม่นยำและสภาพคล่องลึก
        ในแพลตฟอร์มเดียว
    </p>
    @endif

    <div class="block">
    <h2>8.1 {{ $isEn ? 'Hybrid Order Book + AMM' : 'Order Book + AMM แบบผสม' }}</h2>
    <table>
        <thead><tr>
            <th>{{ $isEn ? 'Feature' : 'คุณสมบัติ' }}</th>
            <th>{{ $isEn ? 'Order Book (Internal)' : 'Order Book (ภายใน)' }}</th>
            <th>AMM (Uniswap V2)</th>
        </tr></thead>
        <tbody>
            <tr><td>{{ $isEn ? 'Order Types' : 'ประเภทคำสั่ง' }}</td><td>Limit, Market, Stop-Limit</td><td>{{ $isEn ? 'Market only' : 'Market เท่านั้น' }}</td></tr>
            <tr><td>{{ $isEn ? 'Price Discovery' : 'การค้นหาราคา' }}</td><td>{{ $isEn ? 'Price-time priority matching' : 'จับคู่ตามราคาและเวลา' }}</td><td>Constant product (x &times; y = k)</td></tr>
            <tr><td>Slippage</td><td>{{ $isEn ? 'Zero (limit orders)' : 'ศูนย์ (limit orders)' }}</td><td>{{ $isEn ? 'Variable based on pool depth' : 'แปรผันตามความลึกของ pool' }}</td></tr>
            <tr><td>{{ $isEn ? 'Best For' : 'เหมาะกับ' }}</td><td>{{ $isEn ? 'Precise entries, large orders' : 'เข้าซื้อแม่นยำ, ออเดอร์ใหญ่' }}</td><td>{{ $isEn ? 'Quick swaps, small amounts' : 'สลับเร็ว, จำนวนน้อย' }}</td></tr>
            <tr><td>{{ $isEn ? 'Settlement' : 'การชำระ' }}</td><td>{{ $isEn ? 'Internal DB + on-chain confirmation' : 'DB ภายใน + ยืนยันบนเชน' }}</td><td>{{ $isEn ? 'Fully on-chain' : 'บนเชนทั้งหมด' }}</td></tr>
        </tbody>
    </table>
    </div>

    <div class="block">
    <h2>8.2 {{ $isEn ? 'Fee Structure' : 'โครงสร้างค่าธรรมเนียม' }}</h2>
    <table>
        <thead><tr>
            <th>{{ $isEn ? 'Fee Type' : 'ประเภทค่าธรรมเนียม' }}</th>
            <th>{{ $isEn ? 'Rate' : 'อัตรา' }}</th>
            <th>{{ $isEn ? 'Distribution' : 'การแจกจ่าย' }}</th>
        </tr></thead>
        <tbody>
            <tr><td>Swap Fee (AMM)</td><td>0.3%</td><td>{{ $isEn ? '0.25% to LPs, 0.05% to Protocol Treasury' : '0.25% ให้ LP, 0.05% ให้คลัง Protocol' }}</td></tr>
            <tr><td>Maker Fee (Order Book)</td><td>0.1%</td><td>{{ $isEn ? 'Collected by platform' : 'แพลตฟอร์มเก็บ' }}</td></tr>
            <tr><td>Taker Fee (Order Book)</td><td>0.2%</td><td>{{ $isEn ? 'Collected by platform' : 'แพลตฟอร์มเก็บ' }}</td></tr>
            <tr><td>Bridge Fee</td><td>0.1%</td><td>{{ $isEn ? '90% to Treasury, 10% burned' : '90% ให้คลัง, 10% ถูก burn' }}</td></tr>
        </tbody>
    </table>
    @if($isEn)
    <p>
        Fee rates are configurable per chain, per trading pair, and globally — with a hierarchical
        override system. Maximum fee cap of 5% enforced by smart contract to protect users.
    </p>
    @else
    <p>
        อัตราค่าธรรมเนียมปรับได้ต่อเชน ต่อคู่เทรด และทั่วโลก — ด้วยระบบ override แบบลำดับชั้น
        ค่าธรรมเนียมสูงสุด 5% บังคับโดย smart contract เพื่อปกป้องผู้ใช้
    </p>
    @endif
    </div>

    <div class="block">
    <h2>8.3 TPIXRouter Smart Contract</h2>
    @if($isEn)
    <p>
        The TPIXRouter is a fee-collection wrapper around any Uniswap V2-compatible router.
        It deducts platform fees from the input amount before forwarding the swap to the
        underlying DEX.
    </p>
    <ul>
        <li><strong>Basis-point precision</strong> — Fees in basis points (1 bp = 0.01%)</li>
        <li><strong>Max fee cap</strong> — 500 bp (5%) hardcoded in contract</li>
        <li><strong>ReentrancyGuard</strong> — Protection against reentrancy attacks</li>
        <li><strong>Pausable</strong> — Emergency circuit breaker for admin</li>
        <li><strong>SafeERC20</strong> — Handles non-standard token implementations</li>
    </ul>
    @else
    <p>
        TPIXRouter เป็น wrapper เก็บค่าธรรมเนียมรอบ router ที่เข้ากันกับ Uniswap V2
        หักค่าธรรมเนียมจากจำนวนอินพุตก่อนส่งต่อการ swap ไปยัง DEX พื้นฐาน
    </p>
    <ul>
        <li><strong>ความแม่นยำ Basis-point</strong> — ค่าธรรมเนียมเป็น basis points (1 bp = 0.01%)</li>
        <li><strong>ค่าธรรมเนียมสูงสุด</strong> — 500 bp (5%) กำหนดตายตัวใน contract</li>
        <li><strong>ReentrancyGuard</strong> — ป้องกันการโจมตี reentrancy</li>
        <li><strong>Pausable</strong> — ระบบหยุดฉุกเฉินสำหรับ admin</li>
        <li><strong>SafeERC20</strong> — จัดการ token implementations ที่ไม่เป็นมาตรฐาน</li>
    </ul>
    @endif
    </div>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 9. CROSS-CHAIN BRIDGE --}}
    {{-- ================================================================ --}}
    <h1>9. {{ $isEn ? 'Cross-Chain Bridge' : 'สะพานข้ามเชน' }}</h1>

    @if($isEn)
    <p>
        The TPIX Bridge enables seamless asset transfer between TPIX Chain (native TPIX) and
        BNB Smart Chain (wTPIX, a BEP-20 token). This allows TPIX holders to access BSC's
        DeFi ecosystem while maintaining the ability to bridge back to TPIX Chain.
    </p>
    @else
    <p>
        TPIX Bridge เปิดให้โอนสินทรัพย์อย่างราบรื่นระหว่าง TPIX Chain (native TPIX) และ
        BNB Smart Chain (wTPIX, โทเคน BEP-20) ทำให้ผู้ถือ TPIX เข้าถึงระบบ DeFi ของ BSC
        พร้อมสามารถ bridge กลับ TPIX Chain ได้
    </p>
    @endif

    <div class="block">
    <h2>{{ $isEn ? 'Bridge Mechanics' : 'กลไก Bridge' }}</h2>
    <table>
        <thead><tr><th>{{ $isEn ? 'Direction' : 'ทิศทาง' }}</th><th>{{ $isEn ? 'Action' : 'การดำเนินการ' }}</th><th>{{ $isEn ? 'Result' : 'ผลลัพธ์' }}</th></tr></thead>
        <tbody>
            <tr><td>BSC &#8594; TPIX Chain</td><td>{{ $isEn ? 'Burn wTPIX on BSC' : 'Burn wTPIX บน BSC' }}</td><td>{{ $isEn ? 'Mint native TPIX on TPIX Chain' : 'Mint native TPIX บน TPIX Chain' }}</td></tr>
            <tr><td>TPIX Chain &#8594; BSC</td><td>{{ $isEn ? 'Lock native TPIX on TPIX Chain' : 'ล็อก native TPIX บน TPIX Chain' }}</td><td>{{ $isEn ? 'Mint wTPIX on BSC' : 'Mint wTPIX บน BSC' }}</td></tr>
        </tbody>
    </table>
    </div>

    <div class="block">
    <h2>wTPIX (Wrapped TPIX) — BEP-20</h2>
    <ul>
        <li><strong>{{ $isEn ? 'Max Supply Cap:' : 'จำนวนสูงสุด:' }}</strong> 700,000,000 wTPIX (10% {{ $isEn ? 'of total TPIX supply' : 'ของจำนวน TPIX ทั้งหมด' }})</li>
        <li><strong>{{ $isEn ? 'Standard:' : 'มาตรฐาน:' }}</strong> ERC-20 + Burnable on BNB Smart Chain</li>
        <li><strong>{{ $isEn ? 'Minter Roles:' : 'สิทธิ์ Mint:' }}</strong> {{ $isEn ? 'TokenSale contract and Bridge contract only' : 'เฉพาะ contract TokenSale และ Bridge เท่านั้น' }}</li>
        <li><strong>{{ $isEn ? 'Bridge Fee:' : 'ค่าธรรมเนียม Bridge:' }}</strong> 0.1% (90% {{ $isEn ? 'to treasury' : 'ให้คลัง' }}, 10% {{ $isEn ? 'permanently burned' : 'ถูก burn ถาวร' }})</li>
    </ul>
    <div class="highlight-info">
        <p><strong>{{ $isEn ? 'Supply Integrity:' : 'ความสมบูรณ์ของจำนวน:' }}</strong>
        {{ $isEn
            ? 'The total of native TPIX + wTPIX always equals 7 billion. When wTPIX is minted on BSC, the equivalent native TPIX is locked on TPIX Chain, and vice versa.'
            : 'จำนวนรวมของ native TPIX + wTPIX จะเท่ากับ 7 พันล้านเสมอ เมื่อ wTPIX ถูก mint บน BSC จำนวน native TPIX ที่เทียบเท่าจะถูกล็อกบน TPIX Chain และในทางกลับกัน'
        }}</p>
    </div>
    </div>

    {{-- ================================================================ --}}
    {{-- 10. TOKEN FACTORY --}}
    {{-- ================================================================ --}}
    <h1>10. {{ $isEn ? 'Token Factory' : 'โรงงานสร้างโทเคน' }}</h1>

    @if($isEn)
    <p>
        The TPIX Token Factory allows anyone to create custom ERC-20 tokens on TPIX Chain
        through a simple web interface — no coding required.
    </p>
    @else
    <p>
        โรงงานสร้างโทเคน TPIX ให้ทุกคนสร้างโทเคน ERC-20 แบบกำหนดเองบน TPIX Chain
        ผ่านเว็บอินเทอร์เฟซง่ายๆ — ไม่ต้องเขียนโค้ด
    </p>
    @endif

    <div class="block">
    <table>
        <thead><tr><th>{{ $isEn ? 'Feature' : 'คุณสมบัติ' }}</th><th>{{ $isEn ? 'Details' : 'รายละเอียด' }}</th></tr></thead>
        <tbody>
            <tr><td>{{ $isEn ? 'Creation Fee' : 'ค่าสร้าง' }}</td><td>100 TPIX (50% burned, 50% {{ $isEn ? 'to treasury' : 'ให้คลัง' }})</td></tr>
            <tr><td>{{ $isEn ? 'Token Types' : 'ประเภทโทเคน' }}</td><td>Standard, Mintable, Burnable, Mintable+Burnable</td></tr>
            <tr><td>{{ $isEn ? 'Supply Range' : 'ช่วงจำนวน' }}</td><td>{{ $isEn ? '1 to 1,000,000,000,000 tokens' : '1 ถึง 1,000,000,000,000 โทเคน' }}</td></tr>
            <tr><td>{{ $isEn ? 'Features' : 'ฟีเจอร์' }}</td><td>ERC-20, Permit (EIP-2612), {{ $isEn ? 'optional Freeze' : 'Freeze ตามเลือก' }}</td></tr>
            <tr><td>{{ $isEn ? 'Verification' : 'การยืนยัน' }}</td><td>{{ $isEn ? 'Auto-verified on Block Explorer' : 'ยืนยันอัตโนมัติบน Block Explorer' }}</td></tr>
            <tr><td>{{ $isEn ? 'Gas Cost' : 'ค่า Gas' }}</td><td>{{ $isEn ? 'Free (zero gas on TPIX Chain)' : 'ฟรี (zero gas บน TPIX Chain)' }}</td></tr>
        </tbody>
    </table>
    </div>

    <div class="block">
    <h2>{{ $isEn ? 'Use Cases' : 'กรณีการใช้งาน' }}</h2>
    <ul>
        @if($isEn)
        <li><strong>Business Loyalty Points</strong> — Restaurants, shops, and brands create their own reward tokens.</li>
        <li><strong>Community/DAO Tokens</strong> — Governance tokens for decentralized organizations.</li>
        <li><strong>Real-World Assets (RWA)</strong> — Tokenized property, equity, or commodities.</li>
        <li><strong>Carbon Credits</strong> — Tokenized emission reductions (see Section 12).</li>
        <li><strong>NFT Project Tokens</strong> — Utility tokens for NFT communities.</li>
        @else
        <li><strong>โปรแกรมสะสมแต้มธุรกิจ</strong> — ร้านอาหาร, ร้านค้า และแบรนด์สร้างโทเคนรางวัลของตัวเอง</li>
        <li><strong>โทเคน Community/DAO</strong> — โทเคน governance สำหรับองค์กรกระจายอำนาจ</li>
        <li><strong>สินทรัพย์โลกจริง (RWA)</strong> — โทเคไนซ์อสังหาริมทรัพย์, หุ้น หรือสินค้าโภคภัณฑ์</li>
        <li><strong>คาร์บอนเครดิต</strong> — โทเคไนซ์การลดการปล่อยก๊าซ (ดูหัวข้อ 12)</li>
        <li><strong>โทเคนโปรเจค NFT</strong> — Utility token สำหรับชุมชน NFT</li>
        @endif
    </ul>
    </div>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 11. TOKEN SALE DETAILS --}}
    {{-- ================================================================ --}}
    <h1>11. {{ $isEn ? 'Token Sale Details' : 'รายละเอียดการขายโทเคน' }}</h1>

    @if($isEn)
    <p>
        The TPIX token sale is conducted in 3 phases on BNB Smart Chain, accepting BNB, USDT,
        BUSD, and USDC. Purchased tokens are allocated as wTPIX (BEP-20) with a vesting schedule,
        bridgeable to native TPIX once the cross-chain bridge is operational.
    </p>
    @else
    <p>
        การขายโทเคน TPIX ดำเนินการใน 3 เฟสบน BNB Smart Chain รับ BNB, USDT, BUSD และ USDC
        โทเคนที่ซื้อจัดสรรเป็น wTPIX (BEP-20) พร้อมตาราง vesting สามารถ bridge ไป native TPIX
        เมื่อสะพานข้ามเชนเปิดใช้งาน
    </p>
    @endif

    <div class="block">
    <table>
        <thead><tr>
            <th>{{ $isEn ? 'Phase' : 'เฟส' }}</th>
            <th>{{ $isEn ? 'Price (USD)' : 'ราคา (USD)' }}</th>
            <th>{{ $isEn ? 'Allocation' : 'จัดสรร' }}</th>
            <th>TGE Unlock</th>
            <th>Vesting</th>
        </tr></thead>
        <tbody>
            <tr><td>Private Sale</td><td>$0.05</td><td>100,000,000 TPIX</td><td>10%</td><td>{{ $isEn ? '30-day cliff, 180-day linear' : '30 วัน cliff, 180 วัน linear' }}</td></tr>
            <tr><td>Pre-Sale</td><td>$0.08</td><td>200,000,000 TPIX</td><td>15%</td><td>{{ $isEn ? '14-day cliff, 120-day linear' : '14 วัน cliff, 120 วัน linear' }}</td></tr>
            <tr><td>Public Sale</td><td>$0.10</td><td>400,000,000 TPIX</td><td>25%</td><td>{{ $isEn ? 'No cliff, 90-day linear' : 'ไม่มี cliff, 90 วัน linear' }}</td></tr>
        </tbody>
    </table>
    </div>

    <div class="block">
    <h2>{{ $isEn ? 'Token Sale Smart Contract' : 'Smart Contract ขายโทเคน' }} (TPIXTokenSale.sol)</h2>
    @if($isEn)
    <p>
        Deployed on BSC, the token sale contract receives BNB directly (fallback function)
        or ERC-20 tokens via <code>purchaseWithToken()</code>. Funds are forwarded immediately
        to the treasury wallet. Token allocation is recorded off-chain and verified by the
        backend before wTPIX distribution.
    </p>
    @else
    <p>
        Deploy บน BSC contract ขายโทเคนรับ BNB โดยตรง (fallback function) หรือ
        ERC-20 tokens ผ่าน <code>purchaseWithToken()</code> เงินถูกส่งต่อทันทีไปยัง treasury wallet
        การจัดสรรโทเคนบันทึกนอกเชนและตรวจสอบโดย backend ก่อนแจกจ่าย wTPIX
    </p>
    @endif
    </div>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 12. REAL-WORLD APPLICATIONS --}}
    {{-- ================================================================ --}}
    <h1>12. {{ $isEn ? 'Real-World Applications' : 'แอปพลิเคชันในโลกจริง' }}</h1>

    <h2>12.1 {{ $isEn ? 'Carbon Credit Trading' : 'ตลาดซื้อขายคาร์บอนเครดิต' }}</h2>
    @if($isEn)
    <p>
        TPIX Chain provides transparent, verified carbon credit trading with IoT sensor integration.
        Zero gas fees make fractional trading (minimum 0.001 tCO&#x2082;e) economically viable for
        individuals and small businesses — not just corporations.
    </p>
    @else
    <p>
        TPIX Chain ให้บริการตลาดคาร์บอนเครดิตที่โปร่งใส ยืนยันแล้ว พร้อมการเชื่อมต่อเซ็นเซอร์ IoT
        ค่า gas เป็นศูนย์ทำให้การซื้อขายแบบเศษส่วน (ขั้นต่ำ 0.001 tCO&#x2082;e) คุ้มค่าทางเศรษฐกิจ
        สำหรับบุคคลและธุรกิจขนาดเล็ก — ไม่ใช่เฉพาะบริษัทใหญ่
    </p>
    @endif

    <div class="block">
    <h3>{{ $isEn ? 'Supported Standards' : 'มาตรฐานที่รองรับ' }}</h3>
    <ul>
        <li><strong>VCS</strong> (Verified Carbon Standard) — {{ $isEn ? 'Largest voluntary carbon market' : 'ตลาดคาร์บอนอาสาสมัครที่ใหญ่ที่สุด' }}</li>
        <li><strong>Gold Standard</strong> — {{ $isEn ? 'Premium market with UN SDG co-benefits' : 'ตลาดพรีเมียมพร้อมประโยชน์ร่วม UN SDG' }}</li>
        <li><strong>CDM</strong> (Clean Development Mechanism) — {{ $isEn ? 'UN/UNFCCC developing nation projects' : 'โครงการประเทศกำลังพัฒนา UN/UNFCCC' }}</li>
        <li><strong>ACR</strong> (American Carbon Registry) — {{ $isEn ? 'North American compliance market' : 'ตลาดมาตรฐานอเมริกาเหนือ' }}</li>
    </ul>
    </div>

    <div class="block">
    <h3>{{ $isEn ? 'Blockchain Advantages' : 'ข้อดีของบล็อกเชน' }}</h3>
    <table>
        <thead><tr><th>{{ $isEn ? 'Feature' : 'คุณสมบัติ' }}</th><th>{{ $isEn ? 'Traditional' : 'แบบดั้งเดิม' }}</th><th>TPIX Chain</th></tr></thead>
        <tbody>
            <tr><td>{{ $isEn ? 'Double-counting' : 'นับซ้ำ' }}</td><td>{{ $isEn ? 'Possible (registry errors)' : 'เป็นไปได้ (ข้อผิดพลาด registry)' }}</td><td>{{ $isEn ? 'Impossible (unique on-chain token)' : 'เป็นไปไม่ได้ (โทเคนบนเชนไม่ซ้ำ)' }}</td></tr>
            <tr><td>{{ $isEn ? 'Verification' : 'การยืนยัน' }}</td><td>{{ $isEn ? 'Months, expensive audits' : 'หลายเดือน ตรวจสอบแพง' }}</td><td>{{ $isEn ? 'Real-time IoT data on-chain' : 'ข้อมูล IoT แบบเรียลไทม์บนเชน' }}</td></tr>
            <tr><td>{{ $isEn ? 'Trading Fee' : 'ค่าธรรมเนียมซื้อขาย' }}</td><td>5-15% ({{ $isEn ? 'brokers' : 'โบรกเกอร์' }})</td><td>2% (P2P {{ $isEn ? 'marketplace' : 'ตลาด' }})</td></tr>
            <tr><td>{{ $isEn ? 'Minimum Trade' : 'ซื้อขายขั้นต่ำ' }}</td><td>1 tCO&#x2082;e ($10-50)</td><td>0.001 tCO&#x2082;e ({{ $isEn ? 'fractional' : 'เศษส่วน' }})</td></tr>
            <tr><td>{{ $isEn ? 'Settlement' : 'การชำระ' }}</td><td>{{ $isEn ? 'Days to weeks' : 'หลายวันถึงสัปดาห์' }}</td><td>{{ $isEn ? '2 seconds' : '2 วินาที' }}</td></tr>
            <tr><td>{{ $isEn ? 'Retirement Proof' : 'หลักฐานการเกษียณ' }}</td><td>{{ $isEn ? 'PDF certificate' : 'ใบรับรอง PDF' }}</td><td>{{ $isEn ? 'Immutable on-chain NFT' : 'NFT บนเชนเปลี่ยนแปลงไม่ได้' }}</td></tr>
        </tbody>
    </table>
    </div>

    <div class="block">
    <h2>12.2 {{ $isEn ? 'Food Passport Traceability' : 'ระบบตรวจสอบย้อนกลับอาหาร' }}</h2>
    @if($isEn)
    <p>
        The Food Passport system tracks food products from farm to consumer using blockchain-verified
        records and IoT sensors at each stage of the supply chain. Every product receives a unique
        on-chain identity that consumers can verify by scanning a QR code.
    </p>
    <ul>
        <li><strong>Farm origin</strong> — GPS-verified farm location, crop type, harvest date.</li>
        <li><strong>Processing</strong> — Temperature, handling, quality certification.</li>
        <li><strong>Transport</strong> — Cold chain monitoring, route tracking.</li>
        <li><strong>Retail</strong> — Shelf date, storage conditions, expiry verification.</li>
    </ul>
    <p>
        All data is immutable on TPIX Chain. Zero gas fees mean even small farmers can participate
        without cost barriers — a critical factor for adoption in Southeast Asian agricultural markets.
    </p>
    @else
    <p>
        ระบบ Food Passport ติดตามผลิตภัณฑ์อาหารจากฟาร์มถึงผู้บริโภคด้วยบันทึกที่ยืนยันโดยบล็อกเชน
        และเซ็นเซอร์ IoT ในทุกขั้นตอนของ supply chain ทุกผลิตภัณฑ์ได้รับตัวตนบนเชนที่ไม่ซ้ำ
        ซึ่งผู้บริโภคสามารถตรวจสอบได้โดยการสแกน QR code
    </p>
    <ul>
        <li><strong>แหล่งกำเนิดฟาร์ม</strong> — ตำแหน่งฟาร์มยืนยันด้วย GPS, ประเภทพืช, วันเก็บเกี่ยว</li>
        <li><strong>การแปรรูป</strong> — อุณหภูมิ, การจัดการ, ใบรับรองคุณภาพ</li>
        <li><strong>การขนส่ง</strong> — ตรวจสอบ cold chain, ติดตามเส้นทาง</li>
        <li><strong>ค้าปลีก</strong> — วันวางขาย, สภาพการจัดเก็บ, ตรวจสอบวันหมดอายุ</li>
    </ul>
    <p>
        ข้อมูลทั้งหมดเปลี่ยนแปลงไม่ได้บน TPIX Chain ค่า gas เป็นศูนย์หมายความว่าแม้เกษตรกรรายเล็ก
        ก็สามารถเข้าร่วมได้โดยไม่มีอุปสรรคด้านต้นทุน — ปัจจัยสำคัญสำหรับการยอมรับในตลาดเกษตรเอเชียตะวันออกเฉียงใต้
    </p>
    @endif
    </div>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 13. PRODUCTS & APPLICATIONS --}}
    {{-- ================================================================ --}}
    <h1>13. {{ $isEn ? 'Products & Applications' : 'ผลิตภัณฑ์และแอปพลิเคชัน' }}</h1>

    <table>
        <thead><tr>
            <th>{{ $isEn ? 'Product' : 'ผลิตภัณฑ์' }}</th>
            <th>{{ $isEn ? 'Platform' : 'แพลตฟอร์ม' }}</th>
            <th>{{ $isEn ? 'Key Features' : 'คุณสมบัติหลัก' }}</th>
        </tr></thead>
        <tbody>
            <tr>
                <td><strong>TPIX Wallet</strong></td>
                <td>Flutter (iOS/Android)</td>
                <td>{{ $isEn ? 'HD wallet (BIP-39/44), Living Identity recovery, QR scanner, AES-256 encryption, bilingual (Thai/English), up to 128 wallets' : 'HD wallet (BIP-39/44), กู้ Living Identity, สแกน QR, เข้ารหัส AES-256, สองภาษา (ไทย/อังกฤษ), สูงสุด 128 กระเป๋า' }}</td>
            </tr>
            <tr>
                <td><strong>Master Node UI</strong></td>
                <td>Electron (Windows)</td>
                <td>{{ $isEn ? 'One-click node setup, multi-node management, real-time dashboard, Leaflet map, reward tracking, auto-update via GitHub' : 'ติดตั้งโหนด 1 คลิก, จัดการหลายโหนด, แดชบอร์ดเรียลไทม์, แผนที่ Leaflet, ติดตามรางวัล, อัปเดตอัตโนมัติ' }}</td>
            </tr>
            <tr>
                <td><strong>TPIX TRADE</strong></td>
                <td>Web (Laravel 11 + Vue 3)</td>
                <td>{{ $isEn ? 'Hybrid order book + AMM, limit/market/stop-limit orders, real-time charts, admin panel, mobile app (React Native)' : 'Order book + AMM แบบผสม, limit/market/stop-limit, กราฟเรียลไทม์, แอดมินแพเนล, แอปมือถือ (React Native)' }}</td>
            </tr>
            <tr>
                <td><strong>Block Explorer</strong></td>
                <td>Blockscout</td>
                <td>{{ $isEn ? 'Transaction viewer, contract verification, token tracking, API access' : 'ดูธุรกรรม, ยืนยัน contract, ติดตามโทเคน, เข้าถึง API' }}</td>
            </tr>
            <tr>
                <td><strong>Token Factory</strong></td>
                <td>Web</td>
                <td>{{ $isEn ? 'No-code ERC-20 creation, 100 TPIX fee, auto-verified, immediately tradeable' : 'สร้าง ERC-20 ไม่ต้องเขียนโค้ด, ค่าธรรมเนียม 100 TPIX, ยืนยันอัตโนมัติ, เทรดได้ทันที' }}</td>
            </tr>
            <tr>
                <td><strong>{{ $isEn ? 'Carbon Credits' : 'คาร์บอนเครดิต' }}</strong></td>
                <td>Web + IoT</td>
                <td>{{ $isEn ? 'VCS/Gold Standard support, IoT verification, P2P marketplace, NFT retirement certificates' : 'รองรับ VCS/Gold Standard, ยืนยัน IoT, ตลาด P2P, ใบรับรอง NFT' }}</td>
            </tr>
            <tr>
                <td><strong>Food Passport</strong></td>
                <td>Web + IoT</td>
                <td>{{ $isEn ? 'Farm-to-table traceability, QR verification, cold chain monitoring' : 'ตรวจสอบย้อนกลับจากฟาร์มถึงโต๊ะ, ยืนยัน QR, ตรวจสอบ cold chain' }}</td>
            </tr>
        </tbody>
    </table>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 14. ROADMAP --}}
    {{-- ================================================================ --}}
    <h1>14. {{ $isEn ? 'Roadmap' : 'แผนงาน' }}</h1>

    <div class="block">
    <h2>2023-2024 — {{ $isEn ? 'Development & Infrastructure' : 'พัฒนาและโครงสร้างพื้นฐาน' }} &#10003;</h2>
    <ul>
        @if($isEn)
        <li>Whitepaper & tokenomics design, team formation</li>
        <li>Polygon Edge core: TPIX native coin (7B supply), IBFT 2.0 consensus, EVM</li>
        <li>Testnet deployment (Chain ID 4290), Block Explorer (Blockscout)</li>
        <li>Laravel platform integration, REST API (500+ endpoints)</li>
        <li>DEX smart contracts: TPIXRouter, Factory, Pair</li>
        <li>Master Node system (NodeRegistryV2 — 4 tiers), ValidatorGovernance, ValidatorKYC</li>
        @else
        <li>ออกแบบ Whitepaper & tokenomics, จัดตั้งทีม</li>
        <li>Polygon Edge core: เหรียญ TPIX (7B supply), IBFT 2.0 consensus, EVM</li>
        <li>Deploy Testnet (Chain ID 4290), Block Explorer (Blockscout)</li>
        <li>เชื่อมต่อแพลตฟอร์ม Laravel, REST API (500+ endpoints)</li>
        <li>DEX smart contracts: TPIXRouter, Factory, Pair</li>
        <li>ระบบ Master Node (NodeRegistryV2 — 4 ระดับ), ValidatorGovernance, ValidatorKYC</li>
        @endif
    </ul>
    </div>

    <div class="block">
    <h2>2025 — {{ $isEn ? 'Mainnet & Products' : 'Mainnet และผลิตภัณฑ์' }} &#10003;</h2>
    <ul>
        @if($isEn)
        <li>TPIX Chain mainnet live (Chain ID 4289, 4 IBFT validators)</li>
        <li>TPIX TRADE DEX platform launch</li>
        <li>wTPIX (BEP-20) bridge contract on BSC</li>
        <li>Token Sale contract (TPIXTokenSale.sol)</li>
        <li>Living Identity — seedless wallet recovery (TPIXIdentity.sol)</li>
        <li>Master Node UI desktop app (Electron) & TPIX Wallet mobile (Flutter)</li>
        <li>Token Factory — permissionless ERC-20 creation</li>
        @else
        <li>เปิด TPIX Chain mainnet (Chain ID 4289, 4 IBFT validators)</li>
        <li>เปิดแพลตฟอร์ม TPIX TRADE DEX</li>
        <li>wTPIX (BEP-20) bridge contract บน BSC</li>
        <li>Token Sale contract (TPIXTokenSale.sol)</li>
        <li>Living Identity — กู้กระเป๋าไม่ต้องใช้ Seed Phrase (TPIXIdentity.sol)</li>
        <li>Master Node UI แอปเดสก์ท็อป (Electron) & TPIX Wallet มือถือ (Flutter)</li>
        <li>Token Factory — สร้างเหรียญ ERC-20 ฟรี</li>
        @endif
    </ul>
    </div>

    <div class="block">
    <h2>Q1 2026 — {{ $isEn ? 'Production & Token Sale' : 'Production และขายโทเคน' }} &#9679;</h2>
    <ul>
        @if($isEn)
        <li>Token Sale — 3 phases (Private, Pre-Sale, Public) on BSC</li>
        <li>Whitepaper v2.0 publication</li>
        <li>Internal order book matching engine (limit / market / stop-limit)</li>
        <li>Admin panel — fee management, trading dashboard analytics</li>
        <li>Carbon Credit trading system & FoodPassport traceability</li>
        @else
        <li>Token Sale — 3 เฟส (Private, Pre-Sale, Public) บน BSC</li>
        <li>เผยแพร่ Whitepaper v2.0</li>
        <li>ระบบจับคู่ order book ภายใน (limit / market / stop-limit)</li>
        <li>แอดมินแพเนล — จัดการค่าธรรมเนียม, dashboard analytics</li>
        <li>ระบบ Carbon Credit & FoodPassport</li>
        @endif
    </ul>
    </div>

    <div class="block">
    <h2>Q2 2026 — {{ $isEn ? 'DeFi Infrastructure' : 'โครงสร้างพื้นฐาน DeFi' }}</h2>
    <ul>
        @if($isEn)
        <li>BSC Bridge launch (wTPIX &#8596; native TPIX)</li>
        <li>TPIX DEX AMM pools deployment (Uniswap V2 fork)</li>
        <li>4-Tier master node staking activation</li>
        <li>Validator Governance smart contract deployment</li>
        <li>TPIX Wallet mobile app release (iOS/Android)</li>
        <li>TPIXRouter fee collection activation</li>
        @else
        <li>เปิด BSC Bridge (wTPIX &#8596; native TPIX)</li>
        <li>Deploy AMM pools (Uniswap V2 fork)</li>
        <li>เปิดระบบ staking Master Node 4 ระดับ</li>
        <li>Deploy smart contract Validator Governance</li>
        <li>เปิดตัว TPIX Wallet มือถือ (iOS/Android)</li>
        <li>เปิดระบบเก็บค่าธรรมเนียม TPIXRouter</li>
        @endif
    </ul>
    </div>

    <div class="block">
    <h2>Q3-Q4 2026 — {{ $isEn ? 'Ecosystem Growth & Governance' : 'เติบโตระบบนิเวศและ Governance' }}</h2>
    <ul>
        @if($isEn)
        <li>Token Factory launch — permissionless ERC-20 creation</li>
        <li>Affiliate/Referral program activation</li>
        <li>Carbon Credit & Food Passport pilots</li>
        <li>CEX listing applications</li>
        <li>Validator KYC onboarding (first external validators)</li>
        <li>DAO governance transition planning</li>
        <li>Multi-chain bridge expansion (Ethereum, Polygon)</li>
        <li>Enterprise partnership program</li>
        <li>NFT marketplace launch</li>
        @else
        <li>เปิด Token Factory — สร้างเหรียญ ERC-20 ฟรี</li>
        <li>เปิดระบบ Affiliate/Referral</li>
        <li>นำร่อง Carbon Credit & Food Passport</li>
        <li>สมัครลิสต์ CEX</li>
        <li>รับสมัคร Validator ภายนอก (KYC)</li>
        <li>วางแผนเปลี่ยนผ่านสู่ DAO governance</li>
        <li>ขยาย Bridge ข้ามเชน (Ethereum, Polygon)</li>
        <li>โปรแกรมพาร์ทเนอร์องค์กร</li>
        <li>เปิด NFT marketplace</li>
        @endif
    </ul>
    </div>

    <div class="block">
    <h2>2027 — {{ $isEn ? 'Global Expansion' : 'ขยายสู่ระดับสากล' }}</h2>
    <ul>
        @if($isEn)
        <li>Full DAO governance activation</li>
        <li>Multi-language support (Japanese, Korean, Vietnamese)</li>
        <li>Carbon credit exchange full launch</li>
        <li>Food Passport government partnership pilots</li>
        <li>Year 2 emission reduction (500M TPIX/year)</li>
        @else
        <li>เปิด DAO governance เต็มรูปแบบ</li>
        <li>รองรับหลายภาษา (ญี่ปุ่น, เกาหลี, เวียดนาม)</li>
        <li>เปิดระบบ Carbon Credit เต็มรูปแบบ</li>
        <li>นำร่องความร่วมมือภาครัฐ (Food Passport)</li>
        <li>ลด emission ปีที่ 2 (500M TPIX/ปี)</li>
        @endif
    </ul>
    </div>

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 15. SECURITY & AUDITS --}}
    {{-- ================================================================ --}}
    <h1>15. {{ $isEn ? 'Security & Audits' : 'ความปลอดภัยและการตรวจสอบ' }}</h1>
    <ul>
        @if($isEn)
        <li><strong>Smart Contract Audits</strong> — All contracts undergo third-party security audits before mainnet deployment. Focus areas: reentrancy, integer overflow, access control, and economic exploits.</li>
        <li><strong>IBFT 2.0 Consensus</strong> — Byzantine fault tolerance ensures network security with up to 1 faulty validator out of 4. No chain reorganizations possible.</li>
        <li><strong>Rate Limiting</strong> — RPC-level per-IP rate limiting prevents spam on the gasless chain. Transaction queue prioritization for legitimate traffic.</li>
        <li><strong>Multi-sig Treasury</strong> — Protocol funds managed by multi-signature wallets (3-of-5 threshold).</li>
        <li><strong>AES-256 Encryption</strong> — All wallet data encrypted with AES-256-GCM. PBKDF2 key derivation. Private keys auto-clear from memory after 60 seconds.</li>
        <li><strong>ReentrancyGuard</strong> — All value-transfer functions protected with OpenZeppelin ReentrancyGuard.</li>
        <li><strong>Pausable Contracts</strong> — Emergency circuit breaker on all critical contracts (DEX router, bridge, token sale).</li>
        <li><strong>ValidatorKYC</strong> — PDPA-compliant KYC. Zero PII on-chain. Encrypted off-chain storage with access logging.</li>
        <li><strong>Bug Bounty</strong> — Ongoing program for responsible vulnerability disclosure.</li>
        <li><strong>24/7 Monitoring</strong> — Chain health monitoring, alerting system, and automatic incident response.</li>
        @else
        <li><strong>ตรวจสอบ Smart Contract</strong> — ทุก contract ผ่านการตรวจสอบความปลอดภัยจากบุคคลที่สามก่อน deploy จุดเน้น: reentrancy, integer overflow, access control และ economic exploits</li>
        <li><strong>IBFT 2.0 Consensus</strong> — Byzantine fault tolerance รับประกันความปลอดภัยเครือข่ายทนได้ถึง 1 validator ที่มีปัญหาจาก 4 ไม่มี chain reorganizations</li>
        <li><strong>Rate Limiting</strong> — จำกัดอัตราต่อ IP ที่ระดับ RPC ป้องกัน spam บนเชนที่ไม่เสียค่า gas จัดลำดับความสำคัญธุรกรรมที่ถูกต้อง</li>
        <li><strong>กระเป๋า Multi-sig</strong> — กองทุน protocol จัดการด้วย multi-signature wallet (3 จาก 5)</li>
        <li><strong>เข้ารหัส AES-256</strong> — ข้อมูลกระเป๋าทั้งหมดเข้ารหัสด้วย AES-256-GCM PBKDF2 key derivation Private key ลบจากหน่วยความจำอัตโนมัติหลัง 60 วินาที</li>
        <li><strong>ReentrancyGuard</strong> — ทุกฟังก์ชันโอนค่าป้องกันด้วย OpenZeppelin ReentrancyGuard</li>
        <li><strong>Pausable Contracts</strong> — ระบบหยุดฉุกเฉินบน contract สำคัญทั้งหมด (DEX router, bridge, token sale)</li>
        <li><strong>ValidatorKYC</strong> — KYC ตาม PDPA ไม่มี PII บนเชน เก็บนอกเชนแบบเข้ารหัสพร้อมบันทึกการเข้าถึง</li>
        <li><strong>Bug Bounty</strong> — โปรแกรมต่อเนื่องสำหรับการเปิดเผยช่องโหว่อย่างมีความรับผิดชอบ</li>
        <li><strong>ตรวจสอบ 24/7</strong> — ตรวจสอบสุขภาพเชน ระบบแจ้งเตือน และตอบสนองเหตุการณ์อัตโนมัติ</li>
        @endif
    </ul>

    {{-- ================================================================ --}}
    {{-- 16. TEAM & PARTNERS --}}
    {{-- ================================================================ --}}
    <h1>16. {{ $isEn ? 'Team & Partners' : 'ทีมงานและพาร์ทเนอร์' }}</h1>

    @if($isEn)
    <p>
        TPIX Chain is developed by <strong>Xman Studio</strong>, a blockchain development team
        specializing in DeFi protocols and Web3 applications for the Southeast Asian market.
    </p>
    <p>The team brings extensive experience in:</p>
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
    @else
    <p>
        TPIX Chain พัฒนาโดย <strong>Xman Studio</strong> ทีมพัฒนาบล็อกเชนที่เชี่ยวชาญด้าน
        โปรโตคอล DeFi และแอปพลิเคชัน Web3 สำหรับตลาดเอเชียตะวันออกเฉียงใต้
    </p>
    <p>ทีมมีประสบการณ์กว้างขวางในด้าน:</p>
    <ul>
        <li>พัฒนา Solidity smart contract และตรวจสอบความปลอดภัย</li>
        <li>Deploy เชนบน EVM (Polygon Edge, Geth, Besu)</li>
        <li>พัฒนา Web3 full-stack (Laravel, Vue, React Native, Flutter)</li>
        <li>ออกแบบโปรโตคอลกระจายอำนาจและ tokenomics</li>
        <li>เชื่อมต่อบล็อกเชนกับโลกจริง (IoT, supply chain, ตลาดคาร์บอน)</li>
    </ul>
    <p>
        TPIX Chain เชื่อมต่อกับระบบนิเวศ <strong>ThaiPrompt</strong> ที่มีผู้ใช้องค์กรลงทะเบียนกว่า 500,000 คน
        ให้ฐานผู้ใช้ในตัวสำหรับการยอมรับ
    </p>
    @endif

    <div class="page-break"></div>

    {{-- ================================================================ --}}
    {{-- 17. LEGAL DISCLAIMER --}}
    {{-- ================================================================ --}}
    <h1>17. {{ $isEn ? 'Legal Disclaimer' : 'ข้อจำกัดความรับผิดชอบ' }}</h1>

    @if($isEn)
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
    @else
    <p class="disclaimer">
        เอกสารไวท์เปเปอร์นี้มีวัตถุประสงค์เพื่อให้ข้อมูลเท่านั้น ไม่ถือเป็นคำแนะนำการลงทุน
        คำแนะนำทางการเงิน คำแนะนำการเทรด หรือคำแนะนำอื่นใด ไม่ควรถือว่าเนื้อหาของเอกสารนี้เป็นเช่นนั้น
        โทเคน TPIX เป็น utility token ที่ออกแบบมาเพื่อใช้ในระบบนิเวศ TPIX Chain
        และไม่ได้มีวัตถุประสงค์เป็นหลักทรัพย์ในเขตอำนาจศาลใดๆ
    </p>
    <p class="disclaimer">
        การซื้อโทเคน TPIX มีความเสี่ยงอย่างมีนัยสำคัญ รวมถึงแต่ไม่จำกัดเพียงความเสี่ยงที่จะสูญเสียเงินซื้อทั้งหมด
        ผู้ซื้อที่มีศักยภาพควรประเมินความเสี่ยงและความไม่แน่นอนทั้งหมดที่เกี่ยวข้องกับโทเคน TPIX
        และแพลตฟอร์ม TPIX Chain อย่างรอบคอบก่อนทำการซื้อ
    </p>
    <p class="disclaimer">
        ข้อมูลในเอกสารนี้อาจได้รับการอัปเดต แก้ไข หรือปรับปรุงเมื่อใดก็ได้โดยไม่ต้องแจ้งล่วงหน้า
        ทีมงานขอสงวนสิทธิ์ในการเปลี่ยนแปลงเอกสารนี้ตามที่โปรเจคพัฒนาไป
        ไม่มีส่วนใดในเอกสารนี้ที่จะถือว่าเป็นหนังสือชี้ชวนหรือการชักชวนให้ลงทุน
    </p>
    <p class="disclaimer">
        ข้อความที่มองไปข้างหน้าทั้งหมด รวมถึงแผนงาน อาจเปลี่ยนแปลงตามสภาวะตลาด
        การพัฒนากฎระเบียบ และความเป็นไปได้ทางเทคนิค ผลงานในอดีตของเทคโนโลยีบล็อกเชน
        ไม่ได้รับประกันผลลัพธ์ในอนาคต
    </p>
    @endif

    <br><br>
    <div style="text-align: center; color: #94a3b8; font-size: 10pt;">
        <p>&copy; 2026 TPIX Chain — Xman Studio. {{ $isEn ? 'All rights reserved.' : 'สงวนลิขสิทธิ์' }}</p>
        <p>https://tpix.online &nbsp;|&nbsp; https://xmanstudio.com</p>
        <p style="margin-top: 10px; font-size: 9pt;">
            GitHub: https://github.com/xjanova/TPIX-Coin<br>
            Block Explorer: https://explorer.tpix.online<br>
            RPC: https://rpc.tpix.online
        </p>
    </div>

</body>
</html>
