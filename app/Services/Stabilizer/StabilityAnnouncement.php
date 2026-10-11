<?php

namespace App\Services\Stabilizer;

use App\Models\Article;

/**
 * ร่างประกาศเปิดตัว TPIX Stability Reserve (ไทย + อังกฤษ) — สร้างเป็น "ร่าง" เท่านั้น ไม่เผยแพร่.
 *
 * เจ้าของต้องอ่านและกดเผยแพร่เองจากหลังบ้าน /admin/content
 * ตัวเลขในประกาศดึงจากพารามิเตอร์ที่ใช้จริง ณ ตอนสร้างร่าง — ถ้าแก้พารามิเตอร์ทีหลัง
 * ให้ลบร่างเดิมแล้วสร้างใหม่ (ประกาศกับกฎจริงต้องตรงกันเสมอ)
 *
 * ถ้อยคำต้องซื่อตรง: ไม่การันตีราคา ไม่บอกว่า AI คุมราคา บอกชัดว่าใครเป็นคนดำเนินการ
 *
 * Developed by Xman Studio
 */
class StabilityAnnouncement
{
    public const SOURCE_KEY = 'announcement:stability-reserve';

    public function __construct(private StabilizerSettings $settings) {}

    /**
     * สร้างร่างที่ยังไม่มี — คืนรายการภาษาที่สร้างใหม่ (ไม่ทับร่าง/บทความเดิม).
     *
     * @return string[]
     */
    public function createDrafts(): array
    {
        $created = [];

        foreach ($this->drafts() as $lang => $draft) {
            // ร่างที่ลบทิ้งแล้ว (soft delete) ไม่นับ — ลบแล้วสั่งใหม่ = ได้ร่างตามพารามิเตอร์ล่าสุด
            if (Article::where('source_key', self::SOURCE_KEY)->where('language', $lang)->exists()) {
                continue;
            }

            Article::create($draft + [
                'language' => $lang,
                'category' => 'news',
                'status' => 'draft',
                'published_at' => null,
                'is_ai_generated' => false,
                'author_name' => 'TPIX TRADE',
                'source_key' => self::SOURCE_KEY,
            ]);
            $created[] = $lang;
        }

        return $created;
    }

    /** @return array<string, array{title: string, summary: string, content: string, tags: string[], seo_title: string, seo_description: string}> */
    public function drafts(): array
    {
        $p = $this->settings->params();
        $wallet = $this->settings->walletAddress();
        $explorer = rtrim((string) config('chains.chains.4289.explorer', 'https://explorer.tpix.online'), '/');
        $fmt = fn ($v) => PoolMath::dec($v);

        $walletEn = $wallet
            ? '<a href="'.e($explorer.'/address/'.$wallet).'" rel="noopener">'.e($wallet).'</a>'
            : 'to be published on the <a href="/stability">/stability</a> page before the reserve is switched on';
        $walletTh = $wallet
            ? '<a href="'.e($explorer.'/address/'.$wallet).'" rel="noopener">'.e($wallet).'</a>'
            : 'จะประกาศบนหน้า <a href="/stability">/stability</a> ก่อนเปิดใช้งาน';

        $en = <<<HTML
<p><strong>Status: not live yet.</strong> The reserve will start only after its wallet is funded and it is switched on. Until then it does nothing. When it starts, the date will be shown on the <a href="/stability">/stability</a> page.</p>

<h2>What it is</h2>
<p>The TPIX Stability Reserve is a fund run by the TPIX project. It follows a fixed set of published rules to push back against sharp TPIX price moves in the TPIX/USDT pool on TPIX DEX (TPIX Chain 4289). It does not set a price. It only steps in when the pool price moves far from its own recent average, and only within strict limits.</p>

<h2>What it is not</h2>
<ul>
<li><strong>No price guarantee.</strong> The reserve can run out of budget, can be paused, and the market can move past its band. Nobody can promise a TPIX price.</li>
<li><strong>AI does not decide trades or control the price.</strong> All decisions come from the fixed rules below. AI only writes a plain-language daily summary of what the rules did.</li>
<li>It never trades for users, never delays or blocks anyone's orders, and never trades with itself.</li>
<li>It runs on a fixed schedule and reads only confirmed (mined) blockchain data. It never looks at pending user transactions.</li>
<li>No wash trading and no fake volume. It cannot buy and sell close together, and it waits a set number of hours between a buy and a sell.</li>
</ul>

<h2>The rules (current values)</h2>
<ul>
<li><strong>Reference price:</strong> the average pool price over the last {$p['lookback_days']} days, sampled every minute. It moves slowly with the market. It is not a fixed target.</li>
<li><strong>Band:</strong> the reference price plus or minus {$fmt($p['band_pct'])}%. Inside the band, the reserve does nothing.</li>
<li><strong>Below the band:</strong> it buys TPIX with USDT, pushing the price back toward the lower edge and never above it.</li>
<li><strong>Above the band:</strong> it sells TPIX for USDT, pushing the price back toward the upper edge and never below it.</li>
<li><strong>Price impact:</strong> at most {$fmt($p['max_impact_pct'])}% per trade.</li>
<li><strong>Trade size:</strong> at most {$fmt($p['max_trade_usdt'])} USDT or {$fmt($p['max_trade_tpix'])} TPIX per trade.</li>
<li><strong>Daily limits:</strong> {$fmt($p['daily_budget_usdt'])} USDT spent and {$fmt($p['daily_budget_tpix'])} TPIX sold per day.</li>
<li><strong>Timing:</strong> at most one trade every {$p['trade_interval_minutes']} minutes, and at least {$p['opposite_cooldown_hours']} hours between a buy and a sell.</li>
<li><strong>Safety stops:</strong> it does nothing if the pool holds less than {$fmt($p['min_pool_usdt'])} USDT or {$fmt($p['min_pool_tpix'])} TPIX, if price data is older than {$p['max_data_age_minutes']} minutes, or if there is not enough price history. If a transaction fails, it pauses itself until a person checks it.</li>
</ul>

<h2>Where the money is</h2>
<p>The reserve trades only from one public wallet: {$walletEn}. The project funds this wallet with TPIX and USDT_TPIX. Every deposit is listed with its transaction hash. Until the bridge publishes proof of reserves, the reserve can spend only USDT that the project deposited and recorded. It will not use any other USDT_TPIX.</p>

<h2>Full transparency</h2>
<p>The <a href="/stability">/stability</a> page shows the rules, the wallet and its balances, a price chart with the band, every trade with a blockchain link, each decision (including "no action" and why), and the daily report. The same data is available as a read-only API at <code>/api/v1/stability</code>.</p>

<h2>Important</h2>
<p>This is not investment advice and not a promise about the price of TPIX. The project runs the reserve and can change its settings or pause it. Changes to the rules will be shown on the /stability page. Crypto prices can fall sharply. Please only use money you can afford to lose.</p>
HTML;

        $th = <<<HTML
<p><strong>สถานะ: ยังไม่เปิดใช้งาน</strong> กองทุนจะเริ่มทำงานหลังจากเติมเงินเข้ากระเป๋าและเปิดสวิตช์แล้วเท่านั้น ระหว่างนี้จะไม่ทำอะไรเลย เมื่อเริ่มแล้วจะแจ้งวันที่ไว้บนหน้า <a href="/stability">/stability</a></p>

<h2>นี่คืออะไร</h2>
<p>TPIX Stability Reserve (กองทุนพยุงราคา TPIX) คือกองทุนที่โครงการ TPIX ดำเนินการเอง ทำงานตามกฎที่ประกาศไว้ล่วงหน้า เพื่อลดความแรงของการแกว่งราคา TPIX ในพูล TPIX/USDT บน TPIX DEX (เชน TPIX 4289) กองทุนไม่ได้ตั้งราคา จะเข้าทำงานเฉพาะเมื่อราคาพูลห่างจากค่าเฉลี่ยย้อนหลังมากเกินกรอบ และทำภายในเพดานที่เข้มงวดเท่านั้น</p>

<h2>สิ่งที่กองทุนนี้ไม่ใช่</h2>
<ul>
<li><strong>ไม่รับประกันราคา</strong> งบอาจหมด กองทุนอาจถูกหยุดชั่วคราว และราคาตลาดอาจหลุดกรอบไปได้ ไม่มีใครสัญญาราคา TPIX ได้</li>
<li><strong>AI ไม่ได้ตัดสินใจซื้อขายและไม่ได้ควบคุมราคา</strong> ทุกการตัดสินใจมาจากกฎตายตัวด้านล่าง AI มีหน้าที่แค่เขียนสรุปรายวันเป็นภาษาที่อ่านง่าย</li>
<li>ไม่ซื้อขายแทนผู้ใช้ ไม่หน่วงหรือกั้นคำสั่งของใคร และไม่ซื้อขายกับตัวเอง</li>
<li>ทำงานตามตารางเวลา และอ่านเฉพาะข้อมูลที่ลงบล็อกแล้ว ไม่ดูธุรกรรมที่ผู้ใช้กำลังส่ง</li>
<li>ไม่ปั่นวอลุ่ม ไม่สร้างปริมาณซื้อขายปลอม ซื้อแล้วขายทันทีไม่ได้ ต้องเว้นระยะหลายชั่วโมงระหว่างการซื้อกับการขาย</li>
</ul>

<h2>กฎที่ใช้ (ค่าปัจจุบัน)</h2>
<ul>
<li><strong>ราคาอ้างอิง:</strong> ค่าเฉลี่ยราคาพูลย้อนหลัง {$p['lookback_days']} วัน เก็บทุกนาที ขยับตามตลาดอย่างช้า ๆ ไม่ใช่ราคาเป้าหมายตายตัว</li>
<li><strong>กรอบราคา:</strong> ราคาอ้างอิง ±{$fmt($p['band_pct'])}% ถ้าราคาอยู่ในกรอบ กองทุนจะไม่ทำอะไร</li>
<li><strong>ราคาต่ำกว่ากรอบ:</strong> ใช้ USDT ซื้อ TPIX ดันราคากลับเข้าหาขอบล่าง และไม่ดันเกินขอบล่าง</li>
<li><strong>ราคาสูงกว่ากรอบ:</strong> ขาย TPIX รับ USDT ดันราคากลับเข้าหาขอบบน และไม่ดันต่ำกว่าขอบบน</li>
<li><strong>ผลต่อราคา:</strong> ไม่เกิน {$fmt($p['max_impact_pct'])}% ต่อหนึ่งไม้</li>
<li><strong>ขนาดไม้:</strong> ไม่เกิน {$fmt($p['max_trade_usdt'])} USDT หรือ {$fmt($p['max_trade_tpix'])} TPIX ต่อไม้</li>
<li><strong>เพดานรายวัน:</strong> ใช้ไม่เกิน {$fmt($p['daily_budget_usdt'])} USDT และขายไม่เกิน {$fmt($p['daily_budget_tpix'])} TPIX ต่อวัน</li>
<li><strong>จังหวะเวลา:</strong> ไม่เกินหนึ่งไม้ทุก {$p['trade_interval_minutes']} นาที และเว้นอย่างน้อย {$p['opposite_cooldown_hours']} ชั่วโมงระหว่างการซื้อกับการขาย</li>
<li><strong>ด่านความปลอดภัย:</strong> ไม่ทำอะไรถ้าพูลมีน้อยกว่า {$fmt($p['min_pool_usdt'])} USDT หรือ {$fmt($p['min_pool_tpix'])} TPIX ถ้าข้อมูลราคาเก่ากว่า {$p['max_data_age_minutes']} นาที หรือถ้าประวัติราคายังไม่พอ ถ้าธุรกรรมล้มเหลว กองทุนจะหยุดตัวเองจนกว่าจะมีคนตรวจ</li>
</ul>

<h2>เงินอยู่ที่ไหน</h2>
<p>กองทุนซื้อขายจากกระเป๋าสาธารณะใบเดียวเท่านั้น: {$walletTh} โครงการเติม TPIX และ USDT_TPIX เข้ากระเป๋านี้ ทุกครั้งที่ฝากจะแสดงพร้อมลิงก์ธุรกรรม จนกว่าสะพานจะเปิดเผยหลักฐานทุนสำรอง (proof of reserves) กองทุนจะใช้ได้เฉพาะ USDT ที่โครงการฝากและบันทึกไว้เท่านั้น จะไม่ใช้ USDT_TPIX อื่น</p>

<h2>โปร่งใสทุกขั้นตอน</h2>
<p>หน้า <a href="/stability">/stability</a> แสดงกฎ กระเป๋าและยอดคงเหลือ กราฟราคาพร้อมกรอบ ทุกไม้พร้อมลิงก์บนบล็อกเชน ทุกการตัดสินใจ (รวมรอบที่ไม่ทำอะไรและเหตุผล) และรายงานรายวัน ข้อมูลชุดเดียวกันเปิดเป็น API แบบอ่านอย่างเดียวที่ <code>/api/v1/stability</code></p>

<h2>ข้อควรทราบ</h2>
<p>ประกาศนี้ไม่ใช่คำแนะนำการลงทุน และไม่ใช่คำสัญญาเรื่องราคา TPIX โครงการเป็นผู้ดำเนินการกองทุน และสามารถปรับค่าหรือหยุดกองทุนได้ การเปลี่ยนกฎจะแสดงบนหน้า /stability ราคาคริปโตลดลงแรงได้ ควรใช้เฉพาะเงินที่เสียได้</p>
HTML;

        return [
            'th' => [
                'title' => 'เปิดตัว TPIX Stability Reserve: กองทุนพยุงราคาที่ทำงานตามกฎ เปิดเผยทุกไม้',
                'summary' => 'กองทุนที่โครงการดำเนินการเอง ทำงานตามกฎที่ประกาศไว้เพื่อลดการแกว่งแรงของราคา TPIX บน TPIX DEX ทุกการตัดสินใจและทุกไม้ตรวจสอบได้ ไม่รับประกันราคา และยังไม่เปิดใช้งาน',
                'content' => $th,
                'tags' => ['TPIX', 'Stability Reserve', 'TPIX DEX', 'transparency'],
                'seo_title' => 'TPIX Stability Reserve — กองทุนพยุงราคาแบบกฎตายตัว',
                'seo_description' => 'กฎ กระเป๋า เพดาน และวิธีตรวจสอบกองทุนพยุงราคา TPIX — ไม่รับประกันราคา',
            ],
            'en' => [
                'title' => 'Introducing the TPIX Stability Reserve: a rule-based fund with every trade public',
                'summary' => 'A project-operated fund that follows fixed, published rules to soften sharp TPIX price moves on TPIX DEX. Every decision and trade can be checked. It does not guarantee any price and it is not live yet.',
                'content' => $en,
                'tags' => ['TPIX', 'Stability Reserve', 'TPIX DEX', 'transparency'],
                'seo_title' => 'TPIX Stability Reserve — rule-based and fully public',
                'seo_description' => 'The rules, wallet, limits and how to verify the TPIX Stability Reserve. No price guarantee.',
            ],
        ];
    }
}
