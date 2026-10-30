<?php

namespace App\Services\Trading;

use App\Models\Chain;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Models\TradingOrderTicket;
use App\Models\Transaction;
use App\Services\FeeCalculationService;
use App\Support\Wei;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * TPIX TRADE — บันทึกไม้สวอปบน BSC จากใบเสร็จบนเชน แล้วตรวจค่าธรรมเนียมและใบอนุญาตวางไม้ของไม้นั้น.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ เดิม /swap/execute บันทึก confirmed ตามตัวเลขของหน้าเว็บทันที (ออดิท 2026-10-28 C1/C2/L3)
 * ═══════════════════════════════════════════════════════════════════════════
 *   - hash มั่ว = ไม้ "สำเร็จ" · แจ้ง hash ของคนอื่นก่อน = แถวของเจ้าของจริงชน unique แล้วหาย
 *   - "เก็บค่าธรรมเนียมแล้ว" ตามคำบอกของหน้าเว็บ = รายได้ในหน้าการเงิน โดยไม่มีธุรกรรมให้ตรวจ
 *   - ใบอนุญาตวางไม้ไม่ถูกปิดตอนบันทึกไม้ และขั้นราคาของตั๋วไม่เคยเทียบกับมูลค่าไม้จริง
 *
 * ตอนนี้:
 *   1. แถวเกิดได้เฉพาะเมื่อเชนเห็นธุรกรรมนี้ว่าส่งโดยกระเป๋านี้ (ลงบล็อกแล้วหรือรออยู่ใน mempool)
 *      ยังไม่ลงบล็อก = แถว pending ใช้ตัวเลขของหน้าเว็บชั่วคราว ไม่นับเป็นรายได้
 *   2. ลงบล็อกแล้ว = ยอดทั้งสองขามาจาก log ของใบเสร็จ แทนตัวเลขของหน้าเว็บ
 *   3. ค่าธรรมเนียมนับเมื่ออ่านธุรกรรมโอนเข้ากระเป๋าค่าธรรมเนียมเจอบนเชนเท่านั้น (fee_tx_hash)
 *   4. ตั๋วถูกปิดในจังหวะเดียวกับที่ไม้ยืนยัน (claimForTrade) — ไม่ต้องพึ่งคำขอ consume ของหน้าเว็บ
 *
 * ⚠️ ข้อจำกัด: ค่าธรรมเนียม BSC ยังเป็นธุรกรรมแยกที่ผู้ใช้เลือกไม่จ่ายได้ จนกว่าจะมีสัญญา fee router
 *    ตัวนี้ทำให้บัญชีของเราตรงกับเชน (ไม่จ่าย = missing ไม่ใช่รายได้) ไม่ได้บังคับให้จ่าย
 *
 * Developed by Xman Studio.
 */
class BscSwapRecorder
{
    /** แถวใหม่ ไม้ยืนยันบนเชนแล้ว (201) */
    public const RECORDED = 'recorded';

    /** กระเป๋านี้บันทึกไม้นี้ไว้แล้ว (200) */
    public const EXISTS = 'exists';

    /** ยังไม่ลงบล็อก / เชนตอบไม่ได้ — ลองใหม่ได้ (202) */
    public const PENDING = 'pending';

    /** คำขออื่นกำลังบันทึก hash เดียวกันอยู่ — ลองใหม่ได้ (202) */
    public const BUSY = 'busy';

    /** hash นี้เป็นไม้ของกระเป๋าอื่น (409) */
    public const TAKEN = 'taken';

    public const FAILED = 'failed';

    public const WALLET_MISMATCH = 'wallet_mismatch';

    public const NOT_SWAP = 'not_swap';

    // ── สถานะค่าธรรมเนียมใน metadata.fee_status ──────────────────────────────

    /** ไม่ต้องเก็บ — จ่ายด้วยตั๋ว TPIX / เช่าบอทอยู่ / อัตรา 0 */
    public const FEE_NOT_REQUIRED = 'not_required';

    /** อ่านธุรกรรมโอนเจอบนเชน ยอดครบ */
    public const FEE_VERIFIED = 'verified';

    /** อ่านเจอ แต่ยอดขาด — นับเฉพาะยอดที่เข้าจริง */
    public const FEE_SHORT = 'short';

    /** มี hash แล้วแต่ยังไม่ลงบล็อก / เชนตอบไม่ได้ */
    public const FEE_PENDING = 'pending';

    /** ไม่ได้โอน (ปฏิเสธในกระเป๋า / ธุรกรรมล้ม / หายไปเกินเวลา) */
    public const FEE_MISSING = 'missing';

    /** หน้าเว็บรุ่นเก่าบอกว่าโอนแล้ว แต่ไม่ส่ง hash มาให้ตรวจ — ไม่นับเป็นรายได้ */
    public const FEE_UNVERIFIED = 'unverified';

    /** hash ที่ส่งมาไม่ใช่การโอนค่าธรรมเนียมของไม้นี้ (คนละผู้รับ / ใช้ซ้ำ / เป็น hash ของไม้เอง) */
    public const FEE_INVALID = 'invalid';

    /** ธุรกรรมของไม้ไม่โผล่บนเชนเลยนานเกินนี้ = หลุดไปแล้ว */
    public const DROP_AFTER_HOURS = 2;

    /** รอธุรกรรมค่าธรรมเนียมที่ไม่โผล่บนเชนนานเท่านี้ก่อนสรุปว่าไม่ได้จ่าย */
    public const FEE_WAIT_HOURS = 2;

    /**
     * ตัวคูณเผื่อราคาขยับระหว่างออกตั๋วกับไม้ลงเชน — มูลค่าไม้จริงเกินขั้นของตั๋วไม่เกิน 10% ไม่นับว่าตั๋วถูกไป.
     */
    private const TICKET_VALUE_SLACK = 1.1;

    /** สถานะค่าธรรมเนียมที่ยังเปลี่ยนได้ — ตัวตรวจย้อนหลังถามซ้ำ */
    private const FEE_OPEN = [self::FEE_PENDING, self::FEE_MISSING, self::FEE_UNVERIFIED];

    /** @var array<string, ?Token> แถวเหรียญที่ค้นแล้วในคำขอนี้ */
    private array $tokenCache = [];

    public function __construct(
        private readonly BscSwapVerifier $verifier,
        private readonly OrderTicketService $tickets,
        private readonly TradingFeeQuoteService $quotes,
        private readonly FeeCalculationService $fees,
    ) {}

    /**
     * บันทึกไม้ที่หน้าเว็บแจ้ง.
     *
     * @param  array  $input  คำขอที่ตรวจรูปแบบแล้ว (tx_hash, wallet_address, from_token, to_token, from_amount,
     *                        to_amount, fee_collected?, fee_tx_hash?, fee_ticket?, pair?, side?, price?)
     * @return array{status: string, transaction?: Transaction}
     */
    public function record(array $input, Chain $chain): array
    {
        $hash = strtolower($input['tx_hash']);

        try {
            // สองคำขอของ hash เดียวกันพร้อมกัน (คิวในเครื่อง + ปุ่มลองใหม่) ต้องได้แถวเดียว
            return Cache::lock("swap-record:{$hash}", 20)
                ->block(15, fn () => $this->recordLocked($input, $chain, $hash));
        } catch (LockTimeoutException) {
            return ['status' => self::BUSY];
        }
    }

    /**
     * ไล่แถวที่ยังไม่จบ — ไม้ที่รอลงบล็อก และค่าธรรมเนียมที่ยังไม่ได้ผล.
     *
     * @return array{checked: int, confirmed: int, failed: int}
     */
    public function reconcile(int $limit = 50): array
    {
        $chain = Chain::query()->where('chain_id', BscSwapVerifier::CHAIN_ID)->first();
        $counts = ['checked' => 0, 'confirmed' => 0, 'failed' => 0];
        if (! $chain) {
            return $counts;
        }

        $rows = Transaction::query()
            ->where('type', 'swap')
            ->where('chain_id', $chain->id)
            ->where('created_at', '>=', now()->subDays(2))
            ->where(function ($q) {
                $q->where('status', 'pending')
                    ->orWhere(function ($w) {
                        $w->where('status', 'confirmed')
                            ->where('metadata->fee_status', self::FEE_PENDING);
                    });
            })
            // ตัวที่เพิ่งถูกถามไปต่อท้ายแถว — แถวที่ RPC ตอบไม่ได้นานไม่บังแถวอื่น
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $counts['checked']++;

            try {
                $lock = Cache::lock("swap-record:{$row->tx_hash}", 20);
                if (! $lock->get()) {
                    continue;   // หน้าเว็บกำลังบันทึกแถวนี้อยู่
                }

                try {
                    $before = $row->status;
                    $this->settle($row);
                    $row->refresh();
                    if ($row->status !== $before) {
                        $counts[$row->status === 'confirmed' ? 'confirmed' : 'failed']++;
                    } else {
                        $row->touch();
                    }
                } finally {
                    $lock->release();
                }
            } catch (\Throwable $e) {
                Log::warning('ตรวจไม้ BSC ย้อนหลังไม่สำเร็จ', [
                    'tx_hash' => $row->tx_hash,
                    'exception' => get_class($e),
                ]);
            }
        }

        return $counts;
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    private function recordLocked(array $input, Chain $chain, string $hash): array
    {
        $wallet = strtolower($input['wallet_address']);

        $existing = Transaction::query()
            ->where('type', 'swap')
            ->where('chain_id', $chain->id)
            ->where('tx_hash', $hash)
            ->first();

        if ($existing) {
            // ⚠️ hash ของคนอื่น — ห้ามแตะแถวของเขา และห้ามตอบเหมือนบันทึกให้แล้ว
            if ($existing->wallet_address !== $wallet) {
                return ['status' => self::TAKEN];
            }

            $this->mergeClaims($existing, $input);
            $this->settle($existing);

            return ['status' => self::EXISTS, 'transaction' => $existing->fresh()];
        }

        $check = $this->verifier->swap($hash, $wallet, $input['from_token'], $input['to_token']);

        if (! in_array($check['status'], [BscSwapVerifier::CONFIRMED, BscSwapVerifier::PENDING], true)) {
            return ['status' => match ($check['status']) {
                BscSwapVerifier::FAILED => self::FAILED,
                BscSwapVerifier::WALLET_MISMATCH => self::WALLET_MISMATCH,
                BscSwapVerifier::WRONG_TARGET, BscSwapVerifier::NO_TRANSFER => self::NOT_SWAP,
                // ยังไม่มีใครเห็นธุรกรรม / RPC ล่ม — ไม่สร้างแถว หน้าเว็บเก็บไว้ในคิวแล้วส่งใหม่
                default => self::PENDING,
            }];
        }

        try {
            $row = $this->createPending($input, $chain, $hash, $wallet);
        } catch (QueryException $e) {
            // ชน unique (คำขอที่ไม่ผ่านล็อก) — แถวที่ชนะคือแถวจริง
            $row = Transaction::query()->where('type', 'swap')->where('chain_id', $chain->id)->where('tx_hash', $hash)->first();
            if (! $row) {
                throw $e;
            }

            return ['status' => $row->wallet_address === $wallet ? self::EXISTS : self::TAKEN, 'transaction' => $row];
        }

        $this->settle($row, $check);
        $row->refresh();

        return ['status' => $row->status === 'confirmed' ? self::RECORDED : self::PENDING, 'transaction' => $row];
    }

    private function createPending(array $input, Chain $chain, string $hash, string $wallet): Transaction
    {
        $ticket = $input['fee_ticket'] ?? null;
        $collector = strtolower(trim((string) SiteSetting::get('trading', 'fee_collector_wallet', '')));

        $row = new Transaction();
        // forceFill: fee_usd / fee_tx_hash ไม่อยู่ใน $fillable (ค่าจากเซิร์ฟเวอร์เท่านั้น)
        $row->forceFill([
            'type' => 'swap',
            'wallet_address' => $wallet,
            'chain_id' => $chain->id,
            'from_token' => strtolower($input['from_token']),
            'to_token' => strtolower($input['to_token']),
            // ตัวเลขของหน้าเว็บ — ใช้แสดงระหว่างรอลงบล็อกเท่านั้น ถูกแทนด้วยยอดบนเชนตอนยืนยัน
            'from_amount' => FeeCalculationService::decimal($input['from_amount']),
            'to_amount' => FeeCalculationService::decimal($input['to_amount']),
            'fee_amount' => 0,
            'fee_usd' => 0,
            'tx_hash' => $hash,
            'status' => 'pending',
            'metadata' => array_filter([
                'fee_status' => self::FEE_PENDING,
                'fee_collected' => false,
                'fee_collector' => $collector !== '' ? $collector : null,
                'fee_ticket' => $ticket,
                'pair' => isset($input['pair']) ? strtoupper(str_replace('-', '/', $input['pair'])) : null,
                'side' => $input['side'] ?? null,
                'price' => isset($input['price']) ? FeeCalculationService::decimal($input['price']) : null,
                'client' => $this->clientClaim($input),
            ], fn ($value) => $value !== null),
        ]);
        $row->save();

        $this->attachFeeTx($row, $input['fee_tx_hash'] ?? null);

        // ผูกตั๋วไว้ทันที — ระหว่างรอลงบล็อก ตั๋วต้องไม่ถูกคืนตามคำขอของหน้าเว็บ
        if ($ticket !== null) {
            try {
                $this->tickets->bind($ticket, $wallet, $hash);
            } catch (RuntimeException) {
                // ผูกกับธุรกรรมอื่น / ใช้ไปแล้ว / ไม่ใช่ของกระเป๋านี้ — ตัดสินอีกทีตอนไม้ยืนยัน
            }
        }

        return $row;
    }

    /** คำขอซ้ำของกระเป๋าเดิม — รับ hash ค่าธรรมเนียม / ตั๋ว ที่เพิ่งได้มาหลังส่งครั้งแรก */
    private function mergeClaims(Transaction $row, array $input): void
    {
        $meta = $row->metadata ?? [];
        $changed = false;

        if (empty($meta['fee_ticket']) && ! empty($input['fee_ticket']) && $row->status === 'pending') {
            $meta['fee_ticket'] = $input['fee_ticket'];
            $changed = true;
        }

        $claim = $this->clientClaim($input);
        if ($claim !== ($meta['client'] ?? null)) {
            $meta['client'] = $claim;
            $changed = true;
        }

        if ($changed) {
            $row->forceFill(['metadata' => $meta])->save();
        }

        if ($row->fee_tx_hash === null && ! empty($input['fee_tx_hash'])) {
            $this->attachFeeTx($row, $input['fee_tx_hash']);
            if ($row->status === 'confirmed' && in_array($row->metadata['fee_status'] ?? null, self::FEE_OPEN, true)) {
                $row->forceFill(['metadata' => [...($row->metadata ?? []), 'fee_status' => self::FEE_PENDING]])->save();
            }
        }
    }

    /**
     * ผูก hash ค่าธรรมเนียมกับไม้ — ธุรกรรมค่าธรรมเนียมหนึ่งรายการอ้างได้ไม้เดียว.
     */
    private function attachFeeTx(Transaction $row, ?string $feeTxHash): void
    {
        if ($feeTxHash === null || $feeTxHash === '' || $row->fee_tx_hash !== null) {
            return;
        }

        $feeTxHash = strtolower($feeTxHash);
        $reject = match (true) {
            $feeTxHash === strtolower((string) $row->tx_hash) => 'same_as_swap',
            Transaction::query()->where('fee_tx_hash', $feeTxHash)->exists() => 'reused',
            default => null,
        };

        if ($reject === null) {
            try {
                $row->forceFill(['fee_tx_hash' => $feeTxHash])->save();

                return;
            } catch (QueryException) {
                $row->refresh();
                $reject = 'reused';   // อีกแถวชิงไปพร้อมกันพอดี
            }
        }

        Log::warning('ปฏิเสธ hash ค่าธรรมเนียมของไม้ BSC', ['tx_hash' => $row->tx_hash, 'reason' => $reject]);
        $row->forceFill(['metadata' => [...($row->metadata ?? []), 'fee_tx_rejected' => $reject]])->save();
    }

    /**
     * ถามเชนแล้วเลื่อนสถานะของแถว — เรียกซ้ำได้.
     *
     * @param  array|null  $check  ผลของ verifier->swap ที่อ่านมาแล้ว (ไม่ต้องอ่านซ้ำ)
     */
    private function settle(Transaction $row, ?array $check = null): void
    {
        if ($row->status === 'failed') {
            return;
        }

        if ($row->status === 'confirmed') {
            if (in_array($row->metadata['fee_status'] ?? null, self::FEE_OPEN, true)) {
                $this->confirm($row, null);
            }

            return;
        }

        $check ??= $this->verifier->swap($row->tx_hash, $row->wallet_address, $row->from_token, $row->to_token);

        match ($check['status']) {
            BscSwapVerifier::CONFIRMED => $this->confirm($row, $check),
            BscSwapVerifier::FAILED,
            BscSwapVerifier::WALLET_MISMATCH,
            BscSwapVerifier::WRONG_TARGET,
            BscSwapVerifier::NO_TRANSFER => $this->fail($row, $check['status']),
            BscSwapVerifier::UNKNOWN => $row->created_at?->lt(now()->subHours(self::DROP_AFTER_HOURS))
                ? $this->fail($row, 'dropped')
                : null,
            default => null,   // PENDING / UNAVAILABLE — รอ
        };
    }

    /**
     * ไม้ลงบล็อกแล้ว (หรือยืนยันไปแล้วแต่ค่าธรรมเนียมยังไม่จบ) — ยอดจากเชน + ตั๋ว + ค่าธรรมเนียม.
     *
     * @param  array|null  $check  null = ยืนยันไปแล้ว ใช้ยอดบนเชนที่จดไว้ใน metadata
     */
    private function confirm(Transaction $row, ?array $check): void
    {
        $meta = $row->metadata ?? [];

        if ($check !== null) {
            $fromDecimals = $this->decimalsOf($row->from_token, (int) $row->chain_id);
            $toDecimals = $this->decimalsOf($row->to_token, (int) $row->chain_id);
            if ($fromDecimals === null || $toDecimals === null) {
                return;   // อ่าน decimals ไม่ได้ (RPC สะดุด) — ตัวตรวจย้อนหลังลองใหม่
            }
            $onchain = [
                'amount_in' => $check['amount_in'],
                'amount_out' => $check['amount_out'],
                'from_decimals' => $fromDecimals,
                'to_decimals' => $toDecimals,
            ];
        } else {
            $onchain = $meta['onchain'] ?? null;
            if (! is_array($onchain)) {
                return;
            }
        }

        // อ่านเชนให้จบก่อนเปิด DB transaction — ไม่ถือ row lock ระหว่างรอ RPC
        $collector = (string) ($meta['fee_collector'] ?? '');
        $feeCheck = ($row->fee_tx_hash !== null && $collector !== '')
            ? $this->verifier->transfer($row->fee_tx_hash, $row->wallet_address, $collector, $row->from_token)
            : null;

        DB::transaction(function () use ($row, $check, $onchain, $feeCheck) {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()->whereKey($row->id)->lockForUpdate()->first();
            $expected = $check !== null ? 'pending' : 'confirmed';
            if (! $locked || $locked->status !== $expected) {
                return;
            }

            $meta = $locked->metadata ?? [];
            $meta['onchain'] = $onchain;

            $fill = [
                'status' => 'confirmed',
                'to_amount' => Wei::format($onchain['amount_out'], $onchain['to_decimals']),
                'error_message' => null,
            ];
            if ($check !== null && ! empty($check['block_number'])) {
                $fill['block_number'] = $check['block_number'];
            }

            $required = $this->feeRequired($locked, $meta, $check !== null);
            $fill += $this->applyFee($locked, $meta, $required, $feeCheck);

            $fill['metadata'] = $this->withFillPrice($meta, $fill['from_amount'], $fill['to_amount']);
            $locked->forceFill($fill)->save();
        });

        $row->refresh();
    }

    /**
     * ไม้นี้ต้องจ่ายค่าธรรมเนียมเป็นเหรียญไหม — เช่าบอท / ตั๋ว TPIX ที่ใช้ได้ = ไม่ต้อง.
     *
     * $claim = ครั้งแรกที่ไม้ยืนยัน: ปิดตั๋วตอนนี้ · ครั้งถัดไป: ใช้ผลที่จดไว้
     */
    private function feeRequired(Transaction $row, array &$meta, bool $claim): bool
    {
        if (! $claim) {
            return ! in_array($meta['fee_model'] ?? 'transfer', ['tpix_ticket', 'waived'], true);
        }

        /*
         * เช่าบอทคลาวด์อยู่ = ค่าวางไม้รวมในค่าเช่าแล้ว (TradingFeeQuoteService::quote)
         * หน้าเว็บไม่หัก % จากเหรียญให้คนกลุ่มนี้ — ห้ามนับเป็น "ไม่ได้จ่าย"
         */
        if ($this->quotes->hasCloudBotRental($row->wallet_address)) {
            $meta['fee_model'] = 'waived';

            return false;
        }

        $uuid = $meta['fee_ticket'] ?? null;
        if ($uuid !== null) {
            [$ticket, $reason] = $this->claimTicket($uuid, $row);
            if ($ticket !== null) {
                $meta['fee_model'] = 'tpix_ticket';
                $this->checkTicketValue($ticket, $row, $meta);

                return false;
            }

            Log::warning('ไม้ BSC อ้างตั๋วที่ใช้ไม่ได้', ['ticket' => $uuid, 'tx_hash' => $row->tx_hash, 'reason' => $reason]);
            $meta['fee_ticket_invalid'] = $reason;
        }

        $meta['fee_model'] = 'transfer';

        return true;
    }

    /**
     * ปิดตั๋วด้วยไม้นี้.
     *
     * กด "Speed up" ในกระเป๋า = ไม้มาเป็น hash ใหม่ ขณะที่ตั๋วผูกกับ hash เดิม — ย้ายได้เฉพาะเมื่อ
     * เชนยืนยันว่าธุรกรรมเดิมไม่ได้ลง (ไม่งั้นตั๋วใบเดียวครอบได้สองไม้)
     *
     * @return array{0: ?TradingOrderTicket, 1: ?string}
     */
    private function claimTicket(string $uuid, Transaction $row): array
    {
        $ticket = TradingOrderTicket::query()
            ->where('uuid', $uuid)
            ->where('wallet_address', $row->wallet_address)
            ->first();

        if (! $ticket) {
            return [null, 'not_found'];
        }
        if ($ticket->chain_id !== null && (int) $ticket->chain_id !== BscSwapVerifier::CHAIN_ID) {
            return [null, 'wrong_chain'];
        }

        [$claimed, $reason] = $this->tickets->claimForTrade($uuid, $row->wallet_address, $row->tx_hash);

        if ($claimed === null && $reason === 'bound_elsewhere' && $ticket->order_tx_hash !== null
            && $ticket->status === TradingOrderTicket::STATUS_ISSUED) {
            $previous = $this->verifier->outcome($ticket->order_tx_hash, $row->wallet_address);
            $lost = in_array($previous, [
                BscSwapVerifier::FAILED,
                BscSwapVerifier::UNKNOWN,
                BscSwapVerifier::WALLET_MISMATCH,
                BscSwapVerifier::WRONG_TARGET,
            ], true);
            if ($lost) {
                [$claimed, $reason] = $this->tickets->claimForTrade($uuid, $row->wallet_address, $row->tx_hash, allowRebind: true);
            }
        }

        return [$claimed, $reason];
    }

    /**
     * ⚠️ ขั้นราคาของตั๋วมาจากมูลค่าไม้ที่หน้าเว็บบอกตอนขอตั๋ว (ออดิท L3) — เทียบกับไม้จริงที่นี่
     *    ตั๋ว 0.05 TPIX เคยครอบไม้ $50,000 ได้ ตอนนี้ตั๋วยังปิดตามปกติ (ไม้ลงเชนไปแล้ว ย้อนไม่ได้)
     *    แต่ติดธงให้แอดมินเห็นว่าจ่ายต่ำกว่าขั้นจริงเท่าไร.
     */
    private function checkTicketValue(TradingOrderTicket $ticket, Transaction $row, array &$meta): void
    {
        $valueUsd = $this->stableLegUsd($row, $meta);
        if ($valueUsd === null) {
            return;   // ไม่มีขาไหนเป็นสเตเบิล — ตีมูลค่าไม่ได้ ไม่เดา
        }

        $tier = $this->quotes->tierFor($valueUsd / self::TICKET_VALUE_SLACK);
        if ($tier === null || (float) $tier->fee_tpix <= (float) $ticket->fee_amount + 1e-9) {
            return;
        }

        $meta['fee_ticket_undervalued'] = [
            'order_value_usd' => round($valueUsd, 2),
            'required_tpix' => (float) $tier->fee_tpix,
            'paid_tpix' => (float) $ticket->fee_amount,
        ];
        Log::warning('ตั๋ววางไม้จ่ายต่ำกว่าขั้นของมูลค่าไม้จริง', ['ticket' => $ticket->uuid, 'tx_hash' => $row->tx_hash] + $meta['fee_ticket_undervalued']);
    }

    /**
     * ค่าธรรมเนียมที่อ่านได้จากเชน → ยอดที่บันทึก.
     *
     * ยอดขาเข้าที่บันทึก = ส่วนที่สวอป + ค่าธรรมเนียมที่ตรวจเจอจริง (ไม่ใช่ยอดที่หน้าเว็บบอก)
     *
     * @return array{from_amount: string, fee_amount: string, fee_usd: ?float}
     */
    private function applyFee(Transaction $row, array &$meta, bool $required, ?array $feeCheck): array
    {
        $swapIn = (string) $meta['onchain']['amount_in'];
        $fromDecimals = (int) $meta['onchain']['from_decimals'];

        // อัตราตอนไม้ยืนยันครั้งแรก — แอดมินเปลี่ยนอัตราทีหลังต้องไม่ทำให้ไม้เก่ากลายเป็น "จ่ายขาด"
        $rate = ! $required ? 0.0 : (float) ($meta['fee_rate']
            ?? $this->fees->calculateSwapFeeForTokens(1.0, $row->from_token, $row->to_token, (int) $row->chain_id)['fee_rate']);
        $ppm = (int) round($rate * 10000);

        [$status, $paid] = $ppm <= 0
            ? [self::FEE_NOT_REQUIRED, '0']
            : $this->feeStatus($row, $meta, $feeCheck, $swapIn, $ppm);

        $meta['fee_rate'] = $rate;
        $meta['fee_status'] = $status;
        $meta['fee_collected'] = in_array($status, [self::FEE_VERIFIED, self::FEE_NOT_REQUIRED], true);

        $fromAmount = Wei::format(bcadd($swapIn, $paid, 0), $fromDecimals);
        $feeAmount = Wei::format($paid, $fromDecimals);

        $feeUsd = 0.0;
        if ($paid !== '0') {
            [$fromSymbol, $toSymbol] = $this->symbols($row);
            $feeUsd = FeeCalculationService::feeUsdFor(
                $fromSymbol,
                $toSymbol,
                (float) $feeAmount,
                (float) $fromAmount,
                (float) Wei::format((string) $meta['onchain']['amount_out'], (int) $meta['onchain']['to_decimals']),
            );
        }

        return ['from_amount' => $fromAmount, 'fee_amount' => $feeAmount, 'fee_usd' => $feeUsd];
    }

    /**
     * @return array{0: string, 1: string} [fee_status, ยอดที่เข้ากระเป๋าค่าธรรมเนียมจริง (หน่วยเล็กสุด)]
     */
    private function feeStatus(Transaction $row, array $meta, ?array $feeCheck, string $swapIn, int $ppm): array
    {
        if ($row->fee_tx_hash === null) {
            if (isset($meta['fee_tx_rejected'])) {
                return [self::FEE_INVALID, '0'];
            }

            // หน้าเว็บรุ่นเก่าส่ง fee_collected:true โดยไม่มี hash — ตรวจไม่ได้ ไม่นับเป็นรายได้
            return [($meta['client']['fee_collected'] ?? null) === true ? self::FEE_UNVERIFIED : self::FEE_MISSING, '0'];
        }

        if ($feeCheck === null) {
            return [self::FEE_UNVERIFIED, '0'];   // ไม่ได้ตั้งกระเป๋าค่าธรรมเนียมไว้ตอนบันทึก
        }

        $timedOut = $row->created_at?->lt(now()->subHours(self::FEE_WAIT_HOURS)) ?? false;

        switch ($feeCheck['status']) {
            case BscSwapVerifier::CONFIRMED:
                $paid = (string) $feeCheck['amount'];
                // หน้าเว็บคิด fee = ceil(ยอดรวม × ppm / 1e6) จากยอดรวม = ส่วนที่สวอป + fee
                $expected = bcdiv(bcadd(bcmul(bcadd($swapIn, $paid, 0), (string) $ppm, 0), '999999', 0), '1000000', 0);
                $tolerance = bccomp(bcdiv($expected, '100', 0), '1', 0) > 0 ? bcdiv($expected, '100', 0) : '1';

                return [bccomp($paid, bcsub($expected, $tolerance, 0), 0) >= 0 ? self::FEE_VERIFIED : self::FEE_SHORT, $paid];

            case BscSwapVerifier::FAILED:
                return [self::FEE_MISSING, '0'];

            case BscSwapVerifier::WALLET_MISMATCH:
            case BscSwapVerifier::WRONG_TARGET:
            case BscSwapVerifier::NO_TRANSFER:
                return [self::FEE_INVALID, '0'];

            case BscSwapVerifier::UNKNOWN:
                return [$timedOut ? self::FEE_MISSING : self::FEE_PENDING, '0'];

            default:   // PENDING / UNAVAILABLE
                return [self::FEE_PENDING, '0'];
        }
    }

    private function fail(Transaction $row, string $reason): void
    {
        $row->forceFill([
            'status' => 'failed',
            'error_message' => $reason,
            'metadata' => [...($row->metadata ?? []), 'fee_collected' => false],
        ])->save();
    }

    /** ราคาที่ได้จริงจากยอดบนเชน (quote ต่อ base) — ป้ายบนกราฟวางตรงจุดที่เกิดจริง */
    private function withFillPrice(array $meta, string $fromAmount, string $toAmount): array
    {
        $side = $meta['side'] ?? null;
        if (! in_array($side, ['buy', 'sell'], true) || bccomp($fromAmount, '0', 18) <= 0 || bccomp($toAmount, '0', 18) <= 0) {
            return $meta;
        }

        $meta['price'] = FeeCalculationService::decimal($side === 'buy'
            ? bcdiv($fromAmount, $toAmount, 18)
            : bcdiv($toAmount, $fromAmount, 18));

        return $meta;
    }

    /** มูลค่าไม้เป็นดอลลาร์จากขาที่เป็นสเตเบิล — ไม่มีขาไหนเป็นสเตเบิล = null */
    private function stableLegUsd(Transaction $row, array $meta): ?float
    {
        [$fromSymbol, $toSymbol] = $this->symbols($row);
        $onchain = $meta['onchain'];

        if (in_array($fromSymbol, FeeCalculationService::USD_STABLES, true)) {
            return (float) Wei::format((string) $onchain['amount_in'], (int) $onchain['from_decimals']);
        }
        if (in_array($toSymbol, FeeCalculationService::USD_STABLES, true)) {
            return (float) Wei::format((string) $onchain['amount_out'], (int) $onchain['to_decimals']);
        }

        return null;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function symbols(Transaction $row): array
    {
        $symbol = function (string $address) use ($row): ?string {
            $token = $this->token($address, (int) $row->chain_id);

            return $token?->symbol ? strtoupper($token->symbol) : null;
        };

        return [$symbol($row->from_token), $symbol($row->to_token)];
    }

    /** decimals จากตารางเหรียญก่อน ไม่มีค่อยถามสัญญาบนเชน (จำไว้วันหนึ่ง) — null = ยังไม่รู้ */
    private function decimalsOf(string $token, int $chainPk): ?int
    {
        if ($this->verifier->isNative($token)) {
            return 18;
        }

        $row = $this->token($token, $chainPk);
        if ($row?->decimals !== null) {
            return (int) $row->decimals;
        }

        $key = 'bsc-token-decimals:'.strtolower($token);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return (int) $cached;
        }

        $decimals = $this->verifier->decimals($token);
        if ($decimals !== null) {
            Cache::put($key, $decimals, now()->addDay());
        }

        return $decimals;
    }

    /** แถวเหรียญของที่อยู่นี้ (เก็บได้ทั้ง checksum/พิมพ์เล็ก และ BNB ได้ทั้ง 0x000… / 0xEeee…) */
    private function token(string $address, int $chainPk): ?Token
    {
        $lower = strtolower($address);
        $key = $chainPk.':'.$lower;
        if (array_key_exists($key, $this->tokenCache)) {
            return $this->tokenCache[$key];
        }

        $native = ['0x0000000000000000000000000000000000000000', '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'];

        return $this->tokenCache[$key] = Token::query()
            ->where('chain_id', $chainPk)
            ->whereIn(DB::raw('LOWER(contract_address)'), in_array($lower, $native, true) ? $native : [$lower])
            ->orderByDesc('is_active')
            ->first(['id', 'symbol', 'decimals']);
    }

    /** สิ่งที่หน้าเว็บอ้าง — เก็บไว้เทียบเท่านั้น ไม่ใช้เป็นตัวเลขบัญชี */
    private function clientClaim(array $input): array
    {
        return array_filter([
            'from_amount' => isset($input['from_amount']) ? FeeCalculationService::decimal($input['from_amount']) : null,
            'to_amount' => isset($input['to_amount']) ? FeeCalculationService::decimal($input['to_amount']) : null,
            'fee_amount' => isset($input['fee_amount']) ? FeeCalculationService::decimal($input['fee_amount']) : null,
            'fee_collected' => isset($input['fee_collected']) ? (bool) $input['fee_collected'] : null,
        ], fn ($value) => $value !== null);
    }
}
