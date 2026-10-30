<?php

namespace App\Services\TokenFactory;

use App\Models\SiteSetting;
use Illuminate\Support\Arr;

/**
 * TokenFactoryPolicy — นโยบายของโรงงานสร้างเหรียญ อยู่ที่เดียวของทั้งระบบ.
 *
 * เจ้าของสั่ง: "ปิดทุกอย่างที่เสี่ยงไว้ก่อน แอดมินเปิด/ปิดเองได้ทีละตัว พร้อมตั้งค่าละเอียด"
 *
 * ทุกประเภทเหรียญ (types) และทุกตัวเลือกย่อย (options) มีสถานะหนึ่งในสามแบบ:
 *   off    — ปิด (ค่าตั้งต้นของทุกอย่างที่เสี่ยง) ผู้ใช้ไม่เห็นในฟอร์ม เซิร์ฟเวอร์ปฏิเสธ
 *   on     — เปิด ถ้าเป็นของเสี่ยง (risky) ผู้ใช้เห็นกล่องเตือนสีแดงและต้องติ๊กยอมรับ
 *   locked — ล็อกในโค้ด แอดมินเปิดไม่ได้เลย (สัญญามีบั๊กที่ทำร้ายผู้ซื้อ) — ดู LOCKED
 *
 * ค่าตั้งต้นอยู่ที่ config('blockchain.token_factory_policy')
 * ค่าที่แอดมินแก้เก็บใน SiteSetting กลุ่ม token_factory_policy คีย์ policy (JSON ก้อนเดียว)
 * ทั้งฟอร์มหน้าเว็บ ใบเสนอราคาค่าธรรมเนียม และด่านตรวจตอนสร้าง อ่านจากที่นี่ที่เดียว
 * — เดิมค่าธรรมเนียมมีสามแหล่ง (หน้าแอดมิน, config, หน้าเว็บ) ที่ไม่ตรงกันเลย
 *
 * Developed by Xman Studio
 */
class TokenFactoryPolicy
{
    public const GROUP = 'token_factory_policy';

    public const KEY = 'policy';

    public const STATE_OFF = 'off';

    public const STATE_ON = 'on';

    public const STATE_LOCKED = 'locked';

    /** ช่วงที่แอดมินตั้งได้ — กันพิมพ์ผิดจนระบบเพี้ยน */
    public const MAX_FEE = 1000000;

    /**
     * ทุกประเภทและทุกตัวเลือกต้องมีราคา (เจ้าของสั่ง) — ค่า 0 ที่เคยบันทึกไว้ถูกแทนด้วยค่าตั้งต้นใน config
     * ถ้าค่าตั้งต้นก็เป็น 0 ใช้ค่านี้.
     */
    public const MIN_FEE_FALLBACK = 1.0;

    /** วิธีคืนค่าธรรมเนียมเมื่อปฏิเสธคำขอ */
    public const REFUND_MANUAL = 'manual';

    public const REFUND_AUTO = 'auto';

    public const MAX_SUPPLY_CEILING = '1000000000000000000000000000000'; // 10^30

    /**
     * ของที่ล็อกถาวรในโค้ด — แอดมินเปิดไม่ได้ ต่อให้แก้ JSON ในฐานข้อมูลเองก็ไม่มีผล.
     *
     * ปลดล็อกได้ทางเดียวคือแก้สัญญาใน TPIX-Coin ให้หายบั๊กก่อน แล้วค่อยลบรายการตรงนี้
     *
     * @var array<string,string> 'types.x' | 'options.x' => เหตุผลภาษาไทย
     */
    public const LOCKED = [
        'types.utility' => 'ล็อกถาวร: เหรียญ Utility ที่เก็บภาษีขายผ่าน TPIX DEX ไม่ได้ '
            .'(Router ไม่รองรับเหรียญที่หักค่าธรรมเนียมตอนโอน) และระบบกันบอทมีบั๊กที่ทำให้ทั้งตลาดเทรดไม่ได้ '
            .'— ผู้ซื้อติดเหรียญขายไม่ออก',
        'types.reward' => 'ล็อกถาวร: สูตรปันผลของสัญญา Reward ผิด ผู้ถือที่เข้ามาทีหลังถอนรางวัลจนพูลหมด '
            .'และค่าธรรมเนียมตอนโอนทำให้ขายผ่าน TPIX DEX ไม่ได้',
        'types.stablecoin' => 'ล็อกถาวร: ผู้สร้างยัง mint เพิ่มได้แม้สละความเป็นเจ้าของแล้ว '
            .'(สิทธิ์ minter ไม่ถูกถอน) และเจ้าของเผาเหรียญจากกระเป๋าของใครก็ได้',
        'options.auto_burn' => 'ล็อกถาวร: เหรียญที่เผาอัตโนมัติทุกครั้งที่โอนขายผ่าน TPIX DEX ไม่ได้ '
            .'(Router ไม่มีฟังก์ชันรองรับเหรียญที่หักตอนโอน) — ผู้ซื้อติดเหรียญ (honeypot)',
    ];

    /**
     * ข้อมูลประจำประเภท (ในโค้ด แอดมินแก้ไม่ได้).
     *
     * ui_types = ค่า token_type ที่ฟอร์มส่งมา ซึ่งจัดเข้าประเภทนี้
     * factory  = แฟกทอรีบนเชนที่ใช้สร้าง (erc20 = TPIXTokenFactoryV2, nft = TPIXNFTFactory)
     */
    public const TYPES = [
        'erc20' => [
            'label_th' => 'เหรียญ ERC-20 มาตรฐาน',
            'desc_th' => 'จำนวนเหรียญคงที่ เจ้าของเพิ่มเหรียญ หยุดการโอน หรือบล็อกผู้ถือไม่ได้',
            'category' => 'fungible',
            'factory' => 'erc20',
            'risky' => false,
            'warning_th' => null,
            'ui_types' => ['standard', 'mintable', 'burnable', 'mintable_burnable'],
        ],
        'utility' => [
            'label_th' => 'Utility Token (ภาษี / กันวาฬ / กันบอท)',
            'desc_th' => 'เหรียญที่มีภาษีซื้อขายและระบบป้องกัน',
            'category' => 'fungible',
            'factory' => 'erc20',
            'risky' => true,
            'warning_th' => 'เจ้าของเก็บภาษีทุกการซื้อขาย จำกัดจำนวนที่ถือ/โอน และหยุดการเทรดช่วงเปิดตัวได้',
            'ui_types' => ['utility'],
        ],
        'reward' => [
            'label_th' => 'Reward Token (ปันผล / reflection)',
            'desc_th' => 'แบ่งรางวัลจากค่าธรรมเนียมให้ผู้ถือ',
            'category' => 'fungible',
            'factory' => 'erc20',
            'risky' => true,
            'warning_th' => 'หักค่าธรรมเนียมทุกการโอนเพื่อแจกรางวัล',
            'ui_types' => ['reward'],
        ],
        'governance' => [
            'label_th' => 'Governance Token (โหวต DAO)',
            'desc_th' => 'เหรียญที่ใช้โหวต มีระบบมอบอำนาจโหวต',
            'category' => 'special',
            'factory' => 'erc20',
            'risky' => true,
            'warning_th' => 'เจ้าของเหรียญ Governance ยังมีอำนาจเหนือผู้ถือตามตัวเลือกที่เปิด: '
                .'mint เพิ่มได้ถึงเพดาน หยุดการโอนทั้งระบบ และบล็อกกระเป๋าไม่ให้โอนหรือขาย '
                .'ผลโหวตไม่ได้บังคับเจ้าของ — ผู้ซื้อต้องเชื่อใจเจ้าของเหรียญ',
            'ui_types' => ['governance'],
        ],
        'stablecoin' => [
            'label_th' => 'Stablecoin',
            'desc_th' => 'เหรียญผูกค่าเงิน มีกระเป๋าสำรอง',
            'category' => 'special',
            'factory' => 'erc20',
            'risky' => true,
            'warning_th' => 'เจ้าของ mint และเผาเหรียญได้ อายัดบัญชีได้',
            'ui_types' => ['stablecoin'],
        ],
        'nft' => [
            'label_th' => 'NFT เดี่ยว (ERC-721)',
            'desc_th' => 'สินทรัพย์ดิจิทัลเฉพาะตัว เช่น ใบรับรอง งานศิลป์',
            'category' => 'nft',
            'factory' => 'nft',
            'risky' => true,
            'warning_th' => 'เจ้าของสัญญา NFT ยังมีสิทธิ์พิเศษ: mint เพิ่มได้จนถึงจำนวนสูงสุดที่ตั้งไว้ '
                .'และกำหนดค่าลิขสิทธิ์ (royalty) ที่ผู้ซื้อต่อต้องจ่ายทุกครั้งที่ขายต่อ',
            'ui_types' => ['nft'],
        ],
        'nft_collection' => [
            'label_th' => 'NFT Collection (ชุดสะสม)',
            'desc_th' => 'คอลเลกชัน NFT หลายใบ เปิดให้คนอื่น mint ได้',
            'category' => 'nft',
            'factory' => 'nft',
            'risky' => true,
            'warning_th' => 'เจ้าของคอลเลกชันกำหนดราคา mint และรับเงินค่า mint ทั้งหมด '
                .'กันจำนวนสำรองไว้ให้ตัวเองได้ ตั้งรูปที่แสดงก่อนเปิดเผย (delayed reveal) '
                .'และกำหนดค่าลิขสิทธิ์ (royalty) ที่ผู้ซื้อต่อต้องจ่าย',
            'ui_types' => ['nft_collection'],
        ],
    ];

    /**
     * ตัวเลือกย่อย (ในโค้ด แอดมินแก้ไม่ได้ ยกเว้นสถานะ/ค่าธรรมเนียม).
     *
     * applies_to = ประเภทที่ตัวเลือกนี้มีผลจริงบนสัญญา (ประเภทอื่นถูกตัดทิ้ง)
     * ui_keys    = คีย์ใน sub_options ของฟอร์มที่เป็นของตัวเลือกนี้
     */
    public const OPTIONS = [
        'burnable' => [
            'label_th' => 'เผาเหรียญได้ (ผู้ถือเผาของตัวเอง)',
            'desc_th' => 'ผู้ถือเผาเหรียญของตัวเองได้ ไม่มีใครเผาเหรียญของคนอื่นได้',
            'risky' => false,
            'warning_th' => null,
            'applies_to' => ['erc20', 'governance'],
            'requires' => null,
            'ui_keys' => ['burnable'],
        ],
        'mintable' => [
            'label_th' => 'Mint เพิ่มได้ (Mintable)',
            'desc_th' => 'เจ้าของสร้างเหรียญเพิ่มได้หลังเปิดตัว',
            'risky' => true,
            'warning_th' => 'เจ้าของเหรียญสร้างเหรียญเพิ่มได้ทุกเมื่อ (ไม่จำกัดจำนวนถ้าไม่ตั้งเพดาน) '
                .'มูลค่าเหรียญที่ผู้ถือมีอยู่ถูกเจือจางได้ทันที และเจ้าของเทขายเหรียญที่สร้างใหม่เข้าพูลได้',
            'applies_to' => ['erc20', 'governance'],
            'requires' => null,
            'ui_keys' => ['mintable'],
        ],
        'mint_cap' => [
            'label_th' => 'เพดาน Mint (Supply Cap)',
            'desc_th' => 'จำกัดจำนวนเหรียญสูงสุดที่เจ้าของ mint ได้ (ต้องเปิด Mintable ด้วย)',
            'risky' => false,
            'warning_th' => null,
            'applies_to' => ['erc20', 'governance'],
            'requires' => 'mintable',
            'ui_keys' => ['mint_cap_enabled', 'mint_cap'],
        ],
        'pausable' => [
            'label_th' => 'หยุดการโอนได้ (Pausable)',
            'desc_th' => 'เจ้าของหยุดการโอนทั้งหมดชั่วคราวได้',
            'risky' => true,
            'warning_th' => 'เจ้าของเหรียญหยุดการโอนทั้งหมดได้ทุกเมื่อ — ระหว่างที่หยุด '
                .'ไม่มีใครโอน ขาย หรือถอนสภาพคล่องเหรียญนี้ได้เลย',
            'applies_to' => ['erc20', 'governance'],
            'requires' => null,
            'ui_keys' => ['pausable'],
        ],
        'blacklist' => [
            'label_th' => 'บัญชีดำ (Blacklist)',
            'desc_th' => 'เจ้าของบล็อกกระเป๋าที่ระบุไม่ให้โอน',
            'risky' => true,
            'warning_th' => 'เจ้าของเหรียญบล็อกกระเป๋าใดก็ได้ไม่ให้โอนหรือขาย '
                .'ผู้ถือที่โดนบล็อกจะติดเหรียญขายไม่ออก (ใช้เป็นกับดัก honeypot ได้)',
            'applies_to' => ['erc20', 'governance'],
            'requires' => null,
            'ui_keys' => ['blacklist_enabled'],
        ],
        'auto_burn' => [
            'label_th' => 'เผาอัตโนมัติทุกการโอน (Auto Burn)',
            'desc_th' => 'หักเหรียญไปเผาทุกครั้งที่โอน',
            'risky' => true,
            'warning_th' => 'ทุกการโอนถูกหักเหรียญไปเผา — ขายผ่าน TPIX DEX ไม่ได้',
            'applies_to' => ['erc20'],
            'requires' => null,
            'ui_keys' => ['auto_burn_enabled', 'auto_burn_rate', 'burn_floor'],
        ],
        /*
         * ไม่ใช่ความสามารถของสัญญา แต่เป็นบริการเสียเงิน — หลัง deploy ระบบส่งซอร์สให้ explorer ยืนยัน
         * explorer ปิดช่องยืนยันสาธารณะไว้ (nginx รับเฉพาะคำขอที่มี explorer_verify_key)
         * จึงยืนยันได้ทางเดียวคือจ่ายค่าธรรมเนียมนี้ (ติ๊กตอนสร้าง หรือซื้อทีหลังในหน้าเหรียญของฉัน)
         */
        'source_verify' => [
            'label_th' => 'ยืนยันซอร์สโค้ดบน Explorer',
            'desc_th' => 'เผยแพร่ซอร์สโค้ดสัญญาบน explorer.tpix.online ให้ทุกคนตรวจได้ (ป้าย Contract verified)',
            'risky' => false,
            'warning_th' => null,
            'applies_to' => ['erc20', 'utility', 'reward', 'governance', 'stablecoin', 'nft', 'nft_collection'],
            'requires' => null,
            'ui_keys' => ['source_verify'],
        ],
    ];

    /**
     * คีย์ใน sub_options ที่ส่งต่อให้สคริปต์ deploy ได้ แยกตามประเภท.
     *
     * คีย์อื่นถูกตัดทิ้งตอนบันทึก — กันคนยิง API ตรงแอบใส่ตัวเลือกของประเภทอื่น
     * (เช่นใส่ tax_enabled มากับเหรียญมาตรฐาน) หรือใส่ชื่อเก่าที่สคริปต์ยังรับเป็น alias
     */
    public const SUB_OPTION_KEYS = [
        'erc20' => ['pausable', 'blacklist_enabled', 'mint_cap_enabled', 'mint_cap',
            'auto_burn_enabled', 'auto_burn_rate', 'burn_floor', 'ownership_type', 'source_verify'],
        'governance' => ['mintable', 'burnable', 'pausable', 'blacklist_enabled', 'mint_cap_enabled', 'mint_cap',
            'delegation_enabled', 'proposal_threshold', 'quorum_percent', 'voting_period_days', 'voting_type',
            'treasury_enabled', 'treasury_wallet', 'ownership_type', 'source_verify'],
        'nft' => ['metadata_storage', 'metadata_uri', 'royalty_enabled', 'royalty_rate', 'royalty_wallet',
            'soulbound', 'ownership_type', 'source_verify'],
        'nft_collection' => ['mint_type', 'mint_price', 'max_per_wallet', 'max_per_tx', 'reserve_count',
            'delayed_reveal', 'placeholder_uri', 'metadata_uri', 'base_uri',
            'royalty_enabled', 'royalty_rate', 'royalty_wallet', 'ownership_type', 'source_verify'],
        'utility' => ['mintable', 'burnable', 'pausable', 'blacklist_enabled', 'tax_enabled', 'buy_tax_rate',
            'sell_tax_rate', 'transfer_tax_rate', 'tax_wallet', 'marketing_wallet', 'marketing_share',
            'anti_whale_enabled', 'max_wallet_percent', 'max_tx_percent', 'anti_bot_enabled',
            'anti_bot_duration', 'trading_cooldown', 'auto_lp_enabled', 'auto_lp_rate', 'lp_lock_days', 'ownership_type',
            'source_verify'],
        'reward' => ['mintable', 'burnable', 'pausable', 'blacklist_enabled', 'reward_type', 'reflection_rate',
            'min_hold_for_reward', 'vesting_enabled', 'vesting_cliff_days', 'vesting_duration_days', 'ownership_type',
            'source_verify'],
        'stablecoin' => ['pausable', 'freeze_enabled', 'kyc_required', 'reserve_wallet', 'peg_currency',
            'peg_ratio', 'reserve_type', 'ownership_type', 'source_verify'],
    ];

    /** ค่าที่เป็นสวิตช์เปิด/ปิด — แปลงเป็น bool ก่อนส่งต่อ */
    private const BOOL_KEYS = [
        'pausable', 'blacklist_enabled', 'mint_cap_enabled', 'auto_burn_enabled', 'mintable', 'burnable',
        'delegation_enabled', 'treasury_enabled', 'royalty_enabled', 'soulbound', 'delayed_reveal',
        'tax_enabled', 'anti_whale_enabled', 'anti_bot_enabled', 'auto_lp_enabled', 'vesting_enabled',
        'freeze_enabled', 'kyc_required', 'source_verify',
    ];

    /** ช่วงตัวเลขที่ยอมรับ [min, max] — นอกช่วงคือพิมพ์ผิดหรือจงใจทำร้ายผู้ซื้อ */
    private const NUMBER_RANGES = [
        'auto_burn_rate' => [0, 10],
        'royalty_rate' => [0, 10],
        'max_per_wallet' => [0, 10000],
        'max_per_tx' => [0, 1000],
        'reserve_count' => [0, 100],
        'mint_price' => [0, 1000000000000],
        'quorum_percent' => [1, 67],
        'voting_period_days' => [1, 30],
    ];

    private const ADDRESS_KEYS = ['royalty_wallet', 'treasury_wallet', 'tax_wallet', 'marketing_wallet', 'reserve_wallet'];

    private const URI_KEYS = ['metadata_uri', 'base_uri', 'placeholder_uri'];

    /**
     * จำนวนเงิน/เหรียญที่สคริปต์แปลงด้วย parseUnits — ต้องเป็นทศนิยมปกติ (ไม่มี e-notation)
     * ค่า = จำนวนทศนิยมสูงสุด (null = ใช้ทศนิยมของเหรียญ).
     *
     * ⚠️ เดิมสคริปต์ทำ String(Number(x)) — ราคา mint 0.0000001 กลายเป็น "1e-7" แล้ว parseEther โยน
     *    หลังผู้ใช้จ่ายค่าธรรมเนียมไปแล้ว
     */
    private const UNIT_KEYS = [
        'mint_price' => 18,       // TPIX native
        'mint_cap' => 0,          // จำนวนเต็มเท่านั้น
        'burn_floor' => null,
        'proposal_threshold' => null,
        'min_hold_for_reward' => null,
    ];

    private const SELECT_CHOICES = [
        'ownership_type' => ['ownable', 'renounced'],
        'mint_type' => ['public', 'whitelist', 'free_claim', 'free'],
        'metadata_storage' => ['ipfs', 'onchain', 'centralized'],
        'voting_type' => ['token_weighted', 'quadratic'],
    ];

    /** cache ภายใน request — effective() ถูกเรียกหลายรอบต่อคำขอ */
    private ?array $cached = null;

    // =========================================================================
    //  อ่านนโยบาย
    // =========================================================================

    /**
     * นโยบายที่ใช้งานจริง = ค่าตั้งต้น + ที่แอดมินแก้ + ล็อกในโค้ด.
     */
    public function effective(): array
    {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $defaults = (array) config('blockchain.token_factory_policy', []);
        $stored = SiteSetting::get(self::GROUP, self::KEY, []);
        $overrides = is_array($stored) ? $stored : [];

        $policy = $this->normalize($this->mergeKnown($defaults, $overrides));

        foreach (self::LOCKED as $path => $reason) {
            if (Arr::has($policy, $path)) {
                Arr::set($policy, "{$path}.state", self::STATE_LOCKED);
                Arr::set($policy, "{$path}.locked_reason", $reason);
            }
        }

        return $this->cached = $policy;
    }

    /** ล้าง cache ภายใน (หลังแอดมินบันทึก) */
    public function refresh(): void
    {
        $this->cached = null;
    }

    /**
     * สวิตช์ใหญ่ของการสร้างเหรียญ — อยู่ในนโยบายที่เดียว (super_admin).
     *
     * ⚠️ ห้ามกลับไปอ่าน factory.creation_enabled — คีย์นั้นอยู่นอกนโยบาย
     *    ถ้าเอามาเป็น fallback = สวิตช์นี้มีสองที่และตรวจคนละกฎ
     */
    public function creationEnabled(): bool
    {
        return (bool) $this->effective()['creation_enabled'];
    }

    /**
     * กระเป๋ารับค่าธรรมเนียมบนเชน 4289 — ต้องตั้งในนโยบายเท่านั้น (super_admin).
     *
     * ⚠️ เดิมตกไปใช้ factory.fee_wallet / revenue.tpix_wallet ซึ่งแอดมินทุกระดับแก้ได้จากหน้าตั้งค่า
     *    = แอดมินระดับล่างเปลี่ยนปลายทางเงินค่าธรรมเนียมได้ ตอนนี้ไม่ตั้ง = ระบบบอก "ยังไม่ได้ตั้งกระเป๋า"
     *    (ไม่ใช้ trading.fee_collector_wallet ด้วย เพราะนั่นคือกระเป๋าบน BSC)
     */
    public function feeWallet(): string
    {
        $wallet = strtolower(trim((string) ($this->effective()['fee_wallet'] ?? '')));

        return preg_match('/^0x[a-f0-9]{40}$/', $wallet) ? $wallet : '';
    }

    /** วิธีคืนค่าธรรมเนียมเมื่อปฏิเสธคำขอ — manual | auto */
    public function refundMode(): string
    {
        return $this->effective()['refund_mode'];
    }

    /** ค่ายืนยันซอร์สบน explorer (TPIX) — null = แอดมินปิดบริการนี้ */
    public function sourceVerifyFee(): ?float
    {
        $option = $this->effective()['options']['source_verify'];

        return $option['state'] === self::STATE_ON ? (float) $option['fee'] : null;
    }

    public function chainId(): int
    {
        return (int) config('blockchain.tpix_chain_id', 4289);
    }

    /** ประเภทในนโยบายของ token_type ที่ฟอร์มส่งมา — null = ไม่รู้จัก */
    public static function policyTypeFor(?string $tokenType): ?string
    {
        foreach (self::TYPES as $key => $meta) {
            if (in_array($tokenType, $meta['ui_types'], true)) {
                return $key;
            }
        }

        return null;
    }

    public function typeState(string $type): string
    {
        return $this->effective()['types'][$type]['state'] ?? self::STATE_OFF;
    }

    public function optionState(string $option): string
    {
        return $this->effective()['options'][$option]['state'] ?? self::STATE_OFF;
    }

    /** ประเภทนี้เปิดให้สร้างอยู่ไหม */
    public function typeEnabled(string $type): bool
    {
        return $this->typeState($type) === self::STATE_ON;
    }

    /** มีประเภทไหนเปิดอยู่บ้างที่ใช้แฟกทอรีตัวนี้ (erc20 | nft) */
    public function anyTypeEnabledFor(string $factory): bool
    {
        foreach (self::TYPES as $key => $meta) {
            if ($meta['factory'] === $factory && $this->typeEnabled($key)) {
                return true;
            }
        }

        return false;
    }

    /** มีอะไรต้องจ่ายค่าธรรมเนียมบ้างไหม — ถ้าฟรีหมด ไม่ต้องมีกระเป๋ารับเงิน */
    public function anyFeeCharged(): bool
    {
        $policy = $this->effective();

        foreach ($policy['types'] as $type) {
            if ($type['state'] === self::STATE_ON && (float) $type['fee'] > 0) {
                return true;
            }
        }
        foreach ($policy['options'] as $option) {
            if ($option['state'] === self::STATE_ON && (float) $option['fee'] > 0) {
                return true;
            }
        }

        return false;
    }

    // =========================================================================
    //  ประเมินคำขอสร้างเหรียญ
    // =========================================================================

    /**
     * ตัวเลือกที่คำขอนี้ "ขอใช้จริง" (ชื่อตามนโยบาย) — เฉพาะที่มีผลกับประเภทนั้น.
     *
     * @return string[]
     */
    public function requestedOptions(string $tokenType, array $subOptions): array
    {
        $type = self::policyTypeFor($tokenType);
        if ($type === null) {
            return [];
        }

        $on = fn (string ...$keys) => $this->truthy($subOptions, ...$keys);
        $requested = [];

        if ($type === 'erc20') {
            // ERC-20 ฟอร์มส่ง mint/burn มาเป็นชนิด (token_type) ไม่ใช่สวิตช์
            if (in_array($tokenType, ['mintable', 'mintable_burnable'], true)) {
                $requested[] = 'mintable';
            }
            if (in_array($tokenType, ['burnable', 'mintable_burnable'], true)) {
                $requested[] = 'burnable';
            }
        } elseif (in_array($type, ['governance', 'utility', 'reward'], true)) {
            if ($on('mintable')) {
                $requested[] = 'mintable';
            }
            if ($on('burnable')) {
                $requested[] = 'burnable';
            }
        }

        // ชื่อเก่า (blacklist, auto_burn) นับด้วย — สคริปต์ deploy เคยรับเป็น alias
        if ($on('pausable')) {
            $requested[] = 'pausable';
        }
        if ($on('blacklist_enabled', 'blacklist')) {
            $requested[] = 'blacklist';
        }
        if ($on('mint_cap_enabled')) {
            $requested[] = 'mint_cap';
        }
        if ($on('auto_burn_enabled', 'auto_burn')) {
            $requested[] = 'auto_burn';
        }
        if ($on('source_verify')) {
            $requested[] = 'source_verify';
        }

        $applicable = array_filter(
            $requested,
            fn (string $option) => in_array($type, self::OPTIONS[$option]['applies_to'], true)
        );

        return array_values(array_unique($applicable));
    }

    /**
     * ตรวจคำขอทั้งก้อนตามนโยบาย.
     *
     * @param  array{token_type:string, sub_options?:array, decimals?:int|string|null, total_supply?:string|int|null,
     *               symbol?:string, name?:string, creator?:string, risk_ack?:array}  $request
     * @return array{
     *     type:?string, options:string[], required_acks:string[], errors:array<string,string>,
     *     fee:array{total:float, breakdown:array}, sub_options:array, auto_approve:bool,
     *     require_kyc:bool, params_hash:string
     * }
     */
    public function evaluate(array $request): array
    {
        $policy = $this->effective();
        $tokenType = (string) ($request['token_type'] ?? '');
        $type = self::policyTypeFor($tokenType);
        $sub = is_array($request['sub_options'] ?? null) ? $request['sub_options'] : [];
        $errors = [];

        $result = [
            'type' => $type,
            'options' => [],
            'required_acks' => [],
            'errors' => [],
            'fee' => ['total' => 0.0, 'breakdown' => []],
            'sub_options' => [],
            'auto_approve' => false,
            'require_kyc' => (bool) $policy['require_kyc'],
            'params_hash' => '',
        ];

        if ($type === null) {
            $result['errors'] = ['token_type' => 'ไม่รู้จักประเภทเหรียญนี้'];

            return $result;
        }

        $typeConfig = $policy['types'][$type];
        $typeLabel = self::TYPES[$type]['label_th'];

        // ── ประเภทต้องเปิดอยู่ ─────────────────────────────────────────────
        if ($typeConfig['state'] === self::STATE_LOCKED) {
            $errors['token_type'] = "{$typeLabel} ถูกล็อก — {$typeConfig['locked_reason']}";
        } elseif ($typeConfig['state'] !== self::STATE_ON) {
            $errors['token_type'] = "ผู้ดูแลระบบปิดการสร้าง{$typeLabel}ไว้";
        }

        // ── ตัวเลือกที่ขอใช้ต้องเปิดอยู่ทุกตัว ─────────────────────────────
        $options = $this->requestedOptions($tokenType, $sub);
        foreach ($options as $option) {
            $optionConfig = $policy['options'][$option];
            $label = self::OPTIONS[$option]['label_th'];

            if ($optionConfig['state'] === self::STATE_LOCKED) {
                $errors["sub_options.{$option}"] = "ตัวเลือก {$label} ถูกล็อก — {$optionConfig['locked_reason']}";
            } elseif ($optionConfig['state'] !== self::STATE_ON) {
                $errors["sub_options.{$option}"] = "ผู้ดูแลระบบปิดตัวเลือก {$label} ไว้";
            }

            $requires = self::OPTIONS[$option]['requires'];
            if ($requires !== null && ! in_array($requires, $options, true)) {
                $errors["sub_options.{$option}"] = "ตัวเลือก {$label} ต้องเปิด "
                    .self::OPTIONS[$requires]['label_th'].' ด้วย';
            }
        }

        // ── ยอมรับความเสี่ยงครบทุกตัวที่เสี่ยง ─────────────────────────────
        $requiredAcks = [];
        if (self::TYPES[$type]['risky']) {
            $requiredAcks[] = $type;
        }
        foreach ($options as $option) {
            if (self::OPTIONS[$option]['risky']) {
                $requiredAcks[] = $option;
            }
        }

        $acks = array_map('strval', array_filter((array) ($request['risk_ack'] ?? []), 'is_scalar'));
        $missing = array_values(array_diff($requiredAcks, $acks));
        if ($missing !== []) {
            $labels = array_map(
                fn (string $key) => self::TYPES[$key]['label_th'] ?? self::OPTIONS[$key]['label_th'] ?? $key,
                $missing
            );
            $errors['risk_ack'] = 'ต้องติ๊กยอมรับความเสี่ยงก่อน: '.implode(', ', $labels);
        }

        // ── ทศนิยม / จำนวน ─────────────────────────────────────────────────
        $isNft = self::TYPES[$type]['category'] === 'nft';
        $decimals = $isNft ? 0 : (int) ($request['decimals'] ?? 18);
        if (! $isNft && ($decimals < $typeConfig['decimals_min'] || $decimals > $typeConfig['decimals_max'])) {
            $errors['decimals'] = $typeConfig['decimals_min'] === $typeConfig['decimals_max']
                ? "ทศนิยมต้องเป็น {$typeConfig['decimals_min']} เท่านั้น"
                : "ทศนิยมต้องอยู่ระหว่าง {$typeConfig['decimals_min']}–{$typeConfig['decimals_max']}";
        }

        $supply = trim((string) ($request['total_supply'] ?? ''));
        if ($supply !== '' && ctype_digit($supply) && bccomp($supply, (string) $typeConfig['max_supply'], 0) > 0) {
            $errors['total_supply'] = 'จำนวนเหรียญเกินเพดานที่ผู้ดูแลกำหนด ('
                .number_format((float) $typeConfig['max_supply']).')';
        }

        // ── ชื่อ / สัญลักษณ์ ───────────────────────────────────────────────
        $symbol = strtoupper(trim((string) ($request['symbol'] ?? '')));
        if ($symbol !== '') {
            $max = (int) $policy['symbol_max_length'];
            if (! preg_match('/^[A-Z0-9]+$/', $symbol)) {
                $errors['symbol'] = 'สัญลักษณ์ใช้ได้เฉพาะตัวอักษรอังกฤษ A–Z และตัวเลข 0–9';
            } elseif (strlen($symbol) < 2 || strlen($symbol) > $max) {
                $errors['symbol'] = "สัญลักษณ์ต้องยาว 2–{$max} ตัวอักษร";
            } elseif (($reserved = $this->reservedAllowlist($symbol)) !== null
                && ! in_array(strtolower((string) ($request['creator'] ?? '')), $reserved, true)) {
                $errors['symbol'] = "สัญลักษณ์ {$symbol} สงวนไว้สำหรับโครงการทางการ ใช้ไม่ได้";
            }
        }

        $name = trim((string) ($request['name'] ?? ''));
        if ($name !== '') {
            $max = (int) $policy['name_max_length'];
            if (mb_strlen($name) < 2 || mb_strlen($name) > $max) {
                $errors['name'] = "ชื่อเหรียญต้องยาว 2–{$max} ตัวอักษร";
            } elseif (preg_match('/[\p{C}]/u', $name)) {
                $errors['name'] = 'ชื่อเหรียญมีอักขระที่ใช้ไม่ได้';
            }
        }

        // ── ค่าของตัวเลือกย่อย ─────────────────────────────────────────────
        foreach ($this->validateSubOptionValues($type, $sub, $supply, $typeConfig, $decimals) as $field => $message) {
            $errors[$field] = $message;
        }

        $result['options'] = $options;
        $result['required_acks'] = $requiredAcks;
        $result['errors'] = $errors;
        $result['fee'] = $this->fee($type, $options);
        $result['sub_options'] = $this->sanitizeSubOptions($tokenType, $sub);
        $result['auto_approve'] = $typeConfig['auto_approve'] && $requiredAcks === [];
        $result['require_kyc'] = (bool) $policy['require_kyc'] || (bool) $typeConfig['require_kyc'];
        $result['params_hash'] = $this->paramsHash($tokenType, $options);

        return $result;
    }

    /**
     * ค่าธรรมเนียม = ค่าประเภท + ค่าตัวเลือกที่ขอใช้ (TPIX).
     *
     * @return array{total: float, breakdown: array<int, array{key:string, label_th:string, amount:float}>}
     */
    public function fee(string $type, array $options): array
    {
        $policy = $this->effective();
        $breakdown = [];

        $typeFee = (float) ($policy['types'][$type]['fee'] ?? 0);
        $breakdown[] = ['key' => $type, 'label_th' => 'ค่าสร้าง'.self::TYPES[$type]['label_th'], 'amount' => $typeFee];
        $total = $typeFee;

        foreach ($options as $option) {
            $amount = (float) ($policy['options'][$option]['fee'] ?? 0);
            if ($amount > 0) {
                $breakdown[] = ['key' => $option, 'label_th' => self::OPTIONS[$option]['label_th'], 'amount' => $amount];
                $total += $amount;
            }
        }

        return ['total' => round($total, 6), 'breakdown' => $breakdown];
    }

    /**
     * ลายนิ้วมือของ "สิ่งที่คิดเงิน" — ใบเสนอราคาผูกกับค่านี้.
     *
     * กันขอใบเสนอราคาของเหรียญถูก ๆ แล้วเอาไปใช้สร้างเหรียญที่มีตัวเลือกแพงกว่า
     */
    public function paramsHash(string $tokenType, array $options): string
    {
        $options = array_values(array_unique($options));
        sort($options);

        return hash('sha256', json_encode([
            'token_type' => $tokenType,
            'options' => $options,
            'chain_id' => $this->chainId(),
        ]));
    }

    /**
     * sub_options ที่ปลอดภัยพอจะส่งให้สคริปต์ deploy.
     *
     * - เก็บเฉพาะคีย์ของประเภทนั้น ตัด alias เก่าทิ้ง
     * - ตัวเลือกที่ไม่ได้เปิด (off/locked) ถูกบังคับเป็น false เสมอ — ต่อให้ข้อมูลเก่าใน DB จะบอกว่าเปิด
     */
    public function sanitizeSubOptions(string $tokenType, array $sub): array
    {
        $type = self::policyTypeFor($tokenType);
        if ($type === null) {
            return [];
        }

        $clean = [];
        foreach (self::SUB_OPTION_KEYS[$type] as $key) {
            if (! array_key_exists($key, $sub)) {
                continue;
            }

            $value = $sub[$key];
            if (in_array($key, self::BOOL_KEYS, true)) {
                $value = $this->truthy([$key => $value], $key);
            } elseif (is_array($value) || is_object($value)) {
                continue;
            } elseif (array_key_exists($key, self::UNIT_KEYS) && $value !== null && $value !== '') {
                // ส่งเป็นทศนิยมปกติเสมอ — สคริปต์ไม่ต้องผ่าน Number (กัน "1e-7" / เลขใหญ่เพี้ยน)
                $value = self::plainDecimal($value);
            }

            $clean[$key] = $value;
        }

        // ปิดทุกสวิตช์ของตัวเลือกที่ไม่ได้เปิดในนโยบาย
        foreach (self::OPTIONS as $option => $meta) {
            if ($this->optionState($option) === self::STATE_ON) {
                continue;
            }
            foreach ($meta['ui_keys'] as $uiKey) {
                if (array_key_exists($uiKey, $clean)) {
                    $clean[$uiKey] = in_array($uiKey, self::BOOL_KEYS, true) ? false : null;
                }
            }
        }

        return array_filter($clean, fn ($value) => $value !== null);
    }

    /**
     * ตัวเลือก/ประเภทในคำขอเดิมที่ตอนนี้ไม่ได้เปิดแล้ว — ใช้ตอนแอดมินกดอนุมัติ.
     *
     * @return string[] ข้อความภาษาไทย
     */
    public function stateViolations(string $tokenType, array $sub): array
    {
        $type = self::policyTypeFor($tokenType);
        if ($type === null) {
            return ['ไม่รู้จักประเภทเหรียญนี้'];
        }

        $violations = [];
        if (! $this->typeEnabled($type)) {
            $violations[] = self::TYPES[$type]['label_th'].($this->typeState($type) === self::STATE_LOCKED ? ' ถูกล็อก' : ' ถูกปิดอยู่');
        }
        foreach ($this->requestedOptions($tokenType, $sub) as $option) {
            if ($this->optionState($option) !== self::STATE_ON) {
                $violations[] = 'ตัวเลือก '.self::OPTIONS[$option]['label_th']
                    .($this->optionState($option) === self::STATE_LOCKED ? ' ถูกล็อก' : ' ถูกปิดอยู่');
            }
        }

        return $violations;
    }

    /** รายชื่อกระเป๋าที่ใช้สัญลักษณ์สงวนนี้ได้ — null = ไม่ใช่สัญลักษณ์สงวน */
    public function reservedAllowlist(string $symbol): ?array
    {
        $reserved = $this->effective()['reserved_symbols'];
        $symbol = strtoupper($symbol);

        return array_key_exists($symbol, $reserved) ? $reserved[$symbol] : null;
    }

    // =========================================================================
    //  สำหรับหน้าเว็บ
    // =========================================================================

    /**
     * นโยบายที่หน้าเว็บสาธารณะต้องรู้ — ไม่มีรายชื่อกระเป๋าใน allowlist.
     */
    public function publicConfig(): array
    {
        $policy = $this->effective();

        $types = [];
        foreach (self::TYPES as $key => $meta) {
            $config = $policy['types'][$key];
            $types[$key] = [
                'state' => $config['state'],
                'label_th' => $meta['label_th'],
                'desc_th' => $meta['desc_th'],
                'category' => $meta['category'],
                'ui_types' => $meta['ui_types'],
                'risky' => $meta['risky'],
                'warning_th' => $meta['warning_th'],
                'locked_reason' => $config['locked_reason'] ?? null,
                'fee' => (float) $config['fee'],
                'max_supply' => (string) $config['max_supply'],
                'decimals_min' => $config['decimals_min'],
                'decimals_max' => $config['decimals_max'],
            ];
        }

        $options = [];
        foreach (self::OPTIONS as $key => $meta) {
            $config = $policy['options'][$key];
            $options[$key] = [
                'state' => $config['state'],
                'label_th' => $meta['label_th'],
                'desc_th' => $meta['desc_th'],
                'risky' => $meta['risky'],
                'warning_th' => $meta['warning_th'],
                'locked_reason' => $config['locked_reason'] ?? null,
                'applies_to' => $meta['applies_to'],
                'requires' => $meta['requires'],
                'ui_keys' => $meta['ui_keys'],
                'fee' => (float) $config['fee'],
            ];
        }

        return [
            'chain_id' => $this->chainId(),
            'currency' => 'TPIX',
            'fee_wallet' => $this->feeWallet(),
            'types' => $types,
            'options' => $options,
            'limits' => [
                'name_max_length' => (int) $policy['name_max_length'],
                'symbol_max_length' => (int) $policy['symbol_max_length'],
                'daily_limit_per_wallet' => (int) $policy['daily_limit_per_wallet'],
                'required_confirmations' => (int) $policy['required_confirmations'],
                'quote_ttl_minutes' => (int) $policy['quote_ttl_minutes'],
            ],
            'reserved_symbols' => array_keys($policy['reserved_symbols']),
        ];
    }

    /**
     * ข้อมูลทั้งหมดสำหรับหน้าแอดมิน (รวม allowlist + ค่าที่ใช้จริง).
     */
    public function adminConfig(): array
    {
        $public = $this->publicConfig();
        $policy = $this->effective();

        foreach ($public['types'] as $key => &$type) {
            $type['auto_approve'] = (bool) $policy['types'][$key]['auto_approve'];
            $type['require_kyc'] = (bool) $policy['types'][$key]['require_kyc'];
        }
        unset($type);

        return array_merge($public, [
            'creation_enabled' => $this->creationEnabled(),
            'fee_wallet_override' => (string) $policy['fee_wallet'],
            'require_kyc' => (bool) $policy['require_kyc'],
            'auto_list_on_deploy' => (bool) $policy['auto_list_on_deploy'],
            'refund_mode' => $policy['refund_mode'],
            'reserved_symbols' => $policy['reserved_symbols'],
        ]);
    }

    // =========================================================================
    //  แอดมินแก้นโยบาย
    // =========================================================================

    /**
     * ตรวจและแปลงค่าที่แอดมินส่งมาเป็นก้อนที่เก็บได้.
     *
     * @return array{policy: array, errors: array<string,string>}
     */
    public function prepareUpdate(array $input): array
    {
        $current = $this->editableSnapshot($this->effective());
        $errors = [];
        $next = $current;

        // ── ค่ารวม ─────────────────────────────────────────────────────────
        if (array_key_exists('creation_enabled', $input)) {
            $next['creation_enabled'] = filter_var($input['creation_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['require_kyc', 'auto_list_on_deploy'] as $key) {
            if (array_key_exists($key, $input)) {
                $next[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (array_key_exists('refund_mode', $input)) {
            $mode = (string) $input['refund_mode'];
            if (! in_array($mode, [self::REFUND_MANUAL, self::REFUND_AUTO], true)) {
                $errors['refund_mode'] = 'วิธีคืนค่าธรรมเนียมต้องเป็น "แอดมินโอนเอง" หรือ "จ่ายอัตโนมัติ"';
            } else {
                $next['refund_mode'] = $mode;
            }
        }

        if (array_key_exists('fee_wallet', $input)) {
            $wallet = strtolower(trim((string) $input['fee_wallet']));
            if ($wallet !== '' && ! preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
                $errors['fee_wallet'] = 'ที่อยู่กระเป๋ารับค่าธรรมเนียมไม่ถูกต้อง';
            } else {
                $next['fee_wallet'] = $wallet;
            }
        }

        $ints = [
            'required_confirmations' => [1, 100, 'จำนวนบล็อกยืนยัน'],
            'quote_ttl_minutes' => [5, 120, 'อายุใบเสนอราคา (นาที)'],
            'daily_limit_per_wallet' => [1, 100, 'จำนวนสร้างต่อกระเป๋าต่อวัน'],
            'name_max_length' => [2, 100, 'ความยาวชื่อสูงสุด'],
            'symbol_max_length' => [2, 20, 'ความยาวสัญลักษณ์สูงสุด'],
        ];
        foreach ($ints as $key => [$min, $max, $label]) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = filter_var($input[$key], FILTER_VALIDATE_INT);
            if ($value === false || $value < $min || $value > $max) {
                $errors[$key] = "{$label} ต้องอยู่ระหว่าง {$min}–{$max}";
            } else {
                $next[$key] = $value;
            }
        }

        // ── สัญลักษณ์สงวน ──────────────────────────────────────────────────
        if (array_key_exists('reserved_symbols', $input)) {
            $reserved = [];
            foreach ((array) $input['reserved_symbols'] as $index => $row) {
                /*
                 * รับ 3 รูปแบบ — รูปแบบที่ไม่รู้จักต้องเป็น error เสมอ ห้ามข้ามเงียบ ๆ
                 * เพราะผลคือรายการสงวนว่าง แล้วใครก็สร้าง TPIX/USDT ได้ (เกิดจริง 2026-10-27):
                 *   [['symbol' => 'IDTC', 'wallets' => '0x..']]  ← หน้าแอดมิน
                 *   ['IDTC' => ['0x..'], 'TPIX' => []]          ← รูปแบบเดียวกับ effective()
                 *   ['TPIX', 'USDT']                             ← สงวนไว้ ไม่มีใครใช้
                 */
                if (is_array($row) && (array_key_exists('symbol', $row) || array_key_exists('wallets', $row))) {
                    $symbol = $row['symbol'] ?? '';
                    $wallets = $row['wallets'] ?? [];
                } elseif (is_string($index) && (is_array($row) || is_string($row) || $row === null)) {
                    [$symbol, $wallets] = [$index, $row ?? []];
                } elseif (is_int($index) && is_string($row)) {
                    [$symbol, $wallets] = [$row, []];
                } else {
                    $errors["reserved_symbols.{$index}"] = 'รูปแบบสัญลักษณ์สงวนไม่ถูกต้อง';

                    continue;
                }

                $symbol = strtoupper(trim((string) $symbol));
                if (is_string($wallets)) {
                    $wallets = preg_split('/[\s,]+/', $wallets, -1, PREG_SPLIT_NO_EMPTY);
                }

                if ($symbol === '') {
                    // แถวว่างที่แอดมินกดเพิ่มไว้ข้ามได้ — แต่มีกระเป๋าโดยไม่มีสัญลักษณ์แปลว่าข้อมูลหาย
                    if ((array) $wallets !== []) {
                        $errors["reserved_symbols.{$index}"] = 'ใส่กระเป๋าแล้วแต่ไม่ได้ใส่สัญลักษณ์';
                    }

                    continue;
                }
                if (! preg_match('/^[A-Z0-9]{1,20}$/', $symbol)) {
                    $errors["reserved_symbols.{$index}"] = "สัญลักษณ์สงวน {$symbol} ไม่ถูกต้อง";

                    continue;
                }

                $clean = [];
                foreach ((array) $wallets as $wallet) {
                    $wallet = strtolower(trim((string) $wallet));
                    if (! preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
                        $errors["reserved_symbols.{$index}"] = "กระเป๋าที่อนุญาตให้ใช้ {$symbol} ไม่ถูกต้อง";

                        continue 2;
                    }
                    $clean[] = $wallet;
                }

                $reserved[$symbol] = array_values(array_unique($clean));
            }
            ksort($reserved);
            $next['reserved_symbols'] = $reserved;
        }

        // ── ประเภท ─────────────────────────────────────────────────────────
        foreach ((array) ($input['types'] ?? []) as $key => $row) {
            if (! isset(self::TYPES[$key]) || ! is_array($row)) {
                continue;
            }
            $label = self::TYPES[$key]['label_th'];

            if (array_key_exists('state', $row)) {
                $state = (string) $row['state'];
                if (isset(self::LOCKED["types.{$key}"]) && $state !== self::STATE_OFF && $state !== self::STATE_LOCKED) {
                    $errors["types.{$key}.state"] = "{$label} ถูกล็อกในโค้ด เปิดไม่ได้ — ".self::LOCKED["types.{$key}"];
                } elseif (! in_array($state, [self::STATE_ON, self::STATE_OFF, self::STATE_LOCKED], true)) {
                    $errors["types.{$key}.state"] = "สถานะของ{$label}ไม่ถูกต้อง";
                } else {
                    $next['types'][$key]['state'] = $state === self::STATE_ON ? self::STATE_ON : self::STATE_OFF;
                }
            }

            if (array_key_exists('fee', $row)) {
                $fee = filter_var($row['fee'], FILTER_VALIDATE_FLOAT);
                if ($fee === false || $fee <= 0 || $fee > self::MAX_FEE) {
                    $errors["types.{$key}.fee"] = "ค่าธรรมเนียม{$label}ต้องมากกว่า 0 และไม่เกิน ".number_format(self::MAX_FEE).' TPIX';
                } else {
                    $next['types'][$key]['fee'] = round($fee, 6);
                }
            }

            if (array_key_exists('max_supply', $row)) {
                $supply = trim((string) $row['max_supply']);
                if (! preg_match('/^[1-9][0-9]*$/', $supply) || bccomp($supply, self::MAX_SUPPLY_CEILING, 0) > 0) {
                    $errors["types.{$key}.max_supply"] = "เพดานจำนวนเหรียญของ{$label}ต้องเป็นจำนวนเต็มบวกไม่เกิน 10^30";
                } else {
                    $next['types'][$key]['max_supply'] = $supply;
                }
            }

            $min = array_key_exists('decimals_min', $row) ? filter_var($row['decimals_min'], FILTER_VALIDATE_INT) : $next['types'][$key]['decimals_min'];
            $max = array_key_exists('decimals_max', $row) ? filter_var($row['decimals_max'], FILTER_VALIDATE_INT) : $next['types'][$key]['decimals_max'];
            if ($min === false || $max === false || $min < 0 || $max > 18 || $min > $max) {
                $errors["types.{$key}.decimals"] = "ช่วงทศนิยมของ{$label}ต้องอยู่ใน 0–18 และค่าต่ำสุดไม่เกินค่าสูงสุด";
            } else {
                $next['types'][$key]['decimals_min'] = $min;
                $next['types'][$key]['decimals_max'] = $max;
            }

            foreach (['auto_approve', 'require_kyc'] as $flag) {
                if (array_key_exists($flag, $row)) {
                    $next['types'][$key][$flag] = filter_var($row[$flag], FILTER_VALIDATE_BOOLEAN);
                }
            }
        }

        // ── ตัวเลือก ───────────────────────────────────────────────────────
        foreach ((array) ($input['options'] ?? []) as $key => $row) {
            if (! isset(self::OPTIONS[$key]) || ! is_array($row)) {
                continue;
            }
            $label = self::OPTIONS[$key]['label_th'];

            if (array_key_exists('state', $row)) {
                $state = (string) $row['state'];
                if (isset(self::LOCKED["options.{$key}"]) && $state !== self::STATE_OFF && $state !== self::STATE_LOCKED) {
                    $errors["options.{$key}.state"] = "ตัวเลือก {$label} ถูกล็อกในโค้ด เปิดไม่ได้ — ".self::LOCKED["options.{$key}"];
                } elseif (! in_array($state, [self::STATE_ON, self::STATE_OFF, self::STATE_LOCKED], true)) {
                    $errors["options.{$key}.state"] = "สถานะของตัวเลือก {$label} ไม่ถูกต้อง";
                } else {
                    $next['options'][$key]['state'] = $state === self::STATE_ON ? self::STATE_ON : self::STATE_OFF;
                }
            }

            if (array_key_exists('fee', $row)) {
                $fee = filter_var($row['fee'], FILTER_VALIDATE_FLOAT);
                if ($fee === false || $fee <= 0 || $fee > self::MAX_FEE) {
                    $errors["options.{$key}.fee"] = "ค่าธรรมเนียมตัวเลือก {$label} ต้องมากกว่า 0 และไม่เกิน ".number_format(self::MAX_FEE).' TPIX';
                } else {
                    $next['options'][$key]['fee'] = round($fee, 6);
                }
            }
        }

        return ['policy' => $next, 'errors' => $errors];
    }

    /**
     * บันทึกนโยบายใหม่ แล้วคืนส่วนที่เปลี่ยน (สำหรับ audit log).
     *
     * @return array{old: array<string,mixed>, new: array<string,mixed>}
     */
    public function save(array $next): array
    {
        $before = Arr::dot($this->editableSnapshot($this->effective()));
        $after = Arr::dot($next);

        SiteSetting::set(self::GROUP, self::KEY, json_encode($next, JSON_UNESCAPED_UNICODE), 'json');
        $this->refresh();

        $old = [];
        $new = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $path) {
            $was = $before[$path] ?? null;
            $now = $after[$path] ?? null;
            if ($was !== $now) {
                $old[$path] = $was;
                $new[$path] = $now;
            }
        }

        return ['old' => $old, 'new' => $new];
    }

    // =========================================================================
    //  ภายใน
    // =========================================================================

    /** ส่วนที่แอดมินแก้ได้ — ไม่มีข้อมูลประจำประเภท และไม่มีสถานะ locked (คิดใหม่ทุกครั้งจากโค้ด) */
    private function editableSnapshot(array $policy): array
    {
        $snapshot = Arr::only($policy, [
            'creation_enabled', 'fee_wallet', 'required_confirmations', 'quote_ttl_minutes',
            'daily_limit_per_wallet', 'name_max_length', 'symbol_max_length', 'auto_list_on_deploy',
            'require_kyc', 'refund_mode', 'reserved_symbols',
        ]);

        foreach ($policy['types'] as $key => $type) {
            $snapshot['types'][$key] = Arr::only($type, ['state', 'fee', 'max_supply', 'decimals_min', 'decimals_max', 'auto_approve', 'require_kyc']);
            if ($snapshot['types'][$key]['state'] === self::STATE_LOCKED) {
                $snapshot['types'][$key]['state'] = self::STATE_OFF;
            }
        }
        foreach ($policy['options'] as $key => $option) {
            $snapshot['options'][$key] = Arr::only($option, ['state', 'fee']);
            if ($snapshot['options'][$key]['state'] === self::STATE_LOCKED) {
                $snapshot['options'][$key]['state'] = self::STATE_OFF;
            }
        }

        return $snapshot;
    }

    /** ผสานเฉพาะคีย์ที่รู้จัก — ของแปลกปลอมใน JSON ถูกทิ้ง */
    private function mergeKnown(array $defaults, array $overrides): array
    {
        $merged = $defaults;

        foreach (['creation_enabled', 'fee_wallet', 'required_confirmations', 'quote_ttl_minutes',
            'daily_limit_per_wallet', 'name_max_length', 'symbol_max_length', 'auto_list_on_deploy',
            'require_kyc', 'refund_mode', 'reserved_symbols'] as $key) {
            if (array_key_exists($key, $overrides)) {
                $merged[$key] = $overrides[$key];
            }
        }

        foreach (['types' => self::TYPES, 'options' => self::OPTIONS] as $group => $known) {
            foreach (array_keys($known) as $key) {
                $row = $overrides[$group][$key] ?? null;
                if (is_array($row)) {
                    $merged[$group][$key] = array_merge($merged[$group][$key] ?? [], $row);
                }
            }
        }

        return $merged;
    }

    /** ให้ทุกช่องมีค่าและชนิดถูกต้องเสมอ ต่อให้ config/JSON ขาดบางคีย์ */
    private function normalize(array $policy): array
    {
        $out = [
            'creation_enabled' => array_key_exists('creation_enabled', $policy) && $policy['creation_enabled'] !== null
                ? (bool) $policy['creation_enabled'] : true,
            'fee_wallet' => strtolower(trim((string) ($policy['fee_wallet'] ?? ''))),
            'required_confirmations' => max(1, (int) ($policy['required_confirmations'] ?? 2)),
            'quote_ttl_minutes' => max(5, (int) ($policy['quote_ttl_minutes'] ?? 30)),
            'daily_limit_per_wallet' => max(1, (int) ($policy['daily_limit_per_wallet'] ?? 3)),
            'name_max_length' => min(100, max(2, (int) ($policy['name_max_length'] ?? 50))),
            'symbol_max_length' => min(20, max(2, (int) ($policy['symbol_max_length'] ?? 11))),
            'auto_list_on_deploy' => (bool) ($policy['auto_list_on_deploy'] ?? false),
            'require_kyc' => (bool) ($policy['require_kyc'] ?? false),
            'refund_mode' => ($policy['refund_mode'] ?? null) === self::REFUND_AUTO ? self::REFUND_AUTO : self::REFUND_MANUAL,
            'reserved_symbols' => [],
            'types' => [],
            'options' => [],
        ];

        foreach ((array) ($policy['reserved_symbols'] ?? []) as $symbol => $wallets) {
            // รองรับทั้ง ['TPIX', 'USDT'] และ ['IDTC' => ['0x...']]
            if (is_int($symbol)) {
                [$symbol, $wallets] = [(string) $wallets, []];
            }
            $symbol = strtoupper(trim((string) $symbol));
            if ($symbol === '') {
                continue;
            }
            $out['reserved_symbols'][$symbol] = array_values(array_filter(
                array_map(fn ($w) => strtolower(trim((string) $w)), (array) $wallets),
                fn ($w) => (bool) preg_match('/^0x[a-f0-9]{40}$/', $w)
            ));
        }

        foreach (array_keys(self::TYPES) as $key) {
            $row = (array) ($policy['types'][$key] ?? []);
            $min = (int) ($row['decimals_min'] ?? 18);
            $max = (int) ($row['decimals_max'] ?? 18);
            $out['types'][$key] = [
                'state' => in_array($row['state'] ?? null, [self::STATE_ON, self::STATE_OFF], true) ? $row['state'] : self::STATE_OFF,
                'fee' => $this->chargedFee($row['fee'] ?? null, "types.{$key}.fee"),
                'max_supply' => preg_match('/^[1-9][0-9]*$/', (string) ($row['max_supply'] ?? '')) ? (string) $row['max_supply'] : '1000000000000000',
                'decimals_min' => max(0, min(18, $min)),
                'decimals_max' => max(0, min(18, max($min, $max))),
                'auto_approve' => (bool) ($row['auto_approve'] ?? false),
                'require_kyc' => (bool) ($row['require_kyc'] ?? false),
                'locked_reason' => null,
            ];
        }

        foreach (array_keys(self::OPTIONS) as $key) {
            $row = (array) ($policy['options'][$key] ?? []);
            $out['options'][$key] = [
                'state' => in_array($row['state'] ?? null, [self::STATE_ON, self::STATE_OFF], true) ? $row['state'] : self::STATE_OFF,
                'fee' => $this->chargedFee($row['fee'] ?? null, "options.{$key}.fee"),
                'locked_reason' => null,
            ];
        }

        return $out;
    }

    /**
     * ค่าธรรมเนียมที่ใช้จริง — ทุกประเภทและทุกตัวเลือกต้องมีราคา.
     *
     * ค่า 0 ที่แอดมินเคยบันทึกไว้ก่อนมีกฎนี้ (เช่น "เผาเหรียญได้" บน production) ถูกแทนด้วยค่าตั้งต้นใน config
     */
    private function chargedFee(mixed $fee, string $path): float
    {
        $fee = (float) $fee;
        if ($fee > 0) {
            return $fee;
        }

        $default = (float) config("blockchain.token_factory_policy.{$path}", 0);

        return $default > 0 ? $default : self::MIN_FEE_FALLBACK;
    }

    /**
     * ตรวจค่าตัวเลข/ที่อยู่/URI ในตัวเลือกย่อย.
     *
     * @return array<string,string>
     */
    private function validateSubOptionValues(string $type, array $sub, string $supply, array $typeConfig, int $tokenDecimals = 18): array
    {
        $errors = [];
        $allowed = self::SUB_OPTION_KEYS[$type] ?? [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $sub) || $sub[$key] === null || $sub[$key] === '') {
                continue;
            }
            $value = $sub[$key];
            $field = "sub_options.{$key}";

            if (in_array($key, self::BOOL_KEYS, true)) {
                continue;
            }

            if (isset(self::SELECT_CHOICES[$key])) {
                if (! in_array($value, self::SELECT_CHOICES[$key], true)) {
                    $errors[$field] = "ค่าของ {$key} ไม่ถูกต้อง";
                }

                continue;
            }

            if (in_array($key, self::ADDRESS_KEYS, true)) {
                if (! is_string($value) || ! preg_match('/^0x[a-fA-F0-9]{40}$/', $value)) {
                    $errors[$field] = 'ที่อยู่กระเป๋าไม่ถูกต้อง';
                }

                continue;
            }

            if (in_array($key, self::URI_KEYS, true)) {
                if (! is_string($value) || mb_strlen($value) > 500
                    || ! (str_starts_with($value, 'https://') || str_starts_with($value, 'ipfs://'))) {
                    $errors[$field] = 'ที่อยู่ข้อมูลต้องขึ้นต้นด้วย https:// หรือ ipfs:// และยาวไม่เกิน 500 ตัวอักษร';
                }

                continue;
            }

            if (is_array($value) || is_object($value) || ! is_numeric($value)) {
                $errors[$field] = "ค่าของ {$key} ต้องเป็นตัวเลข";

                continue;
            }

            if (array_key_exists($key, self::UNIT_KEYS)) {
                $plain = self::plainDecimal($value);
                $maxDecimals = self::UNIT_KEYS[$key] ?? $tokenDecimals;
                $fraction = $plain !== null && str_contains($plain, '.') ? strlen(explode('.', $plain)[1]) : 0;

                if ($plain === null) {
                    $errors[$field] = "ค่าของ {$key} ต้องเป็นตัวเลขไม่ติดลบ";
                } elseif ($fraction > $maxDecimals) {
                    $errors[$field] = $maxDecimals === 0
                        ? "ค่าของ {$key} ต้องเป็นจำนวนเต็ม"
                        : "ค่าของ {$key} มีทศนิยมได้ไม่เกิน {$maxDecimals} ตำแหน่ง";
                }

                continue;
            }

            $number = (float) $value;
            if ($number < 0) {
                $errors[$field] = "ค่าของ {$key} ติดลบไม่ได้";

                continue;
            }

            if (isset(self::NUMBER_RANGES[$key])) {
                [$min, $max] = self::NUMBER_RANGES[$key];
                if ($number < $min || $number > $max) {
                    $errors[$field] = "ค่าของ {$key} ต้องอยู่ระหว่าง {$min}–{$max}";
                }
            }
        }

        // เพดาน mint ต้องไม่ต่ำกว่าจำนวนที่ออกตอนแรก และไม่เกินเพดานที่แอดมินตั้ง
        if ($this->truthy($sub, 'mint_cap_enabled') && in_array('mint_cap', $allowed, true)) {
            $cap = (string) (self::plainDecimal($sub['mint_cap'] ?? null) ?? '');
            if (! ctype_digit($cap) || $cap === '' || bccomp($cap, '0', 0) <= 0) {
                $errors['sub_options.mint_cap'] = 'ต้องระบุเพดาน mint เป็นจำนวนเต็มบวก';
            } elseif ($supply !== '' && ctype_digit($supply) && bccomp($cap, $supply, 0) < 0) {
                $errors['sub_options.mint_cap'] = 'เพดาน mint ต้องไม่ต่ำกว่าจำนวนเหรียญตั้งต้น';
            } elseif (bccomp($cap, (string) $typeConfig['max_supply'], 0) > 0) {
                $errors['sub_options.mint_cap'] = 'เพดาน mint เกินเพดานจำนวนเหรียญที่ผู้ดูแลกำหนด';
            }
        }

        // จำนวนสำรองต้องไม่เกินจำนวนทั้งหมดของคอลเลกชัน
        if (isset($sub['reserve_count']) && is_numeric($sub['reserve_count']) && $supply !== '' && ctype_digit($supply)
            && bccomp((string) (int) $sub['reserve_count'], $supply, 0) > 0) {
            $errors['sub_options.reserve_count'] = 'จำนวนสำรองต้องไม่เกินจำนวน NFT ทั้งหมด';
        }

        return $errors;
    }

    /**
     * ตัวเลขไม่ติดลบ → ทศนิยมแบบ string ไม่มี e-notation ("1.0E-7" → "0.0000001") — null = ไม่ใช่ตัวเลขที่ใช้ได้.
     *
     * float จาก JSON แปลงจากรูปสั้นสุดที่ PHP พิมพ์ (ไม่ใช่ sprintf %.18F ที่ได้ 0.1000000000000000055)
     */
    public static function plainDecimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value < 0 ? null : (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value) || $value < 0) {
                return null;
            }
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $text = strtolower(trim($value));

        if (preg_match('/^(\d+)(?:\.(\d*))?e([+-]?\d+)$/', $text, $m)) {
            $digits = $m[1].($m[2] ?? '');
            $point = strlen($m[1]) + (int) $m[3];
            if ($point <= 0) {
                $text = '0.'.str_repeat('0', -$point).$digits;
            } elseif ($point >= strlen($digits)) {
                $text = $digits.str_repeat('0', $point - strlen($digits));
            } else {
                $text = substr($digits, 0, $point).'.'.substr($digits, $point);
            }
        }

        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $text, $m)) {
            return null;
        }

        $integer = ltrim($m[1], '0');
        $fraction = rtrim($m[2] ?? '', '0');

        return ($integer === '' ? '0' : $integer).($fraction !== '' ? '.'.$fraction : '');
    }

    /** ตีความสวิตช์แบบเดียวกับสคริปต์ deploy (true, 'true', 1, '1') */
    private function truthy(array $sub, string ...$keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $sub) || $sub[$key] === null || $sub[$key] === '') {
                continue;
            }
            $value = $sub[$key];

            return $value === true || $value === 1 || $value === '1' || $value === 'true';
        }

        return false;
    }
}
