<?php

namespace App\Services;

use App\Models\SalePhase;
use App\Models\SiteSetting;
use App\Models\TokenSale;
use App\Support\TreasuryWallet;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SaleLaunchService — ตารางรอบขายเริ่มนับจาก "วันที่พร้อมขายจริง".
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * โจทย์ที่มาของไฟล์นี้
 * ═══════════════════════════════════════════════════════════════════════════
 * เดิมวันของแต่ละเฟสถูกตั้งตายตัวไว้ตั้งแต่ตอน seed แล้วระบบไม่เคยพร้อมขายจริง
 * ตามวันนั้น — เฟสแรกหมดอายุไปเงียบ ๆ โดยยังไม่เคยขายได้เลยสักบาท
 *
 * เจ้าของกำหนดใหม่: **พร้อมขายวันไหน เฟสแรกเริ่มนับวันนั้น**
 * ไฟล์นี้จึงมีสองหน้าที่ที่แยกกันชัด ๆ
 *
 *   1. readiness()  ตอบว่า "ตอนนี้พร้อมขายจริงหรือยัง" — ไม่ใช่ดูแค่คอนฟิก
 *                   แต่ถามของจริง (ยอดในกระเป๋าจ่ายเหรียญบนเชน) ด้วย
 *   2. launch()     ปักหมุดวันเปิด แล้วไล่ตั้งวันของทุกเฟสต่อกันเป็นทอด ๆ
 *
 * ⚠️ ตรรกะการตั้งตารางอยู่ที่นี่ที่เดียว ทั้ง `sale:launch`, `sale:reschedule`
 *    และปุ่มบนหน้าแอดมินเรียกตัวเดียวกันหมด — ห้ามเขียนซ้ำที่อื่น
 *    (กับดักเดียวกับที่เคยทำให้ด่าน "เฟสเปิดอยู่ไหม" แตกกันคนละที่)
 *
 * Developed by Xman Studio.
 */
class SaleLaunchService
{
    /** ความยาวเฟสเริ่มต้นเมื่อแถวนั้นยังไม่มี duration_days */
    public const DEFAULT_DURATION_DAYS = 60;

    /** กลุ่ม/คีย์ของสวิตช์ "เปิดขายอัตโนมัติเมื่อระบบพร้อม" ใน site_settings */
    public const ARM_GROUP = 'sale';

    public const ARM_KEY = 'auto_launch';

    private const USER_AGENT = 'TPIX-TRADE-SaleLaunch/1.0 (+https://tpix.online)';

    public function __construct(
        private readonly StripePaymentService $stripe,
        private readonly BankTransferSaleService $bank,
    ) {}

    // =========================================================================
    // 1) พร้อมขายจริงหรือยัง
    // =========================================================================

    /**
     * รายการตรวจความพร้อมก่อนเปิดขาย.
     *
     * ทุกข้อตอบจากของจริง ไม่ใช่จากคอนฟิกอย่างเดียว — ยอดเหรียญในกระเป๋าจ่าย
     * ถามเชนสด เพราะ "ตั้งที่อยู่กระเป๋าไว้แล้ว" ไม่ได้แปลว่ามีเหรียญให้จ่าย
     *
     * @return array{ready:bool, checks:array<int,array<string,mixed>>, blocking:array<int,string>}
     */
    public function readiness(?TokenSale $sale = null): array
    {
        $sale ??= $this->targetSale();
        $checks = [];

        // ── รอบขายและเฟส ────────────────────────────────────────────────
        $phases = $sale?->phases()->orderBy('phase_order')->get();
        $sellable = $phases?->first(fn (SalePhase $p) => (float) $p->allocation > (float) $p->sold);

        $checks[] = $this->check(
            'sale',
            'มีรอบขายพร้อมเฟสที่ยังขายได้',
            $sale !== null && $sellable !== null,
            true,
            $sale === null
                ? 'ยังไม่มีรอบขายที่เปิดอยู่'
                : ($sellable === null ? 'ทุกเฟสขายหมดโควตาแล้ว' : "เฟสแรกที่ขายได้: {$sellable->name}"),
            'สร้างรอบขาย/เฟสที่ /admin/token-sales'
        );

        // ── ช่องทางรับเงิน (ต้องมีอย่างน้อยหนึ่งทาง) ────────────────────
        $stripeOn = $this->stripe->isEnabled();
        $bankOn = $this->bank->isConfigured();

        $checks[] = $this->check(
            'payment',
            'มีช่องทางรับเงินอย่างน้อย 1 ทาง',
            $stripeOn || $bankOn,
            true,
            'บัตรเครดิต: '.($stripeOn ? 'เปิด' : 'ปิด').' · โอนเงิน: '.($bankOn ? 'เปิด' : 'ยังไม่ได้ใส่เลขบัญชี'),
            'ใส่คีย์ Stripe หรือเลขบัญชีรับโอนที่ /admin/settings'
        );

        // ── กระเป๋าที่ใช้จ่ายเหรียญออก ──────────────────────────────────
        $walletSet = TreasuryWallet::isConfigured();
        $wallet = TreasuryWallet::address();

        $checks[] = $this->check(
            'wallet',
            'ตั้งกระเป๋าจ่ายเหรียญแล้ว',
            $walletSet,
            true,
            $walletSet ? $wallet : 'ยังไม่ได้ตั้ง',
            'ตั้งกระเป๋ารับค่าบริการที่ /admin/settings (ใบเดียวกับใบที่จ่ายเหรียญ)'
        );

        // ── สวิตช์จ่ายเหรียญ ────────────────────────────────────────────
        $payouts = (bool) config('treasury.payouts_enabled', false);

        $checks[] = $this->check(
            'payouts',
            'เปิดระบบจ่ายเหรียญแล้ว',
            $payouts,
            true,
            $payouts ? 'เปิดอยู่' : 'ปิดอยู่ — ลูกค้าจ่ายเงินแล้วเหรียญจะค้างในคิว',
            'ตั้ง TPIX_TREASURY_PAYOUTS_ENABLED=true ใน .env แล้ว php artisan config:cache'
        );

        // ── มีเหรียญให้จ่ายจริงไหม ──────────────────────────────────────
        $checks[] = $this->fundsCheck($walletSet ? $wallet : null, $sellable);

        $blocking = collect($checks)
            ->filter(fn (array $c) => $c['blocking'] && ! $c['ok'])
            ->pluck('label')
            ->values()
            ->all();

        return [
            'ready' => $blocking === [],
            'checks' => $checks,
            'blocking' => $blocking,
        ];
    }

    public function isReady(?TokenSale $sale = null): bool
    {
        return $this->readiness($sale)['ready'];
    }

    /**
     * ตรวจว่ากระเป๋าจ่ายเหรียญมี TPIX พอจ่ายจริงไหม.
     *
     * บล็อกเฉพาะกรณี "ศูนย์" หรือ "ถามเชนไม่ได้" เท่านั้น
     * ส่วน "ไม่พอถ้าขายหมดเฟส" เป็นแค่คำเตือน — ไม่ควรบังคับให้ต้องมีครบ
     * ตั้งแต่วันแรก เพราะเงินที่ขายได้ระหว่างทางเอามาเติมได้
     *
     * ถามเชนไม่ได้ = บล็อก โดยตั้งใจ: ยอมไม่เปิดขายดีกว่าเปิดขายทั้งที่ไม่รู้ว่ามีของ
     *
     * @return array<string,mixed>
     */
    private function fundsCheck(?string $wallet, ?SalePhase $firstPhase): array
    {
        if ($wallet === null) {
            return $this->check(
                'funds',
                'กระเป๋าจ่ายเหรียญมี TPIX',
                false,
                true,
                'ยังไม่ได้ตั้งกระเป๋า จึงยังตรวจยอดไม่ได้',
                'ตั้งกระเป๋าก่อน'
            );
        }

        $balance = $this->balanceTpix($wallet);

        if ($balance === null) {
            return $this->check(
                'funds',
                'กระเป๋าจ่ายเหรียญมี TPIX',
                false,
                true,
                'ถามยอดจากเชนไม่สำเร็จ — ยังยืนยันไม่ได้ว่ามีเหรียญให้จ่าย',
                'ตรวจว่า RPC ของเชน TPIX เข้าถึงได้จากเซิร์ฟเวอร์'
            );
        }

        // เพดานที่ต้องจ่ายทันทีถ้าเฟสนี้ขายหมด (TGE ปลดล็อกทันทีที่ยืนยันการชำระเงิน)
        $tgeNeed = $firstPhase !== null
            ? (float) $firstPhase->allocation * ((float) $firstPhase->vesting_tge_percent / 100)
            : 0.0;

        $detail = number_format($balance, 2).' TPIX ในกระเป๋า';
        if ($tgeNeed > 0) {
            $detail .= ' · ถ้าเฟสแรกขายหมดต้องจ่ายทันที '.number_format($tgeNeed, 2).' TPIX';
            if ($balance < $tgeNeed) {
                $detail .= ' (ยังไม่พอ — เติมได้ระหว่างทาง)';
            }
        }

        return $this->check(
            'funds',
            'กระเป๋าจ่ายเหรียญมี TPIX',
            $balance > 0,
            true,
            $detail,
            'โอน TPIX จากคลังเข้ากระเป๋าจ่ายเหรียญ'
        );
    }

    /** ยอด TPIX ของกระเป๋า — null เมื่อถามเชนไม่ได้ (ห้ามคืน 0 เพราะแยกกันไม่ออก) */
    private function balanceTpix(string $address): ?float
    {
        try {
            $response = Http::timeout(8)
                // rpc.tpix.online มี Cloudflare bot rule ตอบ 403 ให้ client ที่ไม่มี User-Agent
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->asJson()
                ->post(config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online'), [
                    'jsonrpc' => '2.0',
                    'method' => 'eth_getBalance',
                    'params' => [$address, 'latest'],
                    'id' => 1,
                ]);

            $hex = $response->json('result');
            if (! is_string($hex) || ! str_starts_with($hex, '0x')) {
                return null;
            }

            // ยอดเป็น wei 18 หลัก — ผ่าน float ตรง ๆ จะเสียความละเอียด
            // ใช้ bcmath หารก่อน (เซิร์ฟเวอร์มี bcmath แน่นอน ไม่มี gmp)
            $wei = $this->hexToDecimalString($hex);

            return (float) bcdiv($wei, bcpow('10', '18'), 6);
        } catch (\Throwable $e) {
            Log::warning('SaleLaunchService: ถามยอดกระเป๋าจ่ายเหรียญไม่สำเร็จ', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** hex → สตริงเลขฐานสิบ ผ่าน bcmath (ไม่พึ่ง gmp ซึ่งเซิร์ฟเวอร์ไม่มี) */
    private function hexToDecimalString(string $hex): string
    {
        $hex = strtolower(ltrim(substr($hex, 2), '0'));
        if ($hex === '') {
            return '0';
        }

        $dec = '0';
        foreach (str_split($hex) as $char) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($char));
        }

        return $dec;
    }

    /**
     * @return array<string,mixed>
     */
    private function check(string $key, string $label, bool $ok, bool $blocking, string $detail, string $fix): array
    {
        return compact('key', 'label', 'ok', 'blocking', 'detail', 'fix');
    }

    // =========================================================================
    // 2) ตั้งตารางจากหมุดวันเปิด
    // =========================================================================

    /**
     * รอบขายที่จะจัดการ — รอบที่ active อยู่ (หรือระบุ id เอง).
     */
    public function targetSale(?int $saleId = null): ?TokenSale
    {
        return $saleId !== null
            ? TokenSale::find($saleId)
            : TokenSale::where('status', 'active')->orderBy('id')->first();
    }

    /**
     * แผนตารางใหม่ — ยังไม่บันทึก.
     *
     * เฟสที่ขายไปแล้ว (sold > 0) จะไม่ถูกแตะ เพราะการเลื่อนวันของรอบที่มีคนซื้อ
     * ไปแล้วเท่ากับเปลี่ยนเงื่อนไขย้อนหลังกับลูกค้าที่จ่ายเงินมาแล้ว
     *
     * @param  int|null  $flatDays  บังคับให้ทุกเฟสยาวเท่ากัน (null = ใช้ duration_days ของแต่ละเฟส)
     * @return array{sale:TokenSale, rows:array<int,array<string,mixed>>, skipped:array<int,array<string,mixed>>, ends_at:?Carbon}
     */
    public function plan(TokenSale $sale, CarbonInterface $start, ?int $flatDays = null): array
    {
        $phases = $sale->phases()->orderBy('phase_order')->get();

        $rows = [];
        $skipped = [];
        $cursor = Carbon::parse($start);

        /*
         * เฟสที่ขายไปแล้วยังกินช่วงเวลาของมันอยู่ ถ้าเริ่มเฟสใหม่ทับช่วงนั้น
         * จะมีสองเฟสเปิดพร้อมกัน แล้ว getActivePhase() ที่ใช้ ->first() จะหยิบมา
         * แบบทายไม่ได้ = ลูกค้าอาจได้ราคาที่เราไม่ได้ตั้งใจขาย
         */
        foreach ($phases as $phase) {
            if ((float) $phase->sold > 0 && $phase->ends_at !== null && $phase->ends_at->gt($cursor)) {
                $cursor = $phase->ends_at->copy();
            }
        }

        $isFirstMovable = true;

        foreach ($phases as $phase) {
            if ((float) $phase->sold > 0) {
                $skipped[] = [
                    'phase' => $phase,
                    'reason' => 'ขายไปแล้ว '.number_format((float) $phase->sold, 2).' TPIX',
                ];

                continue;
            }

            $days = $flatDays ?? (int) ($phase->duration_days ?: self::DEFAULT_DURATION_DAYS);
            $days = max(1, $days);

            $startsAt = $cursor->copy();
            $endsAt = $cursor->copy()->addDays($days);

            $rows[] = [
                'phase' => $phase,
                'days' => $days,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                /*
                 * เปิดแค่เฟสแรกเท่านั้น ที่เหลือรอ sale:advance-phases เลื่อนให้ตามเวลา
                 *
                 * และเฟสแรกจะได้ active ก็ต่อเมื่อถึงเวลาเริ่มแล้วจริง ๆ
                 * เพราะสองกรณีนี้ทำให้ status ไปโกหกล่วงหน้าได้:
                 *   - ตั้งวันเปิดไว้ในอนาคต (--start=2026-09-01)
                 *   - มีเฟสที่ขายไปแล้วยังไม่จบ เฟสใหม่จึงถูกดันไปเริ่มหลังจากนั้น
                 * ป้าย active ที่ยังซื้อไม่ได้จริงคือกับดักเดิมที่เคยทำให้ลูกค้าโอนเงินฟรี
                 */
                'status' => $isFirstMovable && $startsAt->lte(now()) ? 'active' : 'upcoming',
            ];

            $cursor = $endsAt->copy();
            $isFirstMovable = false;
        }

        return [
            'sale' => $sale,
            'rows' => $rows,
            'skipped' => $skipped,
            'ends_at' => $rows === [] ? null : end($rows)['ends_at'],
        ];
    }

    /**
     * ปักหมุดวันเปิดขาย แล้วบันทึกตารางใหม่.
     *
     * @param  bool  $force  ข้ามด่านความพร้อม (ใช้ได้เฉพาะคนกดเอง ไม่ใช่ตัวเปิดอัตโนมัติ)
     * @return array{ok:bool, message:?string, plan:?array<string,mixed>, readiness:array<string,mixed>}
     */
    public function launch(TokenSale $sale, ?CarbonInterface $start = null, ?int $flatDays = null, bool $force = false): array
    {
        $readiness = $this->readiness($sale);

        if (! $readiness['ready'] && ! $force) {
            return [
                'ok' => false,
                'message' => 'ยังไม่พร้อมขาย: '.implode(' · ', $readiness['blocking']),
                'plan' => null,
                'readiness' => $readiness,
            ];
        }

        $start = $start !== null ? Carbon::parse($start) : now();
        $plan = $this->plan($sale, $start, $flatDays);

        if ($plan['rows'] === []) {
            return [
                'ok' => false,
                'message' => 'ไม่มีเฟสที่เลื่อนได้ (ทุกเฟสขายไปแล้ว)',
                'plan' => $plan,
                'readiness' => $readiness,
            ];
        }

        $this->apply($plan);

        Log::info('SaleLaunchService: เปิดรอบขาย', [
            'sale_id' => $sale->id,
            'start' => $start->toIso8601String(),
            'forced' => $force,
            'blocking_ignored' => $force ? $readiness['blocking'] : [],
            'phases' => collect($plan['rows'])->map(fn ($r) => [
                'name' => $r['phase']->name,
                'starts_at' => $r['starts_at']->toIso8601String(),
                'ends_at' => $r['ends_at']->toIso8601String(),
                'status' => $r['status'],
            ])->all(),
        ]);

        return [
            'ok' => true,
            'message' => 'เปิดรอบขายแล้ว เฟสแรกเริ่ม '.$plan['rows'][0]['starts_at']->format('Y-m-d H:i'),
            'plan' => $plan,
            'readiness' => $readiness,
        ];
    }

    /**
     * บันทึกแผนลงฐานข้อมูล.
     *
     * ทั้งก้อนในทรานแซกชันเดียว — ถ้าบันทึกไปได้ครึ่งทางแล้วล้ม จะเหลือสภาพที่
     * บางเฟสใช้วันใหม่ บางเฟสใช้วันเก่า ซึ่งอาจซ้อนทับกันจนมีสองเฟสเปิดพร้อมกัน
     *
     * @param  array{sale:TokenSale, rows:array<int,array<string,mixed>>, ends_at:?Carbon}  $plan
     */
    public function apply(array $plan): void
    {
        $sale = $plan['sale'];
        $rows = $plan['rows'];
        $firstStart = $rows === [] ? null : $rows[0]['starts_at'];

        DB::transaction(function () use ($sale, $rows, $plan, $firstStart) {
            foreach ($rows as $row) {
                SalePhase::whereKey($row['phase']->id)->update([
                    'duration_days' => $row['days'],
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                    'status' => $row['status'],
                ]);
            }

            $update = [];

            /*
             * launched_at ตั้งครั้งเดียวแล้วไม่แตะอีก — เป็นประวัติว่าเปิดขายจริงวันไหน
             * และเป็นตัวกันไม่ให้ตัวเปิดอัตโนมัติเปิดซ้ำรอบสอง
             */
            if ($sale->launched_at === null && $firstStart !== null) {
                $update['launched_at'] = $firstStart;
            }

            // starts_at ของรอบขายเดินตามเฟสแรกเสมอ ไม่งั้นหน้าเว็บนับถอยหลังผิด
            if ($firstStart !== null) {
                $update['starts_at'] = $firstStart;
            }

            /*
             * ขยายขาปลายให้ครอบคลุมเฟสสุดท้าย
             * ถ้าไม่ขยายจะได้สภาพขัดกันเอง: เฟสเปิดขายอยู่ แต่ token_sales.ends_at
             * เป็นวันในอดีต → หน้าเว็บขึ้นว่า "รอบขายจบแล้ว" ทั้งที่ยังซื้อได้
             */
            if ($plan['ends_at'] !== null
                && ($sale->ends_at === null || $sale->ends_at->lt($plan['ends_at']))) {
                $update['ends_at'] = $plan['ends_at'];
            }

            if ($update !== []) {
                $sale->forceFill($update)->save();
            }
        });

        // getActiveSale() แคชไว้ 30 วิ — ล้างทันที ไม่งั้นหน้าเว็บยังเห็นตารางเก่า
        Cache::forget('token_sale:active');
    }

    // =========================================================================
    // 3) สวิตช์เปิดขายอัตโนมัติ
    // =========================================================================

    /**
     * เจ้าของติดอาวุธให้ระบบเปิดขายเองเมื่อพร้อมหรือยัง.
     *
     * ปิดไว้เป็นค่าเริ่มต้นโดยตั้งใจ — ระบบที่เปิดรับเงินตัวเองโดยไม่มีคนกด
     * เป็นสิ่งที่ต้องเลือกเอง ไม่ใช่สิ่งที่ได้มาฟรีจากการอัปเดตโค้ด
     */
    public function autoLaunchArmed(): bool
    {
        return filter_var(
            SiteSetting::get(self::ARM_GROUP, self::ARM_KEY, false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public function setAutoLaunch(bool $armed): void
    {
        SiteSetting::set(self::ARM_GROUP, self::ARM_KEY, $armed ? '1' : '0', 'boolean');
    }

    /**
     * เปิดขายแล้วหรือยัง — ใช้กันเปิดซ้ำ.
     */
    public function alreadyLaunched(TokenSale $sale): bool
    {
        return $sale->launched_at !== null;
    }
}
