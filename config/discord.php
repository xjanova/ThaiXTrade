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

    /*
    |--------------------------------------------------------------------------
    | ดูแลห้องอัตโนมัติ (เจ้าของ: "แบน เตะ คนได้หากมีแนวโน้มไม่ดี")
    |--------------------------------------------------------------------------
    | ชั้นที่ 1 — AutoMod ของ Discord (ทำงานบนฝั่ง Discord 24 ชม. ไม่ต้องให้บอทออนไลน์)
    |            บล็อกข้อความทันที + ปิดเสียงชั่วคราวสำหรับเคสร้ายแรง + แจ้งเตือนเข้าห้องแอดมิน
    | ชั้นที่ 2 — discord:moderate ทุก 5 นาที อ่าน audit log ของ AutoMod แล้วไล่ระดับโทษตามคะแนน
    |
    | ⚠️ คำที่ใส่ในกฎต้อง "จับคนหลอก ไม่จับคนเตือน": ห้ามใส่ "private key" / "seed phrase" เฉย ๆ
    |    เพราะคนดีพิมพ์ "อย่าบอก seed phrase ใคร" บ่อยกว่าคนหลอก — ใส่เฉพาะประโยคที่ขอ/ชวนส่ง
    | ⚠️ คำไทยไม่มีเว้นวรรค ต้องใช้ *คำ* (จับกลางประโยค) — และห้ามใส่คำที่เป็นส่วนของคำปกติ
    |    ("สัด" อยู่ใน "สัดส่วน", "หี" อยู่ใน "หีบห่อ") ไม่งั้นบล็อกคนคุยเรื่องโทเคโนมิกส์
    */
    'moderation' => [
        'window_days' => 7,

        /* คะแนนสะสมในช่วง window_days → ระดับโทษ */
        'timeout_at' => 3,
        'timeout_minutes' => 24 * 60,
        'kick_at' => 6,
        'ban_at' => 10,

        /* กันกฎทำงานผิดแล้วลงโทษคนดีรัว ๆ — เกินเพดาน = แจ้งเตือนแอดมินแทน */
        'max_bans_per_day' => 5,
        'max_kicks_per_day' => 10,

        /* ยศที่มีสิทธิ์พวกนี้ = ทีมงาน ไม่ถูกลงโทษอัตโนมัติและไม่โดน AutoMod ของเรา */
        'staff_permissions' => (1 << 3) | (1 << 5) | (1 << 13) | (1 << 40), // Administrator · Manage Server · Manage Messages · Moderate Members

        /*
         * กฎ AutoMod ที่บอทติดตั้ง — ชื่อขึ้นต้น "TPIX •" เสมอ (ใช้หาของเราเจอ และไม่แตะกฎที่แอดมินตั้งเอง)
         * weight = คะแนนความผิดต่อหนึ่งครั้ง · timeout_seconds = ให้ AutoMod ปิดเสียงทันที (ใช้ได้กับ keyword/mention เท่านั้น)
         */
        'rules' => [
            'scam' => [
                'name' => 'TPIX • กันมิจฉาชีพ',
                'trigger_type' => 1,
                'weight' => 5,
                'timeout_seconds' => 3600,
                'block_message' => 'ข้อความถูกบล็อก: เข้าข่ายหลอกขอข้อมูลกระเป๋า/ชวนรับของฟรี — ทีมงาน TPIX ไม่มีวันขอ seed phrase',
                'keywords' => [
                    '*free nitro*', '*nitro giveaway*', '*free discord nitro*', '*steam gift card*',
                    '*claim your airdrop*', '*claim airdrop now*', '*airdrop is live*', '*validate your wallet*', '*wallet validation*',
                    '*rectify your wallet*', '*sync your wallet*', '*connect your wallet to claim*',
                    '*send me your seed*', '*dm me your seed*', '*send your seed phrase*', '*enter your seed phrase*', '*share your seed phrase with*',
                    '*send me your private key*', '*dm me for support*', '*check your dm*', '*i will double your*', '*guaranteed profit*',
                    '*ส่งseedมา*', '*ส่ง seed มา*', '*ขอseed*', '*ขอ seed*', '*ส่งวลีกู้คืน*', '*ขอวลีกู้คืน*', '*ส่งคีย์ส่วนตัว*', '*ขอคีย์ส่วนตัว*',
                    '*ยืนยันกระเป๋าเพื่อรับ*', '*ซิงค์กระเป๋า*', '*เคลมแอร์ดรอป*', '*รับแอร์ดรอปฟรี*', '*แจกเหรียญฟรี*', '*แจก nitro*',
                    '*ทักแชทส่วนตัว*', '*ทักinbox*', '*ทัก inbox*', '*การันตีกำไร*', '*กำไรการันตี*', '*ลงทุนน้อยได้เยอะ*', '*ปันผลรายวัน*',
                ],
                // โดเมนสะกดเลียน Discord/Steam ที่ใช้หลอกแจก nitro (ไม่จับ discord.com / discord.gg ของจริง)
                'regex' => [
                    '(?i)\b(?:dlscord|disc0rd|d1scord|discorcl|dicsord|discrod|disocrd|discorb)\.[a-z]{2,}',
                    '(?i)\b(?:discord|nitro)-(?:gift|nitro|drop|promo|airdrop)[a-z0-9-]*\.[a-z]{2,}',
                    '(?i)\bsteamcommun[1il]ty[a-z0-9-]*\.[a-z]{2,}',
                ],
            ],
            'invite' => [
                'name' => 'TPIX • ลิงก์เชิญเซิร์ฟเวอร์อื่น',
                'trigger_type' => 1,
                'weight' => 2,
                'block_message' => 'ห้ามโพสต์ลิงก์เชิญเข้าเซิร์ฟเวอร์อื่นในห้องนี้',
                'keywords' => [],
                'regex' => ['(?i)(?:discord\.gg|discord(?:app)?\.com/invite)/[a-z0-9-]+'],
            ],
            'profanity_th' => [
                'name' => 'TPIX • คำหยาบภาษาไทย',
                'trigger_type' => 1,
                'weight' => 1,
                'block_message' => 'ข้อความถูกบล็อก: มีคำหยาบ — คุยกันสุภาพนะครับ',
                'keywords' => [
                    '*เหี้ย*', '*สัส*', '*ควย*', '*เย็ดแม่*', '*แม่มึงตาย*', '*พ่อมึงตาย*', '*อีดอก*',
                    '*ไอ้สัตว์*', '*ไอสัตว์*', '*ส้นตีน*', '*ระยำ*',
                ],
                'regex' => [],
            ],
            'profanity_preset' => [
                'name' => 'TPIX • คำไม่เหมาะสม (อังกฤษ)',
                'trigger_type' => 4, // KEYWORD_PRESET — มีได้กฎเดียวต่อเซิร์ฟเวอร์
                'weight' => 1,
                'presets' => [1, 2, 3], // profanity · sexual content · slurs
                'block_message' => 'ข้อความถูกบล็อก: มีคำไม่เหมาะสม',
            ],
            'mention_spam' => [
                'name' => 'TPIX • แท็กคนรัว',
                'trigger_type' => 5, // MENTION_SPAM
                'weight' => 3,
                'timeout_seconds' => 3600,
                'mention_limit' => 5,
                'block_message' => 'ข้อความถูกบล็อก: แท็กคนมากเกินไป',
            ],
            'spam' => [
                'name' => 'TPIX • สแปม',
                'trigger_type' => 3, // SPAM (ตัวจับสแปมของ Discord) — มีได้กฎเดียวต่อเซิร์ฟเวอร์
                'weight' => 2,
            ],
        ],
    ],
];
