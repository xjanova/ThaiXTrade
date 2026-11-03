<script setup>
/**
 * TPIX TRADE - Carbon Credit Whitepaper (Premium Edition)
 * เอกสาร Carbon Credit แบบ whitepaper-style พร้อม:
 * - สลับภาษาไทย/อังกฤษ (ใช้ locale กลางของเว็บ — เปลี่ยนที่ NavBar หรือปุ่มในหน้านี้ก็ได้)
 * - Interactive Table of Contents
 * - SVG Charts & Diagrams
 * - PDF Download
 * Developed by Xman Studio
 */

import { ref, computed, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTranslation } from '@/Composables/useTranslation';

const { t, locale, setLocale } = useTranslation();

// คีย์ทั้งหมดของหน้านี้อยู่ใต้ page.carbonCreditWhitepaper
const NS = 'page.carbonCreditWhitepaper';
const tk = (key, params) => t(`${NS}.${key}`, params);
// เลขลำดับ 1..n สำหรับสร้างคีย์ item1, item2, ...
const range = (n) => Array.from({ length: n }, (_, i) => i + 1);

const toggleLang = () => setLocale(locale.value === 'th' ? 'en' : 'th');

const sectionIds = [
    'overview', 'why-carbon', 'how-it-works', 'project-types', 'standards', 'marketplace',
    'blockchain', 'tokenomics', 'api', 'mobile', 'roadmap', 'legal',
];

const sections = computed(() => sectionIds.map((id, i) => ({ id, title: tk(`toc.item${i + 1}`) })));

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
    sectionIds.forEach(id => {
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
    // คืน title เดิมหลังพิมพ์ (title เดิมแปลตามภาษาปัจจุบันอยู่แล้ว)
    const previousTitle = document.title;
    const suffix = locale.value === 'th' ? 'TH' : 'EN';
    document.title = `TPIX-Carbon-Credit-Whitepaper-v1.0-${suffix}`;
    setTimeout(() => {
        window.print();
        setTimeout(() => {
            document.title = previousTitle;
            const s = document.getElementById('print-style');
            if (s) s.remove();
        }, 1000);
    }, 100);
}

// SVG Donut Chart — Carbon Credit Allocation (ป้ายชื่ออยู่ที่ tokenomics.allocation.itemN)
const creditAllocationBase = [
    { pct: 35, color: '#10B981', icon: '🌳' },
    { pct: 25, color: '#F59E0B', icon: '⚡' },
    { pct: 15, color: '#EF4444', icon: '🔥' },
    { pct: 15, color: '#3B82F6', icon: '🌊' },
    { pct: 10, color: '#8B5CF6', icon: '🦋' },
];
const creditAllocation = computed(() => creditAllocationBase.map((item, idx) => ({
    ...item,
    id: `alloc-${idx + 1}`,
    label: tk(`tokenomics.allocation.item${idx + 1}`),
})));

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

// path ไม่ขึ้นกับภาษา — คำนวณครั้งเดียว
const donutSegments = (() => {
    let angle = 0;
    return creditAllocationBase.map((item, idx) => {
        const start = angle;
        const sweep = (item.pct / 100) * 360;
        angle += sweep;
        return { ...item, id: `alloc-${idx + 1}`, path: donutPath(start, start + sweep - 0.5) };
    });
})();

// Overview stats
const overviewStats = computed(() => range(6).map(i => ({
    id: i,
    value: tk(`overview.stats.item${i}.value`),
    label: tk(`overview.stats.item${i}.label`),
})));

// รายการ title + desc แบบทั่วไป — เรียกใน computed เท่านั้น
const titledList = (prefix, n) => range(n).map(i => ({
    id: i,
    title: tk(`${prefix}.item${i}.title`),
    desc: tk(`${prefix}.item${i}.desc`),
}));

const whyProblems = computed(() => titledList('why.problems', 4));
const whySolutions = computed(() => titledList('why.solutions', 4));
const howPurchaseSteps = computed(() => titledList('how.purchaseSteps', 4).map(s => ({ ...s, num: s.id })));
const howRetireSteps = computed(() => titledList('how.retireSteps', 4).map(s => ({ ...s, num: s.id })));

// Project types — icon และช่วงราคาไม่ขึ้นกับภาษา
const projectTypeBase = [
    { icon: '🌳', price: '$8-25/tCO2e' },
    { icon: '⚡', price: '$5-15/tCO2e' },
    { icon: '🔥', price: '$10-30/tCO2e' },
    { icon: '🌊', price: '$15-40/tCO2e' },
    { icon: '🦋', price: '$10-35/tCO2e' },
    { icon: '🏭', price: '$3-12/tCO2e' },
    { icon: '🌾', price: '$8-20/tCO2e' },
];
const projectTypes = computed(() => projectTypeBase.map((p, idx) => {
    const i = idx + 1;
    return {
        ...p,
        id: i,
        title: tk(`projectTypes.item${i}.title`),
        desc: tk(`projectTypes.item${i}.desc`),
        metric: tk(`projectTypes.item${i}.metric`),
    };
}));

// Standards — ชื่อมาตรฐานเป็นชื่อเฉพาะ ไม่แปล
const standardBase = [
    { name: 'VCS (Verra)', full: 'Verified Carbon Standard' },
    { name: 'Gold Standard', full: 'Gold Standard for the Global Goals' },
    { name: 'CDM', full: 'Clean Development Mechanism' },
    { name: 'ACR', full: 'American Carbon Registry' },
];
const standards = computed(() => standardBase.map((s, idx) => {
    const i = idx + 1;
    return {
        ...s,
        desc: tk(`standards.item${i}.desc`),
        features: range(4).map(f => ({ id: f, text: tk(`standards.item${i}.features.item${f}`) })),
    };
}));

// Marketplace features
const marketIcons = ['💳', '📊', '⚡', '🏆', '📈', '🏢'];
const marketFeatures = computed(() => titledList('market.features', marketIcons.length)
    .map((f, idx) => ({ ...f, icon: marketIcons[idx] })));

// Blockchain hybrid model — kind ใช้กำหนดสี (ไม่ผูกกับข้อความที่แปลแล้ว)
const blockchainModel = computed(() => [
    { kind: 'on', i: 1 },
    { kind: 'off', i: 2 },
].map(m => ({
    kind: m.kind,
    layer: tk(`blockchain.model.item${m.i}.layer`),
    items: range(5).map(n => ({ id: n, text: tk(`blockchain.model.item${m.i}.items.item${n}`) })),
})));

const blockchainSpecs = computed(() => range(7).map(i => ({
    id: i,
    label: tk(`blockchain.specs.item${i}.label`),
    value: tk(`blockchain.specs.item${i}.value`),
})));

// Fees — ค่าตัวเลขไม่ขึ้นกับภาษา
const feeValues = ['2.5%', '0%', '0', '0.01 tCO2e'];
const tokenomicsFees = computed(() => feeValues.map((value, idx) => ({
    id: idx + 1,
    value,
    item: tk(`tokenomics.fees.item${idx + 1}.item`),
    desc: tk(`tokenomics.fees.item${idx + 1}.desc`),
})));

// Mobile features — ready = API พร้อมแล้ว, planned = กำลังพัฒนา
const mobileStatus = ['ready', 'ready', 'ready', 'planned', 'planned', 'planned'];
const mobileFeatures = computed(() => titledList('mobile.features', mobileStatus.length).map((f, idx) => ({
    ...f,
    ready: mobileStatus[idx] === 'ready',
    status: mobileStatus[idx] === 'ready' ? tk('mobile.statusReady') : tk('mobile.statusPlanned'),
})));

// Roadmap
const roadmapPeriods = ['Q1 2026', 'Q2 2026', 'Q3 2026', 'Q4 2026'];
const roadmap = computed(() => roadmapPeriods.map((period, idx) => {
    const i = idx + 1;
    return {
        id: i,
        period,
        phase: tk('roadmap.phase', { n: i }),
        title: tk(`roadmap.phases.item${i}.title`),
        items: range(4).map(n => ({ id: n, text: tk(`roadmap.phases.item${i}.items.item${n}`) })),
    };
}));

// API Endpoints — path/method เป็นข้อมูลเทคนิค ไม่แปล
const apiEndpointBase = [
    { method: 'GET', path: '/api/v1/carbon-credits/projects', auth: false },
    { method: 'GET', path: '/api/v1/carbon-credits/projects/{id}', auth: false },
    { method: 'POST', path: '/api/v1/carbon-credits/purchase', auth: true },
    { method: 'POST', path: '/api/v1/carbon-credits/retire', auth: true },
    { method: 'GET', path: '/api/v1/carbon-credits/my-credits', auth: true },
    { method: 'GET', path: '/api/v1/carbon-credits/my-retirements', auth: true },
    { method: 'GET', path: '/api/v1/carbon-credits/stats', auth: false },
];
const apiEndpoints = computed(() => apiEndpointBase.map((ep, idx) => ({
    ...ep,
    desc: tk(`api.endpoints.item${idx + 1}`),
})));
</script>

<template>
    <Head :title="tk('pageTitle')" />

    <AppLayout :title="tk('pageTitle')">

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

                <h1 class="text-4xl sm:text-5xl font-bold text-white mb-2">{{ tk('pageTitle') }}</h1>
                <p class="text-lg text-green-400 font-medium mb-1">{{ tk('subtitle') }}</p>
                <p class="text-sm text-gray-500 mb-6">{{ tk('version') }}</p>

                <div class="flex flex-wrap items-center justify-center gap-3">
                    <button @click="downloadPdf" class="btn-primary px-8 py-3 inline-flex items-center gap-2 font-semibold">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                        {{ tk('downloadPdf') }}
                    </button>
                    <button @click="toggleLang" class="btn-secondary px-6 py-3 font-semibold">
                        {{ tk('switchLang') }}
                    </button>
                    <Link href="/carbon-credits" class="btn-secondary px-6 py-3 font-semibold inline-flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 100 4 2 2 0 000-4z" /></svg>
                        {{ tk('goToMarketplace') }}
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
                            {{ tk('contents') }}
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
                        <h2 class="wp-heading">{{ tk('toc.item1') }}</h2>
                        <h3 class="wp-subheading text-green-400">{{ tk('overview.title') }}</h3>
                        <p class="wp-text">{{ tk('overview.p1') }}</p>
                        <p class="wp-text">{{ tk('overview.p2') }}</p>
                        <p class="wp-text font-medium text-white/90">{{ tk('overview.p3') }}</p>

                        <div class="wp-highlight">
                            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-center">
                                <div v-for="s in overviewStats" :key="s.id">
                                    <p class="text-2xl font-bold text-green-400">{{ s.value }}</p>
                                    <p class="text-xs text-gray-400 mt-1">{{ s.label }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 2. Why Carbon Credits -->
                    <section id="why-carbon" class="wp-section">
                        <h2 class="wp-heading">{{ tk('toc.item2') }}</h2>
                        <p class="wp-text">{{ tk('why.p1') }}</p>
                        <p class="wp-text">{{ tk('why.p2') }}</p>

                        <h3 class="wp-subheading text-trading-red">{{ tk('why.problemsTitle') }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4 mb-8">
                            <div v-for="p in whyProblems" :key="p.id" class="p-4 rounded-xl bg-red-500/5 border border-red-500/10">
                                <h4 class="font-semibold text-white mb-2">{{ p.title }}</h4>
                                <p class="text-sm text-gray-400">{{ p.desc }}</p>
                            </div>
                        </div>

                        <h3 class="wp-subheading text-trading-green">{{ tk('why.solutionsTitle') }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="s in whySolutions" :key="s.id" class="p-4 rounded-xl bg-green-500/5 border border-green-500/10">
                                <h4 class="font-semibold text-white mb-2">{{ s.title }}</h4>
                                <p class="text-sm text-gray-400">{{ s.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 3. How It Works -->
                    <section id="how-it-works" class="wp-section">
                        <h2 class="wp-heading">{{ tk('toc.item3') }}</h2>

                        <!-- Purchase Flow Diagram -->
                        <div class="wp-highlight mb-6">
                            <h4 class="text-center text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                {{ tk('how.purchaseFlow') }}
                            </h4>
                            <div class="flex flex-wrap items-center justify-center gap-2 text-xs">
                                <span class="px-3 py-2 rounded-lg bg-blue-500/20 text-blue-300 font-medium">{{ tk('how.flowBrowse') }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-purple-500/20 text-purple-300 font-medium">{{ tk('how.flowSelectPay') }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-green-500/20 text-green-300 font-medium">{{ tk('how.flowOnChain') }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-yellow-500/20 text-yellow-300 font-medium">{{ tk('how.flowHoldRetire') }}</span>
                            </div>
                        </div>

                        <h3 class="wp-subheading text-green-400">{{ tk('how.buyingTitle') }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4 mb-8">
                            <div v-for="step in howPurchaseSteps" :key="step.num" class="p-4 rounded-xl bg-white/5 border border-white/10">
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
                                {{ tk('how.retireFlow') }}
                            </h4>
                            <div class="flex flex-wrap items-center justify-center gap-2 text-xs">
                                <span class="px-3 py-2 rounded-lg bg-emerald-500/20 text-emerald-300 font-medium">{{ tk('how.flowSelectCredits') }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-teal-500/20 text-teal-300 font-medium">{{ tk('how.flowBeneficiary') }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-cyan-500/20 text-cyan-300 font-medium">{{ tk('how.flowBurn') }}</span>
                                <span class="text-gray-500">&#x2192;</span>
                                <span class="px-3 py-2 rounded-lg bg-amber-500/20 text-amber-300 font-medium">{{ tk('how.flowCertificate') }}</span>
                            </div>
                        </div>

                        <h3 class="wp-subheading text-emerald-400">{{ tk('how.retiringTitle') }}</h3>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="step in howRetireSteps" :key="step.num" class="p-4 rounded-xl bg-white/5 border border-white/10">
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
                        <h2 class="wp-heading">{{ tk('toc.item4') }}</h2>
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div v-for="pt in projectTypes" :key="pt.id" class="p-5 rounded-xl bg-white/5 border border-white/10 hover:border-green-500/30 transition-colors">
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
                        <h2 class="wp-heading">{{ tk('toc.item5') }}</h2>
                        <div class="space-y-4">
                            <div v-for="std in standards" :key="std.name" class="p-5 rounded-xl bg-white/5 border border-white/10">
                                <div class="flex items-start gap-4">
                                    <div class="w-14 h-14 rounded-xl bg-gradient-to-br from-green-500/20 to-emerald-500/20 flex items-center justify-center flex-shrink-0">
                                        <span class="text-green-400 font-bold text-xs text-center leading-tight">{{ std.name.split(' ')[0] }}</span>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-semibold text-white">{{ std.name }}</h4>
                                        <p class="text-xs text-green-400 mb-2">{{ std.full }}</p>
                                        <p class="text-sm text-gray-400 mb-3">{{ std.desc }}</p>
                                        <div class="flex flex-wrap gap-2">
                                            <span v-for="f in std.features" :key="f.id" class="px-2 py-1 rounded-md bg-green-500/10 text-green-400 text-xs">{{ f.text }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 6. Marketplace -->
                    <section id="marketplace" class="wp-section">
                        <h2 class="wp-heading">{{ tk('toc.item6') }}</h2>
                        <p class="wp-text">{{ tk('market.p1') }}</p>
                        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="f in marketFeatures" :key="f.id" class="p-5 rounded-xl bg-white/5 border border-white/10 hover:border-green-500/30 transition-colors">
                                <span class="text-2xl mb-3 block">{{ f.icon }}</span>
                                <h4 class="font-semibold text-white mb-2">{{ f.title }}</h4>
                                <p class="text-sm text-gray-400">{{ f.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 7. Blockchain -->
                    <section id="blockchain" class="wp-section">
                        <h2 class="wp-heading">{{ tk('toc.item7') }}</h2>
                        <p class="wp-text">{{ tk('blockchain.p1') }}</p>

                        <!-- Hybrid Model Diagram -->
                        <div class="grid sm:grid-cols-2 gap-4 mb-6">
                            <div v-for="model in blockchainModel" :key="model.kind" class="p-5 rounded-xl border" :class="model.kind === 'on' ? 'bg-green-500/5 border-green-500/20' : 'bg-blue-500/5 border-blue-500/20'">
                                <h4 class="font-semibold mb-3" :class="model.kind === 'on' ? 'text-green-400' : 'text-blue-400'">{{ model.layer }}</h4>
                                <ul class="space-y-1.5">
                                    <li v-for="item in model.items" :key="item.id" class="text-sm text-gray-400 flex items-start gap-2">
                                        <span class="mt-1" :class="model.kind === 'on' ? 'text-green-500' : 'text-blue-500'">&#x25B8;</span>
                                        <span>{{ item.text }}</span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Chain Specs -->
                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tk('blockchain.parameter') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tk('blockchain.value') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(row, i) in blockchainSpecs" :key="row.id" :class="i < blockchainSpecs.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3 text-gray-300">{{ row.label }}</td>
                                        <td class="py-2 px-3 text-white font-medium font-mono text-xs">{{ row.value }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 8. Credit Tokenomics -->
                    <section id="tokenomics" class="wp-section">
                        <h2 class="wp-heading">{{ tk('toc.item8') }}</h2>
                        <p class="wp-text">{{ tk('tokenomics.p1') }}</p>

                        <div class="grid lg:grid-cols-2 gap-8 items-start">
                            <!-- Donut Chart -->
                            <div class="flex flex-col items-center">
                                <h4 class="text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                    {{ tk('tokenomics.distribution') }}
                                </h4>
                                <svg viewBox="0 0 200 200" class="w-64 h-64">
                                    <path v-for="seg in donutSegments" :key="seg.id" :d="seg.path" :fill="seg.color" class="opacity-90 hover:opacity-100 transition-opacity cursor-pointer" />
                                    <text x="100" y="95" text-anchor="middle" fill="white" font-size="11" font-weight="bold">CO2</text>
                                    <text x="100" y="112" text-anchor="middle" fill="#9CA3AF" font-size="7">tCO2e</text>
                                </svg>
                                <div class="grid grid-cols-1 gap-2 mt-4 w-full max-w-xs">
                                    <div v-for="item in creditAllocation" :key="item.id" class="flex items-center gap-2">
                                        <span class="w-3 h-3 rounded-full flex-shrink-0" :style="{ backgroundColor: item.color }"></span>
                                        <span class="text-sm text-gray-300 flex-1">{{ item.label }}</span>
                                        <span class="text-sm font-bold text-white">{{ item.pct }}%</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Fee Table -->
                            <div>
                                <h4 class="text-sm font-bold text-gray-400 mb-4 uppercase tracking-wider">
                                    {{ tk('tokenomics.feeStructure') }}
                                </h4>
                                <div class="wp-table">
                                    <table class="w-full text-sm">
                                        <thead><tr class="border-b border-white/10">
                                            <th class="text-left py-2 px-3 text-gray-400">{{ tk('tokenomics.item') }}</th>
                                            <th class="text-center py-2 px-3 text-gray-400">{{ tk('tokenomics.fee') }}</th>
                                        </tr></thead>
                                        <tbody>
                                            <tr v-for="(fee, i) in tokenomicsFees" :key="fee.id" :class="i < tokenomicsFees.length - 1 ? 'border-b border-white/5' : ''">
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
                        <h2 class="wp-heading">{{ tk('toc.item9') }}</h2>
                        <p class="wp-text">{{ tk('api.p1') }}</p>

                        <div class="wp-table">
                            <table class="w-full text-sm">
                                <thead><tr class="border-b border-white/10">
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tk('api.method') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400">{{ tk('api.endpoint') }}</th>
                                    <th class="text-left py-2 px-3 text-gray-400 hidden sm:table-cell">{{ tk('api.description') }}</th>
                                    <th class="text-center py-2 px-3 text-gray-400">{{ tk('api.auth') }}</th>
                                </tr></thead>
                                <tbody>
                                    <tr v-for="(ep, i) in apiEndpoints" :key="ep.path" :class="i < apiEndpoints.length - 1 ? 'border-b border-white/5' : ''">
                                        <td class="py-2 px-3">
                                            <span class="px-2 py-0.5 rounded text-xs font-bold" :class="ep.method === 'GET' ? 'bg-blue-500/20 text-blue-400' : 'bg-yellow-500/20 text-yellow-400'">{{ ep.method }}</span>
                                        </td>
                                        <td class="py-2 px-3 text-white font-mono text-xs">{{ ep.path }}</td>
                                        <td class="py-2 px-3 text-gray-400 hidden sm:table-cell">{{ ep.desc }}</td>
                                        <td class="py-2 px-3 text-center">
                                            <span v-if="ep.auth" class="text-yellow-400 text-xs">{{ tk('api.wallet') }}</span>
                                            <span v-else class="text-green-400 text-xs">{{ tk('api.public') }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- 10. Mobile App -->
                    <section id="mobile" class="wp-section">
                        <h2 class="wp-heading">{{ tk('toc.item10') }}</h2>
                        <p class="wp-text">{{ tk('mobile.p1') }}</p>
                        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div v-for="f in mobileFeatures" :key="f.id" class="p-5 rounded-xl bg-white/5 border border-white/10">
                                <div class="flex items-center justify-between mb-3">
                                    <h4 class="font-semibold text-white">{{ f.title }}</h4>
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="f.ready ? 'bg-green-500/20 text-green-400' : 'bg-yellow-500/20 text-yellow-400'">{{ f.status }}</span>
                                </div>
                                <p class="text-sm text-gray-400">{{ f.desc }}</p>
                            </div>
                        </div>
                    </section>

                    <!-- 11. Roadmap -->
                    <section id="roadmap" class="wp-section">
                        <h2 class="wp-heading">{{ tk('toc.item11') }}</h2>
                        <div class="space-y-6">
                            <div v-for="(phase, idx) in roadmap" :key="phase.id" class="relative pl-8">
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
                                        <li v-for="item in phase.items" :key="item.id" class="text-sm text-gray-400 flex items-start gap-2">
                                            <span class="text-green-500 mt-0.5">&#x25B8;</span>
                                            <span>{{ item.text }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- 12. Legal -->
                    <section id="legal" class="wp-section">
                        <h2 class="wp-heading">{{ tk('toc.item12') }}</h2>
                        <div class="p-6 rounded-xl bg-white/5 border border-white/10">
                            <p class="text-sm text-gray-500 mb-3">{{ tk('legal.p1') }}</p>
                            <p class="text-sm text-gray-500">{{ tk('legal.p2') }}</p>
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
