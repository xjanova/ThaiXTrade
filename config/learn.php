<?php

/*
 * TPIX TRADE — หมวด "เรียนรู้" (วิดีโอสอนใช้งาน + บทความประกอบ)
 *
 * ไฟล์นี้คือต้นฉบับของบทเรียนทั้งหมด:
 *  - LearnLessonsSeeder สร้าง/อัปเดตบทความหมวด learn ตามไฟล์นี้ทุกรอบ deploy (แก้ที่นี่ ไม่ใช่ในหลังบ้าน
 *    — แก้ในหลังบ้านจะถูกทับรอบ deploy ถัดไป) · ยอดวิว/ไลก์/วันเผยแพร่ไม่ถูกแตะ
 *  - บอท Discord โพสต์คลิปลงห้องเรียนรู้ (role "learn") ทีละบทเรียน
 *
 * วิดีโอ/โปสเตอร์อยู่ public_html/videos/learn — mp4 ไม่อยู่ใน git (อัปขึ้นเซิร์ฟด้วย scp แบบ whitepaper)
 * chapters = [วินาทีที่เริ่ม, ชื่อบทภาษาไทย, ชื่อบทภาษาอังกฤษ] ตามเวลาจริงในคลิป
 *
 * Developed by Xman Studio.
 */

return [
    'path' => '/videos/learn',

    'lessons' => [
        [
            'code' => 'install-android',
            'file' => 'install-android.mp4',
            'poster' => 'install-android.jpg',
            'duration' => 274,
            'slug' => ['th' => 'learn-install-tpix-apps-android', 'en' => 'learn-install-tpix-apps-android-en'],
            'title' => [
                'th' => 'วิธีติดตั้ง TPIX TRADE และ TPIX Wallet บน Android (วิดีโอสอนทีละขั้น)',
                'en' => 'How to install TPIX TRADE and TPIX Wallet on Android (step-by-step video)',
            ],
            'summary' => [
                'th' => 'ดาวน์โหลดแอปตัวจริงจาก tpix.online แล้วติดตั้งทีละขั้น พร้อมวิธีแก้ปัญหาที่เจอบ่อย เช่น Play Protect และ Auto Blocker ของ Samsung',
                'en' => 'Download the real apps from tpix.online and install them step by step, plus fixes for common problems like Play Protect and Samsung Auto Blocker.',
            ],
            'tags' => ['เรียนรู้', 'ติดตั้งแอป', 'Android', 'TPIX TRADE', 'TPIX Wallet'],
            'chapters' => [
                [0, 'แนะนำ', 'Intro'],
                [9, 'ทำไมยังไม่มีใน Google Play', 'Why not on Google Play yet'],
                [26, 'TPIX TRADE: เปิดหน้าดาวน์โหลด', 'TPIX TRADE: open the download page'],
                [46, 'รอดาวน์โหลด แล้วเปิดไฟล์', 'Wait for the download, open the file'],
                [71, 'อนุญาตให้ Chrome ติดตั้งแอป', 'Allow Chrome to install apps'],
                [99, 'ติดตั้งและเปิดแอป', 'Install and open the app'],
                [137, 'TPIX Wallet: ดาวน์โหลด', 'TPIX Wallet: download'],
                [172, 'อัปเดตหรือติดตั้ง', 'Update or install'],
                [201, 'แก้ปัญหาที่เจอบ่อย', 'Common problems'],
                [216, 'Play Protect เด้งขึ้นมา', 'Play Protect pops up'],
                [231, 'ถอนแอปเก่าก่อน (จดวลีกู้คืน!)', 'Remove the old app (save your recovery phrase!)'],
                [240, 'Samsung Auto Blocker', 'Samsung Auto Blocker'],
                [257, 'โหลดจาก tpix.online เท่านั้น', 'Only download from tpix.online'],
            ],
            'body' => [
                'th' => <<<'HTML'
<p>ตอนนี้แอป TPIX ยังไม่อยู่ใน Google Play เพราะ Google ตรวจแอปคริปโตเข้มมาก ทีมงานกำลังยื่นขออนุมัติอยู่ ระหว่างนี้ดาวน์โหลดแอปตัวจริงได้จาก <a href="/download">tpix.online/download</a> — เป็นแอปตัวเดียวกัน</p>
<h2>ติดตั้ง TPIX TRADE</h2>
<ol>
<li>เปิด Chrome แล้วพิมพ์ <strong>tpix.online/download</strong></li>
<li>หา TPIX TRADE แล้วแตะ <strong>ดาวน์โหลด APK</strong> รอจนโหลดเสร็จ</li>
<li>แตะจุด 3 จุดมุมขวาบน แล้วแตะ <strong>ดาวน์โหลด</strong> จากนั้นแตะไฟล์ TPIX TRADE</li>
<li>ครั้งแรกมือถือจะห้ามไว้ก่อน (เป็นเรื่องปกติ) แตะ <strong>การตั้งค่า</strong> เปิดสวิตช์ <strong>อนุญาตจากแหล่งที่มานี้</strong> แล้วกดย้อนกลับ</li>
<li>แตะไฟล์อีกครั้ง แล้วแตะ <strong>ติดตั้ง</strong> ติดตั้งเสร็จแตะ <strong>เปิด</strong></li>
</ol>
<h2>ติดตั้ง TPIX Wallet</h2>
<ol>
<li>กลับไปหน้าดาวน์โหลด เลื่อนลงไปที่ TPIX Wallet แล้วแตะ <strong>ดาวน์โหลด TPIX Wallet</strong></li>
<li>เปิด <strong>ดาวน์โหลด</strong> อีกครั้ง แล้วแตะไฟล์ TPIX Wallet — รอบนี้ไม่ต้องตั้งค่าแล้ว</li>
<li>ถ้ามีแอปอยู่แล้วจะขึ้นว่า <strong>อัปเดต</strong> กระเป๋าของคุณยังอยู่ครบ แตะ <strong>อัปเดต</strong> หรือ <strong>ติดตั้ง</strong> แล้วเปิดแอปและใส่ PIN</li>
</ol>
<h2>ติดปัญหา?</h2>
<ul>
<li><strong>Chrome เตือนว่าไฟล์อาจเป็นอันตราย</strong> — เป็นเรื่องปกติของแอปนอก Google Play แตะ <strong>ดาวน์โหลดต่อ</strong></li>
<li><strong>Play Protect เด้งขึ้นมา</strong> — แตะ <strong>รายละเอียดเพิ่มเติม</strong> แล้ว <strong>ติดตั้งต่อไป</strong></li>
<li><strong>ขึ้นว่า "ไม่ได้ติดตั้งแอป"</strong> — เช็คว่ามีพื้นที่ว่างอย่างน้อย 300 MB แล้วลองใหม่</li>
<li><strong>ยังไม่ได้อีก</strong> — ถอนแอปเก่าออกก่อน แต่ถ้าเป็น TPIX Wallet ต้อง <strong>จดวลีกู้คืนเก็บไว้ก่อนถอนเสมอ</strong></li>
<li><strong>มือถือ Samsung</strong> — Auto Blocker อาจบล็อก ปิดได้ที่ การตั้งค่า &gt; ความปลอดภัยและความเป็นส่วนตัว ติดตั้งเสร็จแล้วเปิดกลับด้วย</li>
<li><strong>หาไฟล์ไม่เจอ</strong> — เปิดแอป "ไฟล์" หรือ "ไฟล์ของฉัน" แล้วดูในโฟลเดอร์ "ดาวน์โหลด" (ไฟล์ลงท้าย .apk)</li>
</ul>
<blockquote><strong>ความปลอดภัย:</strong> ดาวน์โหลดจาก tpix.online เท่านั้น ห้ามติดตั้งไฟล์จากลิงก์ที่คนแปลกหน้าส่งมาเด็ดขาด</blockquote>
HTML,
                'en' => <<<'HTML'
<p>TPIX apps are not on Google Play yet — Google reviews crypto apps very strictly and our application is in progress. Until then, download the real apps from <a href="/download">tpix.online/download</a>. They are the same apps.</p>
<h2>Install TPIX TRADE</h2>
<ol>
<li>Open Chrome and type <strong>tpix.online/download</strong>.</li>
<li>Find TPIX TRADE and tap <strong>Download APK</strong>. Wait for the download to finish.</li>
<li>Tap the three dots at the top right, tap <strong>Downloads</strong>, then tap the TPIX TRADE file.</li>
<li>The first time, your phone blocks it (this is normal). Tap <strong>Settings</strong>, turn on <strong>Allow from this source</strong>, then go back.</li>
<li>Tap the file again, tap <strong>Install</strong>, then tap <strong>Open</strong>.</li>
</ol>
<h2>Install TPIX Wallet</h2>
<ol>
<li>Go back to the download page, scroll to TPIX Wallet and tap <strong>Download TPIX Wallet</strong>.</li>
<li>Open <strong>Downloads</strong> again and tap the TPIX Wallet file — no settings this time.</li>
<li>If the app is already installed you will see <strong>Update</strong>. Your wallet stays safe. Tap <strong>Update</strong> or <strong>Install</strong>, then open the app and enter your PIN.</li>
</ol>
<h2>Having trouble?</h2>
<ul>
<li><strong>Chrome says the file may be harmful</strong> — normal for apps outside Google Play. Tap <strong>Download anyway</strong>.</li>
<li><strong>Play Protect pops up</strong> — tap <strong>More details</strong>, then <strong>Install anyway</strong>.</li>
<li><strong>"App not installed"</strong> — make sure you have at least 300 MB free, then try again.</li>
<li><strong>Still not working</strong> — uninstall the old app first. For TPIX Wallet, <strong>always write down your recovery phrase before uninstalling</strong>.</li>
<li><strong>Samsung phones</strong> — Auto Blocker may block it. Turn it off in Settings &gt; Security and privacy, and turn it back on after installing.</li>
<li><strong>Can't find the file</strong> — open the Files (My Files) app and look in the Downloads folder for the file ending in .apk.</li>
</ul>
<blockquote><strong>Stay safe:</strong> only download from tpix.online. Never install a file from a link a stranger sends you.</blockquote>
HTML,
            ],
        ],
        [
            'code' => 'trade-basics',
            'file' => 'tpix-trade-basics.mp4',
            'poster' => 'tpix-trade-basics.jpg',
            'duration' => 115,
            'slug' => ['th' => 'learn-tpix-trade-basics', 'en' => 'learn-tpix-trade-basics-en'],
            'title' => [
                'th' => 'สอนใช้ TPIX TRADE เบื้องต้น: ดูราคา ซื้อขาย สลับเหรียญ (วิดีโอ)',
                'en' => 'TPIX TRADE basics: prices, buy and sell, swap (video)',
            ],
            'summary' => [
                'th' => 'ทัวร์แอป TPIX TRADE ใน 2 นาที — หน้าหลัก ค้นหาเหรียญ กราฟราคา ซื้อ/ขาย สลับเหรียญ แลกบน TPIX Chain แบบไม่มีค่าแก๊ส และ AI TRADE',
                'en' => 'A 2-minute tour of TPIX TRADE — home, coin search, price charts, buy and sell, swap, gas-free swaps on TPIX Chain, and AI TRADE.',
            ],
            'tags' => ['เรียนรู้', 'TPIX TRADE', 'สอนเทรด', 'Swap', 'AI TRADE'],
            'chapters' => [
                [0, 'แนะนำ', 'Intro'],
                [6, 'หน้าหลัก', 'Home screen'],
                [14, 'ค้นหาเหรียญ', 'Find a coin'],
                [25, 'เซ็นยืนยันครั้งแรก', 'First-time sign-in'],
                [34, 'ดูกราฟราคา', 'Price chart'],
                [43, 'ซื้อ / ขาย', 'Buy / sell'],
                [53, 'ตรวจก่อนกดซื้อ', 'Check before you buy'],
                [60, 'สลับเหรียญ', 'Swap'],
                [71, 'แลกบน TPIX Chain', 'Swap on TPIX Chain'],
                [78, 'AI TRADE', 'AI TRADE'],
                [87, 'กระเป๋า', 'Wallet'],
                [96, 'ตั้งค่า', 'Settings'],
                [107, 'สรุป', 'Wrap-up'],
            ],
            'body' => [
                'th' => <<<'HTML'
<p>คลิปนี้พาดูทุกเมนูหลักของ TPIX TRADE ดูไปกดตามไปได้เลย การดูราคาและกดเข้าเมนูต่าง ๆ ไม่เสียเงิน ยังไม่ได้ติดตั้งแอป? ดู <a href="/blog/learn-install-tpix-apps-android">วิธีติดตั้งบน Android</a> ก่อน</p>
<h2>ดูตลาด</h2>
<ol>
<li><strong>หน้าหลัก</strong> — ด้านบนคือมูลค่ารวมในกระเป๋า เลื่อนลงเพื่อดูราคาตลาด</li>
<li><strong>ค้นหาเหรียญ</strong> — แตะ <strong>ตลาด</strong> ด้านล่าง แตะช่องค้นหา พิมพ์ชื่อเหรียญ เช่น ETH แล้วแตะเลือก</li>
<li><strong>เซ็นยืนยันครั้งแรก</strong> — TPIX Wallet จะขอลายเซ็นหนึ่งครั้ง <strong>ไม่เสียเงิน</strong> ตรวจว่าเป็น TPIX TRADE แล้วแตะ <strong>เซ็นชื่อ</strong></li>
<li><strong>กราฟราคา</strong> — แตะ 4H หรือ 1D เพื่อดูช่วงเวลาที่ยาวขึ้น</li>
</ol>
<h2>ซื้อขายและแลกเหรียญ</h2>
<ol>
<li>เลื่อนลงเพื่อซื้อขาย เลือก <strong>ซื้อ</strong> หรือ <strong>ขาย</strong> แล้วใส่จำนวน แอปจะบอกว่าคุณจะได้เท่าไร</li>
<li>กดซื้อเมื่อมั่นใจแล้วเท่านั้น — TPIX Wallet จะให้ยืนยันก่อนทุกครั้ง</li>
<li><strong>สลับ</strong> — แลกเหรียญหนึ่งเป็นอีกเหรียญ ใส่จำนวนแล้วเห็นราคาทันที</li>
<li><strong>TPIX Chain</strong> — แลกบนเชนของเรา <strong>ไม่มีค่าแก๊ส</strong></li>
</ol>
<h2>เมนูอื่น ๆ</h2>
<ul>
<li><strong>AI TRADE</strong> — ฝึกเทรดด้วยเงินทดลอง 10,000 ดอลลาร์ ฟรี (ยังอยู่ระหว่างทดสอบ)</li>
<li><strong>กระเป๋า</strong> — ดูเหรียญที่มีและประวัติการเทรด</li>
<li><strong>ตั้งค่า</strong> (รูปเฟือง) — เลือกธีมที่ชอบได้</li>
</ul>
<blockquote><strong>จำไว้:</strong> ตรวจให้ดีก่อนกดซื้อทุกครั้ง และห้ามบอกวลีกู้คืนกับใครเด็ดขาด คริปโตมีความเสี่ยงสูง ข้อมูลนี้ไม่ใช่คำแนะนำการลงทุน</blockquote>
HTML,
                'en' => <<<'HTML'
<p>This video walks through every main menu in TPIX TRADE. Follow along — looking at prices and opening menus is free. Not installed yet? Watch <a href="/blog/learn-install-tpix-apps-android-en">how to install on Android</a> first.</p>
<h2>Explore the market</h2>
<ol>
<li><strong>Home</strong> — your total value is at the top. Scroll down for market prices.</li>
<li><strong>Find a coin</strong> — tap <strong>Market</strong> at the bottom, tap the search box, type a coin such as ETH and tap it.</li>
<li><strong>First-time sign-in</strong> — TPIX Wallet asks you to sign once. <strong>It is free.</strong> Check that it says TPIX TRADE, then tap <strong>Sign</strong>.</li>
<li><strong>Price chart</strong> — tap 4H or 1D to see a longer time range.</li>
</ol>
<h2>Trade and swap</h2>
<ol>
<li>Scroll down to trade. Choose <strong>Buy</strong> or <strong>Sell</strong> and type the amount. The app shows what you will get.</li>
<li>Only tap Buy when you are sure — TPIX Wallet always asks you to confirm first.</li>
<li><strong>Swap</strong> changes one coin into another. Type the amount and see the price right away.</li>
<li><strong>TPIX Chain</strong> swaps on our own chain with <strong>no gas fee</strong>.</li>
</ol>
<h2>More menus</h2>
<ul>
<li><strong>AI TRADE</strong> — practise with 10,000 demo dollars for free (still in testing).</li>
<li><strong>Wallet</strong> — your coins and trade history.</li>
<li><strong>Settings</strong> (gear icon) — pick the theme you like.</li>
</ul>
<blockquote><strong>Remember:</strong> always check before you tap Buy, and never share your recovery phrase. Crypto is high risk; this is not investment advice.</blockquote>
HTML,
            ],
        ],
        [
            'code' => 'wallet-basics',
            'file' => 'tpix-wallet-basics.mp4',
            'poster' => 'tpix-wallet-basics.jpg',
            'duration' => 106,
            'slug' => ['th' => 'learn-tpix-wallet-basics', 'en' => 'learn-tpix-wallet-basics-en'],
            'title' => [
                'th' => 'สอนใช้ TPIX Wallet เบื้องต้น: รับ ส่ง แลก บริดจ์ และสำรองวลีกู้คืน (วิดีโอ)',
                'en' => 'TPIX Wallet basics: receive, send, swap, bridge and back up (video)',
            ],
            'summary' => [
                'th' => 'เรียนใช้ TPIX Wallet ทีละขั้น — ดูยอดเงินแต่ละเชน รับและส่งเหรียญอย่างปลอดภัย แลกเหรียญ บริดจ์ USDT มา TPIX Chain และสำรองวลีกู้คืน',
                'en' => 'Learn TPIX Wallet step by step — balances on each chain, receiving and sending safely, swaps, bridging USDT to TPIX Chain, and backing up your recovery phrase.',
            ],
            'tags' => ['เรียนรู้', 'TPIX Wallet', 'กระเป๋าคริปโต', 'บริดจ์', 'ความปลอดภัย'],
            'chapters' => [
                [0, 'แนะนำ', 'Intro'],
                [6, 'ยอดเงินและเชน', 'Balance and chains'],
                [15, 'รับเหรียญ', 'Receive'],
                [26, 'ส่งเหรียญ', 'Send'],
                [37, 'ตรวจก่อนส่ง', 'Check before you send'],
                [42, 'แลกเหรียญ', 'Swap'],
                [53, 'บริดจ์', 'Bridge'],
                [63, 'มาสเตอร์โหนด', 'Master nodes'],
                [75, 'กิจกรรม', 'Activity'],
                [81, 'กระเป๋าทั้งหมด', 'All wallets'],
                [88, 'ตั้งค่า + สำรองวลีกู้คืน', 'Settings + back up'],
                [98, 'สรุป', 'Wrap-up'],
            ],
            'body' => [
                'th' => <<<'HTML'
<p>TPIX Wallet คือที่เก็บเหรียญของคุณ คลิปนี้พาไปทีละขั้นแบบช้า ๆ ยังไม่ได้ติดตั้งแอป? ดู <a href="/blog/learn-install-tpix-apps-android">วิธีติดตั้งบน Android</a> ก่อน</p>
<h2>ดูยอดและรับเหรียญ</h2>
<ol>
<li><strong>ยอดเงิน</strong> — แตะชื่อเชน เช่น BNB เพื่อดูเหรียญบนเชนนั้น</li>
<li><strong>รับ</strong> — ให้อีกฝ่ายสแกน QR หรือแตะ <strong>คัดลอก</strong> แล้วส่งที่อยู่ให้ผู้โอน</li>
</ol>
<h2>ส่งเหรียญอย่างปลอดภัย</h2>
<ol>
<li>แตะ <strong>ส่ง</strong> ใส่จำนวน แล้ววางที่อยู่ปลายทาง</li>
<li><strong>ตรวจที่อยู่ให้ดีสองรอบเสมอ</strong> โอนผิดแล้วเอาคืนไม่ได้</li>
<li>แตะ <strong>ตรวจสอบก่อนส่ง</strong> แล้วยืนยันด้วย PIN</li>
</ol>
<h2>แลก บริดจ์ และโหนด</h2>
<ul>
<li><strong>แลก</strong> — เปลี่ยนเหรียญหนึ่งเป็นอีกเหรียญ ดูราคาให้ดีก่อนแลก</li>
<li><strong>บริดจ์</strong> — ย้าย USDT จาก BNB Chain มาที่ TPIX Chain <strong>ห้ามโอนตรงจากกระดานเทรด (exchange) เข้าบริดจ์เด็ดขาด</strong></li>
<li><strong>โหนด</strong> — ดูระดับมาสเตอร์โหนดและจำนวนที่ต้อง stake (ตอนนี้ปิดรับสมัครใหม่ชั่วคราว)</li>
<li><strong>กิจกรรม</strong> — ทุกรายการที่ส่งและรับ</li>
<li><strong>กระเป๋า</strong> — ดูกระเป๋าทั้งหมด หรือเพิ่มกระเป๋าใหม่</li>
</ul>
<h2>สำรองวลีกู้คืน (สำคัญที่สุด)</h2>
<p>ในตั้งค่า ให้สำรองวลีกู้คืน <strong>จดลงกระดาษ</strong> เก็บไว้ที่ปลอดภัย ห้ามถ่ายรูปหรือเก็บในแชท</p>
<blockquote><strong>ทีม TPIX จะไม่มีวันขอ PIN หรือวลีกู้คืนของคุณ</strong> ใครขอ = มิจฉาชีพ</blockquote>
HTML,
                'en' => <<<'HTML'
<p>TPIX Wallet is where your coins live, so this video goes slowly, one step at a time. Not installed yet? Watch <a href="/blog/learn-install-tpix-apps-android-en">how to install on Android</a> first.</p>
<h2>Balance and receiving</h2>
<ol>
<li><strong>Balance</strong> — tap a chain such as BNB to see the coins on that chain.</li>
<li><strong>Receive</strong> — let the sender scan the QR code, or tap <strong>Copy</strong> and send them your address.</li>
</ol>
<h2>Send safely</h2>
<ol>
<li>Tap <strong>Send</strong>, type the amount, then paste the address you are sending to.</li>
<li><strong>Always check the address twice.</strong> A transfer to the wrong address cannot be undone.</li>
<li>Tap <strong>Review</strong> and confirm with your PIN.</li>
</ol>
<h2>Swap, bridge and nodes</h2>
<ul>
<li><strong>Swap</strong> — change one coin into another. Check the price before you swap.</li>
<li><strong>Bridge</strong> — move USDT from BNB Chain to TPIX Chain. <strong>Never send to the bridge straight from an exchange.</strong></li>
<li><strong>Node</strong> — master node levels and how much you need to stake (new sign-ups are paused for now).</li>
<li><strong>Activity</strong> — everything you sent and received.</li>
<li><strong>Wallets</strong> — see all your wallets or add a new one.</li>
</ul>
<h2>Back up your recovery phrase (most important)</h2>
<p>In Settings, back up your recovery phrase. <strong>Write it on paper</strong> and keep it somewhere safe. Never photograph it or keep it in a chat.</p>
<blockquote><strong>TPIX staff will never ask for your PIN or recovery phrase.</strong> Anyone who asks is a scammer.</blockquote>
HTML,
            ],
        ],
    ],
];
