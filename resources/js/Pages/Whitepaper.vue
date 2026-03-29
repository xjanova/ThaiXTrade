<script setup>
/**
 * TPIX TRADE - Whitepaper Page (Premium Edition)
 * เอกสาร Whitepaper แบบ interactive พร้อม:
 * - สลับภาษาไทย/อังกฤษ
 * - แผนภูมิ Tokenomics (SVG Donut Chart)
 * - Architecture Diagram
 * - Use Cases จาก ThaiPrompt Ecosystem
 * - Roadmap Timeline 8 Phases
 * - PDF Download
 * Developed by Xman Studio
 */

import { ref, computed, onMounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

// ภาษาปัจจุบัน — เริ่มต้นภาษาอังกฤษ
const lang = ref('en');
const toggleLang = () => { lang.value = lang.value === 'en' ? 'th' : 'en'; };

// ข้อมูลสองภาษา
const t = computed(() => content[lang.value]);

// สารบัญ — Table of Contents
const sections = computed(() => [
    { id: 'executive-summary', title: t.value.toc[0] },
    { id: 'problem-solution', title: t.value.toc[1] },
    { id: 'tpix-chain', title: t.value.toc[2] },
    { id: 'tokenomics', title: t.value.toc[3] },
    { id: 'use-cases', title: t.value.toc[4] },
    { id: 'dex-protocol', title: t.value.toc[5] },
    { id: 'token-sale', title: t.value.toc[6] },
    { id: 'masternode', title: t.value.toc[7] },
    { id: 'living-identity', title: t.value.toc[8] },
    { id: 'governance', title: t.value.toc[9] },
    { id: 'bridge', title: t.value.toc[10] },
    { id: 'ecosystem', title: t.value.toc[11] },
    { id: 'integrations', title: t.value.toc[12] },
    { id: 'roadmap', title: t.value.toc[13] },
    { id: 'tech-stack', title: t.value.toc[14] },
    { id: 'team', title: t.value.toc[15] },
    { id: 'security', title: t.value.toc[16] },
    { id: 'legal', title: t.value.toc[17] },
]);

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
        { threshold: 0.2, rootMargin: '-80px 0px -50% 0px' }
    );

    // observe ทั้ง 18 sections
    const ids = ['executive-summary','problem-solution','tpix-chain','tokenomics','use-cases',
        'dex-protocol','token-sale','masternode','living-identity','governance','bridge',
        'ecosystem','integrations','roadmap','tech-stack','team','security','legal'];
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) observer.observe(el);
    });
});

// Download PDF — Dark Premium Theme (เหมือนหน้าเว็บ)
// รองรับไทย/อังกฤษ, ป้องกันเนื้อหาขาดข้ามหน้า, หน้าปกหรูหรา
function downloadPdf() {
    const style = document.createElement('style');
    style.id = 'print-style';
    style.textContent = `
        @page {
            size: A4;
            margin: 16mm 14mm 20mm 14mm;
        }

        @media print {
            /* ===== พื้นฐาน — Dark Premium ===== */
            html, body {
                background: #080c1a !important;
                color: #cbd5e1 !important;
                font-family: 'Sarabun', 'Noto Sans Thai', 'Inter', 'Helvetica Neue', Arial, sans-serif !important;
                font-size: 10pt !important;
                line-height: 1.75 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            /* ===== ซ่อน UI elements ===== */
            nav, header, footer, aside,
            button, .btn-primary, .btn-secondary, .btn-brand,
            .fixed, .sticky,
            .ticker-strip, .banner-ad,
            [class*="animate-pulse"] {
                display: none !important;
            }

            /* ===== ล้าง effects ที่ print ไม่รองรับ ===== */
            * {
                box-shadow: none !important;
                backdrop-filter: none !important;
                -webkit-backdrop-filter: none !important;
                text-shadow: none !important;
            }

            /* ===== หน้าปก — Premium Cover ===== */
            .print-cover {
                page-break-after: always !important;
                background: #080c1a !important;
                min-height: 92vh !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                text-align: center !important;
                position: relative !important;
                overflow: hidden !important;
            }
            .print-cover::before {
                content: '' !important;
                position: absolute !important;
                top: 15% !important;
                left: 50% !important;
                transform: translateX(-50%) !important;
                width: 500px !important;
                height: 500px !important;
                background: radial-gradient(circle, rgba(59,130,246,0.12) 0%, rgba(139,92,246,0.06) 40%, transparent 70%) !important;
                border-radius: 50% !important;
            }
            .print-cover img {
                display: block !important;
                width: 88px !important;
                height: 88px !important;
                margin: 0 auto 28px !important;
                position: relative !important;
            }
            .print-cover h1 {
                font-size: 32pt !important;
                font-weight: 800 !important;
                color: #f1f5f9 !important;
                margin-bottom: 6px !important;
                letter-spacing: -0.5px !important;
                position: relative !important;
            }
            .print-cover .text-primary-400 {
                color: #60a5fa !important;
                font-size: 13pt !important;
                position: relative !important;
            }
            .print-cover .text-gray-500 {
                color: #64748b !important;
                font-size: 10pt !important;
                position: relative !important;
            }

            /* ===== Typography ===== */
            h1 {
                font-size: 20pt !important;
                color: #f1f5f9 !important;
                font-weight: 800 !important;
            }
            h2 {
                font-size: 15pt !important;
                color: #e2e8f0 !important;
                font-weight: 700 !important;
                border-bottom: 1px solid rgba(255,255,255,0.1) !important;
                padding-bottom: 8px !important;
                margin-top: 4px !important;
                margin-bottom: 12px !important;
            }
            h3 {
                font-size: 12pt !important;
                color: #e2e8f0 !important;
                font-weight: 600 !important;
                margin-bottom: 6px !important;
            }
            h4 {
                font-size: 11pt !important;
                color: #cbd5e1 !important;
                font-weight: 600 !important;
            }
            p, li, span, div {
                color: #94a3b8 !important;
            }
            a {
                color: #60a5fa !important;
                text-decoration: none !important;
            }

            /* Gradient text → สีสว่าง */
            .text-gradient, .text-gradient-brand {
                -webkit-text-fill-color: #60a5fa !important;
                background: none !important;
                color: #60a5fa !important;
            }
            .text-gradient-gold {
                -webkit-text-fill-color: #fbbf24 !important;
                background: none !important;
                color: #fbbf24 !important;
            }

            /* เน้นข้อความสำคัญ */
            .font-medium, strong, b {
                color: #e2e8f0 !important;
            }

            /* ===== ป้องกันเนื้อหาขาดข้ามหน้า ===== */
            .wp-section {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            h2, h3, h4 {
                page-break-after: avoid;
                break-after: avoid;
            }
            p, .wp-text {
                orphans: 3;
                widows: 3;
            }
            .wp-highlight, .wp-table,
            [class*="rounded-xl"], [class*="rounded-2xl"],
            table, tr, img, svg, figure {
                page-break-inside: avoid;
                break-inside: avoid;
            }

            /* ===== ตาราง — Dark Glass Style ===== */
            table {
                border-collapse: separate !important;
                border-spacing: 0 !important;
                width: 100% !important;
                font-size: 8.5pt !important;
                margin: 8px 0 !important;
                border-radius: 8px !important;
                overflow: hidden !important;
                border: 1px solid rgba(255,255,255,0.08) !important;
            }
            thead tr {
                background: rgba(255,255,255,0.06) !important;
            }
            th {
                background: rgba(255,255,255,0.06) !important;
                color: #94a3b8 !important;
                font-weight: 600 !important;
                border-bottom: 1px solid rgba(255,255,255,0.08) !important;
                border-right: none !important;
                border-left: none !important;
                border-top: none !important;
                padding: 8px 10px !important;
                text-align: left !important;
                font-size: 7.5pt !important;
                text-transform: uppercase !important;
                letter-spacing: 0.5px !important;
            }
            td {
                border-bottom: 1px solid rgba(255,255,255,0.04) !important;
                border-right: none !important;
                border-left: none !important;
                border-top: none !important;
                padding: 7px 10px !important;
                color: #cbd5e1 !important;
                vertical-align: top !important;
            }
            tbody tr:nth-child(even) {
                background: rgba(255,255,255,0.02) !important;
            }

            /* ===== Cards & Highlights — Glass Dark ===== */
            .wp-highlight {
                border: 1px solid rgba(255,255,255,0.08) !important;
                border-radius: 12px !important;
                padding: 16px !important;
                background: rgba(255,255,255,0.03) !important;
                margin: 12px 0 !important;
            }
            .wp-table {
                border: 1px solid rgba(255,255,255,0.08) !important;
                border-radius: 12px !important;
                background: rgba(255,255,255,0.02) !important;
            }

            /* Problem/Solution cards */
            [class*="bg-red-500/5"] {
                border: 1px solid rgba(239,68,68,0.2) !important;
                border-left: 3px solid #ef4444 !important;
                padding: 12px !important;
                background: rgba(239,68,68,0.05) !important;
                border-radius: 8px !important;
            }
            [class*="bg-green-500/5"] {
                border: 1px solid rgba(34,197,94,0.2) !important;
                border-left: 3px solid #22c55e !important;
                padding: 12px !important;
                background: rgba(34,197,94,0.05) !important;
                border-radius: 8px !important;
            }

            /* Stats / Key numbers */
            .wp-highlight .text-2xl,
            .text-2xl {
                font-size: 16pt !important;
                font-weight: 700 !important;
                color: #60a5fa !important;
            }

            /* ===== Grid ===== */
            .grid {
                display: grid !important;
                gap: 8px !important;
            }

            /* ===== SVG (Donut chart etc.) ===== */
            svg {
                page-break-inside: avoid;
                max-width: 100% !important;
            }
            svg path, svg circle, svg rect {
                print-color-adjust: exact !important;
                -webkit-print-color-adjust: exact !important;
            }

            /* ===== Layout: full width content ===== */
            .flex.gap-8 {
                display: block !important;
            }
            aside {
                display: none !important;
            }
            main {
                max-width: 100% !important;
                width: 100% !important;
            }

            /* ===== สีหลักให้ print exact ===== */
            [class*="text-trading-green"] { color: #22c55e !important; }
            [class*="text-trading-red"] { color: #ef4444 !important; }
            [class*="text-primary-400"] { color: #60a5fa !important; }
            [class*="text-primary-300"] { color: #93bbfd !important; }
            [class*="text-accent-"] { color: #a78bfa !important; }
            [class*="text-yellow-400"] { color: #fbbf24 !important; }
            [class*="text-cyan-400"] { color: #22d3ee !important; }
            [class*="text-white"] { color: #f1f5f9 !important; }

            /* ===== Responsive columns: show all ===== */
            .hidden { display: revert !important; }
            [class*="hidden sm:"], [class*="hidden md:"], [class*="hidden lg:"] {
                display: revert !important;
            }

            /* ===== Lists ===== */
            ul, ol { padding-left: 18px !important; }
            li { margin-bottom: 3px !important; color: #94a3b8 !important; }

            /* ===== Print-only elements ===== */
            .print\\:block { display: block !important; }
            .print\\:hidden { display: none !important; }

            /* ===== Blur backgrounds → ซ่อน ===== */
            [class*="blur-"] { display: none !important; }

            /* ===== Page footer ===== */
            @page {
                @bottom-center {
                    content: "TPIX Chain Whitepaper  ·  Page " counter(page);
                    font-size: 7pt;
                    color: #475569;
                }
            }
        }
    `;
    document.head.appendChild(style);

    // ตั้งชื่อไฟล์ตามภาษา
    const suffix = lang.value === 'th' ? 'TH' : 'EN';
    document.title = `TPIX-Chain-Whitepaper-v2.0-${suffix}`;

    // เปิด print dialog (Save as PDF)
    setTimeout(() => {
        window.print();
        // คืนค่า title + ลบ style
        setTimeout(() => {
            document.title = 'TPIX Chain Whitepaper';
            const s = document.getElementById('print-style');
            if (s) s.remove();
        }, 1000);
    }, 200);
}

// Tokenomics Donut Chart — ข้อมูล allocation (ตรงกับ TPIX-Coin NodeRegistryV2)
const tokenAllocation = [
    { label: 'Ecosystem Development', labelTh: 'พัฒนา Ecosystem', pct: 25, color: '#3B82F6', amount: '1.75B' },
    { label: 'Community & Rewards', labelTh: 'ชุมชนและรางวัล', pct: 20, color: '#8B5CF6', amount: '1.4B' },
    { label: 'Master Node Rewards', labelTh: 'รางวัล Master Node', pct: 20, color: '#10B981', amount: '1.4B' },
    { label: 'Liquidity & Market Making', labelTh: 'สภาพคล่องและ Market Making', pct: 15, color: '#06B6D4', amount: '1.05B' },
    { label: 'Token Sale (ICO)', labelTh: 'ขายเหรียญ (ICO)', pct: 10, color: '#F59E0B', amount: '700M' },
    { label: 'Team & Advisors', labelTh: 'ทีมและที่ปรึกษา', pct: 10, color: '#EF4444', amount: '700M' },
];

// คำนวณ SVG donut chart paths
function donutPath(startAngle, endAngle, outerR = 90, innerR = 55) {
    const rad = (a) => (a - 90) * Math.PI / 180;
    const cx = 100, cy = 100;
    const x1 = cx + outerR * Math.cos(rad(startAngle));
    const y1 = cy + outerR * Math.sin(rad(startAngle));
    const x2 = cx + outerR * Math.cos(rad(endAngle));
    const y2 = cy + outerR * Math.sin(rad(endAngle));
    const x3 = cx + innerR * Math.cos(rad(endAngle));
    const y3 = cy + innerR * Math.sin(rad(endAngle));
    const x4 = cx + innerR * Math.cos(rad(startAngle));
    const y4 = cy + innerR * Math.sin(rad(startAngle));
    const large = endAngle - startAngle > 180 ? 1 : 0;
    return `M${x1},${y1} A${outerR},${outerR} 0 ${large},1 ${x2},${y2} L${x3},${y3} A${innerR},${innerR} 0 ${large},0 ${x4},${y4} Z`;
}

// สร้าง donut segments
const donutSegments = computed(() => {
    let angle = 0;
    return tokenAllocation.map(item => {
        const start = angle;
        const sweep = (item.pct / 100) * 360;
        angle += sweep;
        return { ...item, path: donutPath(start, start + sweep - 0.5) };
    });
});

// ==========================================
// เนื้อหาสองภาษา — Content i18n
// ==========================================
const content = {
    en: {
        pageTitle: 'TPIX Chain Whitepaper',
        subtitle: 'A Next-Generation EVM Blockchain for the ASEAN Digital Economy',
        version: 'Version 2.0 — March 2026',
        downloadPdf: 'Download PDF',
        readInThai: '🇹🇭 อ่านเป็นภาษาไทย',
        readInEn: '🇬🇧 Read in English',
        toc: [
            '1. Executive Summary',
            '2. Problem & Solution',
            '3. TPIX Chain Architecture',
            '4. Tokenomics',
            '5. Use Cases & Applications',
            '6. DEX Protocol',
            '7. Token Sale Details',
            '8. Master Node & Rewards',
            '9. Living Identity — Seedless Recovery',
            '10. Validator Governance',
            '11. Cross-Chain Bridge',
            '12. Ecosystem & Affiliate',
            '13. Platform Integrations',
            '14. Roadmap',
            '15. Technology Stack',
            '16. Team & Partners',
            '17. Security & Audits',
            '18. Legal Disclaimer',
        ],
        execSummary: {
            p1: 'TPIX Chain is a next-generation EVM-compatible blockchain built on Polygon Edge technology, designed specifically for the Thai and Southeast Asian digital economy. With gasless transactions, 2-second block times, and IBFT Proof-of-Authority consensus, TPIX Chain provides an unmatched platform for decentralized applications, DeFi, and real-world asset tokenization.',
            p2: 'The native TPIX coin (7 billion fixed supply, 18 decimals) powers the entire ecosystem including: a built-in Uniswap V2 DEX, multi-tier master node system, a token factory for custom ERC-20 creation, cross-chain bridge to BSC, an affiliate referral program, and integration with the Thaiprompt Affiliate enterprise platform serving 500,000+ users.',
            p3: 'TPIX is not just a cryptocurrency — it is the backbone of a complete digital economy spanning food supply chain traceability, IoT smart farming, delivery services, e-commerce, AI bot marketplace, hotel booking, carbon credit trading, and enterprise affiliate marketing.',
            stats: [
                { value: '7B', label: 'Total Supply' },
                { value: '0 Gas', label: 'Transaction Fee' },
                { value: '2s', label: 'Block Time' },
                { value: '~1,500', label: 'TPS Capacity' },
                { value: 'IBFT', label: 'Consensus' },
                { value: '~10s', label: 'Finality' },
            ],
        },
        problems: [
            { title: 'High Gas Fees', desc: 'Ethereum gas fees ($5-50+) and BSC fees ($0.10-1.00) make micro-transactions and daily DeFi usage impractical for average users in developing economies.' },
            { title: 'Complexity Barrier', desc: 'Existing DEXes and DeFi protocols require deep technical knowledge. The onboarding experience is intimidating for the 95% of people who have never used cryptocurrency.' },
            { title: 'No ASEAN Focus', desc: 'Major blockchain ecosystems are built for Western markets. There is no localized DeFi ecosystem with Thai/ASEAN language support and culturally relevant use cases.' },
            { title: 'Fragmented Utility', desc: 'Most tokens lack real-world utility beyond speculation. There is no integrated ecosystem connecting DeFi with real businesses like agriculture, food supply chain, and services.' },
        ],
        solutions: [
            { title: 'Zero Gas Fees', desc: 'All TPIX Chain transactions are completely free. Gas price is hardcoded to 0 in the genesis block, removing the cost barrier permanently.' },
            { title: 'Intuitive UX', desc: 'Clean, modern interface with Thai language support. Connect wallet, swap tokens, and stake — all in 3 clicks.' },
            { title: 'ASEAN-First Design', desc: 'Built from the ground up for Thai and Southeast Asian users with full Thai localization, local payment integrations, and culturally relevant use cases.' },
            { title: 'Real-World Integration', desc: 'TPIX connects DeFi with real businesses: food traceability (FoodPassport), smart farming (IoT), delivery services, e-commerce, and hotel booking.' },
        ],
        chainSpecs: [
            ['Chain Name', 'TPIX Chain'],
            ['Chain ID (Mainnet)', '4289'],
            ['Chain ID (Testnet)', '4290'],
            ['Consensus', 'IBFT (Istanbul Byzantine Fault Tolerant)'],
            ['Block Time', '2 seconds'],
            ['Finality', '~10 seconds (5 blocks)'],
            ['Gas Price', '0 (Free — hardcoded in genesis)'],
            ['TPS Capacity', '~1,500 transactions/second'],
            ['VM', 'EVM (Ethereum Virtual Machine) — full Solidity support'],
            ['Native Coin', 'TPIX (18 decimals)'],
            ['Total Supply', '7,000,000,000 TPIX (pre-mined in genesis)'],
            ['Validators', '4 IBFT nodes (BFT tolerates ⌊(n-1)/3⌋ = 1 faulty)'],
            ['RPC URL', 'https://rpc.tpix.online'],
            ['Explorer', 'https://explorer.tpix.online'],
        ],
        useCases: [
            {
                icon: '🏦',
                title: 'Decentralized Exchange (DEX)',
                desc: 'Trade tokens using automated market making (AMM) with 0.3% swap fee. Provide liquidity and earn trading fees. Uniswap V2 fork optimized for TPIX Chain.',
                features: ['Token swaps via constant product formula (x·y=k)', 'Liquidity provision with LP token rewards', 'Farming & yield optimization', 'Zero gas costs for all trades'],
            },
            {
                icon: '🍲',
                title: 'FoodPassport — Food Supply Chain Traceability',
                desc: 'Blockchain-based food safety and traceability system. Track food from farm to consumer with immutable records.',
                features: ['Farm-to-table traceability via blockchain records', 'Quality verification with AI image recognition', 'Certificate management as NFTs on TPIX Chain', 'Smart contract auto-payment upon inspection pass', 'Consumer QR scanning for full product history'],
            },
            {
                icon: '🌾',
                title: 'IoT Smart Farm System',
                desc: 'Intelligent farming system using IoT sensors and AI, integrated with blockchain for data integrity and automated operations.',
                features: ['Real-time sensor monitoring (temperature, humidity, light, soil)', 'Automated irrigation, fertilization, and lighting control', 'Agricultural data marketplace — sell farm data for TPIX', 'Predictive analytics for crop yield optimization', 'Carbon credit generation and trading on-chain'],
            },
            {
                icon: '🚚',
                title: 'Multi-Service Delivery Platform',
                desc: 'Full-stack delivery platform for food, groceries, courier services, and home services. TPIX as the payment backbone.',
                features: ['Food, grocery, and courier delivery', 'Service marketplace (cleaning, repair, etc.)', '3% TPIX cashback on every order', 'Rider earnings paid in TPIX', 'Real-time order tracking on-chain'],
            },
            {
                icon: '🤖',
                title: 'AI Bot Marketplace',
                desc: 'Buy, sell, and subscribe to AI-powered bots for trading, customer service, and business automation.',
                features: ['LINE Official Account AI chatbots', 'Trading bots with sentiment analysis', 'Auto-response with NLP capabilities', 'Monthly subscription payments in TPIX', 'Creator revenue sharing program'],
            },
            {
                icon: '🏨',
                title: 'Hotel & Travel Booking',
                desc: 'Decentralized hotel booking system with TPIX payment, cashback rewards, and loyalty program.',
                features: ['Direct hotel booking with TPIX payment', '3% cashback rewards on every booking', 'Loyalty program with TPIX accumulation', 'Instant settlement to hotel operators'],
            },
            {
                icon: '🛒',
                title: 'E-Commerce & Marketplace',
                desc: 'Multi-vendor marketplace platform supporting TPIX payments with 5% cashback and affiliate commission tracking.',
                features: ['Multi-vendor marketplace with TPIX payments', '5% cashback in TPIX on all purchases', 'POS integration for physical stores', 'Affiliate commission tracking and auto-payout'],
            },
            {
                icon: '🏭',
                title: 'Token Factory',
                desc: 'Create custom ERC-20 tokens on TPIX Chain for loyalty programs, vouchers, memberships, and business tokens.',
                features: ['Create ERC-20 tokens for 100 TPIX', 'Point tokens, voucher tokens, membership NFTs', 'All subsequent transactions are gas-free', 'Perfect for loyalty programs and business tokens'],
            },
            {
                icon: '🌱',
                title: 'Carbon Credit Trading',
                desc: 'Blockchain-based carbon credit marketplace integrated with IoT Smart Farm for verified emission reduction.',
                features: ['Carbon credit tokenization as NFTs', 'Transparent on-chain trading', 'Smart farm integration for automated verification', 'Compliance with international carbon standards'],
            },
            {
                icon: '🧠',
                title: 'AI Autonomous Ecosystem',
                desc: 'Self-improving AI system that builds and manages AI agents autonomously, operating 24/7 without human intervention.',
                features: ['AI-building-AI: self-improving agent creation', 'Autonomous system management 24/7', 'Predictive analytics and automated decision-making', 'TPIX payment for AI compute resources'],
            },
        ],
        dex: {
            desc: 'TPIX DEX is a Uniswap V2 fork deployed natively on TPIX Chain. It provides automated market making (AMM) with constant product formula (x·y=k) and a 0.3% swap fee (0.25% to LPs, 0.05% to protocol treasury).',
            contracts: [
                ['TPIXDEXFactory', 'Creates and manages trading pair contracts'],
                ['TPIXDEXRouter02', 'Handles multi-hop swaps and liquidity operations'],
                ['TPIXDEXPair', 'Individual liquidity pool with ERC-20 LP tokens'],
                ['WTPIX', 'Wrapped TPIX for ERC-20 compatibility within the DEX'],
            ],
        },
        salePhases: [
            { phase: 'Private Sale', price: '$0.05', alloc: '100M TPIX', tge: '10%', vesting: '30d cliff, 180d linear', color: 'text-purple-400' },
            { phase: 'Pre-Sale', price: '$0.08', alloc: '200M TPIX', tge: '15%', vesting: '14d cliff, 120d linear', color: 'text-blue-400' },
            { phase: 'Public Sale', price: '$0.10', alloc: '400M TPIX', tge: '25%', vesting: 'No cliff, 90d linear', color: 'text-green-400' },
        ],
        masternodeTiers: [
            { tier: 'Validator Node', stake: '10,000,000 TPIX', apy: '15-20%', lock: '180 days', maxNodes: '21', reward: '20% of reward pool', hardware: '16 CPU, 32GB RAM, 1TB SSD' },
            { tier: 'Guardian Node', stake: '1,000,000 TPIX', apy: '10-12%', lock: '90 days', maxNodes: '100', reward: '35% of reward pool', hardware: '8 CPU, 16GB RAM, 500GB SSD' },
            { tier: 'Sentinel Node', stake: '100,000 TPIX', apy: '7-9%', lock: '30 days', maxNodes: '500', reward: '30% of reward pool', hardware: '4 CPU, 8GB RAM, 200GB SSD' },
            { tier: 'Light Node', stake: '10,000 TPIX', apy: '4-6%', lock: '7 days', maxNodes: 'Unlimited', reward: '15% of reward pool', hardware: '2 CPU, 4GB RAM, 100GB SSD' },
        ],
        masternodeEmission: [
            { year: 'Year 1 (2025-2026)', amount: '600,000,000 TPIX', perBlock: '~38.3 TPIX', pct: '42.9%' },
            { year: 'Year 2 (2026-2027)', amount: '500,000,000 TPIX', perBlock: '~31.9 TPIX', pct: '35.7%' },
            { year: 'Year 3 (2027-2028)', amount: '300,000,000 TPIX', perBlock: '~19.1 TPIX', pct: '21.4%' },
        ],
        masternodeDesc: 'TPIX uses IBFT2 Proof-of-Authority consensus with a 4-tier master node system. Validators are real IBFT2 block sealers with governance power (requiring 10M TPIX + company KYC). Guardian, Sentinel, and Light nodes stake TPIX to participate in the network and earn proportional rewards from a 1.4 billion TPIX reward pool distributed over 3 years (ending 2028) with decreasing emission.',
        masternodeRewardSplit: 'Each block reward is split: 20% to Validators (IBFT2 sealers), 35% to Guardian nodes, 30% shared among Sentinel nodes, and 15% shared among Light nodes (weighted by stake amount and uptime score).',
        masternodeSlashing: 'Validators face 15% stake slashing for misbehavior. Guardian nodes face 10% slashing if offline >24h. Sentinel nodes face 5% slashing if offline >48h. Light nodes have no slashing penalty. All tiers are deregistered after 7 days offline.',
        masternodeAfterY5: 'After the 3-year reward pool is fully distributed (2028), master nodes continue earning from multiple sustainable revenue streams: (1) Transaction Fee Sharing — while end-user transactions are gasless, dApp developers and token creators pay platform fees (0.1-1% per contract deployment and token creation). 50% of these fees go to active nodes. (2) Cross-Chain Bridge Fees — every TPIX-BSC bridge transfer incurs a 0.05% fee, shared among validators. (3) Token Factory Revenue — creating tokens costs 100 TPIX, with 50% going to the node pool. (4) DEX Protocol Fees — 0.05% of every swap fee goes to the node treasury. (5) Governance Rewards — Validator-tier nodes vote on proposals and earn voting rewards. (6) Premium API Access — commercial dApps pay for priority RPC endpoints, shared among nodes.',
        integrations: [
            { name: 'Thaiprompt Affiliate', desc: 'Enterprise MLM platform with 500,000+ users', items: ['Auto TPIX wallet on signup', 'Commission payout in TPIX', 'Rank bonuses', 'Activity rewards (100 TPIX signup, 50 TPIX referral)'] },
            { name: 'FoodPassport', desc: 'Blockchain food traceability system', items: ['Pay for quality verification', 'Certificate NFTs on TPIX Chain', 'Farmer rewards', 'Supply chain data access'] },
            { name: 'Delivery Platform', desc: 'Multi-service delivery ecosystem', items: ['TPIX payment for orders', '3% cashback per order', 'Rider TPIX earnings', 'Merchant instant settlement'] },
            { name: 'IoT Smart Farm', desc: 'AI-powered agriculture system', items: ['Sensor data marketplace', 'Equipment rental with TPIX', 'Carbon credit trading', 'Yield prediction services'] },
        ],
        roadmap: [
            { q: 'Q1-Q2 2023', title: 'Concept & Foundation', status: 'done', items: ['Whitepaper & tokenomics design', 'Technical architecture planning', 'Team formation', 'Initial funding & partnerships'] },
            { q: 'Q3-Q4 2023', title: 'Blockchain Development', status: 'done', items: ['Polygon Edge core implementation', 'TPIX native coin (7B fixed supply)', 'IBFT 2.0 consensus & EVM integration', 'Testnet deployment (Chain ID 4290)'] },
            { q: 'Q1-Q2 2024', title: 'Platform Integration', status: 'done', items: ['Laravel service integration', 'REST API (500+ endpoints)', 'Block explorer (Blockscout) deployment', 'Docker deployment & monitoring'] },
            { q: 'Q3-Q4 2024', title: 'Ecosystem Build', status: 'done', items: ['DEX smart contracts (TPIXRouter, Factory, Pair)', 'Master Node system (NodeRegistryV2 — 4 tiers)', 'ValidatorGovernance & ValidatorKYC contracts', 'Faucet service & SDK development'] },
            { q: 'Q1-Q2 2025', title: 'Mainnet & DeFi Launch', status: 'done', items: ['TPIX Chain mainnet live (Chain ID 4289)', 'TPIX TRADE DEX platform launch', 'wTPIX (BEP-20) bridge contract on BSC', 'Token Sale contract (TPIXTokenSale.sol)'] },
            { q: 'Q3-Q4 2025', title: 'Products & Applications', status: 'done', items: ['Living Identity — seedless wallet recovery (TPIXIdentity.sol)', 'Master Node UI desktop app (Electron)', 'TPIX Wallet mobile app (Flutter)', 'Token Factory — permissionless ERC-20 creation'] },
            { q: 'Q1 2026', title: 'Production & Token Sale', status: 'current', items: ['Token Sale 3 phases (Private / Pre-Sale / Public)', 'Whitepaper v2.0 publication', 'Internal order book matching engine (limit/market/stop-limit)', 'Admin panel with fee management, dashboard analytics', 'Carbon Credit trading system & FoodPassport traceability'] },
            { q: 'Q2 2026', title: 'DeFi Infrastructure', status: 'planned', items: ['BSC Bridge activation (wTPIX ↔ native TPIX)', '4-Tier master node staking activation', 'TPIXRouter fee collection live', 'DEX AMM liquidity pools deployment', 'Mobile app (React Native) release'] },
            { q: 'Q3 2026', title: 'Ecosystem Growth', status: 'planned', items: ['Token Factory public launch', 'Affiliate/Referral program activation', 'CEX listing applications', 'Validator KYC onboarding (external validators)', 'Carbon Credit & FoodPassport pilots'] },
            { q: 'Q4 2026', title: 'Scale & Governance', status: 'planned', items: ['DAO governance transition', 'Multi-chain bridge expansion (Ethereum, Polygon)', 'NFT marketplace launch', 'Validator set decentralization (21 validators)', 'Master Node UI — macOS/Linux support'] },
            { q: '2027', title: 'Global Expansion', status: 'planned', items: ['Full DAO governance activation', 'Multi-language support (Japanese, Korean, Vietnamese)', 'Carbon credit exchange full launch', 'Government partnership pilots (Food Passport)', 'Year 2 emission reduction (500M TPIX/year)'] },
        ],
        techStack: {
            blockchain: [['Polygon Edge', 'Modular blockchain framework (Go)'], ['IBFT Consensus', 'Byzantine fault tolerant PoA'], ['EVM', 'Full Solidity smart contract support'], ['LevelDB', 'High-performance storage layer']],
            smartContracts: [['Solidity ^0.8.20', 'Smart contract language'], ['Hardhat', 'Development & testing framework'], ['OpenZeppelin', 'Audited security libraries'], ['ethers.js', 'Web3 interaction library']],
            backend: [['Laravel 11', 'Enterprise PHP framework'], ['PHP 8.2+', 'Server-side language'], ['MySQL 8.0+', 'Relational database'], ['Redis', 'Caching & queue management']],
            frontend: [['Vue.js 3', 'Reactive frontend framework'], ['Inertia.js', 'SPA without API'], ['TailwindCSS', 'Utility-first styling'], ['Chart.js', 'Data visualization']],
            infra: [['Docker', 'Container orchestration'], ['Blockscout', 'Open-source block explorer'], ['Prometheus + Grafana', 'Metrics & monitoring'], ['GitHub Actions', 'CI/CD pipeline']],
        },
        teamDesc: 'TPIX Chain is developed by Xman Studio, an experienced blockchain development team specializing in DeFi, Web3, and enterprise applications for the Southeast Asian market. The team brings deep expertise in Solidity smart contracts, EVM chain deployment, full-stack Web3 development, and integration with real-world business systems.',
        teamHighlights: ['500,000+ platform users on Thaiprompt Affiliate', '389 Eloquent models, 294 controllers, 500,000+ lines of production code', '20+ integrated business modules (MLM, e-commerce, AI, IoT, hotel booking)', 'Live blockchain infrastructure with Blockscout explorer'],
        securityItems: [
            { title: 'Smart Contract Audits', desc: 'All contracts undergo third-party security audits before mainnet deployment. OpenZeppelin libraries used for battle-tested implementations.' },
            { title: 'IBFT Consensus', desc: 'Byzantine fault tolerance ensures network security. The network can tolerate up to ⌊(n-1)/3⌋ faulty validators while maintaining consensus.' },
            { title: 'Rate Limiting', desc: 'RPC-level rate limiting (5-50 req/hr per endpoint) prevents spam attacks on the gasless chain.' },
            { title: 'Multi-Sig Treasury', desc: 'Protocol funds managed by multi-signature wallets requiring 3-of-5 signatures for fund movements.' },
            { title: 'Bug Bounty Program', desc: 'Ongoing bug bounty program with rewards up to 10,000 TPIX for critical vulnerability disclosure.' },
            { title: 'Infrastructure Security', desc: 'Docker containerization, encrypted RPC connections (TLS), firewall-protected validator nodes, and automated backup systems.' },
        ],
    },
    th: {
        pageTitle: 'TPIX Chain ไวท์เปเปอร์',
        subtitle: 'บล็อกเชน EVM ยุคใหม่ สำหรับเศรษฐกิจดิจิทัลอาเซียน',
        version: 'เวอร์ชัน 2.0 — มีนาคม 2569',
        downloadPdf: 'ดาวน์โหลด PDF',
        readInThai: '🇹🇭 อ่านเป็นภาษาไทย',
        readInEn: '🇬🇧 Read in English',
        toc: [
            '1. บทสรุปผู้บริหาร',
            '2. ปัญหาและทางออก',
            '3. สถาปัตยกรรม TPIX Chain',
            '4. โทเคโนมิกส์',
            '5. กรณีการใช้งานและแอปพลิเคชัน',
            '6. โปรโตคอล DEX',
            '7. รายละเอียดการขายโทเคน',
            '8. Master Node และรางวัล',
            '9. Living Identity — กู้กระเป๋าไม่ต้องใช้ Seed Phrase',
            '10. Validator Governance — การปกครองแบบกระจายอำนาจ',
            '11. Cross-Chain Bridge',
            '12. ระบบนิเวศและ Affiliate',
            '13. การเชื่อมต่อแพลตฟอร์ม',
            '14. แผนงาน (Roadmap)',
            '15. เทคโนโลยีที่ใช้',
            '16. ทีมงานและพาร์ทเนอร์',
            '17. ความปลอดภัยและการตรวจสอบ',
            '18. ข้อจำกัดความรับผิดชอบ',
        ],
        execSummary: {
            p1: 'TPIX Chain เป็นบล็อกเชนที่รองรับ EVM สร้างบนเทคโนโลยี Polygon Edge ออกแบบมาโดยเฉพาะสำหรับเศรษฐกิจดิจิทัลไทยและอาเซียน ด้วยการทำธุรกรรมไม่เสียค่า Gas, เวลาสร้างบล็อก 2 วินาที และ IBFT Proof-of-Authority consensus ทำให้ TPIX Chain เป็นแพลตฟอร์มที่เหนือกว่าสำหรับแอปพลิเคชันกระจายอำนาจ, DeFi และการโทเคไนซ์สินทรัพย์ในโลกจริง',
            p2: 'เหรียญ TPIX (จำนวนคงที่ 7 พันล้าน, 18 ทศนิยม) เป็นพลังขับเคลื่อนระบบนิเวศทั้งหมด ได้แก่: DEX ในตัว (Uniswap V2), ระบบ Master Node หลายระดับ, โรงงานสร้างโทเคน ERC-20, สะพานข้ามเชนไป BSC, โปรแกรม Affiliate และการเชื่อมต่อกับแพลตฟอร์ม Thaiprompt Affiliate ที่มีผู้ใช้กว่า 500,000 คน',
            p3: 'TPIX ไม่ใช่แค่สกุลเงินดิจิทัล — แต่เป็นกระดูกสันหลังของเศรษฐกิจดิจิทัลครบวงจร ครอบคลุมระบบตรวจสอบย้อนกลับห่วงโซ่อาหาร, ฟาร์มอัจฉริยะ IoT, บริการส่งสินค้า, อีคอมเมิร์ซ, ตลาด AI Bot, จองโรงแรม, ซื้อขายคาร์บอนเครดิต และการตลาดแบบ Affiliate ระดับองค์กร',
            stats: [
                { value: '7B', label: 'จำนวนทั้งหมด' },
                { value: '0 Gas', label: 'ค่าธรรมเนียม' },
                { value: '2 วิ', label: 'เวลาสร้างบล็อก' },
                { value: '~1,500', label: 'TPS' },
                { value: 'IBFT', label: 'Consensus' },
                { value: '~10 วิ', label: 'Finality' },
            ],
        },
        problems: [
            { title: 'ค่า Gas สูง', desc: 'ค่า Gas ของ Ethereum ($5-50+) และ BSC ($0.10-1.00) ทำให้ธุรกรรมขนาดเล็กและการใช้ DeFi ประจำวันไม่คุ้มค่าสำหรับผู้ใช้ทั่วไปในประเทศกำลังพัฒนา' },
            { title: 'ความซับซ้อนสูง', desc: 'DEX และโปรโตคอล DeFi ที่มีอยู่ต้องการความรู้ทางเทคนิคอย่างลึกซึ้ง ประสบการณ์การใช้งานน่ากลัวสำหรับ 95% ของคนที่ไม่เคยใช้คริปโตเคอร์เรนซี' },
            { title: 'ไม่เน้นอาเซียน', desc: 'ระบบนิเวศบล็อกเชนหลักๆ สร้างขึ้นสำหรับตลาดตะวันตก ยังไม่มีระบบ DeFi ที่แปลเป็นภาษาไทย/อาเซียน และมี use case ที่เกี่ยวข้องกับวัฒนธรรมท้องถิ่น' },
            { title: 'ประโยชน์กระจัดกระจาย', desc: 'โทเคนส่วนใหญ่ไม่มีประโยชน์ในโลกจริงนอกเหนือจากการเก็งกำไร ไม่มีระบบนิเวศแบบบูรณาการที่เชื่อมต่อ DeFi กับธุรกิจจริง เช่น เกษตรกรรม ห่วงโซ่อาหาร และบริการ' },
        ],
        solutions: [
            { title: 'ค่า Gas เป็นศูนย์', desc: 'ธุรกรรมทั้งหมดบน TPIX Chain ฟรีทั้งหมด Gas price ถูกกำหนดเป็น 0 ใน genesis block อย่างถาวร' },
            { title: 'ใช้ง่ายมาก', desc: 'อินเทอร์เฟซสะอาด ทันสมัย รองรับภาษาไทย เชื่อมต่อ wallet, สลับโทเคน, Stake — ทำได้ใน 3 คลิก' },
            { title: 'ออกแบบเพื่ออาเซียน', desc: 'สร้างตั้งแต่แรกเพื่อผู้ใช้ไทยและเอเชียตะวันออกเฉียงใต้ แปลภาษาไทยครบ เชื่อมต่อการชำระเงินท้องถิ่น' },
            { title: 'เชื่อมต่อธุรกิจจริง', desc: 'TPIX เชื่อม DeFi กับธุรกิจจริง: ตรวจสอบอาหาร (FoodPassport), ฟาร์มอัจฉริยะ (IoT), บริการส่งของ, อีคอมเมิร์ซ, จองโรงแรม' },
        ],
        chainSpecs: [
            ['ชื่อเชน', 'TPIX Chain'],
            ['Chain ID (Mainnet)', '4289'],
            ['Chain ID (Testnet)', '4290'],
            ['Consensus', 'IBFT (Istanbul Byzantine Fault Tolerant)'],
            ['เวลาสร้างบล็อก', '2 วินาที'],
            ['Finality', '~10 วินาที (5 บล็อก)'],
            ['ค่า Gas', '0 (ฟรี — กำหนดใน genesis)'],
            ['ความจุ TPS', '~1,500 ธุรกรรม/วินาที'],
            ['VM', 'EVM (Ethereum Virtual Machine) — รองรับ Solidity เต็มรูปแบบ'],
            ['Native Coin', 'TPIX (18 ทศนิยม)'],
            ['จำนวนทั้งหมด', '7,000,000,000 TPIX (pre-mined ใน genesis)'],
            ['Validator', '4 โหนด IBFT (BFT ทนได้ ⌊(n-1)/3⌋ = 1 โหนดที่มีปัญหา)'],
            ['RPC URL', 'https://rpc.tpix.online'],
            ['Explorer', 'https://explorer.tpix.online'],
        ],
        useCases: [
            {
                icon: '🏦',
                title: 'กระดานแลกเปลี่ยนกระจายอำนาจ (DEX)',
                desc: 'ซื้อขายโทเคนด้วย AMM ค่าธรรมเนียม 0.3% เพิ่มสภาพคล่องเพื่อรับค่าธรรมเนียม',
                features: ['สลับโทเคนด้วยสูตร x·y=k', 'เพิ่มสภาพคล่อง รับ LP Token', 'Farming & yield optimization', 'ค่า Gas เป็น 0 สำหรับทุกการซื้อขาย'],
            },
            {
                icon: '🍲',
                title: 'FoodPassport — ระบบตรวจสอบย้อนกลับห่วงโซ่อาหาร',
                desc: 'ระบบตรวจสอบความปลอดภัยอาหารบนบล็อกเชน ติดตามอาหารจากฟาร์มถึงผู้บริโภค',
                features: ['ตรวจสอบย้อนกลับจากฟาร์มถึงโต๊ะอาหาร', 'ตรวจสอบคุณภาพด้วย AI Image Recognition', 'จัดการใบรับรองเป็น NFT บน TPIX Chain', 'ชำระเงินอัตโนมัติผ่าน Smart Contract', 'ผู้บริโภคสแกน QR ดูประวัติสินค้า'],
            },
            {
                icon: '🌾',
                title: 'ระบบฟาร์มอัจฉริยะ IoT',
                desc: 'ระบบฟาร์มอัจฉริยะด้วยเซ็นเซอร์ IoT และ AI เชื่อมต่อกับบล็อกเชน',
                features: ['ติดตามเซ็นเซอร์แบบเรียลไทม์ (อุณหภูมิ, ความชื้น, แสง, ดิน)', 'ควบคุมระบบน้ำ ปุ๋ย แสงอัตโนมัติ', 'ตลาดข้อมูลเกษตร — ขายข้อมูลเป็น TPIX', 'วิเคราะห์และทำนายผลผลิต', 'สร้างและซื้อขายคาร์บอนเครดิตบนเชน'],
            },
            {
                icon: '🚚',
                title: 'แพลตฟอร์มส่งสินค้าครบวงจร',
                desc: 'แพลตฟอร์มส่งอาหาร ของชำ พัสดุ และบริการ ใช้ TPIX เป็นระบบชำระเงิน',
                features: ['ส่งอาหาร ของชำ และพัสดุ', 'ตลาดบริการ (ทำความสะอาด ซ่อมแซม)', 'คืนเงิน 3% เป็น TPIX ทุกออเดอร์', 'ผู้ขนส่งรับค่าจ้างเป็น TPIX', 'ติดตามออเดอร์แบบเรียลไทม์บนเชน'],
            },
            {
                icon: '🤖',
                title: 'ตลาด AI Bot',
                desc: 'ซื้อ ขาย และสมัครสมาชิก AI Bot สำหรับการเทรด บริการลูกค้า และอัตโนมัติธุรกิจ',
                features: ['LINE Official Account AI chatbot', 'บอทเทรดพร้อม sentiment analysis', 'ตอบกลับอัตโนมัติด้วย NLP', 'จ่ายค่าสมาชิกรายเดือนด้วย TPIX', 'โปรแกรมแบ่งรายได้ผู้สร้าง'],
            },
            {
                icon: '🏨',
                title: 'ระบบจองโรงแรมและท่องเที่ยว',
                desc: 'จองโรงแรมแบบกระจายอำนาจ ชำระด้วย TPIX พร้อมรางวัล cashback',
                features: ['จองโรงแรมโดยตรงด้วย TPIX', 'คืนเงิน 3% ทุกการจอง', 'โปรแกรมสะสม TPIX', 'โอนเงินให้โรงแรมทันที'],
            },
            {
                icon: '🛒',
                title: 'อีคอมเมิร์ซและตลาด',
                desc: 'ตลาดออนไลน์หลายร้านค้ารองรับ TPIX คืนเงิน 5% และติดตามคอมมิชชั่น Affiliate',
                features: ['ตลาดหลายร้านค้าชำระด้วย TPIX', 'คืนเงิน 5% เป็น TPIX', 'POS สำหรับร้านค้าจริง', 'ติดตามคอมมิชชั่น Affiliate อัตโนมัติ'],
            },
            {
                icon: '🏭',
                title: 'โรงงานสร้างโทเคน',
                desc: 'สร้างโทเคน ERC-20 บน TPIX Chain สำหรับ loyalty program, voucher, membership',
                features: ['สร้างโทเคน ERC-20 ด้วย 100 TPIX', 'โทเคนสะสมแต้ม, บัตรกำนัล, NFT สมาชิก', 'ธุรกรรมต่อมาทั้งหมดฟรี', 'เหมาะสำหรับ loyalty program และโทเคนธุรกิจ'],
            },
            {
                icon: '🌱',
                title: 'ตลาดซื้อขายคาร์บอนเครดิต',
                desc: 'ตลาดคาร์บอนเครดิตบนบล็อกเชน เชื่อมกับ IoT Smart Farm',
                features: ['โทเคไนซ์คาร์บอนเครดิตเป็น NFT', 'ซื้อขายโปร่งใสบนเชน', 'เชื่อมกับ Smart Farm ตรวจสอบอัตโนมัติ', 'รองรับมาตรฐานคาร์บอนสากล'],
            },
            {
                icon: '🧠',
                title: 'ระบบ AI อัตโนมัติ',
                desc: 'ระบบ AI ที่พัฒนาตัวเอง สร้างและจัดการ AI agent อัตโนมัติ ทำงาน 24/7',
                features: ['AI สร้าง AI อัตโนมัติ (Self-improving)', 'จัดการระบบอัตโนมัติ 24/7', 'วิเคราะห์และตัดสินใจอัตโนมัติ', 'ชำระค่า AI compute ด้วย TPIX'],
            },
        ],
        dex: {
            desc: 'TPIX DEX เป็น Uniswap V2 fork ที่ deploy บน TPIX Chain โดยตรง ใช้ AMM สูตร x·y=k ค่าธรรมเนียม 0.3% (0.25% ให้ LP, 0.05% ให้คลัง protocol)',
            contracts: [
                ['TPIXDEXFactory', 'สร้างและจัดการ trading pair contracts'],
                ['TPIXDEXRouter02', 'จัดการ multi-hop swaps และสภาพคล่อง'],
                ['TPIXDEXPair', 'สระสภาพคล่อง พร้อม ERC-20 LP tokens'],
                ['WTPIX', 'Wrapped TPIX สำหรับใช้ใน DEX'],
            ],
        },
        salePhases: [
            { phase: 'Private Sale', price: '$0.05', alloc: '100M TPIX', tge: '10%', vesting: '30 วัน cliff, 180 วัน linear', color: 'text-purple-400' },
            { phase: 'Pre-Sale', price: '$0.08', alloc: '200M TPIX', tge: '15%', vesting: '14 วัน cliff, 120 วัน linear', color: 'text-blue-400' },
            { phase: 'Public Sale', price: '$0.10', alloc: '400M TPIX', tge: '25%', vesting: 'ไม่มี cliff, 90 วัน linear', color: 'text-green-400' },
        ],
        masternodeTiers: [
            { tier: 'Validator Node', stake: '10,000,000 TPIX', apy: '15-20%', lock: '180 วัน', maxNodes: '21', reward: '20% ของรางวัล', hardware: '16 CPU, 32GB RAM, 1TB SSD' },
            { tier: 'Guardian Node', stake: '1,000,000 TPIX', apy: '10-12%', lock: '90 วัน', maxNodes: '100', reward: '35% ของรางวัล', hardware: '8 CPU, 16GB RAM, 500GB SSD' },
            { tier: 'Sentinel Node', stake: '100,000 TPIX', apy: '7-9%', lock: '30 วัน', maxNodes: '500', reward: '30% ของรางวัล', hardware: '4 CPU, 8GB RAM, 200GB SSD' },
            { tier: 'Light Node', stake: '10,000 TPIX', apy: '4-6%', lock: '7 วัน', maxNodes: 'ไม่จำกัด', reward: '15% ของรางวัล', hardware: '2 CPU, 4GB RAM, 100GB SSD' },
        ],
        masternodeEmission: [
            { year: 'ปีที่ 1 (2025-2026)', amount: '600,000,000 TPIX', perBlock: '~38.3 TPIX', pct: '42.9%' },
            { year: 'ปีที่ 2 (2026-2027)', amount: '500,000,000 TPIX', perBlock: '~31.9 TPIX', pct: '35.7%' },
            { year: 'ปีที่ 3 (2027-2028)', amount: '300,000,000 TPIX', perBlock: '~19.1 TPIX', pct: '21.4%' },
        ],
        masternodeDesc: 'TPIX ใช้ IBFT2 Proof-of-Authority consensus พร้อมระบบ Master Node 4 ระดับ Validator เป็น IBFT2 block sealer ตัวจริงมีสิทธิ์โหวต governance (ต้อง 10M TPIX + KYC บริษัท) Guardian, Sentinel และ Light node stake TPIX เพื่อรับรางวัลจากกองทุน 1.4 พันล้าน TPIX แจกจ่ายตลอด 3 ปี (ถึง 2028) ด้วยอัตราที่ลดลง',
        masternodeRewardSplit: 'รางวัลแต่ละบล็อกแบ่ง: 20% ให้ Validator (IBFT2 sealers), 35% ให้ Guardian nodes, 30% แบ่งให้ Sentinel nodes, และ 15% แบ่งให้ Light nodes (ถ่วงน้ำหนักตามจำนวน stake และคะแนน uptime)',
        masternodeSlashing: 'Validator ถูกหัก stake 15% หากทำผิด Guardian ถูกหัก 10% หาก offline เกิน 24 ชม. Sentinel ถูกหัก 5% หาก offline เกิน 48 ชม. Light node ไม่มีการลงโทษ ทุกระดับถูกยกเลิกหาก offline เกิน 7 วัน',
        masternodeAfterY5: 'หลังกองทุนรางวัล 3 ปีแจกจ่ายครบ (2028) Master Node ยังได้รับรายได้จากหลายแหล่งอย่างยั่งยืน: (1) ส่วนแบ่งค่าธรรมเนียมธุรกรรม — แม้ผู้ใช้ทั่วไปไม่เสียค่า Gas แต่นักพัฒนา dApp และผู้สร้างโทเคนจ่ายค่าธรรมเนียมแพลตฟอร์ม (0.1-1% ต่อการ deploy contract และสร้างโทเคน) 50% ของค่าธรรมเนียมนี้แจกจ่ายให้โหนดที่ทำงานอยู่ (2) ค่าธรรมเนียม Cross-Chain Bridge — ทุกการโอน TPIX↔BSC เสียค่าธรรมเนียม 0.05% แบ่งให้ validators (3) รายได้ Token Factory — การสร้างโทเคนเสีย 100 TPIX โดย 50% เข้ากองทุนโหนด (4) ค่าธรรมเนียม DEX — 0.05% ของค่า swap fee เข้าคลังโหนด (5) รางวัล Governance — Validator โหวตข้อเสนอและได้รับรางวัลการโหวต (6) Premium API — dApp เชิงพาณิชย์จ่ายค่า RPC endpoint พิเศษ แบ่งรายได้ให้โหนด',
        integrations: [
            { name: 'Thaiprompt Affiliate', desc: 'แพลตฟอร์ม MLM ระดับองค์กร ผู้ใช้ 500,000+ คน', items: ['สร้าง TPIX wallet อัตโนมัติเมื่อสมัคร', 'จ่ายคอมมิชชั่นเป็น TPIX', 'โบนัสจากการเลื่อนระดับ', 'รางวัลกิจกรรม (100 TPIX สมัคร, 50 TPIX แนะนำ)'] },
            { name: 'FoodPassport', desc: 'ระบบตรวจสอบอาหารบนบล็อกเชน', items: ['ชำระค่าตรวจสอบคุณภาพ', 'ใบรับรอง NFT บน TPIX Chain', 'รางวัลเกษตรกร', 'เข้าถึงข้อมูล Supply Chain'] },
            { name: 'Delivery Platform', desc: 'ระบบส่งสินค้าครบวงจร', items: ['ชำระด้วย TPIX', 'คืนเงิน 3% ต่อออเดอร์', 'ค่าจ้างผู้ส่งเป็น TPIX', 'โอนเงินร้านค้าทันที'] },
            { name: 'IoT Smart Farm', desc: 'ระบบเกษตรอัจฉริยะด้วย AI', items: ['ตลาดข้อมูลเซ็นเซอร์', 'เช่าอุปกรณ์ด้วย TPIX', 'ซื้อขายคาร์บอนเครดิต', 'บริการทำนายผลผลิต'] },
        ],
        roadmap: [
            { q: 'Q1-Q2 2566', title: 'แนวคิดและรากฐาน', status: 'done', items: ['ออกแบบ Whitepaper & tokenomics', 'วางแผนสถาปัตยกรรมเทคนิค', 'จัดตั้งทีม', 'ระดมทุนและจับมือพาร์ทเนอร์'] },
            { q: 'Q3-Q4 2566', title: 'พัฒนาบล็อกเชน', status: 'done', items: ['สร้าง Polygon Edge core', 'เหรียญ TPIX (7B fixed supply)', 'IBFT 2.0 consensus & EVM', 'Deploy Testnet (Chain ID 4290)'] },
            { q: 'Q1-Q2 2567', title: 'เชื่อมต่อแพลตฟอร์ม', status: 'done', items: ['เชื่อมต่อ Laravel service', 'REST API (500+ endpoints)', 'Block Explorer (Blockscout)', 'Docker deployment & monitoring'] },
            { q: 'Q3-Q4 2567', title: 'สร้างระบบนิเวศ', status: 'done', items: ['DEX smart contracts (TPIXRouter, Factory, Pair)', 'ระบบ Master Node (NodeRegistryV2 — 4 ระดับ)', 'ValidatorGovernance & ValidatorKYC contracts', 'Faucet service & พัฒนา SDK'] },
            { q: 'Q1-Q2 2568', title: 'Mainnet & DeFi Launch', status: 'done', items: ['เปิด TPIX Chain mainnet (Chain ID 4289)', 'เปิดแพลตฟอร์ม TPIX TRADE DEX', 'wTPIX (BEP-20) bridge contract บน BSC', 'Token Sale contract (TPIXTokenSale.sol)'] },
            { q: 'Q3-Q4 2568', title: 'ผลิตภัณฑ์และแอปพลิเคชัน', status: 'done', items: ['Living Identity — กู้กระเป๋าไม่ต้องใช้ Seed Phrase', 'Master Node UI แอปเดสก์ท็อป (Electron)', 'TPIX Wallet แอปมือถือ (Flutter)', 'Token Factory — สร้างเหรียญ ERC-20 ฟรี'] },
            { q: 'Q1 2569', title: 'Production & Token Sale', status: 'current', items: ['Token Sale 3 เฟส (Private / Pre-Sale / Public)', 'Whitepaper v2.0', 'ระบบจับคู่ออเดอร์ (limit/market/stop-limit)', 'แอดมินแพเนลครบ — จัดการ fee, dashboard analytics', 'ระบบ Carbon Credit & FoodPassport'] },
            { q: 'Q2 2569', title: 'โครงสร้างพื้นฐาน DeFi', status: 'planned', items: ['เปิด Bridge BSC (wTPIX ↔ native TPIX)', 'เปิดระบบ staking Master Node 4 ระดับ', 'TPIXRouter เก็บค่าธรรมเนียมจริง', 'Deploy AMM liquidity pools', 'แอปมือถือ (React Native) เปิดตัว'] },
            { q: 'Q3 2569', title: 'เติบโตระบบนิเวศ', status: 'planned', items: ['เปิด Token Factory สาธารณะ', 'เปิดระบบ Affiliate/Referral', 'สมัครลิสต์ CEX', 'รับสมัคร Validator ภายนอก (KYC)', 'นำร่อง Carbon Credit & FoodPassport'] },
            { q: 'Q4 2569', title: 'ขยายขนาดและ Governance', status: 'planned', items: ['เปลี่ยนผ่านสู่ DAO governance', 'ขยาย Bridge ข้ามเชน (Ethereum, Polygon)', 'เปิด NFT marketplace', 'กระจายอำนาจ Validator (เป้า 21 nodes)', 'Master Node UI — รองรับ macOS/Linux'] },
            { q: '2570', title: 'ขยายสู่ระดับสากล', status: 'planned', items: ['เปิด DAO governance เต็มรูปแบบ', 'รองรับหลายภาษา (ญี่ปุ่น, เกาหลี, เวียดนาม)', 'เปิดระบบ Carbon Credit เต็มรูป', 'นำร่องความร่วมมือภาครัฐ (Food Passport)', 'ลด emission ปีที่ 2 (500M TPIX/ปี)'] },
        ],
        techStack: {
            blockchain: [['Polygon Edge', 'เฟรมเวิร์กบล็อกเชน (Go)'], ['IBFT Consensus', 'Byzantine fault tolerant PoA'], ['EVM', 'รองรับ Solidity smart contract'], ['LevelDB', 'ชั้น storage ประสิทธิภาพสูง']],
            smartContracts: [['Solidity ^0.8.20', 'ภาษา smart contract'], ['Hardhat', 'เฟรมเวิร์กพัฒนาและทดสอบ'], ['OpenZeppelin', 'ไลบรารีความปลอดภัย'], ['ethers.js', 'ไลบรารี Web3']],
            backend: [['Laravel 11', 'เฟรมเวิร์ก PHP ระดับ Enterprise'], ['PHP 8.2+', 'ภาษาฝั่งเซิร์ฟเวอร์'], ['MySQL 8.0+', 'ฐานข้อมูลเชิงสัมพันธ์'], ['Redis', 'แคชและจัดการคิว']],
            frontend: [['Vue.js 3', 'เฟรมเวิร์ก frontend แบบ reactive'], ['Inertia.js', 'SPA ไม่ต้องสร้าง API'], ['TailwindCSS', 'Utility-first styling'], ['Chart.js', 'แสดงข้อมูลเชิงภาพ']],
            infra: [['Docker', 'จัดการ container'], ['Blockscout', 'Block explorer โอเพนซอร์ส'], ['Prometheus + Grafana', 'ตรวจสอบและแดชบอร์ด'], ['GitHub Actions', 'CI/CD pipeline']],
        },
        teamDesc: 'TPIX Chain พัฒนาโดย Xman Studio ทีมพัฒนาบล็อกเชนที่มีประสบการณ์ เชี่ยวชาญด้าน DeFi, Web3 และแอปพลิเคชันระดับองค์กรสำหรับตลาดเอเชียตะวันออกเฉียงใต้ ทีมมีความเชี่ยวชาญลึกซึ้งในด้าน Solidity smart contracts, การ deploy เชน EVM, การพัฒนา Web3 full-stack และการเชื่อมต่อกับระบบธุรกิจในโลกจริง',
        teamHighlights: ['ผู้ใช้แพลตฟอร์ม 500,000+ คนบน Thaiprompt Affiliate', '389 Eloquent models, 294 controllers, โค้ด 500,000+ บรรทัด', '20+ โมดูลธุรกิจ (MLM, อีคอมเมิร์ซ, AI, IoT, จองโรงแรม)', 'โครงสร้างบล็อกเชนพร้อม Blockscout explorer'],
        securityItems: [
            { title: 'ตรวจสอบ Smart Contract', desc: 'ทุก contract ผ่านการตรวจสอบความปลอดภัยจากบุคคลที่สามก่อน deploy ใช้ไลบรารี OpenZeppelin ที่ผ่านการทดสอบแล้ว' },
            { title: 'IBFT Consensus', desc: 'Byzantine fault tolerance รับประกันความปลอดภัยของเครือข่าย ทนได้ถึง ⌊(n-1)/3⌋ validator ที่มีปัญหา' },
            { title: 'Rate Limiting', desc: 'จำกัดอัตราการเรียกใช้ RPC (5-50 req/hr) ป้องกัน spam บนเชนที่ไม่เสียค่า Gas' },
            { title: 'กระเป๋า Multi-Sig', desc: 'กองทุน protocol จัดการด้วย multi-signature wallet ต้องใช้ 3 จาก 5 ลายเซ็น' },
            { title: 'โปรแกรม Bug Bounty', desc: 'โปรแกรมรางวัลสูงสุด 10,000 TPIX สำหรับการเปิดเผยช่องโหว่ร้ายแรง' },
            { title: 'ความปลอดภัยโครงสร้าง', desc: 'Docker containerization, RPC เข้ารหัส (TLS), validator มี firewall, ระบบ backup อัตโนมัติ' },
        ],
    },
};
</script>

<template>
    <Head :title="t.pageTitle" />

    <AppLayout :hide-sidebar="true">
        <!-- Hero — หน้าปก (print: จะเป็นหน้าแรกของ PDF) -->
        <section class="print-cover relative py-16 overflow-hidden">
            <div class="absolute inset-0 pointer-events-none print:hidden">
                <div class="absolute top-0 left-1/4 w-96 h-96 rounded-full bg-primary-500/10 blur-[120px]" />
                <div class="absolute bottom-0 right-1/3 w-80 h-80 rounded-full bg-accent-500/10 blur-[100px]" />
            </div>

            <div class="relative max-w-4xl mx-auto px-4 text-center">
                <img src="/logo.webp" alt="TPIX" class="w-28 h-28 mx-auto mb-6 shadow-lg shadow-primary-500/20" />
                <h1 class="text-4xl sm:text-5xl font-bold text-white mb-2">{{ t.pageTitle }}</h1>
                <p class="text-lg text-primary-400 font-medium mb-1">{{ t.subtitle }}</p>
                <p class="text-sm text-gray-500 mb-6">{{ t.version }}</p>

                <!-- Print-only: ข้อมูลเอกสารเพิ่มเติม -->
                <div class="hidden print:block mt-12 text-sm text-gray-500 space-y-1">
                    <p>Developed by Xman Studio</p>
                    <p>https://tpix.trade</p>
                    <p class="mt-4 text-xs text-gray-400">{{ lang === 'en' ? 'This document is confidential. © 2026 TPIX Chain. All rights reserved.' : 'เอกสารนี้เป็นความลับ © 2026 TPIX Chain สงวนลิขสิทธิ์' }}</p>
                </div>

                <div class="flex flex-wrap items-center justify-center gap-3 print:hidden">
                    <a :href="`/whitepaper/download?lang=${lang}`" class="btn-primary px-8 py-3 inline-flex items-center gap-2 font-semibold">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        {{ t.downloadPdf }}
                    </a>
                    <button @click="toggleLang" class="btn-secondary px-6 py-3 font-semibold">
                        {{ lang === 'en' ? t.readInThai : t.readInEn }}
                    </button>
                </div>
            </div>
        </section>

        <!-- Content: TOC + Sections -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 pb-20">
            <div class="flex gap-8">
                <!-- Sidebar: Table of Contents (sticky) -->
                <aside class="hidden xl:block w-64 flex-shrink-0">
                    <div class="sticky top-24">
                        <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">
                            {{ lang === 'en' ? 'Contents' : 'สารบัญ' }}
                        </h3>
                        <nav class="space-y-0.5 max-h-[70vh] overflow-y-auto pr-2">
                            <button
                                v-for="s in sections" :key="s.id"
                                class="block w-full text-left px-3 py-1.5 rounded-lg text-xs transition-colors"
                                :class="activeSection === s.id
                                    ? 'text-primary-400 bg-primary-500/10 font-medium'
                                    : 'text-gray-500 hover:text-white hover:bg-white/5'"
                                @click="scrollTo(s.id)"
                            >{{ s.title }}</button>
                        </nav>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1 min-w-0">

                    <!-- 1. Executive Summary -->
                    <section id="executive-summary" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[0] }}</h2>
                        <p class="wp-text">{{ t.execSummary.p1 }}</p>
                        <p class="wp-text">{{ t.execSummary.p2 }}</p>
                        <p class="wp-text font-medium text-white/90">{{ t.execSummary.p3 }}</p>

                        <!-- สถิติสำคัญ — Key Stats -->
                        <div class="wp-highlight">
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-center">
                                <div v-for="s in t.execSummary.stats" :key="s.label">
                                    <p class="text-2xl font-bold text-primary-400">{{ s.value }}</p>
                                    <p class="text-xs text-gray-400 mt-1">{{ s.label }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 2. Problem & Solution -->
                    <section id="problem-solution" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[1] }}</h2>

                        <h3 class="wp-subheading text-trading-red">{{ lang === 'en' ? '⚠️ The Problems' : '⚠️ ปัญหา' }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4 mb-8">
                            <div v-for="p in t.problems" :key="p.title" class="p-4 rounded-xl bg-red-500/5 border border-red-500/10">
                                <h4 class="font-semibold text-white mb-2">{{ p.title }}</h4>
                                <p class="text-sm text-gray-400">{{ p.desc }}</p>
                            </div>
                        </div>

                        <h3 class="wp-subheading text-trading-green">{{ lang === 'en' ? '✅ Our Solutions' : '✅ ทางออกของเรา' }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="s in t.solutions" :key="s.title" class="p-4 rounded-xl bg-green-500/5 border border-green-500/10">
                                <h4 class="font-semibold text-white mb-2">{{ s.title }}</h4>
                                <p class="text-sm text-gray-400">{{ s.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 3. TPIX Chain Architecture -->
                    <section id="tpix-chain" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[2] }}</h2>

                        <!-- Architecture Diagram — แผนภูมิสถาปัตยกรรม -->
                        <div class="wp-highlight mb-6">
                            <h4 class="text-center text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                {{ lang === 'en' ? 'Network Architecture' : 'สถาปัตยกรรมเครือข่าย' }}
                            </h4>
                            <div class="flex flex-col items-center gap-3">
                                <!-- DApps Layer -->
                                <div class="flex flex-wrap justify-center gap-2">
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">DEX</span>
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">Master Node</span>
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">Token Factory</span>
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">FoodPassport</span>
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">IoT Farm</span>
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">NFT</span>
                                </div>
                                <div class="text-gray-600">▼</div>
                                <!-- Smart Contract Layer -->
                                <div class="w-full max-w-lg p-3 rounded-xl bg-accent-500/10 border border-accent-500/20 text-center">
                                    <p class="text-xs font-bold text-accent-400 mb-1">{{ lang === 'en' ? 'Smart Contract Layer (EVM / Solidity)' : 'ชั้น Smart Contract (EVM / Solidity)' }}</p>
                                    <div class="flex flex-wrap justify-center gap-1.5 text-xs text-gray-400">
                                        <span>TPIXDEXFactory</span><span>·</span>
                                        <span>TPIXDEXRouter02</span><span>·</span>
                                        <span>MasterNodePool</span><span>·</span>
                                        <span>TokenFactory</span>
                                    </div>
                                </div>
                                <div class="text-gray-600">▼</div>
                                <!-- Consensus Layer -->
                                <div class="w-full max-w-lg p-3 rounded-xl bg-green-500/10 border border-green-500/20 text-center">
                                    <p class="text-xs font-bold text-green-400 mb-1">IBFT Consensus (4 Validators)</p>
                                    <div class="flex justify-center gap-3">
                                        <span v-for="i in 4" :key="i" class="w-8 h-8 rounded-full bg-green-500/20 flex items-center justify-center text-green-400 text-xs font-bold">V{{ i }}</span>
                                    </div>
                                </div>
                                <div class="text-gray-600">▼</div>
                                <!-- Storage Layer -->
                                <div class="w-full max-w-lg p-3 rounded-xl bg-warm-500/10 border border-warm-500/20 text-center">
                                    <p class="text-xs font-bold text-warm-400">{{ lang === 'en' ? 'Storage (LevelDB) + Networking (libp2p)' : 'Storage (LevelDB) + เครือข่าย (libp2p)' }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Chain Specifications Table -->
                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Parameter' : 'พารามิเตอร์' }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Value' : 'ค่า' }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(row, i) in t.chainSpecs" :key="i" :class="i < t.chainSpecs.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3 text-gray-300">{{ row[0] }}</td>
                                        <td class="py-2 px-3 text-white font-medium">{{ row[1] }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 4. Tokenomics — โทเคโนมิกส์ -->
                    <section id="tokenomics" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[3] }}</h2>

                        <div class="grid lg:grid-cols-2 gap-8 items-start">
                            <!-- Donut Chart — แผนภูมิโดนัท -->
                            <div class="flex flex-col items-center">
                                <svg viewBox="0 0 200 200" class="w-64 h-64">
                                    <path v-for="seg in donutSegments" :key="seg.label" :d="seg.path" :fill="seg.color" class="opacity-90 hover:opacity-100 transition-opacity cursor-pointer" />
                                    <text x="100" y="95" text-anchor="middle" fill="white" font-size="14" font-weight="bold">7B</text>
                                    <text x="100" y="112" text-anchor="middle" fill="#9CA3AF" font-size="8">TPIX</text>
                                </svg>
                                <!-- Legend -->
                                <div class="grid grid-cols-1 gap-2 mt-4 w-full max-w-xs">
                                    <div v-for="item in tokenAllocation" :key="item.label" class="flex items-center gap-2">
                                        <span class="w-3 h-3 rounded-full flex-shrink-0" :style="{ backgroundColor: item.color }"></span>
                                        <span class="text-sm text-gray-300 flex-1">{{ lang === 'en' ? item.label : item.labelTh }}</span>
                                        <span class="text-sm font-bold text-white">{{ item.pct }}%</span>
                                        <span class="text-xs text-gray-500">{{ item.amount }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Details Table -->
                            <div>
                                <div class="wp-table">
                                    <table class="w-full text-sm">
                                        <thead><tr class="border-b border-white/10">
                                            <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Allocation' : 'การจัดสรร' }}</th>
                                            <th class="text-right py-2 px-3 text-gray-400">%</th>
                                            <th class="text-right py-2 px-3 text-gray-400">TPIX</th>
                                        </tr></thead>
                                        <tbody>
                                            <tr v-for="(item, i) in tokenAllocation" :key="item.label" :class="i < tokenAllocation.length - 1 ? 'border-b border-white/5' : ''">
                                                <td class="py-2 px-3 text-white flex items-center gap-2">
                                                    <span class="w-2.5 h-2.5 rounded-full" :style="{ backgroundColor: item.color }"></span>
                                                    {{ lang === 'en' ? item.label : item.labelTh }}
                                                </td>
                                                <td class="py-2 px-3 text-right font-medium" :style="{ color: item.color }">{{ item.pct }}%</td>
                                                <td class="py-2 px-3 text-right text-gray-300">{{ item.amount }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-4 p-4 rounded-xl bg-white/5 border border-white/10">
                                    <p class="text-sm text-gray-400">
                                        {{ lang === 'en'
                                            ? '✦ Fixed supply of 7,000,000,000 TPIX with 18 decimals. No inflation or minting mechanism — total supply is pre-mined in the genesis block.'
                                            : '✦ จำนวนคงที่ 7,000,000,000 TPIX (18 ทศนิยม) ไม่มีเงินเฟ้อหรือกลไก mint — จำนวนทั้งหมด pre-mined ใน genesis block'
                                        }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 5. Use Cases — กรณีการใช้งาน -->
                    <section id="use-cases" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[4] }}</h2>
                        <p class="wp-text">
                            {{ lang === 'en'
                                ? 'TPIX is the backbone of a comprehensive digital economy with 10+ real-world applications spanning agriculture, food safety, logistics, AI, e-commerce, and hospitality.'
                                : 'TPIX เป็นกระดูกสันหลังของเศรษฐกิจดิจิทัลครบวงจร มีแอปพลิเคชันในโลกจริง 10+ ด้าน ครอบคลุมเกษตรกรรม ความปลอดภัยอาหาร โลจิสติกส์ AI อีคอมเมิร์ซ และโรงแรม'
                            }}
                        </p>

                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="uc in t.useCases" :key="uc.title" class="p-5 rounded-xl bg-white/5 border border-white/10 hover:border-primary-500/30 transition-colors">
                                <div class="flex items-start gap-3 mb-3">
                                    <span class="text-2xl">{{ uc.icon }}</span>
                                    <h4 class="font-semibold text-white">{{ uc.title }}</h4>
                                </div>
                                <p class="text-sm text-gray-400 mb-3">{{ uc.desc }}</p>
                                <ul class="space-y-1">
                                    <li v-for="f in uc.features" :key="f" class="text-xs text-gray-500 flex items-start gap-1.5">
                                        <span class="text-primary-400 mt-0.5">▸</span>
                                        <span>{{ f }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </section>

                    <!-- 6. DEX Protocol -->
                    <section id="dex-protocol" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[5] }}</h2>
                        <p class="wp-text">{{ t.dex.desc }}</p>

                        <!-- DEX Flow Diagram -->
                        <div class="wp-highlight mb-6">
                            <h4 class="text-center text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                {{ lang === 'en' ? 'AMM Swap Flow' : 'ขั้นตอนการ Swap ผ่าน AMM' }}
                            </h4>
                            <div class="flex flex-wrap items-center justify-center gap-2 text-xs">
                                <span class="px-3 py-2 rounded-lg bg-blue-500/20 text-blue-300 font-medium">{{ lang === 'en' ? 'User' : 'ผู้ใช้' }}</span>
                                <span class="text-gray-500">→</span>
                                <span class="px-3 py-2 rounded-lg bg-purple-500/20 text-purple-300 font-medium">Router02</span>
                                <span class="text-gray-500">→</span>
                                <span class="px-3 py-2 rounded-lg bg-green-500/20 text-green-300 font-medium">Pair (x·y=k)</span>
                                <span class="text-gray-500">→</span>
                                <span class="px-3 py-2 rounded-lg bg-yellow-500/20 text-yellow-300 font-medium">{{ lang === 'en' ? '0.3% Fee Split' : 'แบ่งค่าธรรมเนียม 0.3%' }}</span>
                                <span class="text-gray-500">→</span>
                                <span class="px-3 py-2 rounded-lg bg-blue-500/20 text-blue-300 font-medium">{{ lang === 'en' ? 'Tokens Out' : 'ได้โทเคน' }}</span>
                            </div>
                        </div>

                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">Contract</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Description' : 'คำอธิบาย' }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(c, i) in t.dex.contracts" :key="c[0]" :class="i < t.dex.contracts.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3 text-primary-400 font-mono font-medium">{{ c[0] }}</td>
                                        <td class="py-2 px-3 text-gray-300">{{ c[1] }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 7. Token Sale Details -->
                    <section id="token-sale" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[6] }}</h2>
                        <p class="wp-text">
                            {{ lang === 'en'
                                ? 'The TPIX token sale is conducted in 3 phases, accepting BNB and USDT on BSC. Purchased tokens are allocated with a vesting schedule and can be claimed as wTPIX (BEP-20) or native TPIX once the bridge is live.'
                                : 'การขายเหรียญ TPIX แบ่งเป็น 3 รอบ รับ BNB และ USDT บน BSC เหรียญที่ซื้อจะมีตาราง vesting สามารถเคลมเป็น wTPIX (BEP-20) หรือ TPIX native เมื่อ bridge พร้อม'
                            }}
                        </p>

                        <div class="grid sm:grid-cols-3 gap-4 mb-6">
                            <div v-for="p in t.salePhases" :key="p.phase" class="p-5 rounded-xl bg-white/5 border border-white/10 text-center">
                                <h4 class="font-semibold text-white mb-2">{{ p.phase }}</h4>
                                <p class="text-3xl font-bold" :class="p.color">{{ p.price }}</p>
                                <p class="text-sm text-gray-400 mt-1">{{ p.alloc }}</p>
                                <div class="mt-3 pt-3 border-t border-white/10 text-xs text-gray-500">
                                    <p>TGE: {{ p.tge }}</p>
                                    <p>{{ p.vesting }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 8. Master Node & Rewards -->
                    <section id="masternode" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[7] }}</h2>
                        <p class="wp-text">{{ t.masternodeDesc }}</p>

                        <!-- Master Node Tiers -->
                        <h3 class="text-lg font-semibold text-white mt-6 mb-3">{{ lang === 'en' ? 'Node Tiers' : 'ระดับโหนด' }}</h3>
                        <div class="wp-table overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Tier' : 'ระดับ' }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Min Stake' : 'Stake ขั้นต่ำ' }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">APY</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Lock' : 'ล็อค' }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Max Nodes' : 'จำนวนสูงสุด' }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Reward Share' : 'ส่วนแบ่งรางวัล' }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">Hardware</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(n, i) in t.masternodeTiers" :key="n.tier" :class="i < t.masternodeTiers.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3 text-white font-semibold">{{ n.tier }}</td>
                                        <td class="py-2 px-3 text-right text-cyan-400 font-bold">{{ n.stake }}</td>
                                        <td class="py-2 px-3 text-right text-trading-green font-bold">{{ n.apy }}</td>
                                        <td class="py-2 px-3 text-gray-400">{{ n.lock }}</td>
                                        <td class="py-2 px-3 text-right text-gray-300">{{ n.maxNodes }}</td>
                                        <td class="py-2 px-3 text-purple-400">{{ n.reward }}</td>
                                        <td class="py-2 px-3 text-gray-500 text-xs">{{ n.hardware }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Reward Split -->
                        <div class="mt-6 p-4 rounded-xl bg-gradient-to-r from-cyan-500/10 to-blue-500/10 border border-cyan-500/20">
                            <h4 class="text-sm font-bold text-cyan-400 mb-2">{{ lang === 'en' ? 'Block Reward Distribution' : 'การแบ่งรางวัลบล็อก' }}</h4>
                            <p class="text-sm text-gray-300">{{ t.masternodeRewardSplit }}</p>
                        </div>

                        <!-- Emission Schedule -->
                        <h3 class="text-lg font-semibold text-white mt-6 mb-3">{{ lang === 'en' ? 'Emission Schedule (3-Year Decreasing)' : 'ตารางการปล่อยรางวัล (3 ปี ลดลงเรื่อยๆ)' }}</h3>
                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Year' : 'ปี' }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Total Reward' : 'รางวัลรวม' }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Per Block' : 'ต่อบล็อก' }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">{{ lang === 'en' ? '% of Pool' : '% ของกองทุน' }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(e, i) in t.masternodeEmission" :key="e.year" :class="i < t.masternodeEmission.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3 text-white">{{ e.year }}</td>
                                        <td class="py-2 px-3 text-right text-trading-green font-bold">{{ e.amount }}</td>
                                        <td class="py-2 px-3 text-right text-cyan-400">{{ e.perBlock }}</td>
                                        <td class="py-2 px-3 text-right text-gray-400">{{ e.pct }}</td>
                                    </tr>
                                    <tr class="border-t border-white/20 font-bold">
                                        <td class="py-2 px-3 text-white">{{ lang === 'en' ? 'Total' : 'รวม' }}</td>
                                        <td class="py-2 px-3 text-right text-yellow-400">1,400,000,000 TPIX</td>
                                        <td class="py-2 px-3"></td>
                                        <td class="py-2 px-3 text-right text-yellow-400">100%</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Slashing & Post-Y5 -->
                        <div class="mt-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="p-4 rounded-xl bg-red-500/10 border border-red-500/20">
                                <h4 class="text-sm font-bold text-red-400 mb-2">{{ lang === 'en' ? 'Slashing Rules' : 'กฎการลงโทษ (Slashing)' }}</h4>
                                <p class="text-sm text-gray-300">{{ t.masternodeSlashing }}</p>
                            </div>
                            <div class="p-4 rounded-xl bg-green-500/10 border border-green-500/20">
                                <h4 class="text-sm font-bold text-green-400 mb-2">{{ lang === 'en' ? 'After Year 3 (Sustainability)' : 'หลังปีที่ 3 (ยั่งยืนระยะยาว)' }}</h4>
                                <p class="text-sm text-gray-300">{{ t.masternodeAfterY5 }}</p>
                            </div>
                        </div>

                        <!-- Download Node -->
                        <div class="mt-6 p-4 rounded-xl bg-gradient-to-r from-purple-500/10 to-pink-500/10 border border-purple-500/20 text-center">
                            <h4 class="text-lg font-bold text-white mb-2">{{ lang === 'en' ? 'Run a Master Node' : 'รัน Master Node' }}</h4>
                            <p class="text-sm text-gray-300 mb-3">{{ lang === 'en' ? 'Download the TPIX Node software and start earning rewards today.' : 'ดาวน์โหลดซอฟต์แวร์ TPIX Node และเริ่มรับรางวัลวันนี้' }}</p>
                            <div class="flex justify-center gap-3 flex-wrap">
                                <a href="https://github.com/xjanova/TPIX-Coin/releases" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-purple-500/20 border border-purple-500/30 text-purple-300 hover:bg-purple-500/30 transition text-sm font-semibold">
                                    Download for Windows / Linux
                                </a>
                                <a href="https://github.com/xjanova/TPIX-Coin" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10 transition text-sm">
                                    GitHub Source Code
                                </a>
                            </div>
                        </div>
                    </section>

                    <!-- 9. Living Identity — Seedless Recovery -->
                    <section id="living-identity" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[8] }}</h2>
                        <div class="glass-dark p-6 rounded-2xl border border-accent-500/20 mb-6">
                            <p class="text-lg font-bold text-accent-400 mb-2">{{ lang === 'en' ? "World's First On-Chain Seedless Wallet Recovery" : 'ระบบกู้คืนกระเป๋าแบบไม่ต้องใช้ Seed Phrase ตัวแรกของโลก' }}</p>
                            <p class="text-gray-300 text-sm">{{ lang === 'en' ? 'No more seed phrases. No more lost funds. Your identity is your key.' : 'ไม่ต้องจำ Seed Phrase อีกต่อไป ไม่มีเงินหาย ตัวตนของคุณคือกุญแจ' }}</p>
                        </div>
                        <p class="wp-text">{{ lang === 'en' ? 'Living Identity (TPIXIdentity smart contract) allows users to recover wallet access by combining three verification factors into a single on-chain proof — security questions, GPS locations, and a 6-digit recovery PIN. Only a 32-byte keccak256 hash is stored on-chain. Zero personal data is retrievable from the blockchain.' : 'Living Identity (TPIXIdentity smart contract) ช่วยให้ผู้ใช้กู้คืนกระเป๋าได้โดยรวม 3 ปัจจัยยืนยันเข้าด้วยกัน — คำถามความปลอดภัย, พิกัด GPS และ PIN กู้คืน 6 หลัก เก็บเฉพาะ hash 32 bytes บนเชน ไม่มีข้อมูลส่วนตัวใดดึงออกจากบล็อกเชนได้' }}</p>

                        <div class="overflow-x-auto mt-4">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Step' : 'ขั้นตอน' }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Action' : 'การกระทำ' }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'On-Chain Data' : 'ข้อมูลบนเชน' }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">1. Register</td><td class="py-2 px-3 text-gray-300">{{ lang === 'en' ? 'Set security questions + GPS + PIN' : 'ตั้งคำถาม + GPS + PIN' }}</td><td class="py-2 px-3 text-gray-400">{{ lang === 'en' ? '32-byte keccak256 hash only' : 'เก็บเฉพาะ hash 32 bytes' }}</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">2. Loss</td><td class="py-2 px-3 text-gray-300">{{ lang === 'en' ? 'User loses device or seed phrase' : 'ผู้ใช้ทำอุปกรณ์หาย' }}</td><td class="py-2 px-3 text-gray-400">-</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">3. Recovery</td><td class="py-2 px-3 text-gray-300">{{ lang === 'en' ? 'Answer questions + stand at GPS + enter PIN' : 'ตอบคำถาม + ยืนที่ GPS + ใส่ PIN' }}</td><td class="py-2 px-3 text-gray-400">{{ lang === 'en' ? '48-hour timelock starts' : 'เริ่ม timelock 48 ชม.' }}</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">4. Safety</td><td class="py-2 px-3 text-gray-300">{{ lang === 'en' ? 'Owner can cancel within 48 hours' : 'เจ้าของยกเลิกได้ภายใน 48 ชม.' }}</td><td class="py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Cancel tx reverts recovery' : 'ยกเลิก tx คืนค่า' }}</td></tr>
                                    <tr><td class="py-2 px-3 text-white">5. Execute</td><td class="py-2 px-3 text-gray-300">{{ lang === 'en' ? 'After 48h, wallet transfers to new address' : 'หลัง 48 ชม. กระเป๋าโอนไปที่อยู่ใหม่' }}</td><td class="py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Ownership updated' : 'อัปเดตเจ้าของ' }}</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6">
                            <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                                <span class="text-2xl mb-2 block">🔐</span>
                                <p class="text-sm font-medium text-white">{{ lang === 'en' ? 'Zero Knowledge' : 'ไม่เก็บข้อมูลส่วนตัว' }}</p>
                                <p class="text-xs text-gray-400 mt-1">{{ lang === 'en' ? 'Only 32-byte hash on-chain' : 'เก็บเฉพาะ hash 32 bytes' }}</p>
                            </div>
                            <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                                <span class="text-2xl mb-2 block">⏰</span>
                                <p class="text-sm font-medium text-white">{{ lang === 'en' ? '48-Hour Timelock' : 'ล็อค 48 ชั่วโมง' }}</p>
                                <p class="text-xs text-gray-400 mt-1">{{ lang === 'en' ? 'Anti-theft protection' : 'ป้องกันขโมย' }}</p>
                            </div>
                            <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                                <span class="text-2xl mb-2 block">🆓</span>
                                <p class="text-sm font-medium text-white">{{ lang === 'en' ? 'Free to Use' : 'ใช้ฟรี' }}</p>
                                <p class="text-xs text-gray-400 mt-1">{{ lang === 'en' ? 'Zero gas on TPIX Chain' : 'ไม่เสียค่า Gas' }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 10. Validator Governance -->
                    <section id="governance" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[9] }}</h2>
                        <p class="wp-text">{{ lang === 'en' ? 'The ValidatorGovernance smart contract enables on-chain governance exclusively for Validator-tier nodes (10M TPIX stake + KYC-approved). Validators act as the chain\'s decision-making body for protocol upgrades, parameter changes, and membership.' : 'ValidatorGovernance smart contract เปิดให้การปกครองบนเชนเฉพาะ Validator-tier (stake 10M TPIX + ผ่าน KYC) ทำหน้าที่เป็นคณะกรรมการตัดสินใจของเชน' }}</p>

                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mt-4 mb-6">
                            <div class="glass-dark p-3 rounded-xl border border-white/10 text-center">
                                <p class="text-xs text-gray-400">{{ lang === 'en' ? 'Voting Period' : 'ระยะโหวต' }}</p>
                                <p class="text-lg font-bold text-white">7 {{ lang === 'en' ? 'days' : 'วัน' }}</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10 text-center">
                                <p class="text-xs text-gray-400">{{ lang === 'en' ? 'Quorum' : 'องค์ประชุม' }}</p>
                                <p class="text-lg font-bold text-white">&gt;50%</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10 text-center">
                                <p class="text-xs text-gray-400">{{ lang === 'en' ? 'Approval' : 'ผ่านมติ' }}</p>
                                <p class="text-lg font-bold text-white">&gt;50%</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10 text-center">
                                <p class="text-xs text-gray-400">Timelock</p>
                                <p class="text-lg font-bold text-white">48h</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10 text-center">
                                <p class="text-xs text-gray-400">KYC</p>
                                <p class="text-lg font-bold text-accent-400">PDPA</p>
                            </div>
                        </div>

                        <h3 class="text-lg font-semibold text-white mb-3">{{ lang === 'en' ? 'Proposal Types' : 'ประเภทข้อเสนอ' }}</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="glass-dark p-3 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white">AddValidator</p>
                                <p class="text-xs text-gray-400">{{ lang === 'en' ? 'Admit new IBFT2 validator node' : 'เพิ่ม Validator node ใหม่' }}</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white">RemoveValidator</p>
                                <p class="text-xs text-gray-400">{{ lang === 'en' ? 'Remove misbehaving validator' : 'ลบ Validator ที่ทำผิด' }}</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white">ChangeParameter</p>
                                <p class="text-xs text-gray-400">{{ lang === 'en' ? 'Modify stake requirements, fees, emission rates' : 'แก้ไขจำนวน stake, ค่าธรรมเนียม, อัตรา emission' }}</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white">UpgradeContract</p>
                                <p class="text-xs text-gray-400">{{ lang === 'en' ? 'Deploy new contract versions' : 'อัปเกรด smart contract' }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 11. Cross-Chain Bridge -->
                    <section id="bridge" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[10] }}</h2>
                        <p class="wp-text">{{ lang === 'en' ? 'The TPIX Bridge enables seamless asset transfer between TPIX Chain (native TPIX) and BNB Smart Chain (wTPIX, BEP-20). This allows TPIX holders to access BSC\'s DeFi ecosystem while maintaining the ability to bridge back.' : 'TPIX Bridge เชื่อมต่อ TPIX Chain (TPIX ดั้งเดิม) กับ BNB Smart Chain (wTPIX, BEP-20) ช่วยให้ผู้ถือ TPIX เข้าถึง DeFi บน BSC ได้' }}</p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                            <div class="glass-dark p-4 rounded-xl border border-green-500/20">
                                <p class="text-sm font-bold text-green-400 mb-2">BSC → TPIX Chain</p>
                                <p class="text-xs text-gray-300">{{ lang === 'en' ? 'Burn wTPIX on BSC → Mint native TPIX on TPIX Chain' : 'เผา wTPIX บน BSC → สร้าง TPIX บน TPIX Chain' }}</p>
                            </div>
                            <div class="glass-dark p-4 rounded-xl border border-blue-500/20">
                                <p class="text-sm font-bold text-blue-400 mb-2">TPIX Chain → BSC</p>
                                <p class="text-xs text-gray-300">{{ lang === 'en' ? 'Lock native TPIX → Mint wTPIX on BSC' : 'ล็อค TPIX → สร้าง wTPIX บน BSC' }}</p>
                            </div>
                        </div>

                        <div class="overflow-x-auto mt-4">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Feature' : 'คุณสมบัติ' }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Details' : 'รายละเอียด' }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">wTPIX Max Supply</td><td class="py-2 px-3 text-gray-300">700,000,000 (10% of total)</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">Standard</td><td class="py-2 px-3 text-gray-300">ERC-20 + Burnable (BEP-20 on BSC)</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">Bridge Fee</td><td class="py-2 px-3 text-gray-300">0.1% (90% treasury, 10% burned)</td></tr>
                                    <tr><td class="py-2 px-3 text-white">{{ lang === 'en' ? 'Supply Integrity' : 'ความสมบูรณ์ Supply' }}</td><td class="py-2 px-3 text-gray-300">{{ lang === 'en' ? 'Native TPIX + wTPIX always = 7 billion' : 'TPIX + wTPIX รวมกัน = 7 พันล้านเสมอ' }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 12. Ecosystem & Affiliate -->
                    <section id="ecosystem" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[11] }}</h2>
                        <p class="wp-text">
                            {{ lang === 'en'
                                ? 'The TPIX ecosystem includes a comprehensive affiliate/referral program and a token factory allowing anyone to create ERC-20 tokens on TPIX Chain.'
                                : 'ระบบนิเวศ TPIX ประกอบด้วยโปรแกรม Affiliate/แนะนำครบวงจร และโรงงานสร้างโทเคนให้ทุกคนสร้าง ERC-20 บน TPIX Chain'
                            }}
                        </p>

                        <!-- Ecosystem Map Diagram -->
                        <div class="wp-highlight mb-6">
                            <h4 class="text-center text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                {{ lang === 'en' ? 'TPIX Ecosystem Map' : 'แผนผังระบบนิเวศ TPIX' }}
                            </h4>
                            <div class="flex flex-col items-center gap-2">
                                <div class="px-6 py-3 rounded-xl bg-primary-500/20 border border-primary-500/30 text-primary-300 font-bold text-sm">TPIX Chain (ID: 4289)</div>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 w-full max-w-2xl">
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🏦</span>DEX
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">💰</span>Master Node
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🏭</span>Token Factory
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🌉</span>BSC Bridge
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🍲</span>FoodPassport
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🌾</span>IoT Farm
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🚚</span>Delivery
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🤖</span>AI Bots
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🏨</span>{{ lang === 'en' ? 'Hotels' : 'โรงแรม' }}
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🛒</span>E-Commerce
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🌱</span>Carbon Credit
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🧠</span>AI Ecosystem
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h3 class="wp-subheading">{{ lang === 'en' ? 'Affiliate Program' : 'โปรแกรม Affiliate' }}</h3>
                        <div class="grid sm:grid-cols-3 gap-4 mb-6">
                            <div class="p-4 rounded-xl bg-white/5 border border-white/10 text-center">
                                <p class="text-2xl font-bold text-primary-400">5%</p>
                                <p class="text-sm text-gray-400">{{ lang === 'en' ? 'Referrer Reward' : 'รางวัลผู้แนะนำ' }}</p>
                            </div>
                            <div class="p-4 rounded-xl bg-white/5 border border-white/10 text-center">
                                <p class="text-2xl font-bold text-accent-400">2%</p>
                                <p class="text-sm text-gray-400">{{ lang === 'en' ? 'Referee Bonus' : 'โบนัสผู้ถูกแนะนำ' }}</p>
                            </div>
                            <div class="p-4 rounded-xl bg-white/5 border border-white/10 text-center">
                                <p class="text-2xl font-bold text-warm-400">1,000</p>
                                <p class="text-sm text-gray-400">{{ lang === 'en' ? 'Max TPIX/Referral' : 'TPIX สูงสุด/การแนะนำ' }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 13. Platform Integrations -->
                    <section id="integrations" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[12] }}</h2>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="int in t.integrations" :key="int.name" class="p-5 rounded-xl bg-white/5 border border-white/10">
                                <h4 class="font-semibold text-white mb-1">{{ int.name }}</h4>
                                <p class="text-xs text-gray-500 mb-3">{{ int.desc }}</p>
                                <ul class="space-y-1">
                                    <li v-for="item in int.items" :key="item" class="text-sm text-gray-400 flex items-start gap-1.5">
                                        <span class="text-primary-400 mt-0.5">•</span>
                                        <span>{{ item }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </section>

                    <!-- 14. Roadmap -->
                    <section id="roadmap" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[13] }}</h2>
                        <div class="space-y-4">
                            <div v-for="r in t.roadmap" :key="r.q" class="flex gap-4">
                                <div class="flex-shrink-0 w-28">
                                    <span class="text-sm font-bold" :class="{
                                        'text-trading-green': r.status === 'done',
                                        'text-yellow-400': r.status === 'progress',
                                        'text-primary-400': r.status === 'current',
                                        'text-gray-500': r.status === 'planned',
                                    }">{{ r.q }}</span>
                                    <span class="block text-xs mt-0.5" :class="{
                                        'text-trading-green': r.status === 'done',
                                        'text-yellow-400': r.status === 'progress',
                                        'text-primary-400': r.status === 'current',
                                        'text-gray-600': r.status === 'planned',
                                    }">
                                        {{ r.status === 'done' ? '✅' : r.status === 'progress' ? '🚧' : r.status === 'current' ? '🔵' : '📅' }}
                                    </span>
                                </div>
                                <div class="flex-1 pb-4" :class="r.q !== t.roadmap[t.roadmap.length - 1].q ? 'border-b border-white/5' : ''">
                                    <h4 class="text-white font-semibold mb-2">{{ r.title }}</h4>
                                    <ul class="space-y-1">
                                        <li v-for="item in r.items" :key="item" class="text-sm text-gray-400">▸ {{ item }}</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 15. Technology Stack -->
                    <section id="tech-stack" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[14] }}</h2>
                        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="(items, category) in t.techStack" :key="category" class="p-4 rounded-xl bg-white/5 border border-white/10">
                                <h4 class="font-semibold text-white mb-3 capitalize">{{ category === 'smartContracts' ? 'Smart Contracts' : category }}</h4>
                                <div v-for="item in items" :key="item[0]" class="flex justify-between text-sm py-1 border-b border-white/5 last:border-0">
                                    <span class="text-primary-400 font-medium">{{ item[0] }}</span>
                                    <span class="text-gray-500 text-xs text-right">{{ item[1] }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 16. Team & Partners -->
                    <section id="team" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[15] }}</h2>
                        <p class="wp-text">{{ t.teamDesc }}</p>
                        <div class="wp-highlight">
                            <h4 class="font-semibold text-white mb-3">{{ lang === 'en' ? 'Key Highlights' : 'จุดเด่น' }}</h4>
                            <ul class="space-y-2">
                                <li v-for="h in t.teamHighlights" :key="h" class="text-sm text-gray-300 flex items-start gap-2">
                                    <span class="text-primary-400">✦</span>
                                    <span>{{ h }}</span>
                                </li>
                            </ul>
                        </div>
                    </section>

                    <!-- 17. Security & Audits -->
                    <section id="security" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[16] }}</h2>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="item in t.securityItems" :key="item.title" class="p-4 rounded-xl bg-white/5 border border-white/10">
                                <h4 class="font-semibold text-white mb-2">🔒 {{ item.title }}</h4>
                                <p class="text-sm text-gray-400">{{ item.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 18. Legal Disclaimer -->
                    <section id="legal" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[17] }}</h2>
                        <div class="p-6 rounded-xl bg-white/5 border border-white/10">
                            <p class="text-sm text-gray-500 mb-3">
                                {{ lang === 'en'
                                    ? 'This whitepaper is for informational purposes only and does not constitute investment advice, financial advice, trading advice, or any other sort of advice. TPIX tokens are utility tokens and are not intended to be securities. The purchase of TPIX tokens involves significant risk. Please conduct your own due diligence before participating in the token sale.'
                                    : 'ไวท์เปเปอร์นี้มีวัตถุประสงค์เพื่อให้ข้อมูลเท่านั้น ไม่ถือเป็นคำแนะนำด้านการลงทุน การเงิน การซื้อขาย หรือคำแนะนำอื่นใด TPIX tokens เป็น utility tokens และไม่ได้มีเจตนาเป็นหลักทรัพย์ การซื้อ TPIX tokens มีความเสี่ยงสูง กรุณาศึกษาข้อมูลด้วยตนเองก่อนเข้าร่วมการขายโทเคน'
                                }}
                            </p>
                            <p class="text-sm text-gray-500">
                                {{ lang === 'en'
                                    ? 'The information in this whitepaper may be updated from time to time. The team reserves the right to make changes without prior notice. Nothing in this whitepaper shall be deemed to constitute a prospectus or solicitation for investment.'
                                    : 'ข้อมูลในไวท์เปเปอร์นี้อาจมีการปรับปรุงเป็นครั้งคราว ทีมงานขอสงวนสิทธิ์ในการเปลี่ยนแปลงโดยไม่ต้องแจ้งล่วงหน้า ไม่มีข้อมูลใดในไวท์เปเปอร์นี้ที่ถือเป็นหนังสือชี้ชวนหรือการเชื้อเชิญเพื่อการลงทุน'
                                }}
                            </p>
                        </div>
                    </section>

                </main>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
/* สไตล์ whitepaper — ใช้ design system ของโปรเจค */
.wp-section { @apply mb-16 scroll-mt-24; }
.wp-heading { @apply text-2xl sm:text-3xl font-bold text-white mb-4 pb-3 border-b border-white/10; }
.wp-subheading { @apply text-lg font-semibold text-white mt-6 mb-3; }
.wp-text { @apply text-gray-300 leading-relaxed mb-4; }
.wp-list { @apply list-disc list-inside space-y-2 text-gray-300 mb-4; }
.wp-highlight { @apply p-6 rounded-xl bg-white/5 border border-white/10 my-6; }
.wp-table { @apply rounded-xl bg-white/5 border border-white/10 overflow-x-auto my-6; }
</style>
