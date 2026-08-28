<?php

/*
 * TPIX TRADE — พจนานุกรมเหรียญสำหรับจับคู่ข่าวกับคู่เทรด.
 *
 * ใช้สองที่:
 *   1. NewsFeedService::detectSymbols()  — อ่านพาดหัวข่าวรวม แล้วเดาว่าพูดถึงเหรียญไหน
 *   2. NewsFeedService::coinFeedUrl()    — ฟีดข่าวรายเหรียญ (Google News RSS)
 *
 * ⚠️ กฎเหล็กของ `aliases`: ใส่ได้เฉพาะคำที่ "ไม่มีความหมายอื่นในภาษาอังกฤษ"
 *
 *    ตัววัดคือฟีดจริง ไม่ใช่ความรู้สึก — ตัวย่อครึ่งหนึ่งของ 70 เหรียญเป็นคำปกติ:
 *      OP    = operation / op-ed        NEAR = คำบุพบท (อยู่ในพาดหัวแทบทุกข่าว)
 *      ETC   = et cetera                ALGO = algorithm
 *      VET   = สัตวแพทย์                 ATOM = อะตอม
 *      COMP  = comparison / compensation SAND = ทราย
 *      NEO   = ชื่อคนและหนัง             EOS  = กล้อง Canon
 *
 *    ใส่ตัวย่อพวกนี้ลงไป = ข่าวการเมืองหนึ่งข่าวจะถูกแท็กเป็น 5 เหรียญ แล้วคะแนน
 *    ความตื่นตระหนกจะรั่วข้ามเหรียญกันหมด — บอท ATOM เทของทิ้งเพราะข่าวนิวเคลียร์
 *
 *    เหรียญพวกนี้จึงพึ่ง `query` (ฟีดรายเหรียญ) เป็นหลัก ซึ่งแท็กจากต้นทางที่ยิงไป
 *    ไม่ต้องเดาจากพาดหัวเลย — แม่นกว่ามากและไม่มีทางชนกัน
 *
 * `query` = คำค้นของ Google News RSS เลือกให้แคบพอที่ผลลัพธ์เป็นเหรียญนั้นจริงๆ
 *
 * Developed by Xman Studio.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | ฟีดข่าวรายเหรียญ
    |--------------------------------------------------------------------------
    | Google News RSS — ฟรี ไม่ต้องใช้คีย์ ค้นได้ทุกภาษา และมีข่าวเหรียญเล็ก
    | ที่ฟีดคริปโตกระแสหลักไม่แตะเลย (วัดจริง: 8 ใน 19 เหรียญมีข่าว 0 ข่าว
    | จาก coindesk/cointelegraph/bitcoinmagazine ตลอด 14 วัน)
    |
    | %s = คำค้นของเหรียญนั้น (urlencode แล้ว)
    */
    'coin_feed_url' => 'https://news.google.com/rss/search?q=%s&hl=en-US&gl=US&ceid=US:en',

    /* น้ำหนักความน่าเชื่อถือของฟีดรายเหรียญ — ต่ำกว่าฟีดคริปโตเฉพาะทาง
       เพราะ Google News รวมบล็อกและเว็บรวมข่าวที่คุณภาพไม่สม่ำเสมอมาด้วย */
    'coin_feed_weight' => 0.75,

    /*
    |--------------------------------------------------------------------------
    | ยิงกี่เหรียญต่อรอบ
    |--------------------------------------------------------------------------
    | 70 เหรียญ × 1 request ทุก 15 นาที = 6,720 request/วัน ซึ่งเกินความจำเป็น
    | และเสี่ยงโดน Google จำกัด จึงหมุนเป็นรอบ:
    |
    |   - เหรียญที่บอทถือของอยู่ หรืออยู่ในรายชื่อที่ AI คัดไว้ → ยิงทุกรอบ
    |   - ที่เหลือหมุนไปทีละชุด `rotation_size` ตัว
    |
    | 12 ตัว/รอบ ทุก 15 นาที → ครบ 70 เหรียญใน ~90 นาที ซึ่งเร็วพอสำหรับ
    | รอบวิเคราะห์ใหญ่ 4 ชั่วโมง และไม่พลาดข่าวด่วนของเหรียญที่ถืออยู่จริง
    */
    'rotation_size' => 12,

    /* ดึงกี่ข่าวต่อเหรียญต่อรอบ — Google News คืนมาเยอะกว่านี้ แต่ข่าวเก่ากว่า
       หน้าต่าง 180 นาทีก็ไม่ถูกนับเข้าคะแนนอยู่ดี เก็บมาก็เปลืองแถว */
    'items_per_coin' => 8,

    /*
    |--------------------------------------------------------------------------
    | เหรียญที่ระบบรู้จัก
    |--------------------------------------------------------------------------
    | name    = ชื่อเต็มไว้แสดงผลและใส่ในบริบทที่ส่งให้ AI
    | aliases = คำที่ใช้จับจากพาดหัวข่าวรวม (ต้องปลอดคำพ้อง — ดูกฎเหล็กด้านบน)
    |           เว้นว่างได้ ถ้าไม่มีคำไหนปลอดภัยพอ
    | query   = คำค้นฟีดรายเหรียญ
    */
    'coins' => [
        'BTC' => ['name' => 'Bitcoin', 'aliases' => ['bitcoin', 'btc'], 'query' => 'Bitcoin BTC price'],
        'ETH' => ['name' => 'Ethereum', 'aliases' => ['ethereum', 'ether', 'eth'], 'query' => 'Ethereum ETH'],
        'BNB' => ['name' => 'BNB', 'aliases' => ['binance coin', 'bnb'], 'query' => 'BNB Binance Coin'],
        'SOL' => ['name' => 'Solana', 'aliases' => ['solana'], 'query' => 'Solana SOL'],
        'XRP' => ['name' => 'XRP', 'aliases' => ['ripple', 'xrp'], 'query' => 'XRP Ripple'],
        'DOGE' => ['name' => 'Dogecoin', 'aliases' => ['dogecoin', 'doge'], 'query' => 'Dogecoin DOGE'],
        'ADA' => ['name' => 'Cardano', 'aliases' => ['cardano'], 'query' => 'Cardano ADA'],
        'POL' => ['name' => 'Polygon', 'aliases' => ['polygon', 'matic'], 'query' => 'Polygon POL crypto'],
        'AVAX' => ['name' => 'Avalanche', 'aliases' => ['avalanche', 'avax'], 'query' => 'Avalanche AVAX'],
        'DOT' => ['name' => 'Polkadot', 'aliases' => ['polkadot'], 'query' => 'Polkadot DOT'],
        'LINK' => ['name' => 'Chainlink', 'aliases' => ['chainlink'], 'query' => 'Chainlink LINK'],
        'UNI' => ['name' => 'Uniswap', 'aliases' => ['uniswap'], 'query' => 'Uniswap UNI'],
        'LTC' => ['name' => 'Litecoin', 'aliases' => ['litecoin', 'ltc'], 'query' => 'Litecoin LTC'],
        'TRX' => ['name' => 'TRON', 'aliases' => ['tron', 'trx'], 'query' => 'TRON TRX crypto'],
        'ATOM' => ['name' => 'Cosmos', 'aliases' => ['cosmos'], 'query' => 'Cosmos ATOM crypto'],
        'NEAR' => ['name' => 'NEAR Protocol', 'aliases' => ['near protocol'], 'query' => 'NEAR Protocol crypto'],
        'SHIB' => ['name' => 'Shiba Inu', 'aliases' => ['shiba inu', 'shib'], 'query' => 'Shiba Inu SHIB'],
        'PEPE' => ['name' => 'Pepe', 'aliases' => ['pepe coin'], 'query' => 'Pepe coin PEPE crypto'],
        'SUI' => ['name' => 'Sui', 'aliases' => ['sui network', 'sui blockchain'], 'query' => 'Sui network SUI crypto'],
        'APT' => ['name' => 'Aptos', 'aliases' => ['aptos'], 'query' => 'Aptos APT crypto'],
        'ARB' => ['name' => 'Arbitrum', 'aliases' => ['arbitrum'], 'query' => 'Arbitrum ARB'],
        'OP' => ['name' => 'Optimism', 'aliases' => ['optimism'], 'query' => 'Optimism OP crypto'],
        'TIA' => ['name' => 'Celestia', 'aliases' => ['celestia'], 'query' => 'Celestia TIA crypto'],
        'SEI' => ['name' => 'Sei', 'aliases' => ['sei network'], 'query' => 'Sei network SEI crypto'],
        'INJ' => ['name' => 'Injective', 'aliases' => ['injective'], 'query' => 'Injective INJ crypto'],
        'RUNE' => ['name' => 'THORChain', 'aliases' => ['thorchain'], 'query' => 'THORChain RUNE'],
        'FIL' => ['name' => 'Filecoin', 'aliases' => ['filecoin'], 'query' => 'Filecoin FIL'],
        'ETC' => ['name' => 'Ethereum Classic', 'aliases' => ['ethereum classic'], 'query' => 'Ethereum Classic ETC'],
        'HBAR' => ['name' => 'Hedera', 'aliases' => ['hedera', 'hbar'], 'query' => 'Hedera HBAR'],
        'VET' => ['name' => 'VeChain', 'aliases' => ['vechain'], 'query' => 'VeChain VET'],
        'ICP' => ['name' => 'Internet Computer', 'aliases' => ['internet computer', 'icp'], 'query' => 'Internet Computer ICP crypto'],
        'ALGO' => ['name' => 'Algorand', 'aliases' => ['algorand'], 'query' => 'Algorand ALGO'],
        'XLM' => ['name' => 'Stellar', 'aliases' => ['stellar lumens', 'xlm'], 'query' => 'Stellar Lumens XLM'],
        'AAVE' => ['name' => 'Aave', 'aliases' => ['aave'], 'query' => 'Aave crypto'],
        'MKR' => ['name' => 'Maker', 'aliases' => ['makerdao', 'maker dao'], 'query' => 'MakerDAO MKR'],
        'GRT' => ['name' => 'The Graph', 'aliases' => ['the graph protocol'], 'query' => 'The Graph GRT crypto'],
        /*
         * SAND ไม่มี alias เลย — "sandbox" เป็นคำที่ข่าวคริปโตใช้บ่อยมากในความหมายอื่น
         * ("regulatory sandbox" ของหน่วยงานกำกับ) และ "the sandbox" ก็ไปแมตช์กับ
         * ประโยคทั่วไปอย่าง "at the sandbox startup" ได้ (เทสต์จับได้ตอนเขียนครั้งแรก)
         * ปล่อยให้ฟีดรายเหรียญทำงานแทน ซึ่งแม่นกว่าและไม่มีทางชนกัน
         */
        'SAND' => ['name' => 'The Sandbox', 'aliases' => [], 'query' => 'The Sandbox SAND crypto'],
        'MANA' => ['name' => 'Decentraland', 'aliases' => ['decentraland'], 'query' => 'Decentraland MANA'],
        'AXS' => ['name' => 'Axie Infinity', 'aliases' => ['axie infinity', 'axie'], 'query' => 'Axie Infinity AXS'],
        'CHZ' => ['name' => 'Chiliz', 'aliases' => ['chiliz'], 'query' => 'Chiliz CHZ'],
        'EOS' => ['name' => 'EOS', 'aliases' => ['eos blockchain', 'eos network'], 'query' => 'EOS blockchain crypto'],
        'FLOW' => ['name' => 'Flow', 'aliases' => ['flow blockchain'], 'query' => 'Flow blockchain FLOW crypto'],
        'GALA' => ['name' => 'Gala Games', 'aliases' => ['gala games'], 'query' => 'Gala Games GALA crypto'],
        'IMX' => ['name' => 'Immutable X', 'aliases' => ['immutable x', 'imx'], 'query' => 'Immutable X IMX'],
        'LDO' => ['name' => 'Lido DAO', 'aliases' => ['lido dao', 'lido finance'], 'query' => 'Lido DAO LDO crypto'],
        'CRV' => ['name' => 'Curve DAO', 'aliases' => ['curve finance', 'curve dao'], 'query' => 'Curve Finance CRV'],
        'COMP' => ['name' => 'Compound', 'aliases' => ['compound finance'], 'query' => 'Compound Finance COMP crypto'],
        'SNX' => ['name' => 'Synthetix', 'aliases' => ['synthetix'], 'query' => 'Synthetix SNX'],
        'ENS' => ['name' => 'Ethereum Name Service', 'aliases' => ['ethereum name service'], 'query' => 'Ethereum Name Service ENS'],
        'DYDX' => ['name' => 'dYdX', 'aliases' => ['dydx'], 'query' => 'dYdX crypto'],
        'STX' => ['name' => 'Stacks', 'aliases' => ['stacks blockchain'], 'query' => 'Stacks STX crypto'],
        'KAVA' => ['name' => 'Kava', 'aliases' => ['kava'], 'query' => 'Kava crypto'],
        'ZIL' => ['name' => 'Zilliqa', 'aliases' => ['zilliqa'], 'query' => 'Zilliqa ZIL'],
        'IOTA' => ['name' => 'IOTA', 'aliases' => ['iota'], 'query' => 'IOTA crypto'],
        'NEO' => ['name' => 'Neo', 'aliases' => ['neo blockchain'], 'query' => 'Neo blockchain NEO crypto'],
        'QNT' => ['name' => 'Quant', 'aliases' => ['quant network'], 'query' => 'Quant Network QNT'],
        'THETA' => ['name' => 'Theta Network', 'aliases' => ['theta network'], 'query' => 'Theta Network THETA'],
        'EGLD' => ['name' => 'MultiversX', 'aliases' => ['multiversx', 'elrond', 'egld'], 'query' => 'MultiversX EGLD'],
        'CAKE' => ['name' => 'PancakeSwap', 'aliases' => ['pancakeswap'], 'query' => 'PancakeSwap CAKE'],
        'XTZ' => ['name' => 'Tezos', 'aliases' => ['tezos'], 'query' => 'Tezos XTZ'],
        'WIF' => ['name' => 'dogwifhat', 'aliases' => ['dogwifhat'], 'query' => 'dogwifhat WIF crypto'],
        'BONK' => ['name' => 'Bonk', 'aliases' => ['bonk coin'], 'query' => 'Bonk coin BONK crypto'],
        'FLOKI' => ['name' => 'Floki', 'aliases' => ['floki'], 'query' => 'Floki coin FLOKI'],
        'JUP' => ['name' => 'Jupiter', 'aliases' => ['jupiter exchange', 'jupiter dex'], 'query' => 'Jupiter exchange JUP crypto'],
        'PYTH' => ['name' => 'Pyth Network', 'aliases' => ['pyth network', 'pyth'], 'query' => 'Pyth Network PYTH'],
        'RENDER' => ['name' => 'Render', 'aliases' => ['render network'], 'query' => 'Render Network RENDER crypto'],
        'ONDO' => ['name' => 'Ondo Finance', 'aliases' => ['ondo finance', 'ondo'], 'query' => 'Ondo Finance ONDO'],
        'ENA' => ['name' => 'Ethena', 'aliases' => ['ethena'], 'query' => 'Ethena ENA crypto'],
        'USDC' => ['name' => 'USD Coin', 'aliases' => ['usd coin', 'usdc'], 'query' => 'USDC stablecoin'],
        'USDT' => ['name' => 'Tether', 'aliases' => ['tether', 'usdt'], 'query' => 'Tether USDT stablecoin'],

        /*
         * เหรียญของเราเอง — ไม่มีสำนักข่าวไหนเขียนถึง ฟีดจึงคืนศูนย์ข่าวเสมอ
         * เก็บไว้ในพจนานุกรมเพื่อให้ตัวจับคู่ไม่ทิ้งคู่ TPIX/USDT ไปเฉยๆ
         * และเพื่อให้ AI เห็นว่า "เหรียญนี้ไม่มีข่าว" ต่างจาก "ยังไม่ได้ดึงข่าว"
         */
        'TPIX' => ['name' => 'TPIX', 'aliases' => ['tpix'], 'query' => null],
    ],
];
