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


import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SectionVideo from '@/Components/Whitepaper/SectionVideo.vue';
import BrandLogo from '@/Components/Brand/BrandLogo.vue';
import { useTranslation } from '@/Composables/useTranslation';

// ภาษาตามระบบทั้งเว็บ — สลับ TH/EN ที่ NavBar หรือปุ่มในหน้านี้ก็เปลี่ยนทั้งหน้าทันที
const { t, locale, setLocale } = useTranslation();
const lang = locale;
const toggleLang = () => setLocale(locale.value === 'en' ? 'th' : 'en');

// คีย์ทั้งหมดของหน้านี้อยู่ใต้ page.whitepaper
const tw = (key, params) => t(`page.whitepaper.${key}`, params);
const seq = (n) => Array.from({ length: n }, (_, i) => i + 1);
const list = (key, n) => seq(n).map(i => tw(`${key}.item${i}`));

// สารบัญ — Table of Contents
const SECTION_IDS = [
    'executive-summary', 'problem-solution', 'tpix-chain', 'tokenomics', 'use-cases',
    'dex-protocol', 'ai-trade', 'token-sale', 'masternode', 'living-identity',
    'governance', 'bridge', 'ecosystem', 'integrations', 'roadmap',
    'tech-stack', 'team', 'security', 'legal',
];
const sections = computed(() => SECTION_IDS.map((id, i) => ({ id, title: tw(`toc.s${i + 1}`) })));

// Section ที่กำลังดูอยู่
const activeSection = ref('executive-summary');

// สารบัญแบบพับได้บนจอเล็ก (จอ xl ขึ้นไปใช้ sidebar แทน)
const tocOpen = ref(false);
const activeTitle = computed(() => sections.value.find(s => s.id === activeSection.value)?.title ?? sections.value[0].title);

// แถบความคืบหน้าการอ่าน 0–100
const readProgress = ref(0);

// section ที่กำลังอ่าน = ตัวสุดท้ายที่หัวเลื่อนผ่านเส้นใต้ NavBar + แถบสารบัญแล้ว
// (IntersectionObserver แบบ threshold ใช้ไม่ได้กับ section ที่ยาวกว่าจอ — ไม่เคยถึงสัดส่วน)
// เบราว์เซอร์ยิง scroll ไม่เกินเฟรมละครั้งอยู่แล้ว — อ่านตำแหน่ง 19 section ต่อครั้งถือว่าเบา
const READ_LINE_PX = 150;
function onScroll() {
    const max = document.documentElement.scrollHeight - window.innerHeight;
    readProgress.value = max > 0 ? Math.min(100, Math.max(0, (window.scrollY / max) * 100)) : 0;

    let current = sections.value[0].id;
    for (const s of sections.value) {
        const el = document.getElementById(s.id);
        if (el && el.getBoundingClientRect().top <= READ_LINE_PX) current = s.id;
        else break;
    }
    activeSection.value = current;
}

// Scroll ไปยัง section
function scrollTo(id) {
    activeSection.value = id;
    tocOpen.value = false;
    document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

// ติดตาม scroll เพื่ออัปเดต active section + แถบความคืบหน้า
onMounted(() => {
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
});
onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScroll);
});

// Download PDF — Dark Premium Theme (เหมือนหน้าเว็บ)
// รองรับไทย/อังกฤษ, ป้องกันเนื้อหาขาดข้ามหน้า, หน้าปกหรูหรา
function downloadPdf() {
    const style = document.createElement('style');
    style.id = 'print-style';
    style.textContent = `
        @page {
            size: A4;
            margin: 15mm 12mm 18mm 12mm;
        }

        @media print {
            /* ===== Reset & พื้นฐาน ===== */
            *, *::before, *::after {
                box-shadow: none !important;
                backdrop-filter: none !important;
                -webkit-backdrop-filter: none !important;
                text-shadow: none !important;
            }

            html, body {
                background: #0a0e1a !important;
                color: #cbd5e1 !important;
                font-family: 'Sarabun', 'Noto Sans Thai', 'Inter', sans-serif !important;
                font-size: 9.5pt !important;
                line-height: 1.6 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            /* ===== ซ่อน UI elements ===== */
            nav, header, footer, aside,
            button, .btn-primary, .btn-secondary, .btn-brand,
            .fixed, .sticky,
            .ticker-strip, .banner-ad,
            [class*="animate-pulse"],
            [class*="blur-"] {
                display: none !important;
            }

            /* ===== Layout: content full width ===== */
            .flex.gap-8 { display: block !important; }
            aside { display: none !important; }
            main, .flex-1, .min-w-0 {
                max-width: 100% !important;
                width: 100% !important;
            }
            .max-w-7xl { max-width: 100% !important; }
            .px-4, .sm\\:px-6 { padding-left: 0 !important; padding-right: 0 !important; }

            /* ===== หน้าปก ===== */
            .print-cover {
                page-break-after: always !important;
                background: #0a0e1a !important;
                min-height: 90vh !important;
                display: flex !important;
                flex-direction: column !important;
                align-items: center !important;
                justify-content: center !important;
                text-align: center !important;
                position: relative !important;
            }
            .print-cover::before {
                content: '' !important;
                position: absolute !important;
                top: 20% !important; left: 50% !important;
                transform: translateX(-50%) !important;
                width: 400px !important; height: 400px !important;
                background: radial-gradient(circle, rgba(59,130,246,0.1) 0%, transparent 70%) !important;
                border-radius: 50% !important;
            }
            .print-cover img {
                display: block !important;
                width: 80px !important; height: 80px !important;
                margin: 0 auto 24px !important;
                position: relative !important;
            }
            .print-cover h1 {
                font-size: 28pt !important;
                font-weight: 800 !important;
                color: #f1f5f9 !important;
                margin-bottom: 6px !important;
                position: relative !important;
            }
            .print-cover .text-primary-400 {
                color: #60a5fa !important;
                font-size: 12pt !important;
                position: relative !important;
            }
            .print-cover .text-gray-500 {
                color: #64748b !important;
                font-size: 9pt !important;
                position: relative !important;
            }

            /* ===== Typography ===== */
            h1 { font-size: 18pt !important; color: #f1f5f9 !important; font-weight: 800 !important; }
            h2 {
                font-size: 14pt !important;
                color: #e2e8f0 !important;
                font-weight: 700 !important;
                border-bottom: 1px solid rgba(255,255,255,0.1) !important;
                padding-bottom: 6px !important;
                margin-top: 0 !important;
                margin-bottom: 10px !important;
            }
            h3 { font-size: 11pt !important; color: #e2e8f0 !important; font-weight: 600 !important; margin-bottom: 6px !important; }
            h4 { font-size: 10pt !important; color: #cbd5e1 !important; font-weight: 600 !important; }
            p, li, span, div { color: #94a3b8 !important; }
            a { color: #60a5fa !important; text-decoration: none !important; }

            .text-gradient, .text-gradient-brand { -webkit-text-fill-color: #60a5fa !important; background: none !important; color: #60a5fa !important; }
            .text-gradient-gold { -webkit-text-fill-color: #fbbf24 !important; background: none !important; color: #fbbf24 !important; }
            .font-medium, strong, b { color: #e2e8f0 !important; }

            /* ===== Page Break Management ===== */
            /* ห้าม break ใน section — ลบออก! section ยาวมากจะทำให้เกิดหน้าว่าง */
            /* ใช้ break-before บน heading แทน เพื่อให้ section ใหม่เริ่มหน้าใหม่ */
            .wp-section {
                margin-bottom: 20px !important;
            }

            /* section สำคัญ เริ่มหน้าใหม่ */
            #problem-solution, #tpix-chain, #tokenomics, #use-cases,
            #dex-protocol, #masternode, #roadmap, #tech-stack, #security {
                page-break-before: always !important;
            }

            /* heading ห้ามอยู่ท้ายหน้า */
            h2, h3, h4 {
                page-break-after: avoid !important;
                break-after: avoid !important;
            }

            /* ย่อหน้า — orphan/widow */
            p, .wp-text { orphans: 3; widows: 3; }

            /* card/highlight เล็กๆ ห้าม break ข้ามหน้า */
            .glass-dark, [class*="rounded-xl"] {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            /* table row ห้าม break ข้ามหน้า (แต่ table ทั้งอันได้) */
            tr {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }
            thead { display: table-header-group !important; }

            /* SVG ห้าม break */
            svg, img, figure {
                page-break-inside: avoid !important;
                break-inside: avoid !important;
            }

            /* ===== ตาราง ===== */
            table {
                border-collapse: separate !important;
                border-spacing: 0 !important;
                width: 100% !important;
                font-size: 8pt !important;
                margin: 6px 0 !important;
                border: 1px solid rgba(255,255,255,0.08) !important;
                border-radius: 6px !important;
            }
            thead tr { background: rgba(255,255,255,0.06) !important; }
            th {
                background: rgba(255,255,255,0.06) !important;
                color: #94a3b8 !important;
                font-weight: 600 !important;
                border-bottom: 1px solid rgba(255,255,255,0.08) !important;
                border-right: none !important; border-left: none !important; border-top: none !important;
                padding: 6px 8px !important;
                text-align: left !important;
                font-size: 7pt !important;
                text-transform: uppercase !important;
                letter-spacing: 0.5px !important;
            }
            td {
                border-bottom: 1px solid rgba(255,255,255,0.04) !important;
                border-right: none !important; border-left: none !important; border-top: none !important;
                padding: 5px 8px !important;
                color: #cbd5e1 !important;
                vertical-align: top !important;
            }
            tbody tr:nth-child(even) { background: rgba(255,255,255,0.02) !important; }

            /* ===== Cards & Highlights ===== */
            .wp-highlight {
                border: 1px solid rgba(255,255,255,0.08) !important;
                border-radius: 8px !important;
                padding: 12px !important;
                background: rgba(255,255,255,0.03) !important;
                margin: 8px 0 !important;
            }
            .wp-table {
                border: 1px solid rgba(255,255,255,0.08) !important;
                border-radius: 8px !important;
                background: rgba(255,255,255,0.02) !important;
                margin: 6px 0 !important;
            }
            .wp-section { padding: 0 !important; }

            /* Problem/Solution cards */
            [class*="bg-red-500/5"], [class*="bg-red-500/10"] {
                border: 1px solid rgba(239,68,68,0.2) !important;
                border-left: 3px solid #ef4444 !important;
                padding: 10px !important;
                background: rgba(239,68,68,0.05) !important;
                border-radius: 6px !important;
            }
            [class*="bg-green-500/5"], [class*="bg-green-500/10"] {
                border: 1px solid rgba(34,197,94,0.2) !important;
                border-left: 3px solid #22c55e !important;
                padding: 10px !important;
                background: rgba(34,197,94,0.05) !important;
                border-radius: 6px !important;
            }

            /* Stats numbers */
            .wp-highlight .text-2xl, .text-2xl {
                font-size: 14pt !important;
                font-weight: 700 !important;
                color: #60a5fa !important;
            }
            .text-3xl { font-size: 16pt !important; font-weight: 700 !important; }
            .text-lg { font-size: 10.5pt !important; }

            /* ===== Grid — ปรับให้เหมาะกับ A4 ===== */
            .grid { display: grid !important; gap: 8px !important; }

            /* Stats 6 คอลัมน์: คงไว้ 3 คอลัมน์ */
            .grid-cols-2.sm\\:grid-cols-3.lg\\:grid-cols-6 {
                grid-template-columns: repeat(3, 1fr) !important;
            }

            /* 2 คอลัมน์ปกติ: คงไว้ */
            .sm\\:grid-cols-2 { grid-template-columns: repeat(2, 1fr) !important; }

            /* 3 คอลัมน์: คง 3 (sale phases) */
            .sm\\:grid-cols-3 { grid-template-columns: repeat(3, 1fr) !important; }

            /* 5 คอลัมน์: ลดเป็น 5 คง (governance stats) */
            .grid-cols-2.sm\\:grid-cols-5 { grid-template-columns: repeat(5, 1fr) !important; }

            /* Tokenomics 2 คอลัมน์: คงไว้ */
            .lg\\:grid-cols-2 { grid-template-columns: repeat(2, 1fr) !important; }

            /* Tech stack 3 คอลัมน์: ลดเป็น 2 */
            .sm\\:grid-cols-2.lg\\:grid-cols-3 { grid-template-columns: repeat(2, 1fr) !important; }

            /* 4 คอลัมน์ ecosystem map: ลดเป็น 4 คง */
            .grid-cols-2.sm\\:grid-cols-4 { grid-template-columns: repeat(4, 1fr) !important; }

            /* ===== SVG Donut Chart ===== */
            svg { max-width: 100% !important; }
            svg path, svg circle, svg rect, svg text {
                print-color-adjust: exact !important;
                -webkit-print-color-adjust: exact !important;
            }
            /* ลดขนาด donut chart สำหรับ print */
            .w-64.h-64 { width: 180px !important; height: 180px !important; }

            /* ===== สี ===== */
            [class*="text-trading-green"] { color: #22c55e !important; }
            [class*="text-trading-red"] { color: #ef4444 !important; }
            [class*="text-primary-400"] { color: #60a5fa !important; }
            [class*="text-primary-300"] { color: #93bbfd !important; }
            [class*="text-accent-"] { color: #a78bfa !important; }
            [class*="text-yellow-400"] { color: #fbbf24 !important; }
            [class*="text-cyan-400"] { color: #22d3ee !important; }
            [class*="text-white"] { color: #f1f5f9 !important; }
            [class*="text-warm-400"] { color: #fb923c !important; }
            [class*="text-purple-400"] { color: #a78bfa !important; }
            [class*="text-blue-400"] { color: #60a5fa !important; }
            [class*="text-green-400"] { color: #4ade80 !important; }
            [class*="text-red-400"] { color: #f87171 !important; }

            /* ===== Responsive: แสดงทุกอย่าง ===== */
            .hidden { display: revert !important; }
            .hidden.xl\\:block { display: none !important; } /* ซ่อน TOC sidebar */
            .hidden.print\\:block { display: block !important; }
            [class*="print:hidden"] { display: none !important; }

            /* Lists */
            ul, ol { padding-left: 16px !important; }
            li { margin-bottom: 2px !important; }

            /* ===== Roadmap layout — compact ===== */
            .space-y-4 > div { margin-bottom: 8px !important; }
            .flex.gap-4 { gap: 8px !important; }
            .w-28 { width: 70px !important; flex-shrink: 0 !important; }
            .pb-4 { padding-bottom: 6px !important; }

            /* ===== Page footer ===== */
            @page {
                @bottom-center {
                    content: "TPIX Chain Whitepaper v2.0  •  ${tw('ui.printPage')} " counter(page);
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
            document.title = tw('pageTitle');
            const s = document.getElementById('print-style');
            if (s) s.remove();
        }, 1000);
    }, 300);
}


// Tokenomics Donut Chart — ข้อมูล allocation (ตรงกับ TPIX-Coin NodeRegistryV2)
// ชื่อหมวดแปลผ่าน page.whitepaper.allocation.itemN
const ALLOCATION = [
    { pct: 25, color: '#3B82F6', amount: '1.75B' },
    { pct: 20, color: '#8B5CF6', amount: '1.4B' },
    { pct: 20, color: '#10B981', amount: '1.4B' },
    { pct: 15, color: '#06B6D4', amount: '1.05B' },
    { pct: 10, color: '#F59E0B', amount: '700M' },
    { pct: 10, color: '#EF4444', amount: '700M' },
];
const tokenAllocation = computed(() => ALLOCATION.map((item, i) => ({
    ...item,
    id: i + 1,
    label: tw(`allocation.item${i + 1}`),
})));

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

// สร้าง donut segments (รูปทรงไม่ขึ้นกับภาษา)
const donutSegments = computed(() => {
    let angle = 0;
    return ALLOCATION.map((item, i) => {
        const start = angle;
        const sweep = (item.pct / 100) * 360;
        angle += sweep;
        return { ...item, id: i + 1, path: donutPath(start, start + sweep - 0.5) };
    });
});

// ==========================================
// ข้อมูลที่ไม่ต้องแปล (ตัวเลข/ชื่อเฉพาะ) — ข้อความแปลอยู่ใน i18n page.whitepaper.*
// ==========================================
const USE_CASES = [
    { key: 'dex', icon: '🏦', features: 4 },
    { key: 'foodPassport', icon: '🍲', features: 5 },
    { key: 'smartFarm', icon: '🌾', features: 5 },
    { key: 'delivery', icon: '🚚', features: 5 },
    { key: 'aiBots', icon: '🤖', features: 5 },
    { key: 'hotel', icon: '🏨', features: 4 },
    { key: 'ecommerce', icon: '🛒', features: 4 },
    { key: 'tokenFactory', icon: '🏭', features: 4 },
    { key: 'carbon', icon: '🌱', features: 4 },
    { key: 'aiAutonomous', icon: '🧠', features: 4 },
];

const DEX_CONTRACTS = ['TPIXDEXFactory', 'TPIXDEXRouter02', 'TPIXDEXPair', 'WTPIX'];

// risk = คีย์ภายใน (low/medium/high) — ป้ายแปลผ่าน riskLevel.*
const STRATEGIES = [
    { name: 'Grid Trading', code: 'grid', risk: 'low', tier: 'Free' },
    { name: 'Smart DCA', code: 'dca', risk: 'low', tier: 'Free' },
    { name: 'Momentum Trend', code: 'momentum', risk: 'medium', tier: 'Basic' },
    { name: 'RSI Mean Reversion', code: 'mean_reversion', risk: 'medium', tier: 'Pro' },
    { name: 'Volatility Breakout', code: 'breakout', risk: 'high', tier: 'Pro' },
    { name: 'Micro Scalper', code: 'scalping', risk: 'high', tier: 'Pro' },
    { name: 'Spread Arbitrage', code: 'arbitrage', risk: 'medium', tier: 'VIP' },
    { name: 'TPIX AI Signal', code: 'ai_signal', risk: 'medium', tier: 'VIP' },
];

// สีป้ายความเสี่ยงของกลยุทธ์ AI TRADE
const riskClass = (risk) => ({
    low: 'bg-green-500/15 text-green-300',
    medium: 'bg-yellow-500/15 text-yellow-300',
    high: 'bg-red-500/15 text-red-300',
}[risk] ?? 'bg-white/10 text-gray-300');

const ENSEMBLE_WEIGHTS = ['30%', '25%', '25%', '20%'];

const SALE_PHASES = [
    { phase: 'Private Sale', price: '$0.05', alloc: '100M TPIX', tge: '10%', color: 'text-purple-400' },
    { phase: 'Pre-Sale', price: '$0.08', alloc: '200M TPIX', tge: '15%', color: 'text-blue-400' },
    { phase: 'Public Sale', price: '$0.10', alloc: '400M TPIX', tge: '25%', color: 'text-green-400' },
];

const NODE_TIERS = [
    { tier: 'Validator Node', stake: '10,000,000 TPIX', apy: '15-20%', lockDays: 180, maxNodes: '21', rewardPct: 20, hardware: '16 CPU, 32GB RAM, 1TB SSD' },
    { tier: 'Guardian Node', stake: '1,000,000 TPIX', apy: '10-12%', lockDays: 90, maxNodes: '100', rewardPct: 35, hardware: '8 CPU, 16GB RAM, 500GB SSD' },
    { tier: 'Sentinel Node', stake: '100,000 TPIX', apy: '7-9%', lockDays: 30, maxNodes: '500', rewardPct: 30, hardware: '4 CPU, 8GB RAM, 200GB SSD' },
    { tier: 'Light Node', stake: '10,000 TPIX', apy: '4-6%', lockDays: 7, maxNodes: null, rewardPct: 15, hardware: '2 CPU, 4GB RAM, 100GB SSD' },
];

const EMISSION = [
    { n: 1, range: '2025-2026', amount: '600,000,000 TPIX', perBlock: '~38.3 TPIX', pct: '42.9%' },
    { n: 2, range: '2026-2027', amount: '500,000,000 TPIX', perBlock: '~31.9 TPIX', pct: '35.7%' },
    { n: 3, range: '2027-2028', amount: '300,000,000 TPIX', perBlock: '~19.1 TPIX', pct: '21.4%' },
];

const INTEGRATIONS = [
    { key: 'thaiprompt', name: 'Thaiprompt Affiliate' },
    { key: 'foodPassport', name: 'FoodPassport' },
    { key: 'delivery', name: 'Delivery Platform' },
    { key: 'smartFarm', name: 'IoT Smart Farm' },
];

// status เป็นคีย์ภายใน ใช้เลือกสี/ไอคอน
const ROADMAP = [
    { status: 'done', items: 4 }, { status: 'done', items: 4 }, { status: 'done', items: 4 },
    { status: 'done', items: 4 }, { status: 'done', items: 4 }, { status: 'done', items: 4 },
    { status: 'current', items: 5 }, { status: 'planned', items: 5 }, { status: 'planned', items: 5 },
    { status: 'planned', items: 5 }, { status: 'planned', items: 5 },
];

const TECH_STACK = {
    blockchain: ['Polygon Edge', 'IBFT Consensus', 'EVM', 'LevelDB'],
    smartContracts: ['Solidity ^0.8.20', 'Hardhat', 'OpenZeppelin', 'ethers.js'],
    backend: ['Laravel 11', 'PHP 8.2+', 'MySQL 8.0+', 'Redis'],
    frontend: ['Vue.js 3', 'Inertia.js', 'TailwindCSS', 'Chart.js'],
    infra: ['Docker', 'Blockscout', 'Prometheus + Grafana', 'Automated CI/CD'],
};

// ==========================================
// เนื้อหาตามภาษาปัจจุบัน — computed เพื่อให้สลับภาษาแล้วเปลี่ยนทันที
// ==========================================
const c = computed(() => ({
    pageTitle: tw('pageTitle'),
    subtitle: tw('subtitle'),
    version: tw('version'),
    downloadPdf: tw('downloadPdf'),
    toc: sections.value.map(s => s.title),
    execSummary: {
        p1: tw('execSummary.p1'),
        p2: tw('execSummary.p2'),
        p3: tw('execSummary.p3'),
        stats: [
            { key: 'supply', value: '7B' },
            { key: 'fee', value: '0 Gas' },
            { key: 'blockTime', value: tw('execSummary.statValues.blockTime') },
            { key: 'tps', value: '~1,500' },
            { key: 'consensus', value: 'IBFT' },
            { key: 'finality', value: tw('execSummary.statValues.finality') },
        ].map(s => ({ ...s, label: tw(`execSummary.stats.${s.key}`) })),
    },
    problems: seq(4).map(i => ({ id: i, title: tw(`problems.item${i}.title`), desc: tw(`problems.item${i}.desc`) })),
    solutions: seq(4).map(i => ({ id: i, title: tw(`solutions.item${i}.title`), desc: tw(`solutions.item${i}.desc`) })),
    chainSpecs: [
        ['name', 'TPIX Chain'],
        ['mainnet', '4289'],
        ['testnet', '4290'],
        ['consensus', 'IBFT (Istanbul Byzantine Fault Tolerant)'],
        ['blockTime', null],
        ['finality', null],
        ['gas', null],
        ['tps', null],
        ['vm', null],
        ['coin', null],
        ['supply', null],
        ['validators', null],
        ['rpc', 'https://rpc1.tpix.online'],
        ['explorer', 'https://explorer.tpix.online'],
    ].map(([key, value]) => [tw(`chainSpecs.labels.${key}`), value ?? tw(`chainSpecs.values.${key}`)]),
    useCases: USE_CASES.map(u => ({
        key: u.key,
        icon: u.icon,
        title: tw(`useCases.${u.key}.title`),
        desc: tw(`useCases.${u.key}.desc`),
        features: list(`useCases.${u.key}.features`, u.features),
    })),
    dex: {
        desc: tw('dex.desc'),
        contracts: DEX_CONTRACTS.map(name => [name, tw(`dex.contracts.${name}`)]),
    },
    aiTrade: {
        lead: tw('aiTrade.lead'),
        barriers: seq(2).map(i => ({ id: i, title: tw(`aiTrade.barriers.item${i}.title`), desc: tw(`aiTrade.barriers.item${i}.desc`) })),
        answer: tw('aiTrade.answer'),
        principle: tw('aiTrade.principle'),
        strategies: STRATEGIES.map(s => ({
            ...s,
            desc: tw(`aiTrade.strategies.${s.code}`),
            riskLabel: tw(`riskLevel.${s.risk}`),
            tierLabel: s.tier === 'Free' ? tw('tierFree') : s.tier,
        })),
        ensemble: ENSEMBLE_WEIGHTS.map((weight, i) => ({
            id: i + 1,
            weight,
            view: tw(`aiTrade.ensemble.item${i + 1}.view`),
            q: tw(`aiTrade.ensemble.item${i + 1}.q`),
        })),
        ensembleNote: tw('aiTrade.ensembleNote'),
        risk: tw('aiTrade.risk'),
        custody: seq(2).map(i => ({
            id: i,
            mode: tw(`aiTrade.custody.item${i}.mode`),
            does: tw(`aiTrade.custody.item${i}.does`),
            signs: tw(`aiTrade.custody.item${i}.signs`),
        })),
        custodyNote: tw('aiTrade.custodyNote'),
        constraints: list('aiTrade.constraints', 5),
        scope: tw('aiTrade.scope'),
        closing: tw('aiTrade.closing'),
    },
    salePhases: SALE_PHASES.map((p, i) => ({ ...p, vesting: tw(`salePhases.item${i + 1}.vesting`) })),
    masternodeTiers: NODE_TIERS.map(n => ({
        ...n,
        lock: tw('units.days', { n: n.lockDays }),
        maxNodes: n.maxNodes ?? tw('units.unlimited'),
        reward: tw('units.rewardPool', { pct: n.rewardPct }),
    })),
    masternodeEmission: EMISSION.map(e => ({ ...e, year: tw('units.yearLabel', { n: e.n, range: e.range }) })),
    masternodeDesc: tw('masternode.desc'),
    masternodeRewardSplit: tw('masternode.rewardSplit'),
    masternodeSlashing: tw('masternode.slashing'),
    masternodeAfterY5: tw('masternode.afterYear3'),
    integrations: INTEGRATIONS.map(it => ({
        ...it,
        desc: tw(`integrations.${it.key}.desc`),
        items: list(`integrations.${it.key}.items`, 4),
    })),
    roadmap: ROADMAP.map((r, i) => ({
        id: i + 1,
        status: r.status,
        q: tw(`roadmap.item${i + 1}.q`),
        title: tw(`roadmap.item${i + 1}.title`),
        items: list(`roadmap.item${i + 1}.items`, r.items),
    })),
    techStack: Object.entries(TECH_STACK).map(([category, names]) => ({
        category,
        title: tw(`techCategory.${category}`),
        items: names.map((name, i) => [name, tw(`techStack.${category}.item${i + 1}`)]),
    })),
    teamDesc: tw('teamDesc'),
    teamHighlights: list('teamHighlights', 4),
    securityItems: seq(6).map(i => ({ id: i, title: tw(`securityItems.item${i}.title`), desc: tw(`securityItems.item${i}.desc`) })),
}));
</script>


<template>
    <Head :title="c.pageTitle" />

    <AppLayout :hide-sidebar="true">
        <!-- Hero — หน้าปก (print: จะเป็นหน้าแรกของ PDF) -->
        <section class="print-cover relative py-8 sm:py-16 overflow-hidden">
            <div class="absolute inset-0 pointer-events-none print:hidden">
                <div class="absolute top-0 left-1/4 w-64 h-64 sm:w-96 sm:h-96 rounded-full bg-primary-500/10 blur-[120px]" />
                <div class="absolute bottom-0 right-1/3 w-56 h-56 sm:w-80 sm:h-80 rounded-full bg-accent-500/10 blur-[100px]" />
            </div>

            <div class="relative max-w-4xl mx-auto sm:px-4 text-center">
                <BrandLogo variant="mark" alt="TPIX" class="w-20 h-20 sm:w-28 sm:h-28 mx-auto mb-5 sm:mb-6 rounded-full shadow-lg shadow-primary-500/20" />
                <h1 class="text-3xl sm:text-5xl font-bold text-white mb-3 sm:mb-2 leading-tight">{{ c.pageTitle }}</h1>
                <p class="text-base sm:text-lg text-primary-400 font-medium mb-2 sm:mb-1 max-w-2xl mx-auto">{{ c.subtitle }}</p>
                <p class="text-sm text-gray-500 mb-6">{{ c.version }}</p>

                <!-- Print-only: ข้อมูลเอกสารเพิ่มเติม -->
                <div class="hidden print:block mt-12 text-sm text-gray-500 space-y-1">
                    <p>{{ tw('ui.developedBy') }}</p>
                    <p>https://tpix.trade</p>
                    <p class="mt-4 text-xs text-gray-400">{{ tw('ui.confidential') }}</p>
                </div>

                <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-center gap-3 max-w-xs sm:max-w-none mx-auto print:hidden">
                    <button @click="downloadPdf" class="btn-primary px-8 py-3 inline-flex items-center justify-center gap-2 font-semibold">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        {{ c.downloadPdf }}
                    </button>
                    <button @click="toggleLang" class="btn-secondary px-6 py-3 font-semibold">
                        {{ tw('ui.switchLang') }}
                    </button>
                </div>
            </div>
        </section>

        <!-- สารบัญแบบพับได้ — ติดใต้ NavBar บนจอที่เล็กกว่า xl -->
        <div class="xl:hidden sticky top-16 z-30 -mx-4 lg:-mx-6 mb-6 print:hidden">
            <div class="glass-dark rounded-none border-b border-white/10 shadow-lg shadow-black/30">
                <button
                    type="button"
                    class="w-full flex items-center gap-3 px-4 py-3 text-left"
                    :aria-expanded="tocOpen"
                    @click="tocOpen = !tocOpen"
                >
                    <svg class="w-4 h-4 flex-shrink-0 text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h10" /></svg>
                    <span class="hidden sm:inline text-xs font-bold uppercase tracking-wider text-gray-500 flex-shrink-0">{{ tw('ui.contents') }}</span>
                    <span class="flex-1 min-w-0 truncate text-sm font-medium text-white">{{ activeTitle }}</span>
                    <svg class="w-4 h-4 flex-shrink-0 text-gray-400 transition-transform" :class="{ 'rotate-180': tocOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
                </button>
                <div class="h-0.5 bg-white/5">
                    <div class="h-full bg-gradient-to-r from-primary-500 to-accent-500 transition-[width] duration-150" :style="{ width: readProgress + '%' }" />
                </div>
                <nav v-show="tocOpen" class="max-h-[60vh] overflow-y-auto overscroll-contain p-2 grid sm:grid-cols-2 gap-0.5 border-t border-white/5">
                    <button
                        v-for="s in sections" :key="s.id"
                        type="button"
                        class="w-full text-left px-3 py-2.5 rounded-lg text-sm transition-colors"
                        :class="activeSection === s.id
                            ? 'text-primary-400 bg-primary-500/10 font-medium'
                            : 'text-gray-400 hover:text-white hover:bg-white/5'"
                        @click="scrollTo(s.id)"
                    >{{ s.title }}</button>
                </nav>
            </div>
        </div>

        <!-- Content: TOC + Sections -->
        <div class="max-w-7xl mx-auto sm:px-2 lg:px-6 pb-20">
            <div class="flex gap-8">
                <!-- Sidebar: Table of Contents (sticky) -->
                <aside class="hidden xl:block w-64 flex-shrink-0">
                    <div class="sticky top-24">
                        <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">
                            {{ tw('ui.contents') }}
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
                        <h2 class="wp-heading">{{ c.toc[0] }}</h2>
                        <SectionVideo file="ep01-foundation.mp4" :part="tw('video.ep01')" :start="19.3" :duration="195" :lang="lang" />
                        <p class="wp-text">{{ c.execSummary.p1 }}</p>
                        <p class="wp-text">{{ c.execSummary.p2 }}</p>
                        <p class="wp-text font-medium text-white/90">{{ c.execSummary.p3 }}</p>

                        <!-- สถิติสำคัญ — Key Stats -->
                        <div class="wp-highlight">
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-center">
                                <div v-for="s in c.execSummary.stats" :key="s.label">
                                    <p class="text-2xl font-bold text-primary-400">{{ s.value }}</p>
                                    <p class="text-xs text-gray-400 mt-1">{{ s.label }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 2. Problem & Solution -->
                    <section id="problem-solution" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[1] }}</h2>
                        <SectionVideo file="ep01-foundation.mp4" :part="tw('video.ep01')" :start="98.8" :duration="195" :lang="lang" />

                        <h3 class="wp-subheading text-trading-red">{{ tw('ui.problemsHeading') }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4 mb-8">
                            <div v-for="p in c.problems" :key="p.title" class="p-4 rounded-xl bg-red-500/5 border border-red-500/10">
                                <h4 class="font-semibold text-white mb-2">{{ p.title }}</h4>
                                <p class="text-sm text-gray-400">{{ p.desc }}</p>
                            </div>
                        </div>

                        <h3 class="wp-subheading text-trading-green">{{ tw('ui.solutionsHeading') }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="s in c.solutions" :key="s.title" class="p-4 rounded-xl bg-green-500/5 border border-green-500/10">
                                <h4 class="font-semibold text-white mb-2">{{ s.title }}</h4>
                                <p class="text-sm text-gray-400">{{ s.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 3. TPIX Chain Architecture -->
                    <section id="tpix-chain" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[2] }}</h2>
                        <SectionVideo file="ep02-the-chain.mp4" :part="tw('video.ep02')" :start="9.1" :duration="230" :lang="lang" />

                        <!-- Architecture Diagram — แผนภูมิสถาปัตยกรรม -->
                        <div class="wp-highlight mb-6">
                            <h4 class="text-center text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                {{ tw('ui.networkArchitecture') }}
                            </h4>
                            <div class="flex flex-col items-center gap-3">
                                <!-- DApps Layer -->
                                <div class="flex flex-wrap justify-center gap-2">
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">DEX</span>
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">Master Node</span>
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">Token Factory</span>
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">FoodPassport</span>
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">{{ tw('ui.iotFarm') }}</span>
                                    <span class="px-3 py-1.5 rounded-lg bg-primary-500/20 text-primary-300 text-xs font-medium">NFT</span>
                                </div>
                                <div class="text-gray-600">▼</div>
                                <!-- Smart Contract Layer -->
                                <div class="w-full max-w-lg p-3 rounded-xl bg-accent-500/10 border border-accent-500/20 text-center">
                                    <p class="text-xs font-bold text-accent-400 mb-1">{{ tw('ui.smartContractLayer') }}</p>
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
                                    <p class="text-xs font-bold text-green-400 mb-1">{{ tw('ui.consensusLayer') }}</p>
                                    <div class="flex justify-center gap-3">
                                        <span v-for="i in 4" :key="i" class="w-8 h-8 rounded-full bg-green-500/20 flex items-center justify-center text-green-400 text-xs font-bold">V{{ i }}</span>
                                    </div>
                                </div>
                                <div class="text-gray-600">▼</div>
                                <!-- Storage Layer -->
                                <div class="w-full max-w-lg p-3 rounded-xl bg-warm-500/10 border border-warm-500/20 text-center">
                                    <p class="text-xs font-bold text-warm-400">{{ tw('ui.storageLayer') }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Chain Specifications Table -->
                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.parameter') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.value') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(row, i) in c.chainSpecs" :key="i" :class="i < c.chainSpecs.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3 text-gray-300">{{ row[0] }}</td>
                                        <td class="py-2 px-3 text-white font-medium">{{ row[1] }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 4. Tokenomics — โทเคโนมิกส์ -->
                    <section id="tokenomics" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[3] }}</h2>
                        <SectionVideo file="ep03-economics.mp4" :part="tw('video.ep03')" :start="21.4" :duration="195" :lang="lang" />

                        <div class="grid lg:grid-cols-2 gap-8 items-start">
                            <!-- Donut Chart — แผนภูมิโดนัท -->
                            <div class="flex flex-col items-center">
                                <svg viewBox="0 0 200 200" class="w-56 h-56 sm:w-64 sm:h-64">
                                    <path v-for="seg in donutSegments" :key="seg.id" :d="seg.path" :fill="seg.color" class="opacity-90 hover:opacity-100 transition-opacity cursor-pointer" />
                                    <text x="100" y="95" text-anchor="middle" fill="white" font-size="14" font-weight="bold">7B</text>
                                    <text x="100" y="112" text-anchor="middle" fill="#9CA3AF" font-size="8">TPIX</text>
                                </svg>
                                <!-- Legend -->
                                <div class="grid grid-cols-1 gap-2 mt-4 w-full max-w-xs">
                                    <div v-for="item in tokenAllocation" :key="item.id" class="flex items-center gap-2">
                                        <span class="w-3 h-3 rounded-full flex-shrink-0" :style="{ backgroundColor: item.color }"></span>
                                        <span class="text-sm text-gray-300 flex-1">{{ item.label }}</span>
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
                                            <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.allocation') }}</th>
                                            <th class="text-right py-2 px-3 text-gray-400">%</th>
                                            <th class="text-right py-2 px-3 text-gray-400">TPIX</th>
                                        </tr></thead>
                                        <tbody>
                                            <tr v-for="(item, i) in tokenAllocation" :key="item.id" :class="i < tokenAllocation.length - 1 ? 'border-b border-white/5' : ''">
                                                <td class="py-2 px-3 text-white">
                                                    <span class="inline-flex items-center gap-2">
                                                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" :style="{ backgroundColor: item.color }"></span>
                                                        {{ item.label }}
                                                    </span>
                                                </td>
                                                <td class="py-2 px-3 text-right font-medium" :style="{ color: item.color }">{{ item.pct }}%</td>
                                                <td class="py-2 px-3 text-right text-gray-300">{{ item.amount }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-4 p-4 rounded-xl bg-white/5 border border-white/10">
                                    <p class="text-sm text-gray-400">
                                        {{ tw('ui.fixedSupplyNote') }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 5. Use Cases — กรณีการใช้งาน -->
                    <section id="use-cases" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[4] }}</h2>
                        <SectionVideo file="ep08-real-world.mp4" :part="tw('video.ep08')" :start="19.1" :duration="272" :lang="lang" />
                        <p class="wp-text">
                            {{ tw('ui.useCasesIntro') }}
                        </p>

                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="uc in c.useCases" :key="uc.title" class="p-4 sm:p-5 rounded-xl bg-white/5 border border-white/10 hover:border-primary-500/30 transition-colors">
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
                        <h2 class="wp-heading">{{ c.toc[5] }}</h2>
                        <SectionVideo file="ep05-the-trading-layer.mp4" :part="tw('video.ep05')" :start="10.6" :duration="263" :lang="lang" />
                        <p class="wp-text">{{ c.dex.desc }}</p>

                        <!-- DEX Flow Diagram -->
                        <div class="wp-highlight mb-6">
                            <h4 class="text-center text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                {{ tw('ui.ammFlow') }}
                            </h4>
                            <div class="flex flex-wrap items-center justify-center gap-2 text-xs">
                                <span class="px-3 py-2 rounded-lg bg-blue-500/20 text-blue-300 font-medium">{{ tw('ui.user') }}</span>
                                <span class="text-gray-500">→</span>
                                <span class="px-3 py-2 rounded-lg bg-purple-500/20 text-purple-300 font-medium">Router02</span>
                                <span class="text-gray-500">→</span>
                                <span class="px-3 py-2 rounded-lg bg-green-500/20 text-green-300 font-medium">Pair (x·y=k)</span>
                                <span class="text-gray-500">→</span>
                                <span class="px-3 py-2 rounded-lg bg-yellow-500/20 text-yellow-300 font-medium">{{ tw('ui.feeSplit') }}</span>
                                <span class="text-gray-500">→</span>
                                <span class="px-3 py-2 rounded-lg bg-blue-500/20 text-blue-300 font-medium">{{ tw('ui.tokensOut') }}</span>
                            </div>
                        </div>

                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.contract') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.description') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(ct, i) in c.dex.contracts" :key="ct[0]" :class="i < c.dex.contracts.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3 text-primary-400 font-mono font-medium">{{ ct[0] }}</td>
                                        <td class="py-2 px-3 text-gray-300">{{ ct[1] }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>


                    <!-- 7. AI TRADE -->
                    <section id="ai-trade" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[6] }}</h2>
                        <SectionVideo file="ep06-ai-trade.mp4" :part="tw('video.ep06')" :start="11.4" :duration="457" :lang="lang" />
                        <p class="wp-text">{{ c.aiTrade.lead }}</p>

                        <!-- ทำไมมันเกิดบน CEX ไม่ได้ -->
                        <div class="grid md:grid-cols-2 gap-4 mb-6">
                            <div v-for="b in c.aiTrade.barriers" :key="b.title"
                                 class="p-4 rounded-xl border border-red-500/25 bg-red-500/5">
                                <h4 class="font-bold text-red-300 mb-2">{{ b.title }}</h4>
                                <p class="text-sm text-gray-300 leading-relaxed">{{ b.desc }}</p>
                            </div>
                        </div>

                        <div class="wp-highlight mb-6">
                            <h4 class="text-sm font-bold text-green-300 mb-2 uppercase tracking-wider">
                                {{ tw('ui.whyDex') }}
                            </h4>
                            <p class="text-sm text-gray-300 leading-relaxed">{{ c.aiTrade.answer }}</p>
                        </div>

                        <h3 class="wp-subheading">{{ tw('ui.explainable') }}</h3>
                        <p class="wp-text">{{ c.aiTrade.principle }}</p>

                        <h3 class="wp-subheading">{{ tw('ui.eightStrategies') }}</h3>
                        <!-- มือถือ: การ์ดแทนตาราง 4 คอลัมน์ -->
                        <div class="md:hidden print:hidden grid gap-3 mb-6">
                            <div v-for="s in c.aiTrade.strategies" :key="s.code" class="p-4 rounded-xl bg-white/5 border border-white/10">
                                <div class="flex items-start justify-between gap-3 mb-2">
                                    <div class="min-w-0">
                                        <p class="text-white font-semibold">{{ s.name }}</p>
                                        <p class="text-xs text-primary-400 font-mono">{{ s.code }}</p>
                                    </div>
                                    <div class="flex flex-shrink-0 items-center gap-1.5 text-xs font-medium">
                                        <span class="px-2 py-0.5 rounded-full" :class="riskClass(s.risk)">{{ s.riskLabel }}</span>
                                        <span class="px-2 py-0.5 rounded-full bg-yellow-500/10 text-yellow-400">{{ s.tierLabel }}</span>
                                    </div>
                                </div>
                                <p class="text-sm text-gray-300 leading-relaxed">{{ s.desc }}</p>
                            </div>
                        </div>
                        <div class="wp-table hidden md:block mb-6">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.strategy') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.behaviour') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.risk') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.tier') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(s, i) in c.aiTrade.strategies" :key="s.code"
                                        :class="i < c.aiTrade.strategies.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3">
                                            <span class="text-white font-medium">{{ s.name }}</span>
                                            <span class="block text-xs text-primary-400 font-mono">{{ s.code }}</span>
                                        </td>
                                        <td class="py-2 px-3 text-gray-300">{{ s.desc }}</td>
                                        <td class="py-2 px-3 text-gray-400">{{ s.riskLabel }}</td>
                                        <td class="py-2 px-3 text-yellow-400 font-medium">{{ s.tierLabel }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <h3 class="wp-subheading">{{ tw('ui.ensembleHeading') }}</h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                            <div v-for="e in c.aiTrade.ensemble" :key="e.view"
                                 class="p-4 rounded-xl border border-primary-500/25 bg-primary-500/5">
                                <div class="text-2xl font-bold text-primary-300">{{ e.weight }}</div>
                                <div class="text-sm font-semibold text-white mt-1">{{ e.view }}</div>
                                <div class="text-xs text-gray-400 mt-2 leading-relaxed">{{ e.q }}</div>
                            </div>
                        </div>
                        <p class="wp-text">{{ c.aiTrade.ensembleNote }}</p>

                        <h3 class="wp-subheading">{{ tw('ui.riskGate') }}</h3>
                        <p class="wp-text">{{ c.aiTrade.risk }}</p>

                        <h3 class="wp-subheading">{{ tw('ui.custodyHeading') }}</h3>
                        <div class="wp-table wp-table-wide mb-4">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.mode') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.whatSystemDoes') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.whoSigns') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(cu, i) in c.aiTrade.custody" :key="cu.mode"
                                        :class="i < c.aiTrade.custody.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3 text-white font-medium">{{ cu.mode }}</td>
                                        <td class="py-2 px-3 text-gray-300">{{ cu.does }}</td>
                                        <td class="py-2 px-3 text-green-400 font-medium">{{ cu.signs }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="wp-highlight mb-6">
                            <p class="text-sm text-gray-300 leading-relaxed">{{ c.aiTrade.custodyNote }}</p>
                        </div>

                        <h3 class="wp-subheading">{{ tw('ui.constraintsHeading') }}</h3>
                        <ul class="wp-list mb-6">
                            <li v-for="rule in c.aiTrade.constraints" :key="rule">{{ rule }}</li>
                        </ul>

                        <div class="p-4 rounded-xl border border-yellow-500/25 bg-yellow-500/5 mb-6">
                            <h4 class="text-sm font-bold text-yellow-300 mb-2 uppercase tracking-wider">
                                {{ tw('ui.scopeHeading') }}
                            </h4>
                            <p class="text-sm text-gray-300 leading-relaxed">{{ c.aiTrade.scope }}</p>
                        </div>

                        <p class="wp-text">{{ c.aiTrade.closing }}</p>
                    </section>

                    <!-- 8. Token Sale Details -->
                    <section id="token-sale" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[7] }}</h2>
                        <SectionVideo file="ep03-economics.mp4" :part="tw('video.ep03')" :start="92.8" :duration="195" :lang="lang" />
                        <p class="wp-text">
                            {{ tw('ui.saleIntro') }}
                        </p>

                        <div class="grid sm:grid-cols-3 gap-4 mb-6">
                            <div v-for="p in c.salePhases" :key="p.phase" class="p-5 rounded-xl bg-white/5 border border-white/10 text-center">
                                <h4 class="font-semibold text-white mb-2">{{ p.phase }}</h4>
                                <p class="text-3xl font-bold" :class="p.color">{{ p.price }}</p>
                                <p class="text-sm text-gray-400 mt-1">{{ p.alloc }}</p>
                                <div class="mt-3 pt-3 border-t border-white/10 text-xs text-gray-500">
                                    <p>{{ tw('ui.tge', { pct: p.tge }) }}</p>
                                    <p>{{ p.vesting }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 8. Master Node & Rewards -->
                    <section id="masternode" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[8] }}</h2>
                        <SectionVideo file="ep04-master-nodes.mp4" :part="tw('video.ep04')" :start="9.4" :duration="264" :lang="lang" />
                        <p class="wp-text">{{ c.masternodeDesc }}</p>

                        <!-- Master Node Tiers -->
                        <h3 class="text-lg font-semibold text-white mt-6 mb-3">{{ tw('ui.nodeTiers') }}</h3>
                        <!-- มือถือ: การ์ดแทนตาราง 7 คอลัมน์ -->
                        <div class="md:hidden print:hidden grid gap-3 my-4">
                            <div v-for="n in c.masternodeTiers" :key="n.tier" class="p-4 rounded-xl bg-white/5 border border-white/10">
                                <div class="flex items-baseline justify-between gap-3 mb-3 pb-2 border-b border-white/5">
                                    <span class="text-white font-semibold">{{ n.tier }}</span>
                                    <span class="text-trading-green font-bold">{{ n.apy }} <span class="text-xs font-normal text-gray-500">APY</span></span>
                                </div>
                                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                    <div><dt class="text-xs text-gray-500">{{ tw('ui.minStake') }}</dt><dd class="text-cyan-400 font-bold">{{ n.stake }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">{{ tw('ui.lock') }}</dt><dd class="text-gray-300">{{ n.lock }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">{{ tw('ui.maxNodes') }}</dt><dd class="text-gray-300">{{ n.maxNodes }}</dd></div>
                                    <div><dt class="text-xs text-gray-500">{{ tw('ui.rewardShare') }}</dt><dd class="text-purple-400">{{ n.reward }}</dd></div>
                                    <div class="col-span-2"><dt class="text-xs text-gray-500">{{ tw('ui.hardware') }}</dt><dd class="text-gray-400 text-xs">{{ n.hardware }}</dd></div>
                                </dl>
                            </div>
                        </div>
                        <div class="wp-table hidden md:block">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.tier') }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">{{ tw('ui.minStake') }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">APY</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.lock') }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">{{ tw('ui.maxNodes') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.rewardShare') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.hardware') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(n, i) in c.masternodeTiers" :key="n.tier" :class="i < c.masternodeTiers.length - 1 ? 'border-b border-white/5' : ''">
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
                            <h4 class="text-sm font-bold text-cyan-400 mb-2">{{ tw('ui.blockRewardDist') }}</h4>
                            <p class="text-sm text-gray-300">{{ c.masternodeRewardSplit }}</p>
                        </div>

                        <!-- Emission Schedule -->
                        <h3 class="text-lg font-semibold text-white mt-6 mb-3">{{ tw('ui.emissionSchedule') }}</h3>
                        <div class="wp-table wp-table-wide">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('ui.year') }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">{{ tw('ui.totalReward') }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">{{ tw('ui.perBlock') }}</th>
                                    <th class="text-right py-2 px-3 text-gray-400">{{ tw('ui.pctOfPool') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(e, i) in c.masternodeEmission" :key="e.year" :class="i < c.masternodeEmission.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3 text-white">{{ e.year }}</td>
                                        <td class="py-2 px-3 text-right text-trading-green font-bold">{{ e.amount }}</td>
                                        <td class="py-2 px-3 text-right text-cyan-400">{{ e.perBlock }}</td>
                                        <td class="py-2 px-3 text-right text-gray-400">{{ e.pct }}</td>
                                    </tr>
                                    <tr class="border-t border-white/20 font-bold">
                                        <td class="py-2 px-3 text-white">{{ tw('ui.total') }}</td>
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
                                <h4 class="text-sm font-bold text-red-400 mb-2">{{ tw('ui.slashingRules') }}</h4>
                                <p class="text-sm text-gray-300">{{ c.masternodeSlashing }}</p>
                            </div>
                            <div class="p-4 rounded-xl bg-green-500/10 border border-green-500/20">
                                <h4 class="text-sm font-bold text-green-400 mb-2">{{ tw('ui.afterYear3') }}</h4>
                                <p class="text-sm text-gray-300">{{ c.masternodeAfterY5 }}</p>
                            </div>
                        </div>

                        <!-- Download Node -->
                        <div class="mt-6 p-4 rounded-xl bg-gradient-to-r from-purple-500/10 to-pink-500/10 border border-purple-500/20 text-center">
                            <h4 class="text-lg font-bold text-white mb-2">{{ tw('ui.runNode') }}</h4>
                            <p class="text-sm text-gray-300 mb-3">{{ tw('ui.runNodeDesc') }}</p>
                            <div class="flex justify-center gap-3 flex-wrap">
                                <a href="/download" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-purple-500/20 border border-purple-500/30 text-purple-300 hover:bg-purple-500/30 transition text-sm font-semibold">
                                    {{ tw('ui.downloadNode') }}
                                </a>
                                <a href="/masternode/guide" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10 transition text-sm">
                                    {{ tw('ui.setupGuide') }}
                                </a>
                                <!--
                                    ข้อยกเว้นเดียวที่เปิดเผยที่อยู่บน GitHub ได้ — เฉพาะสัญญาอัจฉริยะ
                                    (เจ้าของกำหนด 2026-09-06) โครงการเชนต้องให้คนตรวจสัญญาเองได้
                                    ส่วนซอร์สของเว็บ/แอป/โปรแกรมโหนด ห้ามชี้ทางไปเด็ดขาด
                                -->
                                <a href="https://github.com/xjanova/TPIX-Coin/tree/main/contracts/src" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/5 border border-white/10 text-gray-300 hover:bg-white/10 transition text-sm">
                                    {{ tw('ui.smartContracts') }}
                                </a>
                            </div>
                        </div>
                    </section>

                    <!-- 9. Living Identity — Seedless Recovery -->
                    <section id="living-identity" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[9] }}</h2>
                        <SectionVideo file="ep07-living-identity.mp4" :part="tw('video.ep07')" :start="8.1" :duration="273" :lang="lang" />
                        <div class="glass-dark p-4 sm:p-6 rounded-2xl border border-accent-500/20 mb-6">
                            <p class="text-lg font-bold text-accent-400 mb-2">{{ tw('identity.headline') }}</p>
                            <p class="text-gray-300 text-sm">{{ tw('identity.tagline') }}</p>
                        </div>
                        <p class="wp-text">{{ tw('identity.desc') }}</p>

                        <div class="wp-table wp-table-wide">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('identity.step') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('identity.action') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('identity.onChainData') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">{{ tw('identity.steps.item1.name') }}</td><td class="py-2 px-3 text-gray-300">{{ tw('identity.steps.item1.action') }}</td><td class="py-2 px-3 text-gray-400">{{ tw('identity.steps.item1.data') }}</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">{{ tw('identity.steps.item2.name') }}</td><td class="py-2 px-3 text-gray-300">{{ tw('identity.steps.item2.action') }}</td><td class="py-2 px-3 text-gray-400">-</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">{{ tw('identity.steps.item3.name') }}</td><td class="py-2 px-3 text-gray-300">{{ tw('identity.steps.item3.action') }}</td><td class="py-2 px-3 text-gray-400">{{ tw('identity.steps.item3.data') }}</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">{{ tw('identity.steps.item4.name') }}</td><td class="py-2 px-3 text-gray-300">{{ tw('identity.steps.item4.action') }}</td><td class="py-2 px-3 text-gray-400">{{ tw('identity.steps.item4.data') }}</td></tr>
                                    <tr><td class="py-2 px-3 text-white">{{ tw('identity.steps.item5.name') }}</td><td class="py-2 px-3 text-gray-300">{{ tw('identity.steps.item5.action') }}</td><td class="py-2 px-3 text-gray-400">{{ tw('identity.steps.item5.data') }}</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6">
                            <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                                <span class="text-2xl mb-2 block">🔐</span>
                                <p class="text-sm font-medium text-white">{{ tw('identity.features.item1.title') }}</p>
                                <p class="text-xs text-gray-400 mt-1">{{ tw('identity.features.item1.desc') }}</p>
                            </div>
                            <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                                <span class="text-2xl mb-2 block">⏰</span>
                                <p class="text-sm font-medium text-white">{{ tw('identity.features.item2.title') }}</p>
                                <p class="text-xs text-gray-400 mt-1">{{ tw('identity.features.item2.desc') }}</p>
                            </div>
                            <div class="glass-dark p-4 rounded-xl border border-white/10 text-center">
                                <span class="text-2xl mb-2 block">🆓</span>
                                <p class="text-sm font-medium text-white">{{ tw('identity.features.item3.title') }}</p>
                                <p class="text-xs text-gray-400 mt-1">{{ tw('identity.features.item3.desc') }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 10. Validator Governance -->
                    <section id="governance" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[10] }}</h2>
                        <SectionVideo file="ep04-master-nodes.mp4" :part="tw('video.ep04')" :start="184.5" :duration="264" :lang="lang" />
                        <p class="wp-text">{{ tw('governance.desc') }}</p>

                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mt-4 mb-6">
                            <div class="glass-dark p-3 rounded-xl border border-white/10 text-center">
                                <p class="text-xs text-gray-400">{{ tw('governance.votingPeriod') }}</p>
                                <p class="text-lg font-bold text-white">{{ tw('governance.votingDays', { n: 7 }) }}</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10 text-center">
                                <p class="text-xs text-gray-400">{{ tw('governance.quorum') }}</p>
                                <p class="text-lg font-bold text-white">&gt;50%</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10 text-center">
                                <p class="text-xs text-gray-400">{{ tw('governance.approval') }}</p>
                                <p class="text-lg font-bold text-white">&gt;50%</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10 text-center">
                                <p class="text-xs text-gray-400">{{ tw('governance.timelock') }}</p>
                                <p class="text-lg font-bold text-white">{{ tw('governance.timelockValue') }}</p>
                            </div>
                            <div class="col-span-2 sm:col-span-1 glass-dark p-3 rounded-xl border border-white/10 text-center">
                                <p class="text-xs text-gray-400">KYC</p>
                                <p class="text-lg font-bold text-accent-400">PDPA</p>
                            </div>
                        </div>

                        <h3 class="text-lg font-semibold text-white mb-3">{{ tw('governance.proposalTypes') }}</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div class="glass-dark p-3 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white">AddValidator</p>
                                <p class="text-xs text-gray-400">{{ tw('governance.addValidator') }}</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white">RemoveValidator</p>
                                <p class="text-xs text-gray-400">{{ tw('governance.removeValidator') }}</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white">ChangeParameter</p>
                                <p class="text-xs text-gray-400">{{ tw('governance.changeParameter') }}</p>
                            </div>
                            <div class="glass-dark p-3 rounded-xl border border-white/10">
                                <p class="text-sm font-medium text-white">UpgradeContract</p>
                                <p class="text-xs text-gray-400">{{ tw('governance.upgradeContract') }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 11. Cross-Chain Bridge -->
                    <section id="bridge" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[11] }}</h2>
                        <SectionVideo file="ep05-the-trading-layer.mp4" :part="tw('video.ep05')" :start="202.5" :duration="263" :lang="lang" />
                        <p class="wp-text">{{ tw('bridge.desc') }}</p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4">
                            <div class="glass-dark p-4 rounded-xl border border-green-500/20">
                                <p class="text-sm font-bold text-green-400 mb-2">BSC → TPIX Chain</p>
                                <p class="text-xs text-gray-300">{{ tw('bridge.toTpix') }}</p>
                            </div>
                            <div class="glass-dark p-4 rounded-xl border border-blue-500/20">
                                <p class="text-sm font-bold text-blue-400 mb-2">TPIX Chain → BSC</p>
                                <p class="text-xs text-gray-300">{{ tw('bridge.toBsc') }}</p>
                            </div>
                        </div>

                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('bridge.feature') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tw('bridge.details') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">{{ tw('bridge.maxSupply') }}</td><td class="py-2 px-3 text-gray-300">{{ tw('bridge.maxSupplyValue') }}</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">{{ tw('bridge.standard') }}</td><td class="py-2 px-3 text-gray-300">{{ tw('bridge.standardValue') }}</td></tr>
                                    <tr class="border-b border-white/5"><td class="py-2 px-3 text-white">{{ tw('bridge.fee') }}</td><td class="py-2 px-3 text-gray-300">{{ tw('bridge.feeValue') }}</td></tr>
                                    <tr><td class="py-2 px-3 text-white">{{ tw('bridge.integrity') }}</td><td class="py-2 px-3 text-gray-300">{{ tw('bridge.integrityValue') }}</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 12. Ecosystem & Affiliate -->
                    <section id="ecosystem" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[12] }}</h2>
                        <SectionVideo file="ep08-real-world.mp4" :part="tw('video.ep08')" :start="19.1" :duration="272" :lang="lang" />
                        <p class="wp-text">
                            {{ tw('ecosystem.intro') }}
                        </p>

                        <!-- Ecosystem Map Diagram -->
                        <div class="wp-highlight mb-6">
                            <h4 class="text-center text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                {{ tw('ecosystem.map') }}
                            </h4>
                            <div class="flex flex-col items-center gap-2">
                                <div class="px-6 py-3 rounded-xl bg-primary-500/20 border border-primary-500/30 text-primary-300 font-bold text-sm">TPIX Chain (ID: 4289)</div>
                                <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 w-full max-w-2xl">
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
                                        <span class="block text-lg mb-1">🌾</span>{{ tw('ecosystem.nodes.iotFarm') }}
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🚚</span>{{ tw('ecosystem.nodes.delivery') }}
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🤖</span>{{ tw('ecosystem.nodes.aiBots') }}
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🏨</span>{{ tw('ecosystem.nodes.hotels') }}
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🛒</span>{{ tw('ecosystem.nodes.ecommerce') }}
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🌱</span>{{ tw('ecosystem.nodes.carbon') }}
                                    </div>
                                    <div class="p-2 rounded-lg bg-white/5 border border-white/10 text-center text-xs text-gray-400">
                                        <span class="block text-lg mb-1">🧠</span>{{ tw('ecosystem.nodes.aiEcosystem') }}
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h3 class="wp-subheading">{{ tw('ecosystem.affiliate') }}</h3>
                        <div class="grid grid-cols-3 gap-2 sm:gap-4 mb-6">
                            <div class="p-3 sm:p-4 rounded-xl bg-white/5 border border-white/10 text-center">
                                <p class="text-xl sm:text-2xl font-bold text-primary-400">5%</p>
                                <p class="text-xs sm:text-sm text-gray-400">{{ tw('ecosystem.referrerReward') }}</p>
                            </div>
                            <div class="p-3 sm:p-4 rounded-xl bg-white/5 border border-white/10 text-center">
                                <p class="text-xl sm:text-2xl font-bold text-accent-400">2%</p>
                                <p class="text-xs sm:text-sm text-gray-400">{{ tw('ecosystem.refereeBonus') }}</p>
                            </div>
                            <div class="p-3 sm:p-4 rounded-xl bg-white/5 border border-white/10 text-center">
                                <p class="text-xl sm:text-2xl font-bold text-warm-400">1,000</p>
                                <p class="text-xs sm:text-sm text-gray-400">{{ tw('ecosystem.maxPerReferral') }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 13. Platform Integrations -->
                    <section id="integrations" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[13] }}</h2>
                        <SectionVideo file="ep08-real-world.mp4" :part="tw('video.ep08')" :start="209.7" :duration="272" :lang="lang" />
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="int in c.integrations" :key="int.name" class="p-4 sm:p-5 rounded-xl bg-white/5 border border-white/10">
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
                        <h2 class="wp-heading">{{ c.toc[14] }}</h2>
                        <SectionVideo file="ep09-the-road-ahead.mp4" :part="tw('video.ep09')" :start="9.3" :duration="388" :lang="lang" />
                        <div class="space-y-4">
                            <div v-for="r in c.roadmap" :key="r.q" class="flex flex-col sm:flex-row gap-1 sm:gap-4">
                                <div class="flex-shrink-0 sm:w-28 flex sm:block items-center gap-2">
                                    <span class="text-sm font-bold" :class="{
                                        'text-trading-green': r.status === 'done',
                                        'text-yellow-400': r.status === 'progress',
                                        'text-primary-400': r.status === 'current',
                                        'text-gray-500': r.status === 'planned',
                                    }">{{ r.q }}</span>
                                    <span class="sm:block text-xs sm:mt-0.5" :class="{
                                        'text-trading-green': r.status === 'done',
                                        'text-yellow-400': r.status === 'progress',
                                        'text-primary-400': r.status === 'current',
                                        'text-gray-600': r.status === 'planned',
                                    }">
                                        {{ r.status === 'done' ? '✅' : r.status === 'progress' ? '🚧' : r.status === 'current' ? '🔵' : '📅' }}
                                    </span>
                                </div>
                                <div class="flex-1 pb-4" :class="r.q !== c.roadmap[c.roadmap.length - 1].q ? 'border-b border-white/5' : ''">
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
                        <h2 class="wp-heading">{{ c.toc[15] }}</h2>
                        <SectionVideo file="ep02-the-chain.mp4" :part="tw('video.ep02')" :start="172.9" :duration="230" :lang="lang" />
                        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="cat in c.techStack" :key="cat.category" class="p-4 rounded-xl bg-white/5 border border-white/10">
                                <h4 class="font-semibold text-white mb-3 capitalize">{{ cat.title }}</h4>
                                <div v-for="item in cat.items" :key="item[0]" class="flex justify-between gap-3 text-sm py-1 border-b border-white/5 last:border-0">
                                    <span class="text-primary-400 font-medium flex-shrink-0">{{ item[0] }}</span>
                                    <span class="text-gray-500 text-xs text-right min-w-0">{{ item[1] }}</span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 16. Team & Partners -->
                    <section id="team" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[16] }}</h2>
                        <SectionVideo file="ep09-the-road-ahead.mp4" :part="tw('video.ep09')" :start="205.8" :duration="388" :lang="lang" />
                        <p class="wp-text">{{ c.teamDesc }}</p>
                        <div class="wp-highlight">
                            <h4 class="font-semibold text-white mb-3">{{ tw('team.highlightsHeading') }}</h4>
                            <ul class="space-y-2">
                                <li v-for="h in c.teamHighlights" :key="h" class="text-sm text-gray-300 flex items-start gap-2">
                                    <span class="text-primary-400">✦</span>
                                    <span>{{ h }}</span>
                                </li>
                            </ul>
                        </div>
                    </section>

                    <!-- 17. Security & Audits -->
                    <section id="security" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[17] }}</h2>
                        <SectionVideo file="ep09-the-road-ahead.mp4" :part="tw('video.ep09')" :start="257.8" :duration="388" :lang="lang" />
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="item in c.securityItems" :key="item.title" class="p-4 rounded-xl bg-white/5 border border-white/10">
                                <h4 class="font-semibold text-white mb-2">🔒 {{ item.title }}</h4>
                                <p class="text-sm text-gray-400">{{ item.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 18. Legal Disclaimer -->
                    <section id="legal" class="wp-section">
                        <h2 class="wp-heading">{{ c.toc[18] }}</h2>
                        <SectionVideo file="ep09-the-road-ahead.mp4" :part="tw('video.ep09')" :start="326.6" :duration="388" :lang="lang" />
                        <div class="p-4 sm:p-6 rounded-xl bg-white/5 border border-white/10">
                            <p class="text-sm text-gray-500 mb-3">
                                {{ tw('legal.p1') }}
                            </p>
                            <p class="text-sm text-gray-500">
                                {{ tw('legal.p2') }}
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
/* scroll-mt บนจอเล็กต้องเผื่อ NavBar (64px) + แถบสารบัญที่ติดอยู่ใต้มัน */
.wp-section { @apply mb-12 sm:mb-16 scroll-mt-32 xl:scroll-mt-24; }
.wp-heading { @apply text-xl sm:text-2xl lg:text-3xl font-bold text-white mb-4 pb-3 border-b border-white/10 leading-snug; }
.wp-subheading { @apply text-base sm:text-lg font-semibold text-white mt-6 mb-3; }
.wp-text { @apply text-sm sm:text-base text-gray-300 leading-relaxed mb-4; }
.wp-list { @apply list-disc pl-5 space-y-2 text-sm sm:text-base text-gray-300 mb-4; }
.wp-highlight { @apply p-4 sm:p-6 rounded-xl bg-white/5 border border-white/10 my-6; }
.wp-table { @apply rounded-xl bg-white/5 border border-white/10 overflow-x-auto overscroll-x-contain my-6; }
/* ตารางหลายคอลัมน์ — เลื่อนแนวนอนบนมือถือแทนการบีบข้อความจนอ่านไม่ออก */
.wp-table-wide table { min-width: 32rem; }
.wp-table td { overflow-wrap: anywhere; }
</style>
