@php
    /**
     * TPIX Chain Whitepaper v2.0 — PDF Template (DomPDF)
     * ข้อมูลเดียวกับ Whitepaper.vue — 18 sections ครบ
     * Developed by Xman Studio
     *
     * Usage: Pdf::loadView('whitepaper.pdf', ['lang' => 'en'|'th'])
     */
    $isEn = ($lang ?? 'en') === 'en';
    $fontDir = storage_path('fonts');

    // === ข้อมูลสองภาษา — ดึงจาก Whitepaper.vue (content object) ===
    $t = $isEn ? [
        'title' => 'TPIX Chain Whitepaper',
        'subtitle' => 'A Next-Generation EVM Blockchain for the ASEAN Digital Economy',
        'version' => 'Version 2.0 — March 2026',
        'toc' => [
            '1. Executive Summary', '2. Problem & Solution', '3. TPIX Chain Architecture',
            '4. Tokenomics', '5. Use Cases & Applications', '6. DEX Protocol',
            '7. Token Sale Details', '8. Master Node & Rewards',
            '9. Living Identity — Seedless Recovery', '10. Validator Governance',
            '11. Cross-Chain Bridge', '12. Ecosystem & Affiliate',
            '13. Platform Integrations', '14. Roadmap', '15. Technology Stack',
            '16. Team & Partners', '17. Security & Audits', '18. Legal Disclaimer',
        ],
        'exec' => [
            'TPIX Chain is a next-generation EVM-compatible blockchain built on Polygon Edge technology, designed specifically for the Thai and Southeast Asian digital economy. With gasless transactions, 2-second block times, and IBFT Proof-of-Authority consensus, TPIX Chain provides an unmatched platform for decentralized applications, DeFi, and real-world asset tokenization.',
            'The native TPIX coin (7 billion fixed supply, 18 decimals) powers the entire ecosystem including: a built-in Uniswap V2 DEX, multi-tier master node system, a token factory for custom ERC-20 creation, cross-chain bridge to BSC, an affiliate referral program, and integration with the Thaiprompt Affiliate enterprise platform serving 500,000+ users.',
            'TPIX is not just a cryptocurrency — it is the backbone of a complete digital economy spanning food supply chain traceability, IoT smart farming, delivery services, e-commerce, AI bot marketplace, hotel booking, carbon credit trading, and enterprise affiliate marketing.',
        ],
        'stats' => [['7B','Total Supply'],['0 Gas','Transaction Fee'],['2s','Block Time'],['~1,500','TPS Capacity'],['IBFT','Consensus'],['~10s','Finality']],
        'problems' => [
            ['High Gas Fees','Ethereum gas fees ($5-50+) and BSC fees ($0.10-1.00) make micro-transactions and daily DeFi usage impractical for average users in developing economies.'],
            ['Complexity Barrier','Existing DEXes and DeFi protocols require deep technical knowledge. The onboarding experience is intimidating for the 95% of people who have never used cryptocurrency.'],
            ['No ASEAN Focus','Major blockchain ecosystems are built for Western markets. There is no localized DeFi ecosystem with Thai/ASEAN language support and culturally relevant use cases.'],
            ['Fragmented Utility','Most tokens lack real-world utility beyond speculation. There is no integrated ecosystem connecting DeFi with real businesses like agriculture, food supply chain, and services.'],
        ],
        'solutions' => [
            ['Zero Gas Fees','All TPIX Chain transactions are completely free. Gas price is hardcoded to 0 in the genesis block, removing the cost barrier permanently.'],
            ['Intuitive UX','Clean, modern interface with Thai language support. Connect wallet, swap tokens, and stake — all in 3 clicks.'],
            ['ASEAN-First Design','Built from the ground up for Thai and Southeast Asian users with full Thai localization, local payment integrations, and culturally relevant use cases.'],
            ['Real-World Integration','TPIX connects DeFi with real businesses: food traceability (FoodPassport), smart farming (IoT), delivery services, e-commerce, and hotel booking.'],
        ],
        'chainSpecs' => [
            ['Chain Name','TPIX Chain'],['Chain ID (Mainnet)','4289'],['Chain ID (Testnet)','4290'],
            ['Consensus','IBFT (Istanbul Byzantine Fault Tolerant)'],['Block Time','2 seconds'],
            ['Finality','~10 seconds (5 blocks)'],['Gas Price','0 (Free — hardcoded in genesis)'],
            ['TPS Capacity','~1,500 transactions/second'],['VM','EVM — full Solidity support'],
            ['Native Coin','TPIX (18 decimals)'],['Total Supply','7,000,000,000 TPIX (pre-mined in genesis)'],
            ['Validators','4 IBFT nodes (BFT tolerates 1 faulty)'],
            ['RPC URL','https://rpc.tpix.online'],['Explorer','https://explorer.tpix.online'],
        ],
        'tokenAlloc' => [['Master Node Rewards','20%','1,400,000,000'],['Ecosystem Development','25%','1,750,000,000'],['Community & Rewards','20%','1,400,000,000'],['Liquidity & Market Making','15%','1,050,000,000'],['Token Sale (ICO)','10%','700,000,000'],['Team & Advisors','10%','700,000,000']],
        'tokenNote' => 'Fixed supply of 7,000,000,000 TPIX with 18 decimals. No inflation or minting mechanism — total supply is pre-mined in the genesis block.',
        'useCases' => [
            ['Decentralized Exchange (DEX)','Trade tokens using AMM with 0.3% swap fee. Provide liquidity and earn trading fees.',['Token swaps via constant product formula (x*y=k)','Liquidity provision with LP token rewards','Farming & yield optimization','Zero gas costs for all trades']],
            ['FoodPassport — Food Supply Chain','Blockchain-based food safety and traceability system. Track food from farm to consumer.',['Farm-to-table traceability via blockchain records','Quality verification with AI image recognition','Certificate management as NFTs on TPIX Chain','Consumer QR scanning for full product history']],
            ['IoT Smart Farm System','Intelligent farming system using IoT sensors and AI, integrated with blockchain.',['Real-time sensor monitoring (temperature, humidity, light, soil)','Automated irrigation, fertilization, and lighting control','Agricultural data marketplace — sell farm data for TPIX','Carbon credit generation and trading on-chain']],
            ['Multi-Service Delivery Platform','Full-stack delivery platform for food, groceries, courier services.',['Food, grocery, and courier delivery','Service marketplace (cleaning, repair, etc.)','3% TPIX cashback on every order','Real-time order tracking on-chain']],
            ['AI Bot Marketplace','Buy, sell, and subscribe to AI-powered bots for trading and business automation.',['LINE Official Account AI chatbots','Trading bots with sentiment analysis','Monthly subscription payments in TPIX','Creator revenue sharing program']],
            ['Hotel & Travel Booking','Decentralized hotel booking with TPIX payment, cashback rewards, and loyalty.',['Direct hotel booking with TPIX payment','3% cashback rewards on every booking','Loyalty program with TPIX accumulation','Instant settlement to hotel operators']],
            ['E-Commerce & Marketplace','Multi-vendor marketplace supporting TPIX payments with 5% cashback.',['Multi-vendor marketplace with TPIX payments','5% cashback in TPIX on all purchases','POS integration for physical stores','Affiliate commission tracking and auto-payout']],
            ['Token Factory','Create custom ERC-20 tokens for loyalty programs, vouchers, and business tokens.',['Create ERC-20 tokens for 100 TPIX','Point tokens, voucher tokens, membership NFTs','All subsequent transactions are gas-free','Perfect for loyalty programs and business tokens']],
            ['Carbon Credit Trading','Blockchain-based carbon credit marketplace integrated with IoT Smart Farm.',['Carbon credit tokenization as NFTs','Transparent on-chain trading','Smart farm integration for automated verification','Compliance with international carbon standards']],
            ['AI Autonomous Ecosystem','Self-improving AI system that builds and manages AI agents autonomously.',['AI-building-AI: self-improving agent creation','Autonomous system management 24/7','Predictive analytics and automated decision-making','TPIX payment for AI compute resources']],
        ],
        'dexDesc' => 'TPIX DEX is a Uniswap V2 fork deployed natively on TPIX Chain. It provides automated market making (AMM) with constant product formula (x*y=k) and a 0.3% swap fee (0.25% to LPs, 0.05% to protocol treasury).',
        'dexContracts' => [['TPIXDEXFactory','Creates and manages trading pair contracts'],['TPIXDEXRouter02','Handles multi-hop swaps and liquidity operations'],['TPIXDEXPair','Individual liquidity pool with ERC-20 LP tokens'],['WTPIX','Wrapped TPIX for ERC-20 compatibility within the DEX']],
        'saleDesc' => 'The TPIX token sale is conducted in 3 phases, accepting BNB and USDT on BSC. Purchased tokens are allocated with a vesting schedule and can be claimed as wTPIX (BEP-20) or native TPIX once the bridge is live.',
        'salePhases' => [['Private Sale','$0.05','100M TPIX','10%','30d cliff, 180d linear'],['Pre-Sale','$0.08','200M TPIX','15%','14d cliff, 120d linear'],['Public Sale','$0.10','400M TPIX','25%','No cliff, 90d linear']],
        'mnDesc' => 'TPIX uses IBFT2 Proof-of-Authority consensus with a 4-tier master node system. Validators are real IBFT2 block sealers with governance power (requiring 10M TPIX + company KYC). Guardian, Sentinel, and Light nodes stake TPIX to participate in the network and earn proportional rewards from a 1.4 billion TPIX reward pool distributed over 3 years (ending 2028) with decreasing emission.',
        'mnTiers' => [['Validator Node','10,000,000 TPIX','15-20%','180 days','21','20% of reward pool'],['Guardian Node','1,000,000 TPIX','10-12%','90 days','100','35% of reward pool'],['Sentinel Node','100,000 TPIX','7-9%','30 days','500','30% of reward pool'],['Light Node','10,000 TPIX','4-6%','7 days','Unlimited','15% of reward pool']],
        'mnEmission' => [['Year 1 (2025-2026)','600,000,000 TPIX','~38.3 TPIX','42.9%'],['Year 2 (2026-2027)','500,000,000 TPIX','~31.9 TPIX','35.7%'],['Year 3 (2027-2028)','300,000,000 TPIX','~19.1 TPIX','21.4%']],
        'mnRewardSplit' => 'Each block reward is split: 20% to Validators (IBFT2 sealers), 35% to Guardian nodes, 30% shared among Sentinel nodes, and 15% shared among Light nodes (weighted by stake amount and uptime score).',
        'mnSlashing' => 'Validators face 15% stake slashing for misbehavior. Guardian nodes face 10% slashing if offline >24h. Sentinel nodes face 5% slashing if offline >48h. Light nodes have no slashing penalty.',
        'livingIdDesc' => 'Living Identity (TPIXIdentity smart contract) allows users to recover wallet access without seed phrases by combining three verification factors: (1) Knowledge factor — answers to personal security questions. (2) Location factor — GPS coordinates of meaningful locations. (3) Possession factor — a 6-digit recovery PIN. All data is stored as a 32-byte keccak256 hash on-chain (zero PII exposure). A 48-hour timelock prevents immediate theft even if all factors are compromised.',
        'govDesc' => 'The ValidatorGovernance smart contract enables on-chain governance exclusively for Validator-tier nodes (10M TPIX stake + KYC-approved). Proposal types: AddValidator, RemoveValidator, ChangeParameter, UpgradeContract, General. Voting requires >50% quorum and >50% approval, with a 48-hour timelock before execution.',
        'bridgeDesc' => 'The TPIX Bridge enables seamless asset transfer between TPIX Chain (native TPIX) and BNB Smart Chain (wTPIX BEP-20). wTPIX has a 700M max supply cap (10% of total). Bridge fee is 0.1% (90% to treasury, 10% burned). Total native TPIX + wTPIX always equals 7 billion.',
        'integrations' => [['Thaiprompt Affiliate','Enterprise MLM platform with 500,000+ users',['Auto TPIX wallet on signup','Commission payout in TPIX','Rank bonuses','Activity rewards (100 TPIX signup, 50 TPIX referral)']],['FoodPassport','Blockchain food traceability system',['Pay for quality verification','Certificate NFTs on TPIX Chain','Farmer rewards','Supply chain data access']],['Delivery Platform','Multi-service delivery ecosystem',['TPIX payment for orders','3% cashback per order','Rider TPIX earnings','Merchant instant settlement']],['IoT Smart Farm','AI-powered agriculture system',['Sensor data marketplace','Equipment rental with TPIX','Carbon credit trading','Yield prediction services']]],
        'roadmap' => [
            ['Q1-Q2 2023','Concept & Foundation','done',['Whitepaper & tokenomics design','Technical architecture planning','Team formation','Initial funding & partnerships']],
            ['Q3-Q4 2023','Blockchain Development','done',['Polygon Edge core implementation','TPIX native coin (7B fixed supply)','IBFT 2.0 consensus & EVM integration','Testnet deployment (Chain ID 4290)']],
            ['Q1-Q2 2024','Platform Integration','done',['Laravel service integration','REST API (500+ endpoints)','Block explorer (Blockscout)','Docker deployment & monitoring']],
            ['Q3-Q4 2024','Ecosystem Build','done',['DEX smart contracts (TPIXRouter, Factory, Pair)','Master Node system (NodeRegistryV2 — 4 tiers)','ValidatorGovernance & ValidatorKYC','Faucet service & SDK development']],
            ['Q1-Q2 2025','Mainnet & DeFi Launch','done',['TPIX Chain mainnet live (Chain ID 4289)','TPIX TRADE DEX platform launch','wTPIX (BEP-20) bridge on BSC','Token Sale contract (TPIXTokenSale.sol)']],
            ['Q3-Q4 2025','Products & Applications','done',['Living Identity — seedless wallet recovery','Master Node UI desktop app (Electron)','TPIX Wallet mobile app (Flutter)','Token Factory — permissionless ERC-20']],
            ['Q1 2026','Production & Token Sale','current',['Token Sale 3 phases (Private/Pre-Sale/Public)','Whitepaper v2.0 publication','Internal order book matching engine','Admin panel — fee management, analytics','Carbon Credit & FoodPassport']],
            ['Q2 2026','DeFi Infrastructure','planned',['BSC Bridge activation (wTPIX <-> native TPIX)','4-Tier master node staking activation','TPIXRouter fee collection live','DEX AMM liquidity pools deployment','Mobile app (React Native) release']],
            ['Q3 2026','Ecosystem Growth','planned',['Token Factory public launch','Affiliate/Referral program activation','CEX listing applications','Validator KYC onboarding','Carbon Credit & FoodPassport pilots']],
            ['Q4 2026','Scale & Governance','planned',['DAO governance transition','Multi-chain bridge expansion','NFT marketplace launch','Validator set decentralization (21 nodes)','Master Node UI — macOS/Linux']],
            ['2027','Global Expansion','planned',['Full DAO governance activation','Multi-language (Japanese, Korean, Vietnamese)','Carbon credit exchange full launch','Government partnership pilots','Year 2 emission reduction']],
        ],
        'techStack' => [['Blockchain',[['Polygon Edge','Modular blockchain framework (Go)'],['IBFT Consensus','Byzantine fault tolerant PoA'],['EVM','Full Solidity smart contract support'],['LevelDB','High-performance storage layer']]],['Smart Contracts',[['Solidity ^0.8.20','Smart contract language'],['Hardhat','Development & testing framework'],['OpenZeppelin','Audited security libraries'],['ethers.js','Web3 interaction library']]],['Backend',[['Laravel 11','Enterprise PHP framework'],['PHP 8.2+','Server-side language'],['MySQL 8.0+','Relational database'],['Redis','Caching & queue management']]],['Frontend',[['Vue.js 3','Reactive frontend framework'],['Inertia.js','SPA without API'],['TailwindCSS','Utility-first styling'],['Chart.js','Data visualization']]],['Infrastructure',[['Docker','Container orchestration'],['Blockscout','Open-source block explorer'],['Prometheus + Grafana','Metrics & monitoring'],['GitHub Actions','CI/CD pipeline']]]],
        'teamDesc' => 'TPIX Chain is developed by Xman Studio, an experienced blockchain development team specializing in DeFi, Web3, and enterprise applications for the Southeast Asian market.',
        'teamHighlights' => ['500,000+ platform users on Thaiprompt Affiliate','389 Eloquent models, 294 controllers, 500,000+ lines of production code','20+ integrated business modules (MLM, e-commerce, AI, IoT, hotel booking)','Live blockchain infrastructure with Blockscout explorer'],
        'security' => [['Smart Contract Audits','All contracts undergo third-party security audits before mainnet deployment. OpenZeppelin libraries used.'],['IBFT Consensus','Byzantine fault tolerance ensures network security. Tolerates up to 1 faulty validator.'],['Rate Limiting','RPC-level rate limiting (5-50 req/hr per endpoint) prevents spam attacks on the gasless chain.'],['Multi-Sig Treasury','Protocol funds managed by multi-signature wallets requiring 3-of-5 signatures.'],['Bug Bounty Program','Ongoing program with rewards up to 10,000 TPIX for critical vulnerability disclosure.'],['Infrastructure Security','Docker containerization, encrypted RPC (TLS), firewall-protected validators, automated backups.']],
        'disclaimer' => 'This whitepaper is for informational purposes only and does not constitute investment advice. TPIX tokens are utility tokens designed for use within the TPIX Chain ecosystem and are not intended to be securities. The purchase of TPIX involves significant risk, including the risk of losing all purchase amount. All forward-looking statements are subject to change based on market conditions and technical feasibility.',
    ] : [
        'title' => 'TPIX Chain ไวท์เปเปอร์',
        'subtitle' => 'บล็อกเชน EVM ยุคใหม่ สำหรับเศรษฐกิจดิจิทัลอาเซียน',
        'version' => 'เวอร์ชัน 2.0 — มีนาคม 2569',
        'toc' => [
            '1. บทสรุปผู้บริหาร', '2. ปัญหาและทางออก', '3. สถาปัตยกรรม TPIX Chain',
            '4. โทเคโนมิกส์', '5. กรณีการใช้งานและแอปพลิเคชัน', '6. โปรโตคอล DEX',
            '7. รายละเอียดการขายโทเคน', '8. Master Node และรางวัล',
            '9. Living Identity — กู้กระเป๋าไม่ต้องใช้ Seed Phrase', '10. Validator Governance',
            '11. Cross-Chain Bridge', '12. ระบบนิเวศและ Affiliate',
            '13. การเชื่อมต่อแพลตฟอร์ม', '14. แผนงาน (Roadmap)', '15. เทคโนโลยีที่ใช้',
            '16. ทีมงานและพาร์ทเนอร์', '17. ความปลอดภัยและการตรวจสอบ', '18. ข้อจำกัดความรับผิดชอบ',
        ],
        'exec' => [
            'TPIX Chain เป็นบล็อกเชนที่รองรับ EVM สร้างบนเทคโนโลยี Polygon Edge ออกแบบมาโดยเฉพาะสำหรับเศรษฐกิจดิจิทัลไทยและอาเซียน ด้วยการทำธุรกรรมไม่เสียค่า Gas, เวลาสร้างบล็อก 2 วินาที และ IBFT Proof-of-Authority consensus ทำให้ TPIX Chain เป็นแพลตฟอร์มที่เหนือกว่าสำหรับแอปพลิเคชันกระจายอำนาจ, DeFi และการโทเคไนซ์สินทรัพย์ในโลกจริง',
            'เหรียญ TPIX (จำนวนคงที่ 7 พันล้าน, 18 ทศนิยม) เป็นพลังขับเคลื่อนระบบนิเวศทั้งหมด ได้แก่: DEX ในตัว (Uniswap V2), ระบบ Master Node หลายระดับ, โรงงานสร้างโทเคน ERC-20, สะพานข้ามเชนไป BSC, โปรแกรม Affiliate และการเชื่อมต่อกับแพลตฟอร์ม Thaiprompt Affiliate ที่มีผู้ใช้กว่า 500,000 คน',
            'TPIX ไม่ใช่แค่สกุลเงินดิจิทัล — แต่เป็นกระดูกสันหลังของเศรษฐกิจดิจิทัลครบวงจร ครอบคลุมระบบตรวจสอบย้อนกลับห่วงโซ่อาหาร, ฟาร์มอัจฉริยะ IoT, บริการส่งสินค้า, อีคอมเมิร์ซ, ตลาด AI Bot, จองโรงแรม, ซื้อขายคาร์บอนเครดิต และการตลาดแบบ Affiliate ระดับองค์กร',
        ],
        'stats' => [['7B','จำนวนทั้งหมด'],['0 Gas','ค่าธรรมเนียม'],['2 วิ','เวลาสร้างบล็อก'],['~1,500','TPS'],['IBFT','Consensus'],['~10 วิ','Finality']],
        'problems' => [
            ['ค่า Gas สูง','ค่า Gas ของ Ethereum ($5-50+) และ BSC ($0.10-1.00) ทำให้ธุรกรรมขนาดเล็กและการใช้ DeFi ประจำวันไม่คุ้มค่าสำหรับผู้ใช้ทั่วไป'],
            ['ความซับซ้อนสูง','DEX และโปรโตคอล DeFi ที่มีอยู่ต้องการความรู้ทางเทคนิคอย่างลึกซึ้ง ประสบการณ์การใช้งานน่ากลัวสำหรับ 95% ของคนที่ไม่เคยใช้คริปโต'],
            ['ไม่เน้นอาเซียน','ระบบนิเวศบล็อกเชนหลักๆ สร้างขึ้นสำหรับตลาดตะวันตก ยังไม่มีระบบ DeFi ที่รองรับภาษาไทย/อาเซียน'],
            ['ประโยชน์กระจัดกระจาย','โทเคนส่วนใหญ่ไม่มีประโยชน์ในโลกจริงนอกเหนือจากการเก็งกำไร ไม่มีระบบนิเวศที่เชื่อมต่อ DeFi กับธุรกิจจริง'],
        ],
        'solutions' => [
            ['ค่า Gas เป็นศูนย์','ธุรกรรมทั้งหมดบน TPIX Chain ฟรีทั้งหมด Gas price ถูกกำหนดเป็น 0 ใน genesis block อย่างถาวร'],
            ['ใช้ง่ายมาก','อินเทอร์เฟซสะอาด ทันสมัย รองรับภาษาไทย เชื่อมต่อ wallet, สลับโทเคน, Stake — ทำได้ใน 3 คลิก'],
            ['ออกแบบเพื่ออาเซียน','สร้างตั้งแต่แรกเพื่อผู้ใช้ไทยและเอเชียตะวันออกเฉียงใต้ แปลภาษาไทยครบ เชื่อมต่อการชำระเงินท้องถิ่น'],
            ['เชื่อมต่อธุรกิจจริง','TPIX เชื่อม DeFi กับธุรกิจจริง: ตรวจสอบอาหาร (FoodPassport), ฟาร์มอัจฉริยะ (IoT), บริการส่งของ, อีคอมเมิร์ซ, จองโรงแรม'],
        ],
        'chainSpecs' => [
            ['ชื่อเชน','TPIX Chain'],['Chain ID (Mainnet)','4289'],['Chain ID (Testnet)','4290'],
            ['Consensus','IBFT (Istanbul Byzantine Fault Tolerant)'],['เวลาสร้างบล็อก','2 วินาที'],
            ['Finality','~10 วินาที (5 บล็อก)'],['ค่า Gas','0 (ฟรี — กำหนดใน genesis)'],
            ['ความจุ TPS','~1,500 ธุรกรรม/วินาที'],['VM','EVM — รองรับ Solidity เต็มรูปแบบ'],
            ['Native Coin','TPIX (18 ทศนิยม)'],['จำนวนทั้งหมด','7,000,000,000 TPIX (pre-mined ใน genesis)'],
            ['Validator','4 โหนด IBFT (BFT ทนได้ 1 โหนดที่มีปัญหา)'],
            ['RPC URL','https://rpc.tpix.online'],['Explorer','https://explorer.tpix.online'],
        ],
        'tokenAlloc' => [['รางวัล Master Node','20%','1,400,000,000'],['พัฒนาระบบนิเวศ','25%','1,750,000,000'],['ชุมชนและรางวัล','20%','1,400,000,000'],['สภาพคล่องและ Market Making','15%','1,050,000,000'],['ขายโทเคน (ICO)','10%','700,000,000'],['ทีมงานและที่ปรึกษา','10%','700,000,000']],
        'tokenNote' => 'จำนวนคงที่ 7,000,000,000 TPIX (18 ทศนิยม) ไม่มีเงินเฟ้อหรือกลไก mint — จำนวนทั้งหมด pre-mined ใน genesis block',
        'useCases' => [
            ['กระดานแลกเปลี่ยนกระจายอำนาจ (DEX)','ซื้อขายโทเคนด้วย AMM ค่าธรรมเนียม 0.3% เพิ่มสภาพคล่องเพื่อรับค่าธรรมเนียม',['สลับโทเคนด้วยสูตร x*y=k','เพิ่มสภาพคล่อง รับ LP Token','Farming & yield optimization','ค่า Gas เป็น 0 สำหรับทุกการซื้อขาย']],
            ['FoodPassport — ระบบตรวจสอบย้อนกลับอาหาร','ระบบตรวจสอบความปลอดภัยอาหารบนบล็อกเชน ติดตามอาหารจากฟาร์มถึงผู้บริโภค',['ตรวจสอบย้อนกลับจากฟาร์มถึงโต๊ะอาหาร','ตรวจสอบคุณภาพด้วย AI Image Recognition','จัดการใบรับรองเป็น NFT บน TPIX Chain','ผู้บริโภคสแกน QR ดูประวัติสินค้า']],
            ['ระบบฟาร์มอัจฉริยะ IoT','ระบบฟาร์มอัจฉริยะด้วยเซ็นเซอร์ IoT และ AI เชื่อมต่อกับบล็อกเชน',['ติดตามเซ็นเซอร์แบบเรียลไทม์','ควบคุมระบบน้ำ ปุ๋ย แสงอัตโนมัติ','ตลาดข้อมูลเกษตร — ขายข้อมูลเป็น TPIX','สร้างและซื้อขายคาร์บอนเครดิตบนเชน']],
            ['แพลตฟอร์มส่งสินค้าครบวงจร','แพลตฟอร์มส่งอาหาร ของชำ พัสดุ และบริการ ใช้ TPIX เป็นระบบชำระเงิน',['ส่งอาหาร ของชำ และพัสดุ','ตลาดบริการ (ทำความสะอาด ซ่อมแซม)','คืนเงิน 3% เป็น TPIX ทุกออเดอร์','ติดตามออเดอร์แบบเรียลไทม์บนเชน']],
            ['ตลาด AI Bot','ซื้อ ขาย และสมัครสมาชิก AI Bot สำหรับการเทรด บริการลูกค้า และอัตโนมัติธุรกิจ',['LINE Official Account AI chatbot','บอทเทรดพร้อม sentiment analysis','จ่ายค่าสมาชิกรายเดือนด้วย TPIX','โปรแกรมแบ่งรายได้ผู้สร้าง']],
            ['ระบบจองโรงแรมและท่องเที่ยว','จองโรงแรมแบบกระจายอำนาจ ชำระด้วย TPIX พร้อมรางวัล cashback',['จองโรงแรมโดยตรงด้วย TPIX','คืนเงิน 3% ทุกการจอง','โปรแกรมสะสม TPIX','โอนเงินให้โรงแรมทันที']],
            ['อีคอมเมิร์ซและตลาด','ตลาดออนไลน์หลายร้านค้ารองรับ TPIX คืนเงิน 5%',['ตลาดหลายร้านค้าชำระด้วย TPIX','คืนเงิน 5% เป็น TPIX','POS สำหรับร้านค้าจริง','ติดตามคอมมิชชั่น Affiliate อัตโนมัติ']],
            ['โรงงานสร้างโทเคน','สร้างโทเคน ERC-20 บน TPIX Chain สำหรับ loyalty program, voucher',['สร้างโทเคน ERC-20 ด้วย 100 TPIX','โทเคนสะสมแต้ม, บัตรกำนัล, NFT สมาชิก','ธุรกรรมต่อมาทั้งหมดฟรี','เหมาะสำหรับ loyalty program']],
            ['ตลาดซื้อขายคาร์บอนเครดิต','ตลาดคาร์บอนเครดิตบนบล็อกเชน เชื่อมกับ IoT Smart Farm',['โทเคไนซ์คาร์บอนเครดิตเป็น NFT','ซื้อขายโปร่งใสบนเชน','เชื่อมกับ Smart Farm ตรวจสอบอัตโนมัติ','รองรับมาตรฐานคาร์บอนสากล']],
            ['ระบบ AI อัตโนมัติ','ระบบ AI ที่พัฒนาตัวเอง สร้างและจัดการ AI agent อัตโนมัติ ทำงาน 24/7',['AI สร้าง AI อัตโนมัติ (Self-improving)','จัดการระบบอัตโนมัติ 24/7','วิเคราะห์และตัดสินใจอัตโนมัติ','ชำระค่า AI compute ด้วย TPIX']],
        ],
        'dexDesc' => 'TPIX DEX เป็น Uniswap V2 fork ที่ deploy บน TPIX Chain โดยตรง ใช้ AMM สูตร x*y=k ค่าธรรมเนียม 0.3% (0.25% ให้ LP, 0.05% ให้คลัง protocol)',
        'dexContracts' => [['TPIXDEXFactory','สร้างและจัดการ trading pair contracts'],['TPIXDEXRouter02','จัดการ multi-hop swaps และสภาพคล่อง'],['TPIXDEXPair','สระสภาพคล่อง พร้อม ERC-20 LP tokens'],['WTPIX','Wrapped TPIX สำหรับใช้ใน DEX']],
        'saleDesc' => 'การขายเหรียญ TPIX แบ่งเป็น 3 รอบ รับ BNB และ USDT บน BSC เหรียญที่ซื้อจะมีตาราง vesting สามารถเคลมเป็น wTPIX (BEP-20) หรือ TPIX native เมื่อ bridge พร้อม',
        'salePhases' => [['Private Sale','$0.05','100M TPIX','10%','30 วัน cliff, 180 วัน linear'],['Pre-Sale','$0.08','200M TPIX','15%','14 วัน cliff, 120 วัน linear'],['Public Sale','$0.10','400M TPIX','25%','ไม่มี cliff, 90 วัน linear']],
        'mnDesc' => 'TPIX ใช้ IBFT2 Proof-of-Authority consensus พร้อมระบบ Master Node 4 ระดับ Validator เป็น IBFT2 block sealer ตัวจริงมีสิทธิ์โหวต governance (ต้อง 10M TPIX + KYC บริษัท) Guardian, Sentinel และ Light node stake TPIX เพื่อรับรางวัลจากกองทุน 1.4 พันล้าน TPIX แจกจ่ายตลอด 3 ปี (ถึง 2028)',
        'mnTiers' => [['Validator Node','10,000,000 TPIX','15-20%','180 วัน','21','20% ของรางวัล'],['Guardian Node','1,000,000 TPIX','10-12%','90 วัน','100','35% ของรางวัล'],['Sentinel Node','100,000 TPIX','7-9%','30 วัน','500','30% ของรางวัล'],['Light Node','10,000 TPIX','4-6%','7 วัน','ไม่จำกัด','15% ของรางวัล']],
        'mnEmission' => [['ปีที่ 1 (2025-2026)','600,000,000 TPIX','~38.3 TPIX','42.9%'],['ปีที่ 2 (2026-2027)','500,000,000 TPIX','~31.9 TPIX','35.7%'],['ปีที่ 3 (2027-2028)','300,000,000 TPIX','~19.1 TPIX','21.4%']],
        'mnRewardSplit' => 'รางวัลแต่ละบล็อกแบ่ง: 20% ให้ Validator, 35% ให้ Guardian, 30% แบ่งให้ Sentinel, 15% แบ่งให้ Light nodes (ถ่วงน้ำหนักตาม stake และ uptime)',
        'mnSlashing' => 'Validator ถูกหัก stake 15% หากทำผิด Guardian ถูกหัก 10% หาก offline เกิน 24 ชม. Sentinel ถูกหัก 5% หาก offline เกิน 48 ชม. Light node ไม่มีการลงโทษ',
        'livingIdDesc' => 'Living Identity (TPIXIdentity smart contract) ให้ผู้ใช้กู้กระเป๋าโดยไม่ต้องใช้ seed phrase ด้วย 3 ปัจจัยยืนยัน: (1) ปัจจัยความรู้ — คำตอบคำถามความปลอดภัย (2) ปัจจัยสถานที่ — พิกัด GPS (3) ปัจจัยการครอบครอง — PIN 6 หลัก ข้อมูลเก็บเป็น keccak256 hash 32 bytes บนเชน (ไม่มีข้อมูลส่วนตัว) Timelock 48 ชั่วโมงป้องกันการโจรกรรม',
        'govDesc' => 'Smart contract ValidatorGovernance เปิดใช้การปกครองบนเชนเฉพาะสำหรับ Validator (10M TPIX + KYC) ประเภทข้อเสนอ: AddValidator, RemoveValidator, ChangeParameter, UpgradeContract, General การโหวตต้อง >50% quorum และ >50% approval พร้อม timelock 48 ชั่วโมง',
        'bridgeDesc' => 'TPIX Bridge เปิดให้โอนสินทรัพย์ระหว่าง TPIX Chain (native TPIX) และ BNB Smart Chain (wTPIX BEP-20) wTPIX มี supply cap สูงสุด 700M (10% ของทั้งหมด) ค่าธรรมเนียม 0.1% (90% ให้คลัง, 10% burn) จำนวน native TPIX + wTPIX รวมเท่ากับ 7 พันล้านเสมอ',
        'integrations' => [['Thaiprompt Affiliate','แพลตฟอร์ม MLM ผู้ใช้ 500,000+ คน',['สร้าง TPIX wallet อัตโนมัติเมื่อสมัคร','จ่ายคอมมิชชั่นเป็น TPIX','โบนัสจากการเลื่อนระดับ','รางวัลกิจกรรม (100 TPIX สมัคร, 50 TPIX แนะนำ)']],['FoodPassport','ระบบตรวจสอบอาหารบนบล็อกเชน',['ชำระค่าตรวจสอบคุณภาพ','ใบรับรอง NFT บน TPIX Chain','รางวัลเกษตรกร','เข้าถึงข้อมูล Supply Chain']],['Delivery Platform','ระบบส่งสินค้าครบวงจร',['ชำระด้วย TPIX','คืนเงิน 3% ต่อออเดอร์','ค่าจ้างผู้ส่งเป็น TPIX','โอนเงินร้านค้าทันที']],['IoT Smart Farm','ระบบเกษตรอัจฉริยะด้วย AI',['ตลาดข้อมูลเซ็นเซอร์','เช่าอุปกรณ์ด้วย TPIX','ซื้อขายคาร์บอนเครดิต','บริการทำนายผลผลิต']]],
        'roadmap' => [
            ['Q1-Q2 2566','แนวคิดและรากฐาน','done',['ออกแบบ Whitepaper & tokenomics','วางแผนสถาปัตยกรรม','จัดตั้งทีม','ระดมทุนและจับมือพาร์ทเนอร์']],
            ['Q3-Q4 2566','พัฒนาบล็อกเชน','done',['สร้าง Polygon Edge core','เหรียญ TPIX (7B fixed supply)','IBFT 2.0 consensus & EVM','Deploy Testnet (Chain ID 4290)']],
            ['Q1-Q2 2567','เชื่อมต่อแพลตฟอร์ม','done',['เชื่อมต่อ Laravel service','REST API (500+ endpoints)','Block Explorer (Blockscout)','Docker deployment & monitoring']],
            ['Q3-Q4 2567','สร้างระบบนิเวศ','done',['DEX smart contracts','ระบบ Master Node (4 ระดับ)','ValidatorGovernance & ValidatorKYC','Faucet service & SDK']],
            ['Q1-Q2 2568','Mainnet & DeFi Launch','done',['เปิด TPIX Chain mainnet','TPIX TRADE DEX','wTPIX (BEP-20) bridge บน BSC','Token Sale contract']],
            ['Q3-Q4 2568','ผลิตภัณฑ์และแอป','done',['Living Identity','Master Node UI (Electron)','TPIX Wallet (Flutter)','Token Factory']],
            ['Q1 2569','Production & Token Sale','current',['Token Sale 3 เฟส','Whitepaper v2.0','ระบบจับคู่ออเดอร์','แอดมินแพเนล','Carbon Credit & FoodPassport']],
            ['Q2 2569','โครงสร้าง DeFi','planned',['เปิด Bridge BSC','staking Master Node 4 ระดับ','TPIXRouter เก็บค่าธรรมเนียม','Deploy AMM pools','แอปมือถือ (React Native)']],
            ['Q3 2569','เติบโตระบบนิเวศ','planned',['Token Factory สาธารณะ','Affiliate/Referral','สมัครลิสต์ CEX','Validator KYC','นำร่อง Carbon Credit']],
            ['Q4 2569','ขยายขนาด & Governance','planned',['DAO governance','ขยาย Bridge ข้ามเชน','NFT marketplace','กระจายอำนาจ Validator','Master Node UI macOS/Linux']],
            ['2570','ขยายสู่ระดับสากล','planned',['DAO governance เต็มรูปแบบ','หลายภาษา (ญี่ปุ่น, เกาหลี, เวียดนาม)','Carbon Credit เต็มรูป','ความร่วมมือภาครัฐ','ลด emission ปีที่ 2']],
        ],
        'techStack' => [['Blockchain',[['Polygon Edge','เฟรมเวิร์กบล็อกเชน (Go)'],['IBFT Consensus','Byzantine fault tolerant PoA'],['EVM','รองรับ Solidity smart contract'],['LevelDB','ชั้น storage ประสิทธิภาพสูง']]],['Smart Contracts',[['Solidity ^0.8.20','ภาษา smart contract'],['Hardhat','เฟรมเวิร์กพัฒนาและทดสอบ'],['OpenZeppelin','ไลบรารีความปลอดภัย'],['ethers.js','ไลบรารี Web3']]],['Backend',[['Laravel 11','เฟรมเวิร์ก PHP ระดับ Enterprise'],['PHP 8.2+','ภาษาฝั่งเซิร์ฟเวอร์'],['MySQL 8.0+','ฐานข้อมูลเชิงสัมพันธ์'],['Redis','แคชและจัดการคิว']]],['Frontend',[['Vue.js 3','เฟรมเวิร์ก frontend แบบ reactive'],['Inertia.js','SPA ไม่ต้องสร้าง API'],['TailwindCSS','Utility-first styling'],['Chart.js','แสดงข้อมูลเชิงภาพ']]],['Infrastructure',[['Docker','จัดการ container'],['Blockscout','Block explorer โอเพนซอร์ส'],['Prometheus + Grafana','ตรวจสอบและแดชบอร์ด'],['GitHub Actions','CI/CD pipeline']]]],
        'teamDesc' => 'TPIX Chain พัฒนาโดย Xman Studio ทีมพัฒนาบล็อกเชนที่มีประสบการณ์ เชี่ยวชาญด้าน DeFi, Web3 และแอปพลิเคชันระดับองค์กรสำหรับตลาดเอเชียตะวันออกเฉียงใต้',
        'teamHighlights' => ['ผู้ใช้แพลตฟอร์ม 500,000+ คนบน Thaiprompt Affiliate','389 Eloquent models, 294 controllers, โค้ด 500,000+ บรรทัด','20+ โมดูลธุรกิจ (MLM, อีคอมเมิร์ซ, AI, IoT, จองโรงแรม)','โครงสร้างบล็อกเชนพร้อม Blockscout explorer'],
        'security' => [['ตรวจสอบ Smart Contract','ทุก contract ผ่านการตรวจสอบจากบุคคลที่สาม ใช้ไลบรารี OpenZeppelin'],['IBFT Consensus','Byzantine fault tolerance รับประกันความปลอดภัย ทนได้ถึง 1 validator ที่มีปัญหา'],['Rate Limiting','จำกัดอัตรา RPC (5-50 req/hr) ป้องกัน spam บนเชนที่ไม่เสียค่า Gas'],['กระเป๋า Multi-Sig','กองทุน protocol จัดการด้วย multi-signature wallet ต้องใช้ 3 จาก 5 ลายเซ็น'],['โปรแกรม Bug Bounty','รางวัลสูงสุด 10,000 TPIX สำหรับการเปิดเผยช่องโหว่ร้ายแรง'],['ความปลอดภัยโครงสร้าง','Docker containerization, RPC เข้ารหัส (TLS), validator มี firewall, backup อัตโนมัติ']],
        'disclaimer' => 'เอกสารนี้มีวัตถุประสงค์เพื่อให้ข้อมูลเท่านั้น ไม่ถือเป็นคำแนะนำการลงทุน โทเคน TPIX เป็น utility token สำหรับใช้ในระบบนิเวศ TPIX Chain ไม่ใช่หลักทรัพย์ การซื้อโทเคน TPIX มีความเสี่ยงที่จะสูญเสียเงินซื้อทั้งหมด ข้อความที่มองไปข้างหน้าอาจเปลี่ยนแปลงตามสภาวะตลาดและความเป็นไปได้ทางเทคนิค',
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ $isEn ? 'en' : 'th' }}">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ $t['title'] }}</title>
<style>
    @font-face { font-family:'Sarabun'; font-style:normal; font-weight:400; src:url('{{ $fontDir }}/Sarabun-Regular.ttf') format('truetype'); }
    @font-face { font-family:'Sarabun'; font-style:normal; font-weight:700; src:url('{{ $fontDir }}/Sarabun-Bold.ttf') format('truetype'); }
    @page { margin:50px 40px 60px 40px; size:A4; }
    body { font-family:'Sarabun','Helvetica','Arial',sans-serif; color:#1e293b; font-size:10pt; line-height:1.6; margin:0; padding:0; }
    .pb { page-break-before:always; }
    h1,h2,h3 { page-break-after:avoid; }
    p { orphans:3; widows:3; }
    thead { display:table-header-group; }
    tr { page-break-inside:avoid; }
    .cover { text-align:center; padding-top:180px; page-break-after:always; }
    .cover h1 { font-size:36pt; color:#0891b2; margin:0 0 6px; letter-spacing:2px; }
    .cover h2 { font-size:13pt; color:#64748b; font-weight:400; margin:0; }
    .cover .line { width:70px; height:3px; background:linear-gradient(to right,#0891b2,#7c3aed); margin:24px auto; }
    .cover .tag { font-size:11pt; color:#475569; font-style:italic; margin-top:20px; }
    .cover .info { font-size:9.5pt; color:#94a3b8; margin-top:40px; line-height:2; }
    h1 { font-size:18pt; color:#0891b2; margin:24px 0 10px; padding-bottom:5px; border-bottom:2px solid #e2e8f0; }
    h2 { font-size:13pt; color:#1e293b; margin:18px 0 8px; }
    h3 { font-size:11pt; color:#334155; margin:14px 0 6px; }
    p { margin:0 0 8px; text-align:justify; }
    ul,ol { margin:0 0 10px; padding-left:20px; }
    li { margin-bottom:3px; }
    strong { color:#0f172a; }
    table { width:100%; border-collapse:collapse; margin:10px 0; font-size:9pt; }
    th { background:#0891b2; color:#fff; font-weight:700; text-align:left; padding:6px 8px; border:1px solid #0891b2; }
    td { padding:5px 8px; border:1px solid #e2e8f0; vertical-align:top; }
    tr:nth-child(even) td { background:#f8fafc; }
    .box { background:#ecfeff; border-left:4px solid #0891b2; padding:10px 14px; margin:10px 0; border-radius:0 5px 5px 0; }
    .stats { text-align:center; margin:16px 0; }
    .stats table { border:none; margin:0 auto; }
    .stats td { text-align:center; padding:10px 16px; border:none; background:transparent !important; }
    .stats .v { font-size:18pt; font-weight:700; color:#0891b2; display:block; }
    .stats .l { font-size:7.5pt; color:#64748b; text-transform:uppercase; display:block; margin-top:2px; }
    .toc-item { display:block; padding:4px 0; border-bottom:1px dotted #cbd5e1; color:#334155; font-size:10pt; }
    .footer { position:fixed; bottom:0; left:0; right:0; text-align:center; font-size:7pt; color:#94a3b8; border-top:1px solid #e2e8f0; padding:5px 40px; }
    .footer .fl { float:left; } .footer .fr { float:right; }
    .rm-done { color:#059669; } .rm-current { color:#0891b2; font-weight:700; } .rm-planned { color:#64748b; }
    .disc { font-size:8.5pt; color:#64748b; line-height:1.5; }
</style>
</head>
<body>
<div class="footer"><span class="fl">TPIX Chain — {{ $isEn ? 'Whitepaper v2.0' : 'ไวท์เปเปอร์ v2.0' }}</span><span class="fr">&copy; 2026 Xman Studio</span></div>

{{-- COVER --}}
<div class="cover">
    @if(file_exists(public_path('logo.png')))
    <img src="{{ public_path('logo.png') }}" style="width:90px;height:90px;margin:0 auto 20px;" alt="TPIX">
    @else
    <div style="width:90px;height:90px;margin:0 auto 20px;background:#0891b2;border-radius:18px;line-height:90px;color:#fff;font-size:26pt;font-weight:700;">TPIX</div>
    @endif
    <h1>TPIX CHAIN</h1>
    <h2>{{ $t['subtitle'] }}</h2>
    <div class="line"></div>
    <p class="tag">{{ $isEn ? 'Zero Gas. Instant Finality. Real-World Impact.' : 'ค่า Gas เป็นศูนย์ Finality ทันที ส่งผลกระทบต่อโลกจริง' }}</p>
    <div class="info">{{ $t['version'] }}<br>{{ $isEn ? 'Developed by' : 'พัฒนาโดย' }} <strong>Xman Studio</strong><br><span style="font-size:8.5pt;">https://tpix.online | https://xmanstudio.com</span></div>
</div>

{{-- TOC --}}
<h1>{{ $isEn ? 'Table of Contents' : 'สารบัญ' }}</h1>
@foreach($t['toc'] as $item)<div class="toc-item">{{ $item }}</div>@endforeach
<div class="pb"></div>

{{-- 1. EXECUTIVE SUMMARY --}}
<h1>{{ $t['toc'][0] }}</h1>
@foreach($t['exec'] as $p)<p>{{ $p }}</p>@endforeach
<div class="stats"><table><tr>@foreach($t['stats'] as $s)<td><span class="v">{{ $s[0] }}</span><span class="l">{{ $s[1] }}</span></td>@endforeach</tr></table></div>

{{-- 2. PROBLEM & SOLUTION --}}
<h1>{{ $t['toc'][1] }}</h1>
<h2>{{ $isEn ? 'The Problems' : 'ปัญหา' }}</h2>
<table><thead><tr><th>{{ $isEn ? 'Problem' : 'ปัญหา' }}</th><th>{{ $isEn ? 'Description' : 'คำอธิบาย' }}</th></tr></thead><tbody>@foreach($t['problems'] as $p)<tr><td><strong>{{ $p[0] }}</strong></td><td>{{ $p[1] }}</td></tr>@endforeach</tbody></table>
<h2>{{ $isEn ? 'Our Solutions' : 'ทางออกของเรา' }}</h2>
<table><thead><tr><th>{{ $isEn ? 'Solution' : 'ทางออก' }}</th><th>{{ $isEn ? 'Description' : 'คำอธิบาย' }}</th></tr></thead><tbody>@foreach($t['solutions'] as $s)<tr><td><strong>{{ $s[0] }}</strong></td><td>{{ $s[1] }}</td></tr>@endforeach</tbody></table>
<div class="pb"></div>

{{-- 3. ARCHITECTURE --}}
<h1>{{ $t['toc'][2] }}</h1>
<table><thead><tr><th>{{ $isEn ? 'Parameter' : 'พารามิเตอร์' }}</th><th>{{ $isEn ? 'Value' : 'ค่า' }}</th></tr></thead><tbody>@foreach($t['chainSpecs'] as $row)<tr><td>{{ $row[0] }}</td><td><strong>{{ $row[1] }}</strong></td></tr>@endforeach</tbody></table>

{{-- 4. TOKENOMICS --}}
<h1>{{ $t['toc'][3] }}</h1>
<table><thead><tr><th>{{ $isEn ? 'Allocation' : 'การจัดสรร' }}</th><th>%</th><th>TPIX</th></tr></thead><tbody>@foreach($t['tokenAlloc'] as $row)<tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td></tr>@endforeach</tbody></table>
<div class="box"><p>{{ $t['tokenNote'] }}</p></div>
<div class="pb"></div>

{{-- 5. USE CASES --}}
<h1>{{ $t['toc'][4] }}</h1>
@foreach($t['useCases'] as $uc)
<h3>{{ $uc[0] }}</h3>
<p>{{ $uc[1] }}</p>
<ul>@foreach($uc[2] as $f)<li>{{ $f }}</li>@endforeach</ul>
@endforeach
<div class="pb"></div>

{{-- 6. DEX --}}
<h1>{{ $t['toc'][5] }}</h1>
<p>{{ $t['dexDesc'] }}</p>
<table><thead><tr><th>Contract</th><th>{{ $isEn ? 'Description' : 'คำอธิบาย' }}</th></tr></thead><tbody>@foreach($t['dexContracts'] as $c)<tr><td><strong>{{ $c[0] }}</strong></td><td>{{ $c[1] }}</td></tr>@endforeach</tbody></table>

{{-- 7. TOKEN SALE --}}
<h1>{{ $t['toc'][6] }}</h1>
<p>{{ $t['saleDesc'] }}</p>
<table><thead><tr><th>{{ $isEn ? 'Phase' : 'เฟส' }}</th><th>{{ $isEn ? 'Price' : 'ราคา' }}</th><th>{{ $isEn ? 'Allocation' : 'จัดสรร' }}</th><th>TGE</th><th>Vesting</th></tr></thead><tbody>@foreach($t['salePhases'] as $p)<tr><td><strong>{{ $p[0] }}</strong></td><td>{{ $p[1] }}</td><td>{{ $p[2] }}</td><td>{{ $p[3] }}</td><td>{{ $p[4] }}</td></tr>@endforeach</tbody></table>
<div class="pb"></div>

{{-- 8. MASTER NODE --}}
<h1>{{ $t['toc'][7] }}</h1>
<p>{{ $t['mnDesc'] }}</p>
<table><thead><tr><th>{{ $isEn ? 'Tier' : 'ระดับ' }}</th><th>{{ $isEn ? 'Stake' : 'Stake' }}</th><th>APY</th><th>{{ $isEn ? 'Lock' : 'ล็อก' }}</th><th>{{ $isEn ? 'Max' : 'สูงสุด' }}</th><th>{{ $isEn ? 'Reward' : 'ส่วนแบ่ง' }}</th></tr></thead><tbody>@foreach($t['mnTiers'] as $tier)<tr><td><strong>{{ $tier[0] }}</strong></td><td>{{ $tier[1] }}</td><td>{{ $tier[2] }}</td><td>{{ $tier[3] }}</td><td>{{ $tier[4] }}</td><td>{{ $tier[5] }}</td></tr>@endforeach</tbody></table>
<h2>{{ $isEn ? 'Emission Schedule' : 'ตารางการปล่อยเหรียญ' }}</h2>
<table><thead><tr><th>{{ $isEn ? 'Period' : 'ช่วงเวลา' }}</th><th>{{ $isEn ? 'Emission' : 'การปล่อย' }}</th><th>{{ $isEn ? 'Per Block' : 'ต่อบล็อก' }}</th><th>%</th></tr></thead><tbody>@foreach($t['mnEmission'] as $e)<tr><td>{{ $e[0] }}</td><td>{{ $e[1] }}</td><td>{{ $e[2] }}</td><td>{{ $e[3] }}</td></tr>@endforeach</tbody></table>
<div class="box"><p><strong>{{ $isEn ? 'Reward Split:' : 'การแบ่งรางวัล:' }}</strong> {{ $t['mnRewardSplit'] }}</p><p><strong>{{ $isEn ? 'Slashing:' : 'การลงโทษ:' }}</strong> {{ $t['mnSlashing'] }}</p></div>
<div class="pb"></div>

{{-- 9. LIVING IDENTITY --}}
<h1>{{ $t['toc'][8] }}</h1>
<p>{{ $t['livingIdDesc'] }}</p>

{{-- 10. GOVERNANCE --}}
<h1>{{ $t['toc'][9] }}</h1>
<p>{{ $t['govDesc'] }}</p>

{{-- 11. BRIDGE --}}
<h1>{{ $t['toc'][10] }}</h1>
<p>{{ $t['bridgeDesc'] }}</p>
<div class="pb"></div>

{{-- 12. ECOSYSTEM --}}
<h1>{{ $t['toc'][11] }}</h1>
@foreach($t['integrations'] as $intg)
<h3>{{ $intg[0] }} — {{ $intg[1] }}</h3>
<ul>@foreach($intg[2] as $item)<li>{{ $item }}</li>@endforeach</ul>
@endforeach

{{-- 13. INTEGRATIONS --}}
<h1>{{ $t['toc'][12] }}</h1>
<p>{{ $isEn ? 'TPIX Chain integrates with the ThaiPrompt ecosystem (500,000+ enterprise users), providing built-in adoption channels for DeFi, food traceability, smart farming, delivery services, and enterprise marketing.' : 'TPIX Chain เชื่อมต่อกับระบบนิเวศ ThaiPrompt (ผู้ใช้องค์กร 500,000+ คน) ให้ช่องทางการยอมรับในตัวสำหรับ DeFi, ตรวจสอบอาหาร, ฟาร์มอัจฉริยะ, บริการส่งของ และการตลาดองค์กร' }}</p>
<div class="pb"></div>

{{-- 14. ROADMAP --}}
<h1>{{ $t['toc'][13] }}</h1>
<table><thead><tr><th>{{ $isEn ? 'Period' : 'ช่วงเวลา' }}</th><th>{{ $isEn ? 'Phase' : 'เฟส' }}</th><th>{{ $isEn ? 'Status' : 'สถานะ' }}</th><th>{{ $isEn ? 'Key Milestones' : 'เป้าหมายสำคัญ' }}</th></tr></thead><tbody>
@foreach($t['roadmap'] as $r)<tr><td><strong>{{ $r[0] }}</strong></td><td>{{ $r[1] }}</td><td class="{{ $r[2] === 'done' ? 'rm-done' : ($r[2] === 'current' ? 'rm-current' : 'rm-planned') }}">{{ $r[2] === 'done' ? ($isEn ? 'Done' : 'เสร็จ') : ($r[2] === 'current' ? ($isEn ? 'Current' : 'ปัจจุบัน') : ($isEn ? 'Planned' : 'วางแผน')) }}</td><td>{{ implode(' | ', $r[3]) }}</td></tr>@endforeach
</tbody></table>
<div class="pb"></div>

{{-- 15. TECH STACK --}}
<h1>{{ $t['toc'][14] }}</h1>
@foreach($t['techStack'] as $cat)
<h3>{{ $cat[0] }}</h3>
<table><thead><tr><th>{{ $isEn ? 'Technology' : 'เทคโนโลยี' }}</th><th>{{ $isEn ? 'Description' : 'คำอธิบาย' }}</th></tr></thead><tbody>@foreach($cat[1] as $tech)<tr><td><strong>{{ $tech[0] }}</strong></td><td>{{ $tech[1] }}</td></tr>@endforeach</tbody></table>
@endforeach

{{-- 16. TEAM --}}
<h1>{{ $t['toc'][15] }}</h1>
<p>{{ $t['teamDesc'] }}</p>
<ul>@foreach($t['teamHighlights'] as $h)<li>{{ $h }}</li>@endforeach</ul>

{{-- 17. SECURITY --}}
<h1>{{ $t['toc'][16] }}</h1>
<table><thead><tr><th>{{ $isEn ? 'Measure' : 'มาตรการ' }}</th><th>{{ $isEn ? 'Details' : 'รายละเอียด' }}</th></tr></thead><tbody>@foreach($t['security'] as $sec)<tr><td><strong>{{ $sec[0] }}</strong></td><td>{{ $sec[1] }}</td></tr>@endforeach</tbody></table>
<div class="pb"></div>

{{-- 18. DISCLAIMER --}}
<h1>{{ $t['toc'][17] }}</h1>
<p class="disc">{{ $t['disclaimer'] }}</p>
<br><br>
<div style="text-align:center; color:#94a3b8; font-size:9pt;">
    <p>&copy; 2026 TPIX Chain — Xman Studio. {{ $isEn ? 'All rights reserved.' : 'สงวนลิขสิทธิ์' }}</p>
    <p>https://tpix.online | https://xmanstudio.com</p>
    <p style="margin-top:8px; font-size:8pt;">GitHub: https://github.com/xjanova/TPIX-Coin | Explorer: https://explorer.tpix.online | RPC: https://rpc.tpix.online</p>
</div>
</body>
</html>
