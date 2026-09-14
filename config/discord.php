<?php

/*
 * TPIX TRADE — บอท Discord ของชุมชน TPIX
 *
 * ค่าที่เจ้าของต้องกรอก (โทเค็นบอท / Application ID / Public Key / Guild ID)
 * อยู่ในหลังบ้าน /admin/discord — ไฟล์นี้เก็บแค่ค่าคงที่ของระบบ
 *
 * Developed by Xman Studio.
 */

return [
    'api_base' => 'https://discord.com/api/v10',

    'gateway_url' => 'wss://gateway.discord.gg/?v=10&encoding=json',

    /* Discord บังคับรูปแบบ User-Agent นี้ — ใช้ตัวอื่นอาจโดนบล็อก */
    'user_agent' => 'DiscordBot (https://tpix.online, 1.0)',

    'timeout' => 10,

    /*
     * บทบาทของห้อง — บอทใช้ชื่อห้อง (และชื่อหมวด) เดาว่าห้องไหนควรได้อะไร
     * แอดมินเปลี่ยนเองได้ที่หลังบ้าน ถ้าเดาผิด
     *
     * คำไทยจับแบบ "มีอยู่ในชื่อ" เพราะชื่อห้องภาษาไทยไม่มีเว้นวรรค (เช่น 📢ประกาศ-ทางการ)
     */
    'roles' => [
        'rules' => [
            'label' => 'กฎของชุมชน',
            'keywords' => ['กฎ', 'กติกา', 'rule', 'regulation', 'code-of-conduct'],
        ],
        'announcements' => [
            'label' => 'ประกาศทางการ',
            'keywords' => ['ประกาศ', 'announce', 'official'],
        ],
        'news' => [
            'label' => 'ข่าว / บทความ',
            'keywords' => ['ข่าว', 'news', 'บทความ', 'article', 'blog', 'update', 'อัปเดต', 'อัพเดท'],
        ],
        'videos' => [
            'label' => 'วิดีโอ',
            'keywords' => ['วิดีโอ', 'วีดีโอ', 'วิดิโอ', 'video', 'youtube', 'คลิป', 'media'],
        ],
        'whitepaper' => [
            'label' => 'Whitepaper / ข้อมูลโปรเจกต์',
            'keywords' => ['whitepaper', 'ไวท์เปเปอร์', 'เอกสาร', 'docs', 'document', 'roadmap', 'โรดแมพ', 'ข้อมูล', 'about', 'info'],
        ],
        'sale' => [
            'label' => 'การขายเหรียญ',
            'keywords' => ['ขายเหรียญ', 'ซื้อเหรียญ', 'token-sale', 'tokensale', 'presale', 'pre-sale', 'ico', 'ido', 'sale', 'ซื้อ', 'ขาย'],
        ],
        'ask' => [
            'label' => 'ถาม-ตอบ',
            'keywords' => ['ถาม', 'faq', 'help', 'support', 'ask', 'ช่วยเหลือ', 'q-a', 'qa'],
        ],
    ],

    /*
     * ห้องสำรองเมื่อหาห้องตรงบทบาทไม่เจอ — ไม่ถอยไปห้องแชททั่วไปโดยตั้งใจ
     * (โพสต์อัตโนมัติลงห้องคุยเล่นจะกลายเป็นสแปมในสายตาสมาชิก)
     */
    'fallbacks' => [
        'news' => ['announcements'],
        'sale' => ['announcements'],
        'whitepaper' => ['announcements'],
        'videos' => ['news', 'announcements'],
    ],

    /* ครั้งแรกที่เปิดบอท โพสต์บทความย้อนหลังกี่ชิ้น (ที่เว็บมีเกือบ 300 ชิ้น — ห้ามเทลงห้องทั้งหมด) */
    'article_backfill' => 5,

    /* โพสต์ข่าว/วิดีโอใหม่ได้ไม่เกินกี่ชิ้นต่อรอบ — กันชนเพดานความถี่ของ Discord */
    'max_new_posts_per_run' => 6,

    /* ภาษาบทความที่ส่งเข้า Discord (เว็บมีทั้งไทยและอังกฤษ) */
    'article_language' => 'th',

    /* ถามตอบด้วย /ถาม — กินโควตา OpenAI ก้อนเดียวกับทั้ง org (ดู ai:pull-pool-key) */
    'ask' => [
        'daily_cap' => 200,
        'per_user_per_hour' => 10,
        'max_question_length' => 500,
    ],

    /*
     * ซีรีส์วิดีโอ Whitepaper — ไฟล์อยู่ public_html/videos/whitepaper (อัปโหลดด้วยสคริปต์ ไม่อยู่ใน git)
     * ไฟล์ใหญ่เกินเพดานแนบของ Discord → โพสต์เป็นลิงก์ (Discord เล่นในห้องได้เอง)
     */
    'videos' => [
        ['code' => 'ep01', 'file' => 'ep01-foundation.mp4', 'title' => 'ตอนที่ 1 · รากฐานของ TPIX', 'part' => 'Part One — Foundation'],
        ['code' => 'ep02', 'file' => 'ep02-the-chain.mp4', 'title' => 'ตอนที่ 2 · เชน TPIX', 'part' => 'Part Two — The Chain'],
        ['code' => 'ep03', 'file' => 'ep03-economics.mp4', 'title' => 'ตอนที่ 3 · เศรษฐศาสตร์ของเหรียญ', 'part' => 'Part Three — Economics'],
        ['code' => 'ep04', 'file' => 'ep04-master-nodes.mp4', 'title' => 'ตอนที่ 4 · มาสเตอร์โหนด', 'part' => 'Part Four — Master Nodes'],
        ['code' => 'ep05', 'file' => 'ep05-the-trading-layer.mp4', 'title' => 'ตอนที่ 5 · ชั้นการเทรด', 'part' => 'Part Five — The Trading Layer'],
        ['code' => 'ep06', 'file' => 'ep06-ai-trade.mp4', 'title' => 'ตอนที่ 6 · AI TRADE', 'part' => 'Part Six — AI TRADE'],
        ['code' => 'ep07', 'file' => 'ep07-living-identity.mp4', 'title' => 'ตอนที่ 7 · Living Identity', 'part' => 'Part Seven — Living Identity'],
        ['code' => 'ep08', 'file' => 'ep08-real-world.mp4', 'title' => 'ตอนที่ 8 · ใช้งานในโลกจริง', 'part' => 'Part Eight — Real World'],
        ['code' => 'ep09', 'file' => 'ep09-the-road-ahead.mp4', 'title' => 'ตอนที่ 9 · เส้นทางข้างหน้า', 'part' => 'Part Nine — The Road Ahead'],
    ],

    /* กฎตั้งต้น — แอดมินแก้ได้ที่หลังบ้าน (บอทแก้ข้อความในห้องตามให้เอง) */
    'default_rules' => <<<'RULES'
1. เคารพกันและกัน — ห้ามด่าทอ เหยียด คุกคาม หรือยุยงให้เกลียดชัง
2. ห้ามสแปม ห้ามโฆษณาหรือชวนลงทุนโปรเจกต์อื่น ห้ามส่งลิงก์แปลกปลอม
3. 🚨 ทีมงานจะ **ไม่มีวัน** DM ขอ seed phrase / private key / รหัสผ่าน หรือให้โอนเงินก่อน — ใครทักแบบนี้คือมิจฉาชีพ แจ้งแอดมินทันที
4. ซื้อเหรียญ TPIX ผ่าน https://tpix.online/token-sale เท่านั้น ระวังเว็บปลอมและบัญชีปลอมที่ใช้ชื่อทีมงาน
5. ข้อมูลในเซิร์ฟเวอร์นี้ไม่ใช่คำแนะนำการลงทุน คริปโตมีความเสี่ยงสูง ศึกษาข้อมูลก่อนตัดสินใจทุกครั้ง
6. ใช้ห้องให้ตรงหัวข้อ — มีคำถามพิมพ์ /ถาม ให้ผู้ช่วย AI ตอบได้ตลอด 24 ชั่วโมง
7. ห้ามเผยแพร่ข้อมูลส่วนตัวของผู้อื่น
8. ทำผิดกฎ แอดมินอาจลบข้อความ ปิดเสียง หรือแบนได้โดยไม่ต้องแจ้งล่วงหน้า
RULES,
];
