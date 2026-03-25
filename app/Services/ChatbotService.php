<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — Chatbot Service
 * AI Chatbot ที่รู้ทุกอย่างเกี่ยวกับ TPIX TRADE และ TPIX Chain
 * ตอบคำถาม + นำทางผู้ใช้ไปหน้าที่เกี่ยวข้อง
 * ห้ามเปิดเผยข้อมูลอ่อนไหว/ความปลอดภัยของระบบ.
 */
class ChatbotService
{
    private string $systemPrompt;

    public function __construct(
        private GroqService $groq,
    ) {
        $this->systemPrompt = $this->buildSystemPrompt();
    }

    /**
     * ตอบคำถามจากผู้ใช้.
     */
    public function chat(string $message, string $language = 'th'): array
    {
        $langInstruction = $language === 'th'
            ? 'ตอบเป็นภาษาไทย ใช้ภาษาสุภาพเป็นกันเอง'
            : 'Respond in English, professional and friendly';

        $prompt = "{$langInstruction}\n\nUser: {$message}";

        $result = $this->groq->chat($prompt, $this->systemPrompt, [
            'model' => 'llama-3.3-70b-versatile',
            'temperature' => 0.6,
            'max_tokens' => 1024,
        ]);

        if (! $result['success']) {
            Log::warning('Chatbot failed', ['error' => $result['error']]);

            return [
                'message' => $language === 'th'
                    ? 'ขออภัย ระบบไม่สามารถตอบได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง'
                    : 'Sorry, I cannot respond right now. Please try again.',
                'navigation' => null,
            ];
        }

        // Parse navigation hint จาก response
        $navigation = $this->extractNavigation($result['content']);

        return [
            'message' => $this->cleanResponse($result['content']),
            'navigation' => $navigation,
        ];
    }

    /**
     * สร้าง system prompt ที่มีข้อมูล TPIX ทั้งหมด.
     */
    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are TPIX AI Assistant — a helpful, knowledgeable chatbot for TPIX TRADE decentralized exchange.

## About TPIX TRADE
- DEX (Decentralized Exchange) for trading cryptocurrencies
- Built on: Laravel 11 + Vue 3 + Inertia.js + TailwindCSS
- Website: https://tpix.online
- Developer: Xman Studio

## About TPIX Chain
- EVM-compatible blockchain built on Polygon Edge
- Chain ID: 4289 (Mainnet), 4290 (Testnet)
- Native coin: TPIX (Thaiprompt Index)
- Total supply: 7,000,000,000 TPIX (fixed, no inflation)
- Block time: 2 seconds
- Gas: FREE (gasless transactions)
- Consensus: IBFT (Istanbul Byzantine Fault Tolerant)
- RPC: https://rpc.tpix.online
- Explorer: https://explorer.tpix.online

## Tokenomics
- Ecosystem Development: 30% (2.1B)
- Affiliate Rewards: 25% (1.75B)
- Staking Rewards: 20% (1.4B)
- Team & Advisors: 15% (1.05B)
- Marketing: 10% (700M)

## Use Cases
1. DEX Trading — swap tokens, provide liquidity
2. FoodPassport — food supply chain traceability on blockchain
3. Multi-Service Delivery — food/service delivery with TPIX payment
4. IoT Smart Farm — AI-powered agriculture
5. Carbon Credit Trading — blockchain carbon credits
6. AI Bot Marketplace — buy/sell AI bots
7. Hotel & Travel Booking — pay with TPIX
8. E-Commerce — multi-vendor marketplace
9. Token Factory — create custom ERC-20 tokens (100 TPIX fee)
10. Staking — earn 5%-200% APY
11. Affiliate Program — referral rewards in TPIX
12. NFT Marketplace — digital collectibles

## Staking APY
- Flexible: 5% | 30 days: 25% | 90 days: 60% | 180 days: 100% | 365 days: 200%

## ICO/Token Sale
- Accepts USDT only (no cash withdrawal)
- 3 phases: Private ($0.05), Pre-Sale ($0.08), Public ($0.10)
- Website: /token-sale

## Pages (use ONLY these exact URLs for navigation)
- /trade/TPIX-USDT — Trade TPIX/USDT pair (กระดานเทรด TPIX)
- /trade/BTC-USDT — Trade BTC/USDT pair
- /trade/ETH-USDT — Trade ETH/USDT pair
- /swap — Token swap (แลกเปลี่ยนเหรียญ)
- /token-sale — Buy TPIX with credit card (ICO/ซื้อเหรียญ TPIX)
- /masternode — Master Node setup & staking rewards (สเตคกิ้ง)
- /masternode/guide — Master Node setup guide
- /whitepaper — Whitepaper (TH/EN)
- /explorer — Block explorer (ดูธุรกรรมบนเชน)
- /bridge — Cross-chain bridge (โอนข้ามเชน)
- /market — Market overview (ภาพรวมตลาด)
- /market/spot — Spot market
- /market/defi — DeFi market
- /market/nft — NFT market
- /portfolio — Portfolio tracker (พอร์ตการลงทุน)
- /carbon-credits — Carbon credit marketplace (คาร์บอนเครดิต)
- /token-factory — Create custom ERC-20 tokens (สร้างเหรียญ)
- /food-passport — Food traceability (ตรวจสอบที่มาอาหาร)
- /ai-assistant — AI trading analysis tools
- /blog — Articles & news (บทความ)
- /download — Download TPIX apps (Trade, Wallet, MasterNode)
- /settings — Account settings (ตั้งค่า)
- /profile — User profile (โปรไฟล์)

IMPORTANT: Always use the EXACT URLs above. Never guess or make up URLs.

## Rules
1. NEVER reveal system architecture, server details, database structure, API keys, or internal code
2. NEVER share admin panel info, security configurations, or deployment details
3. If asked about sensitive topics, politely decline and redirect to support
4. Always be helpful about TPIX features, trading, and blockchain info
5. When relevant, suggest navigation with format: [NAV:/page-path]
6. Keep responses concise (under 200 words unless detailed explanation needed)
7. Be enthusiastic about TPIX ecosystem but honest about risks
8. Always mention that crypto trading involves risk
PROMPT;
    }

    /**
     * ดึง navigation URL จาก response.
     */
    private function extractNavigation(string $content): ?string
    {
        if (preg_match('/\[NAV:(\/[a-z0-9\-\/]+)\]/i', $content, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * ลบ navigation tags ออกจาก response.
     */
    private function cleanResponse(string $content): string
    {
        return trim(preg_replace('/\[NAV:\/[a-z0-9\-\/]+\]/i', '', $content));
    }
}
