/* ===========================================================
   make-thumbs.mjs — ปก YouTube 1280×720 ทั้ง 9 ตอน + ปกเพลย์ลิสต์
   ใช้ภาพประดับและโทนสีชุดเดียวกับตัวหนัง ปกจึงเป็นชุดเดียวกันทั้งซีรีส์

   ตัวหนังสือต้องอ่านออกตอนย่อเหลือ 320×180 (ขนาดที่ YouTube โชว์จริง
   ในหน้าแนะนำ) จึงใช้ตัวใหญ่และจำกัดข้อความให้สั้นที่สุด

   node build/make-thumbs.mjs
   =========================================================== */

import puppeteer from 'puppeteer-core';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const CHROME = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const OUT = resolve('out/thumbs');
mkdirSync(OUT, { recursive: true });

const EPS = [
    { id: 'ep01', n: 'ONE',   plate: 'ep01', accent: 'gold',
      title: 'Foundation',        hook: 'บล็อกเชนไทยที่ค่าแก๊สเป็นศูนย์' },
    { id: 'ep02', n: 'TWO',   plate: 'ep02', accent: 'cyan',
      title: 'The Chain',         hook: '2 วินาทีต่อบล็อก ยืนยันเด็ดขาดทันที' },
    { id: 'ep03', n: 'THREE', plate: 'ep03', accent: 'gold',
      title: 'Economics',         hook: '7,000 ล้านเหรียญ แบ่งกันอย่างไร' },
    { id: 'ep04', n: 'FOUR',  plate: 'ep04', accent: 'gold',
      title: 'Master Nodes',      hook: 'สี่ระดับ ผลตอบแทน 4–20% ต่อปี' },
    { id: 'ep05', n: 'FIVE',  plate: 'ep05', accent: 'cyan',
      title: 'Trading Layer',     hook: 'DEX · โรงงานโทเคน · สะพานข้ามเชน' },
    { id: 'ep06', n: 'SIX',   plate: 'ep06', accent: 'gold', hero: true,
      title: 'AI TRADE',          hook: 'บอทเทรดที่เทรดสวนคุณไม่ได้' },
    { id: 'ep07', n: 'SEVEN', plate: 'ep07', accent: 'violet',
      title: 'Living Identity',   hook: 'กู้กระเป๋าคืนโดยไม่ต้องมีวลีลับ' },
    { id: 'ep08', n: 'EIGHT', plate: 'ep08', accent: 'green',
      title: 'Real World',        hook: 'เก้าผลิตภัณฑ์ที่เปิดใช้จริงแล้ว' },
    { id: 'ep09', n: 'NINE',  plate: 'ep09', accent: 'cyan',
      title: 'The Road Ahead',    hook: 'ปี 2027 ให้ AI ดูแลเชนเอง' },
];

const ACCENT = {
    gold:   { c: '#F8D678', glow: 'rgba(233,174,40,.55)', bar: '#E9AE28' },
    cyan:   { c: '#7DE7F7', glow: 'rgba(34,211,238,.50)', bar: '#22D3EE' },
    violet: { c: '#B79BFB', glow: 'rgba(139,92,246,.50)', bar: '#8B5CF6' },
    green:  { c: '#5FE39B', glow: 'rgba(43,196,106,.50)', bar: '#2BC46A' },
};

function html(ep) {
    const a = ACCENT[ep.accent];
    const titleSize = ep.title.length > 12 ? 96 : 116;
    return `<!doctype html><meta charset="utf-8">
<link rel="stylesheet" href="${pathToFileURL(resolve('assets/fonts.css')).href}">
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  html,body{width:1280px;height:720px;overflow:hidden;background:#04060C;
    font-family:"Inter",sans-serif;-webkit-font-smoothing:antialiased}
  #t{position:relative;width:1280px;height:720px;overflow:hidden}
  .plate{position:absolute;inset:0;width:1280px;height:720px;object-fit:cover;opacity:.72}
  /* ผ้าคลุมไล่เฉดจากซ้าย — ให้ตัวหนังสือลอยเหนือภาพได้ทุกใบ ไม่ต้องเลือกภาพเป็นพิเศษ */
  .scrim{position:absolute;inset:0;background:
     linear-gradient(96deg, rgba(2,4,9,.96) 0%, rgba(2,4,9,.88) 42%, rgba(2,4,9,.30) 72%, rgba(2,4,9,.55) 100%)}
  .vig{position:absolute;inset:0;background:radial-gradient(120% 90% at 40% 50%,transparent 35%,rgba(0,0,0,.6) 100%)}
  .ghost{position:absolute;right:-30px;top:-70px;font-size:430px;font-weight:800;
    letter-spacing:-.05em;color:transparent;-webkit-text-stroke:2px rgba(255,255,255,.055);line-height:1}
  .wrap{position:absolute;left:74px;top:0;bottom:0;width:800px;display:flex;
    flex-direction:column;justify-content:center}
  .eyebrow{display:flex;align-items:center;gap:14px;font-size:22px;font-weight:750;
    letter-spacing:.26em;color:#8A9AB0}
  .eyebrow b{color:${a.c}}
  .dot{width:10px;height:10px;border-radius:50%;background:${a.bar};box-shadow:0 0 16px ${a.glow}}
  .title{font-size:${titleSize}px;font-weight:800;letter-spacing:-.035em;line-height:1.02;
    margin-top:20px;color:#fff;text-shadow:0 6px 34px rgba(0,0,0,.85)}
  .title span{color:${a.c}}
  .rule{width:132px;height:5px;border-radius:3px;margin-top:26px;
    background:linear-gradient(90deg,${a.bar},transparent)}
  .hook{font-family:"Noto Sans Thai","Leelawadee UI",sans-serif;font-size:41px;font-weight:600;
    line-height:1.35;color:#E6EDF7;margin-top:26px;max-width:760px;
    text-shadow:0 3px 18px rgba(0,0,0,.9);text-wrap:balance}
  .foot{position:absolute;left:74px;bottom:52px;display:flex;align-items:center;gap:16px}
  .foot img{width:58px;height:58px;object-fit:contain;filter:drop-shadow(0 0 18px rgba(233,174,40,.5))}
  .foot .txt{font-size:21px;font-weight:700;letter-spacing:.2em;color:#A6B6CC}
  .coin{position:absolute;right:92px;top:50%;transform:translateY(-50%);width:300px;height:300px;
    object-fit:contain;filter:drop-shadow(0 0 60px ${a.glow}) drop-shadow(0 0 140px rgba(233,174,40,.22))}
  .bar{position:absolute;left:0;right:0;bottom:0;height:8px;
    background:linear-gradient(90deg,${a.bar},#E9AE28 55%,transparent)}
</style>
<div id="t">
  <img class="plate" src="${pathToFileURL(resolve(`assets/plates/${ep.plate}.jpg`)).href}">
  <div class="scrim"></div><div class="vig"></div>
  <div class="ghost">${ep.n}</div>
  <img class="coin" src="${pathToFileURL(resolve('assets/tpix-logo.webp')).href}">
  <div class="wrap">
    <div class="eyebrow"><span class="dot"></span>TPIX CHAIN WHITEPAPER<span style="color:#3C4A5E">/</span><b>PART ${ep.n}</b></div>
    <div class="title">${ep.title}</div>
    <div class="rule"></div>
    <div class="hook">${ep.hook}</div>
  </div>
  <div class="foot">
    <img src="${pathToFileURL(resolve('assets/tpix-logo.webp')).href}">
    <span class="txt">บรรยายอังกฤษ · ซับไทย</span>
  </div>
  <div class="bar"></div>
</div>`;
}

const COVER = `<!doctype html><meta charset="utf-8">
<link rel="stylesheet" href="${pathToFileURL(resolve('assets/fonts.css')).href}">
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  html,body{width:1280px;height:720px;overflow:hidden;background:#04060C;
    font-family:"Inter",sans-serif;-webkit-font-smoothing:antialiased}
  #t{position:relative;width:1280px;height:720px;overflow:hidden;
     display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}
  .plate{position:absolute;inset:0;width:1280px;height:720px;object-fit:cover;opacity:.6}
  .scrim{position:absolute;inset:0;background:radial-gradient(105% 85% at 50% 48%,rgba(2,4,9,.94) 20%,rgba(2,4,9,.72) 62%,rgba(2,4,9,.94) 100%)}
  .coin{width:172px;height:172px;object-fit:contain;position:relative;
    filter:drop-shadow(0 0 54px rgba(233,174,40,.55)) drop-shadow(0 0 150px rgba(233,174,40,.2))}
  .eye{position:relative;margin-top:26px;font-size:23px;font-weight:750;letter-spacing:.42em;color:#22D3EE}
  .wm{position:relative;margin-top:16px;font-size:98px;font-weight:800;letter-spacing:.02em;line-height:1;
    background:linear-gradient(96deg,#FFF0C6 0%,#F8D678 30%,#E9AE28 58%,#7DE7F7 100%);
    -webkit-background-clip:text;background-clip:text;color:transparent}
  .sub{position:relative;margin-top:22px;font-family:"Noto Sans Thai",sans-serif;font-size:38px;
    font-weight:600;color:#E6EDF7;text-shadow:0 3px 18px rgba(0,0,0,.9)}
  .meta{position:relative;margin-top:34px;display:flex;gap:30px;align-items:center;
    font-size:22px;font-weight:700;letter-spacing:.14em;color:#8A9AB0}
  .meta b{color:#F8D678}
  .bar{position:absolute;left:0;right:0;bottom:0;height:8px;
    background:linear-gradient(90deg,#E9AE28,#22D3EE 60%,transparent)}
</style>
<div id="t">
  <img class="plate" src="${pathToFileURL(resolve('assets/plates/ep01.jpg')).href}">
  <div class="scrim"></div>
  <img class="coin" src="${pathToFileURL(resolve('assets/tpix-logo.webp')).href}">
  <div class="eye">WHITEPAPER · DOCUMENTARY SERIES</div>
  <div class="wm">TPIX CHAIN</div>
  <div class="sub">ไวท์เปเปอร์ฉบับเต็ม 9 ตอน · บรรยายอังกฤษ ซับไทย</div>
  <div class="meta"><span><b>9</b> ตอน</span><span><b>42</b> นาที</span><span><b>19</b> หัวข้อ</span></div>
  <div class="bar"></div>
</div>`;

const browser = await puppeteer.launch({
    executablePath: CHROME, headless: true,
    args: ['--hide-scrollbars', '--force-color-profile=srgb', '--font-render-hinting=none',
           '--disable-lcd-text', '--allow-file-access-from-files', '--enable-unsafe-swiftshader'],
});
const page = await browser.newPage();
await page.setViewport({ width: 1280, height: 720, deviceScaleFactor: 1 });

async function shoot(name, markup) {
    const f = resolve(`tmp/thumb-${name}.html`);
    writeFileSync(f, markup, 'utf-8');
    await page.goto(pathToFileURL(f).href, { waitUntil: 'load' });
    await page.evaluate(async () => {
        await document.fonts.ready;
        await Promise.all(Array.from(document.images).map((i) =>
            i.complete ? Promise.resolve() : i.decode().catch(() => {})));
    });
    const out = `${OUT}\\${name}.jpg`;
    await page.screenshot({ path: out, type: 'jpeg', quality: 92 });
    console.log(`  ${name}.jpg`);
}

console.log('rendering thumbnails 1280x720…');
for (const ep of EPS) await shoot(`${ep.id}-thumb`, html(ep));
await shoot('00-series-cover', COVER);
await browser.close();
console.log(`✓ ${EPS.length + 1} thumbnails → ${OUT}`);
