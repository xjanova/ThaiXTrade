<?php

namespace App\Services\TokenFactory;

use App\Jobs\VerifyFactoryTokenOnExplorerJob;
use App\Models\FactoryToken;
use App\Models\TokenFactoryFeeQuote;
use App\Services\BlockscoutVerifyService;
use App\Support\Wei;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ซื้อการยืนยันซอร์สโค้ดบน explorer ให้เหรียญที่ deploy แล้ว (ไม่ได้ติ๊กไว้ตอนสร้าง).
 *
 * ลำดับเดียวกับค่าสร้างเหรียญ: ใบเสนอราคา (ล็อกยอด ผูกกับเหรียญและกระเป๋าเจ้าของ) → เจ้าของโอน TPIX
 * → ตรวจธุรกรรมบนเชนด้วย TokenFactoryFeeVerifier → บันทึก → ส่งซอร์สให้ explorer
 *
 * explorer ปิดช่องยืนยันสาธารณะ (nginx รับเฉพาะคำขอที่มี explorer_verify_key)
 * ทางนี้จึงเป็นทางเดียวที่เหรียญจะได้ป้าย "Contract verified"
 *
 * Developed by Xman Studio
 */
class SourceVerifyPurchases
{
    public function __construct(
        private readonly TokenFactoryPolicy $policy,
        private readonly TokenFactoryFeeVerifier $feeVerifier,
        private readonly BlockscoutVerifyService $explorer,
    ) {}

    /**
     * ออกใบเสนอราคาค่ายืนยันซอร์สของเหรียญนี้.
     *
     * @throws TokenFactoryException
     */
    public function quote(FactoryToken $token, string $creator): TokenFactoryFeeQuote
    {
        $creator = strtolower($creator);
        $this->assertPurchasable($token, $creator);

        $fee = $this->policy->sourceVerifyFee();
        if ($fee === null) {
            throw new TokenFactoryException('SOURCE_VERIFY_DISABLED', 'ตอนนี้ปิดบริการยืนยันซอร์สโค้ดชั่วคราว', 403);
        }

        $feeWallet = $this->policy->feeWallet();
        if ($feeWallet === '') {
            throw new TokenFactoryException('FEE_WALLET_MISSING', 'ระบบยังไม่ได้ตั้งกระเป๋ารับค่าธรรมเนียม กรุณาติดต่อทีมงาน', 503);
        }

        $amount = rtrim(rtrim(number_format($fee, 6, '.', ''), '0'), '.');
        $label = TokenFactoryPolicy::OPTIONS['source_verify']['label_th'];

        return TokenFactoryFeeQuote::create([
            'creator_address' => $creator,
            'chain_id' => $this->policy->chainId(),
            'purpose' => TokenFactoryFeeQuote::PURPOSE_SOURCE_VERIFY,
            'token_type' => (string) $token->token_type,
            'params_hash' => hash('sha256', 'source_verify:'.$token->id),
            'amount' => $amount,
            'amount_wei' => Wei::toWei($amount),
            'fee_wallet' => $feeWallet,
            'breakdown' => [['key' => 'source_verify', 'label_th' => "{$label} ({$token->symbol})", 'amount' => (float) $amount]],
            'expires_at' => now()->addMinutes((int) $this->policy->effective()['quote_ttl_minutes']),
            // ผูกกับเหรียญตั้งแต่ออกใบ — ใบนี้ใช้จ่ายให้เหรียญอื่นไม่ได้
            'factory_token_id' => $token->id,
        ]);
    }

    /**
     * รับการชำระแล้วส่งซอร์สให้ explorer.
     *
     * @throws TokenFactoryException FEE_PENDING (202) เมื่อธุรกรรมยังยืนยันไม่ครบ — หน้าเว็บถามซ้ำได้
     */
    public function purchase(FactoryToken $token, string $quoteId, string $txHash, string $creator): FactoryToken
    {
        $creator = strtolower($creator);
        $txHash = strtolower(trim($txHash));
        $this->assertPurchasable($token, $creator);

        $quote = TokenFactoryFeeQuote::find($quoteId);
        if (! $quote
            || $quote->creator_address !== $creator
            || $quote->purpose !== TokenFactoryFeeQuote::PURPOSE_SOURCE_VERIFY
            || (int) $quote->factory_token_id !== (int) $token->id) {
            throw new TokenFactoryException('QUOTE_NOT_FOUND', 'ไม่พบใบเสนอราคาค่ายืนยันซอร์สของเหรียญนี้ กรุณาขอใหม่');
        }

        if ($quote->used_at !== null) {
            throw new TokenFactoryException('QUOTE_USED', 'ใบเสนอราคานี้ถูกใช้ไปแล้ว', 409);
        }

        $verification = $this->feeVerifier->verify($quote, $txHash, $creator);

        try {
            // ล็อกที่ hash — ธุรกรรมเดียวห้ามจ่ายทั้งค่าสร้างเหรียญและค่ายืนยันซอร์สแม้ส่งมาพร้อมกัน
            TokenFactoryFeeVerifier::claimTx($txHash, fn () => DB::transaction(function () use ($quote, $token, $txHash, $verification) {
                $locked = TokenFactoryFeeQuote::whereKey($quote->id)->lockForUpdate()->first();
                if (! $locked || $locked->used_at !== null) {
                    throw new TokenFactoryException('QUOTE_USED', 'ใบเสนอราคานี้ถูกใช้ไปแล้ว', 409);
                }

                $row = FactoryToken::whereKey($token->id)->lockForUpdate()->first();
                if ($row->sourceVerifyPaid()) {
                    throw new TokenFactoryException('SOURCE_VERIFY_ALREADY_PAID', 'จ่ายค่ายืนยันซอร์สของเหรียญนี้ไปแล้ว', 409);
                }

                $metadata = $row->metadata ?? [];
                $metadata['source_verify'] = [
                    'paid' => true,
                    'via' => 'purchase',
                    'fee_tx_hash' => $txHash,
                    'fee_quote_id' => $locked->id,
                    'fee_value_wei' => $verification['value_wei'],
                    'paid_at' => now()->toIso8601String(),
                ];
                // ผลเดิม (เช่น skipped ตอนยังไม่จ่าย) ห้ามค้าง — งานยืนยันจะข้ามแถวที่มีผลแล้ว
                unset($metadata['explorer_verification']);

                $row->update(['source_verify_tx_hash' => $txHash, 'metadata' => $metadata]);
                $locked->update(['used_at' => now()]);
            }));
        } catch (QueryException $e) {
            if (TokenFactoryFeeVerifier::txAlreadyUsed($txHash)) {
                throw new TokenFactoryException('FEE_TX_USED', 'ธุรกรรมนี้ถูกใช้ชำระค่าธรรมเนียมไปแล้ว', 409);
            }

            throw $e;
        }

        Log::info("Token {$token->symbol} source verification purchased", ['token_id' => $token->id, 'fee_tx' => $txHash]);

        VerifyFactoryTokenOnExplorerJob::dispatch($token->id);

        return $token->fresh();
    }

    /** @throws TokenFactoryException */
    private function assertPurchasable(FactoryToken $token, string $creator): void
    {
        if ($token->creator_address !== $creator) {
            throw new TokenFactoryException('TOKEN_NOT_FOUND', 'ไม่พบเหรียญนี้ในกระเป๋าของคุณ', 404);
        }

        if ($token->status !== 'deployed' || ! $token->contract_address) {
            throw new TokenFactoryException('SOURCE_VERIFY_NOT_DEPLOYED', 'ยืนยันซอร์สได้หลังสร้างเหรียญบนเชนเสร็จแล้วเท่านั้น', 409);
        }

        if ($token->sourceVerifyPaid()) {
            throw new TokenFactoryException('SOURCE_VERIFY_ALREADY_PAID', 'จ่ายค่ายืนยันซอร์สของเหรียญนี้ไปแล้ว', 409);
        }

        if (($token->metadata['explorer_verification']['status'] ?? null) === 'verified') {
            throw new TokenFactoryException('SOURCE_VERIFY_ALREADY_VERIFIED', 'ซอร์สโค้ดของเหรียญนี้ยืนยันบน explorer แล้ว', 409);
        }

        if (! $this->explorer->supports($token)) {
            throw new TokenFactoryException('SOURCE_VERIFY_UNSUPPORTED', 'ยังยืนยันซอร์สของเหรียญชนิดนี้ไม่ได้ กรุณาติดต่อทีมงาน');
        }
    }
}
