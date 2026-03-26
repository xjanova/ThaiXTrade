<script setup>
/**
 * TPIX TRADE - Carbon Credit Whitepaper (Premium Edition)
 * เอกสาร Carbon Credit แบบ whitepaper-style พร้อม:
 * - สลับภาษาไทย/อังกฤษ
 * - Interactive Table of Contents
 * - SVG Charts & Diagrams
 * - PDF Download
 * Developed by Xman Studio
 */

import { ref, computed, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const lang = ref('en');
const toggleLang = () => { lang.value = lang.value === 'en' ? 'th' : 'en'; };
const t = computed(() => content[lang.value]);

const sections = computed(() => [
    { id: 'overview', title: t.value.toc[0] },
    { id: 'why-carbon', title: t.value.toc[1] },
    { id: 'how-it-works', title: t.value.toc[2] },
    { id: 'project-types', title: t.value.toc[3] },
    { id: 'standards', title: t.value.toc[4] },
    { id: 'marketplace', title: t.value.toc[5] },
    { id: 'blockchain', title: t.value.toc[6] },
    { id: 'tokenomics', title: t.value.toc[7] },
    { id: 'api', title: t.value.toc[8] },
    { id: 'mobile', title: t.value.toc[9] },
    { id: 'roadmap', title: t.value.toc[10] },
    { id: 'legal', title: t.value.toc[11] },
]);

const activeSection = ref('overview');

function scrollTo(id) {
    activeSection.value = id;
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

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
    const ids = sections.value.map(s => s.id);
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) observer.observe(el);
    });
});

function downloadPdf() {
    const style = document.createElement('style');
    style.id = 'print-style';
    style.textContent = `
        @media print {
            body { background: white !important; color: black !important; }
            nav, .sidebar, header, footer, button, .btn-primary, .btn-secondary,
            .btn-brand, [class*="glass"], .fixed { display: none !important; }
            main, section, div { background: white !important; border: none !important;
                backdrop-filter: none !important; color: black !important; }
            h1, h2, h3, h4 { color: #1a1a1a !important; }
            p, span, td, th, li { color: #333 !important; }
            a { color: #0066cc !important; text-decoration: underline !important; }
            table { border-collapse: collapse !important; }
            th, td { border: 1px solid #ddd !important; padding: 8px !important; }
            .text-gradient, .text-gradient-brand { -webkit-text-fill-color: #1a1a1a !important;
                background: none !important; color: #1a1a1a !important; }
            @page { margin: 20mm; size: A4; }
        }
    `;
    document.head.appendChild(style);
    const suffix = lang.value === 'th' ? 'TH' : 'EN';
    document.title = `TPIX-Carbon-Credit-Whitepaper-v1.0-${suffix}`;
    setTimeout(() => {
        window.print();
        setTimeout(() => {
            document.title = 'TPIX Carbon Credit Whitepaper';
            const s = document.getElementById('print-style');
            if (s) s.remove();
        }, 1000);
    }, 100);
}

// SVG Donut Chart — Carbon Credit Allocation
const creditAllocation = [
    { label: 'Reforestation', labelTh: 'ปลูกป่า', pct: 35, color: '#10B981', icon: '🌳' },
    { label: 'Renewable Energy', labelTh: 'พลังงานหมุนเวียน', pct: 25, color: '#F59E0B', icon: '⚡' },
    { label: 'Methane Capture', labelTh: 'ดักจับมีเทน', pct: 15, color: '#EF4444', icon: '🔥' },
    { label: 'Ocean Cleanup', labelTh: 'ทำความสะอาดมหาสมุทร', pct: 15, color: '#3B82F6', icon: '🌊' },
    { label: 'Biodiversity', labelTh: 'ความหลากหลายทางชีวภาพ', pct: 10, color: '#8B5CF6', icon: '🦋' },
];

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

const donutSegments = computed(() => {
    let angle = 0;
    return creditAllocation.map(item => {
        const start = angle;
        const sweep = (item.pct / 100) * 360;
        angle += sweep;
        return { ...item, path: donutPath(start, start + sweep - 0.5) };
    });
});

// Roadmap
const roadmap = computed(() => lang.value === 'en' ? [
    { phase: 'Phase 1', period: 'Q1 2026', title: 'Foundation', items: ['Carbon Credit smart contracts on TPIX Chain', 'Admin panel for project management', 'Basic marketplace UI', 'VCS & Gold Standard integration'] },
    { phase: 'Phase 2', period: 'Q2 2026', title: 'Marketplace Launch', items: ['Public marketplace with purchase & retire flows', 'Multi-currency payments (TPIX/BNB/USDT)', 'Certificate generation as NFTs', 'API for third-party integrations'] },
    { phase: 'Phase 3', period: 'Q3 2026', title: 'Mobile & IoT', items: ['Mobile app for carbon credit management', 'IoT sensor integration for MRV', 'Real-time emission monitoring', 'Automated credit issuance from verified data'] },
    { phase: 'Phase 4', period: 'Q4 2026', title: 'Global Expansion', items: ['Cross-chain bridge for credit trading', 'International standard certifications (CDM, ACR)', 'Enterprise API & bulk trading', 'Carbon offset marketplace for businesses'] },
] : [
    { phase: 'เฟส 1', period: 'Q1 2026', title: 'รากฐาน', items: ['Smart contracts สำหรับ Carbon Credit บน TPIX Chain', 'แผงควบคุมสำหรับจัดการโครงการ', 'หน้า Marketplace เบื้องต้น', 'รองรับมาตรฐาน VCS & Gold Standard'] },
    { phase: 'เฟส 2', period: 'Q2 2026', title: 'เปิดตลาด', items: ['Marketplace สาธารณะพร้อมระบบซื้อ & retire', 'รองรับหลายสกุลเงิน (TPIX/BNB/USDT)', 'สร้างใบรับรองเป็น NFTs', 'API สำหรับระบบภายนอก'] },
    { phase: 'เฟส 3', period: 'Q3 2026', title: 'มือถือ & IoT', items: ['แอปมือถือสำหรับจัดการ Carbon Credit', 'เชื่อมต่อเซ็นเซอร์ IoT สำหรับ MRV', 'ติดตามการปล่อยก๊าซแบบเรียลไทม์', 'ออก credit อัตโนมัติจากข้อมูลที่ตรวจสอบแล้ว'] },
    { phase: 'เฟส 4', period: 'Q4 2026', title: 'ขยายทั่วโลก', items: ['สะพานข้ามเชนสำหรับซื้อขาย credit', 'ใบรับรองมาตรฐานสากล (CDM, ACR)', 'API สำหรับองค์กร & ซื้อขายจำนวนมาก', 'ตลาด Carbon Offset สำหรับธุรกิจ'] },
]);

// API Endpoints
const apiEndpoints = [
    { method: 'GET', path: '/api/v1/carbon-credits/projects', desc: 'List active projects', descTh: 'รายการโครงการที่ active', auth: false },
    { method: 'GET', path: '/api/v1/carbon-credits/projects/{id}', desc: 'Get project details', descTh: 'รายละเอียดโครงการ', auth: false },
    { method: 'POST', path: '/api/v1/carbon-credits/purchase', desc: 'Purchase credits', descTh: 'ซื้อ Carbon Credit', auth: true },
    { method: 'POST', path: '/api/v1/carbon-credits/retire', desc: 'Retire credits', descTh: 'Retire Carbon Credit', auth: true },
    { method: 'GET', path: '/api/v1/carbon-credits/my-credits', desc: 'My credit balance', descTh: 'ยอด credit ของฉัน', auth: true },
    { method: 'GET', path: '/api/v1/carbon-credits/my-retirements', desc: 'My retirement history', descTh: 'ประวัติการ retire', auth: true },
    { method: 'GET', path: '/api/v1/carbon-credits/stats', desc: 'Platform statistics', descTh: 'สถิติแพลตฟอร์ม', auth: false },
];

// Content i18n
const content = {
    en: {
        pageTitle: 'Carbon Credit Whitepaper',
        subtitle: 'Transparent Carbon Offset Trading on TPIX Chain',
        version: 'Version 1.0 — March 2026',
        downloadPdf: 'Download PDF',
        readInThai: '\u{1F1F9}\u{1F1ED} อ่านเป็นภาษาไทย',
        readInEn: '\u{1F1EC}\u{1F1E7} Read in English',
        toc: [
            '1. Overview',
            '2. Why Carbon Credits Matter',
            '3. How It Works',
            '4. Project Types',
            '5. Standards & Certification',
            '6. Marketplace',
            '7. Blockchain Integration',
            '8. Credit Tokenomics',
            '9. API Reference',
            '10. Mobile App',
            '11. Roadmap',
            '12. Legal Disclaimer',
        ],
        overviewTitle: 'What is TPIX Carbon Credit?',
        overviewP1: 'TPIX Carbon Credit is a blockchain-based carbon offset marketplace built on TPIX Chain (Chain ID: 4289). It enables individuals and businesses to purchase, trade, and retire verified carbon credits with full transparency and immutability.',
        overviewP2: 'Every carbon credit represents one metric ton of CO2 equivalent (tCO2e) that has been reduced, avoided, or removed from the atmosphere through verified environmental projects. By tokenizing these credits on the blockchain, we ensure tamper-proof provenance, instant settlement, and global accessibility.',
        overviewP3: 'The platform supports multiple internationally recognized standards including VCS (Verra), Gold Standard, CDM, and ACR, making TPIX Carbon Credits compliant with global carbon trading frameworks.',
        overviewStats: [
            { value: '1 Credit', label: '= 1 tCO2e' },
            { value: '0 Gas', label: 'Transaction Fee' },
            { value: '5+', label: 'Project Types' },
            { value: '4', label: 'Certified Standards' },
            { value: '3', label: 'Payment Currencies' },
            { value: 'NFT', label: 'Certificates' },
        ],
        whyTitle: 'The Climate Crisis & Carbon Markets',
        whyP1: 'The world must reduce greenhouse gas emissions by 45% by 2030 to limit global warming to 1.5C. Carbon markets are a critical mechanism to incentivize emission reductions by putting a price on carbon.',
        whyP2: 'However, traditional carbon markets suffer from opacity, double-counting, high intermediary costs, and lack of accessibility for small participants. Blockchain technology solves these problems.',
        whyProblems: [
            { title: 'Opacity & Fraud', desc: 'Traditional carbon registries are centralized databases vulnerable to manipulation. Credits can be double-counted or issued for non-existent projects.' },
            { title: 'High Intermediary Costs', desc: 'Brokers, exchanges, and verification bodies take 15-30% of credit value, making small projects economically unviable.' },
            { title: 'Slow Settlement', desc: 'Traditional credit transfers take days to weeks through multiple intermediaries, creating friction and counterparty risk.' },
            { title: 'Limited Access', desc: 'Small businesses and individuals are effectively excluded from carbon markets due to minimum purchase requirements and complex onboarding.' },
        ],
        whySolutions: [
            { title: 'Immutable Records', desc: 'Every credit issuance, transfer, and retirement is recorded on TPIX Chain with cryptographic proof. Double-counting is mathematically impossible.' },
            { title: 'Near-Zero Fees', desc: 'TPIX Chain is gasless. Platform fee is only 2.5%, with no broker or intermediary costs. Small projects become economically viable.' },
            { title: 'Instant Settlement', desc: 'Credits transfer in 2 seconds (TPIX block time). No waiting, no counterparty risk, no intermediaries needed.' },
            { title: 'Open Access', desc: 'Anyone with a wallet can buy credits starting from 0.01 tCO2e. No minimum purchase, no KYC for small amounts, no geographic restrictions.' },
        ],
        howTitle: 'Purchase & Retirement Flow',
        howPurchaseSteps: [
            { num: 1, title: 'Browse Projects', desc: 'Explore verified carbon offset projects by type, standard, location, and vintage year.' },
            { num: 2, title: 'Select & Pay', desc: 'Choose credits and pay with TPIX, BNB, or USDT. Multi-currency support with real-time pricing.' },
            { num: 3, title: 'Receive Credits', desc: 'Credits are recorded on-chain with a unique serial number (CC-XXXXXXXX-YYYYMMDD) and linked to your wallet.' },
            { num: 4, title: 'Hold or Retire', desc: 'Hold credits for trading or retire them to claim your carbon offset. Retired credits are permanently burned.' },
        ],
        howRetireSteps: [
            { num: 1, title: 'Select Credits', desc: 'Choose which credits to retire from your portfolio.' },
            { num: 2, title: 'Specify Beneficiary', desc: 'Enter the beneficiary name and reason for retirement (e.g., company carbon neutrality).' },
            { num: 3, title: 'Blockchain Record', desc: 'Retirement is recorded immutably on TPIX Chain with a unique transaction hash.' },
            { num: 4, title: 'NFT Certificate', desc: 'Receive a retirement certificate as an NFT, proving your verified carbon offset permanently on-chain.' },
        ],
        projectTypesTitle: 'Supported Project Categories',
        projectTypes: [
            { icon: '🌳', title: 'Reforestation & Afforestation', desc: 'Tree planting projects that sequester CO2 through photosynthesis. Includes mangrove restoration, urban forests, and commercial reforestation.', metric: '5-20 tCO2e/hectare/year', price: '$8-25/tCO2e' },
            { icon: '⚡', title: 'Renewable Energy', desc: 'Solar, wind, hydro, and biomass energy projects that displace fossil fuel generation. Verified emission reductions from clean energy production.', metric: '0.5-1.2 tCO2e/MWh displaced', price: '$5-15/tCO2e' },
            { icon: '🔥', title: 'Methane Capture', desc: 'Capturing methane from landfills, coal mines, and agricultural operations. Methane has 80x the warming potential of CO2 over 20 years.', metric: '21-80x CO2 equivalent', price: '$10-30/tCO2e' },
            { icon: '🌊', title: 'Ocean & Blue Carbon', desc: 'Coastal ecosystem restoration including mangroves, seagrass beds, and salt marshes. These ecosystems store carbon at rates 2-4x faster than tropical forests.', metric: '10-40 tCO2e/hectare/year', price: '$15-40/tCO2e' },
            { icon: '🦋', title: 'Biodiversity Conservation', desc: 'Forest conservation projects (REDD+) that prevent deforestation while protecting endangered species and indigenous communities.', metric: 'Varies by project', price: '$10-35/tCO2e' },
            { icon: '🏭', title: 'Industrial Efficiency', desc: 'Energy efficiency improvements in manufacturing, transportation, and buildings. Includes cookstove programs and industrial process optimization.', metric: '0.1-5 tCO2e/unit', price: '$3-12/tCO2e' },
            { icon: '🌾', title: 'Agriculture & Soil Carbon', desc: 'Regenerative agriculture, no-till farming, and biochar application that increase soil carbon sequestration.', metric: '1-5 tCO2e/hectare/year', price: '$8-20/tCO2e' },
        ],
        standardsTitle: 'Certification Standards',
        standards: [
            { name: 'VCS (Verra)', full: 'Verified Carbon Standard', desc: 'The world\'s most widely used voluntary carbon credit standard. Over 1,800 certified projects across 80+ countries. Rigorous third-party verification with transparent methodology.', features: ['1,800+ projects certified', '80+ countries covered', 'Most liquid voluntary market', 'Detailed public registry'] },
            { name: 'Gold Standard', full: 'Gold Standard for the Global Goals', desc: 'Premium standard requiring demonstration of sustainable development co-benefits aligned with UN SDGs. Credits command 20-50% premium due to higher quality requirements.', features: ['UN SDG alignment required', 'Stakeholder consultation mandatory', '20-50% price premium', 'Strong social co-benefits'] },
            { name: 'CDM', full: 'Clean Development Mechanism', desc: 'UN-backed mechanism under the Kyoto Protocol. Government-endorsed credits from developing countries with national approval processes.', features: ['UN-backed & government endorsed', 'National approval required', 'Developing country focus', 'Compliance market eligible'] },
            { name: 'ACR', full: 'American Carbon Registry', desc: 'The first private voluntary carbon credit registry in the world (est. 1996). Strong focus on North American projects with rigorous scientific methodologies.', features: ['Oldest private registry (1996)', 'North America focus', 'Scientific methodology', 'Government program eligible'] },
        ],
        marketTitle: 'TPIX Carbon Credit Marketplace',
        marketP1: 'The TPIX Carbon Credit Marketplace is a decentralized platform where buyers and sellers can trade verified carbon credits directly on the blockchain. No intermediaries, no hidden fees.',
        marketFeatures: [
            { title: 'Multi-Currency Payments', desc: 'Pay with TPIX (native coin, lowest fees), BNB (cross-chain via bridge), or USDT (stablecoin for USD-pegged pricing).', icon: '💳' },
            { title: 'Real-Time Pricing', desc: 'Dynamic pricing based on project type, standard, vintage year, and market demand. Price feeds updated from verified oracles.', icon: '📊' },
            { title: 'Instant Settlement', desc: '2-second finality on TPIX Chain. Credits transfer immediately upon payment confirmation. No T+2 settlement delay.', icon: '⚡' },
            { title: 'NFT Certificates', desc: 'Every retirement generates an immutable NFT certificate on TPIX Chain. Proof of your carbon offset that can never be disputed or lost.', icon: '🏆' },
            { title: 'Portfolio Dashboard', desc: 'Track your credit holdings, retirement history, total offset impact, and pending transactions in a unified dashboard.', icon: '📈' },
            { title: 'Bulk Trading', desc: 'Enterprise API for buying and retiring credits in bulk. Support for corporate carbon neutrality programs and ESG reporting.', icon: '🏢' },
        ],
        blockchainTitle: 'On-Chain Architecture',
        blockchainP1: 'TPIX Carbon Credit uses a hybrid model combining the best of on-chain transparency with off-chain efficiency.',
        blockchainModel: [
            { layer: 'On-Chain (TPIX Chain)', items: ['Credit serial numbers & ownership', 'Purchase & retirement transactions', 'NFT certificates (ERC-721)', 'Transaction hashes & timestamps', 'Certificate hash verification'] },
            { layer: 'Off-Chain (Database)', items: ['Project details & descriptions', 'Pricing & payment processing', 'User profiles & KYC data', 'Analytics & reporting', 'Admin management tools'] },
        ],
        blockchainSpecs: [
            ['Chain', 'TPIX Chain (Chain ID: 4289)'],
            ['Consensus', 'IBFT Proof-of-Authority'],
            ['Block Time', '2 seconds'],
            ['Gas Price', '0 (gasless)'],
            ['Contract Standard', 'ERC-1155 (Multi-Token) + ERC-721 (Certificates)'],
            ['Serial Number Format', 'CC-XXXXXXXX-YYYYMMDD'],
            ['Certificate Hash', '0x + 64 hex characters (SHA-256)'],
        ],
        tokenomicsTitle: 'Credit Economics',
        tokenomicsP1: 'Each carbon credit on TPIX represents exactly 1 metric ton of CO2 equivalent (1 tCO2e). Credits are priced in USD but payable in multiple currencies.',
        tokenomicsFees: [
            { item: 'Platform Fee', value: '2.5%', desc: 'Applied to each purchase. Funds platform development and verification.' },
            { item: 'Retirement Fee', value: '0%', desc: 'Free retirement to incentivize carbon offset actions.' },
            { item: 'Gas Fee', value: '0', desc: 'TPIX Chain is completely gasless. All transactions are free.' },
            { item: 'Minimum Purchase', value: '0.01 tCO2e', desc: 'Micro-purchases enabled for individual participation.' },
        ],
        apiTitle: 'API Reference',
        apiP1: 'The TPIX Carbon Credit API enables third-party integrations, mobile apps, and enterprise systems to interact with the carbon credit marketplace programmatically.',
        mobileTitle: 'Mobile App Integration',
        mobileP1: 'The TPIX Carbon Credit system is designed for mobile-first experience. The upcoming mobile app will provide full marketplace functionality on iOS and Android.',
        mobileFeatures: [
            { title: 'Browse & Buy', desc: 'Discover projects and purchase credits directly from your phone.', status: 'API Ready' },
            { title: 'Portfolio Tracking', desc: 'Monitor your carbon credit holdings and offset impact.', status: 'API Ready' },
            { title: 'Retire & Certificate', desc: 'Retire credits and receive NFT certificates in-app.', status: 'API Ready' },
            { title: 'QR Verification', desc: 'Scan QR codes to verify retirement certificates instantly.', status: 'Planned' },
            { title: 'Push Notifications', desc: 'Alerts for new projects, price changes, and credit expirations.', status: 'Planned' },
            { title: 'Offset Calculator', desc: 'Calculate your carbon footprint and recommended offset amount.', status: 'Planned' },
        ],
        legalP1: 'Carbon credits traded on this platform represent voluntary carbon offsets and are not compliance credits under any regulatory framework unless specifically stated. The platform facilitates peer-to-peer trading of carbon credits and does not guarantee the future value, liquidity, or regulatory status of any credit.',
        legalP2: 'Project verification is performed by independent third-party bodies according to the stated standard (VCS, Gold Standard, CDM, ACR). TPIX TRADE does not independently verify emission reductions and relies on the stated certification. Users should conduct their own due diligence before purchasing carbon credits.',
    },
    th: {
        pageTitle: 'เอกสาร Carbon Credit',
        subtitle: 'การซื้อขาย Carbon Offset อย่างโปร่งใสบน TPIX Chain',
        version: 'เวอร์ชัน 1.0 — มีนาคม 2026',
        downloadPdf: 'ดาวน์โหลด PDF',
        readInThai: '\u{1F1F9}\u{1F1ED} อ่านเป็นภาษาไทย',
        readInEn: '\u{1F1EC}\u{1F1E7} Read in English',
        toc: [
            '1. ภาพรวม',
            '2. ทำไม Carbon Credit ถึงสำคัญ',
            '3. ขั้นตอนการทำงาน',
            '4. ประเภทโครงการ',
            '5. มาตรฐานและการรับรอง',
            '6. ตลาดซื้อขาย',
            '7. การเชื่อมต่อ Blockchain',
            '8. เศรษฐศาสตร์ Credit',
            '9. API Reference',
            '10. แอปมือถือ',
            '11. แผนงาน',
            '12. ข้อจำกัดทางกฎหมาย',
        ],
        overviewTitle: 'TPIX Carbon Credit คืออะไร?',
        overviewP1: 'TPIX Carbon Credit คือตลาดซื้อขาย Carbon Offset บน Blockchain ที่สร้างบน TPIX Chain (Chain ID: 4289) ช่วยให้บุคคลและธุรกิจสามารถซื้อ แลกเปลี่ยน และ retire carbon credits ที่ผ่านการตรวจสอบแล้วได้อย่างโปร่งใสและไม่สามารถเปลี่ยนแปลงได้',
        overviewP2: 'Carbon credit ทุกหน่วยแทนการลดก๊าซเรือนกระจก 1 เมตริกตัน CO2 เทียบเท่า (tCO2e) ที่ถูกลด หลีกเลี่ยง หรือกำจัดออกจากชั้นบรรยากาศผ่านโครงการสิ่งแวดล้อมที่ผ่านการรับรอง การ tokenize credit เหล่านี้บน blockchain ช่วยรับประกันความถูกต้อง การชำระเงินทันที และการเข้าถึงจากทั่วโลก',
        overviewP3: 'แพลตฟอร์มรองรับมาตรฐานสากลหลายรายการ ได้แก่ VCS (Verra), Gold Standard, CDM และ ACR ทำให้ TPIX Carbon Credits สอดคล้องกับกรอบการซื้อขายคาร์บอนทั่วโลก',
        overviewStats: [
            { value: '1 Credit', label: '= 1 tCO2e' },
            { value: '0 Gas', label: 'ค่าธรรมเนียม' },
            { value: '5+', label: 'ประเภทโครงการ' },
            { value: '4', label: 'มาตรฐานรับรอง' },
            { value: '3', label: 'สกุลเงินชำระ' },
            { value: 'NFT', label: 'ใบรับรอง' },
        ],
        whyTitle: 'วิกฤตสภาพอากาศและตลาดคาร์บอน',
        whyP1: 'โลกต้องลดการปล่อยก๊าซเรือนกระจกลง 45% ภายในปี 2030 เพื่อจำกัดภาวะโลกร้อนไว้ที่ 1.5 องศาเซลเซียส ตลาดคาร์บอนเป็นกลไกสำคัญในการกระตุ้นให้ลดการปล่อยก๊าซโดยกำหนดราคาให้กับคาร์บอน',
        whyP2: 'อย่างไรก็ตาม ตลาดคาร์บอนแบบดั้งเดิมมีปัญหาเรื่องความไม่โปร่งใส การนับซ้ำ ค่าใช้จ่ายตัวกลางสูง และการเข้าถึงจำกัดสำหรับผู้เข้าร่วมรายย่อย เทคโนโลยี Blockchain แก้ปัญหาเหล่านี้ได้',
        whyProblems: [
            { title: 'ความไม่โปร่งใสและการฉ้อโกง', desc: 'ทะเบียนคาร์บอนแบบดั้งเดิมเป็นฐานข้อมูลรวมศูนย์ที่เสี่ยงต่อการถูกดัดแปลง Credits อาจถูกนับซ้ำหรือออกให้โครงการที่ไม่มีอยู่จริง' },
            { title: 'ค่าใช้จ่ายตัวกลางสูง', desc: 'โบรกเกอร์ ตลาด และหน่วยตรวจสอบหักค่าใช้จ่าย 15-30% ของมูลค่า credit ทำให้โครงการขนาดเล็กไม่คุ้มทุน' },
            { title: 'การชำระเงินช้า', desc: 'การโอน credit แบบดั้งเดิมใช้เวลาหลายวันถึงหลายสัปดาห์ผ่านตัวกลางหลายราย สร้างความยุ่งยากและความเสี่ยงคู่สัญญา' },
            { title: 'การเข้าถึงจำกัด', desc: 'ธุรกิจขนาดเล็กและบุคคลทั่วไปถูกกีดกันจากตลาดคาร์บอน เนื่องจากยอดซื้อขั้นต่ำสูงและการเริ่มต้นที่ซับซ้อน' },
        ],
        whySolutions: [
            { title: 'บันทึกที่เปลี่ยนแปลงไม่ได้', desc: 'การออก โอน และ retire credit ทุกรายการถูกบันทึกบน TPIX Chain พร้อมหลักฐานเข้ารหัส การนับซ้ำเป็นไปไม่ได้ทางคณิตศาสตร์' },
            { title: 'ค่าธรรมเนียมเกือบเป็นศูนย์', desc: 'TPIX Chain ไม่มีค่า gas ค่าธรรมเนียมแพลตฟอร์มเพียง 2.5% ไม่มีค่าโบรกเกอร์ ทำให้โครงการขนาดเล็กคุ้มทุน' },
            { title: 'ชำระเงินทันที', desc: 'Credits โอนใน 2 วินาที (block time ของ TPIX Chain) ไม่ต้องรอ ไม่มีความเสี่ยงคู่สัญญา ไม่ต้องใช้ตัวกลาง' },
            { title: 'เปิดกว้างทุกคน', desc: 'ทุกคนที่มี wallet สามารถซื้อ credit ได้ตั้งแต่ 0.01 tCO2e ไม่มียอดซื้อขั้นต่ำ ไม่ต้อง KYC สำหรับจำนวนน้อย ไม่จำกัดพื้นที่' },
        ],
        howTitle: 'ขั้นตอนการซื้อและ Retire',
        howPurchaseSteps: [
            { num: 1, title: 'เลือกดูโครงการ', desc: 'สำรวจโครงการ carbon offset ที่ผ่านการรับรอง ตามประเภท มาตรฐาน พื้นที่ และปีผลิต' },
            { num: 2, title: 'เลือกและชำระเงิน', desc: 'เลือก credits และชำระด้วย TPIX, BNB หรือ USDT รองรับหลายสกุลเงินพร้อมราคาเรียลไทม์' },
            { num: 3, title: 'รับ Credits', desc: 'Credits ถูกบันทึกบน chain พร้อมหมายเลขซีเรียล (CC-XXXXXXXX-YYYYMMDD) เชื่อมกับ wallet ของคุณ' },
            { num: 4, title: 'ถือหรือ Retire', desc: 'ถือ credit ไว้เพื่อซื้อขาย หรือ retire เพื่อใช้สิทธิ์ carbon offset credit ที่ retire แล้วจะถูกเผาถาวร' },
        ],
        howRetireSteps: [
            { num: 1, title: 'เลือก Credits', desc: 'เลือก credits ที่ต้องการ retire จากพอร์ตของคุณ' },
            { num: 2, title: 'ระบุผู้รับประโยชน์', desc: 'ป้อนชื่อผู้รับประโยชน์และเหตุผลการ retire (เช่น carbon neutrality ของบริษัท)' },
            { num: 3, title: 'บันทึกบน Blockchain', desc: 'การ retire ถูกบันทึกอย่างถาวรบน TPIX Chain พร้อม transaction hash เฉพาะ' },
            { num: 4, title: 'ใบรับรอง NFT', desc: 'รับใบรับรองการ retire เป็น NFT พิสูจน์ carbon offset ของคุณอย่างถาวรบน chain' },
        ],
        projectTypesTitle: 'ประเภทโครงการที่รองรับ',
        projectTypes: [
            { icon: '🌳', title: 'ปลูกป่าและฟื้นฟูป่า', desc: 'โครงการปลูกต้นไม้ที่ดูดซับ CO2 ผ่านการสังเคราะห์แสง รวมถึงการฟื้นฟูป่าชายเลน ป่าในเมือง และการปลูกป่าเชิงพาณิชย์', metric: '5-20 tCO2e/เฮกตาร์/ปี', price: '$8-25/tCO2e' },
            { icon: '⚡', title: 'พลังงานหมุนเวียน', desc: 'โครงการพลังงานแสงอาทิตย์ ลม น้ำ และชีวมวล ที่ทดแทนการผลิตไฟฟ้าจากเชื้อเพลิงฟอสซิล', metric: '0.5-1.2 tCO2e/MWh ที่ทดแทน', price: '$5-15/tCO2e' },
            { icon: '🔥', title: 'ดักจับก๊าซมีเทน', desc: 'การดักจับมีเทนจากหลุมฝังกลบ เหมืองถ่านหิน และการเกษตร มีเทนมีศักยภาพทำให้โลกร้อนมากกว่า CO2 ถึง 80 เท่าในช่วง 20 ปี', metric: '21-80x เทียบเท่า CO2', price: '$10-30/tCO2e' },
            { icon: '🌊', title: 'มหาสมุทรและ Blue Carbon', desc: 'การฟื้นฟูระบบนิเวศชายฝั่ง ได้แก่ ป่าชายเลน หญ้าทะเล และป่าเค็ม ซึ่งกักเก็บคาร์บอนเร็วกว่าป่าเขตร้อน 2-4 เท่า', metric: '10-40 tCO2e/เฮกตาร์/ปี', price: '$15-40/tCO2e' },
            { icon: '🦋', title: 'อนุรักษ์ความหลากหลายทางชีวภาพ', desc: 'โครงการอนุรักษ์ป่า (REDD+) ที่ป้องกันการตัดไม้ทำลายป่า พร้อมปกป้องสัตว์ใกล้สูญพันธุ์และชุมชนท้องถิ่น', metric: 'แตกต่างตามโครงการ', price: '$10-35/tCO2e' },
            { icon: '🏭', title: 'ประสิทธิภาพอุตสาหกรรม', desc: 'การปรับปรุงประสิทธิภาพพลังงานในอุตสาหกรรม การขนส่ง และอาคาร รวมถึงโครงการเตาหุงต้มและการเพิ่มประสิทธิภาพกระบวนการ', metric: '0.1-5 tCO2e/หน่วย', price: '$3-12/tCO2e' },
            { icon: '🌾', title: 'เกษตรกรรมและคาร์บอนในดิน', desc: 'เกษตรกรรมฟื้นฟู การทำนาแบบไม่ไถ และการใช้ biochar ที่เพิ่มการกักเก็บคาร์บอนในดิน', metric: '1-5 tCO2e/เฮกตาร์/ปี', price: '$8-20/tCO2e' },
        ],
        standardsTitle: 'มาตรฐานการรับรอง',
        standards: [
            { name: 'VCS (Verra)', full: 'Verified Carbon Standard', desc: 'มาตรฐาน carbon credit สมัครใจที่ใช้แพร่หลายที่สุดในโลก มีโครงการที่ได้รับการรับรองมากกว่า 1,800 โครงการใน 80+ ประเทศ การตรวจสอบโดยบุคคลที่สามอย่างเข้มงวด', features: ['1,800+ โครงการรับรอง', '80+ ประเทศ', 'ตลาดสมัครใจที่สภาพคล่องสูงสุด', 'ทะเบียนสาธารณะละเอียด'] },
            { name: 'Gold Standard', full: 'Gold Standard for the Global Goals', desc: 'มาตรฐานระดับพรีเมียมที่ต้องแสดงผลประโยชน์ร่วมด้านการพัฒนาที่ยั่งยืนตาม UN SDGs Credit มีราคาสูงกว่า 20-50% เนื่องจากข้อกำหนดคุณภาพที่สูงกว่า', features: ['ต้องสอดคล้องกับ UN SDGs', 'ต้องปรึกษาผู้มีส่วนได้ส่วนเสีย', 'ราคาสูงกว่า 20-50%', 'ผลประโยชน์ร่วมทางสังคมสูง'] },
            { name: 'CDM', full: 'Clean Development Mechanism', desc: 'กลไกที่ได้รับการสนับสนุนจาก UN ภายใต้พิธีสาร Kyoto Credit ที่ได้รับการรับรองจากรัฐบาลในประเทศกำลังพัฒนา', features: ['ได้รับการสนับสนุนจาก UN', 'ต้องอนุมัติจากรัฐบาล', 'เน้นประเทศกำลังพัฒนา', 'ใช้ในตลาดบังคับได้'] },
            { name: 'ACR', full: 'American Carbon Registry', desc: 'ทะเบียน carbon credit เอกชนแห่งแรกของโลก (ก่อตั้ง 1996) เน้นโครงการในอเมริกาเหนือพร้อมวิธีการทางวิทยาศาสตร์ที่เข้มงวด', features: ['ทะเบียนเอกชนเก่าแก่สุด (1996)', 'เน้นอเมริกาเหนือ', 'วิธีการทางวิทยาศาสตร์', 'ใช้ในโปรแกรมรัฐบาลได้'] },
        ],
        marketTitle: 'ตลาด TPIX Carbon Credit',
        marketP1: 'ตลาด TPIX Carbon Credit เป็นแพลตฟอร์มกระจายศูนย์ที่ผู้ซื้อและผู้ขายสามารถซื้อขาย carbon credits ที่ผ่านการรับรองโดยตรงบน blockchain ไม่มีตัวกลาง ไม่มีค่าธรรมเนียมแอบแฝง',
        marketFeatures: [
            { title: 'ชำระเงินหลายสกุล', desc: 'ชำระด้วย TPIX (เหรียญดั้งเดิม ค่าธรรมเนียมต่ำสุด), BNB (ข้ามเชนผ่าน bridge) หรือ USDT (stablecoin ผูกกับ USD)', icon: '💳' },
            { title: 'ราคาเรียลไทม์', desc: 'ราคาแบบไดนามิกตามประเภทโครงการ มาตรฐาน ปีผลิต และอุปสงค์ตลาด ข้อมูลราคาอัปเดตจาก oracle', icon: '📊' },
            { title: 'ชำระเงินทันที', desc: 'สิ้นสุดใน 2 วินาทีบน TPIX Chain Credits โอนทันทีเมื่อยืนยันการชำระ ไม่มี T+2', icon: '⚡' },
            { title: 'ใบรับรอง NFT', desc: 'การ retire ทุกครั้งสร้างใบรับรอง NFT ถาวรบน TPIX Chain หลักฐาน carbon offset ที่ไม่สามารถโต้แย้งหรือสูญหาย', icon: '🏆' },
            { title: 'แดชบอร์ดพอร์ต', desc: 'ติดตาม credit ที่ถือ ประวัติ retire ผลกระทบ offset รวม และรายการรอดำเนินการในแดชบอร์ดเดียว', icon: '📈' },
            { title: 'ซื้อขายจำนวนมาก', desc: 'API สำหรับองค์กรเพื่อซื้อและ retire credits จำนวนมาก รองรับโปรแกรม carbon neutrality และรายงาน ESG', icon: '🏢' },
        ],
        blockchainTitle: 'สถาปัตยกรรม On-Chain',
        blockchainP1: 'TPIX Carbon Credit ใช้โมเดลไฮบริดที่ผสมผสานความโปร่งใสของ on-chain กับประสิทธิภาพของ off-chain',
        blockchainModel: [
            { layer: 'On-Chain (TPIX Chain)', items: ['หมายเลขซีเรียลและความเป็นเจ้าของ credit', 'ธุรกรรมการซื้อและ retire', 'ใบรับรอง NFT (ERC-721)', 'Transaction hash และ timestamp', 'การตรวจสอบ certificate hash'] },
            { layer: 'Off-Chain (Database)', items: ['รายละเอียดและคำอธิบายโครงการ', 'การกำหนดราคาและการชำระเงิน', 'โปรไฟล์ผู้ใช้และข้อมูล KYC', 'การวิเคราะห์และรายงาน', 'เครื่องมือจัดการสำหรับผู้ดูแล'] },
        ],
        blockchainSpecs: [
            ['Chain', 'TPIX Chain (Chain ID: 4289)'],
            ['Consensus', 'IBFT Proof-of-Authority'],
            ['Block Time', '2 วินาที'],
            ['Gas Price', '0 (ไม่มีค่า gas)'],
            ['มาตรฐาน Contract', 'ERC-1155 (Multi-Token) + ERC-721 (ใบรับรอง)'],
            ['รูปแบบหมายเลขซีเรียล', 'CC-XXXXXXXX-YYYYMMDD'],
            ['Certificate Hash', '0x + 64 ตัวอักษร hex (SHA-256)'],
        ],
        tokenomicsTitle: 'เศรษฐศาสตร์ Credit',
        tokenomicsP1: 'Carbon credit แต่ละหน่วยบน TPIX แทนการลดก๊าซเรือนกระจก 1 เมตริกตัน CO2 เทียบเท่า (1 tCO2e) ราคา credit เป็น USD แต่ชำระได้หลายสกุลเงิน',
        tokenomicsFees: [
            { item: 'ค่าธรรมเนียมแพลตฟอร์ม', value: '2.5%', desc: 'คิดจากทุกการซื้อ เพื่อพัฒนาแพลตฟอร์มและการตรวจสอบ' },
            { item: 'ค่าธรรมเนียม Retire', value: '0%', desc: 'Retire ฟรีเพื่อกระตุ้นการ offset คาร์บอน' },
            { item: 'ค่า Gas', value: '0', desc: 'TPIX Chain ไม่มีค่า gas ธุรกรรมทั้งหมดฟรี' },
            { item: 'ยอดซื้อขั้นต่ำ', value: '0.01 tCO2e', desc: 'ซื้อจำนวนน้อยได้เพื่อให้ทุกคนมีส่วนร่วม' },
        ],
        apiTitle: 'API Reference',
        apiP1: 'TPIX Carbon Credit API ช่วยให้ระบบภายนอก แอปมือถือ และระบบองค์กรเชื่อมต่อกับตลาด carbon credit ได้ผ่านโปรแกรม',
        mobileTitle: 'การเชื่อมต่อแอปมือถือ',
        mobileP1: 'ระบบ TPIX Carbon Credit ออกแบบมาเพื่อประสบการณ์มือถือเป็นหลัก แอปมือถือที่กำลังจะมาจะมีฟังก์ชัน marketplace ครบถ้วนบน iOS และ Android',
        mobileFeatures: [
            { title: 'เรียกดูและซื้อ', desc: 'ค้นหาโครงการและซื้อ credits ได้โดยตรงจากโทรศัพท์', status: 'API พร้อม' },
            { title: 'ติดตามพอร์ต', desc: 'ตรวจสอบ credit ที่ถืออยู่และผลกระทบ offset', status: 'API พร้อม' },
            { title: 'Retire และใบรับรอง', desc: 'Retire credits และรับใบรับรอง NFT ในแอป', status: 'API พร้อม' },
            { title: 'สแกน QR', desc: 'สแกน QR code เพื่อตรวจสอบใบรับรองการ retire ทันที', status: 'กำลังพัฒนา' },
            { title: 'การแจ้งเตือน Push', desc: 'แจ้งเตือนโครงการใหม่ การเปลี่ยนแปลงราคา และ credit ใกล้หมดอายุ', status: 'กำลังพัฒนา' },
            { title: 'คำนวณ Offset', desc: 'คำนวณ carbon footprint และจำนวน offset ที่แนะนำ', status: 'กำลังพัฒนา' },
        ],
        legalP1: 'Carbon credits ที่ซื้อขายบนแพลตฟอร์มนี้เป็น voluntary carbon offsets และไม่ใช่ compliance credits ภายใต้กรอบกฎหมายใดๆ เว้นแต่ระบุไว้เป็นการเฉพาะ แพลตฟอร์มอำนวยความสะดวกในการซื้อขาย carbon credits แบบ peer-to-peer และไม่รับประกันมูลค่า สภาพคล่อง หรือสถานะทางกฎหมายในอนาคตของ credit ใดๆ',
        legalP2: 'การตรวจสอบโครงการดำเนินการโดยหน่วยงานอิสระตามมาตรฐานที่ระบุ (VCS, Gold Standard, CDM, ACR) TPIX TRADE ไม่ได้ตรวจสอบการลดการปล่อยก๊าซด้วยตนเอง และอ้างอิงตามการรับรองที่ระบุ ผู้ใช้ควรศึกษาข้อมูลด้วยตนเองก่อนซื้อ carbon credits',
    },
};
</script>

<template>
    <Head :title="t.pageTitle" />

    <AppLayout :title="t.pageTitle">

        <!-- Hero -->
        <section class="relative py-16 overflow-hidden">
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute top-0 left-1/4 w-96 h-96 rounded-full bg-green-500/10 blur-[120px]" />
                <div class="absolute bottom-0 right-1/3 w-80 h-80 rounded-full bg-emerald-500/10 blur-[100px]" />
            </div>

            <div class="relative max-w-4xl mx-auto px-4 text-center">
                <!-- Carbon Icon -->
                <div class="w-28 h-28 mx-auto mb-6 rounded-full bg-gradient-to-br from-green-500/20 to-emerald-500/20 border border-green-500/30 flex items-center justify-center shadow-lg shadow-green-500/20">
                    <svg class="w-16 h-16 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
                    </svg>
                </div>

                <h1 class="text-4xl sm:text-5xl font-bold text-white mb-2">{{ t.pageTitle }}</h1>
                <p class="text-lg text-green-400 font-medium mb-1">{{ t.subtitle }}</p>
                <p class="text-sm text-gray-500 mb-6">{{ t.version }}</p>

                <div class="flex flex-wrap items-center justify-center gap-3">
                    <button @click="downloadPdf" class="btn-primary px-8 py-3 inline-flex items-center gap-2 font-semibold">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        {{ t.downloadPdf }}
                    </button>
                    <button @click="toggleLang" class="btn-secondary px-6 py-3 font-semibold">
                        {{ lang === 'en' ? t.readInThai : t.readInEn }}
                    </button>
                    <Link href="/carbon-credits" class="btn-secondary px-6 py-3 font-semibold inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" /></svg>
                        {{ lang === 'en' ? 'Go to Marketplace' : 'ไปยังตลาด' }}
                    </Link>
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
                                    ? 'text-green-400 bg-green-500/10 font-medium'
                                    : 'text-gray-500 hover:text-white hover:bg-white/5'"
                                @click="scrollTo(s.id)"
                            >{{ s.title }}</button>
                        </nav>
                    </div>
                </aside>

                <!-- Main Content -->
                <main class="flex-1 min-w-0">

                    <!-- 1. Overview -->
                    <section id="overview" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[0] }}</h2>
                        <h3 class="wp-subheading text-green-400">{{ t.overviewTitle }}</h3>
                        <p class="wp-text">{{ t.overviewP1 }}</p>
                        <p class="wp-text">{{ t.overviewP2 }}</p>
                        <p class="wp-text font-medium text-white/90">{{ t.overviewP3 }}</p>

                        <div class="wp-highlight">
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-center">
                                <div v-for="s in t.overviewStats" :key="s.label">
                                    <p class="text-2xl font-bold text-green-400">{{ s.value }}</p>
                                    <p class="text-xs text-gray-400 mt-1">{{ s.label }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 2. Why Carbon Credits -->
                    <section id="why-carbon" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[1] }}</h2>
                        <p class="wp-text">{{ t.whyP1 }}</p>
                        <p class="wp-text">{{ t.whyP2 }}</p>

                        <h3 class="wp-subheading text-trading-red">{{ lang === 'en' ? 'The Problems' : 'ปัญหา' }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4 mb-8">
                            <div v-for="p in t.whyProblems" :key="p.title" class="p-4 rounded-xl bg-red-500/5 border border-red-500/10">
                                <h4 class="font-semibold text-white mb-2">{{ p.title }}</h4>
                                <p class="text-sm text-gray-400">{{ p.desc }}</p>
                            </div>
                        </div>

                        <h3 class="wp-subheading text-trading-green">{{ lang === 'en' ? 'Our Solutions' : 'ทางออกของเรา' }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="s in t.whySolutions" :key="s.title" class="p-4 rounded-xl bg-green-500/5 border border-green-500/10">
                                <h4 class="font-semibold text-white mb-2">{{ s.title }}</h4>
                                <p class="text-sm text-gray-400">{{ s.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 3. How It Works -->
                    <section id="how-it-works" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[2] }}</h2>

                        <!-- Purchase Flow Diagram -->
                        <div class="wp-highlight mb-6">
                            <h4 class="text-center text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                {{ lang === 'en' ? 'Purchase Flow' : 'ขั้นตอนการซื้อ' }}
                            </h4>
                            <div class="flex flex-wrap items-center justify-center gap-2 text-xs">
                                <span class="px-3 py-2 rounded-lg bg-blue-500/20 text-blue-300 font-medium">{{ lang === 'en' ? 'Browse' : 'เลือกดู' }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-purple-500/20 text-purple-300 font-medium">{{ lang === 'en' ? 'Select & Pay' : 'เลือกและชำระ' }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-green-500/20 text-green-300 font-medium">{{ lang === 'en' ? 'On-Chain Record' : 'บันทึกบน Chain' }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-yellow-500/20 text-yellow-300 font-medium">{{ lang === 'en' ? 'Hold or Retire' : 'ถือหรือ Retire' }}</span>
                            </div>
                        </div>

                        <h3 class="wp-subheading text-green-400">{{ lang === 'en' ? 'Buying Credits' : 'การซื้อ Credits' }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4 mb-8">
                            <div v-for="step in t.howPurchaseSteps" :key="step.num" class="p-4 rounded-xl bg-white/5 border border-white/10">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="w-8 h-8 rounded-full bg-green-500/20 flex items-center justify-center text-green-400 text-sm font-bold">{{ step.num }}</span>
                                    <h4 class="font-semibold text-white">{{ step.title }}</h4>
                                </div>
                                <p class="text-sm text-gray-400 ml-11">{{ step.desc }}</p>
                            </div>
                        </div>

                        <!-- Retire Flow Diagram -->
                        <div class="wp-highlight mb-6">
                            <h4 class="text-center text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                {{ lang === 'en' ? 'Retirement Flow' : 'ขั้นตอนการ Retire' }}
                            </h4>
                            <div class="flex flex-wrap items-center justify-center gap-2 text-xs">
                                <span class="px-3 py-2 rounded-lg bg-emerald-500/20 text-emerald-300 font-medium">{{ lang === 'en' ? 'Select Credits' : 'เลือก Credits' }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-teal-500/20 text-teal-300 font-medium">{{ lang === 'en' ? 'Set Beneficiary' : 'ระบุผู้รับประโยชน์' }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-cyan-500/20 text-cyan-300 font-medium">{{ lang === 'en' ? 'Burn on Chain' : 'เผาบน Chain' }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-amber-500/20 text-amber-300 font-medium">{{ lang === 'en' ? 'NFT Certificate' : 'ใบรับรอง NFT' }}</span>
                            </div>
                        </div>

                        <h3 class="wp-subheading text-emerald-400">{{ lang === 'en' ? 'Retiring Credits' : 'การ Retire Credits' }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="step in t.howRetireSteps" :key="step.num" class="p-4 rounded-xl bg-white/5 border border-white/10">
                                <div class="flex items-center gap-3 mb-2">
                                    <span class="w-8 h-8 rounded-full bg-emerald-500/20 flex items-center justify-center text-emerald-400 text-sm font-bold">{{ step.num }}</span>
                                    <h4 class="font-semibold text-white">{{ step.title }}</h4>
                                </div>
                                <p class="text-sm text-gray-400 ml-11">{{ step.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 4. Project Types -->
                    <section id="project-types" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[3] }}</h2>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="pt in t.projectTypes" :key="pt.title" class="p-5 rounded-xl bg-white/5 border border-white/10 hover:border-green-500/30 transition-colors">
                                <div class="flex items-start gap-3 mb-3">
                                    <span class="text-2xl">{{ pt.icon }}</span>
                                    <div class="flex-1">
                                        <h4 class="font-semibold text-white">{{ pt.title }}</h4>
                                        <div class="flex gap-3 mt-1">
                                            <span class="text-xs text-green-400">{{ pt.metric }}</span>
                                            <span class="text-xs text-yellow-400">{{ pt.price }}</span>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-sm text-gray-400">{{ pt.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 5. Standards -->
                    <section id="standards" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[4] }}</h2>
                        <div class="space-y-4">
                            <div v-for="std in t.standards" :key="std.name" class="p-5 rounded-xl bg-white/5 border border-white/10">
                                <div class="flex items-start gap-4">
                                    <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-green-500/20 to-emerald-500/20 flex items-center justify-center flex-shrink-0">
                                        <span class="text-green-400 font-bold text-xs text-center leading-tight">{{ std.name.split(' ')[0] }}</span>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-semibold text-white">{{ std.name }}</h4>
                                        <p class="text-xs text-green-400 mb-2">{{ std.full }}</p>
                                        <p class="text-sm text-gray-400 mb-3">{{ std.desc }}</p>
                                        <div class="flex flex-wrap gap-2">
                                            <span v-for="f in std.features" :key="f" class="px-2 py-1 rounded-md bg-green-500/10 text-green-400 text-xs">{{ f }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 6. Marketplace -->
                    <section id="marketplace" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[5] }}</h2>
                        <p class="wp-text">{{ t.marketP1 }}</p>
                        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="f in t.marketFeatures" :key="f.title" class="p-5 rounded-xl bg-white/5 border border-white/10 hover:border-green-500/30 transition-colors">
                                <span class="text-2xl mb-3 block">{{ f.icon }}</span>
                                <h4 class="font-semibold text-white mb-2">{{ f.title }}</h4>
                                <p class="text-sm text-gray-400">{{ f.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 7. Blockchain -->
                    <section id="blockchain" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[6] }}</h2>
                        <p class="wp-text">{{ t.blockchainP1 }}</p>

                        <!-- Hybrid Model Diagram -->
                        <div class="grid sm:grid-cols-2 gap-4 mb-6">
                            <div v-for="model in t.blockchainModel" :key="model.layer" class="p-5 rounded-xl border" :class="model.layer.includes('On-Chain') ? 'bg-green-500/5 border-green-500/20' : 'bg-blue-500/5 border-blue-500/20'">
                                <h4 class="font-semibold mb-3" :class="model.layer.includes('On-Chain') ? 'text-green-400' : 'text-blue-400'">{{ model.layer }}</h4>
                                <ul class="space-y-1.5">
                                    <li v-for="item in model.items" :key="item" class="text-sm text-gray-400 flex items-start gap-2">
                                        <span class="mt-1" :class="model.layer.includes('On-Chain') ? 'text-green-500' : 'text-blue-500'">&#x25B8;</span>
                                        <span>{{ item }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Chain Specs -->
                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Parameter' : 'พารามิเตอร์' }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Value' : 'ค่า' }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(row, i) in t.blockchainSpecs" :key="i" :class="i < t.blockchainSpecs.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3 text-gray-300">{{ row[0] }}</td>
                                        <td class="py-2 px-3 text-white font-medium font-mono text-xs">{{ row[1] }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 8. Credit Tokenomics -->
                    <section id="tokenomics" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[7] }}</h2>
                        <p class="wp-text">{{ t.tokenomicsP1 }}</p>

                        <div class="grid lg:grid-cols-2 gap-8 items-start">
                            <!-- Donut Chart -->
                            <div class="flex flex-col items-center">
                                <h4 class="text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                    {{ lang === 'en' ? 'Project Type Distribution' : 'สัดส่วนประเภทโครงการ' }}
                                </h4>
                                <svg viewBox="0 0 200 200" class="w-64 h-64">
                                    <path v-for="seg in donutSegments" :key="seg.label" :d="seg.path" :fill="seg.color" class="opacity-90 hover:opacity-100 transition-opacity cursor-pointer" />
                                    <text x="100" y="95" text-anchor="middle" fill="white" font-size="11" font-weight="bold">CO2</text>
                                    <text x="100" y="112" text-anchor="middle" fill="#9CA3AF" font-size="7">tCO2e</text>
                                </svg>
                                <div class="grid grid-cols-1 gap-2 mt-4 w-full max-w-xs">
                                    <div v-for="item in creditAllocation" :key="item.label" class="flex items-center gap-2">
                                        <span class="w-3 h-3 rounded-full flex-shrink-0" :style="{ backgroundColor: item.color }"></span>
                                        <span class="text-sm text-gray-300 flex-1">{{ lang === 'en' ? item.label : item.labelTh }}</span>
                                        <span class="text-sm font-bold text-white">{{ item.pct }}%</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Fee Table -->
                            <div>
                                <h4 class="text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                    {{ lang === 'en' ? 'Fee Structure' : 'โครงสร้างค่าธรรมเนียม' }}
                                </h4>
                                <div class="wp-table">
                                    <table class="w-full text-sm">
                                        <thead><tr class="border-b border-white/10">
                                            <th class="text-left py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Item' : 'รายการ' }}</th>
                                            <th class="text-center py-2 px-3 text-gray-400">{{ lang === 'en' ? 'Fee' : 'ค่าธรรมเนียม' }}</th>
                                        </tr></thead>
                                        <tbody>
                                            <tr v-for="(fee, i) in t.tokenomicsFees" :key="fee.item" :class="i < t.tokenomicsFees.length - 1 ? 'border-b border-white/5' : ''">
                                                <td class="py-2 px-3">
                                                    <p class="text-white">{{ fee.item }}</p>
                                                    <p class="text-xs text-gray-500">{{ fee.desc }}</p>
                                                </td>
                                                <td class="py-2 px-3 text-center font-bold text-green-400">{{ fee.value }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 9. API Reference -->
                    <section id="api" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[8] }}</h2>
                        <p class="wp-text">{{ t.apiP1 }}</p>

                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">Method</th>
                                    <th class="text-left py-2 px-3 text-gray-400">Endpoint</th>
                                    <th class="text-left py-2 px-3 text-gray-400 hidden sm:table-cell">{{ lang === 'en' ? 'Description' : 'คำอธิบาย' }}</th>
                                    <th class="text-center py-2 px-3 text-gray-400">Auth</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(ep, i) in apiEndpoints" :key="ep.path" :class="i < apiEndpoints.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3">
                                            <span class="px-2 py-0.5 rounded text-xs font-bold" :class="ep.method === 'GET' ? 'bg-blue-500/20 text-blue-400' : 'bg-yellow-500/20 text-yellow-400'">{{ ep.method }}</span>
                                        </td>
                                        <td class="py-2 px-3 text-white font-mono text-xs">{{ ep.path }}</td>
                                        <td class="py-2 px-3 text-gray-400 hidden sm:table-cell">{{ lang === 'en' ? ep.desc : ep.descTh }}</td>
                                        <td class="py-2 px-3 text-center">
                                            <span v-if="ep.auth" class="text-yellow-400 text-xs">Wallet</span>
                                            <span v-else class="text-green-400 text-xs">Public</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 10. Mobile App -->
                    <section id="mobile" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[9] }}</h2>
                        <p class="wp-text">{{ t.mobileP1 }}</p>
                        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="f in t.mobileFeatures" :key="f.title" class="p-5 rounded-xl bg-white/5 border border-white/10">
                                <div class="flex items-center justify-between mb-3">
                                    <h4 class="font-semibold text-white">{{ f.title }}</h4>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="f.status.includes('Ready') || f.status.includes('พร้อม') ? 'bg-green-500/20 text-green-400' : 'bg-yellow-500/20 text-yellow-400'">{{ f.status }}</span>
                                </div>
                                <p class="text-sm text-gray-400">{{ f.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 11. Roadmap -->
                    <section id="roadmap" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[10] }}</h2>
                        <div class="space-y-6">
                            <div v-for="(phase, idx) in roadmap" :key="phase.phase" class="relative pl-8">
                                <!-- Timeline line -->
                                <div v-if="idx < roadmap.length - 1" class="absolute left-3 top-8 bottom-0 w-px bg-green-500/20"></div>
                                <!-- Timeline dot -->
                                <div class="absolute left-0 top-1 w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold" :class="idx === 0 ? 'bg-green-500/30 text-green-400 ring-2 ring-green-500/50' : 'bg-white/10 text-gray-400'">
                                    {{ idx + 1 }}
                                </div>
                                <div class="p-5 rounded-xl bg-white/5 border border-white/10" :class="idx === 0 ? 'border-green-500/30' : ''">
                                    <div class="flex items-center gap-3 mb-3">
                                        <span class="px-2 py-0.5 rounded-md bg-green-500/20 text-green-400 text-xs font-bold">{{ phase.period }}</span>
                                        <h4 class="font-semibold text-white">{{ phase.phase }} — {{ phase.title }}</h4>
                                    </div>
                                    <ul class="space-y-1.5">
                                        <li v-for="item in phase.items" :key="item" class="text-sm text-gray-400 flex items-start gap-2">
                                            <span class="text-green-500 mt-0.5">&#x25B8;</span>
                                            <span>{{ item }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 12. Legal -->
                    <section id="legal" class="wp-section">
                        <h2 class="wp-heading">{{ t.toc[11] }}</h2>
                        <div class="p-6 rounded-xl bg-white/5 border border-white/10">
                            <p class="text-sm text-gray-500 mb-3">{{ t.legalP1 }}</p>
                            <p class="text-sm text-gray-500">{{ t.legalP2 }}</p>
                        </div>
                    </section>

                </main>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.wp-section { @apply mb-16 scroll-mt-24; }
.wp-heading { @apply text-2xl sm:text-3xl font-bold text-white mb-4 pb-3 border-b border-white/10; }
.wp-subheading { @apply text-lg font-semibold text-white mt-6 mb-3; }
.wp-text { @apply text-gray-300 leading-relaxed mb-4; }
.wp-list { @apply list-disc list-inside space-y-2 text-gray-300 mb-4; }
.wp-highlight { @apply p-6 rounded-xl bg-white/5 border border-white/10 my-6; }
.wp-table { @apply rounded-xl bg-white/5 border border-white/10 overflow-hidden my-6; }
</style>
