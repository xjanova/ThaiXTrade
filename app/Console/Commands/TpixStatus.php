<?php

namespace App\Console\Commands;

use App\Models\Chain;
use App\Models\SalePhase;
use App\Models\SiteSetting;
use App\Models\TradingPair;
use App\Services\ChainResolver;
use App\Services\ContractRegistry;
use App\Services\SaleLaunchService;
use App\Services\TpixDexService;
use App\Services\TreasuryService;
use App\Support\DefaultMarket;
use App\Support\TreasuryWallet;
use App\Support\Wei;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * tpix:status — ความพร้อมของเชน TPIX ทั้งสาย อยู่ในคำสั่งเดียว.
 *
 * ทำไมต้องมี: ความพร้อมของ "เชน · สัญญา DEX · คู่เทรด · การขายเหรียญ · การจ่ายเหรียญ"
 * เกี่ยวพันกันหมด แต่เดิมต้องไล่ดูคนละที่ (tinker / หน้าแอดมิน / curl) แล้วมักเห็นไม่ครบ
 * จนสรุปผิดว่า "ระบบพร้อมแล้ว" ทั้งที่ปลายทางยังจ่ายเหรียญไม่ได้
 *
 * อ่านอย่างเดียว ไม่แก้อะไร รันบน production ได้ปลอดภัย:
 *   php artisan tpix:status
 *
 * Developed by Xman Studio
 */
class TpixStatus extends Command
{
    protected $signature = 'tpix:status {--json : พิมพ์เป็น JSON สำหรับสคริปต์อื่น}';

    protected $description = 'ตรวจความพร้อมของเชน TPIX: สัญญา DEX · คู่เทรด · เชนปริยาย · การขาย · การจ่ายเหรียญ';

    /*
     * ⚠️ ไม่ inject SaleLaunchService / TreasuryService ทาง constructor
     *
     * artisan สร้างอ็อบเจกต์ของทุกคำสั่งตอน boot รวมถึงตอนที่ RefreshDatabase
     * ยังไม่ได้ migrate — และ StripePaymentService (ที่ SaleLaunchService ลากมา)
     * อ่านคีย์จากตาราง site_settings ใน constructor ของมัน
     * ผลคือ **ทุกเทสต์ทั้งชุดพัง** ด้วย "no such table: site_settings" ทั้งที่ไม่มีใครเรียกคำสั่งนี้
     *
     * จึงหยิบตอนใช้จริงใน handle() เท่านั้น
     */
    public function __construct(
        private ContractRegistry $registry,
        private TpixDexService $dex,
        private ChainResolver $chains,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = [
            'chain' => $this->chainSection(),
            'dex' => $this->dexSection(),
            'pairs' => $this->pairsSection(),
            'sale' => $this->saleSection(),
            'payouts' => $this->payoutsSection(),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        foreach ($report as $name => $section) {
            $this->newLine();
            $this->line('<options=bold>'.$section['title'].'</>');
            $this->table(['', 'รายการ', 'ค่า'], array_map(
                fn (array $r) => [$r['ok'] === null ? '·' : ($r['ok'] ? '✓' : '✗'), $r['label'], $r['value']],
                $section['rows'],
            ));
        }

        $blocking = collect($report)
            // ต้องเทียบแบบเข้ม — ok = null แปลว่า "ยังไม่ถึงคิวตรวจ" ไม่ใช่ "ไม่ผ่าน"
            // (loose compare ของ collect()->where() มอง null == false แล้วรายงานเกินจริง)
            ->flatMap(fn (array $s) => collect($s['rows'])->where('ok', '===', false)->pluck('label'))
            ->values();

        $this->newLine();
        if ($blocking->isEmpty()) {
            $this->info('พร้อมทุกข้อ');

            return self::SUCCESS;
        }

        $this->warn('ยังไม่ผ่าน '.$blocking->count().' ข้อ:');
        foreach ($blocking as $item) {
            $this->line('  • '.$item);
        }

        return self::SUCCESS;
    }

    // =========================================================================

    private function chainSection(): array
    {
        $tpixChainId = (int) config('blockchain.tpix_chain_id', 4289);
        $chain = $this->chains->resolve($tpixChainId);
        $default = $this->chains->defaultChainId();

        $height = null;
        $liveChainId = null;
        try {
            $rpc = (string) config('blockchain.tpix_rpc_url');
            $res = Http::timeout(8)->withHeaders(['User-Agent' => 'TPIX-TRADE-Server/1.0'])->asJson()
                ->post($rpc, ['jsonrpc' => '2.0', 'method' => 'eth_blockNumber', 'params' => [], 'id' => 1]);
            $height = $res->successful() ? hexdec((string) $res->json('result')) : null;

            $res2 = Http::timeout(8)->withHeaders(['User-Agent' => 'TPIX-TRADE-Server/1.0'])->asJson()
                ->post($rpc, ['jsonrpc' => '2.0', 'method' => 'eth_chainId', 'params' => [], 'id' => 1]);
            $liveChainId = $res2->successful() ? hexdec((string) $res2->json('result')) : null;
        } catch (\Throwable) {
            // อ่านไม่ได้ = ถือว่าไม่ผ่าน ไม่ต้องโยนต่อ
        }

        return [
            'title' => 'เชน TPIX',
            'rows' => [
                ['label' => 'RPC ตอบและ chainId ตรง', 'ok' => $liveChainId === $tpixChainId,
                    'value' => $liveChainId === null ? 'ต่อไม่ได้' : "chainId {$liveChainId}"],
                ['label' => 'เชนเดินอยู่', 'ok' => $height !== null && $height > 0,
                    'value' => $height === null ? '-' : 'บล็อก '.number_format($height)],
                ['label' => 'เชนเปิดใช้งานในระบบ (is_active)', 'ok' => (bool) $chain?->is_active,
                    'value' => $chain === null ? 'ไม่มีในตาราง chains' : ($chain->is_active ? 'เปิด' : 'ปิด')],
                ['label' => 'สถานะเชน = live (dex:sync ตั้งให้เอง)', 'ok' => $chain?->status === Chain::STATUS_LIVE,
                    'value' => (string) ($chain?->status ?? '-')],
                ['label' => 'เชนปริยายของเว็บ/แอป', 'ok' => $default === $tpixChainId,
                    'value' => $default === $tpixChainId ? "TPIX ({$default})" : "ยังเป็นเชน {$default}"],
            ],
        ];
    }

    private function dexSection(): array
    {
        $cfg = $this->registry->dexConfig();
        $rows = [];

        foreach (['WTPIX' => 'wtpix', 'USDT' => 'usdt_tpix', 'FACTORY' => 'dex_factory', 'ROUTER' => 'dex_router'] as $field => $key) {
            $address = $cfg[$field];
            $rows[] = [
                'label' => ContractRegistry::CONTRACTS[$key]['label'],
                'ok' => $address !== null && ! in_array($key, $cfg['missing'], true),
                'value' => $address ?? 'ยังไม่ได้ลงทะเบียน',
            ];
        }

        $poolCount = $cfg['ready'] ? count($this->dex->allPairs()) : 0;
        $rows[] = ['label' => 'พูลบน factory', 'ok' => $cfg['ready'] ? $poolCount > 0 : null,
            'value' => $cfg['ready'] ? (string) $poolCount : 'รอ deploy'];

        $price = $cfg['ready'] ? $this->dex->tpixUsdPrice() : null;
        $rows[] = ['label' => 'ราคา TPIX จากพูล WTPIX/USDT', 'ok' => $cfg['ready'] ? $price !== null : null,
            'value' => $price === null ? 'ยังไม่มีสภาพคล่อง' : number_format($price, 6).' USDT'];

        return ['title' => 'สัญญา TPIX DEX', 'rows' => $rows];
    }

    private function pairsSection(): array
    {
        $tpixChainId = (int) config('blockchain.tpix_chain_id', 4289);
        $chain = $this->chains->resolve($tpixChainId);

        if ($chain === null) {
            return ['title' => 'คู่เทรดบนเชน TPIX', 'rows' => [
                ['label' => 'เชน TPIX ในตาราง chains', 'ok' => false, 'value' => 'ไม่มี'],
            ]];
        }

        $onchain = TradingPair::where('chain_id', $chain->id)->where('execution_mode', 'onchain')->get();
        $tpixUsdt = $onchain->firstWhere('symbol', 'TPIX-USDT');

        return ['title' => 'คู่เทรดบนเชน TPIX', 'rows' => [
            ['label' => 'คู่ที่ส่งคำสั่งได้จริง (onchain)', 'ok' => $onchain->isNotEmpty(),
                'value' => $onchain->count().' คู่'.($onchain->isEmpty() ? ' — เติมพูลที่ /liquidity แล้วรอ 1 นาที' : '')],
            ['label' => 'คู่ที่เปิดซื้อขายอยู่ (มีสภาพคล่อง)', 'ok' => $onchain->where('is_active', true)->isNotEmpty(),
                'value' => $onchain->where('is_active', true)->pluck('symbol')->take(8)->implode(', ') ?: '-'],
            ['label' => 'คู่หลัก TPIX-USDT', 'ok' => $tpixUsdt !== null && (bool) $tpixUsdt->is_active,
                'value' => $tpixUsdt === null ? 'ยังไม่มีพูล' : ($tpixUsdt->is_active ? 'เปิด · พูล '.$tpixUsdt->dex_pair_address : 'พูลว่าง')],
            ['label' => 'คู่ปริยายของหน้า /trade', 'ok' => DefaultMarket::pair() === DefaultMarket::TPIX,
                'value' => DefaultMarket::pair()],
        ]];
    }

    private function saleSection(): array
    {
        $readiness = app(SaleLaunchService::class)->readiness();
        $phase = SalePhase::where('status', 'active')->orderBy('phase_order')->first();

        $rows = array_map(fn (array $c) => [
            'label' => $c['label'],
            'ok' => (bool) $c['ok'],
            'value' => (string) ($c['detail'] ?? $c['hint'] ?? ''),
        ], $readiness['checks']);

        array_unshift($rows, [
            'label' => 'เฟสที่เปิดขายอยู่',
            'ok' => $phase !== null,
            'value' => $phase === null ? 'ไม่มีเฟส active'
                : "{$phase->name} · \${$phase->price_usd} · ขายแล้ว ".number_format((float) $phase->sold).'/'.number_format((float) $phase->allocation),
        ]);

        return ['title' => 'การขายเหรียญ', 'rows' => $rows];
    }

    private function payoutsSection(): array
    {
        $readiness = app(TreasuryService::class)->readiness();
        $wallet = TreasuryWallet::address();

        $balance = '-';
        if ($wallet !== '') {
            $raw = null;
            try {
                $res = Http::timeout(8)->withHeaders(['User-Agent' => 'TPIX-TRADE-Server/1.0'])->asJson()
                    ->post((string) config('blockchain.tpix_rpc_url'), [
                        'jsonrpc' => '2.0', 'method' => 'eth_getBalance', 'params' => [$wallet, 'latest'], 'id' => 1,
                    ]);
                $raw = $res->successful() ? $res->json('result') : null;
            } catch (\Throwable) {
                $raw = null;
            }
            $balance = $raw === null ? 'อ่านยอดไม่ได้' : Wei::hexToDecimal($raw).' TPIX';
        }

        $rows = array_map(fn (array $c) => [
            'label' => $c['label'],
            'ok' => (bool) $c['ok'],
            'value' => (string) ($c['hint'] ?? ''),
        ], $readiness['checks']);

        array_unshift($rows, [
            'label' => 'กระเป๋าที่จ่ายเหรียญให้ลูกค้า',
            'ok' => $wallet !== '',
            'value' => $wallet === '' ? 'ยังไม่ได้ตั้ง' : "{$wallet} · {$balance}",
        ]);

        $rows[] = [
            'label' => 'ค่าธรรมเนียมเก็บเข้ากระเป๋า (พิมพ์เล็กเสมอ)',
            'ok' => (bool) preg_match('/^0x[0-9a-f]{40}$/', (string) SiteSetting::get('trading', 'fee_collector_wallet', '')),
            'value' => (string) SiteSetting::get('trading', 'fee_collector_wallet', '') ?: 'ยังไม่ได้ตั้ง',
        ];

        return ['title' => 'การจ่ายเหรียญให้ลูกค้า', 'rows' => $rows];
    }
}
