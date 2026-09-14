<?php

namespace App\Services;

use App\Models\SalePhase;
use App\Models\TokenSale;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * TPIX TRADE — สถานะการขายเหรียญ "ตามจริง" สำหรับประกาศต่อสาธารณะ.
 *
 * เจ้าของสั่ง: "ประกาศเลยตามจริง แต่ให้บอทจัดการว่าเปิดหรือปิด ตามจริง วันเวลารอบต่าง ๆ"
 * ใช้ทั้งบอท Discord และผู้ช่วย AI บนหน้าเว็บ — สองที่ต้องพูดตรงกันเสมอ
 *
 * ═══ "เปิดขาย" แปลว่าอะไร ═══
 *
 * ต้องครบทั้งสามข้อ ไม่ใช่แค่ status ในตาราง:
 *   1. มีเฟสที่ active และอยู่ในช่วงเวลาจริง (TokenSaleService::getActivePhase)
 *   2. มีช่องทางจ่ายเงินที่ใช้ได้จริงตอนนี้ (รอบขายประกาศรับ + ตั้งค่าครบ)
 *   3. ด่านความพร้อมผ่านครบ — จ่ายเหรียญได้จริง (SaleLaunchService::readiness)
 *
 * ข้อ 3 สำคัญที่สุด: ถ้าประกาศ "ซื้อได้เลย" ทั้งที่ระบบจ่ายเหรียญยังปิด
 * คนที่จ่ายเงินจะได้เหรียญค้างคิว — ประกาศแบบนั้นในชุมชนคือสัญญาที่รักษาไม่ได้
 *
 * ═══ วันที่ของเฟส ═══
 *
 * ก่อนเปิดขายจริง (launched_at ว่าง) วันในตารางเป็นแค่ค่าจอง — SaleLaunchService จะคำนวณใหม่
 * ทั้งหมดนับจากวันที่เปิดขายจริง จึงบอกเป็น "ยาว N วัน" ไม่ใช่วันที่ ไม่งั้นประกาศผิดทันทีที่เปิด
 *
 * Developed by Xman Studio.
 */
class SaleStatusService
{
    public const OPEN = 'open';

    public const PREPARING = 'preparing';

    public const CLOSED = 'closed';

    /**
     * ด่านความพร้อมถามยอดเหรียญจากเชนสด (timeout 8 วิ) — แคชไว้ ไม่งั้นทุกคำถามในแชทยิง RPC หนึ่งครั้ง
     * 15 นาที > รอบ discord:sync (10 นาที) ที่คอยอุ่นแคชให้ คนถามจึงแทบไม่เจอแคชเย็น.
     */
    private const READINESS_TTL = 900;

    public function __construct(
        private readonly TokenSaleService $sales,
        private readonly SaleLaunchService $launcher,
        private readonly StripePaymentService $stripe,
        private readonly BankTransferSaleService $bank,
    ) {}

    /**
     * ช่องทางจ่ายเงินที่ "ใช้ได้จริงตอนนี้" — ต้องผ่านสองด่าน: รอบขายประกาศรับ + ตั้งค่าครบ
     * (ตรรกะเดียวกับที่หน้าซื้อเหรียญใช้ ห้ามแยกเขียนสองที่).
     *
     * @return array{card: bool, bank: bool}
     */
    public function paymentMethods(TokenSale $sale): array
    {
        $accepted = array_map('strtoupper', (array) ($sale->accept_currencies ?? []));

        return [
            'card' => in_array('CARD', $accepted, true) && $this->stripe->isEnabled(),
            'bank' => in_array('BANK', $accepted, true) && $this->bank->isConfigured(),
        ];
    }

    /**
     * @return array{
     *     state: string,
     *     headline: string,
     *     detail: string,
     *     sale: ?array<string, mixed>,
     *     current_phase: ?string,
     *     phases: list<array<string, mixed>>,
     *     payment_methods: array{card: bool, bank: bool},
     *     url: string,
     * }
     */
    public function snapshot(): array
    {
        $url = rtrim((string) config('app.url'), '/').'/token-sale';
        $sale = $this->sales->getActiveSale();

        if ($sale === null) {
            return [
                'state' => self::CLOSED,
                'headline' => 'ยังไม่มีรอบขายเหรียญในตอนนี้',
                'detail' => 'ติดตามประกาศรอบถัดไปได้ที่นี่',
                'sale' => null,
                'current_phase' => null,
                'phases' => [],
                'payment_methods' => ['card' => false, 'bank' => false],
                'url' => $url,
            ];
        }

        $launched = $sale->launched_at !== null;
        $active = $this->sales->getActivePhase($sale);
        $methods = $this->paymentMethods($sale);
        $ready = $this->ready($sale);
        $phases = $sale->phases->sortBy('phase_order')->values();

        $sellableLeft = $phases->contains(fn (SalePhase $p) => (float) $p->allocation > (float) $p->sold
            && ($p->ends_at === null || ! $launched || $p->ends_at->isFuture()));

        $state = match (true) {
            $active !== null && ($methods['card'] || $methods['bank']) && $ready => self::OPEN,
            $launched && ! $sellableLeft => self::CLOSED,
            default => self::PREPARING,
        };

        [$headline, $detail] = match ($state) {
            self::OPEN => [
                "เปิดขายแล้ว — {$active->name} ราคา ".$this->usd($active->price_usd).' ต่อ TPIX',
                'ซื้อได้ที่หน้าเว็บ ช่องทางชำระ: '.$this->methodsText($methods),
            ],
            self::CLOSED => ['ปิดการขายแล้ว', 'ขอบคุณทุกท่านที่ร่วมรอบนี้ — ติดตามประกาศถัดไปได้ที่นี่'],
            default => [
                'เตรียมเปิดขาย — ยังไม่เปิดให้ซื้ออย่างเป็นทางการ',
                'ทีมงานกำลังเตรียมระบบจ่ายเหรียญให้พร้อมก่อนเปิดรับเงิน บอทจะอัปเดตข้อความนี้เองทันทีที่เปิดขาย',
            ],
        };

        return [
            'state' => $state,
            'headline' => $headline,
            'detail' => $detail,
            'sale' => [
                'name' => $sale->name,
                'total_supply' => (float) $sale->total_supply_for_sale,
                'total_sold' => (float) $sale->total_sold,
                'percent_sold' => $sale->percent_sold,
                'launched_at' => $sale->launched_at?->toIso8601String(),
            ],
            'current_phase' => $state === self::OPEN ? $active?->name : null,
            'phases' => $phases->map(fn (SalePhase $p) => $this->phase($p, $launched, $state === self::OPEN ? $active : null))->all(),
            'payment_methods' => $methods,
            'url' => $url,
        ];
    }

    /**
     * สรุปเป็นข้อความสั้นสำหรับใส่ใน prompt ของผู้ช่วย AI.
     */
    public function asText(): string
    {
        $s = $this->snapshot();
        $lines = ["สถานะ: {$s['headline']}", $s['detail']];

        foreach ($s['phases'] as $phase) {
            $lines[] = "- {$phase['name']}: ราคา {$phase['price']} · ขายแล้ว {$phase['sold_text']} · {$phase['window']} · {$phase['status_label']}";
        }

        if ($s['state'] === self::OPEN) {
            $lines[] = 'ช่องทางชำระตอนนี้: '.$this->methodsText($s['payment_methods']);
        }

        $lines[] = 'หน้าซื้อเหรียญ: /token-sale';

        return implode("\n", $lines);
    }

    /** ล้างแคชความพร้อม — แอดมินกด "อัปเดตตอนนี้" หลังเพิ่งเปิดระบบจ่ายเหรียญ จะได้ไม่ต้องรอ 15 นาที */
    public function forget(): void
    {
        $sale = $this->sales->getActiveSale();

        if ($sale !== null) {
            Cache::forget("sale_status:ready:{$sale->id}");
        }
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    private function ready(TokenSale $sale): bool
    {
        try {
            return (bool) Cache::remember("sale_status:ready:{$sale->id}", self::READINESS_TTL, fn () => $this->launcher->readiness($sale)['ready']);
        } catch (\Throwable $e) {
            // ตรวจไม่ได้ = ไม่ประกาศว่าเปิดขาย (เหมือนด่านความพร้อมที่ fail-closed)
            Log::warning('SaleStatus: ตรวจความพร้อมไม่สำเร็จ', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /** @return array<string, mixed> */
    private function phase(SalePhase $phase, bool $launched, ?SalePhase $open): array
    {
        $days = (int) ($phase->duration_days ?: SaleLaunchService::DEFAULT_DURATION_DAYS);
        $soldOut = (float) $phase->allocation > 0 && (float) $phase->sold >= (float) $phase->allocation;

        $window = $launched && $phase->starts_at && $phase->ends_at
            ? $this->date($phase->starts_at).' – '.$this->date($phase->ends_at)
            : "ยาว {$days} วัน · เริ่มนับเมื่อเปิดขายจริง";

        // ป้ายสถานะคิดจากของจริง ไม่ใช่ค่า status ในตาราง (ค้างได้ถึงชั่วโมงก่อน sale:advance-phases รัน)
        $label = match (true) {
            $open !== null && $open->is($phase) => '🟢 เปิดขายอยู่',
            $soldOut => 'ขายหมดแล้ว',
            $launched && $phase->ends_at !== null && $phase->ends_at->isPast() => 'จบเฟสแล้ว',
            $launched => 'รอเปิด',
            default => 'รอเปิดขาย',
        };

        return [
            'name' => $phase->name,
            'price' => $this->usd($phase->price_usd),
            'price_usd' => (float) $phase->price_usd,
            'allocation' => (float) $phase->allocation,
            'sold' => (float) $phase->sold,
            'sold_text' => $this->amount((float) $phase->sold).' / '.$this->amount((float) $phase->allocation).' TPIX',
            'window' => $window,
            'duration_days' => $days,
            'status_label' => $label,
        ];
    }

    private function methodsText(array $methods): string
    {
        $names = array_keys(array_filter(['บัตรเครดิต/เดบิต' => $methods['card'] ?? false, 'โอนผ่านธนาคาร' => $methods['bank'] ?? false]));

        return $names === [] ? '—' : implode(', ', $names);
    }

    private function usd(mixed $value): string
    {
        return '$'.rtrim(rtrim(number_format((float) $value, 4, '.', ','), '0'), '.');
    }

    private function amount(float $value): string
    {
        return match (true) {
            $value >= 1_000_000_000 => rtrim(rtrim(number_format($value / 1_000_000_000, 2), '0'), '.').'B',
            $value >= 1_000_000 => rtrim(rtrim(number_format($value / 1_000_000, 2), '0'), '.').'M',
            default => number_format($value),
        };
    }

    private function date(CarbonInterface $date): string
    {
        return $date->copy()->timezone('Asia/Bangkok')->locale('th')->isoFormat('D MMM YYYY');
    }
}
