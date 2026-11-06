<?php

use App\Models\CreatorRegistration;
use App\Support\LaunchpadRules as R;

/*
 * TPIX TRADE — ข้อตกลงและข้อกำหนดการใช้บริการสำหรับผู้สร้างเหรียญ ฉบับ 2026-11-06.2
 *
 * ต่างจากฉบับ 2026-11-06:
 *   - หน้าที่และข้อผูกพันของผู้สร้างครบ 10 หมวด (เจ้าของ: "ในเอกสารการสมัครต้องระบุหน้าที่ ขอ้ผูกพันธ์ให้ครบนะ")
 *   - กติกากันลักพูลชั้นที่ 1 (บนเชน) และชั้นที่ 2 (บนเว็บ) ทุกข้อ (เจ้าของ: "พวกกติกาพวกนี้อยู่ในเอกสารที่ให้ผู้สร้างเซ็นครบนะ")
 *   - เอกสารประกอบ การเผยแพร่แบบมีลายน้ำ ความยินยอมทีละรายการ และไวท์เปเปอร์มาตรฐาน
 *   - ตัวเลขกติกาบนเชนมาจาก App\Support\LaunchpadRules (สำเนาค่าคงที่ของสัญญา) — แก้ค่าที่นั่นแล้วไฟล์นี้
 *     โหลดไม่ผ่านเพราะแฮชไม่ตรง จึงต้องออกฉบับใหม่ ไม่ใช่เปลี่ยนตัวเลขในฉบับที่เซ็นแล้วเงียบๆ
 *
 * ห้ามเขียนว่าแพลตฟอร์มยุติ หยุด อายัด หรือยึดเหรียญ/LP/vesting บนเชนได้ — สัญญาบนเชนไม่มีสิทธิ์นั้น
 *
 * ⚠️ ห้ามแก้ไฟล์นี้หลังเผยแพร่ — ผู้สร้างเซ็น hash ของเนื้อหาทั้งไฟล์ไว้แล้ว
 *    ต้องการแก้ = สร้างไฟล์ฉบับใหม่ แล้วเพิ่มใน CreatorAgreement::VERSIONS
 *
 * Developed by Xman Studio.
 */

$lpDays = R::MIN_LP_LOCK_DAYS;
$poolPct = R::percent(R::MIN_POOL_SHARE_BPS);
$lockedPct = R::percent(R::MIN_LOCKED_SUPPLY_BPS);
$freePct = R::maxUnlockedPercent();
$band = R::percent(R::DEFAULT_BAND_BPS);
$starterDefault = R::percent(CreatorRegistration::DEFAULT_STARTER_BPS);
$starterMax = R::percent(CreatorRegistration::MAX_STARTER_BPS);
$ref = R::CONTRACT_REF;

return [
    'version' => '2026-11-06.2',
    'title_th' => 'ข้อตกลงและข้อกำหนดการใช้บริการสำหรับผู้สร้างเหรียญ TPIX TRADE',
    'title_en' => 'TPIX TRADE Token Creator Agreement and Terms of Service',

    'draft_notice_th' => 'สถานะเอกสาร: ร่างเพื่อการใช้งานเบื้องต้น — ผู้ให้บริการจะให้ที่ปรึกษากฎหมายตรวจทานเอกสารนี้ก่อนใช้เป็นสัญญาฉบับสมบูรณ์ โดยเฉพาะประเด็นตามพระราชกำหนดการประกอบธุรกิจสินทรัพย์ดิจิทัล พ.ศ. 2561 และประกาศของสำนักงานคณะกรรมการกำกับหลักทรัพย์และตลาดหลักทรัพย์ (ก.ล.ต.) รวมถึงการระบุชื่อ เลขทะเบียน และที่อยู่ของนิติบุคคลผู้ให้บริการ หากมีการแก้ไข ผู้ให้บริการจะออกฉบับใหม่และขอให้ผู้สร้างลงนามใหม่',
    'draft_notice_en' => 'Document status: preliminary draft — the Provider will have this document reviewed by legal counsel before it is used as a final contract, in particular regarding the Emergency Decree on Digital Asset Businesses B.E. 2561 (2018) and the notifications of the Thai Securities and Exchange Commission (SEC), and to state the name, registration number and address of the Provider\'s legal entity. If amended, the Provider will issue a new version and ask the Creator to sign again.',

    'preamble_th' => 'ข้อตกลงนี้ทำขึ้นระหว่างผู้ให้บริการแพลตฟอร์ม TPIX TRADE (tpix.online) ซึ่งพัฒนาและดำเนินการโดย Xman Studio ("ผู้ให้บริการ" หรือ "แพลตฟอร์ม") กับบุคคลธรรมดาหรือนิติบุคคลที่ลงทะเบียนเป็นผู้สร้างเหรียญตามข้อมูลในส่วนที่ 1 ของเอกสารนี้ ("ผู้สร้าง") ผู้สร้างที่เป็นบุคคลธรรมดาไม่จำเป็นต้องมีนิติบุคคล ผู้สร้างต้องอ่านข้อตกลงนี้ทั้งฉบับก่อนลงนาม การลงนามด้วยกระเป๋าดิจิทัลถือว่าผู้สร้างเข้าใจและตกลงผูกพันตามหน้าที่และข้อผูกพันในข้อตกลงนี้ทุกประการ',
    'preamble_en' => 'This Agreement is made between the operator of the TPIX TRADE platform (tpix.online), developed and operated by Xman Studio (the "Provider" or the "Platform"), and the individual or legal entity registering as a token creator as identified in Part 1 of this document (the "Creator"). An individual Creator does not need to have a company. The Creator must read the whole Agreement before signing. Signing with a digital wallet means the Creator understands and agrees to be bound by every duty and obligation in this Agreement.',

    'sections' => [
        [
            'id' => 'definitions',
            'title_th' => 'คำนิยาม',
            'title_en' => 'Definitions',
            'clauses' => [
                ['th' => '"เหรียญลูก" หมายถึง โทเคนดิจิทัลที่ผู้สร้างออกบน TPIX Chain ผ่านระบบสร้างเหรียญ (Token Factory) ของแพลตฟอร์ม และ "TPIX Chain" หมายถึง บล็อกเชนหมายเลขเครือข่าย 4289 ซึ่งมี TPIX เป็นเหรียญหลัก',
                    'en' => '"Child Token" means a digital token the Creator issues on TPIX Chain through the Platform\'s Token Factory, and "TPIX Chain" means the blockchain with network ID 4289 whose native coin is TPIX.'],
                ['th' => '"Launchpad" หมายถึง หน้าขายเหรียญลูกของแพลตฟอร์มและชุดสัญญาอัจฉริยะ LaunchpadFactory, FixedPriceSale (สัญญาขายราคาคงที่), PoolFeeder (สัญญาเติมสภาพคล่อง) และ TrancheVesting (สัญญาล็อกเหรียญ) ตามที่อ้างอิงในข้อ 12',
                    'en' => '"Launchpad" means the Platform\'s Child Token sale page and the LaunchpadFactory, FixedPriceSale (fixed-price sale), PoolFeeder (liquidity feeder) and TrancheVesting (token lock) smart contracts referred to in clause 12.'],
                ['th' => '"กระเป๋าที่ลงทะเบียน" หมายถึง ที่อยู่กระเป๋าดิจิทัลที่ผู้สร้างใช้ลงนามเข้าสู่การลงทะเบียน ซึ่งเป็นกระเป๋ารับเหรียญลูกส่วนเริ่มโครงการและเป็นตัวแทนของผู้สร้างในระบบ',
                    'en' => '"Registered Wallet" means the digital wallet address the Creator signed into the registration with; it receives the Child Token starter allocation and represents the Creator in the system.'],
                ['th' => '"ส่วนเริ่มโครงการ" หมายถึง สัดส่วนของซัพพลายเหรียญลูกที่แพลตฟอร์มอนุมัติให้ผลิตเข้ากระเป๋าที่ลงทะเบียนโดยไม่ล็อก เพื่อใช้เริ่มดำเนินโครงการ',
                    'en' => '"Starter Allocation" means the share of the Child Token supply that the Platform approves to be minted, unlocked, to the Registered Wallet to start the project.'],
                ['th' => '"เอกสารประกอบ" หมายถึง ไฟล์ที่ผู้สร้างแนบกับใบลงทะเบียน เช่น หนังสือรับรองบริษัท ใบทะเบียนภาษีมูลค่าเพิ่ม (ภ.พ.20) บัญชีรายชื่อผู้ถือหุ้น รูปภาพโครงการ และสำเนาบัตรของผู้มีอำนาจลงนาม',
                    'en' => '"Supporting Documents" means files the Creator attaches to the registration, such as the company certificate, VAT registration (Por Por 20), shareholder list, project photos and the authorised signatory\'s ID.'],
                ['th' => '"หน้าโครงการสาธารณะ" หมายถึง หน้าบน tpix.online ที่แสดงข้อมูลโครงการที่ผู้สร้างยินยอมเผยแพร่ และ "ไวท์เปเปอร์มาตรฐาน" หมายถึง เอกสารสองภาษาที่แพลตฟอร์มจัดทำจากข้อมูลบนเชนของเหรียญลูกและข้อมูลที่ผู้สร้างให้ไว้',
                    'en' => '"Public Project Page" means the page on tpix.online showing the project information the Creator consents to publish, and "Standard Whitepaper" means the bilingual document the Platform generates from the Child Token\'s on-chain data and the information the Creator provided.'],
                ['th' => '"ผู้ซื้อ" หมายถึง บุคคลใดที่ซื้อหรือถือเหรียญลูกของผู้สร้าง',
                    'en' => '"Buyer" means any person who buys or holds the Creator\'s Child Token.'],
            ],
        ],
        [
            'id' => 'acceptance',
            'title_th' => 'การยอมรับข้อตกลงและการลงลายมือชื่ออิเล็กทรอนิกส์',
            'title_en' => 'Acceptance and electronic signature',
            'clauses' => [
                ['th' => 'ผู้สร้างตกลงว่าการลงนามข้อความด้วยกุญแจของกระเป๋าที่ลงทะเบียน (มาตรฐาน EIP-191 personal_sign) เป็นลายมือชื่ออิเล็กทรอนิกส์ตามพระราชบัญญัติว่าด้วยธุรกรรมทางอิเล็กทรอนิกส์ พ.ศ. 2544 และมีผลผูกพันเช่นเดียวกับการลงลายมือชื่อในเอกสาร',
                    'en' => 'The Creator agrees that signing a message with the key of the Registered Wallet (EIP-191 personal_sign) is an electronic signature under the Electronic Transactions Act B.E. 2544 (2001) and binds the Creator as a handwritten signature would.'],
                ['th' => 'การลงนามไม่ส่งธุรกรรมและไม่มีค่าใช้จ่าย แพลตฟอร์มจะไม่ขอ private key หรือวลีกู้คืน (seed phrase) ของผู้สร้างไม่ว่ากรณีใด ผู้ใดขอข้อมูลดังกล่าวในนามแพลตฟอร์มถือเป็นการหลอกลวง',
                    'en' => 'Signing sends no transaction and costs nothing. The Platform will never ask for the Creator\'s private key or seed phrase; anyone asking for them in the Platform\'s name is a scammer.'],
            ],
        ],
        [
            'id' => 'eligibility',
            'title_th' => 'คุณสมบัติของผู้สร้าง การยืนยันตัวตน และเอกสารประกอบ',
            'title_en' => 'Creator eligibility, identity verification and supporting documents',
            'clauses' => [
                ['th' => 'ผู้สร้างที่เป็นบุคคลธรรมดาต้องอายุ 20 ปีบริบูรณ์ขึ้นไปและมีความสามารถทำนิติกรรม โดยไม่ต้องมีนิติบุคคล ผู้สร้างที่เป็นนิติบุคคลต้องจดทะเบียนถูกต้องตามกฎหมาย ผู้ลงนามต้องมีอำนาจกระทำการแทน และต้องแนบหนังสือรับรองบริษัทก่อนลงนาม',
                    'en' => 'An individual Creator must be at least 20 years old with full legal capacity and does not need a company. A corporate Creator must be lawfully registered, the signer must have authority to act for it, and the company certificate must be attached before signing.'],
                ['th' => 'ผู้สร้างต้องยืนยันตัวตน (KYC) ผ่านแอป Thaiprompt ก่อนลงนาม แพลตฟอร์มรับเฉพาะผลการยืนยันจาก Thaiprompt และอาจขอเอกสารเพิ่มเติมเมื่อมีเหตุอันควร',
                    'en' => 'The Creator must complete identity verification (KYC) in the Thaiprompt app before signing. The Platform relies only on Thaiprompt\'s verification result and may request further documents where reasonable.'],
                ['th' => 'ผู้สร้างรับรองว่าตนเอง ผู้ถือหุ้นรายใหญ่ และผู้มีอำนาจควบคุม ไม่อยู่ในรายชื่อบุคคลที่ถูกกำหนดหรือรายชื่อคว่ำบาตรตามกฎหมายไทยหรือมติคณะมนตรีความมั่นคงแห่งสหประชาชาติ',
                    'en' => 'The Creator declares that neither it nor its major shareholders or controlling persons are on any designated-person or sanctions list under Thai law or United Nations Security Council resolutions.'],
                ['th' => 'หนึ่งกระเป๋าลงทะเบียนได้หนึ่งใบ ผู้สร้างรับผิดชอบรักษากุญแจของกระเป๋าที่ลงทะเบียนแต่เพียงผู้เดียว การกระทำใดที่ลงนามด้วยกระเป๋านี้ถือเป็นการกระทำของผู้สร้าง',
                    'en' => 'One wallet may hold one registration. The Creator alone is responsible for keeping the Registered Wallet\'s key safe; anything signed with it is the Creator\'s act.'],
            ],
        ],
        [
            'id' => 'information',
            'title_th' => 'หน้าที่ที่ 1 — ข้อมูลและการรับรอง',
            'title_en' => 'Duty 1 — Information and warranties',
            'clauses' => [
                ['th' => 'ผู้สร้างรับรองว่าข้อมูลตัวตน ข้อมูลโครงการ และเอกสารประกอบทุกฉบับเป็นความจริง ครบถ้วน เป็นปัจจุบัน และไม่ทำให้สำคัญผิด และเอกสารประกอบเป็นสำเนาที่ถูกต้องของเอกสารจริง',
                    'en' => 'The Creator warrants that its identity data, project data and every Supporting Document are true, complete, current and not misleading, and that each Supporting Document is a true copy of the original.'],
                ['th' => 'หากข้อมูลสำคัญเปลี่ยนแปลง เช่น ผู้มีอำนาจลงนาม ผู้ถือหุ้นรายใหญ่ ที่อยู่ หรือแผนโครงการ ผู้สร้างต้องแจ้งแพลตฟอร์มภายใน 7 วัน',
                    'en' => 'If material information changes — such as the authorised signatory, major shareholders, address or project plan — the Creator must notify the Platform within 7 days.'],
                ['th' => 'ผู้สร้างรับผิดชอบเนื้อหาไวท์เปเปอร์ เว็บไซต์ คำโฆษณา และการสื่อสารทุกช่องทางของโครงการ รวมถึงข้อมูลที่ผู้สร้างให้ไว้ซึ่งปรากฏในไวท์เปเปอร์มาตรฐาน ส่วนข้อมูลบนเชนในไวท์เปเปอร์มาตรฐานแพลตฟอร์มอ่านจากสัญญาโดยตรง',
                    'en' => 'The Creator is responsible for the content of its whitepaper, website, advertising and all project communications, including the Creator-supplied information shown in the Standard Whitepaper; the on-chain facts in the Standard Whitepaper are read by the Platform directly from the contract.'],
            ],
        ],
        [
            'id' => 'buyer-duties',
            'title_th' => 'หน้าที่ที่ 2 — หน้าที่ต่อผู้ซื้อ',
            'title_en' => 'Duty 2 — Duties to buyers',
            'clauses' => [
                ['th' => 'ผู้สร้างต้องดำเนินโครงการตามแผนที่ประกาศ ได้แก่ แผนการใช้เงิน ตาราง vesting และการล็อก LP และต้องประกาศให้ผู้ซื้อทราบล่วงหน้าก่อนเปลี่ยนแผนที่เป็นสาระสำคัญ',
                    'en' => 'The Creator must run the project according to the published plan — use of funds, vesting schedule and LP lock — and must announce any material change to buyers in advance.'],
                ['th' => 'ผู้สร้างต้องประกาศต่อสาธารณะล่วงหน้าอย่างน้อย 3 วันก่อนขายเหรียญลูกที่ตนถือ (รวมส่วนเริ่มโครงการและเหรียญที่ปลดจาก vesting) ในปริมาณเกินร้อยละ 1 ของซัพพลายภายใน 30 วัน',
                    'en' => 'The Creator must publicly announce, at least 3 days in advance, any sale of Child Tokens it holds (including the Starter Allocation and tokens released from vesting) exceeding 1% of the supply within 30 days.'],
                ['th' => 'ผู้สร้างต้องแจ้งความเสี่ยงตามจริง ไม่ทำให้เข้าใจผิดว่ารับประกันผลตอบแทนหรือราคา คงเว็บไซต์และเอกสารโครงการให้เข้าถึงได้อย่างน้อยจนถึงวันปลดล็อก LP และแจ้งเหตุการณ์สำคัญที่กระทบผู้ซื้อ เช่น ทีมงานเปลี่ยน โครงการหยุด หรือกระเป๋าถูกเจาะ ภายใน 3 วัน',
                    'en' => 'The Creator must state the risks truthfully, never imply a guaranteed return or price, keep its website and project documents available at least until the LP unlock date, and disclose events affecting buyers — such as team changes, project suspension or a wallet compromise — within 3 days.'],
                ['th' => 'ผู้สร้างต้องตอบคำถามหรือคำขอเอกสารของแพลตฟอร์มภายใน 7 วัน และต้องไม่ลบ บิดเบือน หรือขัดแย้งกับคำเตือนความเสี่ยงและข้อมูลบนเชนที่แพลตฟอร์มแสดง',
                    'en' => 'The Creator must answer the Platform\'s questions or document requests within 7 days and must not remove, distort or contradict the risk warnings and on-chain facts the Platform displays.'],
            ],
        ],
        [
            'id' => 'starter-allocation',
            'title_th' => 'ส่วนเริ่มโครงการ',
            'title_en' => 'Starter allocation',
            'clauses' => [
                ['th' => "เมื่ออนุมัติ แพลตฟอร์มกำหนดสัดส่วนส่วนเริ่มโครงการ (ค่าตั้งต้นร้อยละ {$starterDefault} และไม่เกินร้อยละ {$starterMax} ของซัพพลาย) ส่วนนี้ผลิตเข้ากระเป๋าที่ลงทะเบียนโดยตรงในขั้นตอนสร้างเหรียญลูก แพลตฟอร์มไม่ถือหรือเก็บรักษาเหรียญลูกแทนผู้สร้าง",
                    'en' => "On approval the Platform sets the Starter Allocation (default {$starterDefault}% and at most {$starterMax}% of the supply). It is minted directly to the Registered Wallet when the Child Token is created; the Platform never holds or keeps Child Tokens for the Creator."],
                ['th' => 'ผู้สร้างต้องเปิดเผยจำนวนส่วนเริ่มโครงการและแผนการใช้ให้ผู้ซื้อทราบ และใช้ตามแผนที่เปิดเผย การเทขายส่วนนี้ขัดกับแผนหรือโดยไม่ประกาศตามข้อ 5.2 ถือเป็นการผิดข้อตกลง',
                    'en' => 'The Creator must disclose the Starter Allocation and its intended use to buyers and use it as disclosed. Dumping it contrary to the plan or without the notice in clause 5.2 is a breach of this Agreement.'],
            ],
        ],
        [
            'id' => 'prohibited',
            'title_th' => 'หน้าที่ที่ 3 — ข้อห้าม',
            'title_en' => 'Duty 3 — Prohibited conduct',
            'clauses' => [
                ['th' => 'ห้ามปั่นราคา ซื้อขายกับตนเองหรือกลุ่มของตนเพื่อสร้างปริมาณเทียม (wash trading) หรือบิดเบือนตลาดในรูปแบบใด',
                    'en' => 'No price manipulation, wash trading with oneself or one\'s group to fake volume, or any other market distortion.'],
                ['th' => 'ห้ามลักพูล (rug pull) ได้แก่ การถอนสภาพคล่อง เทขาย หรือย้ายเงินของโครงการในลักษณะที่ขัดกับแผนที่ประกาศและทำให้ผู้ซื้อเสียหาย และห้ามหลอกลวง ฉ้อโกง โฆษณาเกินจริง หรือดำเนินการในลักษณะแชร์ลูกโซ่',
                    'en' => 'No rug pull — withdrawing liquidity, dumping, or moving project funds contrary to the published plan to the buyers\' harm — and no deception, fraud, exaggerated advertising or Ponzi-style schemes.'],
                ['th' => 'ห้ามใช้ชื่อ สัญลักษณ์ หรือแบรนด์ที่ซ้ำหรือเลียนแบบเหรียญลูก บุคคล องค์กร หรือแบรนด์อื่น และห้ามละเมิดทรัพย์สินทางปัญญา',
                    'en' => 'No name, symbol or brand that copies or imitates another Child Token, person, organisation or brand, and no infringement of intellectual property.'],
                ['th' => 'ห้ามฟอกเงิน สนับสนุนการก่อการร้าย หรือหลีกเลี่ยงมาตรการคว่ำบาตร ตามพระราชบัญญัติป้องกันและปราบปรามการฟอกเงิน พ.ศ. 2542 และกฎหมายที่เกี่ยวข้อง',
                    'en' => 'No money laundering, terrorist financing or sanctions evasion under the Anti-Money Laundering Act B.E. 2542 (1999) and related laws.'],
                ['th' => 'ห้ามระดมทุนหรือเสนอขายเหรียญลูกที่เข้าข่ายหลักทรัพย์หรือโทเคนดิจิทัลเพื่อการลงทุนต่อประชาชนโดยไม่ได้รับอนุญาตตามกฎหมาย',
                    'en' => 'No fundraising or public offering of a Child Token that qualifies as a security or investment token without the licence the law requires.'],
            ],
        ],
        [
            'id' => 'fees',
            'title_th' => 'หน้าที่ที่ 4 — ค่าบริการ',
            'title_en' => 'Duty 4 — Service fees',
            'clauses' => [
                ['th' => 'ผู้สร้างชำระค่าบริการเป็นเหรียญ TPIX ครบก่อนเริ่มบริการ ตามรายการและอัตราที่แพลตฟอร์มแสดงแยกตามตัวเลือก ณ เวลาสั่งบริการ ได้แก่ ค่าสร้างเหรียญ และค่าตัวเลือกเสริม เช่น หน้าขาย การรับชำระ USDT จาก BSC การเติมพูลอัตโนมัติ การล็อกเหรียญ (vesting) และการยืนยันซอร์สโค้ดสัญญา',
                    'en' => 'The Creator pays service fees in TPIX in full before the service starts, at the itemised per-option rates the Platform shows at the time of ordering — token creation and add-ons such as a sale page, USDT-on-BSC payments, automatic pool feeding, vesting and contract source verification.'],
                ['th' => 'หากแพลตฟอร์มปฏิเสธคำขอก่อนเริ่มดำเนินการ แพลตฟอร์มคืนค่าบริการเต็มจำนวนที่ได้รับจริงไปยังกระเป๋าที่ชำระ บริการที่ดำเนินการแล้วไม่คืนเงิน ค่าแก๊ส ค่าธรรมเนียมเครือข่ายบนเชนอื่น และภาษีของผู้สร้างเป็นความรับผิดชอบของผู้สร้าง',
                    'en' => 'If the Platform rejects a request before work starts, it refunds the full fee actually received to the paying wallet. Services already performed are not refunded. Gas, other-chain network fees and the Creator\'s own taxes are the Creator\'s responsibility.'],
            ],
        ],
        [
            'id' => 'liability',
            'title_th' => 'หน้าที่ที่ 5 — ความรับผิดและการชดใช้',
            'title_en' => 'Duty 5 — Liability and indemnity',
            'clauses' => [
                ['th' => 'ผู้สร้างรับผิดต่อความเสียหายที่เกิดจากการกระทำหรือการละเว้นของตน ทีมงาน และผู้ที่ทำการแทนตน',
                    'en' => 'The Creator is liable for damage caused by its own acts or omissions and those of its team and anyone acting for it.'],
                ['th' => 'ผู้สร้างตกลงชดใช้และปกป้องแพลตฟอร์มจากข้อเรียกร้อง ความเสียหาย ค่าปรับ และค่าใช้จ่าย (รวมค่าทนายความตามสมควร) ที่เกิดจากการผิดข้อตกลง โครงการ หรือเหรียญลูกของผู้สร้าง รวมถึงข้อเรียกร้องของผู้ซื้อและหน่วยงานรัฐ',
                    'en' => 'The Creator indemnifies and holds the Platform harmless from claims, damages, fines and costs (including reasonable legal fees) arising from its breach, its project or its Child Token, including claims by buyers and authorities.'],
                ['th' => 'ภายในขอบเขตที่กฎหมายอนุญาต แพลตฟอร์มไม่ต้องรับผิดต่อความเสียหายจากข้อบกพร่องของสัญญาอัจฉริยะหรือบล็อกเชน กระเป๋าของผู้สร้างถูกเจาะ การตั้งค่าที่ผู้สร้างเลือกเอง ความผันผวนของตลาด หรือเหตุสุดวิสัย และความรับผิดรวมของแพลตฟอร์มไม่เกินค่าบริการที่ผู้สร้างชำระในรอบ 12 เดือนก่อนเกิดเหตุ',
                    'en' => 'To the extent the law allows, the Platform is not liable for losses from smart-contract or blockchain defects, a compromise of the Creator\'s wallet, settings the Creator chose, market volatility or force majeure, and its total liability is capped at the fees the Creator paid in the 12 months before the event.'],
            ],
        ],
        [
            'id' => 'compliance',
            'title_th' => 'หน้าที่ที่ 6 — การปฏิบัติตามกฎหมายและภาษี',
            'title_en' => 'Duty 6 — Legal compliance and tax',
            'clauses' => [
                ['th' => 'ผู้สร้างรับผิดชอบปฏิบัติตามกฎหมายที่ใช้กับการออกและเสนอขายเหรียญลูกของตนแต่เพียงผู้เดียว รวมถึงพระราชกำหนดการประกอบธุรกิจสินทรัพย์ดิจิทัล พ.ศ. 2561 ประกาศของ ก.ล.ต. กฎหมายคุ้มครองผู้บริโภค และกฎหมายของประเทศที่เสนอขาย',
                    'en' => 'The Creator alone is responsible for complying with the laws that apply to issuing and offering its Child Token, including the Emergency Decree on Digital Asset Businesses B.E. 2561 (2018), Thai SEC notifications, consumer protection law and the laws of every country where it is offered.'],
                ['th' => 'หากเหรียญลูกเข้าข่ายต้องได้รับอนุญาตหรือต้องเสนอขายผ่านผู้ให้บริการระบบเสนอขายโทเคนดิจิทัล (ICO Portal) ผู้สร้างต้องดำเนินการให้ถูกต้องก่อนเสนอขาย และผู้สร้างรับผิดชอบภาษีเงินได้ ภาษีมูลค่าเพิ่ม และภาษีอื่นที่เกิดจากโครงการของตน',
                    'en' => 'If the Child Token needs a licence or must be offered through a licensed ICO Portal, the Creator must arrange this before offering it, and the Creator is responsible for income tax, VAT and any other tax arising from its project.'],
            ],
        ],
        [
            'id' => 'platform-rights',
            'title_th' => 'หน้าที่ที่ 7 — สิทธิ์ของแพลตฟอร์ม (เฉพาะที่ทำได้จริง)',
            'title_en' => 'Duty 7 — Platform rights (only what it can actually do)',
            'clauses' => [
                ['th' => 'แพลตฟอร์มมีสิทธิ์ปฏิเสธ ระงับ หรือยุติการให้บริการบนเว็บไซต์ ซ่อนหรือถอดหน้าขาย หน้าโครงการสาธารณะ ไวท์เปเปอร์มาตรฐาน และรายการเหรียญลูกบน tpix.online และเพิกถอนการอนุมัติใบลงทะเบียน เมื่อผู้สร้างผิดข้อตกลงหรือมีเหตุอันควรสงสัยว่ากระทำผิดกฎหมาย',
                    'en' => 'The Platform may refuse, suspend or end its website services, hide or remove the sale page, Public Project Page, Standard Whitepaper and Child Token listings on tpix.online, and revoke the registration approval when the Creator breaches this Agreement or is reasonably suspected of breaking the law.'],
                ['th' => 'แพลตฟอร์มมีสิทธิ์ส่งข้อมูลและเอกสารของผู้สร้างให้หน่วยงานรัฐเมื่อมีคำสั่งหรือหน้าที่ตามกฎหมาย หรือเมื่อพบการทุจริต',
                    'en' => 'The Platform may give the Creator\'s data and documents to authorities when ordered or required by law, or when it finds fraud.'],
                ['th' => 'ผู้สร้างรับทราบว่าแพลตฟอร์มไม่มีสิทธิ์และไม่สามารถยุติ หยุด อายัด หรือยึดเหรียญลูก LP หรือ vesting บนเชนได้ เพราะสัญญาอัจฉริยะไม่มีกลไกดังกล่าว ข้อตกลงนี้ไม่ให้สิทธิ์ใดแก่แพลตฟอร์มเกินกว่าที่สัญญาบนเชนทำได้จริง',
                    'en' => 'The Creator acknowledges that the Platform has no right and no ability to stop, halt, freeze or seize Child Tokens, LP or vesting on chain, because the smart contracts have no such mechanism. This Agreement grants the Platform nothing beyond what the on-chain contracts can actually do.'],
            ],
        ],
        [
            'id' => 'onchain-rules',
            'title_th' => 'หน้าที่ที่ 8 — กติกาบนเชน (กติกากันลักพูลชั้นที่ 1) และข้อเท็จจริงที่ผู้สร้างรับทราบ',
            'title_en' => 'Duty 8 — On-chain rules (anti-rug layer 1) and facts the Creator acknowledges',
            'clauses' => [
                ['th' => "เมื่อผู้สร้างเปิดขายเหรียญลูกผ่าน Launchpad สัญญา LaunchpadFactory บังคับกติกาต่อไปนี้ด้วยโค้ด และปฏิเสธการเปิดขายที่ไม่ผ่าน ไม่ว่าจะเรียกผ่านเว็บไซต์หรือเรียกสัญญาโดยตรง (อ้างอิงโค้ด: {$ref})",
                    'en' => "When the Creator opens a Child Token sale through the Launchpad, the LaunchpadFactory contract enforces the following rules in code and rejects any launch that fails them, whether called through the website or directly (code reference: {$ref})."],
                ['th' => 'รับเฉพาะเหรียญที่สร้างจาก TPIX Token Factory V2 ประเภท ERC20V2 (ตรวจจากทะเบียน tokenRecords ของโรงงาน) และเหรียญต้องปิดความสามารถผลิตเพิ่ม (mint) หยุดการโอน (pause) ขึ้นบัญชีดำ (blacklist) และเผาอัตโนมัติ (auto-burn) ซึ่งเป็นค่าที่ฝังถาวรในเหรียญ เปิดภายหลังไม่ได้',
                    'en' => 'Only ERC20V2 tokens made by TPIX Token Factory V2 are accepted (checked against the factory\'s tokenRecords), and minting, pausing, blacklisting and auto-burn must all be switched off — switches fixed in the token forever that can never be turned on later.'],
                ['th' => "สภาพคล่อง (LP) ล็อกอย่างน้อย {$lpDays} วันหลังปิดการขาย ระหว่างนั้น LP ทุนสำรองเหรียญที่ยังไม่จับคู่ เหรียญที่ขายไม่หมด และเงินขายที่ยังไม่เข้าพูล ถอนออกจากสัญญา PoolFeeder ไม่ได้",
                    'en' => "The LP stays locked at least {$lpDays} days after the sale ends; until then the LP, the unpaired token reserve, the unsold sale tokens and proceeds not yet pooled cannot be withdrawn from the PoolFeeder contract."],
                ['th' => "ยอดซื้อแต่ละครั้งต้องเข้าพูลสภาพคล่องอย่างน้อยร้อยละ {$poolPct} ส่วนที่เหลือไปยังกระเป๋าโครงการ",
                    'en' => "At least {$poolPct}% of every purchase goes into the liquidity pool; the rest goes to the project wallet."],
                ['th' => "ณ วันปลด LP เหรียญลูกอย่างน้อยร้อยละ {$lockedPct} ของซัพพลายทั้งหมดต้องอยู่ในสัญญาขาย ทุนสำรองพูล หรือ vesting ที่ยังล็อกอยู่ ผู้สร้างจึงถือเหรียญแบบไม่ล็อกได้ไม่เกินร้อยละ {$freePct} (vesting ที่ปลดก่อนวันปลด LP ไม่นับเป็นส่วนที่ล็อก)",
                    'en' => "On the LP unlock date at least {$lockedPct}% of the total supply must sit in the sale contract, the pool reserve or vesting still locked, so the Creator can hold at most {$freePct}% unlocked (vesting released before the LP unlock does not count as locked)."],
                ['th' => 'ทางเดินของเงินขายเปลี่ยนไม่ได้: สัญญา PoolFeeder เป็นเจ้าของถาวรของสัญญาขาย ไม่มีคำสั่งเปลี่ยนผู้รับเงิน (setTreasury) หรือโอนสิทธิ์ ผู้สร้างเปลี่ยนได้เพียงกระเป๋าที่รับส่วนที่ไม่เข้าพูล',
                    'en' => 'The proceeds path cannot be changed: PoolFeeder is the permanent owner of the sale contract with no setTreasury or ownership transfer; the Creator can only change the wallet that receives the non-pool share.'],
                ['th' => "พูลเปิดที่ราคาขายพอดีในธุรกรรมเดียวกับการเปิดขาย การเติมพูลทำได้เฉพาะเมื่อราคาพูลอยู่ในกรอบรอบราคาขาย (ค่าตั้งต้นบวกลบร้อยละ {$band}) ยืดเวลาขายเกินวันสิ้นสุดเดิมไม่ได้ และยอดขั้นต่ำต่อการเติมพูล (minFeed) แก้ไม่ได้",
                    'en' => "The pool opens at exactly the sale price in the same transaction as the launch, feeding happens only while the pool price is within a band around the sale price (default ±{$band}%), the sale cannot be extended past its original end, and the minimum feed amount (minFeed) cannot be changed."],
                ['th' => 'ตาราง vesting ฝังตอนสร้างและแก้ไม่ได้ ไม่มีเจ้าของและไม่มีปุ่มถอนก่อนกำหนด เหรียญที่ปลดไปที่ผู้รับ (beneficiary) เท่านั้น',
                    'en' => 'The vesting schedule is fixed at creation and cannot be changed; it has no owner and no early-withdrawal function, and released tokens go only to the beneficiary.'],
                ['th' => 'หลังวันปลด LP เจ้าของสัญญา PoolFeeder ถอน LP ได้ ถอนทุนสำรองเหรียญได้เมื่อไม่มีเงินขายรอเข้าพูล ถอนเงินขายที่ไม่ได้เข้าพูลได้ และถอนเหรียญที่ขายไม่หมดได้ ก่อนวันนั้นทำไม่ได้แม้หยุดการขายไว้',
                    'en' => 'After the LP unlock date the PoolFeeder owner may withdraw the LP, withdraw the token reserve once no proceeds wait for the pool, withdraw proceeds that never entered the pool, and withdraw unsold sale tokens; none of this is possible earlier, even if the sale is paused.'],
                ['th' => 'ผู้สร้างรับทราบว่าเงินขายเข้าพูลอัตโนมัติ แพลตฟอร์มไม่ถือเงินแทน (ไม่มี escrow) และกติกาทุกข้อในหมวดนี้รวมถึงค่าที่ผู้สร้างตั้งเองแก้ไม่ได้หลังเริ่มขาย ผู้สร้างต้องตรวจพารามิเตอร์ทุกค่าก่อนลงนามธุรกรรมด้วยตนเอง ตั้งค่าผิดแล้วแก้ไม่ได้',
                    'en' => 'The Creator acknowledges that proceeds feed the pool automatically, the Platform holds no funds (no escrow), and every rule in this section — including the values the Creator sets — cannot be changed once the sale starts. The Creator must check every parameter before signing the transaction; a wrong setting cannot be fixed.'],
            ],
        ],
        [
            'id' => 'web-rules',
            'title_th' => 'กติกาบนเว็บไซต์ (กติกากันลักพูลชั้นที่ 2)',
            'title_en' => 'Website rules (anti-rug layer 2)',
            'clauses' => [
                ['th' => 'หน้าขายขึ้นบนเว็บไซต์ได้หลังแพลตฟอร์มอนุมัติเท่านั้น แพลตฟอร์มปฏิเสธได้พร้อมเหตุผลและคืนค่าบริการตามข้อ 8.2',
                    'en' => 'A sale page goes live on the website only after the Platform approves it; the Platform may reject it with reasons and refund fees under clause 8.2.'],
                ['th' => 'หน้าขาย หน้าโครงการสาธารณะ และไวท์เปเปอร์มาตรฐานแสดงป้ายความเสี่ยงจากข้อมูลบนเชนและคำเตือนภาษาไทยและอังกฤษ และผู้ซื้อต้องติ๊กยอมรับคำเตือนก่อนซื้อ',
                    'en' => 'The sale page, Public Project Page and Standard Whitepaper show risk labels from on-chain data and Thai/English warnings, and buyers must tick to accept the warnings before buying.'],
                ['th' => 'ห้ามชื่อหรือสัญลักษณ์ที่ซ้ำหรือเลียนแบบเหรียญลูกที่อนุมัติแล้ว ผู้ใช้รายงานเหรียญที่น่าสงสัยได้ และแพลตฟอร์มซ่อนหรือถอดหน้าบนเว็บไซต์ได้ตามข้อ 11 แต่ไม่มีสิทธิ์อายัดหรือยุติเหรียญบนเชน',
                    'en' => 'Names or symbols that copy an approved Child Token are not allowed; users can report suspicious tokens, and the Platform may hide or remove website pages under clause 11 but has no power to freeze or end a token on chain.'],
            ],
        ],
        [
            'id' => 'documents-publication',
            'title_th' => 'เอกสารประกอบและการเผยแพร่',
            'title_en' => 'Supporting documents and publication',
            'clauses' => [
                ['th' => 'ค่าแฮช SHA-256 ของเอกสารประกอบทุกไฟล์และตัวเลือกการเผยแพร่ถูกผูกไว้ในข้อความที่ผู้สร้างลงนาม การเปลี่ยนเอกสารหลังลงนามทำให้ต้องลงนามใหม่',
                    'en' => 'The SHA-256 hash of every Supporting Document and its publication choice is bound into the message the Creator signs; changing a document after signing requires signing again.'],
                ['th' => 'ผู้สร้างเลือกเผยแพร่เอกสารบริษัทและรูปโครงการได้ทีละไฟล์ เอกสารที่เลือกเผยแพร่แสดงบนหน้าโครงการสาธารณะเป็นภาพที่เซิร์ฟเวอร์ย่อขนาดและประทับลายน้ำ "ใช้ตรวจสอบโครงการบน tpix.online เท่านั้น" พร้อมวันที่ ผู้ซื้อไม่ได้รับไฟล์ต้นฉบับ',
                    'en' => 'The Creator chooses, file by file, which company documents and project photos to publish. Published documents appear on the Public Project Page as server-resized images watermarked "For project verification on tpix.online only" with the date; buyers never receive the original file.'],
                ['th' => 'สำเนาบัตรของผู้มีอำนาจลงนามและข้อมูลติดต่อ (อีเมล เบอร์โทรศัพท์ ที่อยู่) ไม่เผยแพร่ แอดมินของแพลตฟอร์มเท่านั้นที่เห็น ผู้ซื้อเห็นเพียงป้าย "ยืนยันตัวตนผ่าน Thaiprompt แล้ว"',
                    'en' => 'The authorised signatory\'s ID and contact details (email, phone, address) are never published; only Platform administrators see them, and buyers see only a "Verified through Thaiprompt" badge.'],
                ['th' => 'ผู้สร้างยินยอมให้เผยแพร่ข้อมูลโครงการ (ชื่อโครงการ รายละเอียด ทีมงาน แผนการใช้เงิน ลิงก์) ชื่อนิติบุคคลและเลขทะเบียน และชื่อผู้สร้างเฉพาะเมื่อติ๊กยินยอมแยกไว้ในใบลงทะเบียน ผู้สร้างขอยกเลิกการเผยแพร่ได้ แต่ข้อมูลบนเชนและหลักฐานการลงนามที่แพลตฟอร์มต้องเก็บตามข้อ 17 ยังคงอยู่',
                    'en' => 'The Creator consents to publishing the project information (name, description, team, use of funds, links), the company name and registration number, and the Creator\'s own name only where separately ticked in the registration. The Creator may withdraw publication consent, but on-chain data and the signing evidence the Platform must keep under clause 17 remain.'],
            ],
        ],
        [
            'id' => 'buyer-risk',
            'title_th' => 'ความเสี่ยงของผู้ซื้อ และการไม่รับประกันผลตอบแทน',
            'title_en' => 'Buyer risk and no guarantee of returns',
            'clauses' => [
                ['th' => 'แพลตฟอร์มเป็นผู้ให้บริการเทคโนโลยี ไม่ใช่ผู้ออกเหรียญลูก นายหน้า ผู้ค้า หรือผู้ให้คำแนะนำการลงทุน และไม่รับประกันราคา สภาพคล่อง การจดทะเบียนซื้อขาย หรือผลตอบแทนของเหรียญลูก การอนุมัติไม่ใช่การรับรองหรือแนะนำโครงการ',
                    'en' => 'The Platform is a technology provider, not the issuer, a broker, a dealer or an investment adviser, and does not guarantee the price, liquidity, listing or returns of any Child Token. Approval is not an endorsement or recommendation of the project.'],
                ['th' => 'ผู้สร้างรับทราบว่าราคาเหรียญลูกอาจลดลงจนไม่มีมูลค่า โดยเฉพาะเมื่อเหรียญที่ล็อกถูกปลดหรือ LP ครบกำหนด และโครงการอาจล้มเหลวได้ ผู้สร้างต้องสื่อสารความเสี่ยงนี้ต่อผู้ซื้อ',
                    'en' => 'The Creator acknowledges the Child Token price may fall to zero, especially when locked tokens unlock or the LP lock ends, and that the project may fail; the Creator must communicate this risk to buyers.'],
            ],
        ],
        [
            'id' => 'ip',
            'title_th' => 'ทรัพย์สินทางปัญญาและไวท์เปเปอร์มาตรฐาน',
            'title_en' => 'Intellectual property and the Standard Whitepaper',
            'clauses' => [
                ['th' => 'ผู้สร้างรับรองว่ามีสิทธิ์ใช้ชื่อ สัญลักษณ์ โลโก้ และเนื้อหาของโครงการ และอนุญาตให้แพลตฟอร์มใช้สิ่งเหล่านี้โดยไม่มีค่าตอบแทนเพื่อแสดงรายการ หน้าขาย หน้าโครงการสาธารณะ ไวท์เปเปอร์มาตรฐาน และประชาสัมพันธ์บนช่องทางของแพลตฟอร์ม',
                    'en' => 'The Creator warrants it has the right to use the project\'s name, symbol, logo and content, and licenses the Platform, free of charge, to use them for listings, the sale page, the Public Project Page, the Standard Whitepaper and promotion on the Platform\'s channels.'],
                ['th' => 'ไวท์เปเปอร์มาตรฐานเป็นเอกสารข้อเท็จจริงที่แพลตฟอร์มจัดทำจากข้อมูลบนเชนและข้อมูลที่ผู้สร้างให้ไว้ ไม่ใช่คำรับรองของแพลตฟอร์ม ผู้สร้างจะจัดทำไวท์เปเปอร์ของตนเองเพิ่มเติมได้ แต่ต้องไม่ขัดกับข้อมูลบนเชน',
                    'en' => 'The Standard Whitepaper is a factual document the Platform generates from on-chain data and Creator-supplied information; it is not the Platform\'s endorsement. The Creator may publish its own whitepaper as well, provided it does not contradict the on-chain facts.'],
            ],
        ],
        [
            'id' => 'pdpa',
            'title_th' => 'การคุ้มครองข้อมูลส่วนบุคคล',
            'title_en' => 'Personal data protection',
            'clauses' => [
                ['th' => 'แพลตฟอร์มเก็บรวบรวม ใช้ และเปิดเผยข้อมูลส่วนบุคคลของผู้สร้างตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 เพื่อยืนยันตัวตน พิจารณาใบลงทะเบียน ปฏิบัติตามข้อตกลง ปฏิบัติตามกฎหมาย และเผยแพร่ตามความยินยอมในข้อ 14',
                    'en' => 'The Platform collects, uses and discloses the Creator\'s personal data under the Personal Data Protection Act B.E. 2562 (2019) to verify identity, review the registration, perform this Agreement, comply with the law and publish as consented in clause 14.'],
                ['th' => 'จากระบบ Thaiprompt แพลตฟอร์มได้รับเฉพาะสถานะผลการยืนยันตัวตน ไม่ได้รับรูปบัตรหรือเลขบัตร ข้อมูลในใบลงทะเบียนถูกเข้ารหัสในฐานข้อมูล และเอกสารประกอบเก็บในพื้นที่ที่ไม่เปิดสู่สาธารณะ',
                    'en' => 'From Thaiprompt the Platform receives only the verification status, not ID images or numbers. Registration data is encrypted in the database and Supporting Documents are stored outside public storage.'],
                ['th' => 'แพลตฟอร์มเก็บข้อมูลไว้ตลอดระยะเวลาที่ข้อตกลงมีผลและต่อไปอีก 10 ปีนับแต่สิ้นสุดเพื่อใช้เป็นหลักฐานตามอายุความ เว้นแต่กฎหมายกำหนดให้เก็บนานกว่า ผู้สร้างมีสิทธิ์ขอเข้าถึง แก้ไข หรือคัดค้านการประมวลผลตามกฎหมาย โดยติดต่อแพลตฟอร์มผ่านช่องทางบน tpix.online',
                    'en' => 'The Platform keeps the data for the term of this Agreement and 10 years after it ends as evidence within the limitation period, unless the law requires longer. The Creator may request access, correction or object to processing as the law allows, through the contact channels on tpix.online.'],
            ],
        ],
        [
            'id' => 'term',
            'title_th' => 'หน้าที่ที่ 9 — ระยะเวลา การบอกเลิก และการแก้ไขข้อตกลง',
            'title_en' => 'Duty 9 — Term, termination and amendment',
            'clauses' => [
                ['th' => 'ข้อตกลงนี้มีผลตั้งแต่ผู้สร้างลงนามจนกว่าจะบอกเลิก ผู้สร้างบอกเลิกได้ก่อนสร้างเหรียญลูก หลังสร้างเหรียญลูกแล้ว หน้าที่ตามข้อ 5 และกติกาบนเชนยังมีผลอย่างน้อยจนถึงวันปลด LP',
                    'en' => 'This Agreement runs from signing until terminated. The Creator may terminate before creating a Child Token; once a Child Token exists, the duties in clause 5 and the on-chain rules continue at least until the LP unlock date.'],
                ['th' => 'แพลตฟอร์มบอกเลิกได้ทันทีเมื่อผู้สร้างผิดข้อตกลงในสาระสำคัญ ข้อกำหนดเรื่องความรับผิด การชดใช้ ข้อมูลส่วนบุคคล และกฎหมายที่ใช้บังคับยังมีผลต่อไปหลังข้อตกลงสิ้นสุด',
                    'en' => 'The Platform may terminate immediately for a material breach. Clauses on liability, indemnity, personal data and governing law survive termination.'],
                ['th' => 'แพลตฟอร์มแก้ไขข้อตกลงได้โดยออกฉบับใหม่ที่มีหมายเลขฉบับและค่าแฮชของตนเอง การแก้ไขที่เป็นสาระสำคัญจะขอให้ผู้สร้างลงนามใหม่ ฉบับที่ผู้สร้างลงนามไว้ยังใช้บังคับกับการกระทำที่เกิดขึ้นระหว่างที่ฉบับนั้นมีผล',
                    'en' => 'The Platform may amend this Agreement by issuing a new version with its own number and hash; material changes require the Creator to sign again, and the signed version still governs acts done while it was in force.'],
            ],
        ],
        [
            'id' => 'law',
            'title_th' => 'กฎหมายที่ใช้บังคับ ศาลที่มีเขตอำนาจ และภาษาของข้อตกลง',
            'title_en' => 'Governing law, jurisdiction and language',
            'clauses' => [
                ['th' => 'ข้อตกลงนี้อยู่ภายใต้และตีความตามกฎหมายไทย คู่สัญญาจะเจรจาโดยสุจริตภายใน 30 วันก่อน หากตกลงกันไม่ได้ให้ศาลที่มีเขตอำนาจในประเทศไทยเป็นผู้พิจารณา',
                    'en' => 'This Agreement is governed by and construed under Thai law. The parties will first negotiate in good faith for 30 days; failing agreement, the competent courts of Thailand have jurisdiction.'],
                ['th' => 'ข้อตกลงนี้จัดทำเป็นภาษาไทยและภาษาอังกฤษ หากข้อความสองภาษาขัดหรือแย้งกัน ให้ถือตามข้อความภาษาไทยเป็นหลัก',
                    'en' => 'This Agreement is made in Thai and English. If the two texts conflict, the Thai text prevails.'],
                ['th' => 'หากข้อใดเป็นโมฆะหรือใช้บังคับไม่ได้ ข้ออื่นยังคงมีผล การที่แพลตฟอร์มไม่ใช้สิทธิ์ใดไม่ถือเป็นการสละสิทธิ์ และผู้สร้างโอนสิทธิ์ตามข้อตกลงนี้ให้ผู้อื่นไม่ได้หากไม่ได้รับความยินยอมเป็นหนังสือ',
                    'en' => 'If any clause is void or unenforceable the rest remain in force; the Platform\'s failure to exercise a right is not a waiver; and the Creator may not assign this Agreement without written consent.'],
            ],
        ],
        [
            'id' => 'signature-record',
            'title_th' => 'หน้าที่ที่ 10 — การลงนามและหลักฐานที่บันทึก',
            'title_en' => 'Duty 10 — Signing and the evidence recorded',
            'clauses' => [
                ['th' => 'ข้อความที่ผู้สร้างลงนามระบุที่อยู่กระเป๋าที่ลงทะเบียน เลขที่ใบลงทะเบียน ฉบับและค่าแฮช SHA-256 ของข้อตกลงนี้ ค่าแฮชของข้อมูลใบลงทะเบียน ค่าแฮชของรายการเอกสารประกอบ (ถ้ามี) และเวลาที่ออกข้อความ',
                    'en' => 'The message the Creator signs states the Registered Wallet address, the registration number, this Agreement\'s version and SHA-256 hash, the hash of the registration data, the hash of the Supporting Documents list (if any) and the time the message was issued.'],
                ['th' => 'แพลตฟอร์มบันทึกลายเซ็น วันเวลาที่ลงนาม หมายเลข IP และโปรแกรมที่ใช้ไว้เป็นหลักฐาน และพิมพ์ทั้งหมดไว้ในเอกสาร PDF ฉบับนี้ ผู้ใดก็ตรวจสอบได้ด้วยตนเองว่าลายเซ็นมาจากกระเป๋าที่ลงทะเบียนและเนื้อหาตรงกับฉบับที่เผยแพร่',
                    'en' => 'The Platform records the signature, the date and time of signing, the IP address and the user agent as evidence and prints them all in this PDF; anyone can verify independently that the signature comes from the Registered Wallet and that the content matches the published version.'],
            ],
        ],
    ],
];
