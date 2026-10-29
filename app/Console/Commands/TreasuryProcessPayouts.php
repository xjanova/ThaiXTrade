<?php

namespace App\Console\Commands;

use App\Models\SystemAlert;
use App\Models\TreasuryLedger;
use App\Models\TreasuryPayout;
use App\Services\HotWalletSigner;
use App\Services\TreasuryService;
use App\Support\TreasuryWallet;
use App\Support\Wei;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * เซ็น ส่ง และตามผลธุรกรรมจ่ายเงินจากกระเป๋าร้อน.
 *
 * รันทุกนาทีจาก scheduler — หน้าเว็บทำได้แค่เปลี่ยนสถานะเป็น broadcasting
 * เพราะ php-fpm ปิด proc_open การเซ็นจึงต้องเกิดฝั่ง CLI เท่านั้น
 *
 * ═══ ลำดับที่กันจ่ายซ้ำ ═══
 *
 * รายการหนึ่งมีสองระยะ แยกกันด้วย "มี tx_hash แล้วหรือยัง":
 *
 *   ยังไม่มี tx_hash → จองแถว → เซ็น → **บันทึก hash + raw ลงฐานข้อมูลก่อน** → ค่อยส่ง
 *   มี tx_hash แล้ว  → ไม่เซ็นใหม่เด็ดขาด ไปตามผลอย่างเดียว (ไม่เจอ = ส่ง raw เดิมซ้ำ)
 *
 * ที่ต้องบันทึกก่อนส่ง เพราะถ้าเครื่องดับหลังส่งแต่ก่อนบันทึก รอบหน้าจะไม่รู้ว่า
 * เคยส่งไปแล้ว แล้วเซ็นใหม่ด้วย nonce ใหม่ = เงินออกสองรอบ
 *
 * ═══ มี hash แล้ว ห้ามตั้ง failed จาก "ส่งไม่สำเร็จ" ═══
 * RPC อยู่หลัง Cloudflare — โหนดรับธุรกรรมแล้วแต่ Cloudflare ตอบ 52x หรือ Process ถูกตัดที่ 120 วิ
 * เกิดได้จริง เดิมตั้ง failed ทันที ทั้งที่เงินอาจออกไปแล้ว แล้วคืนเงินอัตโนมัติสร้างรอบ N+1
 * = จ่ายซ้ำ (ออดิท 2026-10-29 C5) ตอนนี้คง broadcasting ไว้ แล้วให้เชนตัดสิน:
 *   ใบเสร็จ 0x1 = สำเร็จ · ใบเสร็จ 0x0 = failed (เงินไม่ได้ออกจริง)
 *   ไม่มีใบเสร็จ + โหนดไม่รู้จัก + nonce ยังว่าง = ส่ง raw เดิมซ้ำ (hash เดิม เชนรับได้ครั้งเดียว)
 *   ไม่มีใบเสร็จ + nonce ถูกใช้ไปแล้ว = พิสูจน์ไม่ได้ว่าเงินไม่ได้ออก → แจ้งเตือนให้คนตรวจ ไม่ตัดสินเอง
 *
 * ═══ สองรอบทับกัน ═══
 * mutex ของ scheduler (withoutOverlapping) หมดอายุได้ และ cache:clear ตอน deploy ล้างมันทิ้ง
 * จึงกันสามชั้น: Cache::lock ของคำสั่ง · จองแถวแบบ atomic (claim_token) · บันทึก hash แบบ
 * compare-and-set เฉพาะรอบที่ถือ claim อยู่ — รอบที่แพ้ทิ้งธุรกรรมที่เซ็นไว้โดยไม่ส่ง
 *
 * ทำทีละรายการต่อรอบโดยตั้งใจ และไม่เซ็นรายการใหม่ระหว่างที่ยังมีธุรกรรมที่ nonce ยังว่างค้างอยู่
 * (nonce ถาม pending — ตัวที่หลุดจาก mempool จะถูกตัวใหม่ทับ nonce แล้วกลายเป็นรายการค้าง)
 */
class TreasuryProcessPayouts extends Command
{
    public const LOCK_KEY = 'treasury:payouts:lock';

    /** ครอบรอบที่ช้าที่สุด: เซ็น 120 วิ + ส่ง 120 วิ + ตามผลหลายรายการ */
    public const LOCK_SECONDS = 900;

    /** รอบที่จองแถวไว้แล้วดับกลางคัน — พ้นเวลานี้รอบอื่นจองต่อได้ (นานกว่าการเซ็นสูงสุด 120 วิหลายเท่า) */
    public const CLAIM_TTL_SECONDS = 600;

    public const ALERT_NODE = 'treasury';

    public const ALERT_STUCK = 'treasury_payout_stuck';

    /** ผลการตามหนึ่งรายการ */
    private const SETTLED = 'settled';      // จบแล้ว (confirmed / failed จากใบเสร็จ)

    private const MINED = 'mined';          // ลงบล็อกแล้ว รอ confirmation — nonce ถูกใช้แล้ว ไม่กันคิว

    private const IN_FLIGHT = 'in_flight';  // ยังลงบล็อกได้ หรืออ่านเชนไม่ได้ — กันคิวไว้ก่อน

    private const DROPPED = 'dropped';      // nonce ถูกใช้แล้วแต่ไม่เจอใบเสร็จ — ต้องให้คนตรวจ

    protected $signature = 'tpix:treasury-payouts
                            {--limit=1 : จำนวนรายการที่จะส่งต่อรอบ (แนะนำ 1)}
                            {--confirmations=1 : จำนวน confirmation ที่ถือว่าสำเร็จ}';

    protected $description = 'เซ็น ส่ง และตามผลรายการจ่ายเงินจากกระเป๋าร้อน';

    public function __construct(
        private TreasuryService $treasury,
        private HotWalletSigner $signer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->warn('มีรอบจ่ายอื่นทำงานอยู่ — ข้ามรอบนี้');

            return self::SUCCESS;
        }

        try {
            return $this->runOnce();
        } finally {
            $lock->release();
        }
    }

    private function runOnce(): int
    {
        // ── ระยะที่ 1: ตามผลของที่ส่งไปแล้ว ──────────────────────────────
        // ทำก่อนเสมอ (แม้สวิตช์ปิด) เพราะถ้ามีรายการค้างอยู่ควรรู้ผลก่อนจะส่งตัวถัดไป
        $inFlight = $this->trackSent(max(1, (int) $this->option('confirmations')));

        // ── ระยะที่ 2: ส่งตัวใหม่ ────────────────────────────────────────
        $readiness = $this->treasury->readiness();

        if (! $readiness['ready']) {
            $waiting = TreasuryPayout::where('status', TreasuryPayout::STATUS_BROADCASTING)
                ->whereNull('tx_hash')
                ->count();

            if ($waiting > 0) {
                $this->warn("มี {$waiting} รายการรอส่ง แต่ระบบยังไม่พร้อม — ยังขาด: "
                    .implode(' · ', array_column($readiness['blocking'], 'label')));
            }

            return self::SUCCESS;
        }

        if (! $this->signer->isAvailable()) {
            $this->error('เซ็นไม่ได้ในบริบทนี้ (ต้องรันจาก CLI)');

            return self::FAILURE;
        }

        if ($inFlight > 0) {
            $this->line("รอธุรกรรมก่อนหน้า {$inFlight} รายการลงบล็อกก่อน — รอบนี้ยังไม่เซ็นรายการใหม่");

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $sent = 0;

        for ($i = 0; $i < $limit; $i++) {
            $claim = $this->claimNext();

            if (! $claim) {
                break;
            }

            $outcome = $this->sendPayout($claim['payout'], $claim['token']);

            if ($outcome === 'sent') {
                $sent++;

                continue;
            }

            // ส่งไม่ยืนยันผล = nonce ของตัวนี้อาจยังว่าง เซ็นตัวถัดไปตอนนี้อาจทับ nonce เดียวกัน
            if ($outcome !== 'failed') {
                break;
            }
        }

        if ($sent > 0) {
            $this->info("ส่งขึ้นเชนแล้ว {$sent} รายการ");
        }

        return self::SUCCESS;
    }

    /**
     * จองรายการถัดไปแบบ atomic — คืน null เมื่อไม่มีงาน หรือมีรอบอื่นกำลังเซ็นอยู่.
     *
     * lockForUpdate ใน transaction ที่ไม่เขียนอะไร (ของเดิม) ปล่อยล็อกทันทีที่ commit
     * สองรอบจึงหยิบแถวเดียวกันได้ — ตอนนี้ต้องชนะ UPDATE แบบมีเงื่อนไขก่อนถึงจะได้เซ็น
     *
     * @return array{payout: TreasuryPayout, token: string}|null
     */
    private function claimNext(): ?array
    {
        $stale = now()->subSeconds(self::CLAIM_TTL_SECONDS);

        $waiting = fn () => TreasuryPayout::where('status', TreasuryPayout::STATUS_BROADCASTING)->whereNull('tx_hash');

        // มีรอบอื่นจองแถวไว้และยังไม่หมดเวลา = อีกรอบกำลังเซ็นอยู่ ถ้าเราไปเซ็นตัวถัดไปพร้อมกัน
        // ทั้งสองจะได้ nonce pending ตัวเดียวกัน แล้วตัวหนึ่งจะค้างตลอดไป
        if ($waiting()->whereNotNull('claimed_at')->where('claimed_at', '>', $stale)->exists()) {
            $this->warn('มีอีกรอบกำลังเซ็นรายการอยู่ — ข้ามรอบนี้');

            return null;
        }

        $candidate = $waiting()->orderBy('id')->first();

        if (! $candidate) {
            return null;
        }

        $token = Str::random(40);

        $won = TreasuryPayout::whereKey($candidate->id)
            ->where('status', TreasuryPayout::STATUS_BROADCASTING)
            ->whereNull('tx_hash')
            ->where(fn ($q) => $q->whereNull('claimed_at')->orWhere('claimed_at', '<=', $stale))
            ->update(['claimed_at' => now(), 'claim_token' => $token, 'updated_at' => now()]);

        if ($won !== 1) {
            return null; // อีกรอบแย่งไปก่อน
        }

        if ($candidate->claimed_at !== null) {
            Log::warning('treasury: จองรายการต่อจากรอบที่หมดเวลา (รอบก่อนอาจดับกลางคัน)', ['payout_id' => $candidate->id]);
        }

        return ['payout' => $candidate->fresh(), 'token' => $token];
    }

    /**
     * เซ็น บันทึก hash + raw แล้วส่ง — ตามลำดับนี้เท่านั้น.
     *
     * คืน sent | ambiguous (มี hash แล้วแต่ส่งไม่ยืนยันผล) | failed (ไม่มีธุรกรรม เงินไม่ออกแน่นอน)
     * | lost_claim (รอบอื่นแย่งแถวไประหว่างเซ็น — ทิ้งธุรกรรมที่เซ็นไว้ ไม่ส่ง)
     */
    private function sendPayout(TreasuryPayout $payout, string $claim): string
    {
        // ตรวจกฎอีกรอบก่อนปล่อยเงินจริง — วงเงินหรือ whitelist อาจถูกแก้
        // ระหว่างที่รายการรออยู่ในคิว
        $check = $this->treasury->validatePayout(
            $payout->to_address,
            (string) $payout->amount_wei,
            $payout->id,
            $payout->origin,
        );

        if (! $check['ok']) {
            $this->failUnsent($payout, $claim, 'ตกกฎตอนจะส่งจริง: '.implode(' / ', $check['errors']));
            $this->error("  #{$payout->id} ตกกฎ: ".implode(' / ', $check['errors']));

            return 'failed';
        }

        $this->line("  #{$payout->id} เซ็น ".Wei::format((string) $payout->amount_wei)." TPIX -> {$payout->to_address}");

        $signed = $this->signer->sign($payout->to_address, (string) $payout->amount_wei);

        // ยังไม่มีธุรกรรมใดออกไป (ขั้นเซ็นไม่ broadcast) — ตั้ง failed ได้อย่างปลอดภัย
        if (! ($signed['ok'] ?? false)) {
            $this->failUnsent($payout, $claim, 'เซ็นไม่สำเร็จ: '.($signed['error'] ?? 'ไม่ทราบสาเหตุ'));
            $this->error('  เซ็นไม่สำเร็จ: '.($signed['error'] ?? '?'));

            return 'failed';
        }

        $hash = strtolower((string) ($signed['txHash'] ?? ''));
        $raw = (string) ($signed['raw'] ?? '');
        $nonce = $signed['nonce'] ?? null;

        if (! preg_match('/^0x[0-9a-f]{64}$/', $hash) || ! str_starts_with($raw, '0x') || ! is_int($nonce)) {
            $this->failUnsent($payout, $claim, 'สคริปต์เซ็นคืนผลไม่ครบ (hash / raw / nonce)');
            $this->error('  สคริปต์เซ็นคืนผลไม่ครบ — ไม่ส่ง');

            return 'failed';
        }

        // ⚠️ บันทึก hash + raw ก่อนส่ง — ห้ามสลับลำดับเด็ดขาด
        // compare-and-set: เขียนได้เฉพาะรอบที่ยังถือ claim และแถวยังไม่มี hash
        // แพ้ = รอบอื่นแย่งไป หรือแอดมินเปลี่ยนสถานะระหว่างเซ็น → ทิ้งธุรกรรมนี้ (ยังไม่ส่ง เงินไม่ออก)
        $saved = TreasuryPayout::whereKey($payout->id)
            ->where('status', TreasuryPayout::STATUS_BROADCASTING)
            ->whereNull('tx_hash')
            ->where('claim_token', $claim)
            ->update([
                'tx_hash' => $hash,
                'raw_tx' => $raw,
                'nonce' => $nonce,
                'from_address' => strtolower((string) ($signed['address'] ?? TreasuryWallet::address())),
                'broadcast_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($saved !== 1) {
            Log::warning('treasury: รายการถูกรอบอื่นแย่งไประหว่างเซ็น — ทิ้งธุรกรรมที่เซ็นไว้ ไม่ส่ง', [
                'payout_id' => $payout->id,
                'discarded_tx' => $hash,
            ]);
            $this->warn("  #{$payout->id} ถูกรอบอื่นแย่งไประหว่างเซ็น — ไม่ส่ง");

            return 'lost_claim';
        }

        $result = $this->signer->send($raw);

        if (! ($result['ok'] ?? false)) {
            // ⚠️ ห้ามตั้ง failed — hash อยู่ในฐานข้อมูลแล้ว และโหนดอาจรับธุรกรรมไปแล้ว
            // (Cloudflare 52x / timeout หลังโหนดรับ) ระยะตามผลรอบหน้าจะตัดสินจากใบเสร็จและ nonce
            // แล้วส่ง raw เดิมซ้ำถ้าโหนดไม่รู้จักจริง
            $why = ($result['code'] ?? '') === 'nonce_used'
                ? 'nonce ถูกใช้แล้ว — รอตรวจใบเสร็จ'
                : 'ส่งขึ้นเชนไม่ยืนยันผล: '.($result['error'] ?? 'ไม่ทราบสาเหตุ');

            TreasuryPayout::whereKey($payout->id)->where('tx_hash', $hash)
                ->update(['last_error' => mb_substr($why, 0, 300), 'updated_at' => now()]);

            Log::warning('treasury: ส่งธุรกรรมจ่ายเงินไม่ยืนยันผล — คง broadcasting ไว้รอตรวจบนเชน', [
                'payout_id' => $payout->id,
                'tx_hash' => $hash,
                'nonce' => $nonce,
                'code' => $result['code'] ?? null,
            ]);
            $this->warn("  #{$payout->id} {$why}");

            return 'ambiguous';
        }

        Log::info('treasury: ส่งธุรกรรมจ่ายเงินขึ้นเชนแล้ว', [
            'payout_id' => $payout->id,
            'tx_hash' => $hash,
            'nonce' => $nonce,
            'already_known' => $result['alreadyKnown'] ?? false,
        ]);

        $this->info("  ส่งแล้ว: {$hash}");

        return 'sent';
    }

    /**
     * ตั้ง failed ให้รายการที่ยังไม่มีธุรกรรม — ผ่าน model เพื่อให้ผลไหลไปที่การคืนเงินของ token factory.
     */
    private function failUnsent(TreasuryPayout $payout, string $claim, string $reason): void
    {
        DB::transaction(function () use ($payout, $claim, $reason) {
            $row = TreasuryPayout::whereKey($payout->id)->lockForUpdate()->first();

            if (! $row
                || $row->status !== TreasuryPayout::STATUS_BROADCASTING
                || $row->tx_hash !== null
                || $row->claim_token !== $claim) {
                return;
            }

            $row->update([
                'status' => TreasuryPayout::STATUS_FAILED,
                'failure_reason' => $reason,
                'claimed_at' => null,
                'claim_token' => null,
            ]);
        });
    }

    /**
     * ตามผลของรายการที่ส่งไปแล้ว — คืนจำนวนรายการที่ยังลงบล็อกได้ (ใช้กันการเซ็นตัวใหม่).
     */
    private function trackSent(int $needConfirmations): int
    {
        $pending = TreasuryPayout::where('status', TreasuryPayout::STATUS_BROADCASTING)
            ->whereNotNull('tx_hash')
            ->orderBy('nonce')
            ->orderBy('id')
            ->get();

        $inFlight = 0;
        $stuck = [];
        $stuckAfter = max(1, (int) config('treasury.stuck_after_minutes', 10)) * 60;

        foreach ($pending as $payout) {
            $verdict = $this->trackOne($payout, $needConfirmations);

            if ($verdict === self::SETTLED) {
                continue;
            }

            if ($verdict === self::IN_FLIGHT) {
                $inFlight++;
            }

            $sentAt = $payout->broadcast_at ?? $payout->updated_at;
            $age = $sentAt ? (int) now()->diffInSeconds($sentAt, true) : 0;

            if ($verdict === self::DROPPED) {
                $stuck[$payout->id] = "#{$payout->id} nonce {$payout->nonce} ถูกธุรกรรมอื่นใช้ไปแล้วแต่ไม่พบใบเสร็จของ {$payout->tx_hash}";
            } elseif ($verdict === self::IN_FLIGHT && $age >= $stuckAfter) {
                $stuck[$payout->id] = "#{$payout->id} ส่งไป ".intdiv($age, 60).' นาทีแล้วยังไม่ลงบล็อก'
                    .($payout->nonce !== null ? " (nonce {$payout->nonce})" : '');
            }
        }

        $this->reportStuck($stuck);

        return $inFlight;
    }

    /** ตามผลหนึ่งรายการ — เชื่อเฉพาะสิ่งที่อ่านเจอบนเชน "อ่านไม่ได้" ไม่ใช่ "ไม่มี" */
    private function trackOne(TreasuryPayout $payout, int $needConfirmations): string
    {
        $hash = (string) $payout->tx_hash;

        $receipt = $this->rpc('eth_getTransactionReceipt', [$hash]);

        if (! $receipt['ok']) {
            return self::IN_FLIGHT; // RPC ล่ม — รอรอบหน้า ไม่เปลี่ยนสถานะ
        }

        if (is_array($receipt['result'])) {
            return $this->settle($payout, $hash, $receipt['result'], $needConfirmations);
        }

        // ยังไม่มีใบเสร็จ — โหนดรู้จักธุรกรรมนี้ไหม (อยู่ใน mempool)
        $tx = $this->rpc('eth_getTransactionByHash', [$hash]);

        if (! $tx['ok'] || is_array($tx['result'])) {
            return self::IN_FLIGHT;
        }

        // โหนดไม่รู้จักธุรกรรมนี้: ส่งไม่ถึง หรือหลุดจาก mempool
        if (! $payout->raw_tx || $payout->nonce === null || ! $payout->from_address) {
            // แถวแบบเก่าที่ไม่ได้เก็บ raw — ส่งซ้ำไม่ได้ พิสูจน์ nonce ไม่ได้ ให้คนตรวจเมื่อค้างนานเกิน
            return self::IN_FLIGHT;
        }

        $count = $this->rpc('eth_getTransactionCount', [$payout->from_address, 'latest']);

        if (! $count['ok'] || ! is_string($count['result'])) {
            return self::IN_FLIGHT;
        }

        $latestNonce = (int) Wei::hexToInt($count['result']);

        if ($latestNonce <= $payout->nonce) {
            // nonce ยังว่าง — ส่ง raw เดิมซ้ำ ไม่เซ็นใหม่ (hash เดิม nonce เดิม เชนรับได้ครั้งเดียว)
            $sent = $this->signer->send((string) $payout->raw_tx);

            $note = ($sent['ok'] ?? false)
                ? 'โหนดไม่รู้จักธุรกรรม — ส่ง raw เดิมซ้ำแล้ว'
                : 'ส่ง raw เดิมซ้ำไม่ผ่าน: '.($sent['error'] ?? 'ไม่ทราบสาเหตุ');

            $this->recordNote($payout, $hash, $note);
            $this->line("  #{$payout->id} {$note}");

            return self::IN_FLIGHT;
        }

        // nonce ถูกใช้ไปแล้ว — ถามใบเสร็จอีกรอบเผื่อธุรกรรมของเราเพิ่งลงบล็อกระหว่างสองคำถาม
        $again = $this->rpc('eth_getTransactionReceipt', [$hash]);

        if ($again['ok'] && is_array($again['result'])) {
            return $this->settle($payout, $hash, $again['result'], $needConfirmations);
        }

        // nonce ถูกใช้แต่ไม่เจอใบเสร็จของ hash เรา: ธุรกรรมอื่นใช้ nonce นี้ไป (เงินรายการนี้ไม่ออก)
        // หรือ RPC แค่ไม่มี index ของมัน (เงินออกไปแล้ว) — แยกไม่ออกจากตรงนี้ จึงห้ามตั้ง failed
        // ไม่งั้นการคืนเงิน/claim จะสร้างรายการใหม่แล้วจ่ายซ้ำ ให้คนตรวจกระเป๋าร้อนบน explorer
        $this->recordNote(
            $payout,
            $hash,
            "nonce {$payout->nonce} ถูกใช้แล้ว (nonce ล่าสุด {$latestNonce}) แต่ไม่พบใบเสร็จของธุรกรรมนี้ — "
            ."เปิดกระเป๋า {$payout->from_address} บน explorer หา nonce {$payout->nonce} ก่อนตัดสิน ห้ามสร้างรายการจ่ายใหม่"
        );

        Log::critical('treasury: nonce ของรายการจ่ายถูกใช้ไปแล้วแต่ไม่พบใบเสร็จ', [
            'payout_id' => $payout->id,
            'tx_hash' => $hash,
            'nonce' => $payout->nonce,
            'latest_nonce' => $latestNonce,
        ]);

        return self::DROPPED;
    }

    /** ได้ใบเสร็จแล้ว — 0x1 = สำเร็จเมื่อ confirmation ครบ · 0x0 = failed (เชนยืนยันว่าเงินไม่ออก) */
    private function settle(TreasuryPayout $payout, string $hash, array $receipt, int $needConfirmations): string
    {
        $status = strtolower((string) ($receipt['status'] ?? ''));

        // ใบเสร็จที่ไม่มีสถานะหรือเลขบล็อกชัดเจน ตัดสินไม่ได้ — ห้ามเดาว่า revert แล้วตั้ง failed
        if (! in_array($status, ['0x0', '0x1'], true) || empty($receipt['blockNumber'])) {
            return self::IN_FLIGHT;
        }

        $blockNumber = (int) Wei::hexToInt((string) $receipt['blockNumber']);
        $success = $status === '0x1';

        if (! $success) {
            $this->transition($payout, $hash, [
                'status' => TreasuryPayout::STATUS_FAILED,
                'block_number' => $blockNumber,
                'failure_reason' => 'เชนปฏิเสธธุรกรรม (status = 0x0)',
                'last_error' => null,
            ]);
            $this->error("  #{$payout->id} เชนปฏิเสธ");

            return self::SETTLED;
        }

        $head = $this->treasury->blockNumber();
        $confirmations = $head !== null ? max(0, $head - $blockNumber + 1) : 0;

        if ($confirmations < $needConfirmations) {
            $this->transition($payout, $hash, ['block_number' => $blockNumber, 'confirmations' => $confirmations]);

            return self::MINED;
        }

        $this->transition($payout, $hash, [
            'status' => TreasuryPayout::STATUS_CONFIRMED,
            'block_number' => $blockNumber,
            'confirmations' => $confirmations,
            'confirmed_at' => now(),
            'last_error' => null,
        ], function (TreasuryPayout $row) use ($blockNumber) {
            // บันทึกลงสมุด — ถ้า tpix:treasury-sync เก็บไปก่อนแล้วก็ไม่เป็นไร
            // unique (tx_hash, wallet_key, direction) กันซ้ำอยู่
            TreasuryLedger::firstOrCreate(
                [
                    'tx_hash' => $row->tx_hash,
                    'wallet_key' => 'hot_wallet',
                    'direction' => TreasuryLedger::DIRECTION_DEBIT,
                ],
                [
                    'wallet_address' => (string) ($row->from_address ?: config('treasury.hot_wallet.address')),
                    'amount_wei' => (string) $row->amount_wei,
                    'source' => 'payout',
                    'payout_id' => $row->id,
                    'block_number' => $blockNumber,
                    'note' => 'จ่ายจากคิว #'.$row->id,
                ],
            );
        });

        $this->info("  #{$payout->id} สำเร็จ (บล็อก {$blockNumber})");

        return self::SETTLED;
    }

    /**
     * เปลี่ยนแถวเฉพาะเมื่อยัง broadcasting ด้วย hash เดิม — ผ่าน model เพื่อให้ event updated ทำงาน
     * (ผลของคิวต้องไหลกลับไปที่การคืนค่าธรรมเนียมสร้างเหรียญ).
     */
    private function transition(TreasuryPayout $payout, string $hash, array $attributes, ?callable $after = null): bool
    {
        return DB::transaction(function () use ($payout, $hash, $attributes, $after) {
            $row = TreasuryPayout::whereKey($payout->id)->lockForUpdate()->first();

            if (! $row || $row->status !== TreasuryPayout::STATUS_BROADCASTING || $row->tx_hash !== $hash) {
                return false;
            }

            $row->update($attributes);

            if ($after) {
                $after($row);
            }

            return true;
        });
    }

    private function recordNote(TreasuryPayout $payout, string $hash, string $note): void
    {
        TreasuryPayout::whereKey($payout->id)
            ->where('status', TreasuryPayout::STATUS_BROADCASTING)
            ->where('tx_hash', $hash)
            ->update(['last_error' => mb_substr($note, 0, 500), 'updated_at' => now()]);
    }

    /**
     * รายการที่ค้าง → คาดแดงในหลังบ้าน + กระดิ่งแอดมิน · ไม่เหลือรายการค้าง → ปิดเอง.
     *
     * เดิมรายการ nonce_used หรือหลุดจาก mempool ค้าง broadcasting เงียบ ๆ ตลอดไป
     * ผู้ซื้อรอเหรียญไม่รู้จบโดยไม่มีใครรู้ (ออดิท 2026-10-29 L7)
     *
     * @param  array<int, string>  $stuck  payout id → คำอธิบาย
     */
    private function reportStuck(array $stuck): void
    {
        if ($stuck === []) {
            SystemAlert::resolveKeys(self::ALERT_NODE, [self::ALERT_STUCK]);

            return;
        }

        $message = 'คลัง TPIX: รายการจ่ายจากกระเป๋าร้อนค้าง '.count($stuck).' รายการ — '
            .implode(' · ', $stuck)
            .' — ห้ามสร้างรายการจ่ายใหม่แทนจนกว่าจะตรวจบน explorer ว่าเงินไม่ได้ออก';

        Log::critical('treasury: รายการจ่ายเงินค้าง', ['payouts' => array_keys($stuck)]);
        SystemAlert::raise(self::ALERT_NODE, self::ALERT_STUCK, 'critical', mb_substr($message, 0, 2000), [
            'payouts' => array_keys($stuck),
        ]);

        $this->error('  '.$message);
    }

    /**
     * เรียก JSON-RPC — ok=false = อ่านไม่ได้ (HTTP/JSON-RPC error) ซึ่ง **ไม่ใช่** "ไม่มี".
     *
     * ต้องแยกสองอย่างนี้ เพราะ "ไม่มีใบเสร็จ" กับ "ถามไม่ได้" นำไปสู่การตัดสินคนละทาง
     *
     * @return array{ok: bool, result: mixed}
     */
    private function rpc(string $method, array $params): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('treasury.rpc_user_agent'),
                'Content-Type' => 'application/json',
            ])
                ->timeout((int) config('treasury.rpc_timeout', 10))
                ->post((string) config('treasury.rpc_url'), [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $params,
                    'id' => 1,
                ]);

            if (! $response->successful()) {
                return ['ok' => false, 'result' => null];
            }

            $data = $response->json();

            if (! is_array($data) || isset($data['error']) || ! array_key_exists('result', $data)) {
                return ['ok' => false, 'result' => null];
            }

            return ['ok' => true, 'result' => $data['result']];
        } catch (\Throwable $e) {
            Log::warning('treasury: เรียก RPC ไม่สำเร็จ', ['method' => $method, 'error' => $e->getMessage()]);

            return ['ok' => false, 'result' => null];
        }
    }
}
