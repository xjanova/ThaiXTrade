<?php

namespace App\Services;

use App\Jobs\CheckFactoryDeployReceiptJob;
use App\Jobs\DeployTokenJob;
use App\Models\FactoryToken;
use App\Models\TokenFactoryFeeQuote;
use App\Services\TokenFactory\TokenFactoryChain;
use App\Services\TokenFactory\TokenFactoryException;
use App\Services\TokenFactory\TokenFactoryFeeVerifier;
use App\Services\TokenFactory\TokenFactoryPolicy;
use App\Services\TokenFactory\TokenFactoryRefunds;
use App\Support\Wei;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * TokenFactoryService — คำขอสร้างเหรียญ ตั้งแต่ใบเสนอราคาจนถึงสั่ง deploy.
 *
 * ลำดับที่ถูกต้อง (ทุกขั้นตรวจฝั่งเซิร์ฟเวอร์ ไม่เชื่อหน้าเว็บ):
 *   1. prepare()    — ตรวจฟอร์ม + นโยบาย (ประเภท/ตัวเลือกที่เปิด, ยอมรับความเสี่ยง, สัญลักษณ์สงวน ...)
 *   2. quote()      — ล็อกยอดค่าธรรมเนียม ผูกกับกระเป๋าและตัวเลือกที่คิดเงิน
 *   3. ผู้ใช้โอน TPIX บนเชน 4289 ไปที่กระเป๋ารับค่าธรรมเนียม
 *   4. createToken() — ตรวจธุรกรรมบนเชนจริง แล้วบันทึกคำขอ (hash ใช้ได้ครั้งเดียว)
 *   5. approveToken() — แอดมินกด (หรืออนุมัติอัตโนมัติตามนโยบาย) → DeployTokenJob ตัวเดียว
 *
 * Developed by Xman Studio
 */
class TokenFactoryService
{
    /** สถานะที่ถือสัญลักษณ์ไว้ (ตรงกับคอลัมน์ generated active_symbol) */
    public const ACTIVE_STATUSES = ['pending', 'deploying', 'deployed'];

    /**
     * คำขอที่ "ยังไม่จบ" — failed นับด้วยเพราะทีมงานยังสั่งสร้างใหม่ได้ (ค่าธรรมเนียมจ่ายแล้ว)
     * จบจริงคือ deployed หรือ rejected เท่านั้น.
     */
    public const IN_PROGRESS_STATUSES = ['pending', 'deploying', 'failed'];

    /** ข้อความขั้นตอนของคำขอที่ยังไม่จบ — หน้าเว็บใช้ชุดเดียวกัน. */
    public const IN_PROGRESS_STAGE_TH = [
        'pending' => 'รอผู้ดูแลตรวจสอบและอนุมัติ',
        'deploying' => 'กำลังสร้างเหรียญบนเชน',
        'failed' => 'สร้างบนเชนไม่สำเร็จ — ทีมงานกำลังตรวจสอบและสั่งสร้างใหม่ (ค่าธรรมเนียมที่จ่ายแล้วไม่หาย)',
    ];

    /** แถวที่ค้าง deploying นานเกินเท่านี้ (และยังไม่มีธุรกรรม) แอดมินรีเซ็ตได้ */
    public const STUCK_MINUTES = 15;

    public function __construct(
        private readonly TokenFactoryPolicy $policy,
        private readonly TokenFactoryFeeVerifier $feeVerifier,
        private readonly TokenFactoryChain $chain,
        private readonly ContractRegistry $contracts,
        private readonly TokenFactoryRefunds $refunds,
        private readonly BlockscoutVerifyService $explorer,
    ) {}

    // =========================================================================
    //  คำขอจากผู้ใช้
    // =========================================================================

    /**
     * ตรวจฟอร์มและนโยบายทั้งหมด — ใช้ร่วมกันระหว่างขอใบเสนอราคาและสร้างจริง.
     *
     * @return array{data: array, decision: array}
     *
     * @throws ValidationException ข้อความภาษาไทยรายช่อง
     */
    public function prepare(array $input, string $creator, bool $forCreate = false): array
    {
        $creator = strtolower($creator);
        $uiTypes = array_merge(...array_column(TokenFactoryPolicy::TYPES, 'ui_types'));

        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'symbol' => ['required', 'string', 'min:2', 'max:20'],
            'decimals' => ['nullable', 'integer', 'min:0', 'max:18'],
            'total_supply' => ['required', 'regex:/^[1-9][0-9]{0,35}$/'],
            'token_type' => ['required', 'string', 'in:'.implode(',', $uiTypes)],
            'token_category' => ['nullable', 'in:fungible,nft,special'],
            // เชนเดียวที่ deploy ได้จริงคือ 4289 — เดิมรับ 4290 (testnet ฟรี) แต่สคริปต์ส่งขึ้น 4289 เสมอ
            'chain_id' => ['nullable', 'integer', 'in:'.$this->policy->chainId()],
            'description' => ['nullable', 'string', 'max:1000'],
            'website' => ['nullable', 'url:https', 'max:255'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'sub_options' => ['nullable', 'array'],
            'risk_ack' => ['nullable', 'array'],
            'risk_ack.*' => ['string', 'max:40'],
        ];

        if ($forCreate) {
            $rules['quote_id'] = ['required', 'uuid'];
            $rules['fee_tx_hash'] = ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'];
        }

        $messages = [
            'required' => 'กรุณากรอก :attribute',
            'total_supply.regex' => 'จำนวนเหรียญต้องเป็นจำนวนเต็มบวก',
            'token_type.in' => 'ไม่รู้จักประเภทเหรียญนี้',
            'chain_id.in' => 'สร้างเหรียญได้เฉพาะบนเชน TPIX ('.$this->policy->chainId().') เท่านั้น',
            'website.url' => 'เว็บไซต์ต้องเป็นลิงก์ https://',
            'quote_id.required' => 'ต้องขอใบเสนอราคาค่าธรรมเนียมก่อน',
            'quote_id.uuid' => 'รหัสใบเสนอราคาไม่ถูกต้อง',
            'fee_tx_hash.regex' => 'รูปแบบ tx hash ของค่าธรรมเนียมไม่ถูกต้อง',
            'decimals.*' => 'ทศนิยมต้องอยู่ระหว่าง 0–18',
            'name.*' => 'ชื่อเหรียญต้องยาว 2–100 ตัวอักษร',
            'symbol.*' => 'สัญลักษณ์ต้องยาว 2–20 ตัวอักษร',
        ];

        $data = Validator::make($input, $rules, $messages, [
            'name' => 'ชื่อเหรียญ', 'symbol' => 'สัญลักษณ์', 'total_supply' => 'จำนวนเหรียญ', 'token_type' => 'ประเภทเหรียญ',
        ])->validate();

        $data['symbol'] = strtoupper(trim($data['symbol']));
        $data['name'] = trim($data['name']);
        $data['total_supply'] = (string) $data['total_supply'];
        $data['chain_id'] = $this->policy->chainId();
        $data['sub_options'] = $data['sub_options'] ?? [];

        $decision = $this->policy->evaluate([
            'token_type' => $data['token_type'],
            'sub_options' => $data['sub_options'],
            'decimals' => $data['decimals'] ?? 18,
            'total_supply' => $data['total_supply'],
            'symbol' => $data['symbol'],
            'name' => $data['name'],
            'creator' => $creator,
            'risk_ack' => $data['risk_ack'] ?? [],
        ]);

        $errors = $decision['errors'];

        $type = $decision['type'];
        $category = $type !== null ? TokenFactoryPolicy::TYPES[$type]['category'] : 'fungible';
        $data['token_category'] = $category;
        $data['decimals'] = $category === 'nft' ? 0 : (int) ($data['decimals'] ?? 18);

        // โลโก้ต้องเป็นไฟล์ที่กระเป๋านี้อัปโหลดเองผ่าน /upload-logo เท่านั้น
        // เดิมรับ https:// อะไรก็ได้ = ฝังรูปจากโดเมนคนอื่น (ติดตาม IP ผู้ชม / เปลี่ยนรูปทีหลังได้)
        if (! empty($data['logo_url']) && ! $this->isOwnUploadedLogo($data['logo_url'], $creator)) {
            $errors['logo_url'] = 'โลโก้ต้องเป็นไฟล์ที่อัปโหลดผ่านหน้าสร้างเหรียญจากกระเป๋านี้เท่านั้น';
        }

        if (! isset($errors['symbol']) && $this->symbolTaken($data['symbol'])) {
            $errors['symbol'] = "สัญลักษณ์ {$data['symbol']} มีคนใช้แล้ว หรือกำลังรออนุมัติอยู่";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return ['data' => $data, 'decision' => $decision];
    }

    /**
     * ออกใบเสนอราคาค่าธรรมเนียม (ล็อกยอดตามนโยบาย ณ ตอนนี้).
     *
     * @throws TokenFactoryException
     */
    public function quote(array $prepared, string $creator): TokenFactoryFeeQuote
    {
        $creator = strtolower($creator);
        $this->assertCanCreate($prepared['data']['token_type'], $creator);
        // เฉพาะตอนออกใบเสนอราคา (ก่อนจ่ายเงิน) — ตอน /create ห้ามตรวจข้อนี้
        // ไม่งั้นผู้ใช้ที่จ่ายแล้วกด "ส่งคำขอต่อ" หลังคำขอเข้าไปแล้วจะติดค้างตลอด
        $this->assertNoRequestInProgress($creator);

        // ยืนยันไม่ได้ = ห้ามเก็บเงิน ต้องตรวจตอนนี้ ตอน /create ผู้ใช้จ่ายไปแล้ว
        if (in_array('source_verify', $prepared['decision']['options'], true)
            && ! $this->explorer->supports(new FactoryToken(['token_type' => $prepared['data']['token_type']]))) {
            throw new TokenFactoryException('SOURCE_VERIFY_UNSUPPORTED', 'ยังยืนยันซอร์สของเหรียญชนิดนี้ไม่ได้ กรุณาติดต่อทีมงาน');
        }

        $fee = $prepared['decision']['fee'];
        $amount = $this->decimalString($fee['total']);
        $amountWei = Wei::toWei($amount);
        $feeWallet = $this->policy->feeWallet();

        if (bccomp($amountWei, '0', 0) > 0 && $feeWallet === '') {
            throw new TokenFactoryException('FEE_WALLET_MISSING', 'ระบบยังไม่ได้ตั้งกระเป๋ารับค่าธรรมเนียม กรุณาติดต่อทีมงาน', 503);
        }

        return TokenFactoryFeeQuote::create([
            'creator_address' => $creator,
            'chain_id' => $this->policy->chainId(),
            'token_type' => $prepared['data']['token_type'],
            'params_hash' => $prepared['decision']['params_hash'],
            'amount' => $amount,
            'amount_wei' => $amountWei,
            'fee_wallet' => $feeWallet !== '' ? $feeWallet : null,
            'breakdown' => $fee['breakdown'],
            'expires_at' => now()->addMinutes((int) $this->policy->effective()['quote_ttl_minutes']),
        ]);
    }

    /**
     * ตรวจสถานะการชำระค่าธรรมเนียม (หน้าเว็บถามซ้ำระหว่างรอบล็อกยืนยัน).
     *
     * @throws TokenFactoryException FEE_PENDING เมื่อยังไม่ครบ
     */
    public function feeStatus(string $quoteId, string $txHash, string $creator): array
    {
        $quote = $this->findQuote($quoteId, $creator);

        return $this->feeVerifier->verify($quote, $txHash, $creator);
    }

    /**
     * บันทึกคำขอสร้างเหรียญ — หลังตรวจค่าธรรมเนียมบนเชนแล้วเท่านั้น.
     *
     * @throws TokenFactoryException
     */
    public function createToken(array $prepared, string $creator): FactoryToken
    {
        $creator = strtolower($creator);
        $data = $prepared['data'];
        $decision = $prepared['decision'];

        $this->assertCanCreate($data['token_type'], $creator);

        $quote = $this->findQuote($data['quote_id'], $creator, TokenFactoryFeeQuote::PURPOSE_CREATE);

        if ($quote->used_at !== null) {
            throw new TokenFactoryException('QUOTE_USED', 'ใบเสนอราคานี้ถูกใช้สร้างเหรียญไปแล้ว', 409);
        }

        if (! hash_equals($quote->params_hash, $decision['params_hash'])) {
            throw new TokenFactoryException('QUOTE_MISMATCH', 'ประเภทหรือตัวเลือกไม่ตรงกับใบเสนอราคา กรุณาขอใบเสนอราคาใหม่');
        }

        $feeTxHash = null;
        $verification = null;

        if ($quote->isFree()) {
            if ($quote->isExpired()) {
                throw new TokenFactoryException('QUOTE_EXPIRED', 'ใบเสนอราคาหมดอายุแล้ว กรุณาขอใหม่');
            }
        } else {
            $feeTxHash = strtolower(trim((string) ($data['fee_tx_hash'] ?? '')));
            if ($feeTxHash === '') {
                throw new TokenFactoryException('FEE_REQUIRED', 'ต้องชำระค่าธรรมเนียมก่อนส่งคำขอสร้างเหรียญ', 402);
            }

            // โยน FEE_PENDING (202) ถ้ายังยืนยันไม่ครบ — หน้าเว็บถามซ้ำได้
            $verification = $this->feeVerifier->verify($quote, $feeTxHash, $creator);
        }

        $metadata = [
            'policy_type' => $decision['type'],
            'requested_options' => $decision['options'],
            'risk_ack' => array_values(array_intersect($decision['required_acks'], (array) ($data['risk_ack'] ?? []))),
            'sub_options' => $decision['sub_options'],
            'fee_amount' => (float) $quote->amount,
            'fee_currency' => 'TPIX',
            'fee_breakdown' => $quote->breakdown ?? [],
            'fee_wallet' => $quote->fee_wallet,
            'fee_tx_hash' => $feeTxHash,
            'fee_block' => $verification['block'] ?? null,
            'fee_confirmations' => $verification['confirmations'] ?? null,
            // ยอดที่โอนมาจริง (wei) — ถูกปฏิเสธแล้วคืน "เต็มจำนวน" ตามนี้ ไม่ใช่ตามยอดในใบเสนอราคา
            'fee_value_wei' => $verification['value_wei'] ?? null,
        ];

        // ติ๊กยืนยันซอร์สมาด้วย = จ่ายรวมในใบเสนอราคานี้แล้ว — deploy เสร็จระบบส่งซอร์สให้ explorer เอง
        if (in_array('source_verify', $decision['options'], true)) {
            $metadata['source_verify'] = [
                'paid' => true,
                'via' => 'create',
                'fee_tx_hash' => $feeTxHash,
                'paid_at' => now()->toIso8601String(),
            ];
        }

        try {
            $write = fn () => DB::transaction(function () use ($quote, $data, $creator, $feeTxHash, $metadata) {
                $locked = TokenFactoryFeeQuote::whereKey($quote->id)->lockForUpdate()->first();
                if (! $locked || $locked->used_at !== null) {
                    throw new TokenFactoryException('QUOTE_USED', 'ใบเสนอราคานี้ถูกใช้สร้างเหรียญไปแล้ว', 409);
                }

                $token = FactoryToken::create([
                    'name' => $data['name'],
                    'symbol' => $data['symbol'],
                    'decimals' => $data['decimals'],
                    'total_supply' => $data['total_supply'],
                    'creator_address' => $creator,
                    'chain_id' => $data['chain_id'],
                    'logo_url' => $data['logo_url'] ?? null,
                    'description' => $data['description'] ?? null,
                    'website' => $data['website'] ?? null,
                    'token_type' => $data['token_type'],
                    'token_category' => $data['token_category'],
                    'status' => 'pending',
                    'fee_tx_hash' => $feeTxHash,
                    'fee_quote_id' => $quote->id,
                    'is_listed' => false,
                    'is_verified' => false,
                    'metadata' => $metadata,
                ]);

                $locked->update(['used_at' => now(), 'factory_token_id' => $token->id]);

                return $token;
            });

            // จ่ายจริง = ล็อกที่ hash กันเอาธุรกรรมเดียวไปจ่ายค่ายืนยันซอร์สพร้อมกัน
            $token = $feeTxHash !== null ? TokenFactoryFeeVerifier::claimTx($feeTxHash, $write) : $write();
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            if ($feeTxHash !== null && FactoryToken::where('fee_tx_hash', $feeTxHash)->exists()) {
                throw new TokenFactoryException('FEE_TX_USED', 'ธุรกรรมค่าธรรมเนียมนี้ถูกใช้สร้างเหรียญไปแล้ว', 409);
            }

            throw new TokenFactoryException('SYMBOL_TAKEN', "สัญลักษณ์ {$data['symbol']} มีคนใช้แล้ว หรือกำลังรออนุมัติอยู่", 409);
        }

        Log::info('Token creation request submitted', [
            'token_id' => $token->id,
            'symbol' => $token->symbol,
            'creator' => $token->creator_address,
            'fee' => $quote->amount,
            'fee_tx' => $feeTxHash,
        ]);

        // อนุมัติอัตโนมัติเฉพาะประเภทที่แอดมินเปิดไว้ และคำขอไม่มีของเสี่ยงเลย
        if ($decision['auto_approve']) {
            try {
                $this->approveToken($token);
                Log::info("Token {$token->symbol} auto-approved", ['token_id' => $token->id]);
            } catch (\InvalidArgumentException $e) {
                Log::warning("Token {$token->symbol} auto-approve skipped", ['reason' => $e->getMessage()]);
            }
        }

        return $token->fresh();
    }

    // =========================================================================
    //  รายการ
    // =========================================================================

    public function getTokensByCreator(string $address, int $perPage = 20): LengthAwarePaginator
    {
        return FactoryToken::byCreator(strtolower($address))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function getDeployedTokens(?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = FactoryToken::deployed()
            ->where('is_listed', true)
            ->orderByDesc('created_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('symbol', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    public function getAllTokens(?string $status = null, ?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = FactoryToken::query()->with('chain')->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('symbol', 'like', "%{$search}%")
                    ->orWhere('creator_address', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    // =========================================================================
    //  แอดมิน
    // =========================================================================

    /**
     * อนุมัติ → สั่ง deploy **ครั้งเดียว**.
     *
     * เปลี่ยนสถานะด้วย UPDATE ... WHERE status IN (pending, failed) แถวเดียว
     * กดซ้ำ/สองแอดมินกดพร้อมกัน คนที่สองได้ 0 แถว → ไม่ dispatch งานที่สอง
     * (เดิม update แล้ว dispatch แยกกัน กดสองครั้ง = สองงาน = สองสัญญา supply เต็ม)
     *
     * @throws \InvalidArgumentException ข้อความภาษาไทยสำหรับแสดงแอดมิน
     */
    public function approveToken(FactoryToken $token): FactoryToken
    {
        if (! in_array($token->status, ['pending', 'failed'], true)) {
            throw new \InvalidArgumentException(
                "อนุมัติไม่ได้: สถานะตอนนี้คือ '{$token->status}' (อนุมัติได้เฉพาะ pending หรือ failed)"
            );
        }

        if ($token->tx_hash) {
            throw new \InvalidArgumentException('มีธุรกรรมสร้างเหรียญเดิมค้างอยู่ — ต้องตรวจผลธุรกรรมก่อน ห้ามส่งซ้ำ');
        }

        // ใช้กับ retry ด้วย — คำขอเก่า (testnet ฟรี / ไม่มีการตรวจค่าธรรมเนียม) ห้ามหลุดไป deploy บน 4289
        $blocker = $this->deployBlocker($token);
        if ($blocker !== null) {
            throw new \InvalidArgumentException($blocker);
        }

        $ready = $this->readyFor($token->token_type);
        if (! $ready['ok']) {
            throw new \InvalidArgumentException('ยัง deploy ไม่ได้: '.$ready['reason']);
        }

        try {
            $claimed = FactoryToken::whereKey($token->id)
                ->whereIn('status', ['pending', 'failed'])
                ->whereNull('tx_hash')
                ->update(['status' => 'deploying', 'deploy_started_at' => null, 'updated_at' => now()]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new \InvalidArgumentException("สัญลักษณ์ {$token->symbol} ถูกเหรียญอื่นใช้ไปแล้ว");
            }

            throw $e;
        }

        if ($claimed !== 1) {
            throw new \InvalidArgumentException('เหรียญนี้กำลังถูกดำเนินการอยู่แล้ว (มีการอนุมัติไปก่อนหน้า)');
        }

        try {
            DeployTokenJob::dispatch($token->id);
        } catch (\Throwable $e) {
            // ส่งงานเข้าคิวไม่ได้ (เช่น redis ล่ม) — คืนสถานะ ไม่งั้นแถวค้าง deploying ไม่มีงานไหนมารับ
            // คืนได้เฉพาะเมื่อยังไม่มีงานไหนเริ่ม (คิว sync อาจรันงานไปแล้วในตัว dispatch)
            $reverted = FactoryToken::whereKey($token->id)
                ->where('status', 'deploying')
                ->whereNull('tx_hash')
                ->whereNull('deploy_started_at')
                ->update(['status' => $token->status, 'updated_at' => now()]);

            Log::error('สั่งงาน deploy ไม่สำเร็จ', ['token_id' => $token->id, 'reverted' => $reverted, 'error' => $e->getMessage()]);

            throw new \InvalidArgumentException($reverted === 1
                ? 'สั่งงาน deploy ไม่สำเร็จ (คิวมีปัญหา) — คืนสถานะเดิมแล้ว ลองใหม่อีกครั้ง'
                : 'งาน deploy ล้มระหว่างทำงาน: '.mb_substr($e->getMessage(), 0, 200));
        }

        Log::info("Token {$token->symbol} approved, deployment job dispatched", [
            'token_id' => $token->id,
            'creator' => $token->creator_address,
            'type' => $token->token_type,
        ]);

        return $token->fresh();
    }

    public function rejectToken(FactoryToken $token, string $reason): FactoryToken
    {
        $updated = FactoryToken::whereKey($token->id)
            ->whereIn('status', ['pending', 'failed'])
            ->whereNull('tx_hash')
            ->update(['status' => 'rejected', 'reject_reason' => $reason, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new \InvalidArgumentException(
                "ปฏิเสธไม่ได้: สถานะตอนนี้คือ '{$token->fresh()?->status}' (ปฏิเสธได้เฉพาะ pending หรือ failed)"
            );
        }

        Log::info("Token {$token->symbol} rejected", ['token_id' => $token->id, 'reason' => $reason]);

        // จ่ายค่าธรรมเนียมมาแล้ว = ต้องคืนเต็มจำนวน (แอดมินโอนเอง หรือระบบจ่ายอัตโนมัติ ตามนโยบาย)
        $this->refunds->openForRejected($token->fresh());

        return $token->fresh();
    }

    /**
     * เหตุที่แถวนี้ deploy ไม่ได้ — null = ได้.
     *
     * ใช้ทั้งตอนอนุมัติ/retry และในงาน deploy เอง (นโยบายอาจเปลี่ยนระหว่างรอคิว)
     *   - ต้องเป็นเชน 4289 — คำขอเก่าที่เลือก testnet 4290 (ฟรี) เคยถูกส่งขึ้น mainnet
     *     และหลุด unique index (chain_id, active_symbol) เพราะ chain_id ไม่ตรง
     *   - ต้องมีใบเสนอราคาที่ถูกใช้กับแถวนี้จริง (และ tx ค่าธรรมเนียมที่ตรวจแล้วถ้าไม่ฟรี)
     *   - ประเภท/ตัวเลือกที่ขอต้องยังเปิดอยู่ในนโยบาย ณ ตอนนี้
     */
    public function deployBlocker(FactoryToken $token): ?string
    {
        if ((int) $token->chain_id !== $this->policy->chainId()) {
            return "คำขอนี้ระบุเชน {$token->chain_id} — สร้างได้เฉพาะเชน ".$this->policy->chainId()
                .' (คำขอระบบเก่า ให้ปฏิเสธแล้วส่งใหม่)';
        }

        if (! $token->fee_quote_id) {
            return 'คำขอนี้ไม่มีการตรวจค่าธรรมเนียมบนเชน (คำขอระบบเก่า) — ให้ปฏิเสธแล้วให้ผู้สร้างส่งคำขอใหม่';
        }

        $quote = TokenFactoryFeeQuote::find($token->fee_quote_id);
        if (! $quote
            || $quote->used_at === null
            || (int) $quote->factory_token_id !== (int) $token->id
            || $quote->creator_address !== strtolower((string) $token->creator_address)) {
            return 'ใบเสนอราคาค่าธรรมเนียมของคำขอนี้ไม่ตรงกับคำขอ';
        }

        if (! $quote->isFree() && ! $token->fee_tx_hash) {
            return 'คำขอนี้ไม่มีธุรกรรมค่าธรรมเนียมที่ตรวจแล้ว';
        }

        $violations = $this->policy->stateViolations($token->token_type, $token->metadata['sub_options'] ?? []);
        if ($violations !== []) {
            return 'นโยบายตอนนี้ไม่อนุญาต: '.implode(', ', $violations);
        }

        return null;
    }

    /**
     * รีเซ็ตแถวที่ค้างสถานะ deploying โดยไม่มีธุรกรรม → failed (แล้วค่อย retry).
     *
     * เกิดได้จากคิวล่มหลังเปลี่ยนสถานะ, งานรุ่นเก่าที่อ่าน payload ไม่ได้, worker ตายก่อนเซ็น
     * ปลอดภัยเพราะ: ไม่มี tx_hash = ไม่เคยบันทึกว่าส่งอะไร · ต้องค้างเกิน STUCK_MINUTES (หลายเท่าของ timeout งาน)
     * · ตอน retry สคริปต์ค้นบนเชนก่อนเสมอว่าเคยสร้างเหรียญเจ้าของ+ชื่อ+สัญลักษณ์นี้แล้วหรือยัง
     */
    public function resetStuckDeployment(FactoryToken $token): FactoryToken
    {
        $cutoff = now()->subMinutes(self::STUCK_MINUTES);

        $stuck = fn ($query) => $query
            ->where('status', 'deploying')
            ->whereNull('tx_hash')
            ->where(function ($q) use ($cutoff) {
                $q->where('deploy_started_at', '<', $cutoff)
                    ->orWhere(fn ($q2) => $q2->whereNull('deploy_started_at')->where('updated_at', '<', $cutoff));
            });

        $row = $stuck(FactoryToken::whereKey($token->id))->first();
        if (! $row) {
            throw new \InvalidArgumentException(
                'รีเซ็ตได้เฉพาะแถวที่ค้างสถานะ deploying เกิน '.self::STUCK_MINUTES.' นาทีและยังไม่มีธุรกรรม'
            );
        }

        $metadata = array_merge($row->metadata ?? [], [
            'deploy_error' => 'ผู้ดูแลรีเซ็ตแถวที่ค้างสถานะ deploying (ไม่มีธุรกรรม)',
            'failed_at' => now()->toIso8601String(),
        ]);

        $updated = $stuck(FactoryToken::whereKey($token->id))->update([
            'status' => 'failed',
            'deploy_started_at' => null,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        if ($updated !== 1) {
            throw new \InvalidArgumentException('สถานะเปลี่ยนไประหว่างรีเซ็ต — ลองโหลดหน้าใหม่');
        }

        return $token->fresh();
    }

    /** สั่งตรวจผลธุรกรรมที่ส่งไปแล้วอีกรอบ (ไม่ส่งธุรกรรมใหม่) */
    public function recheckDeployment(FactoryToken $token): void
    {
        if ($token->status !== 'deploying' || ! $token->tx_hash) {
            throw new \InvalidArgumentException('ตรวจผลได้เฉพาะเหรียญที่กำลัง deploy และมีธุรกรรมแล้ว');
        }

        CheckFactoryDeployReceiptJob::dispatch($token->id);
    }

    /**
     * "Verified" เป็นการตัดสินของแอดมินเท่านั้น — ระบบไม่เคยตั้งให้เอง.
     */
    public function toggleVerified(FactoryToken $token): FactoryToken
    {
        if ($token->status !== 'deployed') {
            throw new \InvalidArgumentException('ยืนยัน (verify) ได้เฉพาะเหรียญที่ deploy แล้ว');
        }

        $token->update(['is_verified' => ! $token->is_verified]);

        return $token->fresh();
    }

    /**
     * ลิสต์/เลิกลิสต์ — มีผลกับหน้า Explore และ dex:sync (เปิด/ปิดคู่เทรดของเหรียญนี้).
     */
    public function toggleListed(FactoryToken $token): FactoryToken
    {
        if ($token->status !== 'deployed') {
            throw new \InvalidArgumentException('ลิสต์ได้เฉพาะเหรียญที่ deploy แล้ว');
        }

        $token->update(['is_listed' => ! $token->is_listed]);

        return $token->fresh();
    }

    // =========================================================================
    //  ความพร้อม
    // =========================================================================

    /**
     * ระบบพร้อมรับคำขอหรือไม่ (ภาพรวม — ใช้บนหน้าเว็บ).
     *
     * ต้องเช็กบนเชนจริง: มีโค้ดที่ที่อยู่แฟกทอรี (เชน TPIX เคย regenesis แล้วสัญญาหาย)
     * และ owner() ของแฟกทอรีต้องเป็นกระเป๋าที่เซิร์ฟเวอร์ใช้เซ็น (create* เป็น onlyOwner)
     */
    public function isFactoryReady(bool $withDetails = false): array
    {
        $creationEnabled = $this->policy->creationEnabled();
        $feeWallet = $this->policy->feeWallet();
        $needsWallet = $this->policy->anyFeeCharged();
        $walletConfigured = $feeWallet !== '';

        $deployer = $this->chain->deployerAddress();
        $deployerConfigured = $deployer !== null;

        // สคริปต์ deploy ใช้ V2 ถ้าตั้งไว้ ไม่งั้นตกไป V1 — ตรวจตัวเดียวกับที่จะใช้จริง
        $erc20Key = $this->contracts->address('token_factory_v2') !== null ? 'token_factory_v2' : 'token_factory_v1';
        $erc20Address = $this->contracts->address($erc20Key);
        $erc20Live = $erc20Address !== null && $this->contracts->isLive($erc20Key);

        $nftAddress = $this->contracts->address('nft_factory');
        $nftLive = $nftAddress !== null && $this->contracts->isLive('nft_factory');

        $erc20Owner = $erc20Live ? $this->chain->ownerOf($erc20Address) : null;
        $nftOwner = $nftLive ? $this->chain->ownerOf($nftAddress) : null;
        $erc20OwnerOk = $deployer !== null && $erc20Owner !== null && hash_equals($erc20Owner, $deployer);
        $nftOwnerOk = $deployer !== null && $nftOwner !== null && hash_equals($nftOwner, $deployer);

        $erc20Ready = $erc20Live && $erc20OwnerOk;
        $nftReady = $nftLive && $nftOwnerOk;

        $erc20Wanted = $this->policy->anyTypeEnabledFor('erc20');
        $nftWanted = $this->policy->anyTypeEnabledFor('nft');
        $chainReady = $erc20Live || $nftLive;
        $usable = ($erc20Wanted && $erc20Ready) || ($nftWanted && $nftReady);

        $ownerIssue = function (string $label, bool $live, ?string $owner, bool $ok, bool $wanted) use ($deployerConfigured): ?string {
            if (! $wanted || ! $live || ! $deployerConfigured || $ok) {
                return null;
            }

            return $owner === null
                ? "อ่าน owner() ของแฟกทอรี {$label} ไม่ได้"
                : "กระเป๋าที่เซิร์ฟเวอร์ใช้ส่งธุรกรรมไม่ใช่เจ้าของ (owner) ของแฟกทอรี {$label}";
        };

        $result = [
            'ready' => $creationEnabled
                && (! $needsWallet || $walletConfigured)
                && $deployerConfigured
                && $usable,
            'creation_enabled' => $creationEnabled,
            'fee_wallet_configured' => $walletConfigured,
            'fee_wallet_needed' => $needsWallet,

            // แยกให้ชัดว่าติดที่ "รอ deploy" หรือติดที่ตั้งค่าอย่างอื่น
            'awaiting_deploy' => ! $chainReady,
            'erc20_factory_ready' => $erc20Ready,
            'nft_factory_ready' => $nftReady,
            'deployer_configured' => $deployerConfigured,

            'issues' => array_values(array_filter([
                ! $creationEnabled ? 'ผู้ดูแลปิดระบบสร้างเหรียญไว้' : null,
                $needsWallet && ! $walletConfigured ? 'ยังไม่ได้ตั้งกระเป๋ารับค่าธรรมเนียม (ตั้งที่หน้า ตั้งค่า Token Factory)' : null,
                ! $erc20Wanted && ! $nftWanted ? 'ผู้ดูแลยังไม่ได้เปิดประเภทเหรียญใดเลย' : null,
                ! $chainReady ? 'ยังไม่ได้ติดตั้งสัญญาแฟกทอรีบนเชน' : null,
                $chainReady && $erc20Wanted && ! $erc20Live ? 'แฟกทอรี ERC-20 ยังไม่พร้อม (สร้างได้เฉพาะ NFT)' : null,
                $chainReady && $nftWanted && ! $nftLive ? 'แฟกทอรี NFT ยังไม่พร้อม (สร้างได้เฉพาะเหรียญ ERC-20)' : null,
                $ownerIssue('ERC-20', $erc20Live, $erc20Owner, $erc20OwnerOk, $erc20Wanted),
                $ownerIssue('NFT', $nftLive, $nftOwner, $nftOwnerOk, $nftWanted),
                ! $deployerConfigured ? 'ยังไม่ได้ตั้งกระเป๋าที่ใช้ส่งธุรกรรม (DEPLOYER_PRIVATE_KEY)' : null,
            ])),
        ];

        if ($withDetails) {
            $result['details'] = [
                'deployer_address' => $deployer,
                'erc20_factory' => ['key' => $erc20Key, 'address' => $erc20Address, 'live' => $erc20Live, 'owner' => $erc20Owner],
                'nft_factory' => ['address' => $nftAddress, 'live' => $nftLive, 'owner' => $nftOwner],
            ];
        }

        return $result;
    }

    /**
     * พร้อมสร้าง "ประเภทนี้" หรือไม่ — ERC-20 ต้องมีแฟกทอรี ERC-20, NFT ต้องมีแฟกทอรี NFT.
     *
     * เดิมถือว่าพร้อมถ้ามีแฟกทอรีตัวใดตัวหนึ่ง — มีแค่ NFT factory ก็รับคำขอ ERC-20 แล้วไปพังตอน deploy
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function readyFor(string $tokenType): array
    {
        $type = TokenFactoryPolicy::policyTypeFor($tokenType);
        if ($type === null) {
            return ['ok' => false, 'reason' => 'ไม่รู้จักประเภทเหรียญนี้'];
        }

        $state = $this->isFactoryReady();
        $factory = TokenFactoryPolicy::TYPES[$type]['factory'];

        $reason = match (true) {
            ! $state['creation_enabled'] => 'ผู้ดูแลปิดระบบสร้างเหรียญไว้',
            $state['fee_wallet_needed'] && ! $state['fee_wallet_configured'] => 'ยังไม่ได้ตั้งกระเป๋ารับค่าธรรมเนียม (ตั้งที่หน้า ตั้งค่า Token Factory)',
            ! $state['deployer_configured'] => 'ยังไม่ได้ตั้งกระเป๋าที่ใช้ส่งธุรกรรม',
            $factory === 'erc20' && ! $state['erc20_factory_ready'] => 'แฟกทอรี ERC-20 ยังไม่พร้อม (ยังไม่ได้ติดตั้ง หรือกระเป๋าของเซิร์ฟเวอร์ไม่ใช่เจ้าของ)',
            $factory === 'nft' && ! $state['nft_factory_ready'] => 'แฟกทอรี NFT ยังไม่พร้อม (ยังไม่ได้ติดตั้ง หรือกระเป๋าของเซิร์ฟเวอร์ไม่ใช่เจ้าของ)',
            default => null,
        };

        return ['ok' => $reason === null, 'reason' => $reason];
    }

    public function getStats(): array
    {
        return [
            'total' => FactoryToken::count(),
            'pending' => FactoryToken::where('status', 'pending')->count(),
            'deploying' => FactoryToken::where('status', 'deploying')->count(),
            'deployed' => FactoryToken::where('status', 'deployed')->count(),
            'failed' => FactoryToken::where('status', 'failed')->count(),
            'rejected' => FactoryToken::where('status', 'rejected')->count(),
            'verified' => FactoryToken::where('is_verified', true)->count(),
            'unique_creators' => FactoryToken::distinct('creator_address')->count('creator_address'),
        ];
    }

    /**
     * ค่าธรรมเนียมตามนโยบาย (ปลายทาง /calculate-fee) — แหล่งเดียวกับใบเสนอราคา.
     */
    public function calculateFee(array $options): array
    {
        $tokenType = (string) ($options['token_type'] ?? 'standard');
        $type = TokenFactoryPolicy::policyTypeFor($tokenType);

        if ($type === null) {
            return ['total' => 0, 'currency' => 'TPIX', 'breakdown' => [], 'is_free' => true];
        }

        $fee = $this->policy->fee($type, $this->policy->requestedOptions($tokenType, (array) ($options['sub_options'] ?? [])));

        return [
            'total' => $fee['total'],
            'currency' => 'TPIX',
            'breakdown' => $fee['breakdown'],
            'is_free' => $fee['total'] <= 0,
        ];
    }

    /**
     * ค่าที่หน้าเว็บสร้างเหรียญต้องใช้ — นโยบายที่ใช้จริง (ไม่มีรายชื่อกระเป๋าใน allowlist).
     */
    public function getFactoryConfig(): array
    {
        $public = $this->policy->publicConfig();

        return [
            'creation_enabled' => $this->policy->creationEnabled(),
            'fee_wallet' => $public['fee_wallet'],
            'nft_enabled' => $this->policy->typeEnabled('nft') || $this->policy->typeEnabled('nft_collection'),
            'max_supply_limit' => $public['types']['erc20']['max_supply'],
            'chain_id' => $public['chain_id'],
            'policy' => $public,
        ];
    }

    // =========================================================================
    //  ภายใน
    // =========================================================================

    /**
     * ด่านที่ต้องผ่านก่อนออกใบเสนอราคาและก่อนสร้าง.
     *
     * @throws TokenFactoryException
     */
    private function assertCanCreate(string $tokenType, string $creator): void
    {
        if (! $this->policy->creationEnabled()) {
            throw new TokenFactoryException('FACTORY_DISABLED', 'ขณะนี้ปิดระบบสร้างเหรียญชั่วคราว', 403);
        }

        $ready = $this->readyFor($tokenType);
        if (! $ready['ok']) {
            throw new TokenFactoryException('FACTORY_NOT_READY', 'ยังสร้างเหรียญประเภทนี้ไม่ได้: '.$ready['reason'], 503);
        }

        $limit = (int) $this->policy->effective()['daily_limit_per_wallet'];
        $recent = FactoryToken::where('creator_address', $creator)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recent >= $limit) {
            throw new TokenFactoryException('DAILY_LIMIT', "กระเป๋านี้สร้างเหรียญครบ {$limit} ครั้งใน 24 ชั่วโมงแล้ว", 429);
        }
    }

    /**
     * ใบเสนอราคาของกระเป๋านี้ — ระบุ purpose เมื่อใช้ชำระจริง (ใบค่ายืนยันซอร์สใช้สร้างเหรียญไม่ได้ และกลับกัน).
     */
    private function findQuote(string $quoteId, string $creator, ?string $purpose = null): TokenFactoryFeeQuote
    {
        $quote = TokenFactoryFeeQuote::find($quoteId);

        if (! $quote || $quote->creator_address !== strtolower($creator)
            || ($purpose !== null && $quote->purpose !== $purpose)) {
            throw new TokenFactoryException('QUOTE_NOT_FOUND', 'ไม่พบใบเสนอราคาของกระเป๋านี้ กรุณาขอใบเสนอราคาใหม่');
        }

        return $quote;
    }

    /**
     * กระเป๋าหนึ่งมีคำขอค้างได้ทีละคำขอ — ค้างอยู่ = ห้ามจ่ายค่าธรรมเนียมใบใหม่.
     *
     * @throws TokenFactoryException
     */
    private function assertNoRequestInProgress(string $creator): void
    {
        $open = FactoryToken::where('creator_address', $creator)
            ->whereIn('status', self::IN_PROGRESS_STATUSES)
            ->latest('id')
            ->first(['id', 'symbol', 'status']);

        if ($open) {
            $stage = self::IN_PROGRESS_STAGE_TH[$open->status];

            throw new TokenFactoryException(
                'REQUEST_IN_PROGRESS',
                "กระเป๋านี้มีคำขอสร้างเหรียญ {$open->symbol} อยู่ระหว่างดำเนินการ ({$stage}) — สร้างเหรียญใหม่ได้เมื่อคำขอนี้เสร็จหรือถูกปฏิเสธ ดูสถานะได้ที่แท็บ My Tokens",
                409
            );
        }
    }

    /** failed นับเป็นจองแล้ว — ทีมงานยังสั่งสร้างใหม่ได้ ถ้าปล่อยให้คนอื่นจองซ้ำ สองคำขอจะชนกันตอน retry. */
    private function symbolTaken(string $symbol): bool
    {
        return FactoryToken::where('chain_id', $this->policy->chainId())
            ->whereRaw('UPPER(symbol) = ?', [strtoupper($symbol)])
            ->whereIn('status', [...self::ACTIVE_STATUSES, 'failed'])
            ->exists();
    }

    private function isOwnUploadedLogo(string $url, string $creator): bool
    {
        $pattern = '#^/storage/(token-logos/'.preg_quote(strtolower($creator), '#').'/[A-Za-z0-9._-]+\.(png|jpe?g|webp))$#';

        if (! preg_match($pattern, $url, $match)) {
            return false;
        }

        return Storage::disk('public')->exists($match[1]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $state = $e->errorInfo[0] ?? $e->getCode();

        return (string) $state === '23000' || (string) $state === '23505'
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    /** float → ทศนิยมแบบ string (ไม่มี e-notation) สำหรับแปลงเป็น wei */
    private function decimalString(float $amount): string
    {
        $text = rtrim(rtrim(sprintf('%.6F', max(0, $amount)), '0'), '.');

        return $text === '' ? '0' : $text;
    }
}
