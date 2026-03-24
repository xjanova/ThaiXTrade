/**
 * Capture screenshots of TPIX Master Node UI for the setup guide.
 * Requires: npm install puppeteer
 * Usage: node scripts/capture-screenshots.js
 */
const puppeteer = require('puppeteer');
const path = require('path');

const OUTPUT_DIR = path.join(__dirname, '..', '..', 'public_html', 'images', 'guide');
const BASE_URL = 'http://localhost:3847';

const TABS = [
    { name: 'dashboard', navIndex: 0, desc: 'Dashboard - แดชบอร์ด' },
    { name: 'setup-tier', navIndex: 1, desc: 'Setup - เลือกระดับโหนด' },
    { name: 'wallet', navIndex: 2, desc: 'Wallet - กระเป๋าเงิน' },
    { name: 'network', navIndex: 3, desc: 'Network - เครือข่าย' },
    { name: 'links', navIndex: 4, desc: 'Links - ลิงก์' },
    { name: 'settings', navIndex: 6, desc: 'Settings - ตั้งค่า' },
    { name: 'about', navIndex: 7, desc: 'About - เกี่ยวกับ' },
];

(async () => {
    const fs = require('fs');
    if (!fs.existsSync(OUTPUT_DIR)) fs.mkdirSync(OUTPUT_DIR, { recursive: true });

    const browser = await puppeteer.launch({ headless: 'new' });
    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });

    console.log('Navigating to', BASE_URL);
    await page.goto(BASE_URL, { waitUntil: 'networkidle2' });
    await page.waitForSelector('.nav-btn');

    for (const tab of TABS) {
        console.log(`Capturing: ${tab.desc}`);
        const btns = await page.$$('.nav-btn');
        await btns[tab.navIndex].click();
        await new Promise(r => setTimeout(r, 500));

        const outPath = path.join(OUTPUT_DIR, `${tab.name}.jpg`);
        await page.screenshot({ path: outPath, type: 'jpeg', quality: 90 });
        console.log(`  Saved: ${outPath}`);
    }

    await browser.close();
    console.log('Done! Screenshots saved to', OUTPUT_DIR);
})();
