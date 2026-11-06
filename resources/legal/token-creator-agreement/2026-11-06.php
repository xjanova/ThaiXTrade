<?php

/*
 * TPIX TRADE — ข้อตกลงและข้อกำหนดการใช้บริการสำหรับผู้สร้างเหรียญ ฉบับ 2026-11-06
 *
 * ⚠️ ห้ามแก้ไฟล์นี้หลังเผยแพร่ — ผู้สร้างเซ็น hash ของเนื้อหาทั้งไฟล์ไว้แล้ว
 *    แก้แม้แต่ตัวอักษรเดียว hash เปลี่ยน แล้วลายเซ็นเดิมจะพิสูจน์เนื้อหาที่เขาตกลงไม่ได้
 *    ต้องการแก้ = สร้างไฟล์ฉบับใหม่ แล้วเพิ่มใน CreatorAgreement::VERSIONS
 *    (CreatorAgreementTest ล็อก hash ของทุกฉบับไว้)
 *
 * Developed by Xman Studio.
 */

return [
    'version' => '2026-11-06',
    'title_th' => 'ข้อตกลงและข้อกำหนดการใช้บริการสำหรับผู้สร้างเหรียญ TPIX TRADE',
    'title_en' => 'TPIX TRADE Token Creator Agreement and Terms of Service',

    'draft_notice_th' => 'สถานะเอกสาร: ร่างเพื่อการใช้งานเบื้องต้น — ผู้ให้บริการจะให้ที่ปรึกษากฎหมายตรวจทานเอกสารนี้ก่อนใช้เป็นสัญญาฉบับสมบูรณ์ โดยเฉพาะประเด็นตามพระราชกำหนดการประกอบธุรกิจสินทรัพย์ดิจิทัล พ.ศ. 2561 และประกาศของสำนักงานคณะกรรมการกำกับหลักทรัพย์และตลาดหลักทรัพย์ (ก.ล.ต.) รวมถึงการระบุชื่อ เลขทะเบียน และที่อยู่ของนิติบุคคลผู้ให้บริการ หากมีการแก้ไข ผู้ให้บริการจะออกฉบับใหม่และขอให้ผู้สร้างลงนามใหม่',
    'draft_notice_en' => 'Document status: preliminary draft — the Provider will have this document reviewed by legal counsel before it is used as a final contract, in particular regarding the Emergency Decree on Digital Asset Businesses B.E. 2561 (2018) and the notifications of the Thai Securities and Exchange Commission (SEC), and to state the name, registration number and address of the Provider\'s legal entity. If amended, the Provider will issue a new version and ask the Creator to sign again.',

    'preamble_th' => 'ข้อตกลงนี้ทำขึ้นระหว่างผู้ให้บริการแพลตฟอร์ม TPIX TRADE (tpix.online) ซึ่งพัฒนาและดำเนินการโดย Xman Studio ("ผู้ให้บริการ" หรือ "แพลตฟอร์ม") กับบุคคลหรือนิติบุคคลที่ลงทะเบียนเป็นผู้สร้างเหรียญ ("ผู้สร้าง") ผู้สร้างต้องอ่านข้อตกลงนี้ทั้งฉบับก่อนลงนาม การลงนามด้วยกระเป๋าดิจิทัลถือว่าผู้สร้างเข้าใจและตกลงผูกพันตามข้อตกลงนี้ทุกประการ',
    'preamble_en' => 'This Agreement is made between the provider of the TPIX TRADE platform (tpix.online), developed and operated by Xman Studio (the "Provider" or the "Platform"), and the individual or legal entity registering as a token creator (the "Creator"). The Creator must read this entire Agreement before signing. Signing with a digital wallet means the Creator understands and agrees to be bound by every term of this Agreement.',

    'sections' => [
        [
            'id' => 'definitions',
            'title_th' => 'คำนิยาม',
            'title_en' => 'Definitions',
            'clauses' => [
                [
                    'th' => '"เหรียญลูก" หมายถึง โทเคนดิจิทัลที่ผู้สร้างออกบน TPIX Chain ผ่านระบบสร้างเหรียญ (Token Factory) ของแพลตฟอร์ม',
                    'en' => '"Child Token" means a digital token issued by the Creator on TPIX Chain through the Platform\'s Token Factory.',
                ],
                [
                    'th' => '"TPIX Chain" หมายถึง บล็อกเชนหมายเลขเครือข่าย 4289 และ "TPIX" หมายถึง เหรียญหลักของเครือข่ายดังกล่าว',
                    'en' => '"TPIX Chain" means the blockchain with chain ID 4289, and "TPIX" means the native coin of that network.',
                ],
                [
                    'th' => '"Launchpad" หมายถึง หน้าขายเหรียญลูกและสัญญาอัจฉริยะที่เกี่ยวข้อง (สัญญาขายราคาคงที่ สัญญาเติมสภาพคล่อง และสัญญาล็อกเหรียญ) ที่แพลตฟอร์มจัดให้',
                    'en' => '"Launchpad" means the Child Token sale pages and related smart contracts (fixed-price sale, liquidity feeder and token lock contracts) provided by the Platform.',
                ],
                [
                    'th' => '"กระเป๋าที่ลงทะเบียน" หมายถึง ที่อยู่กระเป๋าดิจิทัลที่ผู้สร้างใช้ลงนามเข้าสู่การลงทะเบียน ซึ่งเป็นกระเป๋าที่ใช้รับเหรียญลูกส่วนเริ่มโครงการและเป็นตัวแทนของผู้สร้างในระบบ',
                    'en' => '"Registered Wallet" means the digital wallet address the Creator signed in with to register; it receives the starter allocation of the Child Token and represents the Creator in the system.',
                ],
                [
                    'th' => '"ส่วนเริ่มโครงการ" หมายถึง สัดส่วนของซัพพลายเหรียญลูกที่แพลตฟอร์มอนุมัติให้ผลิตเข้ากระเป๋าที่ลงทะเบียนโดยไม่ล็อก เพื่อใช้เริ่มดำเนินโครงการ',
                    'en' => '"Starter Allocation" means the share of the Child Token supply that the Platform approves to be minted, unlocked, to the Registered Wallet to start the project.',
                ],
                [
                    'th' => '"ผู้ซื้อ" หมายถึง บุคคลใดที่ซื้อหรือถือเหรียญลูกของผู้สร้าง',
                    'en' => '"Buyer" means any person who buys or holds the Creator\'s Child Token.',
                ],
            ],
        ],
        [
            'id' => 'acceptance',
            'title_th' => 'การยอมรับข้อตกลงและการลงลายมือชื่ออิเล็กทรอนิกส์',
            'title_en' => 'Acceptance and Electronic Signature',
            'clauses' => [
                [
                    'th' => 'ผู้สร้างตกลงว่าการลงนามข้อความด้วยกุญแจของกระเป๋าที่ลงทะเบียน (มาตรฐาน EIP-191 personal_sign) เป็นลายมือชื่ออิเล็กทรอนิกส์ตามพระราชบัญญัติว่าด้วยธุรกรรมทางอิเล็กทรอนิกส์ พ.ศ. 2544 ที่ใช้ระบุตัวผู้สร้างและแสดงเจตนายอมรับข้อตกลงฉบับนี้',
                    'en' => 'The Creator agrees that signing a message with the key of the Registered Wallet (EIP-191 personal_sign) is an electronic signature under the Electronic Transactions Act B.E. 2544 (2001) that identifies the Creator and shows the Creator\'s intent to accept this Agreement.',
                ],
                [
                    'th' => 'ข้อความที่ลงนามระบุฉบับของข้อตกลง ค่าแฮช SHA-256 ของข้อตกลงฉบับนั้น ค่าแฮชของข้อมูลใบลงทะเบียน และเวลาที่ออกข้อความ แพลตฟอร์มบันทึกลายเซ็น เวลา และหมายเลข IP ไว้เป็นหลักฐาน ซึ่งบุคคลใดก็ตรวจสอบย้อนหลังได้ด้วยวิธี ecrecover โดยไม่ต้องพึ่งแพลตฟอร์ม',
                    'en' => 'The signed message states the Agreement version, the SHA-256 hash of that version, the hash of the application data and the time the message was issued. The Platform records the signature, time and IP address as evidence, which anyone can verify later with ecrecover without relying on the Platform.',
                ],
                [
                    'th' => 'การลงนามไม่ส่งธุรกรรมและไม่มีค่าใช้จ่าย แพลตฟอร์มจะไม่ขอ private key หรือวลีกู้คืน (seed phrase) ของผู้สร้างไม่ว่ากรณีใด ผู้ใดขอข้อมูลดังกล่าวในนามแพลตฟอร์มถือเป็นการหลอกลวง',
                    'en' => 'Signing sends no transaction and costs nothing. The Platform will never ask for the Creator\'s private key or seed phrase under any circumstances; anyone asking for them in the Platform\'s name is a scammer.',
                ],
            ],
        ],
        [
            'id' => 'eligibility',
            'title_th' => 'คุณสมบัติของผู้สร้างและการยืนยันตัวตน',
            'title_en' => 'Creator Eligibility and Identity Verification',
            'clauses' => [
                [
                    'th' => 'ผู้สร้างที่เป็นบุคคลธรรมดาต้องบรรลุนิติภาวะ (อายุ 20 ปีบริบูรณ์ขึ้นไป) และมีความสามารถทำนิติกรรม ผู้สร้างที่เป็นนิติบุคคลต้องจดทะเบียนถูกต้องตามกฎหมาย และผู้ลงนามต้องมีอำนาจกระทำการแทนนิติบุคคลนั้น',
                    'en' => 'An individual Creator must be of legal age (20 years or older) and have legal capacity. A corporate Creator must be duly registered under law, and the signer must be authorised to act on its behalf.',
                ],
                [
                    'th' => 'ผู้สร้างต้องผ่านการยืนยันตัวตน (KYC) ผ่านระบบ Thaiprompt ที่แพลตฟอร์มเชื่อมต่อไว้ก่อนส่งใบลงทะเบียน แพลตฟอร์มรับเฉพาะผลการยืนยันจาก Thaiprompt และอาจขอเอกสารเพิ่มเติมเมื่อจำเป็น เช่น หนังสือรับรองบริษัทหรือหนังสือมอบอำนาจ',
                    'en' => 'The Creator must pass identity verification (KYC) through the Thaiprompt system connected to the Platform before submitting the application. The Platform receives only the verification result from Thaiprompt and may request further documents when needed, such as a company certificate or a power of attorney.',
                ],
                [
                    'th' => 'ผู้สร้างรับรองว่าตนเอง ผู้ถือหุ้นรายใหญ่ และผู้มีอำนาจควบคุม ไม่อยู่ในรายชื่อบุคคลที่ถูกกำหนดหรือรายชื่อคว่ำบาตรตามกฎหมายไทยหรือมติคณะมนตรีความมั่นคงแห่งสหประชาชาติ และต้องแจ้งตามจริงหากเป็นบุคคลที่มีสถานภาพทางการเมือง (PEP)',
                    'en' => 'The Creator represents that it, its major shareholders and controlling persons are not on any designated-person or sanctions list under Thai law or United Nations Security Council resolutions, and must truthfully disclose if it is a politically exposed person (PEP).',
                ],
                [
                    'th' => 'หนึ่งกระเป๋าลงทะเบียนได้หนึ่งใบ ผู้สร้างรับผิดชอบรักษากุญแจของกระเป๋าที่ลงทะเบียนแต่เพียงผู้เดียว การกระทำใดที่ลงนามด้วยกระเป๋านี้ถือเป็นการกระทำของผู้สร้าง',
                    'en' => 'Each wallet may hold one registration. The Creator is solely responsible for safeguarding the key of the Registered Wallet; any action signed with this wallet is deemed the Creator\'s own action.',
                ],
            ],
        ],
        [
            'id' => 'accuracy',
            'title_th' => 'ความถูกต้องของข้อมูล',
            'title_en' => 'Accuracy of Information',
            'clauses' => [
                [
                    'th' => 'ผู้สร้างรับรองว่าข้อมูลในใบลงทะเบียน เอกสารประกอบ เว็บไซต์ และเอกสารโครงการ (whitepaper) เป็นความจริง ครบถ้วน และไม่ทำให้สำคัญผิด',
                    'en' => 'The Creator represents that the information in the application, supporting documents, website and project documents (whitepaper) is true, complete and not misleading.',
                ],
                [
                    'th' => 'หากข้อมูลสำคัญเปลี่ยนแปลง ผู้สร้างต้องแจ้งแพลตฟอร์มภายใน 7 วัน การให้ข้อมูลเท็จหรือปกปิดข้อเท็จจริงเป็นเหตุให้แพลตฟอร์มปฏิเสธ ระงับ หรือยกเลิกการอนุมัติได้ทันที',
                    'en' => 'If material information changes, the Creator must notify the Platform within 7 days. Giving false information or concealing facts entitles the Platform to reject, suspend or revoke approval immediately.',
                ],
            ],
        ],
        [
            'id' => 'review',
            'title_th' => 'การพิจารณาอนุมัติ',
            'title_en' => 'Review and Approval',
            'clauses' => [
                [
                    'th' => 'แพลตฟอร์มพิจารณาใบลงทะเบียนตามดุลยพินิจ และอาจปฏิเสธโดยแจ้งเหตุผล ผู้สร้างแก้ไขข้อมูลแล้วยื่นใหม่ได้ ผู้สร้างจะสร้างเหรียญลูกได้หลังได้รับอนุมัติแล้วเท่านั้น',
                    'en' => 'The Platform reviews applications at its discretion and may reject them with reasons. The Creator may correct the information and apply again. The Creator may create a Child Token only after approval.',
                ],
                [
                    'th' => 'การอนุมัติไม่ใช่การรับรอง แนะนำ หรือค้ำประกันโครงการ ราคา หรือคุณภาพของเหรียญลูก ผู้สร้างต้องไม่อ้างว่าแพลตฟอร์มรับรองโครงการของตน',
                    'en' => 'Approval is not an endorsement, recommendation or guarantee of the project, price or quality of the Child Token. The Creator must not claim that the Platform endorses its project.',
                ],
            ],
        ],
        [
            'id' => 'onchain-rules',
            'title_th' => 'การสร้างเหรียญและกฎบนบล็อกเชน',
            'title_en' => 'Token Creation and On-chain Rules',
            'clauses' => [
                [
                    'th' => 'เหรียญลูกที่ขายผ่าน Launchpad ต้องสร้างจากสัญญา TPIXTokenFactoryV2 ของแพลตฟอร์มเท่านั้น และต้องไม่มีความสามารถผลิตเหรียญเพิ่ม (mint) หยุดการโอน (pause) ขึ้นบัญชีดำ (blacklist) หรือเผาเหรียญอัตโนมัติ (auto-burn)',
                    'en' => 'A Child Token sold through the Launchpad must be created from the Platform\'s TPIXTokenFactoryV2 contract only and must not be able to mint more tokens, pause transfers, blacklist holders or auto-burn.',
                ],
                [
                    'th' => 'สภาพคล่อง (LP) ต้องล็อกไม่น้อยกว่า 180 วัน ยอดซื้อไม่น้อยกว่าร้อยละ 50 ต้องเข้าพูลสภาพคล่อง และซัพพลายไม่น้อยกว่าร้อยละ 80 ต้องอยู่ในส่วนขาย ส่วนสำรองเติมพูล หรือส่วนที่ล็อกตามตารางทยอยปลด (vesting) จนถึงวันปลดล็อก LP',
                    'en' => 'Liquidity (LP) must be locked for at least 180 days, at least 50% of sale proceeds must go to the liquidity pool, and at least 80% of the supply must remain in the sale, the pool reserve or vesting locks until the LP unlock date.',
                ],
                [
                    'th' => 'กฎข้างต้นบังคับด้วยสัญญาอัจฉริยะ เมื่อสัญญาถูกนำขึ้นเชนแล้ว ทั้งผู้สร้างและแพลตฟอร์มไม่สามารถแก้ไขหรือยกเลิกได้ ผู้สร้างต้องตรวจพารามิเตอร์ทุกค่าก่อนลงนามธุรกรรมด้วยตนเอง',
                    'en' => 'The rules above are enforced by smart contracts. Once deployed, neither the Creator nor the Platform can change or cancel them. The Creator must check every parameter before signing the transaction itself.',
                ],
            ],
        ],
        [
            'id' => 'starter-allocation',
            'title_th' => 'ส่วนเริ่มโครงการ',
            'title_en' => 'Starter Allocation',
            'clauses' => [
                [
                    'th' => 'เมื่อแพลตฟอร์มอนุมัติ แพลตฟอร์มจะกำหนดสัดส่วนส่วนเริ่มโครงการ (ค่าตั้งต้นร้อยละ 10 ของซัพพลาย และไม่เกินร้อยละ 20) ส่วนนี้ผลิตเข้ากระเป๋าที่ลงทะเบียนโดยตรงในขั้นตอนสร้างเหรียญ แพลตฟอร์มไม่ถือหรือเก็บรักษาเหรียญลูกแทนผู้สร้าง',
                    'en' => 'On approval, the Platform sets the Starter Allocation (10% of supply by default and no more than 20%). It is minted directly to the Registered Wallet when the token is created. The Platform does not hold or custody Child Tokens on the Creator\'s behalf.',
                ],
                [
                    'th' => 'ผู้สร้างต้องเปิดเผยจำนวนส่วนเริ่มโครงการและแผนการใช้ให้ผู้ซื้อทราบ และต้องใช้ตามแผนที่เปิดเผย การเทขายส่วนนี้ในลักษณะที่ขัดกับแผนที่เปิดเผยถือเป็นการผิดข้อตกลง',
                    'en' => 'The Creator must disclose the size of the Starter Allocation and its planned use to Buyers and must use it as disclosed. Dumping this allocation contrary to the disclosed plan is a breach of this Agreement.',
                ],
            ],
        ],
        [
            'id' => 'fees',
            'title_th' => 'ค่าบริการ',
            'title_en' => 'Service Fees',
            'clauses' => [
                [
                    'th' => 'ค่าบริการทั้งหมดชำระเป็นเหรียญ TPIX ตามอัตราที่แพลตฟอร์มแสดง ณ เวลาสั่งบริการ ได้แก่ ค่าสร้างเหรียญ และค่าตัวเลือกเสริม เช่น หน้าขาย การรับชำระ USDT จาก BSC การเติมพูลอัตโนมัติ การล็อกเหรียญทยอยปลด และการยืนยันซอร์สโค้ดสัญญา แพลตฟอร์มอาจปรับอัตราได้โดยมีผลกับคำสั่งบริการใหม่เท่านั้น',
                    'en' => 'All service fees are paid in TPIX at the rates shown by the Platform at the time of ordering, namely the token creation fee and optional add-ons such as a sale page, accepting USDT from BSC, automatic pool feeding, vesting locks and contract source verification. The Platform may change rates for new orders only.',
                ],
                [
                    'th' => 'ค่าบริการที่ให้บริการแล้วไม่คืนเงิน เว้นแต่แพลตฟอร์มปฏิเสธการเปิดขายตามนโยบายที่ประกาศไว้ ค่าธรรมเนียมเครือข่ายบนเชนอื่นและภาษีที่เกี่ยวข้องเป็นความรับผิดชอบของผู้สร้าง',
                    'en' => 'Fees for services already delivered are non-refundable, except where the Platform rejects a launch under its published policy. Network fees on other chains and applicable taxes are the Creator\'s responsibility.',
                ],
            ],
        ],
        [
            'id' => 'prohibited',
            'title_th' => 'ข้อห้าม',
            'title_en' => 'Prohibited Conduct',
            'clauses' => [
                [
                    'th' => 'ห้ามใช้แพลตฟอร์มเพื่อหลอกลวง ฉ้อโกง หรือ "rug pull" ห้ามโฆษณาเกินจริง ห้ามรับประกันผลตอบแทนหรือราคา และห้ามดำเนินการในลักษณะแชร์ลูกโซ่หรือพีระมิด',
                    'en' => 'The Platform must not be used to deceive, defraud or "rug pull". Exaggerated advertising, guaranteeing returns or prices, and Ponzi or pyramid schemes are prohibited.',
                ],
                [
                    'th' => 'ห้ามฟอกเงิน สนับสนุนการก่อการร้าย หรือหลีกเลี่ยงมาตรการคว่ำบาตร ตามพระราชบัญญัติป้องกันและปราบปรามการฟอกเงิน พ.ศ. 2542 และกฎหมายที่เกี่ยวข้อง แพลตฟอร์มอาจรายงานธุรกรรมที่น่าสงสัยต่อหน่วยงานที่มีอำนาจ',
                    'en' => 'Money laundering, terrorist financing and sanctions evasion are prohibited under the Anti-Money Laundering Act B.E. 2542 (1999) and related laws. The Platform may report suspicious transactions to the competent authorities.',
                ],
                [
                    'th' => 'ห้ามปั่นราคา ซื้อขายกับตนเองเพื่อสร้างปริมาณเทียม (wash trading) หรือบิดเบือนตลาดในรูปแบบใด',
                    'en' => 'Price manipulation, wash trading to create fake volume, or any other market distortion is prohibited.',
                ],
                [
                    'th' => 'ห้ามแอบอ้างบุคคล องค์กร หรือแบรนด์อื่น ห้ามละเมิดทรัพย์สินทางปัญญา และห้ามเกี่ยวข้องกับสินค้าหรือบริการที่ผิดกฎหมาย',
                    'en' => 'Impersonating other persons, organisations or brands, infringing intellectual property, and involvement with illegal goods or services are prohibited.',
                ],
                [
                    'th' => 'ห้ามเสนอขายเหรียญลูกที่มีลักษณะเป็นหลักทรัพย์หรือโทเคนดิจิทัลเพื่อการลงทุนต่อประชาชนโดยไม่ได้รับอนุญาตตามกฎหมาย',
                    'en' => 'Offering to the public a Child Token that is a security or an investment token without the authorisation required by law is prohibited.',
                ],
            ],
        ],
        [
            'id' => 'conduct',
            'title_th' => 'ข้อปฏิบัติของผู้สร้าง',
            'title_en' => 'Creator Code of Conduct',
            'clauses' => [
                [
                    'th' => 'เปิดเผยข้อมูลโครงการ ทีมงาน ตารางปลดล็อก และความเสี่ยงอย่างตรงไปตรงมา และคงเว็บไซต์กับเอกสารโครงการให้เข้าถึงได้ตลอดช่วงขายและอย่างน้อยจนถึงวันปลดล็อก LP',
                    'en' => 'Disclose the project, team, unlock schedule and risks honestly, and keep the website and project documents accessible throughout the sale and at least until the LP unlock date.',
                ],
                [
                    'th' => 'แจ้งเหตุการณ์สำคัญที่กระทบผู้ซื้อ เช่น ทีมงานเปลี่ยน โครงการหยุด หรือกระเป๋าถูกเจาะ ต่อแพลตฟอร์มและชุมชนภายใน 3 วัน',
                    'en' => 'Report material events affecting Buyers, such as team changes, project suspension or a compromised wallet, to the Platform and the community within 3 days.',
                ],
                [
                    'th' => 'ตอบคำถามหรือคำขอเอกสารของแพลตฟอร์มภายใน 7 วัน และไม่ลบหรือขัดแย้งกับคำเตือนความเสี่ยงและข้อมูลบนเชนที่แพลตฟอร์มแสดงบนหน้าขาย',
                    'en' => 'Answer the Platform\'s questions or document requests within 7 days, and do not remove or contradict the risk warnings and on-chain facts the Platform shows on the sale page.',
                ],
            ],
        ],
        [
            'id' => 'buyer-risk',
            'title_th' => 'ความเสี่ยงของผู้ซื้อ และการไม่รับประกันผลตอบแทน',
            'title_en' => 'Buyer Risk and No Guarantee of Returns',
            'clauses' => [
                [
                    'th' => 'แพลตฟอร์มเป็นผู้ให้บริการเทคโนโลยี ไม่ใช่ผู้ออกเหรียญลูก นายหน้า ผู้ค้า หรือผู้ให้คำแนะนำการลงทุน แพลตฟอร์มไม่รับประกันราคา สภาพคล่อง การจดทะเบียนซื้อขาย หรือผลตอบแทนของเหรียญลูกใด',
                    'en' => 'The Platform is a technology provider, not the issuer of any Child Token, a broker, a dealer or an investment adviser. The Platform does not guarantee the price, liquidity, listing or returns of any Child Token.',
                ],
                [
                    'th' => 'แพลตฟอร์มไม่ให้บริการรับฝากเงิน (escrow) ยอดขายไหลตามสัญญาอัจฉริยะเข้าสู่พูลสภาพคล่องและกระเป๋าโครงการโดยตรง หน้าขายแสดงคำเตือนความเสี่ยงและข้อเท็จจริงบนเชน และผู้ซื้อต้องยืนยันว่าเข้าใจความเสี่ยงก่อนซื้อ',
                    'en' => 'The Platform provides no escrow. Proceeds flow under the smart contracts directly to the liquidity pool and the project wallet. Sale pages show risk warnings and on-chain facts, and Buyers must confirm they understand the risks before buying.',
                ],
                [
                    'th' => 'ผู้สร้างรับทราบว่าราคาเหรียญลูกอาจลดลงจนไม่มีมูลค่า โดยเฉพาะเมื่อเหรียญที่ล็อกถูกปลด และโครงการอาจล้มเหลวได้ ผู้สร้างต้องสื่อสารความเสี่ยงนี้ต่อผู้ซื้อ',
                    'en' => 'The Creator acknowledges that the Child Token price may fall to zero, especially when locked tokens unlock, and that projects can fail. The Creator must communicate these risks to Buyers.',
                ],
            ],
        ],
        [
            'id' => 'compliance',
            'title_th' => 'การปฏิบัติตามกฎหมาย',
            'title_en' => 'Legal Compliance',
            'clauses' => [
                [
                    'th' => 'ผู้สร้างรับผิดชอบปฏิบัติตามกฎหมายที่ใช้กับโครงการของตนแต่เพียงผู้เดียว รวมถึงพระราชกำหนดการประกอบธุรกิจสินทรัพย์ดิจิทัล พ.ศ. 2561 ประกาศของ ก.ล.ต. กฎหมายภาษีอากร กฎหมายคุ้มครองผู้บริโภค และกฎหมายของทุกประเทศที่มีการเสนอขายเหรียญลูก',
                    'en' => 'The Creator is solely responsible for complying with all laws applicable to its project, including the Emergency Decree on Digital Asset Businesses B.E. 2561 (2018), SEC notifications, tax laws, consumer protection laws and the laws of every country where the Child Token is offered.',
                ],
                [
                    'th' => 'หากเหรียญลูกเข้าข่ายต้องได้รับอนุญาตหรือต้องเสนอขายผ่านผู้ให้บริการระบบเสนอขายโทเคนดิจิทัล (ICO Portal) ผู้สร้างต้องดำเนินการให้ถูกต้องก่อนเสนอขาย แพลตฟอร์มอาจจำกัดการเข้าถึงจากบางประเทศหรือบางกลุ่มผู้ใช้เพื่อให้เป็นไปตามกฎหมาย',
                    'en' => 'If the Child Token requires a licence or must be offered through an ICO portal, the Creator must comply before offering it. The Platform may restrict access from certain countries or user groups to comply with law.',
                ],
            ],
        ],
        [
            'id' => 'ip',
            'title_th' => 'ทรัพย์สินทางปัญญา',
            'title_en' => 'Intellectual Property',
            'clauses' => [
                [
                    'th' => 'ผู้สร้างรับรองว่ามีสิทธิ์ใช้ชื่อ สัญลักษณ์ โลโก้ และเนื้อหาของโครงการ และอนุญาตให้แพลตฟอร์มใช้สิ่งเหล่านี้โดยไม่มีค่าตอบแทนเพื่อแสดงรายการ หน้าขาย และประชาสัมพันธ์บนช่องทางของแพลตฟอร์ม',
                    'en' => 'The Creator represents that it has the right to use the project\'s name, symbol, logo and content, and grants the Platform a royalty-free licence to use them for listings, sale pages and promotion on the Platform\'s channels.',
                ],
            ],
        ],
        [
            'id' => 'pdpa',
            'title_th' => 'การคุ้มครองข้อมูลส่วนบุคคล',
            'title_en' => 'Personal Data Protection',
            'clauses' => [
                [
                    'th' => 'แพลตฟอร์มเก็บรวบรวม ใช้ และเปิดเผยข้อมูลส่วนบุคคลของผู้สร้างตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 เพื่อยืนยันตัวตน พิจารณาใบลงทะเบียน ปฏิบัติตามข้อตกลง ปฏิบัติตามกฎหมาย (รวมถึงการป้องกันการฟอกเงิน) และใช้สิทธิเรียกร้องตามกฎหมาย โดยอาศัยฐานสัญญา ฐานหน้าที่ตามกฎหมาย และฐานประโยชน์โดยชอบด้วยกฎหมาย',
                    'en' => 'The Platform collects, uses and discloses the Creator\'s personal data under the Personal Data Protection Act B.E. 2562 (2019) to verify identity, review the application, perform this Agreement, comply with law (including anti-money laundering) and establish legal claims, on the bases of contract, legal obligation and legitimate interest.',
                ],
                [
                    'th' => 'จากระบบ Thaiprompt แพลตฟอร์มได้รับเฉพาะสถานะผลการยืนยันตัวตน ไม่ได้รับรูปบัตรหรือเลขบัตร ข้อมูลในใบลงทะเบียนถูกเข้ารหัสในฐานข้อมูล ส่วนชื่อโครงการ ข้อมูลเหรียญลูก และที่อยู่กระเป๋าที่ลงทะเบียนอาจถูกเปิดเผยต่อสาธารณะบนหน้าขายและบนบล็อกเชน',
                    'en' => 'From Thaiprompt the Platform receives only the verification status, not ID images or ID numbers. Application data is encrypted in the database. The project name, Child Token details and the Registered Wallet address may be shown publicly on sale pages and on the blockchain.',
                ],
                [
                    'th' => 'แพลตฟอร์มเก็บข้อมูลไว้ตลอดระยะเวลาที่ข้อตกลงมีผล และต่อไปอีก 10 ปีนับแต่สิ้นสุดเพื่อใช้เป็นหลักฐานตามอายุความ เว้นแต่กฎหมายกำหนดให้เก็บนานกว่า ผู้สร้างมีสิทธิ์ขอเข้าถึง แก้ไข ลบ คัดค้าน ระงับการใช้ และขอรับข้อมูลของตน ภายใต้หน้าที่เก็บรักษาตามกฎหมาย และมีสิทธิ์ร้องเรียนต่อสำนักงานคณะกรรมการคุ้มครองข้อมูลส่วนบุคคล',
                    'en' => 'The Platform keeps the data for as long as this Agreement is in force and for 10 years after it ends as evidence within the limitation period, unless law requires longer. The Creator may request access, correction, deletion, objection, restriction and portability of its data, subject to legal retention duties, and may complain to the Personal Data Protection Committee.',
                ],
            ],
        ],
        [
            'id' => 'termination',
            'title_th' => 'การระงับและการยกเลิก',
            'title_en' => 'Suspension and Termination',
            'clauses' => [
                [
                    'th' => 'หากผู้สร้างผิดข้อตกลงหรือมีเหตุอันควรสงสัยว่ากระทำผิดกฎหมาย แพลตฟอร์มอาจระงับหรือเพิกถอนการอนุมัติ ซ่อนหรือปิดหน้าขาย ถอนเหรียญลูกออกจากรายการของแพลตฟอร์ม และแจ้งหน่วยงานที่มีอำนาจ ทั้งนี้แพลตฟอร์มไม่สามารถแก้ไขสัญญาอัจฉริยะที่อยู่บนเชนแล้ว',
                    'en' => 'If the Creator breaches this Agreement or is reasonably suspected of breaking the law, the Platform may suspend or revoke approval, hide or close the sale page, remove the Child Token from the Platform\'s listings and notify the competent authorities. The Platform cannot alter smart contracts already on-chain.',
                ],
                [
                    'th' => 'ผู้สร้างขอถอนการลงทะเบียนได้ก่อนสร้างเหรียญลูก ข้อกำหนดเรื่องความรับผิด การชดใช้ ข้อมูลส่วนบุคคล และกฎหมายที่ใช้บังคับยังมีผลต่อไปหลังข้อตกลงสิ้นสุด',
                    'en' => 'The Creator may withdraw its registration before creating a Child Token. The provisions on liability, indemnity, personal data and governing law survive termination.',
                ],
            ],
        ],
        [
            'id' => 'liability',
            'title_th' => 'ข้อจำกัดความรับผิดและการชดใช้ค่าเสียหาย',
            'title_en' => 'Limitation of Liability and Indemnity',
            'clauses' => [
                [
                    'th' => 'ภายในขอบเขตที่กฎหมายอนุญาต แพลตฟอร์มไม่ต้องรับผิดต่อความเสียหายจากข้อบกพร่องของสัญญาอัจฉริยะหรือบล็อกเชน กระเป๋าของผู้สร้างถูกเจาะ ความผันผวนของตลาด หรือเหตุสุดวิสัย และความรับผิดรวมของแพลตฟอร์มไม่เกินค่าบริการที่ผู้สร้างชำระในช่วง 12 เดือนก่อนเกิดเหตุ ทั้งนี้ไม่ยกเว้นความรับผิดจากการกลฉ้อฉลหรือประมาทเลินเล่ออย่างร้ายแรงของแพลตฟอร์ม',
                    'en' => 'To the extent permitted by law, the Platform is not liable for losses caused by smart contract or blockchain defects, compromise of the Creator\'s wallet, market volatility or force majeure, and the Platform\'s total liability does not exceed the fees the Creator paid in the 12 months before the event. This does not exclude liability for the Platform\'s fraud or gross negligence.',
                ],
                [
                    'th' => 'ผู้สร้างตกลงชดใช้และปกป้องแพลตฟอร์มจากข้อเรียกร้อง ความเสียหาย และค่าใช้จ่าย (รวมถึงค่าทนายความตามสมควร) ที่เกิดจากการผิดข้อตกลง โครงการ หรือเหรียญลูกของผู้สร้าง รวมถึงข้อเรียกร้องของผู้ซื้อ',
                    'en' => 'The Creator agrees to indemnify and hold the Platform harmless from claims, damages and costs (including reasonable legal fees) arising from the Creator\'s breach, project or Child Token, including claims by Buyers.',
                ],
            ],
        ],
        [
            'id' => 'amendments',
            'title_th' => 'การแก้ไขข้อตกลง',
            'title_en' => 'Amendments',
            'clauses' => [
                [
                    'th' => 'แพลตฟอร์มอาจแก้ไขข้อตกลงนี้โดยออกฉบับใหม่ ซึ่งมีหมายเลขฉบับและค่าแฮชของตนเอง การแก้ไขที่เป็นสาระสำคัญจะขอให้ผู้สร้างลงนามใหม่ ฉบับที่ผู้สร้างลงนามไว้ยังใช้บังคับกับการกระทำที่เกิดขึ้นก่อนการลงนามฉบับใหม่',
                    'en' => 'The Platform may amend this Agreement by issuing a new version with its own version number and hash. Material amendments will require the Creator to sign again. The version the Creator signed continues to govern acts that occurred before a new version is signed.',
                ],
            ],
        ],
        [
            'id' => 'law',
            'title_th' => 'กฎหมายที่ใช้บังคับ การระงับข้อพิพาท และภาษาของข้อตกลง',
            'title_en' => 'Governing Law, Disputes and Language',
            'clauses' => [
                [
                    'th' => 'ข้อตกลงนี้อยู่ภายใต้และตีความตามกฎหมายไทย คู่สัญญาจะเจรจาโดยสุจริตภายใน 30 วันก่อน หากตกลงกันไม่ได้ให้ศาลที่มีเขตอำนาจในประเทศไทยเป็นผู้พิจารณา',
                    'en' => 'This Agreement is governed by and construed under Thai law. The parties will first negotiate in good faith for 30 days; failing agreement, the competent courts of Thailand have jurisdiction.',
                ],
                [
                    'th' => 'ข้อตกลงนี้จัดทำเป็นภาษาไทยและภาษาอังกฤษ หากข้อความสองภาษาขัดหรือแย้งกัน ให้ถือตามข้อความภาษาไทยเป็นหลัก',
                    'en' => 'This Agreement is made in Thai and English. If the two texts conflict or are inconsistent, the Thai text prevails.',
                ],
                [
                    'th' => 'หากข้อใดของข้อตกลงนี้เป็นโมฆะหรือใช้บังคับไม่ได้ ข้ออื่นยังคงมีผล การที่แพลตฟอร์มไม่ใช้สิทธิ์ใดไม่ถือเป็นการสละสิทธิ์ ผู้สร้างโอนสิทธิ์ตามข้อตกลงนี้ให้ผู้อื่นไม่ได้หากไม่ได้รับความยินยอมเป็นหนังสือจากแพลตฟอร์ม และการแจ้งให้ส่งทางอีเมลที่ลงทะเบียนหรือผ่านแพลตฟอร์ม',
                    'en' => 'If any term of this Agreement is void or unenforceable, the other terms remain in effect. The Platform\'s failure to exercise a right is not a waiver. The Creator may not assign its rights under this Agreement without the Platform\'s written consent, and notices are given to the registered email or through the Platform.',
                ],
            ],
        ],
    ],
];
